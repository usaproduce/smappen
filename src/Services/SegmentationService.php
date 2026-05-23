<?php
namespace App\Services;

use App\Core\Database;

/**
 * Rule-based customer segmentation derived from US Census ACS variables we
 * already have at the tract level — no external taxonomy fees, no LLM calls.
 *
 * 10 segments calibrated against rough national distribution. The cutoffs are
 * percentiles computed lazily on first call and cached in-memory for the rest
 * of the request; the persisted assignments live in tract_segments.
 *
 * recomputeAll() is intended to be invoked from `php bin/segment-tracts.php`
 * after a Census refresh.
 */
class SegmentationService
{
    public const SEGMENTS = [
        ['id' => 'affluent-suburbs',     'name' => 'Affluent suburbs',     'color' => '#9333ea'],
        ['id' => 'urban-professionals',  'name' => 'Urban professionals',  'color' => '#0ea5e9'],
        ['id' => 'family-suburbs',       'name' => 'Family suburbs',       'color' => '#22c55e'],
        ['id' => 'working-class-urban',  'name' => 'Working-class urban',  'color' => '#f59e0b'],
        ['id' => 'rural-stable',         'name' => 'Rural stable',         'color' => '#84cc16'],
        ['id' => 'retirement',           'name' => 'Retirement communities','color' => '#a855f7'],
        ['id' => 'college-towns',        'name' => 'College towns',        'color' => '#06b6d4'],
        ['id' => 'low-income-urban',     'name' => 'Low-income urban',     'color' => '#ef4444'],
        ['id' => 'moderate-suburbs',     'name' => 'Moderate suburbs',     'color' => '#14b8a6'],
        ['id' => 'emerging-growth',      'name' => 'Emerging growth',      'color' => '#ec4899'],
    ];

    private static ?array $cuts = null;

    public function recomputeAll(?callable $onProgress = null): int
    {
        $db = Database::getInstance();
        $tracts = $db->fetchAll(
            "SELECT ct.geoid, ct.land_area_sqm,
                    d.total_population, d.median_household_income, d.median_home_value,
                    d.housing_units_total,
                    d.age_under_18, d.age_18_to_34, d.age_35_to_54,
                    d.age_55_to_64, d.age_65_plus
             FROM census_tracts ct
             LEFT JOIN census_demographics d ON d.geoid = ct.geoid"
        );

        self::$cuts = self::computeCutoffs($tracts);

        $now = date('Y-m-d H:i:s');
        $count = 0;
        $batch = [];
        foreach ($tracts as $t) {
            $r = self::classifyRow($t, self::$cuts);
            $batch[] = [$t['geoid'], $r['id'], $r['name'], $r['confidence'], json_encode($r['features']), $now];
            if (count($batch) >= 500) {
                self::flushBatch($batch);
                $count += count($batch);
                $batch = [];
                if ($onProgress) $onProgress($count, count($tracts));
            }
        }
        if (!empty($batch)) {
            self::flushBatch($batch);
            $count += count($batch);
        }
        return $count;
    }

    /** Classify a single demographics row (used live for hypothetical inputs). */
    public function classify(array $tract): array
    {
        if (self::$cuts === null) {
            self::$cuts = self::loadCutoffsFromDb();
        }
        return self::classifyRow($tract, self::$cuts);
    }

    private static function flushBatch(array $batch): void
    {
        $sql = "REPLACE INTO tract_segments (geoid, segment_id, segment_name, confidence, features_json, computed_at)
                VALUES " . implode(',', array_fill(0, count($batch), '(?,?,?,?,?,?)'));
        $flat = [];
        foreach ($batch as $row) {
            foreach ($row as $v) $flat[] = $v;
        }
        Database::getInstance()->query($sql, $flat);
    }

    private static function classifyRow(array $t, array $c): array
    {
        $pop = (float)($t['total_population'] ?? 0);
        $income = $t['median_household_income'] !== null ? (float)$t['median_household_income'] : null;
        $home = $t['median_home_value'] !== null ? (float)$t['median_home_value'] : null;
        $landM2 = (float)($t['land_area_sqm'] ?? 1);
        $density = $landM2 > 0 ? $pop / ($landM2 / 1_000_000) : 0; // per sq km

        $u18 = (float)($t['age_under_18'] ?? 0);
        $a1834 = (float)($t['age_18_to_34'] ?? 0);
        $a3554 = (float)($t['age_35_to_54'] ?? 0);
        $a5564 = (float)($t['age_55_to_64'] ?? 0);
        $a65 = (float)($t['age_65_plus'] ?? 0);
        $ageTot = $u18 + $a1834 + $a3554 + $a5564 + $a65;
        $f = fn($n) => $ageTot > 0 ? $n / $ageTot : 0;
        $pctU18 = $f($u18);
        $pct1834 = $f($a1834);
        $pct3554 = $f($a3554);
        $pct65 = $f($a65 + $a5564);

        $highInc = $income !== null && $income >= ($c['income_p75'] ?? 90000);
        $midHighInc = $income !== null && $income >= ($c['income_p50'] ?? 65000);
        $lowInc = $income !== null && $income <= ($c['income_p25'] ?? 40000);
        $highHome = $home !== null && $home >= ($c['home_p75'] ?? 400000);
        $highDensity = $density >= ($c['density_p75'] ?? 3000);
        $lowDensity = $density <= ($c['density_p25'] ?? 200);

        // Rule cascade — first match wins. Order chosen so "specific" segments
        // are checked before generic catch-alls.
        $features = compact('density', 'pctU18', 'pct1834', 'pct3554', 'pct65');
        $features['income'] = $income;
        $features['home_value'] = $home;

        if ($pct65 >= 0.40) {
            return ['id' => 'retirement', 'name' => 'Retirement communities', 'confidence' => 0.85, 'features' => $features];
        }
        if ($pct1834 >= 0.40 && !$highInc) {
            return ['id' => 'college-towns', 'name' => 'College towns', 'confidence' => 0.80, 'features' => $features];
        }
        if ($highInc && $highHome && !$highDensity) {
            return ['id' => 'affluent-suburbs', 'name' => 'Affluent suburbs', 'confidence' => 0.90, 'features' => $features];
        }
        if ($highDensity && $midHighInc) {
            return ['id' => 'urban-professionals', 'name' => 'Urban professionals', 'confidence' => 0.85, 'features' => $features];
        }
        if ($highDensity && $lowInc) {
            return ['id' => 'low-income-urban', 'name' => 'Low-income urban', 'confidence' => 0.80, 'features' => $features];
        }
        if ($pctU18 >= 0.25 && !$lowDensity && !$lowInc) {
            return ['id' => 'family-suburbs', 'name' => 'Family suburbs', 'confidence' => 0.75, 'features' => $features];
        }
        if ($lowDensity && !$lowInc) {
            return ['id' => 'rural-stable', 'name' => 'Rural stable', 'confidence' => 0.70, 'features' => $features];
        }
        if ($highDensity) {
            return ['id' => 'working-class-urban', 'name' => 'Working-class urban', 'confidence' => 0.65, 'features' => $features];
        }
        // emerging growth heuristic: high housing-to-population ratio
        $housing = (float)($t['housing_units_total'] ?? 0);
        if ($pop > 0 && ($housing / $pop) > 0.55) {
            return ['id' => 'emerging-growth', 'name' => 'Emerging growth', 'confidence' => 0.55, 'features' => $features];
        }
        return ['id' => 'moderate-suburbs', 'name' => 'Moderate suburbs', 'confidence' => 0.50, 'features' => $features];
    }

    private static function computeCutoffs(array $tracts): array
    {
        $incomes = [];
        $homes = [];
        $dens = [];
        foreach ($tracts as $t) {
            if ($t['median_household_income'] !== null) $incomes[] = (float)$t['median_household_income'];
            if ($t['median_home_value'] !== null) $homes[] = (float)$t['median_home_value'];
            $pop = (float)($t['total_population'] ?? 0);
            $land = (float)($t['land_area_sqm'] ?? 0);
            if ($land > 0) $dens[] = $pop / ($land / 1_000_000);
        }
        return [
            'income_p25' => self::pct($incomes, 25),
            'income_p50' => self::pct($incomes, 50),
            'income_p75' => self::pct($incomes, 75),
            'home_p75' => self::pct($homes, 75),
            'density_p25' => self::pct($dens, 25),
            'density_p75' => self::pct($dens, 75),
        ];
    }

    private static function loadCutoffsFromDb(): array
    {
        // Fall back to sane US-wide defaults if not yet computed.
        $row = Database::getInstance()->fetch(
            "SELECT
              AVG(d.median_household_income) AS inc_avg,
              AVG(d.median_home_value) AS home_avg
             FROM census_demographics d"
        );
        return [
            'income_p25' => 40000,
            'income_p50' => $row && $row['inc_avg'] ? (float)$row['inc_avg'] : 65000,
            'income_p75' => 90000,
            'home_p75' => $row && $row['home_avg'] ? max(400000, (float)$row['home_avg']) : 400000,
            'density_p25' => 200,
            'density_p75' => 3000,
        ];
    }

    private static function pct(array $arr, int $p): float
    {
        if (empty($arr)) return 0.0;
        sort($arr);
        $idx = (int) floor(($p / 100) * (count($arr) - 1));
        return (float) $arr[$idx];
    }
}
