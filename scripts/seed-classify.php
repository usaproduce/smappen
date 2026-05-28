<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\VendorClassifierService;
use App\Services\WorkerHeartbeat;

/**
 * Carafe vendor classification pass. Spec v3 §4.3 + §9 step 6.
 *
 * For every vendor without a classified_at timestamp, run the
 * VendorClassifierService cascade and write the result. Vendors below
 * the confidence threshold get classification_needs_review=1 so they
 * surface in the admin review queue.
 *
 * Run after seed-dedupe (so dedupe's survivors get classified, not the
 * merged-into ghosts). Idempotent — re-running doesn't double-classify
 * because the WHERE filter is `classified_at IS NULL`.
 *
 * Args:
 *   --batch-size=N   default 5000
 *   --quiet
 */

Config::load(dirname(__DIR__));
ini_set('memory_limit', '512M');
set_time_limit(0);

$opts       = getopt('', ['batch-size::', 'quiet']);
$batchSize  = max(1, (int) ($opts['batch-size'] ?? 5000));
$quiet      = array_key_exists('quiet', $opts);

$svc     = new VendorClassifierService();
WorkerHeartbeat::start('seed-classify', "batch-size=$batchSize");
$started = microtime(true);
$counts  = $svc->classifyPending($batchSize);
$elapsed = round(microtime(true) - $started, 1);

if (!$quiet) {
    echo "Classified {$counts['total']} vendor(s): auto={$counts['auto']} review={$counts['review']} in {$elapsed}s\n";
}

WorkerHeartbeat::finish('seed-classify', "classified={$counts['total']} auto={$counts['auto']} review={$counts['review']}");
