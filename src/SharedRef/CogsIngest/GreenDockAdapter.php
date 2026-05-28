<?php
declare(strict_types=1);

namespace App\SharedRef\CogsIngest;

use App\Core\Config;

/**
 * GreenDock-side COGS feed adapter — spec §1a Pipe A.
 *
 * TODO(pipe-a): the GreenDock side of this pipe is not built yet. This
 * adapter implements the documented contract on the Carafe side so that
 * the moment GreenDock starts publishing /benchmarks?since=... we get
 * real landed-cost data flowing into cogs_benchmark with source=
 * 'greendock' / 'usa_produce' / 'foundation_foods' alongside the USDA
 * rows the USDA adapters write.
 *
 * Until then: isEnabled() returns false (unless COGS_FEED_URL +
 * COGS_FEED_KEY are set), the service skips this adapter silently, and
 * the USDA + stub rows are what plate-cost queries hit.
 *
 * ──────────────────────────── Documented contract ────────────────────────────
 * GET  $COGS_FEED_URL/benchmarks?since=YYYY-MM-DD
 * Headers:
 *   Authorization: Bearer $COGS_FEED_KEY
 *   Accept: application/json
 *
 * Response (200):
 *   {
 *     "as_of": "YYYY-MM-DD",
 *     "items": [
 *       {
 *         "ingredient_key": "tomato_roma",
 *         "region": "US" | "US-NE" | null,
 *         "unit": "lb" | "oz" | "each" | "cup" | "tbsp",
 *         "market_price_cents": 180,
 *         "source": "greendock" | "usa_produce" | "foundation_foods"
 *       },
 *       ...
 *     ]
 *   }
 *
 * Any 4xx/5xx ⇒ IngestBatch(ok=false). A GreenDock outage must never
 * take Carafe down — Carafe just serves the last-ingested data.
 * ──────────────────────────────────────────────────────────────────────────
 */
class GreenDockAdapter implements CogsIngestAdapter
{
    private const ALLOWED_SOURCES = ['greendock', 'usa_produce', 'foundation_foods'];

    public function key(): string    { return 'greendock'; }
    public function source(): string { return 'greendock'; }
    public function regions(): array { return []; /* "all" — feed decides */ }

    public function isEnabled(): bool
    {
        return (string) Config::get('COGS_FEED_URL', '') !== ''
            && (string) Config::get('COGS_FEED_KEY', '') !== '';
    }

    public function fetchBatch(string $asOfDate, ?string $region): IngestBatch
    {
        // TODO(pipe-a): contract pending — log once per process and short-circuit
        // until COGS_FEED_URL/KEY land. The service treats this as a no-op when
        // isEnabled() is false, so this branch only runs once the operator has
        // pointed env at a live GreenDock endpoint.
        if (!$this->isEnabled()) {
            error_log('[cogs-benchmark][greendock] adapter contract pending (spec §1a Pipe A); set COGS_FEED_URL + COGS_FEED_KEY to enable');
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: $region, asOf: $asOfDate, rows: [],
                ok: true, notes: ['stub_reason' => 'COGS_FEED env not configured; pipe A pending'],
            );
        }

        $url = rtrim((string) Config::get('COGS_FEED_URL'), '/') . '/benchmarks?since=' . urlencode($asOfDate);
        if ($region !== null) $url .= '&region=' . urlencode($region);

        [$status, $body, $latencyMs, $err] = $this->httpGet($url);

        if ($err !== null || $body === '') {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: $region, asOf: $asOfDate, rows: [],
                endpoint: $url, httpStatus: $status, latencyMs: $latencyMs,
                ok: false, errorMessage: $err ?? 'empty response',
            );
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: $region, asOf: $asOfDate, rows: [],
                endpoint: $url, httpStatus: $status, latencyMs: $latencyMs,
                ok: false, errorMessage: 'non-JSON response',
            );
        }
        $feedAsOf = (string) ($payload['as_of'] ?? $asOfDate);
        $items    = $payload['items'] ?? [];
        if (!is_array($items)) {
            return new IngestBatch(
                adapter: $this->key(), source: $this->source(),
                region: $region, asOf: $feedAsOf, rows: [],
                endpoint: $url, httpStatus: $status, latencyMs: $latencyMs,
                ok: false, errorMessage: 'bad payload (items not array)',
            );
        }

        $rows    = [];
        $skipped = 0;
        foreach ($items as $it) {
            $row = $this->normalize($it, $feedAsOf);
            if ($row === null) { $skipped++; continue; }
            $rows[] = $row;
        }

        return new IngestBatch(
            adapter: $this->key(), source: $this->source(),
            region: $region, asOf: $feedAsOf, rows: $rows,
            endpoint: $url, httpStatus: $status, latencyMs: $latencyMs,
            ok: true,
            notes: ['records_in_response' => count($items), 'records_skipped' => $skipped],
        );
    }

    private function normalize(mixed $raw, string $defaultAsOf): ?IngestRow
    {
        if (!is_array($raw)) return null;
        $key    = isset($raw['ingredient_key']) ? trim((string) $raw['ingredient_key']) : '';
        $unit   = isset($raw['unit']) ? trim((string) $raw['unit']) : '';
        $price  = isset($raw['market_price_cents']) ? (int) $raw['market_price_cents'] : -1;
        $source = isset($raw['source']) ? (string) $raw['source'] : 'greendock';
        $region = isset($raw['region']) ? (string) $raw['region'] : null;
        if ($region === '') $region = null;
        if ($key === '' || $unit === '' || $price < 0) return null;
        if (!in_array($source, self::ALLOWED_SOURCES, true)) return null;

        // GreenDock payloads carry their own per-row source; we encode it in
        // source_ref so the service can split a single batch into the
        // appropriate cogs_benchmark.source values downstream.
        return new IngestRow(
            ingredientKey:    $key,
            unit:             $unit,
            marketPriceCents: $price,
            region:           $region,
            asOf:             isset($raw['as_of']) ? (string) $raw['as_of'] : $defaultAsOf,
            sourceRef:        'greendock_source=' . $source,
        );
    }

    /** @return array{0:int,1:string,2:int,3:?string} */
    protected function httpGet(string $url): array
    {
        $start = microtime(true);
        $ch    = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Authorization: Bearer ' . (string) Config::get('COGS_FEED_KEY'),
            ],
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
