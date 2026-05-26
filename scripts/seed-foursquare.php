<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\FoursquareAdapter;
use App\Services\SeedCampaignService;
use App\Services\VendorImportPipeline;

/**
 * Carafe Foursquare bulk import. Spec v3 §2 + §9 step 10.
 *
 * Modes:
 *   --campaign=ID                                Use campaign bbox + types
 *   --bbox=lat1,lng1,lat2,lng2 --types=t1,t2     Ad-hoc region
 *
 * Each call costs ~$0.0049 (see FoursquareAdapter::PER_CALL_COST_USD).
 * Cost is recorded to api_cost_events with sku='foursquare_search'
 * so the run dashboard surfaces it alongside Google Places spend.
 *
 * Requires FOURSQUARE_API_KEY in env.
 *
 * Args:
 *   --campaign=ID
 *   --bbox=latMin,lngMin,latMax,lngMax
 *   --types=t1,t2,...
 *   --limit=N    per-request result cap (max 50)
 *   --quiet
 */

Config::load(dirname(__DIR__));
ini_set('memory_limit', '512M');
set_time_limit(0);

$opts = getopt('', ['campaign::', 'bbox::', 'types::', 'limit::', 'quiet']);
$campaignId = $opts['campaign'] ?? null;
$bboxArg    = $opts['bbox']     ?? null;
$typesArg   = $opts['types']    ?? null;
$limit      = max(1, min(50, (int) ($opts['limit'] ?? 50)));
$quiet      = array_key_exists('quiet', $opts);

if ($campaignId === null && ($bboxArg === null || $typesArg === null)) {
    fwrite(STDERR, "Usage: seed-foursquare.php (--campaign=ID | --bbox=latMin,lngMin,latMax,lngMax --types=t1,t2) [--limit=N] [--quiet]\n");
    exit(2);
}

$bbox  = null;
$types = [];

if ($campaignId !== null) {
    $cs = new SeedCampaignService();
    $c  = $cs->findById((string) $campaignId);
    if (!$c) {
        fwrite(STDERR, "campaign $campaignId not found\n");
        exit(2);
    }
    $bbox  = [(float) $c['bbox_lat_min'], (float) $c['bbox_lng_min'], (float) $c['bbox_lat_max'], (float) $c['bbox_lng_max']];
    $types = json_decode($c['vendor_types_json'] ?? '[]', true) ?: [];
} else {
    $bbox  = array_map('floatval', explode(',', (string) $bboxArg));
    $types = array_filter(array_map('trim', explode(',', (string) $typesArg)));
}

try {
    $adapter = new FoursquareAdapter();
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$started = microtime(true);
$places  = [];
try {
    $places = $adapter->discover($types, $bbox, $limit);
} catch (\Throwable $e) {
    fwrite(STDERR, "Foursquare discover failed: " . $e->getMessage() . "\n");
    exit(1);
}
if (!$quiet) {
    $elapsed = round(microtime(true) - $started, 1);
    echo "Foursquare returned " . count($places) . " place(s) in {$elapsed}s\n";
}

$pipeline = new VendorImportPipeline();
$tally    = $pipeline->importBatch($places, 'foursquare');
if (!$quiet) {
    echo "imported: " . json_encode($tally) . "\n";
}
