<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\GeoUtils;

Config::load(dirname(__DIR__));

/**
 * One-shot DMV geometry normalization (v2 — correct direction).
 *
 * MySQL 8 SRID 4326 axis behavior (empirically verified):
 *
 *   • Default ST_GeomFromText validates first WKT value as latitude (±90)
 *     and second as longitude (±180). Stores X=first, Y=second positionally.
 *   • ST_AsGeoJSON without axis-option returns [Y, X] (i.e., second-position
 *     value first) — MySQL emits SRID 4326 in EPSG canonical lat-first order.
 *
 *   • DMV original ingest (seed-census.php pre-BF1): WKT "lng lat" → MySQL
 *     happened to accept because DMV's |lng|<90. Stored X=lng, Y=lat.
 *   • New-state ingest (seed-census.php post-BF1, with pre-swap): WKT
 *     "lat lng" → validates fine for all states. Stored X=lat, Y=lng.
 *
 * The two conventions are POSITIONAL OPPOSITES. The heatmap viewport WKT
 * (built in HeatmapController as "lat lng" after BF1) matches the new-state
 * storage but misses every DMV tract.
 *
 * Fix: rewrite DMV geometries to match the new-state convention. For each
 * DMV tract, we:
 *
 *   1. ST_AsGeoJSON returns coordinates in [Y, X] = [lat_value, lng_value]
 *      ORDER (per MySQL EPSG canonical emission), regardless of storage.
 *   2. Feed those same coords back into ST_GeomFromText AS-IS, no swap.
 *      WKT "lat lng" → validates OK → stores X=lat, Y=lng (new convention).
 *   3. ST_AsGeoJSON of the new storage still emits [Y, X] = [lng, lat]
 *      because Y was lng — and that's now actually STANDARD GeoJSON.
 *
 * After this script the heatmap WKT will ST_Intersects DMV correctly.
 *
 * Re-runnable: idempotency check verifies the first vertex's stored X-coord
 * looks like a latitude (|X| ≤ 90) before swapping. If the row is already
 * normalized, we skip.
 */

$db = Database::getInstance();
$states = ['11', '24', '51', '54'];

$total = 0;
$fixed = 0;
$skipped = 0;

foreach ($states as $fips) {
    // Probe one tract to test whether ST_Intersects expects "lng lat" or
    // "lat lng" against this state's storage. We hit a tiny bbox guaranteed
    // to overlap a real DMV/WV tract centroid; if the lat-first WKT misses,
    // we know the storage is still in the OLD (lng-first) convention and
    // needs to be flipped.
    $probe = $db->fetch("SELECT ST_Intersects(geometry, ST_GeomFromText('POLYGON((37 -78, 37 -76, 39 -76, 39 -78, 37 -78))', 4326)) AS hit
                          FROM census_tracts WHERE state_fips = ? LIMIT 1", [$fips]);
    if (is_array($probe) && (int)($probe['hit'] ?? 0) === 1) {
        echo "STATE $fips: already in new convention — skipping\n";
        $skipped += $db->fetch('SELECT COUNT(*) AS n FROM census_tracts WHERE state_fips = ?', [$fips])['n'] ?? 0;
        continue;
    }
    $rows = $db->fetchAll(
        "SELECT geoid, ST_AsGeoJSON(geometry) AS gj
           FROM census_tracts WHERE state_fips = ?",
        [$fips]
    );
    echo "STATE $fips: " . count($rows) . " tracts — flipping\n";
    foreach ($rows as $r) {
        $total++;
        $geom = json_decode($r['gj'], true);
        if (!$geom || !isset($geom['coordinates'])) { $skipped++; continue; }
        // ST_AsGeoJSON already returns coordinates as [Y, X] pairs which,
        // given the OLD storage (Y=lat-value), happens to be in [lat, lng]
        // order. Feed that string directly back as WKT — no swap. New
        // storage will be X=lat, Y=lng (matching the post-BF1 convention).
        $wkt = GeoUtils::geoJsonToWkt($geom);
        try {
            $db->query(
                'UPDATE census_tracts SET geometry = ST_GeomFromText(?, 4326) WHERE geoid = ?',
                [$wkt, $r['geoid']]
            );
            $fixed++;
            if ($fixed % 200 === 0) printf("  …%d fixed\n", $fixed);
        } catch (\Throwable $e) {
            fwrite(STDERR, "  geoid {$r['geoid']} failed: " . $e->getMessage() . "\n");
        }
    }
}

echo "\nDONE\n";
echo "  total considered: $total\n";
echo "  fixed:            $fixed\n";
echo "  skipped (already-normalized): $skipped\n";
