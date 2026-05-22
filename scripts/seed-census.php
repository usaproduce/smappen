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
                $sql = "INSERT IGNORE INTO census_tracts (geoid, state_fips, county_fips, tract_id, name, geometry)
                        VALUES (?, ?, ?, ?, ?, ST_GeomFromText(?, 4326))";
                $db->query($sql, [
                    $geoid, $stateFips, $countyFips, $tractId,
                    $props['NAME'] ?? $props['NAMELSAD'] ?? null,
                    $wkt,
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
