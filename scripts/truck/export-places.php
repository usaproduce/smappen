<?php
declare(strict_types=1);

/**
 * Exports the places of a region's live dataset (docs/truck-planner/03_DATA.md section 14).
 *
 *     php scripts/truck/export-places.php --region=dc > places.ndjson
 *
 * The places table is a database derived from OpenStreetMap. Its licence, the Open Database License,
 * asks that the derived database be offered to whoever asks for it: this file is that offer. The first
 * line states the licence and the attribution; every further line is one place as a JSON object, in
 * place_key order, with the fields of the pipeline's places.ndjson and the date of the snapshot. Host
 * vectors are not part of it: they are computed from census data as well and are not place data.
 *
 * Read-only. Exit codes: 0 success, 1 usage error or a region without a live dataset.
 */

require __DIR__ . '/_bootstrap.php';

use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\Support\JsonSafe;

$usage = <<<'TXT'
Usage: php scripts/truck/export-places.php --region=<id> > places.ndjson

  --region=<id>   the region whose live dataset is exported
TXT;

$options = tp_args(['region' => 'value'], $usage);
if (!isset($options['region'])) {
    tp_usage('--region is required', $usage);
}
$regionId = (string) $options['region'];

$regions = new RegionRepository();
$active = (new RegionService($regions))->active($regionId);
if ($active === null) {
    tp_fail('export-places: region ' . $regionId . ' has no live dataset', TP_EXIT_USAGE);
}
$version = $active['dataset_version'];
$meta = $regions->packMeta($regionId, $version);
$snapshot = $meta['vintages']['osm_snapshot_date'] ?? ($meta['osm_snapshot'] ?? null);

JsonSafe::shortestFloats();
$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

echo json_encode([
    'licence' => 'ODbL 1.0',
    'attribution' => '© OpenStreetMap contributors',
    'attribution_url' => 'https://www.openstreetmap.org/copyright',
    'notice' => 'Places derived from the OpenStreetMap snapshot of ' . ($snapshot ?? 'an unknown date')
        . ' (Geofabrik extracts). This database is made available under the Open Database License (ODbL) 1.0.',
    'region_id' => $regionId,
    'dataset_version' => $version,
    'osm_snapshot_date' => $snapshot,
    'rows' => $meta['place_count'] ?? null,
], $flags), "\n";

$places = new PlaceRepository();
$after = '';
$pageRows = 1000;
do {
    $page = $places->exportPage($regionId, $version, $after, $pageRows);
    foreach ($page as $row) {
        echo json_encode($row, $flags), "\n";
        $after = $row['place_key'];
    }
} while (count($page) === $pageRows);

exit(TP_EXIT_OK);
