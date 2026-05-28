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
 * v2 (mig 039): instead of inserting raw rows and letting UNIQUE
 * collisions arbitrate, the service ACCUMULATES IngestRows across all
 * batches into a (key, region, source, as_of) bucket, then writes the
 * MEDIAN cents per bucket with observation_count + price_stddev_cents +
 * is_anomaly. After writes, recomputes 7d/30d rolling stats for every
 * touched tuple. Anomaly = |today − prior_30d_mean| > 3 × prior_30d_stddev.
 *
 * isConfigured() = at least one non-stub row in cogs_benchmark within the
 * last 30 days (audit §509). PlateCostService / FoodCostController key UI
 * messaging off this.
 */
class CogsBenchmarkService
{
    /** @var CogsIngestAdapter[] */
    private array $adapters;

    private const ANOMALY_SIGMA = 3.0;

    public function __construct(?array $adapters = null)
    {
        $this->adapters = $adapters ?? [
            new UsdaAmsAdapter(),
            new UsdaNassAdapter(),
            new GreenDockAdapter(),
        ];
    }

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
     * Drive every enabled adapter for every requested region, accumulate
     * observations, write rolled-up rows, recompute rolling stats, flag
     * anomalies.
     *
     * @param string[]|null $regions e.g. ['US','US-NE',...]. Null = adapter chooses.
     */
    public function ingest(?string $asOfDate = null, ?array $regions = null, bool $dryRun = false): array
    {
        $asOf = $asOfDate ?? date('Y-m-d');
        $summary = [
            'as_of'           => $asOf,
            'dry_run'         => $dryRun,
            'adapters_run'    => [],
            'batches'         => 0,
            'batches_ok'      => 0,
            'rows_fetched'    => 0,
            'rows_contributed'=> 0,
            'rollups_written' => 0,
            'anomalies'       => 0,
            'unmatched_logged'=> 0,
        ];

        // Accumulator: "ingredient_key|region_or_*|source|as_of" => [{cents, unit, batch_id}, ...]
        $accumulator = [];
        // Per-batch contribution counts so we can update cogs_ingest_batches
        // after the rollups land.
        $batchContrib = [];
        $batchIds = [];

        foreach ($this->adapters as $adapter) {
            if (!$adapter->isEnabled()) {
                $summary['adapters_run'][] = ['key' => $adapter->key(), 'enabled' => false];
                continue;
            }
            $targets = $this->resolveRegions($adapter, $regions);
            $adapterStats = ['key' => $adapter->key(), 'enabled' => true, 'batches' => 0, 'contributed' => 0, 'errors' => 0];

            foreach ($targets as $region) {
                $batches = $this->callAdapter($adapter, $asOf, $region);
                foreach ($batches as $batch) {
                    $summary['batches']++;
                    $adapterStats['batches']++;
                    if ($batch->ok) $summary['batches_ok']++;
                    else            $adapterStats['errors']++;
                    $summary['rows_fetched'] += count($batch->rows);

                    $batchId = $dryRun ? null : $this->recordBatch($batch);
                    if ($batchId !== null) {
                        $batchIds[$batchId]  = true;
                        $batchContrib[$batchId] = 0;
                    }

                    // Accumulate rows.
                    foreach ($batch->rows as $row) {
                        $rowSrc = $this->resolveRowSource($batch, $row);
                        $regionKey = $row->region ?? '*';
                        $bucket = $row->ingredientKey . '|' . $regionKey . '|' . $rowSrc . '|' . $row->asOf;
                        $accumulator[$bucket][] = [
                            'cents' => $row->marketPriceCents,
                            'unit'  => $row->unit,
                            'batch' => $batchId,
                        ];
                        $summary['rows_contributed']++;
                        $adapterStats['contributed']++;
                        if ($batchId !== null) $batchContrib[$batchId]++;
                    }

                    // Log unmatched commodities.
                    if (!$dryRun && !empty($batch->unmatched)) {
                        $logged = $this->persistUnmatched($adapter->key(), $batch->sourceRef, $batch->unmatched);
                        $summary['unmatched_logged'] += $logged;
                    }
                }
            }
            $summary['adapters_run'][] = $adapterStats;
        }

        if ($dryRun) {
            // Tell the operator what would have been written.
            $summary['rollups_written'] = count($accumulator);
            return $summary;
        }

        // Write rolled-up rows. One per bucket.
        $touched = [];
        foreach ($accumulator as $bucket => $observations) {
            [$ingredientKey, $regionKey, $rowSource, $asOfRow] = explode('|', $bucket, 4);
            $region = $regionKey === '*' ? null : $regionKey;

            $rollup = $this->rollup($observations);
            if ($rollup === null) continue;

            $isAnomaly = $this->isAnomalous($ingredientKey, $region, $rowSource, $asOfRow, $rollup['median_cents']);
            if ($isAnomaly) $summary['anomalies']++;

            $this->upsertRollupRow(
                ingredientKey:   $ingredientKey,
                region:          $region,
                source:          $rowSource,
                asOf:            $asOfRow,
                medianCents:     $rollup['median_cents'],
                stddevCents:     $rollup['stddev_cents'],
                observationCount:$rollup['n'],
                unit:            $rollup['unit'],
                batchId:         $rollup['batch'],
                isAnomaly:       $isAnomaly,
            );
            $summary['rollups_written']++;

            $touched[$ingredientKey . '|' . $regionKey . '|' . $rowSource] = [
                'ingredient_key' => $ingredientKey,
                'region'         => $region,
                'source'         => $rowSource,
            ];
        }

        // Update per-batch contribution counters.
        if (!empty($batchContrib)) {
            try {
                $db = Database::getInstance();
                foreach ($batchContrib as $bid => $contrib) {
                    $db->query(
                        'UPDATE cogs_ingest_batches SET rows_inserted = ?, rows_skipped = ? WHERE id = ?',
                        [$contrib, max(0, $contrib === 0 ? 0 : 0), $bid]
                    );
                }
            } catch (\Throwable $e) {
                error_log('[cogs-benchmark] batch contrib update failed: ' . $e->getMessage());
            }
        }

        // Recompute rolling stats for every touched tuple.
        $summary['rolling_updated'] = $this->recomputeRollings(array_values($touched));

        return $summary;
    }

    /**
     * For each (source, region) tuple, fill in any missing days in the
     * last $days days by re-running ingest() with that date. Returns the
     * list of dates fetched. Quiet no-op when no gaps exist.
     */
    public function backfillMissingDays(int $days = 14, ?array $regions = null): array
    {
        $today = date('Y-m-d');
        $oldest = date('Y-m-d', strtotime("-$days days"));

        // Find every (source, region) tuple that has ingested at all and
        // figure out which dates are missing within $days.
        try {
            $existing = Database::getInstance()->fetchAll(
                "SELECT DISTINCT source, region, as_of
                   FROM cogs_benchmark
                  WHERE source <> 'stub'
                    AND as_of BETWEEN ? AND ?",
                [$oldest, $today]
            );
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] backfill scan failed: ' . $e->getMessage());
            return [];
        }

        $haveByTuple = [];
        foreach ($existing as $r) {
            $tuple = (string) $r['source'] . '|' . ($r['region'] ?? '*');
            $haveByTuple[$tuple][$r['as_of']] = true;
        }

        $missingDates = [];
        foreach ($haveByTuple as $tuple => $haveSet) {
            for ($d = strtotime($oldest); $d <= strtotime($today); $d += 86400) {
                $date = date('Y-m-d', $d);
                if (!isset($haveSet[$date])) $missingDates[$date] = true;
            }
        }
        // Strip today (the caller's main run handles it) and weekends
        // (terminal markets don't publish Sat/Sun).
        unset($missingDates[$today]);
        foreach (array_keys($missingDates) as $d) {
            $dow = (int) date('w', strtotime($d));
            if ($dow === 0 || $dow === 6) unset($missingDates[$d]);
        }

        $dates = array_keys($missingDates);
        sort($dates);
        $ran = [];
        foreach ($dates as $date) {
            $res = $this->ingest($date, $regions, false);
            $ran[] = ['as_of' => $date, 'rollups' => $res['rollups_written'] ?? 0, 'anomalies' => $res['anomalies'] ?? 0];
        }
        return $ran;
    }

    /**
     * Run one adapter slug end-to-end against the live API and return the
     * IngestBatch struct without persisting. CLI test mode for operators
     * debugging slug configs.
     */
    public function testSlug(string $adapterKey, string $slug, string $asOf): array
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->key() !== $adapterKey) continue;
            if (!$adapter->isEnabled())          return ['error' => "$adapterKey not enabled"];
            if (!method_exists($adapter, 'fetchBatchesForRegion')) {
                return ['error' => "$adapterKey doesn't support slug-level testing"];
            }
            // The AMS adapter's slug config is in $this->reports; we don't expose
            // it, so just probe every region and pick the slug we want.
            $batches = [];
            foreach ($adapter->regions() as $region) {
                foreach ($adapter->fetchBatchesForRegion($asOf, $region) as $b) {
                    if (str_contains((string) $b->endpoint, '/reports/' . $slug)) {
                        $batches[] = [
                            'ok'           => $b->ok,
                            'http_status'  => $b->httpStatus,
                            'latency_ms'   => $b->latencyMs,
                            'as_of'        => $b->asOf,
                            'source_ref'   => $b->sourceRef,
                            'endpoint'     => $b->endpoint,
                            'error'        => $b->errorMessage,
                            'notes'        => $b->notes,
                            'unmatched'    => $b->unmatched,
                            'sample_rows'  => array_slice(array_map(fn($r) => [
                                'ingredient_key' => $r->ingredientKey,
                                'cents_per_unit' => $r->marketPriceCents,
                                'unit'           => $r->unit,
                                'region'         => $r->region,
                                'as_of'          => $r->asOf,
                                'source_ref'     => $r->sourceRef,
                            ], $b->rows), 0, 8),
                            'total_rows'   => count($b->rows),
                        ];
                    }
                }
            }
            return ['adapter' => $adapterKey, 'slug' => $slug, 'as_of' => $asOf, 'batches' => $batches];
        }
        return ['error' => "unknown adapter $adapterKey"];
    }

    /**
     * Freshness summary for the UI footer. Returns one row per (source, region).
     * @param string|null $region restrict to this region (and national fallback)
     */
    public function freshness(?string $region = null): array
    {
        $sql = "SELECT source, region, MAX(fetched_at) AS last_ingested_at,
                       MAX(as_of)         AS as_of,
                       SUM(rows_inserted) AS row_count
                  FROM cogs_ingest_batches
                 WHERE ok = 1
                   AND fetched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $params = [];
        if ($region !== null) {
            $sql .= " AND (region = ? OR region = 'US' OR region IS NULL)";
            $params[] = $region;
        }
        $sql .= " GROUP BY source, region
                  ORDER BY last_ingested_at DESC";
        try {
            $rows = Database::getInstance()->fetchAll($sql, $params);
            return array_map(function ($r) {
                $r['rows'] = (int) ($r['row_count'] ?? 0);
                unset($r['row_count']);
                return $r;
            }, $rows);
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] freshness() failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Aggregate operator health: per-source batch ok rate, anomaly count,
     * unmatched-commodity top hits, stale-source flag, ingredient_keys
     * present in recipes but missing from cogs_benchmark.
     */
    public function healthSummary(): array
    {
        $db = Database::getInstance();
        $out = [
            'generated_at'    => date('c'),
            'overall_status'  => 'green',
            'sources'         => [],
            'recent_anomalies'=> [],
            'top_unmatched'   => [],
            'missing_recipe_keys' => [],
            'totals'          => [],
        ];
        try {
            $sources = $db->fetchAll(
                "SELECT source,
                        MAX(fetched_at) AS last_fetched,
                        SUM(ok)         AS ok_batches,
                        COUNT(*)        AS total_batches,
                        SUM(rows_inserted) AS rows_inserted
                   FROM cogs_ingest_batches
                  WHERE fetched_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                  GROUP BY source"
            );
            foreach ($sources as $s) {
                $hoursOld = $s['last_fetched']
                    ? (int) round((time() - strtotime((string) $s['last_fetched'])) / 3600)
                    : 9999;
                $errorRate = ((int) $s['total_batches'] > 0)
                    ? 1 - ((int) $s['ok_batches'] / (int) $s['total_batches'])
                    : 0;
                $status = 'green';
                if ($hoursOld > 36 || $errorRate > 0.5) $status = 'red';
                elseif ($hoursOld > 26 || $errorRate > 0.2) $status = 'yellow';
                if ($status === 'red') $out['overall_status'] = 'red';
                elseif ($status === 'yellow' && $out['overall_status'] === 'green') $out['overall_status'] = 'yellow';
                $out['sources'][] = [
                    'source'       => $s['source'],
                    'last_fetched' => $s['last_fetched'],
                    'hours_since'  => $hoursOld,
                    'ok_batches'   => (int) $s['ok_batches'],
                    'total_batches'=> (int) $s['total_batches'],
                    'rows_inserted'=> (int) $s['rows_inserted'],
                    'status'       => $status,
                ];
            }

            $out['recent_anomalies'] = $db->fetchAll(
                'SELECT ingredient_key, region, source, market_price_cents, unit, as_of, price_stddev_cents
                   FROM cogs_benchmark
                  WHERE is_anomaly = 1
                    AND as_of >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                  ORDER BY as_of DESC, ingredient_key
                  LIMIT 30'
            );

            $out['top_unmatched'] = $db->fetchAll(
                'SELECT adapter, commodity, variety, observation_count, last_seen_at
                   FROM cogs_unmatched_commodities
                  WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  ORDER BY observation_count DESC, last_seen_at DESC
                  LIMIT 25'
            );

            // Ingredient keys appearing in recipes but not in cogs_benchmark.
            // The `recipe_ingredients` table is in the PrivateData reservoir,
            // but this is operator admin scope so cross-reservoir read is OK.
            $out['missing_recipe_keys'] = $db->fetchAll(
                'SELECT DISTINCT ri.ingredient_key, COUNT(DISTINCT ri.recipe_id) AS recipes_using
                   FROM recipe_ingredients ri
                   LEFT JOIN cogs_benchmark cb ON cb.ingredient_key = ri.ingredient_key
                  WHERE cb.id IS NULL
                  GROUP BY ri.ingredient_key
                  ORDER BY recipes_using DESC
                  LIMIT 30'
            );

            $tot = $db->fetch(
                'SELECT COUNT(*) AS rows_total,
                        SUM(CASE WHEN source = "stub"  THEN 1 ELSE 0 END) AS stub_rows,
                        SUM(CASE WHEN source <> "stub" THEN 1 ELSE 0 END) AS live_rows,
                        SUM(CASE WHEN is_anomaly = 1 THEN 1 ELSE 0 END)  AS anomaly_rows
                   FROM cogs_benchmark'
            );
            $out['totals'] = [
                'rows_total'   => (int) ($tot['rows_total']   ?? 0),
                'stub_rows'    => (int) ($tot['stub_rows']    ?? 0),
                'live_rows'    => (int) ($tot['live_rows']    ?? 0),
                'anomaly_rows' => (int) ($tot['anomaly_rows'] ?? 0),
            ];
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] healthSummary failed: ' . $e->getMessage());
        }
        return $out;
    }

    /**
     * Drill into one price: returns the rollup row + batch provenance +
     * rolling stats + the last 14d of observations for trend context.
     */
    public function lookupTrace(string $ingredientKey, ?string $region = null): array
    {
        $db = Database::getInstance();
        $params = [$ingredientKey];
        $regionClause = '';
        if ($region !== null) {
            $regionClause = ' AND (region = ? OR region IS NULL)';
            $params[] = $region;
        }
        try {
            $current = $db->fetch(
                "SELECT cb.id, cb.ingredient_key, cb.region, cb.source, cb.market_price_cents,
                        cb.unit, cb.as_of, cb.observation_count, cb.price_stddev_cents,
                        cb.is_anomaly, cb.batch_id, cb.created_at
                   FROM cogs_benchmark cb
                  WHERE cb.ingredient_key = ?$regionClause
                  ORDER BY cb.as_of DESC, FIELD(cb.source, 'greendock','usa_produce','foundation_foods','usda','stub')
                  LIMIT 1",
                $params
            );

            $batch = null;
            if ($current && $current['batch_id']) {
                $batch = $db->fetch(
                    'SELECT id, adapter, source, region, endpoint, source_ref, as_of,
                            fetched_at, http_status, latency_ms, ok, rows_inserted,
                            error_message, notes_json
                       FROM cogs_ingest_batches WHERE id = ?',
                    [$current['batch_id']]
                );
            }

            $rolling = null;
            if ($current) {
                $rolling = $db->fetch(
                    'SELECT mean_7d_cents, mean_30d_cents, stddev_30d_cents,
                            min_30d_cents, max_30d_cents, obs_count_30d, as_of_max, updated_at
                       FROM cogs_benchmark_rolling
                      WHERE ingredient_key = ?
                        AND ((region IS NULL AND ? IS NULL) OR region = ?)
                        AND source = ?',
                    [$ingredientKey, $current['region'], $current['region'], $current['source']]
                );
            }

            $history = $db->fetchAll(
                "SELECT as_of, source, region, market_price_cents, unit,
                        observation_count, price_stddev_cents, is_anomaly
                   FROM cogs_benchmark
                  WHERE ingredient_key = ?$regionClause
                    AND as_of >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  ORDER BY as_of DESC, source",
                $params
            );

            return [
                'ingredient_key' => $ingredientKey,
                'region'         => $region,
                'current'        => $current,
                'batch'          => $batch,
                'rolling'        => $rolling,
                'history_30d'    => $history,
            ];
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] lookupTrace failed: ' . $e->getMessage());
            return ['ingredient_key' => $ingredientKey, 'region' => $region, 'error' => $e->getMessage()];
        }
    }

    /**
     * Recompute 7d/30d rolling stats for the given (key, region, source)
     * tuples. Idempotent.
     *
     * @param array<int, array{ingredient_key:string, region:?string, source:string}> $tuples
     */
    public function recomputeRollings(array $tuples): int
    {
        if (!$tuples) return 0;
        $db = Database::getInstance();
        $updated = 0;
        foreach ($tuples as $t) {
            try {
                $stats = $db->fetch(
                    'SELECT MAX(as_of)               AS as_of_max,
                            AVG(CASE WHEN as_of >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                                     THEN market_price_cents END)  AS mean_7d,
                            AVG(CASE WHEN as_of >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                     THEN market_price_cents END)  AS mean_30d,
                            STDDEV_POP(CASE WHEN as_of >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                            THEN market_price_cents END) AS stddev_30d,
                            MIN(CASE WHEN as_of >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                     THEN market_price_cents END)  AS min_30d,
                            MAX(CASE WHEN as_of >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                     THEN market_price_cents END)  AS max_30d,
                            SUM(CASE WHEN as_of >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                     THEN 1 ELSE 0 END)            AS n_30d
                       FROM cogs_benchmark
                      WHERE ingredient_key = ?
                        AND ((region IS NULL AND ? IS NULL) OR region = ?)
                        AND source = ?',
                    [$t['ingredient_key'], $t['region'], $t['region'], $t['source']]
                );
                if (!$stats || (int) ($stats['n_30d'] ?? 0) === 0) continue;

                $db->query(
                    'INSERT INTO cogs_benchmark_rolling
                        (id, ingredient_key, region, source, as_of_max,
                         mean_7d_cents, mean_30d_cents, stddev_30d_cents,
                         min_30d_cents, max_30d_cents, obs_count_30d, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE
                        as_of_max        = VALUES(as_of_max),
                        mean_7d_cents    = VALUES(mean_7d_cents),
                        mean_30d_cents   = VALUES(mean_30d_cents),
                        stddev_30d_cents = VALUES(stddev_30d_cents),
                        min_30d_cents    = VALUES(min_30d_cents),
                        max_30d_cents    = VALUES(max_30d_cents),
                        obs_count_30d    = VALUES(obs_count_30d),
                        updated_at       = NOW()',
                    [
                        Database::uuid(),
                        $t['ingredient_key'],
                        $t['region'],
                        $t['source'],
                        $stats['as_of_max'],
                        $stats['mean_7d']    !== null ? (int) round((float) $stats['mean_7d'])    : null,
                        $stats['mean_30d']   !== null ? (int) round((float) $stats['mean_30d'])   : null,
                        $stats['stddev_30d'] !== null ? (int) round((float) $stats['stddev_30d']) : null,
                        $stats['min_30d']    !== null ? (int) $stats['min_30d']    : null,
                        $stats['max_30d']    !== null ? (int) $stats['max_30d']    : null,
                        (int) $stats['n_30d'],
                    ]
                );
                $updated++;
            } catch (\Throwable $e) {
                error_log('[cogs-benchmark] recomputeRollings failed for ' . json_encode($t) . ': ' . $e->getMessage());
            }
        }
        return $updated;
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
        if ($advertised === []) return $requested;
        $intersection = array_values(array_intersect($requested, $advertised));
        return $intersection === [] ? [] : $intersection;
    }

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

    private function resolveRowSource(IngestBatch $batch, IngestRow $row): string
    {
        if (is_string($row->sourceRef) && str_starts_with($row->sourceRef, 'greendock_source=')) {
            return substr($row->sourceRef, strlen('greendock_source='));
        }
        return $batch->source;
    }

    /** @return array{median_cents:int, stddev_cents:?int, n:int, unit:string, batch:?string}|null */
    private function rollup(array $observations): ?array
    {
        if (!$observations) return null;
        // Pick the most-common unit; drop observations with a different unit.
        $unitCounts = [];
        foreach ($observations as $o) {
            $u = (string) ($o['unit'] ?? '');
            if ($u === '') continue;
            $unitCounts[$u] = ($unitCounts[$u] ?? 0) + 1;
        }
        if (!$unitCounts) return null;
        arsort($unitCounts);
        $winningUnit = array_key_first($unitCounts);

        $cents = [];
        $batches = [];
        foreach ($observations as $o) {
            if ((string) $o['unit'] !== $winningUnit) continue;
            $cents[] = (int) $o['cents'];
            if (!empty($o['batch'])) $batches[(string) $o['batch']] = true;
        }
        if (!$cents) return null;

        sort($cents);
        $n = count($cents);
        $median = $n % 2 === 0
            ? (int) round(($cents[$n / 2 - 1] + $cents[$n / 2]) / 2)
            : $cents[(int) floor($n / 2)];

        $stddev = null;
        if ($n > 1) {
            $mean = array_sum($cents) / $n;
            $sq   = 0.0;
            foreach ($cents as $c) $sq += ($c - $mean) ** 2;
            $stddev = (int) round(sqrt($sq / $n));
        }

        // batch_id on the rollup row points at one of the contributing batches
        // (whichever happens to be first). Audit trail can join via this; the
        // full set lives in notes_json on the batch.
        $primaryBatch = array_key_first($batches);

        return [
            'median_cents' => $median,
            'stddev_cents' => $stddev,
            'n'            => $n,
            'unit'         => $winningUnit,
            'batch'        => $primaryBatch !== null ? (string) $primaryBatch : null,
        ];
    }

    private function isAnomalous(string $key, ?string $region, string $source, string $asOf, int $todayMedianCents): bool
    {
        try {
            $row = Database::getInstance()->fetch(
                'SELECT AVG(market_price_cents) AS m, STDDEV_POP(market_price_cents) AS s, COUNT(*) AS n
                   FROM cogs_benchmark
                  WHERE ingredient_key = ?
                    AND ((region IS NULL AND ? IS NULL) OR region = ?)
                    AND source = ?
                    AND as_of >= DATE_SUB(?, INTERVAL 30 DAY)
                    AND as_of < ?',
                [$key, $region, $region, $source, $asOf, $asOf]
            );
            if (!$row || (int) $row['n'] < 5) return false; // not enough history
            $mean   = (float) $row['m'];
            $stddev = (float) $row['s'];
            if ($stddev <= 0) return false;
            $sigmas = abs($todayMedianCents - $mean) / $stddev;
            if ($sigmas >= self::ANOMALY_SIGMA) {
                error_log(sprintf(
                    '[cogs-benchmark] anomaly: %s/%s/%s on %s — median %dc vs prior mean %.1fc ± %.1fc (%.1fσ)',
                    $key, $region ?? 'national', $source, $asOf,
                    $todayMedianCents, $mean, $stddev, $sigmas
                ));
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] anomaly check failed: ' . $e->getMessage());
            return false;
        }
    }

    private function upsertRollupRow(
        string $ingredientKey, ?string $region, string $source, string $asOf,
        int $medianCents, ?int $stddevCents, int $observationCount, string $unit,
        ?string $batchId, bool $isAnomaly
    ): void {
        try {
            // INSERT ... ON DUPLICATE KEY UPDATE so a backfill re-run for the
            // same (key, region, source, as_of) replaces the prior median.
            Database::getInstance()->query(
                'INSERT INTO cogs_benchmark
                    (id, ingredient_key, region, market_price_cents, unit, source, as_of,
                     batch_id, observation_count, price_stddev_cents, is_anomaly, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    market_price_cents = VALUES(market_price_cents),
                    unit               = VALUES(unit),
                    batch_id           = VALUES(batch_id),
                    observation_count  = VALUES(observation_count),
                    price_stddev_cents = VALUES(price_stddev_cents),
                    is_anomaly         = VALUES(is_anomaly)',
                [
                    Database::uuid(),
                    $ingredientKey, $region, $medianCents, $unit, $source, $asOf,
                    $batchId, $observationCount, $stddevCents, $isAnomaly ? 1 : 0,
                ]
            );
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] rollup upsert failed: ' . $e->getMessage());
        }
    }

    private function persistUnmatched(string $adapterKey, ?string $sourceRef, array $unmatched): int
    {
        $db = Database::getInstance();
        $logged = 0;
        foreach ($unmatched as $u) {
            try {
                $db->query(
                    'INSERT INTO cogs_unmatched_commodities
                        (id, adapter, source_ref, commodity, variety, unit_hint, observation_count, first_seen_at, last_seen_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE
                        observation_count = observation_count + VALUES(observation_count),
                        last_seen_at      = NOW(),
                        source_ref        = COALESCE(source_ref, VALUES(source_ref)),
                        unit_hint         = COALESCE(unit_hint, VALUES(unit_hint))',
                    [
                        Database::uuid(),
                        $adapterKey,
                        $sourceRef !== null ? substr($sourceRef, 0, 255) : null,
                        substr((string) $u['commodity'], 0, 160),
                        substr((string) ($u['variety'] ?? ''), 0, 160),
                        isset($u['unit_hint']) ? substr((string) $u['unit_hint'], 0, 60) : null,
                        (int) ($u['count'] ?? 1),
                    ]
                );
                $logged++;
            } catch (\Throwable $e) {
                error_log('[cogs-benchmark] unmatched persist failed: ' . $e->getMessage());
            }
        }
        return $logged;
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
