<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));

/**
 * Fetches ACS 5-year tract demographics from Census Reporter (api.censusreporter.org).
 * No API key required. Designed as a backup when the official Census API key is unavailable.
 *
 *   php scripts/seed-census-reporter.php <state_fips>          # one state
 *   php scripts/seed-census-reporter.php all                   # all loaded states
 */

$arg = $argv[1] ?? null;
if (!$arg) {
    fwrite(STDERR, "Usage: seed-census-reporter.php <state_fips|all>\n");
    exit(1);
}

$db = Database::getInstance();

if ($arg === 'all') {
    $rows = $db->fetchAll('SELECT DISTINCT state_fips FROM census_tracts ORDER BY state_fips');
    $states = array_column($rows, 'state_fips');
} else {
    $states = [$arg];
}

/**
 * Tables we need and their column → DB-field mapping.
 * Census Reporter returns `data.{geo_id}.{table_id}.estimate.{column_id}`.
 */
const TABLES = ['B01003', 'B19013', 'B25077', 'B23025', 'B25001', 'B01001', 'B19001', 'B09001'];

function fetch_batch(array $geoids): ?array {
    $geoParam = implode(',', array_map(fn($g) => '14000US' . $g, $geoids));
    $url = 'https://api.censusreporter.org/1.0/data/show/latest'
         . '?table_ids=' . implode(',', TABLES)
         . '&geo_ids=' . $geoParam;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'smappen/1.0',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $code !== 200) {
        fwrite(STDERR, "  HTTP $code on batch of " . count($geoids) . "\n");
        return null;
    }
    $j = json_decode($resp, true);
    return is_array($j) ? $j : null;
}

function pluck(array $node, string $tableId, array $columns): ?int {
    $sum = 0;
    $hadAny = false;
    foreach ($columns as $col) {
        $v = $node[$tableId]['estimate'][$col] ?? null;
        if ($v !== null) { $sum += (int)$v; $hadAny = true; }
    }
    return $hadAny ? $sum : null;
}

function median(array $node, string $tableId, string $col): ?int {
    $v = $node[$tableId]['estimate'][$col] ?? null;
    if ($v === null || $v < 0) return null;
    return (int)$v;
}

foreach ($states as $stateFips) {
    echo "=== State $stateFips ===\n";
    $tractRows = $db->fetchAll('SELECT geoid FROM census_tracts WHERE state_fips = ? ORDER BY geoid', [$stateFips]);
    $tracts = array_column($tractRows, 'geoid');
    echo " " . count($tracts) . " tracts to fetch\n";

    $batches = array_chunk($tracts, 100);
    $done = 0;
    $upserted = 0;
    foreach ($batches as $i => $batch) {
        $result = fetch_batch($batch);
        if (!$result || empty($result['data'])) {
            sleep(1);
            $result = fetch_batch($batch);
        }
        if (!$result || empty($result['data'])) continue;

        foreach ($result['data'] as $geoIdKey => $node) {
            $geoid = preg_replace('/^14000US/', '', $geoIdKey);
            $data = [
                'geoid' => $geoid,
                'total_population'        => median($node, 'B01003', 'B01003001'),
                'male_total'              => median($node, 'B01001', 'B01001002'),
                'female_total'            => median($node, 'B01001', 'B01001026'),
                'median_household_income' => median($node, 'B19013', 'B19013001'),
                'median_home_value'       => median($node, 'B25077', 'B25077001'),
                'labor_force_total'       => median($node, 'B23025', 'B23025002'),
                'unemployed_total'        => median($node, 'B23025', 'B23025005'),
                'housing_units_total'     => median($node, 'B25001', 'B25001001'),
                'age_under_18'            => median($node, 'B09001', 'B09001001'),
                // 18-34 = M(7..12) + F(31..36)
                'age_18_to_34' => pluck($node, 'B01001',
                    ['B01001007','B01001008','B01001009','B01001010','B01001011','B01001012',
                     'B01001031','B01001032','B01001033','B01001034','B01001035','B01001036']),
                // 35-54 = M(13..16) + F(37..40)
                'age_35_to_54' => pluck($node, 'B01001',
                    ['B01001013','B01001014','B01001015','B01001016',
                     'B01001037','B01001038','B01001039','B01001040']),
                // 55-64 = M(17..19) + F(41..43)
                'age_55_to_64' => pluck($node, 'B01001',
                    ['B01001017','B01001018','B01001019','B01001041','B01001042','B01001043']),
                // 65+ = M(20..25) + F(44..49)
                'age_65_plus' => pluck($node, 'B01001',
                    ['B01001020','B01001021','B01001022','B01001023','B01001024','B01001025',
                     'B01001044','B01001045','B01001046','B01001047','B01001048','B01001049']),
                // Income brackets
                'income_under_25k'   => pluck($node, 'B19001', ['B19001002','B19001003','B19001004','B19001005']),
                'income_25k_to_50k'  => pluck($node, 'B19001', ['B19001006','B19001007','B19001008','B19001009','B19001010']),
                'income_50k_to_75k'  => pluck($node, 'B19001', ['B19001011','B19001012']),
                'income_75k_to_100k' => median($node, 'B19001', 'B19001013'),
                'income_100k_plus'   => pluck($node, 'B19001', ['B19001014','B19001015','B19001016','B19001017']),
                'data_year' => 2023,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($data)));
            $places = implode(',', array_fill(0, count($data), '?'));
            $updates = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", array_keys($data)));
            try {
                $db->query("INSERT INTO census_demographics ($cols) VALUES ($places) ON DUPLICATE KEY UPDATE $updates", array_values($data));
                $upserted++;
            } catch (\Throwable $e) {
                fwrite(STDERR, "  $geoid: " . $e->getMessage() . "\n");
            }
        }
        $done += count($batch);
        printf("\r [%d/%d] %d%% (upserted %d)", $done, count($tracts), (int)($done / count($tracts) * 100), $upserted);
        usleep(150_000); // 150ms between batches — be polite
    }
    echo "\n done state $stateFips: $upserted rows\n";
}

echo "\nAll done.\n";
