<?php
namespace App\Services;

use App\Core\Config;
use App\Core\Database;

class CensusService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.census.gov/data';
    private string $acsYear = '2023';
    private string $acsDataset = 'acs/acs5';

    public const VARIABLES = [
        'total_population' => 'B01003_001E',
        'male_total' => 'B01001_002E',
        'female_total' => 'B01001_026E',
        'median_household_income' => 'B19013_001E',
        'median_home_value' => 'B25077_001E',
        'labor_force_total' => 'B23025_002E',
        'unemployed_total' => 'B23025_005E',
        'housing_units_total' => 'B25001_001E',
        // Age (B09001 is population under 18 only — use B01001 brackets for the rest)
        'age_under_18' => 'B09001_001E',
        // 18-34: male 7-12 + female 31-36
        'age_18_to_34_m1' => 'B01001_007E',
        'age_18_to_34_m2' => 'B01001_008E',
        'age_18_to_34_m3' => 'B01001_009E',
        'age_18_to_34_m4' => 'B01001_010E',
        'age_18_to_34_m5' => 'B01001_011E',
        'age_18_to_34_m6' => 'B01001_012E',
        'age_18_to_34_f1' => 'B01001_031E',
        'age_18_to_34_f2' => 'B01001_032E',
        'age_18_to_34_f3' => 'B01001_033E',
        'age_18_to_34_f4' => 'B01001_034E',
        'age_18_to_34_f5' => 'B01001_035E',
        'age_18_to_34_f6' => 'B01001_036E',
        // 35-54: male 13-16 + female 37-40
        'age_35_to_54_m1' => 'B01001_013E',
        'age_35_to_54_m2' => 'B01001_014E',
        'age_35_to_54_m3' => 'B01001_015E',
        'age_35_to_54_m4' => 'B01001_016E',
        'age_35_to_54_f1' => 'B01001_037E',
        'age_35_to_54_f2' => 'B01001_038E',
        'age_35_to_54_f3' => 'B01001_039E',
        'age_35_to_54_f4' => 'B01001_040E',
        // 55-64: male 17-19 + female 41-43
        'age_55_to_64_m1' => 'B01001_017E',
        'age_55_to_64_m2' => 'B01001_018E',
        'age_55_to_64_m3' => 'B01001_019E',
        'age_55_to_64_f1' => 'B01001_041E',
        'age_55_to_64_f2' => 'B01001_042E',
        'age_55_to_64_f3' => 'B01001_043E',
        // 65+: male 20-25 + female 44-49
        'age_65_plus_m1' => 'B01001_020E',
        'age_65_plus_m2' => 'B01001_021E',
        'age_65_plus_m3' => 'B01001_022E',
        'age_65_plus_m4' => 'B01001_023E',
        'age_65_plus_m5' => 'B01001_024E',
        'age_65_plus_m6' => 'B01001_025E',
        'age_65_plus_f1' => 'B01001_044E',
        'age_65_plus_f2' => 'B01001_045E',
        'age_65_plus_f3' => 'B01001_046E',
        'age_65_plus_f4' => 'B01001_047E',
        'age_65_plus_f5' => 'B01001_048E',
        'age_65_plus_f6' => 'B01001_049E',
        // Income brackets
        'income_under_25k' => 'B19001_002E',
        'income_25k_to_50k' => 'B19001_006E',
        'income_50k_to_75k' => 'B19001_011E',
        'income_75k_to_100k' => 'B19001_013E',
        'income_100k_plus' => 'B19001_014E',
    ];

    public function __construct()
    {
        $this->apiKey = Config::get('CENSUS_API_KEY', '');
    }

    public function fetchDemographicsForState(string $stateFips): array
    {
        if (!$this->apiKey) {
            throw new \RuntimeException('CENSUS_API_KEY not configured');
        }
        // Census API: max 50 variables per call (including NAME), so chunk and merge.
        $allVars = array_values(self::VARIABLES);
        $chunks = array_chunk($allVars, 49);

        $merged = [];
        foreach ($chunks as $chunk) {
            $vars = implode(',', $chunk);
            $url = "{$this->baseUrl}/{$this->acsYear}/{$this->acsDataset}"
                 . "?get=NAME,{$vars}&for=tract:*&in=state:{$stateFips}&key={$this->apiKey}";
            $resp = $this->httpGet($url);
            $data = json_decode($resp, true);
            if (!is_array($data) || count($data) < 2) continue;
            $headers = array_shift($data);
            foreach ($data as $row) {
                $rec = array_combine($headers, $row);
                $geoid = $rec['state'] . $rec['county'] . $rec['tract'];
                if (!isset($merged[$geoid])) {
                    $merged[$geoid] = ['geoid' => $geoid];
                }
                // Drop the state/county/tract location keys before merging vars in.
                unset($rec['state'], $rec['county'], $rec['tract']);
                $merged[$geoid] = array_merge($merged[$geoid], $rec);
            }
            usleep(200_000); // throttle a bit between calls
        }
        return array_values($merged);
    }

    public function getDemographicsForArea(string $areaId): ?array
    {
        $db = Database::getInstance();
        $area = $db->fetch('SELECT *, ST_AsGeoJSON(geometry) AS geom_json FROM areas WHERE id = ?', [$areaId]);
        if (!$area) return null;

        // Check cache (< 30 days)
        if (!empty($area['demographics_cache']) && !empty($area['demographics_cached_at'])) {
            $cachedTs = strtotime($area['demographics_cached_at']);
            if (time() - $cachedTs < 86400 * 30) {
                return json_decode($area['demographics_cache'], true);
            }
        }

        // Find overlapping tracts + overlap percentage.
        // ST_Intersection of two polygons that share only an edge returns a
        // GEOMETRYCOLLECTION, which ST_Area rejects with MySQL error 3516
        // (Unexpected geometry type). Filter via ST_GeometryType so only
        // real-area overlaps contribute — same pattern used in Reach and
        // Cannibalization.
        $sql = 'SELECT ct.geoid,
                       CASE WHEN ST_GeometryType(ST_Intersection(ct.geometry, a.geometry))
                                IN ("Polygon", "MultiPolygon")
                            THEN ST_Area(ST_Intersection(ct.geometry, a.geometry))
                                 / NULLIF(ST_Area(ct.geometry), 0)
                            ELSE 0
                       END AS overlap_pct
                FROM census_tracts ct
                JOIN areas a ON a.id = :aid
                WHERE ST_Intersects(ct.geometry, a.geometry)
                HAVING overlap_pct > 0';
        $tracts = $db->fetchAll($sql, [':aid' => $areaId]);

        if (empty($tracts)) {
            // No census data loaded — return empty structure
            return $this->emptyStructure();
        }

        $geoids = array_column($tracts, 'geoid');
        $placeholders = implode(',', array_fill(0, count($geoids), '?'));
        $demos = $db->fetchAll("SELECT * FROM census_demographics WHERE geoid IN ($placeholders)", $geoids);
        $byGeoid = [];
        foreach ($demos as $d) $byGeoid[$d['geoid']] = $d;

        $agg = [
            'total_population' => 0, 'male_total' => 0, 'female_total' => 0,
            'labor_force_total' => 0, 'unemployed_total' => 0, 'housing_units_total' => 0,
            'age_under_18' => 0, 'age_18_to_34' => 0, 'age_35_to_54' => 0, 'age_55_to_64' => 0, 'age_65_plus' => 0,
            'income_under_25k' => 0, 'income_25k_to_50k' => 0, 'income_50k_to_75k' => 0, 'income_75k_to_100k' => 0, 'income_100k_plus' => 0,
        ];
        $incomeWeightedSum = 0.0; $incomeWeight = 0.0;
        $homeValWeightedSum = 0.0; $homeValWeight = 0.0;

        foreach ($tracts as $t) {
            $pct = (float)($t['overlap_pct'] ?? 0);
            if ($pct <= 0) continue;
            $d = $byGeoid[$t['geoid']] ?? null;
            if (!$d) continue;
            foreach ($agg as $k => &$v) {
                $v += (int)($d[$k] ?? 0) * $pct;
            }
            unset($v);
            $pop = (int)($d['total_population'] ?? 0);
            $weight = $pop * $pct;
            if (!empty($d['median_household_income'])) {
                $incomeWeightedSum += $d['median_household_income'] * $weight;
                $incomeWeight += $weight;
            }
            if (!empty($d['median_home_value'])) {
                $homeValWeightedSum += $d['median_home_value'] * $weight;
                $homeValWeight += $weight;
            }
        }

        $medianIncome = $incomeWeight > 0 ? (int)round($incomeWeightedSum / $incomeWeight) : null;
        $medianHomeValue = $homeValWeight > 0 ? (int)round($homeValWeightedSum / $homeValWeight) : null;
        $areaSqKm = GeoUtils::calculateArea(json_decode($area['geom_json'], true));
        $density = $areaSqKm > 0 ? (int)round($agg['total_population'] / $areaSqKm) : 0;
        $unemploymentRate = $agg['labor_force_total'] > 0
            ? round(($agg['unemployed_total'] / $agg['labor_force_total']) * 100, 2)
            : 0;

        $result = [
            'population' => [
                'total' => (int)$agg['total_population'],
                'male' => (int)$agg['male_total'],
                'female' => (int)$agg['female_total'],
                'density_per_sq_km' => $density,
            ],
            'age' => [
                'under_18' => (int)$agg['age_under_18'],
                '18_to_34' => (int)$agg['age_18_to_34'],
                '35_to_54' => (int)$agg['age_35_to_54'],
                '55_to_64' => (int)$agg['age_55_to_64'],
                '65_plus' => (int)$agg['age_65_plus'],
            ],
            'income' => [
                'median_household' => $medianIncome,
                'brackets' => [
                    'under_25k' => (int)$agg['income_under_25k'],
                    '25k_to_50k' => (int)$agg['income_25k_to_50k'],
                    '50k_to_75k' => (int)$agg['income_50k_to_75k'],
                    '75k_to_100k' => (int)$agg['income_75k_to_100k'],
                    '100k_plus' => (int)$agg['income_100k_plus'],
                ],
            ],
            'employment' => [
                'labor_force' => (int)$agg['labor_force_total'],
                'unemployed' => (int)$agg['unemployed_total'],
                'unemployment_rate' => $unemploymentRate,
            ],
            'housing' => [
                'total_units' => (int)$agg['housing_units_total'],
                'median_value' => $medianHomeValue,
            ],
            'meta' => [
                'area_sq_km' => round($areaSqKm, 3),
                'tracts_intersected' => count($tracts),
                'data_year' => (int)$this->acsYear,
            ],
        ];

        $db->update('areas', [
            'demographics_cache' => json_encode($result),
            'demographics_cached_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', [':id' => $areaId]);

        return $result;
    }

    private function emptyStructure(): array
    {
        return [
            'population' => ['total' => 0, 'male' => 0, 'female' => 0, 'density_per_sq_km' => 0],
            'age' => ['under_18' => 0, '18_to_34' => 0, '35_to_54' => 0, '55_to_64' => 0, '65_plus' => 0],
            'income' => ['median_household' => null, 'brackets' => ['under_25k' => 0, '25k_to_50k' => 0, '50k_to_75k' => 0, '75k_to_100k' => 0, '100k_plus' => 0]],
            'employment' => ['labor_force' => 0, 'unemployed' => 0, 'unemployment_rate' => 0],
            'housing' => ['total_units' => 0, 'median_value' => null],
            'meta' => ['data_year' => (int)$this->acsYear, 'tracts_intersected' => 0, 'note' => 'No census data loaded for this area.'],
        ];
    }

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $code >= 400) {
            throw new \RuntimeException('Census API error ' . $code);
        }
        return $resp;
    }
}
