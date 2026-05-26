<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\PlacesEnrichService;
use App\Services\SeedCampaignService;

/**
 * Carafe enrich worker. Spec v3 §4.4 + §9 step 7.
 *
 * Three modes, picked at invocation:
 *
 *   --campaign=ID
 *       Apply that campaign's enrich_policy across its in-bbox
 *       candidate vendors (all | priority_types | on_demand→noop).
 *       Stops + pauses the campaign on budget-cap halt.
 *
 *   --refresh-tier=cold|warm|hot
 *       Nightly refresh — re-pull stale rows for one tier with a
 *       narrow tier-specific mask. Should be scheduled out of band
 *       from sweep workers so the QPS bucket isn't contended.
 *
 *   --all-campaigns
 *       Apply enrich_policy to every campaign currently in 'running'
 *       status. Convenient for a single nightly cron entry.
 *
 * Args:
 *   --batch-size=N    default 100 (campaign mode) / 200 (refresh mode)
 *   --quiet
 */

Config::load(dirname(__DIR__));
ini_set('memory_limit', '512M');
set_time_limit(0);

$opts = getopt('', ['campaign::', 'refresh-tier::', 'all-campaigns', 'batch-size::', 'quiet']);
$campaignId   = $opts['campaign']      ?? null;
$refreshTier  = $opts['refresh-tier']  ?? null;
$allCampaigns = array_key_exists('all-campaigns', $opts);
$batchSize    = isset($opts['batch-size']) ? max(1, (int) $opts['batch-size']) : null;
$quiet        = array_key_exists('quiet', $opts);

if ($campaignId === null && $refreshTier === null && !$allCampaigns) {
    fwrite(STDERR, "Usage: seed-enrich.php (--campaign=ID | --refresh-tier=cold|warm|hot | --all-campaigns) [--batch-size=N] [--quiet]\n");
    exit(2);
}

$svc = new PlacesEnrichService();

if ($refreshTier !== null) {
    $started = microtime(true);
    $tally   = $svc->refreshStaleTier((string) $refreshTier, $batchSize ?? 200);
    $elapsed = round(microtime(true) - $started, 1);
    if (!$quiet) {
        echo "tier-refresh $refreshTier: " . json_encode($tally) . " in {$elapsed}s\n";
    }
    exit(0);
}

if ($allCampaigns) {
    $cs = new SeedCampaignService();
    $rows = $cs->index(50, 0);
    $running = array_filter($rows, fn ($r) => ($r['status'] ?? '') === 'running');
    if (empty($running) && !$quiet) {
        echo "no running campaigns\n";
    }
    foreach ($running as $c) {
        $started = microtime(true);
        $tally = $svc->enrichCampaign($c['id'], $batchSize ?? 100);
        $elapsed = round(microtime(true) - $started, 1);
        if (!$quiet) {
            echo "campaign {$c['id']} ({$c['name']}): " . json_encode($tally) . " in {$elapsed}s\n";
        }
    }
    exit(0);
}

// Single campaign
$started = microtime(true);
$tally   = $svc->enrichCampaign((string) $campaignId, $batchSize ?? 100);
$elapsed = round(microtime(true) - $started, 1);
if (!$quiet) {
    echo "campaign $campaignId: " . json_encode($tally) . " in {$elapsed}s\n";
}
