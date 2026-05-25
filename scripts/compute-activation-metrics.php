<?php
declare(strict_types=1);

/**
 * Daily activation-metrics rollup.
 *
 * Fills two derived columns that the funnel stamps in OnboardingController::stampActivation
 * don't write themselves:
 *   1. returned_in_week_2 — 1 iff the user had any api_usage_log row between
 *      day 8 and day 14 after signup. Once flipped to 1 it stays at 1.
 *   2. health_score — 0–100 composite of the 6 funnel milestones, recomputed
 *      every run so previously-set milestones promote the score.
 *
 * Designed for a daily cron at ~03:00:
 *   0 3 * * * php /var/www/smappen/scripts/compute-activation-metrics.php \
 *     >> /var/www/smappen/storage/logs/activation-metrics.log 2>&1
 *
 * Run on the droplet: php scripts/compute-activation-metrics.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));
$db = Database::getInstance();

$startedAt = date('c');
echo "[$startedAt] activation-metrics rollup start\n";

// 1. returned_in_week_2 — only consider users old enough to measure and not
//    yet flagged. Once set to 1, no need to revisit.
$eligible = $db->fetchAll(
    "SELECT am.user_id, am.signed_up_at
       FROM activation_metrics am
      WHERE COALESCE(am.returned_in_week_2, 0) = 0
        AND am.signed_up_at IS NOT NULL
        AND am.signed_up_at <= DATE_SUB(NOW(), INTERVAL 14 DAY)"
);
$returnedCount = 0;
foreach ($eligible as $row) {
    $day8  = date('Y-m-d H:i:s', strtotime($row['signed_up_at'] . ' +8 days'));
    $day14 = date('Y-m-d H:i:s', strtotime($row['signed_up_at'] . ' +14 days'));
    $hit = $db->fetch(
        'SELECT 1 AS one FROM api_usage_log
          WHERE user_id = ? AND created_at BETWEEN ? AND ?
          LIMIT 1',
        [$row['user_id'], $day8, $day14]
    );
    if ($hit) {
        $db->query(
            'UPDATE activation_metrics SET returned_in_week_2 = 1 WHERE user_id = ?',
            [$row['user_id']]
        );
        $returnedCount++;
    }
}
echo "  returned_in_week_2: scanned " . count($eligible) . " eligible users, $returnedCount flagged\n";

// 2. health_score — recompute for every row. Weights sum to 100; tweak in
//    one place if product ever re-prioritizes the funnel.
// Detect whether the Carafe milestone columns exist (added in migration 021).
// Lets this script run safely against droplets that haven't applied 021 yet.
$hasCarafeCols = (int) ($db->fetch(
    "SELECT COUNT(*) AS c FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'activation_metrics'
        AND COLUMN_NAME = 'first_dollar_measured_at'"
)['c'] ?? 0) === 1;

$baseCols = 'user_id, first_area_at, first_demographic_at, first_export_at, '
          . 'first_share_at, first_report_at, returned_in_week_2';
$carafeCols = $hasCarafeCols
    ? ', first_pos_connected_at, first_menu_synced_at, first_recommendation_accepted_at, first_dollar_measured_at'
    : '';

$rows = $db->fetchAll('SELECT ' . $baseCols . $carafeCols . ' FROM activation_metrics');
$updated = 0;
foreach ($rows as $r) {
    $score = 0;
    if (!empty($r['first_area_at']))        $score += 15;
    if (!empty($r['first_demographic_at'])) $score += 10;
    if (!empty($r['first_export_at']))      $score += 10;
    if (!empty($r['first_share_at']))       $score += 10;
    if (!empty($r['first_report_at']))      $score += 10;
    if ((int) $r['returned_in_week_2'] === 1) $score += 15;
    // Carafe-side milestones — heavier weights on the bottom-of-funnel
    // ones (acceptance + measured dollars) because they prove value.
    if ($hasCarafeCols) {
        if (!empty($r['first_pos_connected_at']))            $score += 10;
        if (!empty($r['first_menu_synced_at']))              $score += 5;
        if (!empty($r['first_recommendation_accepted_at']))  $score += 5;
        if (!empty($r['first_dollar_measured_at']))          $score += 10;
    }
    $score = min($score, 100);
    $db->query(
        'UPDATE activation_metrics SET health_score = ? WHERE user_id = ?',
        [$score, $r['user_id']]
    );
    $updated++;
}
echo "  health_score: recomputed for $updated users\n";

echo "[" . date('c') . "] done\n";
