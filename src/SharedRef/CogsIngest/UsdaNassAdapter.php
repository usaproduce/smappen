<?php
declare(strict_types=1);

namespace App\SharedRef\CogsIngest;

use App\Core\Config;

/**
 * USDA NASS Quick Stats adapter — public, free producer-side commodity
 * prices for protein and dairy. National (no regional rollup at this
 * tier; NASS state breakouts are noisy and lag, so we serve them under
 * region='US' and let CogsBenchmarkRepository's national-fallback rule
 * handle restaurants in any region).
 *
 * Each commodity is one HTTP call → one IngestBatch. fetchBatchesForRegion
 * yields one batch per commodity; fetchBatch(region) returns the first.
 *
 * Defensive: never throws. Upstream failure → IngestBatch(ok=false).
 * CURLOPT_CONNECTTIMEOUT=3 per audit §5.4.
 */
class UsdaNassAdapter implements CogsIngestAdapter
{
    private const ENDPOINT = 'https://quickstats.nass.usda.gov/api/api_GET/';

    /** @var array<int, array<string, mixed>> */
    private array $queries;

    private string $apiKey;

    public function __construct(?array $queries = null, ?string $apiKey = null)
    {
        $this->queries = $queries ?? require dirname(__DIR__, 3) . '/config/cogs_usda_nass_queries.php';
        // NASS Quick Stats requires its OWN key (separate registration at
        // https://quickstats.nass.usda.gov/api). AMS keys are rejected with
        // 401. Look for USDA_NASS_API_KEY first; only fall back to
        // USDA_API_KEY if the operator explicitly opts in via
        // USDA_NASS_REUSE_AMS_KEY=1 (used for testing — production should
        // register the proper key).
        if ($apiKey !== null) {
            $this->apiKey = $apiKey;
        } else {
            $nass = (string) Config::get('USDA_NASS_API_KEY', '');
            if ($nass !== '') {
                $this->apiKey = $nass;
            } elseif ((string) Config::get('USDA_NASS_REUSE_AMS_KEY', '') === '1') {
                $this->apiKey = (string) Config::get('USDA_API_KEY', '');
            } else {
                $this->apiKey = '';
            }
        }
    }

    public function key(): string    { return 'usda_nass'; }
    public function source(): string { return 'usda'; }
    public function isEnabled(): bool { return $this->apiKey !== ''; }
    public function regions(): array { return ['US']; }

    public function fetchBatch(string $asOfDate, ?string $region): IngestBatch
    {
        $batches = $this->fetchBatchesForRegion($asOfDate, $region);
        if (!$batches) {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: 'US', asOf: $asOfDate, rows: [],
                ok: false, errorMessage: 'no NASS queries configured',
            );
        }
        return $batches[0];
    }

    /** @return IngestBatch[] one per commodity query */
    public function fetchBatchesForRegion(string $asOfDate, ?string $region): array
    {
        // NASS is national-only in this adapter; if caller asks for a
        // non-national region, skip rather than return wrong data.
        if ($region !== null && $region !== 'US') return [];

        $out = [];
        foreach ($this->queries as $q) {
            $out[] = $this->fetchOneCommodity($asOfDate, (array) $q);
        }
        return $out;
    }

    private function fetchOneCommodity(string $asOfDate, array $q): IngestBatch
    {
        $params = [
            'key'                  => $this->apiKey,
            'commodity_desc'       => (string) $q['commodity_desc'],
            'statisticcat_desc'    => 'PRICE RECEIVED',
            'unit_desc'            => (string) $q['unit_desc'],
            'agg_level_desc'       => 'NATIONAL',
            'year__GE'             => (string) ((int) date('Y', strtotime($asOfDate)) - 1),
            'format'               => 'JSON',
        ];
        if (!empty($q['class_desc'])) $params['class_desc'] = (string) $q['class_desc'];

        $url = self::ENDPOINT . '?' . http_build_query($params);

        [$status, $body, $latencyMs, $err] = $this->httpGet($url);

        $sourceRef = (string) ($q['source_ref'] ?? $q['commodity_desc']);

        if ($err !== null || $body === '') {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: 'US', asOf: $asOfDate, rows: [],
                endpoint: $this->redactKey($url), sourceRef: $sourceRef,
                httpStatus: $status, latencyMs: $latencyMs,
                ok: false, errorMessage: $err ?? 'empty response',
            );
        }

        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['data']) || !is_array($json['data'])) {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: 'US', asOf: $asOfDate, rows: [],
                endpoint: $this->redactKey($url), sourceRef: $sourceRef,
                httpStatus: $status, latencyMs: $latencyMs,
                ok: false, errorMessage: 'non-JSON or missing data[]',
            );
        }

        $latest = $this->pickLatestObservation($json['data']);
        if ($latest === null) {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: 'US', asOf: $asOfDate, rows: [],
                endpoint: $this->redactKey($url), sourceRef: $sourceRef,
                httpStatus: $status, latencyMs: $latencyMs,
                ok: true,
                notes: ['records_in_response' => count($json['data']), 'reason' => 'no usable observation'],
            );
        }

        $value = $this->parseFloat($latest['Value'] ?? $latest['value'] ?? null);
        if ($value === null || $value <= 0) {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: 'US', asOf: $asOfDate, rows: [],
                endpoint: $this->redactKey($url), sourceRef: $sourceRef,
                httpStatus: $status, latencyMs: $latencyMs,
                ok: true,
                notes: ['records_in_response' => count($json['data']), 'reason' => 'value missing or non-positive'],
            );
        }

        // $/unit_desc → $/wholesale_unit, then × markup.
        $perWholesaleUnitDollars = $value * (float) $q['cwt_to_unit'] * (float) $q['markup'];
        $cents = (int) round($perWholesaleUnitDollars * 100);
        if ($cents <= 0 || $cents > 100_000) {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: 'US', asOf: $asOfDate, rows: [],
                endpoint: $this->redactKey($url), sourceRef: $sourceRef,
                httpStatus: $status, latencyMs: $latencyMs,
                ok: true,
                notes: ['records_in_response' => count($json['data']), 'reason' => 'cents out of range', 'computed_cents' => $cents],
            );
        }

        $obsDate = $this->observationDate($latest) ?? $asOfDate;

        $row = new IngestRow(
            ingredientKey:    (string) $q['ingredient_key'],
            unit:             (string) $q['wholesale_unit'],
            marketPriceCents: $cents,
            region:           'US',
            asOf:             $obsDate,
            sourceRef:        $sourceRef,
        );

        return new IngestBatch(
            adapter: $this->key(), source: $this->source(),
            region: 'US', asOf: $obsDate, rows: [$row],
            endpoint: $this->redactKey($url), sourceRef: $sourceRef,
            httpStatus: $status, latencyMs: $latencyMs,
            ok: true,
            notes: [
                'records_in_response' => count($json['data']),
                'raw_value'           => $value,
                'unit_desc'           => (string) $q['unit_desc'],
                'markup'              => (float) $q['markup'],
            ],
        );
    }

    /**
     * NASS often returns many year/period rows. Pick the most recent
     * observation by year+reference_period_desc, treating "MARKETING
     * YEAR" / "YEAR" as Dec-31, quarterly as quarter-end, monthly as
     * month-end.
     */
    private function pickLatestObservation(array $data): ?array
    {
        $best = null;
        $bestDate = null;
        foreach ($data as $row) {
            if (!is_array($row)) continue;
            $d = $this->observationDate($row);
            if ($d === null) continue;
            if ($bestDate === null || strcmp($d, $bestDate) > 0) {
                $best     = $row;
                $bestDate = $d;
            }
        }
        return $best;
    }

    private function observationDate(array $row): ?string
    {
        $year = (int) ($row['year'] ?? 0);
        if ($year < 1900) return null;
        $period = strtoupper((string) ($row['reference_period_desc'] ?? ''));
        if ($period === '' || $period === 'YEAR' || $period === 'MARKETING YEAR') {
            return sprintf('%04d-12-31', $year);
        }
        // Months
        $months = ['JAN'=>1,'FEB'=>2,'MAR'=>3,'APR'=>4,'MAY'=>5,'JUN'=>6,
                   'JUL'=>7,'AUG'=>8,'SEP'=>9,'OCT'=>10,'NOV'=>11,'DEC'=>12];
        foreach ($months as $abbr => $m) {
            if (str_starts_with($period, $abbr)) {
                $day = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $m)));
                return sprintf('%04d-%02d-%02d', $year, $m, $day);
            }
        }
        // Quarters
        if (preg_match('/Q\s*([1-4])/', $period, $m)) {
            $endMonth = [1=>3, 2=>6, 3=>9, 4=>12][(int) $m[1]];
            $day = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $endMonth)));
            return sprintf('%04d-%02d-%02d', $year, $endMonth, $day);
        }
        return sprintf('%04d-12-31', $year);
    }

    private function parseFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (float) $v;
        if (is_string($v)) {
            $clean = preg_replace('/[^0-9.\-]/', '', $v);
            if ($clean === '' || $clean === '.' || $clean === '-') return null;
            return (float) $clean;
        }
        return null;
    }

    private function redactKey(string $url): string
    {
        return preg_replace('/(key=)[^&]+/', '$1<redacted>', $url) ?? $url;
    }

    /** @return array{0:int,1:string,2:int,3:?string} */
    protected function httpGet(string $url): array
    {
        $start = microtime(true);
        $ch    = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $resp      = curl_exec($ch);
        $status    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err       = curl_error($ch);
        curl_close($ch);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);
        if ($resp === false) return [$status ?: 0, '', $latencyMs, $err ?: 'curl failed'];
        if ($status >= 400)  return [$status, (string) $resp, $latencyMs, "HTTP $status"];
        return [$status, (string) $resp, $latencyMs, null];
    }
}
