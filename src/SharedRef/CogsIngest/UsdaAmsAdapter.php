<?php
declare(strict_types=1);

namespace App\SharedRef\CogsIngest;

use App\Core\Config;

/**
 * USDA AMS MyMarketNews adapter — public, free wholesale terminal-market
 * prices. Each configured slug is one report (e.g. Boston Terminal Daily
 * Fruits & Vegetables Wholesale). Auth is HTTP Basic with the API key as
 * username and empty password.
 *
 * One adapter instance handles many slugs. fetchBatch(region) picks the
 * configured slugs for that region (or every slug if region is null) and
 * issues one HTTP call per slug. Each call becomes its own
 * cogs_ingest_batches row via the service loop — we return a batch
 * struct per call.
 *
 * NOTE: this class implements CogsIngestAdapter but, unlike a "one call
 * per fetchBatch" adapter, it yields one batch *per slug*. The service
 * driver handles that via fetchBatchesForRegion() instead of fetchBatch().
 * fetchBatch() is kept satisfying the interface (returns the first slug's
 * batch) so type-checking doesn't fight us.
 *
 * Defensive: never throws. Upstream failure → returns IngestBatch with
 * ok=false. CURLOPT_CONNECTTIMEOUT=3 per audit §5.4.
 */
class UsdaAmsAdapter implements CogsIngestAdapter
{
    /** @var array<int, array<string, mixed>> */
    private array $reports;

    private string $apiKey;

    private const ENDPOINT_FMT = 'https://marsapi.ams.usda.gov/services/v1.2/reports/%s';

    public function __construct(?array $reports = null, ?string $apiKey = null)
    {
        $this->reports = $reports ?? require dirname(__DIR__, 3) . '/config/cogs_usda_ams_reports.php';
        $this->apiKey  = $apiKey  ?? (string) Config::get('USDA_API_KEY', '');
        $this->resolveShares();
    }

    public function key(): string    { return 'usda_ams'; }
    public function source(): string { return 'usda'; }
    public function isEnabled(): bool { return $this->apiKey !== ''; }

    public function regions(): array
    {
        $r = [];
        foreach ($this->reports as $rep) $r[(string) $rep['region']] = true;
        return array_keys($r);
    }

    /**
     * Interface-satisfying single-batch fetch. The driver normally calls
     * fetchBatchesForRegion() instead; this method picks the first slug
     * matching $region (or any slug if $region is null) and returns it.
     */
    public function fetchBatch(string $asOfDate, ?string $region): IngestBatch
    {
        $batches = $this->fetchBatchesForRegion($asOfDate, $region);
        if (!$batches) {
            return new IngestBatch(
                adapter: $this->key(),
                source:  $this->source(),
                region:  $region,
                asOf:    $asOfDate,
                rows:    [],
                ok:      false,
                errorMessage: 'no USDA AMS slugs configured for region ' . ($region ?? 'null'),
            );
        }
        return $batches[0];
    }

    /**
     * @return IngestBatch[] one batch per AMS slug touching $region.
     */
    public function fetchBatchesForRegion(string $asOfDate, ?string $region): array
    {
        $slugs = array_values(array_filter(
            $this->reports,
            fn($r) => $region === null || $r['region'] === $region
        ));
        $out = [];
        foreach ($slugs as $r) {
            $out[] = $this->fetchOneSlug($asOfDate, (array) $r);
        }
        return $out;
    }

    private function fetchOneSlug(string $asOfDate, array $report): IngestBatch
    {
        $slug   = (string) $report['slug'];
        $region = (string) $report['region'];
        $label  = (string) ($report['label'] ?? "USDA AMS slug $slug");
        $url    = sprintf(self::ENDPOINT_FMT, $slug) . '?q=published_date=' . rawurlencode($asOfDate) . '..&pageSize=2000';

        [$status, $body, $latencyMs, $err] = $this->httpGet($url);

        if ($err !== null || $body === '') {
            return new IngestBatch(
                adapter: $this->key(),
                source:  $this->source(),
                region:  $region,
                asOf:    $asOfDate,
                rows:    [],
                endpoint: $url,
                sourceRef: $label,
                httpStatus: $status,
                latencyMs: $latencyMs,
                ok: false,
                errorMessage: $err ?? 'empty response',
            );
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: $region, asOf: $asOfDate, rows: [],
                endpoint: $url, sourceRef: $label,
                httpStatus: $status, latencyMs: $latencyMs,
                ok: false, errorMessage: 'non-JSON response',
            );
        }

        // MARS API typically returns ['results' => [ {...row}, ... ]]
        $records = $json['results'] ?? $json['Report'] ?? $json['report'] ?? [];
        if (!is_array($records)) $records = [];

        $rows    = [];
        $skipped = 0;
        $latestObserved = $asOfDate;
        $ingredientMap = (array) ($report['ingredients'] ?? []);

        foreach ($records as $rec) {
            $row = $this->normalizeRecord($rec, $ingredientMap, $region, $label);
            if ($row === null) { $skipped++; continue; }
            $rows[] = $row;
            if ($row->asOf > $latestObserved) $latestObserved = $row->asOf;
        }

        return new IngestBatch(
            adapter:  $this->key(),
            source:   $this->source(),
            region:   $region,
            asOf:     $latestObserved,
            rows:     $rows,
            endpoint: $url,
            sourceRef: $label,
            httpStatus: $status,
            latencyMs: $latencyMs,
            ok: true,
            notes: ['records_in_response' => count($records), 'records_skipped' => $skipped, 'slug' => $slug],
        );
    }

    /**
     * Map one AMS record → IngestRow, or null if it can't be normalized.
     *
     * AMS record shape varies by report but commonly includes:
     *   commodity, variety, package, low_price, high_price,
     *   mostly_low, mostly_high, report_begin_date, origin, ...
     */
    private function normalizeRecord(mixed $rec, array $ingredientMap, string $region, string $label): ?IngestRow
    {
        if (!is_array($rec)) return null;
        $commodity = strtoupper((string) ($rec['commodity'] ?? $rec['commodity_desc'] ?? ''));
        $variety   = strtoupper((string) ($rec['variety']   ?? $rec['variety_desc']   ?? ''));
        if ($commodity === '') return null;

        $matched = null;
        foreach ($ingredientMap as $candidate) {
            $c = strtoupper((string) ($candidate['commodity'] ?? ''));
            $v = strtoupper((string) ($candidate['variety']   ?? ''));
            if ($c !== '' && !str_contains($commodity, $c)) continue;
            if ($v !== '' && !str_contains($variety, $v))   continue;
            $matched = $candidate;
            break;
        }
        if ($matched === null) return null;

        // Prefer mostly_high/low midpoint (sheds outliers); fall back to high/low.
        $low  = $this->parseFloat($rec['mostly_low']  ?? $rec['low_price']  ?? null);
        $high = $this->parseFloat($rec['mostly_high'] ?? $rec['high_price'] ?? null);
        if ($low === null && $high === null) return null;
        if ($low === null)  $low  = $high;
        if ($high === null) $high = $low;
        $midDollars = ($low + $high) / 2.0;
        if ($midDollars <= 0) return null;

        $package = (string) ($rec['package'] ?? $rec['pkg'] ?? '');
        $norm    = $this->normalizePackage($package);
        if ($norm === null) return null;

        $perUnitDollars = $midDollars / $norm['quantityInUnit'];
        $cents = (int) round($perUnitDollars * 100);
        if ($cents <= 0 || $cents > 100_000) return null; // sanity cap: $1000/unit

        $asOf = $this->parseDate($rec['report_begin_date'] ?? $rec['report_end_date'] ?? $rec['published_date'] ?? null)
              ?? date('Y-m-d');

        return new IngestRow(
            ingredientKey:   (string) $matched['key'],
            unit:            $norm['unit'],
            marketPriceCents: $cents,
            region:          $region,
            asOf:            $asOf,
            sourceRef:       sprintf('%s | %s %s | %s', $label, $commodity, $variety, $package),
        );
    }

    /**
     * Parse the "package" free-text into (quantity, unit) so a $14/25lb-carton
     * price normalizes to $0.56/lb. Returns null when ambiguous — better no
     * row than a wrong row.
     *
     * Examples that match:
     *   "25 lb cartons"       → 25 lb
     *   "50 lb sacks"         → 50 lb
     *   "1 1/9 bushel cartons" → 1.11 each (we treat bushel as "each" — too variable)
     *   "12 ct cartons"       → 12 each
     *   "flats 8 1-lb cont"   → 8 lb
     *   "1 pound bag"         → 1 lb
     *
     * The unit returned is one of cogs_benchmark's allowed units:
     * lb | oz | each | cup | tbsp.
     */
    public function normalizePackage(string $pkg): ?array
    {
        $p = strtolower(trim($pkg));
        if ($p === '') return null;

        if (preg_match('/(\d+(?:\.\d+)?)\s*(lb|pound|pounds)\b/', $p, $m)) {
            return ['quantityInUnit' => (float) $m[1], 'unit' => 'lb'];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(oz|ounce|ounces)\b/', $p, $m)) {
            return ['quantityInUnit' => (float) $m[1], 'unit' => 'oz'];
        }
        // Flats / cases with N×Mlb inner packs (e.g. "8 1-lb cont")
        if (preg_match('/(\d+)\s*x?\s*(\d+(?:\.\d+)?)\s*[- ]?\s*(lb|pound)/', $p, $m)) {
            $total = (float) $m[1] * (float) $m[2];
            return ['quantityInUnit' => $total, 'unit' => 'lb'];
        }
        // Count packs ("12 ct cartons", "24 ct"). Treat each piece as "each".
        if (preg_match('/(\d+)\s*(ct|count|each)\b/', $p, $m)) {
            return ['quantityInUnit' => (float) $m[1], 'unit' => 'each'];
        }
        // Bushels — convert to lb using typical produce bushel weight (~45 lb avg).
        // This is approximate, so flag in source_ref via the carrier slug; for now we
        // only use it if no lb match was found.
        if (preg_match('/(\d+(?:\.\d+)?)\s*bushel/', $p, $m)) {
            return ['quantityInUnit' => (float) $m[1] * 45.0, 'unit' => 'lb'];
        }
        return null;
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

    private function parseDate(mixed $v): ?string
    {
        if (!is_string($v) || $v === '') return null;
        // Accept ISO (2026-05-26) and US (05/26/2026).
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v, $m)) return substr($m[0], 0, 10);
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $v, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2]);
        }
        $ts = strtotime($v);
        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** @return array{0:int,1:string,2:int,3:?string} */
    protected function httpGet(string $url): array
    {
        $start = microtime(true);
        $ch    = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->apiKey . ':',
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
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

    /**
     * Resolve `ingredients => '__share_with__:1830'` references so every
     * report sees the same ingredient map without us repeating 21 lines
     * per slug.
     */
    private function resolveShares(): void
    {
        $bySlug = [];
        foreach ($this->reports as $r) $bySlug[(string) $r['slug']] = $r;
        foreach ($this->reports as $i => $r) {
            $ing = $r['ingredients'] ?? null;
            if (is_string($ing) && str_starts_with($ing, '__share_with__:')) {
                $ref = substr($ing, strlen('__share_with__:'));
                $this->reports[$i]['ingredients'] = $bySlug[$ref]['ingredients'] ?? [];
            }
        }
    }
}
