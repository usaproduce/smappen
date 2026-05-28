<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\VendorDedupeService;
use App\Services\WorkerHeartbeat;

/**
 * Carafe seed-dedupe + geocode pass. Spec v3 §9 step 5.
 *
 * Two phases per invocation:
 *
 *   1. Block-key assignment + candidate enumeration + match scoring
 *      on every vendor_locations row added since the last pass
 *      (dedupe_scanned_at IS NULL). Writes decision rows to
 *      vendor_dedupe_pairs.
 *
 *   2. Apply auto_merge pairs that haven't been applied yet, using
 *      union-find so chained pairs (A↔B, B↔C) produce a single
 *      cluster merge with a deterministic survivor.
 *
 * Census batch geocoding for non-Google sources (OSM, USDA, manual
 * imports) hooks in here too once the spec's Phase 10 adapters land —
 * for now the sweep pipeline already has lat/lng from Places, so
 * geocoding is a no-op on the current input set.
 *
 * Run from cron after seed-tile-worker. Idempotent on re-run.
 *
 * Args:
 *   --batch-size=N    process at most N new locations per phase (default 5000)
 *   --skip-merges     dedupe only; don't actually merge anything
 *   --quiet
 */

Config::load(dirname(__DIR__));
ini_set('memory_limit', '1024M');
set_time_limit(0);

$opts = getopt('', ['batch-size::', 'skip-merges', 'quiet']);
$batchSize  = max(1, (int) ($opts['batch-size'] ?? 5000));
$skipMerges = array_key_exists('skip-merges', $opts);
$quiet      = array_key_exists('quiet', $opts);

$svc = new VendorDedupeService();

WorkerHeartbeat::start('seed-dedupe', "batch-size=$batchSize" . ($skipMerges ? ' --skip-merges' : ''));

$started = microtime(true);
$counts  = $svc->dedupeNewLocations($batchSize);
$elapsed = round(microtime(true) - $started, 1);

if (!$quiet) {
    echo "Dedupe pass: scanned={$counts['scanned']} auto_merge={$counts['auto_merge']} review={$counts['review']} reject={$counts['reject']} in {$elapsed}s\n";
}

$merged = 0;
if (!$skipMerges) {
    $started = microtime(true);
    $merged  = $svc->applyPendingAutoMerges();
    $elapsed = round(microtime(true) - $started, 1);
    if (!$quiet) {
        echo "Applied $merged auto-merge action(s) in {$elapsed}s\n";
    }
}

WorkerHeartbeat::finish('seed-dedupe', "scanned={$counts['scanned']} auto_merge={$counts['auto_merge']} review={$counts['review']} merged=$merged");
