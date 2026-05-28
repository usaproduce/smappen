<?php
declare(strict_types=1);

/**
 * Carafe cron-health CLI — same data as GET /api/admin/carafe/cron-health,
 * formatted as a TTY-friendly table for operators SSH'd into the droplet.
 * Especially useful when the web UI isn't reachable (the admin home is the
 * usual surface for this info, but if Apache/PHP-FPM is the problem, it
 * helps to have a CLI fallback).
 *
 * Exit codes:
 *   0 — all workers green
 *   1 — at least one yellow (degraded; cron may have slipped a tick)
 *   2 — at least one red    (stuck or never run; investigate)
 *
 * Usage:
 *   php scripts/cron-health.php          # human table
 *   php scripts/cron-health.php --json   # machine-readable JSON (same shape as API)
 *   php scripts/cron-health.php --quiet  # exit code only, no output
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\WorkerHeartbeat;

Config::load(dirname(__DIR__));

$opts  = getopt('', ['json', 'quiet']);
$asJson = array_key_exists('json',  $opts);
$quiet  = array_key_exists('quiet', $opts);

$db = Database::getInstance();

try {
    $rows = $db->fetchAll(
        'SELECT worker_name, beat_at, last_started_at, status, last_error,
                ticks_total, ticks_failed, last_duration_ms,
                pid, host, last_args, last_note,
                TIMESTAMPDIFF(SECOND, beat_at, NOW()) AS age_seconds
           FROM worker_heartbeats'
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: heartbeat read failed: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Has migration 038/039 been applied? Try: php scripts/migrate.php\n");
    exit(2);
}

$byName = [];
foreach ($rows as $r) {
    $byName[(string) $r['worker_name']] = $r;
}

$worst = 'green';
$workers = [];
foreach (WorkerHeartbeat::CADENCE_SECONDS as $name => $cadence) {
    $r = $byName[$name] ?? null;
    if ($r === null) {
        $bucket = 'red';
        $workers[] = compact('name','bucket','cadence') + ['age'=>null,'duration'=>null,'ticks'=>0,'fails'=>0,'run'=>null,'err'=>null,'note'=>null];
        $worst = worst($worst, $bucket);
        continue;
    }
    $age = (int) $r['age_seconds'];
    $bucket = $age <= $cadence ? 'green' : ($age <= 2 * $cadence ? 'yellow' : 'red');
    $worst = worst($worst, $bucket);
    $workers[] = [
        'name'     => $name,
        'bucket'   => $bucket,
        'cadence'  => $cadence,
        'age'      => $age,
        'duration' => $r['last_duration_ms'] !== null ? (int) $r['last_duration_ms'] : null,
        'ticks'    => (int) $r['ticks_total'],
        'fails'    => (int) $r['ticks_failed'],
        'run'      => $r['status'],
        'err'      => $r['last_error'],
        'note'     => $r['last_note'],
    ];
}

if ($asJson) {
    echo json_encode([
        'overall'     => $worst,
        'workers'     => $workers,
        'server_time' => date('c'),
    ], JSON_PRETTY_PRINT) . "\n";
    exit(exitCode($worst));
}

if (!$quiet) {
    $useColor = stream_isatty(STDOUT);
    $dot = function (string $b) use ($useColor): string {
        if (!$useColor) return strtoupper(substr($b, 0, 1));
        return match ($b) {
            'green'  => "\033[32m●\033[0m",
            'yellow' => "\033[33m●\033[0m",
            'red'    => "\033[31m●\033[0m",
            default  => '?',
        };
    };
    printf("%-21s %-1s %-7s %-9s %-9s %-12s  %s\n", 'WORKER', '', 'STATUS', 'AGE', 'DURATION', 'TICKS/FAIL', 'NOTE / ERROR');
    printf("%s\n", str_repeat('─', 90));
    foreach ($workers as $w) {
        printf(
            "%-21s %s %-7s %-9s %-9s %-12s  %s\n",
            $w['name'],
            $dot($w['bucket']),
            $w['bucket'],
            $w['age']      !== null ? fmtAge($w['age'])         : 'never',
            $w['duration'] !== null ? fmtDuration($w['duration']) : '–',
            $w['ticks'] . '/' . $w['fails'],
            $w['err'] ?? $w['note'] ?? '–'
        );
    }
    printf("\noverall: %s  (%s)\n", $worst, date('c'));
}

exit(exitCode($worst));

// ─────────────────────────── helpers ───────────────────────────
function worst(string $cur, string $new): string {
    $rank = ['green' => 0, 'yellow' => 1, 'red' => 2];
    return ($rank[$new] ?? 2) > ($rank[$cur] ?? 0) ? $new : $cur;
}
function exitCode(string $bucket): int {
    return ['green' => 0, 'yellow' => 1, 'red' => 2][$bucket] ?? 2;
}
function fmtAge(int $s): string {
    if ($s < 60)    return "{$s}s";
    if ($s < 3600)  return round($s / 60) . 'm';
    if ($s < 86400) return round($s / 3600) . 'h';
    return round($s / 86400) . 'd';
}
function fmtDuration(int $ms): string {
    if ($ms < 1000)   return "{$ms}ms";
    if ($ms < 60_000) return round($ms / 1000, 1) . 's';
    return round($ms / 60_000) . 'm';
}
