<?php
declare(strict_types=1);

namespace App\SharedRef;

use App\Core\Database;

/**
 * Read-side accessor for `cogs_benchmark`.
 *
 * The benchmark is the unfair advantage: USDA + GreenDock-published market
 * prices that no analytics competitor can replicate because they don't own
 * a distributor. PlateCostService walks each recipe ingredient through
 * `lookup()` to get the true wholesale price.
 *
 * Lookup order (within an ingredient_key):
 *   1. Most recent regional row for the caller's region (if provided).
 *   2. Most recent national row (region IS NULL).
 *   3. Source preference: greendock > usa_produce > foundation_foods > usda > stub.
 *
 * Returns null when nothing is on file — callers must handle this (the
 * spec's graceful degradation rule: a missing benchmark must not crash
 * the engine, it just falls back to whatever the restaurant's invoice
 * cost says).
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

    public function lookup(string $ingredientKey, ?string $region = null): ?array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT id, ingredient_key, region, market_price_cents, unit, source, as_of
               FROM cogs_benchmark
              WHERE ingredient_key = ?
                AND (region = ? OR region IS NULL)
              ORDER BY as_of DESC
              LIMIT 20',
            [$ingredientKey, $region]
        );
        if (!$rows) return null;

        usort($rows, function ($a, $b) use ($region) {
            // Prefer regional match over national fallback.
            $aRegional = ($region !== null && $a['region'] === $region) ? 1 : 0;
            $bRegional = ($region !== null && $b['region'] === $region) ? 1 : 0;
            if ($aRegional !== $bRegional) return $bRegional - $aRegional;
            // Then prefer fresher data.
            if ($a['as_of'] !== $b['as_of']) return strcmp((string) $b['as_of'], (string) $a['as_of']);
            // Then prefer higher-quality source.
            $aPref = self::SOURCE_PREFERENCE[$a['source']] ?? 0;
            $bPref = self::SOURCE_PREFERENCE[$b['source']] ?? 0;
            return $bPref - $aPref;
        });

        return $rows[0];
    }

    /**
     * Bulk-lookup variant for the menu-engineering hot path.
     * Returns map: ingredient_key => best row (or omitted if no match).
     */
    public function lookupMany(array $ingredientKeys, ?string $region = null): array
    {
        $out = [];
        foreach (array_unique($ingredientKeys) as $k) {
            $row = $this->lookup((string) $k, $region);
            if ($row !== null) $out[(string) $k] = $row;
        }
        return $out;
    }

    /** True if any rows exist at all — used for the "feed live yet?" health check. */
    public function hasAnyData(): bool
    {
        $row = Database::getInstance()->fetch('SELECT 1 AS one FROM cogs_benchmark LIMIT 1');
        return $row !== null;
    }

    /**
     * List every known ingredient_key with its current best price. Used by
     * the recipe-builder UI to autocomplete and show "the market rate"
     * inline so operators know what they're committing to.
     */
    public function listAvailableIngredients(?string $region = null): array
    {
        $rows = Database::getInstance()->fetchAll(
            'SELECT ingredient_key, region, market_price_cents, unit, source, as_of
               FROM cogs_benchmark
              ORDER BY ingredient_key, as_of DESC'
        );
        $byKey = [];
        foreach ($rows as $r) {
            // Take the first row per key (sorted by as_of DESC), preferring
            // the regional one if a region is passed.
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
}
