<?php
declare(strict_types=1);

/**
 * Carafe — weekly action digest. For every restaurant with ≥1 suggested
 * recommendation in the last 7 days, email the operator the top 3 by
 * dollar impact. Spec §5.12 wiring of the existing digest endpoint.
 *
 * Idempotent per (organization_id, week_start) via the digest_sends table
 * created on first run (CREATE TABLE IF NOT EXISTS).
 *
 *   0 13 * * 1 php /var/www/smappen/scripts/send-weekly-digest.php >> /var/www/smappen/storage/logs/digest.log 2>&1
 *
 * Manual: php scripts/send-weekly-digest.php
 *         php scripts/send-weekly-digest.php --dry      (no email send, log only)
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\MailService;
use App\Services\WorkerHeartbeat;

Config::load(dirname(__DIR__));
$db = Database::getInstance();

$dry = in_array('--dry', $argv ?? [], true);

WorkerHeartbeat::beat('send-weekly-digest', 'start', $dry ? '--dry' : '');

// Idempotency ledger — lightweight inline migration so this script is
// self-contained. Removes the operator-step of "did you run the migration?"
$db->query('CREATE TABLE IF NOT EXISTS digest_sends (
  id              CHAR(36)    PRIMARY KEY,
  organization_id CHAR(36)    NOT NULL,
  restaurant_id   CHAR(36)    NOT NULL,
  week_start      DATE        NOT NULL,
  sent_at         DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  recipient_email VARCHAR(255) NOT NULL,
  rec_count       INT UNSIGNED NOT NULL DEFAULT 0,
  total_cents     INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uk_digest_week (organization_id, restaurant_id, week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$weekStart = date('Y-m-d', strtotime('monday this week'));
echo "[" . date('c') . "] weekly-digest start (week=$weekStart" . ($dry ? ', DRY' : '') . ")\n";

// Find restaurants with new recommendations + their organization's owner.
$rows = $db->fetchAll(
    "SELECT DISTINCT
        r.id AS restaurant_id, r.name AS restaurant_name, r.organization_id,
        u.email AS owner_email
       FROM restaurants r
       JOIN recommendations rec ON rec.restaurant_id = r.id
       JOIN users u ON u.organization_id = r.organization_id
      WHERE rec.status = 'suggested'
        AND rec.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND r.archived_at IS NULL
      GROUP BY r.id, u.id"
);

$mail = new MailService();
$sent = 0;
$skipped = 0;

foreach ($rows as $row) {
    // Idempotency check
    $already = $db->fetch(
        'SELECT 1 AS one FROM digest_sends WHERE organization_id = ? AND restaurant_id = ? AND week_start = ?',
        [$row['organization_id'], $row['restaurant_id'], $weekStart]
    );
    if ($already) {
        $skipped++;
        continue;
    }

    $recs = $db->fetchAll(
        'SELECT id, kind, narrative, dollar_estimate_cents, payload, menu_item_id
           FROM recommendations
          WHERE restaurant_id = ? AND status = "suggested"
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          ORDER BY dollar_estimate_cents DESC
          LIMIT 3',
        [$row['restaurant_id']]
    );
    if (!$recs) { $skipped++; continue; }

    $total = 0;
    foreach ($recs as $r) $total += (int) $r['dollar_estimate_cents'];

    $html = render_html_email((string) $row['restaurant_name'], $recs, $total);
    $text = render_text_email((string) $row['restaurant_name'], $recs, $total);
    $subject = 'Carafe — ' . format_usd($total) . '/mo on the table at ' . $row['restaurant_name'];

    if ($dry) {
        echo "  DRY: would send to {$row['owner_email']} (" . count($recs) . " recs, total " . format_usd($total) . ")\n";
    } else {
        $ok = $mail->send((string) $row['owner_email'], $subject, $html, $text);
        if (!$ok) {
            echo "  ! send failed for {$row['owner_email']}\n";
            continue;
        }
        $db->query(
            'INSERT INTO digest_sends (id, organization_id, restaurant_id, week_start, recipient_email, rec_count, total_cents)
             VALUES (UUID(), ?, ?, ?, ?, ?, ?)',
            [$row['organization_id'], $row['restaurant_id'], $weekStart, $row['owner_email'], count($recs), $total]
        );
        $sent++;
        echo "  + sent to {$row['owner_email']} (" . count($recs) . " recs, " . format_usd($total) . ")\n";
    }
}

echo "[" . date('c') . "] done. sent=$sent skipped=$skipped\n";

// ─────────────────────────── helpers ───────────────────────────

function format_usd(int $cents): string
{
    return '$' . number_format($cents / 100, 0);
}

function render_html_email(string $restaurantName, array $recs, int $total): string
{
    $items = '';
    foreach ($recs as $r) {
        $payload = is_string($r['payload']) ? json_decode($r['payload'], true) : ($r['payload'] ?? []);
        $items .= '<tr><td style="padding:14px 0;border-bottom:1px solid #eee">'
            . '<div style="font-weight:800;font-size:20px;color:#1A1A2E">' . format_usd((int) $r['dollar_estimate_cents']) . '/mo</div>'
            . '<div style="font-size:12px;color:#7848BB;text-transform:uppercase;letter-spacing:.5px;font-weight:700;margin:4px 0">' . htmlspecialchars(str_replace('_', ' ', (string) $r['kind'])) . '</div>'
            . '<div style="color:#374151;font-size:14px;line-height:1.45">' . htmlspecialchars((string) ($r['narrative'] ?? '')) . '</div>'
            . '</td></tr>';
    }
    return '<html><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f8fafc;padding:32px 0">'
        . '<table cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 1px 3px rgba(0,0,0,.05)">'
        . '<tr><td>'
        . '<div style="font-weight:900;font-size:13px;color:#7848BB;text-transform:uppercase;letter-spacing:1px">Carafe — this week</div>'
        . '<h1 style="margin:6px 0 4px 0;font-size:24px;color:#1A1A2E">~' . format_usd($total) . '/mo on the table</h1>'
        . '<p style="color:#64748b;margin:0 0 18px 0">at ' . htmlspecialchars($restaurantName) . '</p>'
        . '<table cellpadding="0" cellspacing="0" width="100%">' . $items . '</table>'
        . '<div style="margin-top:24px;color:#64748b;font-size:12px">Open Carafe → Restaurants to accept or dismiss each recommendation.</div>'
        . '</td></tr></table></body></html>';
}

function render_text_email(string $restaurantName, array $recs, int $total): string
{
    $lines = ["Carafe — this week", "~" . format_usd($total) . "/mo on the table at $restaurantName", "", "Top moves:"];
    foreach ($recs as $r) {
        $lines[] = "  " . format_usd((int) $r['dollar_estimate_cents']) . "/mo — " . str_replace('_', ' ', (string) $r['kind']);
        if (!empty($r['narrative'])) $lines[] = '    ' . $r['narrative'];
    }
    $lines[] = '';
    $lines[] = 'Open Carafe → Restaurants to accept or dismiss each recommendation.';
    return implode("\n", $lines);
}
