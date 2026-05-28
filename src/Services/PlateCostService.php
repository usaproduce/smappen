<?php
declare(strict_types=1);

namespace App\Services;

use App\PrivateData\MenuItemRepository;
use App\PrivateData\RecipeRepository;
use App\PrivateData\PlateCostRepository;
use App\SharedRef\CogsBenchmarkRepository;

/**
 * Compute true plate cost = Σ (recipe_ingredient.qty × cogs_benchmark.market_price_cents),
 * normalized to a per-unit basis (cents per oz / lb / each / cup / tbsp).
 *
 * Conversion is intentionally small:
 *   - Same family (weight↔weight, volume↔volume): direct factor.
 *   - Count→weight: ingredient-specific default (lemon = 4 oz, lime = 2.5
 *     oz, egg = 1.75 oz). See INGREDIENT_COUNT_WEIGHT_OZ.
 *   - Cross-family without a default: skipped + logged, recorded as a
 *     missing_ingredient so the UI's coverage_pct tells the operator.
 *
 * Uses CogsBenchmarkRepository::bulkLookup for the restaurant-wide
 * recompute so a 60-item menu × 5-ingredient recipe doesn't issue 300
 * separate SELECTs.
 */
class PlateCostService
{
    private const WEIGHT_TO_OZ  = ['oz' => 1.0, 'lb' => 16.0, 'g' => 0.035274, 'kg' => 35.274];
    private const VOLUME_TO_TBSP = ['tbsp' => 1.0, 'tsp' => 1.0 / 3.0, 'cup' => 16.0, 'ml' => 0.067628, 'l' => 67.628];

    /**
     * Default weight in ounces per "each" for ingredients commonly
     * specified by count in recipes but priced by weight upstream. USDA
     * Handbook + foundation-foods averages. Best-effort proxies, not
     * exact — recipe builders can over-ride at the recipe level.
     */
    private const INGREDIENT_COUNT_WEIGHT_OZ = [
        'lemon'          => 4.0,
        'lime'           => 2.5,
        'avocado'        => 6.0,
        'egg_large'      => 1.75,
        'egg'            => 1.75,
        'onion_yellow'   => 5.5,   // small/med onion
        'tomato_roma'    => 2.2,
        'tomato_cherry'  => 0.25,
        'garlic_clove'   => 0.18,
        'potato_russet'  => 6.0,
        'pepper_bell'    => 5.0,
    ];

    public function __construct(
        private MenuItemRepository $items,
        private RecipeRepository $recipes,
        private PlateCostRepository $plateCosts,
        private CogsBenchmarkRepository $benchmark,
    ) {}

    public function computeForMenuItem(string $menuItemId, string $organizationId, ?string $region = null): ?array
    {
        $item = $this->items->findById($menuItemId, $organizationId);
        if (!$item || empty($item['recipe_id'])) return null;

        $ingredients = $this->recipes->ingredientsFor($item['recipe_id']);
        if (!$ingredients) {
            $this->plateCosts->upsert($organizationId, $menuItemId, 0, 0, ['__empty_recipe__']);
            return ['true_cost_cents' => 0, 'coverage_pct' => 0, 'missing_ingredients' => ['__empty_recipe__']];
        }

        // Bulk-fetch every benchmark needed for this recipe in one SELECT.
        $keys = array_map(fn($ing) => (string) $ing['ingredient_key'], $ingredients);
        $benchmarks = $this->benchmark->bulkLookup($keys, $region);

        return $this->computeFromBenchmarks($menuItemId, $organizationId, $ingredients, $benchmarks);
    }

    /**
     * Recompute for every active menu item with a linked recipe. Issues
     * one bulkLookup per pass across the union of recipe ingredients.
     */
    public function computeForRestaurant(string $restaurantId, string $organizationId, ?string $region = null): int
    {
        $items = $this->items->listWithRecipes($restaurantId);
        if (!$items) return 0;

        $allIngredients = [];
        $perItem = [];
        $allKeys = [];
        foreach ($items as $row) {
            $rid = (string) $row['id'];
            $ings = $this->recipes->ingredientsFor((string) $row['recipe_id']);
            $perItem[$rid] = $ings;
            foreach ($ings as $i) $allKeys[(string) $i['ingredient_key']] = true;
        }

        $benchmarks = $this->benchmark->bulkLookup(array_keys($allKeys), $region);

        $count = 0;
        foreach ($items as $row) {
            $rid = (string) $row['id'];
            $r = $this->computeFromBenchmarks($rid, $organizationId, $perItem[$rid], $benchmarks);
            if ($r !== null) $count++;
        }
        return $count;
    }

    private function computeFromBenchmarks(string $menuItemId, string $organizationId, array $ingredients, array $benchmarks): ?array
    {
        if (!$ingredients) return null;
        $totalCents = 0.0;
        $covered = 0;
        $missing = [];
        foreach ($ingredients as $ing) {
            $key = (string) $ing['ingredient_key'];
            $row = $benchmarks[$key] ?? null;
            if (!$row) { $missing[] = $key; continue; }
            $perUnit = (float) $row['market_price_cents'];
            $factor  = $this->convertFactor((string) $ing['unit'], (string) $row['unit'], $key);
            if ($factor === null) {
                error_log('[plate-cost] unit mismatch for ' . $key
                    . ' (recipe=' . $ing['unit'] . ', benchmark=' . $row['unit'] . ')');
                $missing[] = $key;
                continue;
            }
            $totalCents += (float) $ing['qty'] * $perUnit * $factor;
            $covered++;
        }

        $coverage = (int) round(($covered / count($ingredients)) * 100);
        $totalCents = (int) round($totalCents);
        $this->plateCosts->upsert($organizationId, $menuItemId, $totalCents, $coverage, $missing);

        return [
            'true_cost_cents'     => $totalCents,
            'coverage_pct'        => $coverage,
            'missing_ingredients' => $missing,
        ];
    }

    /**
     * Conversion factor from $from unit to $to unit. Returns null when
     * cross-family and no count→weight default exists for the ingredient.
     *
     * Cross-family count→weight: when from='each' and the benchmark is in
     * a weight unit, multiply through the ingredient-specific
     * INGREDIENT_COUNT_WEIGHT_OZ entry (then chain via WEIGHT_TO_OZ).
     */
    private function convertFactor(string $from, string $to, string $ingredientKey = ''): ?float
    {
        $from = strtolower($from);
        $to   = strtolower($to);
        if ($from === $to || ($from === 'each' && $to === 'each')) return 1.0;

        if (isset(self::WEIGHT_TO_OZ[$from]) && isset(self::WEIGHT_TO_OZ[$to])) {
            return self::WEIGHT_TO_OZ[$from] / self::WEIGHT_TO_OZ[$to];
        }
        if (isset(self::VOLUME_TO_TBSP[$from]) && isset(self::VOLUME_TO_TBSP[$to])) {
            return self::VOLUME_TO_TBSP[$from] / self::VOLUME_TO_TBSP[$to];
        }

        // Cross-family count → weight (e.g. recipe says "2 lemons", benchmark
        // is $/lb). Need per-each ounce weight for this ingredient.
        if ($from === 'each' && isset(self::WEIGHT_TO_OZ[$to])) {
            $ozPerEach = self::INGREDIENT_COUNT_WEIGHT_OZ[$ingredientKey] ?? null;
            if ($ozPerEach !== null) {
                // factor = oz/each × (1 oz expressed in $to-unit space)
                return $ozPerEach / self::WEIGHT_TO_OZ[$to];
            }
        }
        // Cross-family weight → count (e.g. recipe in "oz", benchmark in "each").
        if (isset(self::WEIGHT_TO_OZ[$from]) && $to === 'each') {
            $ozPerEach = self::INGREDIENT_COUNT_WEIGHT_OZ[$ingredientKey] ?? null;
            if ($ozPerEach !== null && $ozPerEach > 0) {
                return self::WEIGHT_TO_OZ[$from] / $ozPerEach;
            }
        }
        return null;
    }
}
