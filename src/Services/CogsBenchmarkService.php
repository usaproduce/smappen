<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;

/**
 * Ingestion adapter for the GreenDock-published COGS feed (spec §1a Pipe A).
 *
 * Carafe pulls USDA + USA Produce / Foundation Foods landed-cost data from
 * GreenDock over HTTPS and writes it into `cogs_benchmark` with
 * `source` provenance set per-row. GreenDock is a SEPARATE SYSTEM —
 * Carafe never reads its database directly.
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
 *         "source": "usda" | "greendock" | "usa_produce" | "foundation_foods"
 *       },
 *       ...
 *     ]
 *   }
 *
 * Any 4xx or 5xx ⇒ Carafe LOGS and serves the last-ingested data (mirror
 * of DataFreshnessFooter behavior in the base). A GreenDock outage must
 * never take Carafe down.
 * ──────────────────────────────────────────────────────────────────────────
 *
 * Graceful degradation matches the base's `ANTHROPIC_API_KEY` pattern:
 * if env COGS_FEED_URL or COGS_FEED_KEY is unset, ingestion is a no-op
 * (no exception thrown) and the local stub data from
 * `seed-cogs-benchmark-stub.php` continues to drive plate-cost lookups.
 */
class CogsBenchmarkService
{
    public function isConfigured(): bool
    {
        return (string) Config::get('COGS_FEED_URL', '') !== ''
            && (string) Config::get('COGS_FEED_KEY', '') !== '';
    }

    /**
     * Pull from the live feed, upsert into cogs_benchmark.
     * Returns: ['fetched' => N, 'inserted' => N, 'skipped' => N, 'feed_as_of' => 'YYYY-MM-DD']
     * Or:      ['stub_mode' => true] if not configured (silent no-op).
     */
    public function ingest(?string $since = null): array
    {
        if (!$this->isConfigured()) {
            error_log('[cogs-benchmark] COGS feed not configured — stub mode active');
            return ['stub_mode' => true, 'fetched' => 0, 'inserted' => 0, 'skipped' => 0];
        }

        $url = rtrim((string) Config::get('COGS_FEED_URL'), '/') . '/benchmarks';
        if ($since !== null) $url .= '?since=' . urlencode($since);

        $payload = $this->fetch($url);
        if ($payload === null) {
            return ['stub_mode' => false, 'fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'error' => 'fetch_failed'];
        }

        $asOf = (string) ($payload['as_of'] ?? date('Y-m-d'));
        $items = $payload['items'] ?? [];
        if (!is_array($items)) {
            error_log('[cogs-benchmark] feed returned non-array items');
            return ['stub_mode' => false, 'fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'error' => 'bad_payload'];
        }

        $inserted = 0;
        $skipped  = 0;
        foreach ($items as $it) {
            $valid = $this->validateItem($it);
            if (!$valid) { $skipped++; continue; }
            try {
                Database::getInstance()->query(
                    'INSERT INTO cogs_benchmark
                        (id, ingredient_key, region, market_price_cents, unit, source, as_of, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        Database::uuid(),
                        $valid['ingredient_key'],
                        $valid['region'],
                        $valid['market_price_cents'],
                        $valid['unit'],
                        $valid['source'],
                        $valid['as_of'] ?? $asOf,
                    ]
                );
                $inserted++;
            } catch (\Throwable $e) {
                // Likely a UNIQUE collision (same ingredient/region/source/as_of already
                // ingested today). That's fine — re-runs are idempotent. Anything else
                // we want to know about.
                if (str_contains($e->getMessage(), '1062')) {
                    $skipped++;
                } else {
                    error_log('[cogs-benchmark] insert failed: ' . $e->getMessage());
                    $skipped++;
                }
            }
        }

        return [
            'stub_mode'  => false,
            'feed_as_of' => $asOf,
            'fetched'    => count($items),
            'inserted'   => $inserted,
            'skipped'    => $skipped,
        ];
    }

    private function fetch(string $url): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . (string) Config::get('COGS_FEED_KEY'),
            ],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            error_log('[cogs-benchmark] HTTP error: ' . $err);
            return null;
        }
        if ($code >= 400) {
            error_log('[cogs-benchmark] feed returned HTTP ' . $code . ' — serving last-ingested data');
            return null;
        }
        $parsed = json_decode((string) $resp, true);
        if (!is_array($parsed)) {
            error_log('[cogs-benchmark] feed returned non-JSON');
            return null;
        }
        return $parsed;
    }

    /** Returns a normalized row or null if the input is malformed. */
    private function validateItem(mixed $raw): ?array
    {
        if (!is_array($raw)) return null;
        $key = isset($raw['ingredient_key']) ? trim((string) $raw['ingredient_key']) : '';
        $unit = isset($raw['unit']) ? trim((string) $raw['unit']) : '';
        $price = isset($raw['market_price_cents']) ? (int) $raw['market_price_cents'] : -1;
        $source = isset($raw['source']) ? (string) $raw['source'] : '';
        $region = isset($raw['region']) ? (string) $raw['region'] : null;
        if ($region === '') $region = null;
        if ($key === '' || $unit === '' || $price < 0) return null;
        if (!in_array($source, ['usda', 'greendock', 'usa_produce', 'foundation_foods'], true)) return null;
        return [
            'ingredient_key'     => $key,
            'unit'               => $unit,
            'market_price_cents' => $price,
            'source'             => $source,
            'region'             => $region,
            'as_of'              => isset($raw['as_of']) ? (string) $raw['as_of'] : null,
        ];
    }
}
