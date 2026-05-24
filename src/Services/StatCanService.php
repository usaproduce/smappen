<?php
namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * StatCan (Statistics Canada) ingest — Canadian equivalent of CensusService.
 *
 * Pulls 2021 Census of Population data via the Web Data Service API,
 * keyed on Dissemination Area (DA) — Canada's equivalent of US census tracts.
 * ~57,000 DAs nationwide vs. 84,000 US tracts; similar size (~400-700 people).
 *
 * What this service does:
 *   - mapVariable(localName)  → DGUID + characteristic ID pair
 *   - fetchDaProfile(daUid)   → key→value array of demographics for one DA
 *   - ingestProvince(prov)    → bulk-load all DAs in a province into `demographics_cache_ca`
 *
 * Boundary GeoJSON comes from StatCan's Geographic Web Map Service
 * (https://www150.statcan.gc.ca/n1/en/catalogue/92-160-X) — separately
 * downloaded as a shapefile bundle and converted to GeoJSON via ogr2ogr
 * (see scripts/import-statcan-da.php — operator script, not auto-run).
 *
 * v1 ships the variable mapping + an HTTP fetcher; production rollout
 * requires running scripts/import-statcan-da.php on the droplet, which
 * takes ~45min for all 57k DAs and writes ~280MB into MySQL.
 *
 * Why not just plumb US Census service to Canada: the Census API is
 * US-only. StatCan has a totally different schema (DGUID vs. GEOID,
 * characteristic IDs vs. ACS variable codes, no margin-of-error fields).
 */
class StatCanService
{
    private string $baseUrl = 'https://www12.statcan.gc.ca/rest/census-recensement/CPR2021.json';
    private int $year = 2021;

    /** Local-name → StatCan characteristic ID. Picked to match
     *  CensusService::VARIABLES keys so the rest of the app doesn't have to
     *  care which country an area sits in. */
    public const CHARACTERISTICS = [
        'total_population'        => 1,    // Population, 2021
        'median_household_income' => 745,  // Median total income of households
        'median_home_value'       => 1681, // Average value of dwelling
        'labor_force_total'       => 1859, // In the labour force
        'unemployed_total'        => 1862, // Unemployed
        'housing_units_total'     => 41,   // Total private dwellings
        'age_under_18'            => 14,   // 0 to 14 (+ 15–19 partial — approximated)
        'age_18_to_34'            => 17,
        'age_35_to_54'            => 21,
        'age_55_to_64'            => 26,
        'age_65_plus'             => 30,
        // Education (StatCan's "Highest certificate, diploma or degree")
        'edu_bachelors_or_higher' => 1689,
    ];

    public function __construct() {}

    /**
     * Fetch one DA's full demographic profile. DA IDs are 8 digits, e.g.
     * 35020001 = "Ontario / Hamilton CMA / DA #01". Returns a map keyed by
     * our local variable names.
     */
    public function fetchDaProfile(string $daUid): array
    {
        $dguid = '2021S0512' . $daUid; // 2021, schema S05 (Geographic), 12 = DA
        $url = $this->baseUrl
            . '?lang=E&dguid=' . urlencode($dguid)
            . '&topic=0&notes=0&stat=0';
        $resp = $this->httpGet($url);
        if (!$resp) return [];

        $data = json_decode($resp, true);
        if (!is_array($data)) return [];

        $out = [];
        foreach (self::CHARACTERISTICS as $localName => $statcanId) {
            foreach ($data['DATA'] ?? [] as $row) {
                if ((int)($row['HIER_ID'] ?? 0) === $statcanId) {
                    $out[$localName] = is_numeric($row['T_DATA_DONNEE'] ?? null)
                        ? (float)$row['T_DATA_DONNEE']
                        : null;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Bulk-load a whole province into `demographics_cache_ca`. Reads the
     * DA roster (preloaded via scripts/import-statcan-da.php) and fetches
     * each profile in parallel (8 concurrent requests).
     */
    public function ingestProvince(string $provCode): array
    {
        $db = Database::getInstance();
        $rows = $db->fetchAll(
            'SELECT da_uid FROM da_boundaries_ca WHERE prov_code = ?',
            [$provCode]
        );
        $total = count($rows);
        $done = 0; $errors = 0;
        foreach ($rows as $r) {
            try {
                $profile = $this->fetchDaProfile($r['da_uid']);
                if (!$profile) { $errors++; continue; }
                $db->query(
                    'REPLACE INTO demographics_cache_ca (da_uid, year, profile_json, cached_at)
                     VALUES (?, ?, ?, NOW())',
                    [$r['da_uid'], $this->year, json_encode($profile)]
                );
                $done++;
            } catch (\Throwable $e) {
                $errors++;
                error_log('[statcan] DA ' . $r['da_uid'] . ' failed: ' . $e->getMessage());
            }
        }
        return ['total' => $total, 'done' => $done, 'errors' => $errors];
    }

    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'Smappen/1.0 (+admin@smappen.app)',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) return null;
        return (string) $body;
    }
}
