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
use App\Services\RecipePasteParser;
use App\Services\RecipeSeedMatcher;
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
 *   POST  /api/restaurants/{id}/recipes/paste/preview — preview TSV paste
 *   POST  /api/restaurants/{id}/recipes/paste/commit  — commit reviewed TSV
 *   POST  /api/restaurants/{id}/recipes/suggest       — seed-dictionary draft
 *   GET   /api/restaurants/{id}/ingredient-autocomplete?q=…
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

    /**
     * Preview a TSV paste. Pure parse — does NOT touch the DB. The frontend
     * shows this preview to the operator; once they hit "commit" we re-run
     * the parse server-side under a single transaction (so a sneaky paste
     * substitution between preview and commit can't slip past validation).
     *
     * Body: { "text": "item\tingredient\tqty\tunit\n…" }
     */
    public function previewPaste(Request $request): void
    {
        $this->verifyOwnedRestaurant($request);
        $b = $request->getBody() ?? [];
        $text = (string) ($b['text'] ?? '');
        if (trim($text) === '') Response::error('text is required', 422);
        $parser = new RecipePasteParser();
        Response::success($parser->parse($text));
    }

    /**
     * Commit a previously-previewed TSV paste. Re-parses server-side and
     * creates recipes + ingredients in a single transaction; if any group
     * has zero usable rows (all errors) it is skipped, not aborted. Returns
     * the list of created recipes so the frontend can navigate to one.
     *
     * Also auto-links each created recipe to a menu item with the same
     * (case-insensitive) name when one exists and isn't already recipe'd.
     *
     * Body: { "text": "…", "include_warnings": bool? (default true) }
     */
    public function commitPaste(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $b = $request->getBody() ?? [];
        $text = (string) ($b['text'] ?? '');
        $includeWarnings = !isset($b['include_warnings']) || (bool) $b['include_warnings'];
        if (trim($text) === '') Response::error('text is required', 422);

        $parsed = (new RecipePasteParser())->parse($text);
        $groups = $parsed['groups'];
        if (empty($groups)) Response::error('No usable rows in paste', 422);

        $db = Database::getInstance();
        $orgId = $request->user['organization_id'];
        $restaurantId = $r['id'];

        $created = [];
        $linkedCount = 0;
        $skipped = [];

        // Pre-pull menu items so we can auto-link by name without N queries.
        $menuItems = $db->fetchAll(
            'SELECT id, LOWER(TRIM(name)) AS norm_name, recipe_id
               FROM menu_items WHERE restaurant_id = ?',
            [$restaurantId]
        );
        $menuByNorm = [];
        foreach ($menuItems as $mi) $menuByNorm[$mi['norm_name']] = $mi;

        $db->beginTransaction();
        try {
            foreach ($groups as $g) {
                $usableRows = array_filter($g['rows'], function ($row) use ($includeWarnings) {
                    if ($row['status'] === 'error') return false;
                    if ($row['status'] === 'warning' && !$includeWarnings) return false;
                    return true;
                });
                if (empty($usableRows)) {
                    $skipped[] = ['item_name' => $g['item_name'], 'reason' => 'all rows had errors'];
                    continue;
                }
                $recipeId = $this->recipes->create($orgId, $restaurantId, $g['item_name']);
                foreach ($usableRows as $row) {
                    $this->recipes->addIngredient(
                        $recipeId,
                        $row['ingredient_key'],
                        (float) $row['qty'],
                        $row['unit']
                    );
                }
                $linkedItemId = null;
                $mi = $menuByNorm[$g['normalized_name']] ?? null;
                if ($mi && $mi['recipe_id'] === null) {
                    $this->items->setRecipe($mi['id'], $orgId, $recipeId);
                    $linkedItemId = $mi['id'];
                    $linkedCount++;
                }
                $created[] = [
                    'recipe_id' => $recipeId,
                    'name' => $g['item_name'],
                    'ingredient_count' => count($usableRows),
                    'linked_menu_item_id' => $linkedItemId,
                ];
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('[recipes/paste/commit] failed: ' . $e->getMessage());
            Response::error('Failed to commit paste: ' . $e->getMessage(), 500);
        }

        // Fire-and-forget plate-cost recompute so the operator sees costs immediately.
        try {
            $svc = new PlateCostService(
                $this->items,
                $this->recipes,
                new PlateCostRepository(),
                new CogsBenchmarkRepository(),
            );
            $recomputed = $svc->computeForRestaurant($restaurantId, $orgId, $r['region'] ?? null);
        } catch (\Throwable $e) {
            error_log('[recipes/paste/commit] recompute failed (non-fatal): ' . $e->getMessage());
            $recomputed = null;
        }

        Response::success([
            'created'           => $created,
            'created_count'     => count($created),
            'linked_count'      => $linkedCount,
            'skipped'           => $skipped,
            'plate_costs_recomputed' => $recomputed,
        ], 'Paste committed', 201);
    }

    /**
     * Return a draft recipe matching the given menu item name from the
     * seed dictionary. Operator confirms/edits before save. If nothing
     * matches, returns matched=false with an empty ingredient list — the
     * operator still gets a form (no blank page).
     *
     * Body: { "name": "Spaghetti Carbonara", "category": "pasta"? }
     */
    public function suggestRecipe(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $b = $request->getBody() ?? [];
        $name = trim((string) ($b['name'] ?? ''));
        $category = isset($b['category']) ? (string) $b['category'] : null;
        if ($name === '') Response::error('name is required', 422);
        $draft = (new RecipeSeedMatcher())->suggest($name, $category);

        // Decorate ingredients with benchmark prices so the operator sees
        // immediate cost feedback before committing the draft.
        $benchRepo = new CogsBenchmarkRepository();
        $region = $r['region'] ?? null;
        foreach ($draft['ingredients'] as &$ing) {
            $row = $benchRepo->lookup($ing['ingredient_key'], $region);
            $ing['benchmark'] = $row ? [
                'market_price_cents' => (int) $row['market_price_cents'],
                'unit'   => $row['unit'],
                'source' => $row['source'],
            ] : null;
        }
        Response::success(['draft' => $draft]);
    }

    /**
     * Ingredient autocomplete ranked by frequency-of-use across the
     * restaurant's own recipes first, then by global usage across the
     * organization, then alphabetically. Benchmarked ingredients always
     * surface ahead of un-benchmarked ones because plate cost depends on
     * them. Free-form (anything in cogs_benchmark or already used in
     * recipe_ingredients).
     *
     * Query: ?q=tom → returns tomato_roma, tomato_cherry, …
     */
    public function ingredientAutocomplete(Request $request): void
    {
        $r = $this->verifyOwnedRestaurant($request);
        $q = trim((string) ($request->getQuery('q') ?? ''));
        $orgId = $request->user['organization_id'];
        $restaurantId = $r['id'];
        $like = $q === '' ? null : strtolower($q) . '%';
        $likeAny = $q === '' ? null : '%' . strtolower($q) . '%';

        // Frequency in this restaurant's own recipes.
        $own = Database::getInstance()->fetchAll(
            'SELECT LOWER(ri.ingredient_key) AS k, COUNT(*) AS c
               FROM recipe_ingredients ri
               JOIN recipes r ON r.id = ri.recipe_id
              WHERE r.restaurant_id = ?
              GROUP BY k',
            [$restaurantId]
        );
        $ownByKey = [];
        foreach ($own as $row) $ownByKey[$row['k']] = (int) $row['c'];

        // Frequency org-wide (other restaurants' learning).
        $org = Database::getInstance()->fetchAll(
            'SELECT LOWER(ri.ingredient_key) AS k, COUNT(*) AS c
               FROM recipe_ingredients ri
               JOIN recipes r ON r.id = ri.recipe_id
              WHERE r.organization_id = ?
              GROUP BY k',
            [$orgId]
        );
        $orgByKey = [];
        foreach ($org as $row) $orgByKey[$row['k']] = (int) $row['c'];

        // All known keys: union of cogs_benchmark + recipe_ingredients.
        // (cogs_benchmark gives us the canonical list with prices; recipe
        // usage tells us how *common* each is in actual practice.)
        $allKeys = Database::getInstance()->fetchAll(
            'SELECT DISTINCT ingredient_key AS k, market_price_cents AS price, unit AS unit
               FROM cogs_benchmark
              UNION
             SELECT DISTINCT LOWER(ingredient_key) AS k, NULL, NULL FROM recipe_ingredients
              WHERE LOWER(ingredient_key) NOT IN (SELECT ingredient_key FROM cogs_benchmark)'
        );
        // De-dup by key, preferring rows that have a price.
        $byKey = [];
        foreach ($allKeys as $row) {
            $k = (string) $row['k'];
            if (!isset($byKey[$k]) || ($row['price'] !== null && $byKey[$k]['price'] === null)) {
                $byKey[$k] = $row;
            }
        }

        $candidates = [];
        foreach ($byKey as $k => $row) {
            if ($like !== null) {
                // Match prefix first (stronger), then anywhere as fallback.
                $prefix = strpos($k, strtolower($q)) === 0;
                $anywhere = $prefix || (strpos($k, strtolower($q)) !== false);
                if (!$anywhere) continue;
                $matchScore = $prefix ? 0 : 1;
            } else {
                $matchScore = 0;
            }
            $ownFreq = $ownByKey[$k] ?? 0;
            $orgFreq = $orgByKey[$k] ?? 0;
            $hasBench = $row['price'] !== null;
            $candidates[] = [
                'key' => $k,
                'has_benchmark' => $hasBench,
                'market_price_cents' => $row['price'] !== null ? (int) $row['price'] : null,
                'unit' => $row['unit'],
                'own_freq' => $ownFreq,
                'org_freq' => $orgFreq,
                'match_score' => $matchScore,
            ];
        }

        usort($candidates, function ($a, $b) {
            // Prefix matches first, then own-frequency, then benchmarked,
            // then org-frequency, then alphabetical.
            if ($a['match_score'] !== $b['match_score']) return $a['match_score'] <=> $b['match_score'];
            if ($a['own_freq']    !== $b['own_freq'])    return $b['own_freq']    <=> $a['own_freq'];
            if ($a['has_benchmark'] !== $b['has_benchmark']) return $b['has_benchmark'] <=> $a['has_benchmark'];
            if ($a['org_freq']    !== $b['org_freq'])    return $b['org_freq']    <=> $a['org_freq'];
            return strcmp($a['key'], $b['key']);
        });

        $limit = (int) ($request->getQuery('limit') ?? 30);
        if ($limit <= 0 || $limit > 200) $limit = 30;
        $candidates = array_slice($candidates, 0, $limit);

        Response::success(['suggestions' => $candidates]);
    }

    private function verifyOwnedRestaurant(Request $request): array
    {
        $id = (string) $request->getParam('id');
        $r = $this->restaurants->findById($id, $request->user['organization_id']);
        if (!$r) Response::error('Restaurant not found', 404);
        return $r;
    }
}
