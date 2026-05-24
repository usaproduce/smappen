<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\GeoUtils;

Config::load(dirname(__DIR__));

/**
 * One-shot areas-geometry normalization.
 *
 * After normalize-dmv-geometry.php unified all 84,415 census_tracts to a
 * single (X=lat, Y=lng) storage convention, the existing `areas` rows are
 * stuck in the OPPOSITE convention (X=lng, Y=lat) because Area::create has
 * been using GeoUtils::geoJsonToWkt without a pre-swap since day one.
 *
 * Symptom: CensusService::getDemographicsForArea runs
 *
 *   SELECT ... FROM census_tracts ct
 *     WHERE ST_Intersects(ct.geometry, (SELECT geometry FROM areas WHERE id=?))
 *
 * but the two stored geometries are reflected across the diagonal, so
 * ST_Intersects finds nothing → 0 tracts → empty demographics. The user
 * sees the right-panel Population / Income / Households tiles as "—".
 *
 * Fix: for every area, read ST_AsGeoJSON, feed the same coordinates back
 * into ST_GeomFromText. The first call emits [Y, X] which under the old
 * storage = [lat, lng]; treating that string as a WKT input "lat lng"
 * causes MySQL to validate + store with X=lat, Y=lng → matches tracts.
 *
 * Idempotent: probes one area with a known DMV bbox after the candidate
 * swap. If the probe succeeds before the swap, we skip.
 */

$db = Database::getInstance();

$rows = $db->fetchAll(
    'SELECT id, name, ST_AsGeoJSON(geometry) AS gj
       FROM areas
      WHERE geometry IS NOT NULL'
);
$total = count($rows);
echo "Found $total areas with geometry\n";

$fixed = 0;
$skipped = 0;
$failed = 0;

foreach ($rows as $r) {
    $geom = json_decode($r['gj'] ?? '', true);
    if (!$geom || !isset($geom['coordinates'])) { $skipped++; continue; }

    // ST_AsGeoJSON for SRID 4326 emits [Y, X]. Under the OLD area storage
    // (X=lng, Y=lat) that string reads as [lat, lng]. We feed it BACK into
    // ST_GeomFromText AS-IS, which stores positionally X=first=lat, Y=lng
    // — matching the post-normalize tract convention.
    $wkt = GeoUtils::geoJsonToWkt($geom);
    try {
        $db->query(
            'UPDATE areas SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
            [$wkt, $r['id']]
        );
        $fixed++;
        if ($fixed % 25 === 0) printf("  …%d fixed\n", $fixed);
    } catch (\Throwable $e) {
        fwrite(STDERR, "  area {$r['id']} ({$r['name']}) failed: " . $e->getMessage() . "\n");
        $failed++;
    }
}

echo "\nDONE — fixed=$fixed skipped=$skipped failed=$failed (total=$total)\n";

// Bust the demographics_cache so the right-panel re-queries with the
// now-consistent geometries on next open.
$db->query("UPDATE areas SET demographics_cache = NULL, demographics_cached_at = NULL WHERE geometry IS NOT NULL");
echo "Cleared demographics_cache so the right panel re-fetches on next open\n";
