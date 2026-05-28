<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\PrivateData\GoalRepository;
use App\PrivateData\LaborShiftRepository;
use App\PrivateData\MenuItemRepository;
use App\PrivateData\PlateCostRepository;
use App\PrivateData\PosIntegrationRepository;
use App\PrivateData\PosSalesRepository;
use App\PrivateData\RecipeRepository;
use App\PrivateData\RecommendationRepository;
use App\PrivateData\RestaurantRepository;
use App\PrivateData\SampleDataService;
use App\SharedRef\CogsBenchmarkRepository;

/**
 * Restaurants — Carafe's primary entity. Org-scoped CRUD.
 *
 * Routes:
 *   GET    /api/restaurants                — list active for caller's org
 *   POST   /api/restaurants                — create
 *   GET    /api/restaurants/{id}           — fetch one
 *   DELETE /api/restaurants/{id}           — archive (soft delete)
 *   POST   /api/onboarding/clone-sample-restaurant — clone the is_sample row
 */
class RestaurantController
{
    private RestaurantRepository $repo;

    public function __construct(?RestaurantRepository $repo = null)
    {
        $this->repo = $repo ?? new RestaurantRepository();
    }

    public function index(Request $request): void
    {
        $rows = $this->repo->listByOrg($request->user['organization_id']);
        Response::success(['restaurants' => $rows]);
    }

    public function show(Request $request): void
    {
        $row = $this->repo->findById((string) $request->getParam('id'), $request->user['organization_id']);
        if (!$row) Response::error('Restaurant not found', 404);
        Response::success(['restaurant' => $row]);
    }

    public function create(Request $request): void
    {
        $b = $request->getBody() ?? [];
        $name = trim((string) ($b['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) {
            Response::error('name (1–160 chars) required', 422);
        }
        $placeId = isset($b['google_place_id']) ? trim((string) $b['google_place_id']) : '';
        // Dedupe: if this org already has a restaurant for this Google place,
        // return it instead of creating a duplicate.
        if ($placeId !== '') {
            $existing = $this->repo->findByGooglePlaceId($request->user['organization_id'], $placeId);
            if ($existing) {
                Response::success(['id' => $existing['id'], 'already_exists' => true], 'Restaurant already in your workspace', 200);
            }
        }
        $id = $this->repo->create($request->user['organization_id'], [
            'name'            => $name,
            'address'         => isset($b['address'])  ? (string) $b['address']  : null,
            'lat'             => isset($b['lat'])      ? (float)  $b['lat']      : null,
            'lng'             => isset($b['lng'])      ? (float)  $b['lng']      : null,
            'timezone'        => isset($b['timezone']) ? (string) $b['timezone'] : null,
            'region'          => isset($b['region'])   ? (string) $b['region']   : null,
            'google_place_id' => $placeId !== '' ? $placeId : null,
            'phone'           => isset($b['phone'])    ? (string) $b['phone']    : null,
            'website'         => isset($b['website'])  ? (string) $b['website']  : null,
        ]);
        Response::success(['id' => $id], 'Restaurant created', 201);
    }

    public function destroy(Request $request): void
    {
        $id = (string) $request->getParam('id');
        $row = $this->repo->findById($id, $request->user['organization_id']);
        if (!$row) Response::error('Restaurant not found', 404);
        $this->repo->archive($id, $request->user['organization_id']);
        Response::success([], 'Archived');
    }

    /**
     * Clone the system-wide `is_sample = 1` restaurant into the caller's
     * org so the first-run wizard's "Try with sample" path can land the
     * user on a war-room within a couple of seconds.
     *
     * Idempotent (#20): if the caller's org already has a Demo: * row,
     *   return it instead of cloning a second copy. The wizard typically
     *   hits this twice (once on click, once if the user reopens) and we
     *   don't want a graveyard of duplicate Demo restaurants.
     *
     * Cuisine-aware (#12): body { cuisine: "italian" | "mexican" | "asian"
     *   | "american" } picks the matching seeded sample. Falls back to the
     *   first available sample if cuisine missing or unmatched.
     *
     * Engines run separately (#11): we deliberately DO NOT kick plate-cost
     *   + recommendation runs inside this request. The frontend chains:
     *      1. POST clone-sample-restaurant          → restaurant row only
     *      2. POST /restaurants/{id}/plate-costs/recompute
     *      3. POST /restaurants/{id}/recommendations/run
     *   …with visible per-step progress. Lets the user see something
     *   happening instead of a 2-3s spinner.
     */
    public function cloneSample(Request $request): void
    {
        $orgId = $request->user['organization_id'];
        $b = $request->getBody() ?? [];
        $cuisine = isset($b['cuisine']) && is_string($b['cuisine']) ? strtolower(trim($b['cuisine'])) : null;
        if ($cuisine !== null && !preg_match('/^[a-z_]{1,30}$/', $cuisine)) {
            $cuisine = null;
        }

        // Idempotency: short-circuit if the org already has a Demo restaurant.
        $existing = $this->repo->findExistingDemoForOrg($orgId);
        if ($existing) {
            Response::success(
                ['id' => $existing['id'], 'already_exists' => true, 'name' => $existing['name']],
                'Demo restaurant already in your workspace',
                200
            );
        }

        $sample = $cuisine !== null ? $this->repo->findSampleByCuisine($cuisine) : null;
        if (!$sample) $sample = $this->repo->findSample();
        if (!$sample) {
            Response::error(
                'No sample restaurant configured on this server. Run scripts/seed-sample-restaurants-by-cuisine.php on the droplet.',
                404
            );
        }

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            $newId = $this->repo->create($orgId, [
                'name'     => 'Demo: ' . $sample['name'],
                'address'  => $sample['address']  ?? null,
                'lat'      => $sample['lat']      ?? null,
                'lng'      => $sample['lng']      ?? null,
                'timezone' => $sample['timezone'] ?? null,
                'region'   => $sample['region']   ?? null,
                'cuisine'  => $sample['cuisine']  ?? $cuisine,
                'is_sample' => false,
            ]);

            // Recipes + ingredients (id remap so menu_item.recipe_id rebinds).
            $recipeMap = [];
            $recipes = $db->fetchAll(
                'SELECT id, name, notes FROM recipes WHERE restaurant_id = ?',
                [$sample['id']]
            );
            foreach ($recipes as $r) {
                $newRecipeId = Database::uuid();
                $recipeMap[$r['id']] = $newRecipeId;
                $db->query(
                    'INSERT INTO recipes (id, organization_id, restaurant_id, name, notes, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
                    [$newRecipeId, $orgId, $newId, $r['name'], $r['notes']]
                );
                $ings = $db->fetchAll(
                    'SELECT ingredient_key, qty, unit, notes FROM recipe_ingredients WHERE recipe_id = ?',
                    [$r['id']]
                );
                foreach ($ings as $ing) {
                    $db->query(
                        'INSERT INTO recipe_ingredients (id, recipe_id, ingredient_key, qty, unit, notes, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, NOW())',
                        [Database::uuid(), $newRecipeId, $ing['ingredient_key'], $ing['qty'], $ing['unit'], $ing['notes']]
                    );
                }
            }

            $items = $db->fetchAll(
                'SELECT name, category, price_cents, recipe_id, is_active
                   FROM menu_items WHERE restaurant_id = ?',
                [$sample['id']]
            );
            foreach ($items as $it) {
                $db->query(
                    'INSERT INTO menu_items
                        (id, organization_id, restaurant_id, name, category, price_cents,
                         recipe_id, is_active, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                    [
                        Database::uuid(), $orgId, $newId,
                        $it['name'], $it['category'], (int) $it['price_cents'],
                        $it['recipe_id'] ? ($recipeMap[$it['recipe_id']] ?? null) : null,
                        (int) ($it['is_active'] ?? 1),
                    ]
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('[cloneSample] failed: ' . $e->getMessage());
            Response::error('Could not clone sample restaurant: ' . $e->getMessage(), 500);
        }

        Response::success(
            ['id' => $newId, 'cuisine' => $sample['cuisine'] ?? null, 'name' => 'Demo: ' . $sample['name']],
            'Sample restaurant cloned',
            201
        );
    }

    /**
     * POST /api/restaurants/sample — seed a fully-populated sample restaurant
     * in the caller's org. Calls SampleDataService, the same code path
     * scripts/seed-sample-restaurant.php uses. Idempotent.
     *
     * Returns: { id, created, counts: { menu_items, recipes, pos_sales, ... } }
     */
    public function createSample(Request $request): void
    {
        $orgId = (string) $request->user['organization_id'];
        try {
            $result = $this->sampleService()->seedForOrganization($orgId);
        } catch (\Throwable $e) {
            error_log('[restaurants.createSample] ' . $e->getMessage());
            Response::error('Could not seed sample data: ' . $e->getMessage(), 500);
        }
        Response::success(
            [
                'id'      => $result['restaurant_id'],
                'created' => $result['created'],
                'counts'  => $result['counts'] ?? [],
            ],
            $result['created'] ? 'Sample restaurant created' : 'Sample restaurant refreshed',
            $result['created'] ? 201 : 200
        );
    }

    /**
     * DELETE /api/restaurants/sample — tear down every sample restaurant
     * in the caller's org. Idempotent (no-op if none exist).
     */
    public function removeSample(Request $request): void
    {
        $orgId = (string) $request->user['organization_id'];
        try {
            $result = $this->sampleService()->removeForOrganization($orgId);
        } catch (\Throwable $e) {
            error_log('[restaurants.removeSample] ' . $e->getMessage());
            Response::error('Could not remove sample data: ' . $e->getMessage(), 500);
        }
        Response::success(
            ['removed' => $result['removed'], 'restaurant_ids' => $result['restaurant_ids']],
            $result['removed'] ? 'Sample data removed' : 'No sample data to remove'
        );
    }

    private function sampleService(): SampleDataService
    {
        return new SampleDataService(
            $this->repo,
            new MenuItemRepository(),
            new RecipeRepository(),
            new PlateCostRepository(),
            new PosIntegrationRepository(),
            new PosSalesRepository(),
            new RecommendationRepository(),
            new LaborShiftRepository(),
            new GoalRepository(),
            new CogsBenchmarkRepository(),
        );
    }
}
