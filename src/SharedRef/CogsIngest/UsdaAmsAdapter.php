<?php
declare(strict_types=1);

namespace App\SharedRef\CogsIngest;

use App\Core\Config;

/**
 * USDA AMS MyMarketNews adapter — public, free wholesale terminal-market
 * prices. Each configured slug is one report split (e.g. Boston Terminal
 * Vegetables Prices, slug 2286). Auth is HTTP Basic with the API key as
 * username and empty password.
 *
 * MARS API call shape (verified against the live API on 2026-05-27):
 *   GET https://marsapi.ams.usda.gov/services/v1.2/reports/{slug}/Report Details
 *       ?q=report_begin_date=YYYY-MM-DD
 *   (the "/Report Details" section is required; the root URL returns
 *    a header-only section with no commodity rows)
 *
 * One slug → one IngestBatch. fetchBatchesForRegion yields one batch
 * per slug touching the requested region. fetchBatch(region) returns
 * the first.
 *
 * Defensive: never throws. CURLOPT_CONNECTTIMEOUT=3 per audit §5.4.
 */
class UsdaAmsAdapter implements CogsIngestAdapter
{
    /** @var array<int, array<string, mixed>> */
    private array $reports;

    private string $apiKey;

    private const ENDPOINT_FMT = 'https://marsapi.ams.usda.gov/services/v1.2/reports/%s/Report%%20Details';

    /**
     * Average pounds per single bushel by commodity. USDA AMS reports
     * prices per "1/2 bushel", "1 1/9 bushel", etc.; multiplying through
     * the bushel fraction gives us total pounds in the package.
     *
     * Source: USDA AMS "Weights, Measures, and Conversion Factors for
     * Agricultural Commodities and Their Products" (Handbook 697).
     */
    private const BUSHEL_LBS = [
        'TOMATOES'    => 53,
        'CUCUMBERS'   => 50,
        'PEPPERS'     => 28,
        'LETTUCE'     => 24,
        'BROCCOLI'    => 30,
        'CAULIFLOWER' => 38,
        'CARROTS'     => 50,
        'CELERY'      => 60,
        'SPINACH'     => 20,
        'GREENS'      => 20,
        'MUSHROOMS'   => 18,
        'GARLIC'      => 30,
        'ONIONS'      => 50,
        'POTATOES'    => 60,
        'APPLES'      => 42,
        'PEACHES'     => 48,
        'PEARS'       => 50,
    ];
    private const BUSHEL_DEFAULT_LBS = 40;

    public function __construct(?array $reports = null, ?string $apiKey = null)
    {
        $this->reports = $reports ?? require dirname(__DIR__, 3) . '/config/cogs_usda_ams_reports.php';
        $this->apiKey  = $apiKey  ?? (string) Config::get('USDA_API_KEY', '');
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

    public function fetchBatch(string $asOfDate, ?string $region): IngestBatch
    {
        $batches = $this->fetchBatchesForRegion($asOfDate, $region);
        if (!$batches) {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: $region, asOf: $asOfDate, rows: [],
                ok: false, errorMessage: 'no USDA AMS slugs configured for region ' . ($region ?? 'null'),
            );
        }
        return $batches[0];
    }

    /** @return IngestBatch[] one batch per AMS slug touching $region */
    public function fetchBatchesForRegion(string $asOfDate, ?string $region): array
    {
        $slugs = array_values(array_filter(
            $this->reports,
            fn($r) => $region === null || $r['region'] === $region
        ));
        $out = [];
        foreach ($slugs as $r) $out[] = $this->fetchOneSlug($asOfDate, (array) $r);
        return $out;
    }

    private function fetchOneSlug(string $asOfDate, array $report): IngestBatch
    {
        $slug   = (string) $report['slug'];
        $region = (string) $report['region'];
        $label  = (string) ($report['label'] ?? "USDA AMS slug $slug");

        // AMS publishes daily. If $asOfDate is today and the report hasn't
        // published yet, we want the previous business day. Try $asOfDate
        // first, then walk back up to 4 days.
        for ($offset = 0; $offset <= 4; $offset++) {
            $tryDate = date('Y-m-d', strtotime("$asOfDate -{$offset} days"));
            [$status, $body, $latencyMs, $err, $url] = $this->fetchForDate($slug, $tryDate);
            if ($err === null && $status === 200) {
                $batch = $this->parseResponse($body, $status, $latencyMs, $url, $region, $label, $tryDate, $report['commodities'] ?? []);
                if (count($batch->rows) > 0 || $offset === 4) return $batch;
                continue; // 200 but empty — walk back
            }
            if ($offset === 4) {
                return new IngestBatch(
                    adapter: $this->key(), source: $this->source(),
                    region: $region, asOf: $tryDate, rows: [],
                    endpoint: $url, sourceRef: $label,
                    httpStatus: $status, latencyMs: $latencyMs,
                    ok: false, errorMessage: $err ?? "no data after 4-day walkback (HTTP $status)",
                );
            }
        }
        // Unreachable, satisfy static analysis
        return new IngestBatch(adapter: $this->key(), source: $this->source(),
            region: $region, asOf: $asOfDate, rows: [], ok: false,
            errorMessage: 'walkback loop exhausted unexpectedly');
    }

    /** @return array{0:int,1:string,2:int,3:?string,4:string} */
    private function fetchForDate(string $slug, string $date): array
    {
        $endpoint = sprintf(self::ENDPOINT_FMT, $slug);
        $url = $endpoint . '?' . http_build_query(['q' => "report_begin_date=$date"]);
        [$status, $body, $latencyMs, $err] = $this->httpGet($url);
        return [$status, $body, $latencyMs, $err, $url];
    }

    private function parseResponse(string $body, int $status, int $latencyMs, string $url, string $region, string $label, string $asOfDate, array $commodityMap): IngestBatch
    {
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['results']) || !is_array($json['results'])) {
            return new IngestBatch(adapter: $this->key(), source: $this->source(),
                region: $region, asOf: $asOfDate, rows: [],
                endpoint: $url, sourceRef: $label,
                httpStatus: $status, latencyMs: $latencyMs,
                ok: false, errorMessage: 'non-JSON or missing results[]');
        }

        $records = $json['results'];
        $rows = [];
        $skippedNoMatch = 0;
        $skippedUnparseable = 0;
        $latestObserved = $asOfDate;

        foreach ($records as $rec) {
            if (!is_array($rec)) { $skippedUnparseable++; continue; }
            $matched = $this->matchIngredient($rec, $commodityMap);
            if ($matched === null) { $skippedNoMatch++; continue; }
            $row = $this->buildRow($rec, $matched, $region, $label);
            if ($row === null) { $skippedUnparseable++; continue; }
            $rows[] = $row;
            if ($row->asOf > $latestObserved) $latestObserved = $row->asOf;
        }

        return new IngestBatch(
            adapter: $this->key(), source: $this->source(),
            region: $region, asOf: $latestObserved, rows: $rows,
            endpoint: $url, sourceRef: $label,
            httpStatus: $status, latencyMs: $latencyMs,
            ok: true,
            notes: [
                'records_in_response' => count($records),
                'no_match'            => $skippedNoMatch,
                'unparseable'         => $skippedUnparseable,
            ],
        );
    }

    private function matchIngredient(array $rec, array $commodityMap): ?array
    {
        $commodity = strtoupper((string) ($rec['commodity'] ?? ''));
        $variety   = strtoupper((string) ($rec['variety']   ?? ''));
        if ($commodity === '') return null;

        foreach ($commodityMap as $entry) {
            [$c, $v, $key] = $entry;
            $c = strtoupper((string) $c);
            $v = strtoupper((string) $v);
            if ($c !== '' && !str_contains($commodity, $c)) continue;
            if ($v !== '' && !str_contains($variety, $v))   continue;
            return ['ingredient_key' => $key, 'matched_commodity' => $commodity, 'matched_variety' => $variety];
        }
        return null;
    }

    private function buildRow(array $rec, array $matched, string $region, string $label): ?IngestRow
    {
        $low  = $this->parseFloat($rec['mostly_low_price']  ?? $rec['low_price']  ?? null);
        $high = $this->parseFloat($rec['mostly_high_price'] ?? $rec['high_price'] ?? null);
        if ($low === null && $high === null) return null;
        if ($low === null)  $low  = $high;
        if ($high === null) $high = $low;
        $midDollars = ($low + $high) / 2.0;
        if ($midDollars <= 0) return null;

        $package = (string) ($rec['package'] ?? '');
        $itemSize = (string) ($rec['item_size'] ?? '');
        $commodity = strtoupper((string) ($rec['commodity'] ?? ''));

        $norm = $this->normalizePackage($package, $commodity, $itemSize);
        if ($norm === null) return null;

        $perUnitDollars = $midDollars / $norm['quantityInUnit'];
        $cents = (int) round($perUnitDollars * 100);
        if ($cents <= 0 || $cents > 100_000) return null;

        $asOf = $this->parseDate($rec['report_begin_date'] ?? $rec['report_end_date'] ?? $rec['published_date'] ?? null)
              ?? date('Y-m-d');

        return new IngestRow(
            ingredientKey:    (string) $matched['ingredient_key'],
            unit:             $norm['unit'],
            marketPriceCents: $cents,
            region:           $region,
            asOf:             $asOf,
            sourceRef:        sprintf('%s | %s %s | %s', $label,
                                 (string) $rec['commodity'],
                                 (string) ($rec['variety'] ?? ''),
                                 $package),
        );
    }

    /**
     * Parse the AMS "package" string into (quantity, unit). Returns null
     * when we can't confidently normalize — better no row than a wrong row.
     *
     * Order matters: most-specific patterns first.
     */
    public function normalizePackage(string $pkg, string $commodity = '', string $itemSize = ''): ?array
    {
        $p = strtolower(trim($pkg));
        if ($p === '') return null;

        // 1. Sub-pack arithmetic: "cartons 12 3-lb bags" / "cartons 16 1-lb packages"
        if (preg_match('/(\d+)\s+(\d+(?:\.\d+)?)[- ]\s*lb\b/', $p, $m)) {
            return ['quantityInUnit' => (float) $m[1] * (float) $m[2], 'unit' => 'lb'];
        }
        if (preg_match('/(\d+)\s+(\d+(?:\.\d+)?)[- ]\s*oz\b/', $p, $m)) {
            return ['quantityInUnit' => (float) $m[1] * (float) $m[2], 'unit' => 'oz'];
        }
        // 2. Explicit "N lb" / "N pound" cartons
        if (preg_match('/(\d+(?:\.\d+)?)(?:[- ])?(?:\s*)(lb|pound|pounds)\b/', $p, $m)) {
            return ['quantityInUnit' => (float) $m[1], 'unit' => 'lb'];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(oz|ounce|ounces)\b/', $p, $m)) {
            return ['quantityInUnit' => (float) $m[1], 'unit' => 'oz'];
        }
        // 3. Kilograms → lb (1 kg = 2.20462 lb)
        if (preg_match('/(\d+(?:\.\d+)?)\s*kg\b/', $p, $m)) {
            return ['quantityInUnit' => (float) $m[1] * 2.20462, 'unit' => 'lb'];
        }
        // 4. Bushels: "1/2 bushel", "1 1/9 bushel cartons", "4/5 bushel cartons"
        $bushels = $this->parseBushelFraction($p);
        if ($bushels !== null) {
            $lbsPerBushel = $this->bushelLbsFor($commodity);
            return ['quantityInUnit' => $bushels * $lbsPerBushel, 'unit' => 'lb'];
        }
        // 5. Count packs ("12 count", "24 ct"). Item is "each".
        if (preg_match('/(\d+)\s*(?:ct|count)\b/', $p, $m)) {
            return ['quantityInUnit' => (float) $m[1], 'unit' => 'each'];
        }
        return null;
    }

    /** Parse "1 1/9", "4/5", "2/3", "1/2", "2" from start of $p. */
    private function parseBushelFraction(string $p): ?float
    {
        if (!str_contains($p, 'bushel')) return null;
        // "1 1/9 bushel" — whole + fraction
        if (preg_match('/^(\d+)\s+(\d+)\s*\/\s*(\d+)\s+bushel/', $p, $m)) {
            return (float) $m[1] + ((float) $m[2] / (float) $m[3]);
        }
        // "1/2 bushel"
        if (preg_match('/^(\d+)\s*\/\s*(\d+)\s+bushel/', $p, $m)) {
            return (float) $m[1] / (float) $m[2];
        }
        // "2 bushel" / "1 bushel"
        if (preg_match('/^(\d+(?:\.\d+)?)\s+bushel/', $p, $m)) {
            return (float) $m[1];
        }
        // Just "bushel" (rare) — treat as 1
        return 1.0;
    }

    private function bushelLbsFor(string $commodity): float
    {
        foreach (self::BUSHEL_LBS as $key => $lbs) {
            if (str_contains($commodity, $key)) return (float) $lbs;
        }
        return self::BUSHEL_DEFAULT_LBS;
    }

    private function parseFloat(mixed $v): ?float
    {
        if ($v === null || $v === '' || $v === 'N/A') return null;
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
}
