<?php
declare(strict_types=1);

/**
 * Carafe — nightly ingest of the COGS benchmark.
 *
 * Drives CogsBenchmarkService over every enabled adapter (USDA AMS, USDA
 * NASS, GreenDock when its pipe lands) across the regions Carafe cares
 * about. Records each batch in cogs_ingest_batches and each price row in
 * cogs_benchmark.
 *
 * Cron (production):
 *   30 4 * * * php /var/www/smappen/scripts/refresh-cogs-benchmark.php \
 *     >> /var/www/smappen/storage/logs/cogs.log 2>&1
 *
 * Usage:
 *   php scripts/refresh-cogs-benchmark.php
 *   php scripts/refresh-cogs-benchmark.php --dry              # parse + log, no writes
 *   php scripts/refresh-cogs-benchmark.php --regions=US-NE,US-SE
 *   php scripts/refresh-cogs-benchmark.php --as-of=2026-05-26
 *   php scripts/refresh-cogs-benchmark.php --quiet            # crontab-friendly output
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\CogsBenchmarkService;

Config::load(dirname(__DIR__));

$args = parse_args($argv);
$dry     = (bool) ($args['dry']     ?? false);
$quiet   = (bool) ($args['quiet']   ?? false);
$asOf    = (string) ($args['as-of']  ?? date('Y-m-d'));
$regions = isset($args['regions']) ? array_values(array_filter(array_map('trim', explode(',', (string) $args['regions'])))) : null;

// Carafe's default region sweep — every region the AMS adapter has at
// least one terminal-market slug for, plus 'US' so NASS national prices
// land. Restaurants with a NULL region fall back to 'US' via
// CogsBenchmarkRepository::lookup() anyway, so 'US' is the always-needed
// floor.
$defaultRegions = ['US', 'US-NE', 'US-MID-ATLANTIC', 'US-MW', 'US-SE', 'US-S', 'US-W'];
$targetRegions  = $regions ?? $defaultRegions;

if (!$quiet) {
    echo "[" . date('c') . "] cogs-benchmark refresh starting\n";
    echo "  as_of:   $asOf\n";
    echo "  regions: " . implode(',', $targetRegions) . "\n";
    echo "  dry_run: " . ($dry ? 'YES (no writes)' : 'no') . "\n";
}

$svc = new CogsBenchmarkService();
$res = $svc->ingest($asOf, $targetRegions, $dry);

if ($dry) {
    if (!$quiet) {
        echo "\nWould ingest the following batches:\n";
        foreach ($res['adapters_run'] as $a) {
            if (!($a['enabled'] ?? false)) {
                echo "  [skip] " . $a['key'] . " (not enabled)\n";
                continue;
            }
            printf("  %-10s  batches=%d  would_insert=%d  errors=%d\n",
                $a['key'], $a['batches'] ?? 0, $a['inserted'] ?? 0, $a['errors'] ?? 0);
        }
        echo "\nTotals: batches=" . $res['batches']
            . " ok=" . $res['batches_ok']
            . " rows_fetched=" . $res['rows_fetched']
            . " would_insert=" . $res['rows_inserted']
            . "\n";
    } else {
        echo "[" . date('c') . "] cogs-benchmark dry-run: " . json_encode($res) . "\n";
    }
    exit(0);
}

echo "[" . date('c') . "] cogs-benchmark refresh: " . json_encode($res) . "\n";

// Process exit code reflects whether we got any successful batch in this run.
// Useful for the operator email "cogs refresh quiet" cron line: silent on
// success, screams on total failure.
exit($res['batches_ok'] > 0 ? 0 : 2);

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
