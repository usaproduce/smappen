<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\PrivateData\MenuItemRepository;
use App\PrivateData\RecipeRepository;
use App\PrivateData\RestaurantRepository;
use App\Services\PlateCostService;
use App\PrivateData\PlateCostRepository;
use App\SharedRef\CogsBenchmarkRepository;

/**
 * Menu items + recipes + plate-cost recompute trigger.
 *
 * Routes (auth):
 *   GET   /api/restaurants/{id}/menu
 *   POST  /api/restaurants/{id}/menu                  — manual create
 *   PUT   /api/menu-items/{id}/price                  — operator price edit
 *   PUT   /api/menu-items/{id}/recipe                 — link to recipe
 *   POST  /api/restaurants/{id}/recipes               — create recipe (returns id)
 *   POST  /api/recipes/{id}/ingredients               — add ingredient
 *   POST  /api/restaurants/{id}/plate-costs/recompute — recompute every item
 */
class MenuController
{
    private MenuItemRepository $items;
    private RecipeRepository $recipes;
    private RestaurantRepository $restaurants;

    public function __construct(
        ?MenuItemRepository $items = null,
        ?RecipeRepository $recipes = null,
        ?RestaurantRepository $restaurants = null,
    ) {
        $this->items = $items ?? new MenuItemRepository();
        $this->recipes = $recipes ?? new RecipeRepository();
        $this->restaurants = $restaurants ?? new RestaurantRepository();
    }

    public function listMenu(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $rows = $this->items->listByRestaurant($r['id']);
        // Decorate each row with margin so the frontend doesn't repeat the math.
        foreach ($rows as &$row) {
            $cost = $row['true_cost_cents'] ?? null;
            $price = (int) ($row['price_cents'] ?? 0);
            $row['margin_cents']  = ($cost !== null && $price > 0) ? ($price - (int) $cost) : null;
            $row['margin_pct']    = ($cost !== null && $price > 0) ? round((($price - (int) $cost) / $price), 3) : null;
        }
        Response::success(['items' => $rows]);
    }

    public function createMenuItem(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $b = $request->getBody() ?? [];
        $name = trim((string) ($b['name'] ?? ''));
        $price = (int) ($b['price_cents'] ?? 0);
        if ($name === '' || $price <= 0) Response::error('name and price_cents required', 422);
        $id = $this->items->createManual($request->user['organization_id'], $r['id'], [
            'name'        => $name,
            'category'    => isset($b['category']) ? (string) $b['category'] : null,
            'price_cents' => $price,
            'recipe_id'   => isset($b['recipe_id']) ? (string) $b['recipe_id'] : null,
        ]);
        Response::success(['id' => $id], 'Menu item created', 201);
    }

    public function setPrice(Request $request): void
    {
        $itemId = (string) $request->getParam('id');
        $item = $this->items->findById($itemId, $request->user['organization_id']);
        if (!$item) Response::error('Menu item not found', 404);
        $b = $request->getBody() ?? [];
        $price = (int) ($b['price_cents'] ?? 0);
        if ($price <= 0) Response::error('price_cents must be > 0', 422);
        $this->items->setPrice($itemId, $request->user['organization_id'], $price);
        Response::success(['id' => $itemId, 'price_cents' => $price]);
    }

    public function setRecipe(Request $request): void
    {
        $itemId = (string) $request->getParam('id');
        $item = $this->items->findById($itemId, $request->user['organization_id']);
        if (!$item) Response::error('Menu item not found', 404);
        $b = $request->getBody() ?? [];
        $recipeId = isset($b['recipe_id']) ? (string) $b['recipe_id'] : null;
        $this->items->setRecipe($itemId, $request->user['organization_id'], $recipeId);
        Response::success(['id' => $itemId, 'recipe_id' => $recipeId]);
    }

    public function createRecipe(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $b = $request->getBody() ?? [];
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '') Response::error('name required', 422);
        $id = $this->recipes->create($request->user['organization_id'], $r['id'], $name, $b['notes'] ?? null);
        Response::success(['id' => $id], 'Recipe created', 201);
    }

    public function listRecipes(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        Response::success(['recipes' => $this->recipes->listByRestaurant($r['id'])]);
    }

    public function showRecipe(Request $request): void
    {
        $id = (string) $request->getParam('id');
        $recipe = $this->recipes->findById($id, $request->user['organization_id']);
        if (!$recipe) Response::error('Recipe not found', 404);
        $recipe['ingredients'] = $this->recipes->ingredientsFor($id);
        Response::success(['recipe' => $recipe]);
    }

    public function removeIngredient(Request $request): void
    {
        $ingredientId = (string) $request->getParam('id');
        $ok = $this->recipes->removeIngredient($ingredientId, $request->user['organization_id']);
        if (!$ok) Response::error('Ingredient not found', 404);
        Response::success([], 'Removed');
    }

    public function listIngredientCatalog(Request $request): void
    {
        // The catalog is org-agnostic shared reference data — but require
        // auth to access so we don't broadcast our COGS-stub contents.
        $region = $request->getQuery('region');
        $catalog = (new \App\SharedRef\CogsBenchmarkRepository())
            ->listAvailableIngredients($region ? (string) $region : null);
        Response::success(['ingredients' => $catalog]);
    }

    public function addIngredient(Request $request): void
    {
        // Ingredient additions don't need explicit ownership check past the
        // recipe FK — but we verify the recipe lives in the caller's org.
        $recipeId = (string) $request->getParam('id');
        $row = Database::getInstance()->fetch(
            'SELECT organization_id FROM recipes WHERE id = ?',
            [$recipeId]
        );
        if (!$row || $row['organization_id'] !== $request->user['organization_id']) {
            Response::error('Recipe not found', 404);
        }
        $b = $request->getBody() ?? [];
        $key = trim((string) ($b['ingredient_key'] ?? ''));
        $qty = (float) ($b['qty'] ?? 0);
        $unit = trim((string) ($b['unit'] ?? ''));
        if ($key === '' || $qty <= 0 || $unit === '') {
            Response::error('ingredient_key, qty > 0, unit required', 422);
        }
        $id = $this->recipes->addIngredient($recipeId, $key, $qty, $unit, $b['notes'] ?? null);
        Response::success(['id' => $id], 'Ingredient added', 201);
    }

    /**
     * Overpay flags — items whose computed plate cost is higher than the
     * recipe's benchmark would predict by ≥10% (suggests the operator is
     * buying ingredients above market). Phase 1 surfacing for spec §5.4.
     */
    public function overpayFlags(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $rows = Database::getInstance()->fetchAll(
            'SELECT mi.id AS menu_item_id, mi.name,
                    pc.true_cost_cents, pc.coverage_pct, pc.missing_ingredients
               FROM menu_items mi
               LEFT JOIN plate_costs pc ON pc.menu_item_id = mi.id
              WHERE mi.restaurant_id = ?
                AND mi.is_active = 1
                AND pc.true_cost_cents IS NOT NULL
              ORDER BY pc.true_cost_cents DESC',
            [$r['id']]
        );
        foreach ($rows as &$row) {
            $row['missing_ingredients'] = $row['missing_ingredients']
                ? json_decode($row['missing_ingredients'], true)
                : [];
        }
        Response::success(['flags' => $rows, 'note' => 'Phase 1: surfaces items with computed cost. Full overpay-vs-invoice comparison ships when restaurant invoice ingestion lands.']);
    }

    public function recomputePlateCosts(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $svc = new PlateCostService(
            $this->items,
            $this->recipes,
            new PlateCostRepository(),
            new CogsBenchmarkRepository(),
        );
        $count = $svc->computeForRestaurant($r['id'], $request->user['organization_id'], $r['region'] ?? null);
        Response::success(['recomputed' => $count]);
    }

    private function verifyOwnedRestaurant(Request $request): array
    {
        $id = (string) $request->getParam('id');
        $r = $this->restaurants->findById($id, $request->user['organization_id']);
        if (!$r) Response::error('Restaurant not found', 404);
        return $r;
    }
}
