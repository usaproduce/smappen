<?php
namespace App\Services;

use App\Core\Database;

/**
 * Time-series demographics — fetches per-vintage ACS profiles and surfaces
 * a tract-level history so the Demographics panel can render a "Trends"
 * sub-tab (population change, income growth, etc.).
 *
 * Vintages currently ingested: 2019-2023 (ACS 5-year). The earliest year
 * is 2019 because that's when ACS migrated to the unified table format —
 * pulling earlier years would require per-year variable mapping.
 */
class DemographicsHistoryService
{
    private const VINTAGES = [2019, 2020, 2021, 2022, 2023];

    /**
     * Returns a per-vintage time series for a single tract:
     *   [ ['year' => 2019, 'profile' => [...] ], ... ]
     */
    public function forTract(string $geoid): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT vintage_year, profile_json
               FROM demographics_history
              WHERE geoid = ?
              ORDER BY vintage_year ASC',
            [$geoid]
        );
        $out = [];
        foreach ($rows as $r) {
            $p = json_decode($r['profile_json'] ?? '{}', true) ?: [];
            $out[] = ['year' => (int)$r['vintage_year'], 'profile' => $p];
        }
        return $out;
    }

    /**
     * Average a metric across multiple tracts at each vintage. Used to
     * render trend lines for whole drive-time isochrones.
     */
    public function avgForArea(array $geoids, string $metric): array
    {
        if (!$geoids) return [];
        $place = implode(',', array_fill(0, count($geoids), '?'));
        $rows = Database::getInstance()->fetchAll(
            "SELECT vintage_year, profile_json
               FROM demographics_history
              WHERE geoid IN ($place)
              ORDER BY vintage_year ASC",
            $geoids
        );
        $byYear = [];
        foreach ($rows as $r) {
            $p = json_decode($r['profile_json'] ?? '{}', true) ?: [];
            if (!isset($p[$metric]) || !is_numeric($p[$metric])) continue;
            $byYear[(int)$r['vintage_year']][] = (float)$p[$metric];
        }
        $out = [];
        foreach ($byYear as $year => $vals) {
            $out[] = ['year' => $year, 'value' => array_sum($vals) / count($vals)];
        }
        usort($out, fn($a, $b) => $a['year'] <=> $b['year']);
        return $out;
    }

    /**
     * One-shot ingest for one tract. Pulls every vintage we have a mapping
     * for and upserts the row. Idempotent.
     */
    /**
     * Operator-run ingest. Hits the ACS API directly (one HTTP call per
     * year × state) and writes per-tract rows. Use the CLI script in
     * scripts/ingest-demographics-history.php — don't call this from a
     * request handler (each year is ~5min and the API is slow).
     */
    public function ingestStateYear(string $stateFips, int $year, string $apiKey): array
    {
        $vars = implode(',', array_values(CensusService::VARIABLES));
        $url = "https://api.census.gov/data/{$year}/acs/acs5"
             . "?get=NAME,{$vars}&for=tract:*&in=state:{$stateFips}&key={$apiKey}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode((string) $resp, true);
        if (!is_array($data) || count($data) < 2) return ['state' => $stateFips, 'year' => $year, 'done' => 0];

        $headers = array_shift($data);
        $done = 0;
        foreach ($data as $row) {
            $rec = array_combine($headers, $row);
            $geoid = ($rec['state'] ?? '') . ($rec['county'] ?? '') . ($rec['tract'] ?? '');
            unset($rec['state'], $rec['county'], $rec['tract']);
            try {
                Database::getInstance()->query(
                    'REPLACE INTO demographics_history (geoid, vintage_year, profile_json, cached_at)
                     VALUES (?, ?, ?, NOW())',
                    [$geoid, $year, json_encode($rec)]
                );
                $done++;
            } catch (\Throwable $e) { /* skip the row, continue with the rest */ }
        }
        return ['state' => $stateFips, 'year' => $year, 'done' => $done];
    }
}
