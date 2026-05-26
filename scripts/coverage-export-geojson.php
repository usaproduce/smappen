<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

/**
 * Carafe coverage → line-delimited GeoJSON exporter. Spec v3 §12.5.
 *
 * Streams one Feature per line to stdout (or to --out=PATH). The
 * vector-tile pipeline pipes this into tippecanoe:
 *
 *   php scripts/coverage-export-geojson.php --tolerance=1km \
 *     | tippecanoe -o coverage_1km.pmtiles -z 10 -Z 5 \
 *         --drop-densest-as-needed --extend-zooms-if-still-dropping
 *
 * Tolerance picks which simplified column to use:
 *   --tolerance=full   simplified_100m fallback (the original geom)
 *   --tolerance=100m   simplified_100m   — city zoom
 *   --tolerance=1km    simplified_1km    — metro zoom
 *   --tolerance=10km   simplified_10km   — state zoom
 *
 * Properties on each Feature: vendor_id, vendor_name, type, primary_category,
 * coverage_type, travel_minutes, is_affiliated, aggregate_rating, confidence.
 * Empty/missing simplified columns fall back to the original geom.
 */

Config::load(dirname(__DIR__));
ini_set('memory_limit', '1024M');
set_time_limit(0);

$opts = getopt('', ['tolerance::', 'out::']);
$tolerance = (string) ($opts['tolerance'] ?? 'full');
$out       = $opts['out']       ?? null;

$columnByTolerance = [
    'full'  => 'geom',
    '100m'  => 'simplified_100m',
    '1km'   => 'simplified_1km',
    '10km'  => 'simplified_10km',
];
if (!isset($columnByTolerance[$tolerance])) {
    fwrite(STDERR, "Usage: coverage-export-geojson.php [--tolerance=full|100m|1km|10km] [--out=PATH]\n");
    exit(2);
}
$col = $columnByTolerance[$tolerance];

$db = Database::getInstance();
// Fall back to the original geom when the simplified column is null
// (not yet simplified). COALESCE lets the export still emit a feature
// for those rows, just at full precision.
$sql = "SELECT vc.id, vc.vendor_id, vc.coverage_type, vc.travel_minutes, vc.confidence,
               v.name AS vendor_name, v.type, v.primary_category, v.is_affiliated,
               v.aggregate_rating, v.rating_count,
               ST_AsGeoJSON(COALESCE(NULLIF(`$col`, 0), vc.geom), 5) AS geom_json
        FROM vendor_coverage vc
        JOIN vendors v ON v.id = vc.vendor_id
        WHERE v.merged_into IS NULL
          AND vc.geom IS NOT NULL";

$stmt = $db->pdo()->prepare($sql);
$stmt->execute();

$fh = $out !== null ? fopen($out, 'w') : STDOUT;
if ($fh === false) {
    fwrite(STDERR, "failed to open output: $out\n");
    exit(1);
}
$count = 0;
while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
    $geom = json_decode($r['geom_json'], true);
    if (!$geom) continue;
    $feature = [
        'type'       => 'Feature',
        'geometry'   => $geom,
        'properties' => [
            'coverage_id'      => $r['id'],
            'vendor_id'        => $r['vendor_id'],
            'vendor_name'      => $r['vendor_name'],
            'type'             => $r['type'],
            'primary_category' => $r['primary_category'],
            'coverage_type'    => $r['coverage_type'],
            'travel_minutes'   => $r['travel_minutes'] !== null ? (int) $r['travel_minutes'] : null,
            'is_affiliated'    => (int) ($r['is_affiliated'] ?? 0),
            'aggregate_rating' => $r['aggregate_rating'] !== null ? (float) $r['aggregate_rating'] : null,
            'rating_count'     => (int) ($r['rating_count'] ?? 0),
            'confidence'       => (int) ($r['confidence'] ?? 0),
        ],
    ];
    fwrite($fh, json_encode($feature, JSON_UNESCAPED_SLASHES) . "\n");
    $count++;
}
if ($fh !== STDOUT) fclose($fh);
fwrite(STDERR, "wrote $count feature(s)\n");
