<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\SharedRef\CogsIngest\CogsIngestAdapter;
use App\SharedRef\CogsIngest\GreenDockAdapter;
use App\SharedRef\CogsIngest\IngestBatch;
use App\SharedRef\CogsIngest\IngestRow;
use App\SharedRef\CogsIngest\UsdaAmsAdapter;
use App\SharedRef\CogsIngest\UsdaNassAdapter;

/**
 * Orchestrator over the registered CogsIngestAdapter implementations.
 *
 * Today's adapters: UsdaAmsAdapter (terminal-market produce), UsdaNassAdapter
 * (national commodity proxies for protein/dairy), GreenDockAdapter (spec
 * §1a Pipe A — stub until COGS_FEED_URL/KEY land).
 *
 * ingest() iterates over enabled adapters × (caller-supplied or adapter-
 * declared) regions, writes each adapter result as one cogs_ingest_batches
 * row, then upserts the IngestRows into cogs_benchmark with batch_id set.
 * Failures don't take the loop down — every batch's success/failure is
 * recorded in cogs_ingest_batches.ok.
 *
 * isConfigured() returns true when at least one non-stub source has a row
 * in the last 30 days. This is what PlateCostService / FoodCostController
 * key their "live data or stub?" UI off (audit §509).
 */
class CogsBenchmarkService
{
    /** @var CogsIngestAdapter[] */
    private array $adapters;

    public function __construct(?array $adapters = null)
    {
        $this->adapters = $adapters ?? [
            new UsdaAmsAdapter(),
            new UsdaNassAdapter(),
            new GreenDockAdapter(),
        ];
    }

    /**
     * Per audit §509: configured ≡ at least one non-stub row landed in
     * cogs_benchmark in the last 30 days. Env-only check is not enough
     * because the GreenDock pipe might be set up but not yet replying,
     * while USDA might be flowing fine — what matters for the UI is
     * whether any real data is on file.
     */
    public function isConfigured(): bool
    {
        try {
            $row = Database::getInstance()->fetch(
                "SELECT 1 AS one
                   FROM cogs_benchmark
                  WHERE source <> 'stub'
                    AND as_of >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  LIMIT 1"
            );
            return $row !== null;
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] isConfigured() failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Drive every enabled adapter for every requested region. Returns
     * aggregate stats. Pass dryRun=true to fetch + parse but skip writes
     * (the script's --dry mode).
     *
     * @param string[]|null $regions e.g. ['US','US-NE','US-MID-ATLANTIC']. Null = adapter chooses.
     */
    public function ingest(?string $asOfDate = null, ?array $regions = null, bool $dryRun = false): array
    {
        $asOf = $asOfDate ?? date('Y-m-d');
        $summary = [
            'as_of'          => $asOf,
            'dry_run'        => $dryRun,
            'adapters_run'   => [],
            'batches'        => 0,
            'batches_ok'     => 0,
            'rows_fetched'   => 0,
            'rows_inserted'  => 0,
            'rows_skipped'   => 0,
        ];

        foreach ($this->adapters as $adapter) {
            if (!$adapter->isEnabled()) {
                $summary['adapters_run'][] = ['key' => $adapter->key(), 'enabled' => false];
                continue;
            }
            $targets = $this->resolveRegions($adapter, $regions);
            $adapterStats = ['key' => $adapter->key(), 'enabled' => true, 'batches' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => 0];

            foreach ($targets as $region) {
                $batches = $this->callAdapter($adapter, $asOf, $region);
                foreach ($batches as $batch) {
                    $summary['batches']++;
                    $adapterStats['batches']++;
                    if ($batch->ok) $summary['batches_ok']++;
                    $summary['rows_fetched'] += count($batch->rows);

                    [$inserted, $skipped] = $this->persistBatch($batch, $dryRun);
                    $summary['rows_inserted'] += $inserted;
                    $summary['rows_skipped']  += $skipped;
                    $adapterStats['inserted'] += $inserted;
                    $adapterStats['skipped']  += $skipped;
                    if (!$batch->ok) $adapterStats['errors']++;
                }
            }
            $summary['adapters_run'][] = $adapterStats;
        }
        return $summary;
    }

    /**
     * Freshness data for the UI footer ("USDA Mid-Atlantic, refreshed 14h ago").
     * Returns rows: source, region, label, as_of, last_ingested_at, rows.
     * Picks the most-recent successful ingest per (source, region).
     */
    public function freshness(?string $region = null): array
    {
        $sql = "SELECT source, region, MAX(fetched_at) AS last_ingested_at,
                       MAX(as_of)      AS as_of,
                       SUM(rows_inserted) AS rows
                  FROM cogs_ingest_batches
                 WHERE ok = 1
                   AND fetched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $params = [];
        if ($region !== null) {
            // Restaurant region OR national fallback OR adapter-agnostic NULL.
            $sql .= " AND (region = ? OR region = 'US' OR region IS NULL)";
            $params[] = $region;
        }
        $sql .= " GROUP BY source, region
                  ORDER BY last_ingested_at DESC";
        try {
            return Database::getInstance()->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] freshness() failed: ' . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────

    /** @return string[]|array<int, null> */
    private function resolveRegions(CogsIngestAdapter $adapter, ?array $requested): array
    {
        $advertised = $adapter->regions();
        if ($requested === null || $requested === []) {
            return $advertised === [] ? [null] : $advertised;
        }
        if ($advertised === []) return $requested; // adapter accepts anything
        $intersection = array_values(array_intersect($requested, $advertised));
        return $intersection === [] ? [] : $intersection;
    }

    /** Adapters that yield many batches per region expose fetchBatchesForRegion. */
    private function callAdapter(CogsIngestAdapter $adapter, string $asOf, ?string $region): array
    {
        if (method_exists($adapter, 'fetchBatchesForRegion')) {
            try {
                return (array) $adapter->fetchBatchesForRegion($asOf, $region);
            } catch (\Throwable $e) {
                error_log('[cogs-benchmark] adapter ' . $adapter->key() . ' threw: ' . $e->getMessage());
                return [];
            }
        }
        try {
            return [$adapter->fetchBatch($asOf, $region)];
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] adapter ' . $adapter->key() . ' threw: ' . $e->getMessage());
            return [];
        }
    }

    /** @return array{0:int,1:int} [inserted, skipped] */
    private function persistBatch(IngestBatch $batch, bool $dryRun): array
    {
        // Group rows by effective source (the GreenDockAdapter encodes per-row
        // source overrides in sourceRef "greendock_source=usa_produce" etc.).
        if ($dryRun) {
            return [count($batch->rows), 0];
        }

        $db = Database::getInstance();
        $batchId = $this->recordBatch($batch);

        $inserted = 0;
        $skipped  = 0;
        foreach ($batch->rows as $row) {
            $rowSource = $this->resolveRowSource($batch, $row);
            try {
                $db->query(
                    'INSERT INTO cogs_benchmark
                        (id, ingredient_key, region, market_price_cents, unit, source, as_of, batch_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
                    [
                        Database::uuid(),
                        $row->ingredientKey,
                        $row->region,
                        $row->marketPriceCents,
                        $row->unit,
                        $rowSource,
                        $row->asOf,
                        $batchId,
                    ]
                );
                $inserted++;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '1062')) {
                    $skipped++;
                } else {
                    error_log('[cogs-benchmark] insert failed: ' . $e->getMessage());
                    $skipped++;
                }
            }
        }

        // Update the batch row with final counts now that we know them.
        if ($batchId !== null) {
            try {
                $db->query(
                    'UPDATE cogs_ingest_batches SET rows_inserted = ?, rows_skipped = ? WHERE id = ?',
                    [$inserted, $skipped, $batchId]
                );
            } catch (\Throwable $e) {
                error_log('[cogs-benchmark] batch counts update failed: ' . $e->getMessage());
            }
        }
        return [$inserted, $skipped];
    }

    private function resolveRowSource(IngestBatch $batch, IngestRow $row): string
    {
        // GreenDock-pattern: per-row source override encoded in sourceRef.
        if (is_string($row->sourceRef) && str_starts_with($row->sourceRef, 'greendock_source=')) {
            return substr($row->sourceRef, strlen('greendock_source='));
        }
        return $batch->source;
    }

    private function recordBatch(IngestBatch $batch): ?string
    {
        try {
            $id = Database::uuid();
            Database::getInstance()->query(
                'INSERT INTO cogs_ingest_batches
                    (id, adapter, source, region, endpoint, source_ref, as_of, fetched_at,
                     rows_fetched, rows_inserted, rows_skipped,
                     http_status, latency_ms, ok, error_message, notes_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 0, 0, ?, ?, ?, ?, ?)',
                [
                    $id,
                    $batch->adapter,
                    $batch->source,
                    $batch->region,
                    $batch->endpoint !== null ? substr($batch->endpoint, 0, 500) : null,
                    $batch->sourceRef,
                    $batch->asOf,
                    count($batch->rows),
                    $batch->httpStatus,
                    $batch->latencyMs,
                    $batch->ok ? 1 : 0,
                    $batch->errorMessage !== null ? substr($batch->errorMessage, 0, 500) : null,
                    $batch->notes !== null ? json_encode($batch->notes) : null,
                ]
            );
            return $id;
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] batch ledger insert failed: ' . $e->getMessage());
            return null;
        }
    }
}
