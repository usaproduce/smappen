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
 * Unit normalization is deliberately small in Phase 1:
 *   - benchmark prices are stored per unit (e.g., $4.20/lb tomato)
 *   - recipe_ingredients.unit is the unit the operator used in the recipe
 *   - if units match, multiply qty × price
 *   - if they don't, convert through the unit table below
 *
 * Coverage_pct reports what fraction of the recipe's ingredients had a
 * benchmark hit. UI uses this to show "based on 3 of 5 ingredients —
 * add the rest to see full plate cost".
 */
class PlateCostService
{
    /** Conversion factors to a canonical base per unit family. */
    private const WEIGHT_TO_OZ  = ['oz' => 1.0, 'lb' => 16.0, 'g' => 0.035274, 'kg' => 35.274];
    private const VOLUME_TO_TBSP = ['tbsp' => 1.0, 'tsp' => 1.0 / 3.0, 'cup' => 16.0, 'ml' => 0.067628, 'l' => 67.628];

    public function __construct(
        private MenuItemRepository $items,
        private RecipeRepository $recipes,
        private PlateCostRepository $plateCosts,
        private CogsBenchmarkRepository $benchmark,
    ) {}

    /**
     * Recompute plate cost for one menu item. Returns the computed row,
     * or null if the item has no recipe linked.
     */
    public function computeForMenuItem(string $menuItemId, string $organizationId, ?string $region = null): ?array
    {
        $item = $this->items->findById($menuItemId, $organizationId);
        if (!$item || empty($item['recipe_id'])) return null;

        $ingredients = $this->recipes->ingredientsFor($item['recipe_id']);
        if (!$ingredients) {
            $this->plateCosts->upsert($organizationId, $menuItemId, 0, 0, ['__empty_recipe__']);
            return ['true_cost_cents' => 0, 'coverage_pct' => 0, 'missing_ingredients' => ['__empty_recipe__']];
        }

        $totalCents = 0.0;
        $covered = 0;
        $missing = [];
        foreach ($ingredients as $ing) {
            $row = $this->benchmark->lookup((string) $ing['ingredient_key'], $region);
            if (!$row) {
                $missing[] = $ing['ingredient_key'];
                continue;
            }
            $perUnit = (float) $row['market_price_cents'];
            $factor  = $this->convertFactor((string) $ing['unit'], (string) $row['unit']);
            if ($factor === null) {
                // Units in different families (e.g. weight vs volume) — skip
                // rather than guess. Log so the operator can fix the recipe.
                error_log('[plate-cost] unit mismatch for ' . $ing['ingredient_key']
                    . ' (recipe=' . $ing['unit'] . ', benchmark=' . $row['unit'] . ')');
                $missing[] = $ing['ingredient_key'];
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

    /** Recompute for every active menu item with a linked recipe. */
    public function computeForRestaurant(string $restaurantId, string $organizationId, ?string $region = null): int
    {
        $count = 0;
        foreach ($this->items->listWithRecipes($restaurantId) as $row) {
            if ($this->computeForMenuItem($row['id'], $organizationId, $region) !== null) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Conversion factor: how many `to` units make up one `from` unit?
     * Returns null if the units aren't comparable (cross-family).
     */
    private function convertFactor(string $from, string $to): ?float
    {
        $from = strtolower($from);
        $to   = strtolower($to);
        if ($from === $to || $from === 'each' && $to === 'each') return 1.0;

        if (isset(self::WEIGHT_TO_OZ[$from]) && isset(self::WEIGHT_TO_OZ[$to])) {
            return self::WEIGHT_TO_OZ[$from] / self::WEIGHT_TO_OZ[$to];
        }
        if (isset(self::VOLUME_TO_TBSP[$from]) && isset(self::VOLUME_TO_TBSP[$to])) {
            return self::VOLUME_TO_TBSP[$from] / self::VOLUME_TO_TBSP[$to];
        }
        return null;
    }
}
