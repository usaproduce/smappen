<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));

/**
 * Aggregate census_tracts → census_counties → census_states.
 * Run after loading new tracts. Idempotent (REPLACE INTO).
 *
 *   php scripts/aggregate-geographies.php counties
 *   php scripts/aggregate-geographies.php states
 *   php scripts/aggregate-geographies.php all
 */

$cmd = $argv[1] ?? 'all';
$db = Database::getInstance();

function aggregate_counties(): void {
    $db = Database::getInstance();
    echo "Aggregating tracts → counties…\n";
    // Distinct (state_fips, county_fips) pairs.
    $pairs = $db->fetchAll('SELECT DISTINCT state_fips, county_fips FROM census_tracts ORDER BY state_fips, county_fips');
    echo "  " . count($pairs) . " counties\n";

    $upserted = 0;
    foreach ($pairs as $i => $p) {
        $stateFips = $p['state_fips'];
        $countyFips = $p['county_fips'];
        $geoid = $stateFips . $countyFips;

        // ST_Union all tracts in this county.
        // For population-weighted median income, weight by tract population.
        $sql = "
          SELECT
            ST_AsText(ST_Union(ct.geometry)) AS wkt,
            COALESCE(SUM(d.total_population), 0) AS pop,
            CASE WHEN SUM(d.total_population * CASE WHEN d.median_household_income IS NOT NULL THEN 1 ELSE 0 END) > 0 THEN
              SUM(d.median_household_income * d.total_population)
              / NULLIF(SUM(CASE WHEN d.median_household_income IS NOT NULL THEN d.total_population ELSE 0 END), 0)
            END AS median_income,
            CASE WHEN SUM(d.total_population * CASE WHEN d.median_home_value IS NOT NULL THEN 1 ELSE 0 END) > 0 THEN
              SUM(d.median_home_value * d.total_population)
              / NULLIF(SUM(CASE WHEN d.median_home_value IS NOT NULL THEN d.total_population ELSE 0 END), 0)
            END AS median_home_value,
            COALESCE(SUM(d.labor_force_total), 0) AS labor_force,
            COALESCE(SUM(d.unemployed_total), 0) AS unemployed,
            COALESCE(SUM(d.housing_units_total), 0) AS housing,
            COALESCE(SUM(ct.land_area_sqm), 0) AS land,
            COUNT(*) AS tracts
          FROM census_tracts ct
          LEFT JOIN census_demographics d ON d.geoid = ct.geoid
          WHERE ct.state_fips = ? AND ct.county_fips = ?
        ";
        $row = $db->fetch($sql, [$stateFips, $countyFips]);
        if (!$row || empty($row['wkt'])) continue;

        $db->query(
            "REPLACE INTO census_counties (geoid, state_fips, county_fips, name, geometry,
                total_population, median_household_income, median_home_value,
                labor_force_total, unemployed_total, housing_units_total,
                land_area_sqm, tract_count, updated_at)
             VALUES (?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $geoid, $stateFips, $countyFips,
                'County ' . $countyFips,
                $row['wkt'],
                (int)$row['pop'],
                $row['median_income'] !== null ? (int)round($row['median_income']) : null,
                $row['median_home_value'] !== null ? (int)round($row['median_home_value']) : null,
                (int)$row['labor_force'], (int)$row['unemployed'], (int)$row['housing'],
                (float)$row['land'], (int)$row['tracts'],
                date('Y-m-d H:i:s'),
            ]
        );
        $upserted++;
        if (($i + 1) % 25 === 0) printf("\r  [%d/%d] %d%%", $i + 1, count($pairs), (int)(($i + 1) / count($pairs) * 100));
    }
    echo "\n  upserted $upserted\n";
}

function aggregate_states(): void {
    $db = Database::getInstance();
    echo "Aggregating counties → states…\n";
    $fipss = array_column($db->fetchAll('SELECT DISTINCT state_fips FROM census_counties ORDER BY state_fips'), 'state_fips');
    echo "  " . count($fipss) . " states\n";

    foreach ($fipss as $i => $stateFips) {
        $sql = "
          SELECT
            ST_AsText(ST_Union(c.geometry)) AS wkt,
            COALESCE(SUM(c.total_population), 0) AS pop,
            CASE WHEN SUM(c.total_population * CASE WHEN c.median_household_income IS NOT NULL THEN 1 ELSE 0 END) > 0 THEN
              SUM(c.median_household_income * c.total_population)
              / NULLIF(SUM(CASE WHEN c.median_household_income IS NOT NULL THEN c.total_population ELSE 0 END), 0)
            END AS median_income,
            CASE WHEN SUM(c.total_population * CASE WHEN c.median_home_value IS NOT NULL THEN 1 ELSE 0 END) > 0 THEN
              SUM(c.median_home_value * c.total_population)
              / NULLIF(SUM(CASE WHEN c.median_home_value IS NOT NULL THEN c.total_population ELSE 0 END), 0)
            END AS median_home_value,
            COALESCE(SUM(c.labor_force_total), 0) AS labor_force,
            COALESCE(SUM(c.unemployed_total), 0) AS unemployed,
            COALESCE(SUM(c.housing_units_total), 0) AS housing,
            COALESCE(SUM(c.land_area_sqm), 0) AS land,
            COALESCE(SUM(c.tract_count), 0) AS tracts
          FROM census_counties c
          WHERE c.state_fips = ?
        ";
        $row = $db->fetch($sql, [$stateFips]);
        if (!$row || empty($row['wkt'])) continue;

        $name = match ($stateFips) {
            '11' => 'District of Columbia',
            '24' => 'Maryland',
            '51' => 'Virginia',
            '54' => 'West Virginia',
            default => 'State ' . $stateFips,
        };

        $db->query(
            "REPLACE INTO census_states (state_fips, name, geometry,
                total_population, median_household_income, median_home_value,
                labor_force_total, unemployed_total, housing_units_total,
                land_area_sqm, tract_count, updated_at)
             VALUES (?, ?, ST_GeomFromText(?, 4326), ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $stateFips, $name, $row['wkt'],
                (int)$row['pop'],
                $row['median_income'] !== null ? (int)round($row['median_income']) : null,
                $row['median_home_value'] !== null ? (int)round($row['median_home_value']) : null,
                (int)$row['labor_force'], (int)$row['unemployed'], (int)$row['housing'],
                (float)$row['land'], (int)$row['tracts'],
                date('Y-m-d H:i:s'),
            ]
        );
        printf("  %s ✓\n", $name);
    }
}

switch ($cmd) {
    case 'counties': aggregate_counties(); break;
    case 'states': aggregate_states(); break;
    case 'all':
    default:
        aggregate_counties();
        aggregate_states();
        break;
}
echo "Done.\n";
