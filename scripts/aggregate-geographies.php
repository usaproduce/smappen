<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\GeoUtils;

Config::load(dirname(__DIR__));

/**
 * Build county/state aggregation tables for zoom-based LOD.
 *
 *   php scripts/aggregate-geographies.php counties <path/to/counties.geojson>
 *   php scripts/aggregate-geographies.php states   <path/to/states.geojson>
 *   php scripts/aggregate-geographies.php demographics   # roll up tract demos into counties+states
 *
 * Download TIGER 2023 national shapefiles:
 *   https://www2.census.gov/geo/tiger/TIGER2023/COUNTY/tl_2023_us_county.zip
 *   https://www2.census.gov/geo/tiger/TIGER2023/STATE/tl_2023_us_state.zip
 * Convert with:  ogr2ogr -f GeoJSON -t_srs EPSG:4326 counties.geojson tl_2023_us_county.shp
 */

$cmd = $argv[1] ?? null;
$arg = $argv[2] ?? null;
$db = Database::getInstance();

function load_counties(string $path): void {
    $db = Database::getInstance();
    echo "Loading counties from $path…\n";
    $json = json_decode(file_get_contents($path), true);
    $features = $json['features'] ?? [];
    $total = count($features);
    $i = 0;
    foreach ($features as $f) {
        $i++;
        $props = $f['properties'] ?? [];
        $stateFips = $props['STATEFP'] ?? null;
        $countyFips = $props['COUNTYFP'] ?? null;
        if (!$stateFips || !$countyFips) continue;
        $geoid = $stateFips . $countyFips;
        $name = $props['NAMELSAD'] ?? $props['NAME'] ?? null;
        $aland = isset($props['ALAND']) ? (float)$props['ALAND'] : null;

        $geom = $f['geometry'];
        if ($geom['type'] === 'Polygon') {
            $geom = ['type' => 'MultiPolygon', 'coordinates' => [$geom['coordinates']]];
        }
        // See seed-census.php — pre-swap to dodge MySQL 8 strict SRID 4326
        // axis order ("lat lng") choking on |lng| > 90.
        $geom = GeoUtils::swapGeometry($geom);
        $wkt = GeoUtils::geoJsonToWkt($geom);
        try {
            $db->query(
                "INSERT INTO census_counties (geoid, state_fips, county_fips, name, geometry, land_area_sqm, tract_count, updated_at)
                 VALUES (?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, 0, ?)
                 ON DUPLICATE KEY UPDATE
                   geometry = VALUES(geometry),
                   land_area_sqm = VALUES(land_area_sqm),
                   name = VALUES(name),
                   updated_at = VALUES(updated_at)",
                [$geoid, $stateFips, $countyFips, $name, $wkt, $aland, date('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, "  $geoid: " . $e->getMessage() . "\n");
        }
        if ($i % 100 === 0) printf("\r [%d/%d] %d%%", $i, $total, (int)($i / $total * 100));
    }
    echo "\nLoaded $i county polygons.\n";
}

function load_states(string $path): void {
    $db = Database::getInstance();
    echo "Loading states from $path…\n";
    $json = json_decode(file_get_contents($path), true);
    $features = $json['features'] ?? [];
    foreach ($features as $f) {
        $props = $f['properties'] ?? [];
        $stateFips = $props['STATEFP'] ?? null;
        if (!$stateFips) continue;
        $name = $props['NAME'] ?? null;
        $aland = isset($props['ALAND']) ? (float)$props['ALAND'] : null;
        $geom = $f['geometry'];
        if ($geom['type'] === 'Polygon') {
            $geom = ['type' => 'MultiPolygon', 'coordinates' => [$geom['coordinates']]];
        }
        // See seed-census.php — pre-swap to dodge MySQL 8 strict SRID 4326
        // axis order ("lat lng") choking on |lng| > 90.
        $geom = GeoUtils::swapGeometry($geom);
        $wkt = GeoUtils::geoJsonToWkt($geom);
        try {
            $db->query(
                "INSERT INTO census_states (state_fips, name, geometry, land_area_sqm, tract_count, updated_at)
                 VALUES (?, ?, ST_GeomFromText(?, 4326), ?, 0, ?)
                 ON DUPLICATE KEY UPDATE
                   geometry = VALUES(geometry),
                   land_area_sqm = VALUES(land_area_sqm),
                   name = VALUES(name),
                   updated_at = VALUES(updated_at)",
                [$stateFips, $name, $wkt, $aland, date('Y-m-d H:i:s')]
            );
            echo "  $stateFips $name ✓\n";
        } catch (\Throwable $e) {
            fwrite(STDERR, "  $stateFips: " . $e->getMessage() . "\n");
        }
    }
}

function roll_up_demographics(): void {
    $db = Database::getInstance();
    echo "Rolling up demographics: tracts → counties…\n";
    $sql = "
      SELECT ct.state_fips, ct.county_fips,
        COALESCE(SUM(d.total_population), 0) AS pop,
        SUM(d.median_household_income * d.total_population) AS num_income,
        SUM(CASE WHEN d.median_household_income IS NOT NULL THEN d.total_population ELSE 0 END) AS den_income,
        SUM(d.median_home_value * d.total_population) AS num_home,
        SUM(CASE WHEN d.median_home_value IS NOT NULL THEN d.total_population ELSE 0 END) AS den_home,
        COALESCE(SUM(d.labor_force_total), 0) AS labor,
        COALESCE(SUM(d.unemployed_total), 0) AS unemp,
        COALESCE(SUM(d.housing_units_total), 0) AS housing,
        COUNT(*) AS tracts
      FROM census_tracts ct
      LEFT JOIN census_demographics d ON d.geoid = ct.geoid
      GROUP BY ct.state_fips, ct.county_fips
    ";
    $rows = $db->fetchAll($sql);
    $u = 0;
    foreach ($rows as $r) {
        $geoid = $r['state_fips'] . $r['county_fips'];
        $medianIncome = ($r['den_income'] > 0) ? (int)round($r['num_income'] / $r['den_income']) : null;
        $medianHome = ($r['den_home'] > 0) ? (int)round($r['num_home'] / $r['den_home']) : null;
        try {
            $db->query(
                "UPDATE census_counties SET
                   total_population = ?, median_household_income = ?, median_home_value = ?,
                   labor_force_total = ?, unemployed_total = ?, housing_units_total = ?,
                   tract_count = ?, updated_at = ?
                 WHERE geoid = ?",
                [
                    (int)$r['pop'], $medianIncome, $medianHome,
                    (int)$r['labor'], (int)$r['unemp'], (int)$r['housing'],
                    (int)$r['tracts'], date('Y-m-d H:i:s'),
                    $geoid,
                ]
            );
            $u++;
        } catch (\Throwable $e) { fwrite(STDERR, "  $geoid: ".$e->getMessage()."\n"); }
    }
    echo "  $u counties updated\n";

    echo "Rolling up: counties → states…\n";
    $sql2 = "
      SELECT c.state_fips,
        COALESCE(SUM(c.total_population), 0) AS pop,
        SUM(c.median_household_income * c.total_population) AS num_income,
        SUM(CASE WHEN c.median_household_income IS NOT NULL THEN c.total_population ELSE 0 END) AS den_income,
        SUM(c.median_home_value * c.total_population) AS num_home,
        SUM(CASE WHEN c.median_home_value IS NOT NULL THEN c.total_population ELSE 0 END) AS den_home,
        COALESCE(SUM(c.labor_force_total), 0) AS labor,
        COALESCE(SUM(c.unemployed_total), 0) AS unemp,
        COALESCE(SUM(c.housing_units_total), 0) AS housing,
        COALESCE(SUM(c.tract_count), 0) AS tracts
      FROM census_counties c
      WHERE c.total_population IS NOT NULL
      GROUP BY c.state_fips
    ";
    $rows2 = $db->fetchAll($sql2);
    $u2 = 0;
    foreach ($rows2 as $r) {
        $medianIncome = ($r['den_income'] > 0) ? (int)round($r['num_income'] / $r['den_income']) : null;
        $medianHome = ($r['den_home'] > 0) ? (int)round($r['num_home'] / $r['den_home']) : null;
        try {
            $db->query(
                "UPDATE census_states SET
                   total_population = ?, median_household_income = ?, median_home_value = ?,
                   labor_force_total = ?, unemployed_total = ?, housing_units_total = ?,
                   tract_count = ?, updated_at = ?
                 WHERE state_fips = ?",
                [
                    (int)$r['pop'], $medianIncome, $medianHome,
                    (int)$r['labor'], (int)$r['unemp'], (int)$r['housing'],
                    (int)$r['tracts'], date('Y-m-d H:i:s'),
                    $r['state_fips'],
                ]
            );
            $u2++;
        } catch (\Throwable $e) { fwrite(STDERR, "  ".$r['state_fips'].": ".$e->getMessage()."\n"); }
    }
    echo "  $u2 states updated\n";
}

switch ($cmd) {
    case 'counties':
        if (!$arg || !file_exists($arg)) { fwrite(STDERR, "Need counties.geojson path\n"); exit(1); }
        load_counties($arg);
        break;
    case 'states':
        if (!$arg || !file_exists($arg)) { fwrite(STDERR, "Need states.geojson path\n"); exit(1); }
        load_states($arg);
        break;
    case 'demographics':
        roll_up_demographics();
        break;
    default:
        echo "Usage:\n";
        echo "  php scripts/aggregate-geographies.php counties <geojson>\n";
        echo "  php scripts/aggregate-geographies.php states   <geojson>\n";
        echo "  php scripts/aggregate-geographies.php demographics\n";
        exit(1);
}
echo "Done.\n";
