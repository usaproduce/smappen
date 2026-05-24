<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));

/**
 * #1 + #10 — compute the 18-dim Analog Finder fingerprint for every tract
 * and write it to `tract_features`, plus refresh the
 * `analog_norm_stats` single-row materialized table that
 * `AnalogService::getNormalizationStats` reads.
 *
 * The Analog Finder cold-call used to rebuild these stats on demand —
 * sorting 84K density values + JSON-encoding them — adding ~5s to every
 * first-run analog query. Pre-computing nightly cuts that to a single
 * SELECT.
 *
 * Re-runnable. Truncates `tract_features` first to handle deletions.
 */

$db = Database::getInstance();
echo "Refreshing tract_features + analog_norm_stats…\n";

// ── 1. Global min/max + sorted-density list ──────────────────────────────
echo "Computing global normalization stats…\n";
$stats = $db->fetch("
    SELECT
        MIN(cd.total_population / GREATEST(ct.land_area_sqm / 1000000, 0.01)) AS density_min,
        MAX(cd.total_population / GREATEST(ct.land_area_sqm / 1000000, 0.01)) AS density_max,
        MIN(cd.median_household_income) AS income_min,
        MAX(cd.median_household_income) AS income_max,
        MIN(cd.median_home_value) AS home_value_min,
        MAX(cd.median_home_value) AS home_value_max
    FROM census_tracts ct
    JOIN census_demographics cd ON ct.geoid = cd.geoid
    WHERE cd.total_population > 100
");

$densities = $db->fetchAll("
    SELECT cd.total_population / GREATEST(ct.land_area_sqm / 1000000, 0.01) AS d
    FROM census_tracts ct
    JOIN census_demographics cd ON ct.geoid = cd.geoid
    WHERE cd.total_population > 100
    ORDER BY d
");
$densityArr = array_map(fn($r) => (float)$r['d'], $densities);
$densityBlob = gzcompress(json_encode($densityArr), 6);

$db->query('REPLACE INTO analog_norm_stats
    (id, density_min, density_max, income_min, income_max, home_value_min, home_value_max, density_values, computed_at)
    VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)',
    [
        (float)$stats['density_min'], (float)$stats['density_max'],
        (float)$stats['income_min'],  (float)$stats['income_max'],
        (float)$stats['home_value_min'], (float)$stats['home_value_max'],
        $densityBlob, date('Y-m-d H:i:s'),
    ]
);
printf("  stats: %d densities cached, range %.0f..%.0f /km²\n",
    count($densityArr), $stats['density_min'], $stats['density_max']);

// ── 2. Per-tract feature vectors. Compute in PHP from the same SQL the ──
//      AnalogService uses; iterate in 2,000-row chunks so we don't blow up
//      memory. Density percentile rank uses a binary search against the
//      sorted array above. ────────────────────────────────────────────────
echo "Computing tract feature vectors…\n";
$db->query('TRUNCATE TABLE tract_features');

$chunkSize = 2000;
$offset = 0;
$total = 0;
$now = date('Y-m-d H:i:s');

$pctRank = function (float $val) use ($densityArr): float {
    $n = count($densityArr);
    if ($n === 0) return 0.5;
    $lo = 0; $hi = $n;
    while ($lo < $hi) {
        $mid = ($lo + $hi) >> 1;
        if ($densityArr[$mid] <= $val) $lo = $mid + 1;
        else $hi = $mid;
    }
    return $lo / $n;
};

$mm = fn($v, $min, $max) => $max > $min ? max(0.0, min(1.0, ($v - $min) / ($max - $min))) : 0.5;

while (true) {
    $rows = $db->fetchAll("
        SELECT ct.geoid, ct.land_area_sqm,
               cd.total_population, cd.median_household_income, cd.median_home_value,
               cd.labor_force_total, cd.unemployed_total,
               cd.age_under_18, cd.age_18_to_34, cd.age_35_to_54, cd.age_55_to_64, cd.age_65_plus,
               cd.income_under_25k, cd.income_25k_to_50k, cd.income_100k_plus,
               ts.segment_name
        FROM census_tracts ct
        JOIN census_demographics cd ON ct.geoid = cd.geoid
        LEFT JOIN tract_segments ts ON ct.geoid = ts.geoid
        WHERE cd.total_population > 100
        ORDER BY ct.geoid
        LIMIT $chunkSize OFFSET $offset
    ");
    if (empty($rows)) break;

    $batch = [];
    $placeholders = [];
    foreach ($rows as $r) {
        $pop = (int)$r['total_population'];
        $landKm2 = max(((float)$r['land_area_sqm']) / 1_000_000, 0.01);
        $segIdx = self_segmentIndex($r['segment_name'] ?? '');
        $affl   = self_affluence($r['segment_name'] ?? '');
        $safe = fn($n, $d) => $d > 0 ? $n / $d : 0.0;

        $vec = [
            $r['geoid'],
            $pctRank($pop / $landKm2),
            $mm((float)$r['median_household_income'], (float)$stats['income_min'], (float)$stats['income_max']),
            $mm((float)$r['median_home_value'], (float)$stats['home_value_min'], (float)$stats['home_value_max']),
            min(max($safe((int)$r['unemployed_total'], (int)$r['labor_force_total']), 0.0), 1.0),
            $safe((int)$r['age_under_18'], $pop),
            $safe((int)$r['age_18_to_34'], $pop),
            $safe((int)$r['age_35_to_54'], $pop),
            $safe((int)$r['age_55_to_64'], $pop),
            $safe((int)$r['age_65_plus'], $pop),
            $safe(((int)$r['income_under_25k']) + ((int)$r['income_25k_to_50k']), $pop),
            $safe((int)$r['income_100k_plus'], $pop),
            $segIdx / 9.0,
            1.0,        // single-tract concentration = 100%
            $affl,
            null,       // POI density — populated by a separate periodic job
            null,       // category diversity — same
            0.5,        // baseline traffic penalty — refined per-area later
            null,       // reach population — populated by a separate job
            $now,
        ];
        foreach ($vec as $v) $batch[] = $v;
        $placeholders[] = '(' . implode(',', array_fill(0, 20, '?')) . ')';
    }
    $sql = "INSERT INTO tract_features
        (geoid, density_norm, income_norm, home_value_norm, unemployment_norm,
         pct_under_18, pct_18_34, pct_35_54, pct_55_64, pct_65_plus,
         pct_income_low, pct_income_high,
         segment_dominant_norm, segment_concentration, affluence_index,
         poi_density_norm, category_diversity, traffic_penalty_norm,
         reach_population_norm, computed_at)
         VALUES " . implode(',', $placeholders);
    $db->query($sql, $batch);

    $total += count($rows);
    $offset += $chunkSize;
    if ($total % 10000 === 0) printf("  …%d tracts\n", $total);
}

echo "\nDONE. $total tract feature vectors materialized.\n";

function self_segmentIndex(string $name): int
{
    static $m = [
        'affluent-suburbs'=>0,'urban-professionals'=>1,'family-suburbs'=>2,
        'working-class-urban'=>3,'rural-stable'=>4,'retirement'=>5,
        'college-towns'=>6,'low-income-urban'=>7,'moderate-suburbs'=>8,'emerging-growth'=>9,
    ];
    return $m[$name] ?? 8;
}
function self_affluence(string $name): float
{
    static $w = [
        'affluent-suburbs'=>1.0,'urban-professionals'=>0.7,'family-suburbs'=>0.3,
        'moderate-suburbs'=>0.2,'college-towns'=>0.4,'retirement'=>0.4,
        'rural-stable'=>0.15,'working-class-urban'=>0.10,'low-income-urban'=>0.05,'emerging-growth'=>0.30,
    ];
    return $w[$name] ?? 0.2;
}
