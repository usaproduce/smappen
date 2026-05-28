<?php
declare(strict_types=1);

/**
 * Carafe — nightly ingest of the COGS benchmark.
 *
 * Drives CogsBenchmarkService over every enabled adapter (USDA AMS, USDA
 * NASS, GreenDock when its pipe lands) across the regions Carafe cares
 * about. Records each batch in cogs_ingest_batches, writes a single
 * rolled-up median price per (key, region, source, as_of) into
 * cogs_benchmark, recomputes 7d/30d rolling stats, and flags 3σ anomalies.
 *
 * Cron (production, installed by scripts/install-cron.sh):
 *   30 4 * * * php /var/www/smappen/scripts/refresh-cogs-benchmark.php --quiet \
 *     >> /var/www/smappen/storage/logs/cogs.log 2>&1
 *
 * Usage:
 *   php scripts/refresh-cogs-benchmark.php
 *   php scripts/refresh-cogs-benchmark.php --dry              # parse + log, no writes
 *   php scripts/refresh-cogs-benchmark.php --regions=US-NE,US-SE
 *   php scripts/refresh-cogs-benchmark.php --as-of=2026-05-26
 *   php scripts/refresh-cogs-benchmark.php --backfill-days=14 # fill missing days first
 *   php scripts/refresh-cogs-benchmark.php --test-slug=2286   # one-slug probe (no writes)
 *   php scripts/refresh-cogs-benchmark.php --quiet            # cron-friendly output
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\CogsBenchmarkService;

Config::load(dirname(__DIR__));

$args = parse_args($argv);
$dry          = (bool) ($args['dry']           ?? false);
$quiet        = (bool) ($args['quiet']         ?? false);
$asOf         = (string) ($args['as-of']       ?? date('Y-m-d'));
$backfillDays = isset($args['backfill-days'])  ? max(0, (int) $args['backfill-days']) : 0;
$testSlug     = $args['test-slug']             ?? null;
$testAdapter  = (string) ($args['test-adapter'] ?? 'usda_ams');
$regions      = isset($args['regions'])
    ? array_values(array_filter(array_map('trim', explode(',', (string) $args['regions']))))
    : null;

$defaultRegions = ['US', 'US-NE', 'US-MID-ATLANTIC', 'US-MW', 'US-SE', 'US-S', 'US-W'];
$targetRegions  = $regions ?? $defaultRegions;

$svc = new CogsBenchmarkService();

// ─────────────────────────────────────────────────────────────────
// Mode 1: --test-slug — single-slug probe, no DB writes.
// ─────────────────────────────────────────────────────────────────
if ($testSlug !== null) {
    $res = $svc->testSlug($testAdapter, (string) $testSlug, $asOf);
    if ($quiet) {
        echo json_encode($res) . "\n";
    } else {
        echo "[" . date('c') . "] test-slug $testAdapter/$testSlug @ $asOf\n\n";
        if (isset($res['error'])) {
            echo "  ERROR: " . $res['error'] . "\n";
            exit(2);
        }
        foreach ($res['batches'] as $b) {
            printf("  ok=%s http=%s latency=%dms rows=%d  err=%s\n",
                $b['ok']?'Y':'N', $b['http_status']??'-', $b['latency_ms']??0,
                $b['total_rows'], $b['error']??'-');
            echo "  source_ref: " . ($b['source_ref'] ?? '') . "\n";
            echo "  endpoint:   " . ($b['endpoint']   ?? '') . "\n";
            if (!empty($b['notes'])) echo "  notes:      " . json_encode($b['notes']) . "\n";
            if (!empty($b['sample_rows'])) {
                echo "  sample rows:\n";
                foreach ($b['sample_rows'] as $r) {
                    printf("    %-20s  %4dc/%s  region=%s  from=%s\n",
                        $r['ingredient_key'], $r['cents_per_unit'], $r['unit'],
                        $r['region']??'?', $r['source_ref']??'?');
                }
            }
            if (!empty($b['unmatched'])) {
                echo "  unmatched commodities (top 5):\n";
                $top = array_slice($b['unmatched'], 0, 5);
                foreach ($top as $u) {
                    printf("    %-30s %-20s × %d\n", $u['commodity'], $u['variety']??'', $u['count']);
                }
            }
            echo "\n";
        }
    }
    exit(0);
}

if (!$quiet) {
    echo "[" . date('c') . "] cogs-benchmark refresh starting\n";
    echo "  as_of:        $asOf\n";
    echo "  regions:      " . implode(',', $targetRegions) . "\n";
    echo "  backfill:     " . ($backfillDays > 0 ? "{$backfillDays}d" : 'no') . "\n";
    echo "  dry_run:      " . ($dry ? 'YES (no writes)' : 'no') . "\n";
}

// ─────────────────────────────────────────────────────────────────
// Mode 2: --backfill-days — fill missing days before the main run.
// ─────────────────────────────────────────────────────────────────
$backfill = [];
if ($backfillDays > 0 && !$dry) {
    $backfill = $svc->backfillMissingDays($backfillDays, $targetRegions);
    if (!$quiet && $backfill) {
        echo "  backfilled:   " . count($backfill) . " day(s)\n";
        foreach ($backfill as $b) {
            printf("    %s  rollups=%d  anomalies=%d\n", $b['as_of'], $b['rollups'], $b['anomalies']);
        }
    } elseif (!$quiet) {
        echo "  backfilled:   no gaps in last {$backfillDays}d\n";
    }
}

// ─────────────────────────────────────────────────────────────────
// Mode 3: normal ingest.
// ─────────────────────────────────────────────────────────────────
$res = $svc->ingest($asOf, $targetRegions, $dry);
$res['backfill'] = $backfill;

if (!$quiet) {
    echo "\nResult:\n";
    foreach ($res['adapters_run'] as $a) {
        if (!($a['enabled'] ?? false)) {
            echo "  [skip] " . $a['key'] . " (not enabled)\n";
            continue;
        }
        printf("  %-10s  batches=%d  rows=%d  errors=%d\n",
            $a['key'], $a['batches']??0, $a['contributed']??0, $a['errors']??0);
    }
    echo "\nTotals: batches=" . ($res['batches']??0)
        . " ok=" . ($res['batches_ok']??0)
        . " rows_fetched=" . ($res['rows_fetched']??0)
        . " rollups_written=" . ($res['rollups_written']??0)
        . " anomalies=" . ($res['anomalies']??0)
        . " unmatched_logged=" . ($res['unmatched_logged']??0)
        . " rolling_updated=" . ($res['rolling_updated']??0)
        . "\n";
} else {
    echo "[" . date('c') . "] cogs-benchmark refresh: " . json_encode($res) . "\n";
}

exit(($res['batches_ok']??0) > 0 ? 0 : 2);

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

function parse_args(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $tok) {
        if (str_starts_with($tok, '--')) {
            $kv = explode('=', substr($tok, 2), 2);
            $out[$kv[0]] = $kv[1] ?? true;
        }
    }
    return $out;
}
