<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\CensusService;
use App\Services\GeoUtils;

Config::load(dirname(__DIR__));

/**
 * Census seed script.
 *
 *  HOW TO GET TIGER/Line tract shapefiles:
 *    Download from: https://www2.census.gov/geo/tiger/TIGER2023/TRACT/
 *    Convert .shp to GeoJSON using ogr2ogr:
 *      ogr2ogr -f GeoJSON -t_srs EPSG:4326 out.geojson tl_2023_06_tract.shp
 *
 *  USAGE:
 *    php scripts/seed-census.php tracts <path/to/tracts.geojson>
 *    php scripts/seed-census.php demographics <state_fips>      # e.g. 06 for CA
 *    php scripts/seed-census.php all-states                      # iterate every state
 */

function nullable_int($v): ?int {
    if ($v === null || $v === '' || $v === -666666666 || $v === '-666666666') return null;
    return (int)$v;
}

function sum_ranges(array $row, array $keys): ?int {
    $sum = 0;
    $hadAny = false;
    foreach ($keys as $k) {
        $v = nullable_int($row[$k] ?? null);
        if ($v !== null) { $sum += $v; $hadAny = true; }
    }
    return $hadAny ? $sum : null;
}

function seed_state(string $stateFips): void {
    $svc = new CensusService();
    $db = Database::getInstance();
    try {
        $rows = $svc->fetchDemographicsForState($stateFips);
        echo " ↳ " . count($rows) . " tracts\n";
        foreach ($rows as $r) {
            $data = [
                'geoid' => $r['geoid'],
                'total_population' => nullable_int($r['B01003_001E'] ?? null),
                'male_total' => nullable_int($r['B01001_002E'] ?? null),
                'female_total' => nullable_int($r['B01001_026E'] ?? null),
                'median_household_income' => nullable_int($r['B19013_001E'] ?? null),
                'median_home_value' => nullable_int($r['B25077_001E'] ?? null),
                'labor_force_total' => nullable_int($r['B23025_002E'] ?? null),
                'unemployed_total' => nullable_int($r['B23025_005E'] ?? null),
                'housing_units_total' => nullable_int($r['B25001_001E'] ?? null),
                'age_under_18' => nullable_int($r['B09001_001E'] ?? null),
                'age_18_to_34' => sum_ranges($r, ['B01001_007E','B01001_008E','B01001_009E','B01001_010E','B01001_011E','B01001_012E','B01001_031E','B01001_032E','B01001_033E','B01001_034E','B01001_035E','B01001_036E']),
                'age_35_to_54' => sum_ranges($r, ['B01001_013E','B01001_014E','B01001_015E','B01001_016E','B01001_037E','B01001_038E','B01001_039E','B01001_040E']),
                'age_55_to_64' => sum_ranges($r, ['B01001_017E','B01001_018E','B01001_019E','B01001_041E','B01001_042E','B01001_043E']),
                'age_65_plus' => sum_ranges($r, ['B01001_020E','B01001_021E','B01001_022E','B01001_023E','B01001_024E','B01001_025E','B01001_044E','B01001_045E','B01001_046E','B01001_047E','B01001_048E','B01001_049E']),
                'data_year' => 2023,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($data)));
            $places = implode(',', array_fill(0, count($data), '?'));
            $updates = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", array_keys($data)));
            $sql = "INSERT INTO census_demographics ($cols) VALUES ($places) ON DUPLICATE KEY UPDATE $updates";
            $db->query($sql, array_values($data));
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "State $stateFips failed: " . $e->getMessage() . "\n");
    }
}

$cmd = $argv[1] ?? null;
$arg = $argv[2] ?? null;

$db = Database::getInstance();

switch ($cmd) {
    case 'tracts':
        if (!$arg || !file_exists($arg)) { fwrite(STDERR, "Need GeoJSON path\n"); exit(1); }
        echo "Loading tracts from $arg...\n";
        $json = json_decode(file_get_contents($arg), true);
        $features = $json['features'] ?? [];
        $total = count($features);
        $i = 0;
        foreach ($features as $f) {
            $i++;
            $props = $f['properties'] ?? [];
            $geoid = $props['GEOID'] ?? $props['GEOID10'] ?? null;
            if (!$geoid) continue;
            $stateFips = substr($geoid, 0, 2);
            $countyFips = substr($geoid, 2, 3);
            $tractId = substr($geoid, 5, 6);

            $geom = $f['geometry'];
            if ($geom['type'] === 'Polygon') {
                $geom = ['type' => 'MultiPolygon', 'coordinates' => [$geom['coordinates']]];
            }
            $wkt = GeoUtils::geoJsonToWkt($geom);
            try {
                $aland = isset($props['ALAND']) ? (float)$props['ALAND'] : null;
                $awater = isset($props['AWATER']) ? (float)$props['AWATER'] : null;
                $sql = "INSERT INTO census_tracts (geoid, state_fips, county_fips, tract_id, name, geometry, land_area_sqm, water_area_sqm)
                        VALUES (?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?)
                        ON DUPLICATE KEY UPDATE
                          geometry = VALUES(geometry),
                          land_area_sqm = VALUES(land_area_sqm),
                          water_area_sqm = VALUES(water_area_sqm)";
                $db->query($sql, [
                    $geoid, $stateFips, $countyFips, $tractId,
                    $props['NAME'] ?? $props['NAMELSAD'] ?? null,
                    $wkt, $aland, $awater,
                ]);
            } catch (\Throwable $e) {
                fwrite(STDERR, "Tract $geoid failed: " . $e->getMessage() . "\n");
            }
            if ($i % 100 === 0) printf("\r[%d/%d] %d%%", $i, $total, (int)($i / $total * 100));
        }
        echo "\nDone.\n";
        break;

    case 'demographics':
        if (!$arg) { fwrite(STDERR, "Need state FIPS\n"); exit(1); }
        seed_state($arg);
        break;

    case 'all-states':
        $states = ['01','02','04','05','06','08','09','10','11','12','13','15','16','17','18','19','20',
                   '21','22','23','24','25','26','27','28','29','30','31','32','33','34','35','36','37',
                   '38','39','40','41','42','44','45','46','47','48','49','50','51','53','54','55','56'];
        foreach ($states as $s) {
            echo "State $s...\n";
            seed_state($s);
            sleep(2);
        }
        break;

    default:
        echo "Usage:\n";
        echo "  php scripts/seed-census.php tracts <geojson>\n";
        echo "  php scripts/seed-census.php demographics <state_fips>\n";
        echo "  php scripts/seed-census.php all-states\n";
}
