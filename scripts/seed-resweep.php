<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\SeedCampaignService;
use App\Services\SeedDeltaService;
use App\Services\WorkerHeartbeat;

/**
 * Carafe re-sweep scheduler + stuck-tile janitor. Spec v3 §12.3.
 *
 * Two responsibilities:
 *
 *   1. Recover stuck tiles — any seed_tiles row left in 'running' state
 *      past --stuck-after seconds gets flipped back to 'queued'. Almost
 *      always means a worker process died mid-tile. Safe to run from
 *      cron every minute; the WHERE filter only matches genuinely stale
 *      rows.
 *
 *   2. Schedule re-sweeps — for one campaign (--campaign=ID) or every
 *      eligible campaign (--all-campaigns), flip done tiles older than
 *      --max-age-days back to 'queued'. The worker preserves the prior
 *      result_id_hash; on its next sweep of that tile, if the new hash
 *      matches, downstream upserts are skipped entirely (§12.3).
 *
 * Args:
 *   --campaign=ID
 *   --all-campaigns
 *   --max-age-days=N      default 30
 *   --stuck-after=SECS    default 1800
 *   --skip-recovery       just schedule, skip the janitor pass
 *   --quiet
 */

Config::load(dirname(__DIR__));
ini_set('memory_limit', '256M');
set_time_limit(0);

$opts = getopt('', ['campaign::', 'all-campaigns', 'max-age-days::', 'stuck-after::', 'skip-recovery', 'quiet']);
$campaignId   = $opts['campaign']     ?? null;
$allCampaigns = array_key_exists('all-campaigns', $opts);
$maxAgeDays   = isset($opts['max-age-days']) ? max(1, (int) $opts['max-age-days']) : SeedDeltaService::DEFAULT_RESWEEP_AGE_DAYS;
$stuckAfter   = isset($opts['stuck-after']) ? max(60, (int) $opts['stuck-after']) : SeedDeltaService::DEFAULT_STUCK_TILE_SECONDS;
$skipRecovery = array_key_exists('skip-recovery', $opts);
$quiet        = array_key_exists('quiet', $opts);

if ($campaignId === null && !$allCampaigns && $skipRecovery) {
    fwrite(STDERR, "Usage: seed-resweep.php (--campaign=ID | --all-campaigns) [--max-age-days=N] [--stuck-after=SECS] [--skip-recovery] [--quiet]\n");
    exit(2);
}

$svc = new SeedDeltaService();

WorkerHeartbeat::start('seed-resweep',
    ($campaignId ? "campaign=$campaignId " : ($allCampaigns ? 'all-campaigns ' : '')) .
    "max-age-days=$maxAgeDays stuck-after=$stuckAfter");

$recovered = 0;
if (!$skipRecovery) {
    $recovered = $svc->recoverStuckTiles($stuckAfter);
    if (!$quiet) {
        echo "Recovered $recovered stuck tile(s) (running > {$stuckAfter}s)\n";
    }
}

if ($campaignId !== null) {
    $n = $svc->scheduleResweepForCampaign((string) $campaignId, $maxAgeDays);
    if (!$quiet) echo "Re-queued $n tile(s) on campaign $campaignId (older than {$maxAgeDays}d)\n";
    WorkerHeartbeat::finish('seed-resweep', "recovered=$recovered requeued=$n");
    exit(0);
}

$total = 0;
if ($allCampaigns) {
    $cs = new SeedCampaignService();
    $rows = $cs->index(200, 0);
    foreach ($rows as $c) {
        if (!in_array($c['status'] ?? '', ['running','done','paused','approved'], true)) continue;
        $n = $svc->scheduleResweepForCampaign($c['id'], $maxAgeDays);
        $total += $n;
        if (!$quiet && $n > 0) {
            echo "  {$c['id']} ({$c['name']}): $n tile(s)\n";
        }
    }
    if (!$quiet) echo "Total re-queued: $total\n";
}

WorkerHeartbeat::finish('seed-resweep', "recovered=$recovered requeued=$total");
