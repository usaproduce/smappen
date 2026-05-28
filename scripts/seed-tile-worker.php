<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\TileSweepWorker;
use App\Services\WorkerHeartbeat;

/**
 * Carafe seed-tile sweep worker. Spec v3 §4.1 + §9 step 4.
 *
 * Pulls one queued tile at a time off seed_tiles (FOR UPDATE SKIP
 * LOCKED, so multiple instances can run in parallel safely), sweeps
 * Places, upserts vendors, marks tile done. Run from cron every
 * minute, or as a long-running daemon. Each invocation processes up
 * to TILES_PER_RUN tiles or MAX_SECONDS wall-clock, whichever first.
 *
 * BudgetCapExceededException raised inside the worker pauses the
 * parent campaign — the next invocation will see the paused status
 * and skip its tiles cleanly.
 *
 * Args:
 *   --max-tiles=N      process at most N tiles (default 50)
 *   --max-seconds=N    stop after N wall-clock seconds (default 240)
 *   --quiet            suppress per-tile output
 */

Config::load(dirname(__DIR__));
ini_set('memory_limit', '512M');
set_time_limit(0);

$opts = getopt('', ['max-tiles::', 'max-seconds::', 'quiet']);
$maxTiles   = (int) ($opts['max-tiles']   ?? 50);
$maxSeconds = (int) ($opts['max-seconds'] ?? 240);
$quiet      = array_key_exists('quiet', $opts);

$worker  = new TileSweepWorker();
$started = microtime(true);
$processed = 0;
$idleSleeps = 0;

while ($processed < $maxTiles && (microtime(true) - $started) < $maxSeconds) {
    WorkerHeartbeat::beat('seed-tile-worker', "processed=$processed", "max-tiles=$maxTiles max-seconds=$maxSeconds");
    try {
        $r = $worker->runOne();
    } catch (\Throwable $e) {
        error_log('[seed-tile-worker] runOne failed: ' . $e->getMessage());
        $r = null;
        // Don't tight-loop on a recurring fault.
        sleep(2);
        continue;
    }

    if ($r === null) {
        // Queue empty — short backoff, then re-check (a campaign run
        // mid-loop may enqueue new tiles).
        $idleSleeps++;
        if ($idleSleeps >= 3) break; // truly idle, exit so cron can re-spawn fresh
        sleep(2);
        continue;
    }
    $idleSleeps = 0;
    $processed++;

    if (!$quiet) {
        $cost = isset($r['cost_usd']) ? sprintf('$%.4f', $r['cost_usd']) : '-';
        $note = '';
        if (!empty($r['unchanged']))   $note .= ' [unchanged]';
        if (!empty($r['subdivided']))  $note .= ' [subdivided×' . count($r['subdivided']) . ']';
        if (!empty($r['campaign_paused'])) $note .= ' [campaign paused]';
        if (!empty($r['error']))       $note .= ' [error: ' . $r['error'] . ']';
        echo "tile {$r['tile_id']} status={$r['status']} cost=$cost calls=" . ($r['calls_made'] ?? '-') . " results=" . ($r['results_count'] ?? '-') . $note . "\n";
    }

    if (!empty($r['campaign_paused'])) {
        // Campaign got paused on this tile (budget cap). Continue the
        // loop — other campaigns may still have running tiles.
        continue;
    }
}

if (!$quiet) {
    $elapsed = round(microtime(true) - $started, 1);
    echo "Processed $processed tile(s) in {$elapsed}s.\n";
}
