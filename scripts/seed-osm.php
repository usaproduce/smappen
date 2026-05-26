<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\OSMAdapter;
use App\Services\SeedCampaignService;
use App\Services\VendorImportPipeline;

/**
 * Carafe OSM bulk import. Spec v3 §2 + §9 step 10.
 *
 * Modes:
 *   --campaign=ID         Discover OSM within that campaign's bbox + types
 *   --bbox=lat1,lng1,lat2,lng2 --types=produce,meat   Ad-hoc region/types
 *
 * OSM Overpass is free + unmetered (rate-limited), so this script
 * doesn't honor a per-call budget. The Overpass servers rate-limit
 * politely; if you hit a 429 the script logs and continues. Run from
 * cron weekly per region.
 *
 * Args:
 *   --campaign=ID
 *   --bbox=latMin,lngMin,latMax,lngMax
 *   --types=t1,t2,...
 *   --quiet
 */

Config::load(dirname(__DIR__));
ini_set('memory_limit', '1024M');
set_time_limit(0);

$opts = getopt('', ['campaign::', 'bbox::', 'types::', 'quiet']);
$campaignId = $opts['campaign']  ?? null;
$bboxArg    = $opts['bbox']      ?? null;
$typesArg   = $opts['types']     ?? null;
$quiet      = array_key_exists('quiet', $opts);

if ($campaignId === null && ($bboxArg === null || $typesArg === null)) {
    fwrite(STDERR, "Usage: seed-osm.php (--campaign=ID | --bbox=latMin,lngMin,latMax,lngMax --types=t1,t2) [--quiet]\n");
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
    $bbox = array_map('floatval', explode(',', (string) $bboxArg));
    $types = array_filter(array_map('trim', explode(',', (string) $typesArg)));
}

$adapter = new OSMAdapter();
$started = microtime(true);
try {
    $places = $adapter->discover($types, $bbox);
} catch (\Throwable $e) {
    fwrite(STDERR, "OSM discover failed: " . $e->getMessage() . "\n");
    exit(1);
}
if (!$quiet) {
    $elapsed = round(microtime(true) - $started, 1);
    echo "OSM returned " . count($places) . " place(s) in {$elapsed}s\n";
}

$pipeline = new VendorImportPipeline();
$tally    = $pipeline->importBatch($places, 'osm');
if (!$quiet) {
    echo "imported: " . json_encode($tally) . "\n";
}
