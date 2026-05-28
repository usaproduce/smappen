<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\PrivateData\MenuItemRepository;
use App\PrivateData\PlateCostRepository;
use App\PrivateData\RecipeRepository;
use App\PrivateData\RecommendationRepository;
use App\PrivateData\RestaurantRepository;
use App\Services\MenuEngineeringService;
use App\Services\PlateCostService;
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
     * org so the first-run wizard's "Try with sample" path lands the user
     * on a war-room with real plate costs and recommendations in <10s.
     *
     * Re-runs PlateCostService + MenuEngineeringService against the new
     * restaurant_id so the war-room shows dollar-quantified recs out of
     * the box — we deliberately don't copy plate_costs / recommendations
     * rows since they reference menu_item_ids that we just regenerated.
     */
    public function cloneSample(Request $request): void
    {
        $sample = $this->repo->findSample();
        if (!$sample) {
            Response::error(
                'No sample restaurant configured on this server. Run scripts/seed-sample-restaurant.php on the droplet.',
                404
            );
        }
        $orgId = $request->user['organization_id'];

        $db = Database::getInstance();
        $db->beginTransaction();
        try {
            // 1. Restaurant row — copy address/coords/region, NOT is_sample.
            $newId = $this->repo->create($orgId, [
                'name'     => 'Demo: ' . $sample['name'],
                'address'  => $sample['address']  ?? null,
                'lat'      => $sample['lat']      ?? null,
                'lng'      => $sample['lng']      ?? null,
                'timezone' => $sample['timezone'] ?? null,
                'region'   => $sample['region']   ?? null,
                'is_sample' => false,
            ]);

            // 2. Recipes + ingredients (id remap so menu_item.recipe_id can rebind).
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

            // 3. Menu items — rebind recipe_id, drop pos_* columns (not connected).
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

        // 4. Kick the engines so the war-room renders dollar-quantified recs
        // on first paint — that's the whole point of the wizard's final screen.
        try {
            $items = new MenuItemRepository();
            $recipes = new RecipeRepository();
            $plateCosts = new PlateCostRepository();
            $bench = new CogsBenchmarkRepository();
            $pc = new PlateCostService($items, $recipes, $plateCosts, $bench);
            $pc->computeForRestaurant($newId, $orgId, $sample['region'] ?? 'US');

            $engine = new MenuEngineeringService($items, $plateCosts, new RecommendationRepository());
            $engine->recommendForRestaurant($newId, $orgId);
        } catch (\Throwable $e) {
            error_log('[cloneSample] engine kick failed: ' . $e->getMessage());
            // Non-fatal — the restaurant is created either way; recs will be
            // empty but the operator can recompute from the war-room.
        }

        Response::success(['id' => $newId], 'Sample restaurant cloned', 201);
    }
}
