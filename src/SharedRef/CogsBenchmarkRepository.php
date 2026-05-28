<?php
declare(strict_types=1);

namespace App\SharedRef;

use App\Core\Database;

/**
 * Read-side accessor for `cogs_benchmark` + `cogs_benchmark_rolling`.
 *
 * Lookup order within an ingredient_key:
 *   1. Regional match for the caller's region (if provided).
 *   2. National fallback (region IS NULL).
 *   3. Source preference: greendock > usa_produce > foundation_foods > usda > stub.
 *   4. Then most-recent as_of.
 *
 * Aggregation modes (v2, mig 039):
 *   - 'current'  : return the latest rollup row (default; backward compatible).
 *   - 'mean_30d' : return the row but with market_price_cents swapped for
 *                  the rolling 30-day mean (when ≥5 obs in last 30d).
 *   - 'compare'  : returns ['current'=>row, 'rolling'=>row] so the caller
 *                  can show "today $1.80 vs 30d avg $1.62 (+11%)".
 *
 * Returns null when nothing is on file — callers must handle this.
 */
class CogsBenchmarkRepository
{
    private const SOURCE_PREFERENCE = [
        'greendock'        => 5,
        'usa_produce'      => 4,
        'foundation_foods' => 3,
        'usda'             => 2,
        'stub'             => 1,
    ];

    /**
     * Single-key lookup. $aggregation ∈ 'current' | 'mean_30d' | 'compare'.
     * When 'compare', returns a different shape — see class docblock.
     */
    public function lookup(string $ingredientKey, ?string $region = null, string $aggregation = 'current'): ?array
    {
        $row = $this->pickBestRow($ingredientKey, $region);
        if (!$row) return null;
        if ($aggregation === 'current') return $row;

        $rolling = $this->fetchRolling($ingredientKey, $row['region'], $row['source']);

        if ($aggregation === 'compare') {
            return ['current' => $row, 'rolling' => $rolling];
        }
        // 'mean_30d' — substitute the price with the rolling mean.
        if ($rolling && $rolling['mean_30d_cents'] !== null && (int) $rolling['obs_count_30d'] >= 5) {
            $row['market_price_cents'] = (int) $rolling['mean_30d_cents'];
            $row['price_aggregation']  = 'mean_30d';
            $row['rolling']            = $rolling;
        } else {
            $row['price_aggregation'] = 'current_fallback';
            $row['rolling']           = $rolling;
        }
        return $row;
    }

    /**
     * One SELECT for many ingredient_keys. Returns map: key => best row.
     * Use this from PlateCostService's hot path instead of N×lookup().
     *
     * @param string[] $ingredientKeys
     */
    public function bulkLookup(array $ingredientKeys, ?string $region = null, string $aggregation = 'current'): array
    {
        $unique = array_values(array_unique(array_filter(array_map('strval', $ingredientKeys))));
        if (!$unique) return [];

        $placeholders = implode(',', array_fill(0, count($unique), '?'));
        $sql = "SELECT id, ingredient_key, region, market_price_cents, unit, source, as_of,
                       observation_count, price_stddev_cents, is_anomaly, batch_id
                  FROM cogs_benchmark
                 WHERE ingredient_key IN ($placeholders)
                   AND (region = ? OR region IS NULL)
                 ORDER BY ingredient_key, as_of DESC";
        $params = array_merge($unique, [$region]);

        try {
            $rows = Database::getInstance()->fetchAll($sql, $params);
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] bulkLookup failed: ' . $e->getMessage());
            return [];
        }

        $byKey = [];
        foreach ($rows as $r) {
            $k = (string) $r['ingredient_key'];
            $byKey[$k][] = $r;
        }
        $out = [];
        foreach ($byKey as $k => $candidates) {
            $best = $this->pickFromCandidates($candidates, $region);
            if ($best) $out[$k] = $best;
        }
        if ($aggregation !== 'current' && $out) {
            // Bulk-fetch rolling stats for every (key, region, source) tuple
            // we just chose, in one query.
            $tuples = [];
            foreach ($out as $r) {
                $tuples[] = [(string) $r['ingredient_key'], $r['region'], (string) $r['source']];
            }
            $rollMap = $this->bulkFetchRolling($tuples);
            foreach ($out as $k => $r) {
                $rollKey = $r['ingredient_key'] . '|' . ($r['region'] ?? '__null__') . '|' . $r['source'];
                $rolling = $rollMap[$rollKey] ?? null;
                if ($aggregation === 'compare') {
                    $out[$k] = ['current' => $r, 'rolling' => $rolling];
                } else { // mean_30d
                    if ($rolling && $rolling['mean_30d_cents'] !== null && (int) $rolling['obs_count_30d'] >= 5) {
                        $r['market_price_cents'] = (int) $rolling['mean_30d_cents'];
                        $r['price_aggregation']  = 'mean_30d';
                    } else {
                        $r['price_aggregation'] = 'current_fallback';
                    }
                    $r['rolling'] = $rolling;
                    $out[$k] = $r;
                }
            }
        }
        return $out;
    }

    /** Legacy alias kept for callers that still iterate one-by-one. */
    public function lookupMany(array $ingredientKeys, ?string $region = null): array
    {
        return $this->bulkLookup($ingredientKeys, $region, 'current');
    }

    public function hasAnyData(): bool
    {
        $row = Database::getInstance()->fetch('SELECT 1 AS one FROM cogs_benchmark LIMIT 1');
        return $row !== null;
    }

    /**
     * List every known ingredient_key with its current best price. Used by
     * the recipe-builder UI to autocomplete with inline market rates.
     */
    public function listAvailableIngredients(?string $region = null): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT ingredient_key, region, market_price_cents, unit, source, as_of,
                    observation_count, is_anomaly
               FROM cogs_benchmark
              ORDER BY ingredient_key, as_of DESC'
        );
        $byKey = [];
        foreach ($rows as $r) {
            $key = (string) $r['ingredient_key'];
            $isPreferred = $region !== null && $r['region'] === $region;
            if (!isset($byKey[$key]) || ($isPreferred && !($byKey[$key]['_pref'] ?? false))) {
                $byKey[$key] = $r + ['_pref' => $isPreferred];
            }
        }
        $out = array_values($byKey);
        foreach ($out as &$x) unset($x['_pref']);
        usort($out, fn($a, $b) => strcmp($a['ingredient_key'], $b['ingredient_key']));
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────

    private function pickBestRow(string $ingredientKey, ?string $region): ?array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, ingredient_key, region, market_price_cents, unit, source, as_of,
                    observation_count, price_stddev_cents, is_anomaly, batch_id
               FROM cogs_benchmark
              WHERE ingredient_key = ?
                AND (region = ? OR region IS NULL)
              ORDER BY as_of DESC
              LIMIT 30',
            [$ingredientKey, $region]
        );
        if (!$rows) return null;
        return $this->pickFromCandidates($rows, $region);
    }

    private function pickFromCandidates(array $candidates, ?string $region): ?array
    {
        if (!$candidates) return null;
        usort($candidates, function ($a, $b) use ($region) {
            $aRegional = ($region !== null && $a['region'] === $region) ? 1 : 0;
            $bRegional = ($region !== null && $b['region'] === $region) ? 1 : 0;
            if ($aRegional !== $bRegional) return $bRegional - $aRegional;
            if ($a['as_of'] !== $b['as_of']) return strcmp((string) $b['as_of'], (string) $a['as_of']);
            $aPref = self::SOURCE_PREFERENCE[$a['source']] ?? 0;
            $bPref = self::SOURCE_PREFERENCE[$b['source']] ?? 0;
            return $bPref - $aPref;
        });
        return $candidates[0];
    }

    private function fetchRolling(string $key, ?string $region, string $source): ?array
    {
        try {
            return Database::getInstance()->fetch(
                'SELECT mean_7d_cents, mean_30d_cents, stddev_30d_cents,
                        min_30d_cents, max_30d_cents, obs_count_30d, as_of_max, updated_at
                   FROM cogs_benchmark_rolling
                  WHERE ingredient_key = ?
                    AND ((region IS NULL AND ? IS NULL) OR region = ?)
                    AND source = ?',
                [$key, $region, $region, $source]
            );
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] fetchRolling failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @param array<int, array{0:string,1:?string,2:string}> $tuples (key, region, source)
     * @return array<string, array> map "key|region_or___null__|source" => rolling row
     */
    private function bulkFetchRolling(array $tuples): array
    {
        if (!$tuples) return [];
        // OR'd predicate; safe because tuples size is bounded by recipe size.
        $ors = [];
        $params = [];
        foreach ($tuples as [$k, $region, $src]) {
            $ors[] = '(ingredient_key = ? AND ((region IS NULL AND ? IS NULL) OR region = ?) AND source = ?)';
            $params[] = $k; $params[] = $region; $params[] = $region; $params[] = $src;
        }
        try {
            $rows = Database::getInstance()->fetchAll(
                'SELECT ingredient_key, region, source, mean_7d_cents, mean_30d_cents,
                        stddev_30d_cents, min_30d_cents, max_30d_cents, obs_count_30d,
                        as_of_max, updated_at
                   FROM cogs_benchmark_rolling
                  WHERE ' . implode(' OR ', $ors),
                $params
            );
        } catch (\Throwable $e) {
            error_log('[cogs-benchmark] bulkFetchRolling failed: ' . $e->getMessage());
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $k = $r['ingredient_key'] . '|' . ($r['region'] ?? '__null__') . '|' . $r['source'];
            $out[$k] = $r;
        }
        return $out;
    }
}
