<?php
declare(strict_types=1);

/**
 * Seed a "Demo: Trattoria Verde" sample restaurant so the Carafe vertical
 * slice has something to show before a real Square account is connected.
 *
 * Idempotent: bails if a restaurant with is_sample = 1 already exists.
 *
 * Creates:
 *   - 1 organization "System Restaurants" (if none exists)
 *   - 1 restaurant "Demo: Trattoria Verde", marked is_sample
 *   - 3 menu items + recipes wired to the cogs_benchmark stub keys
 *   - plate costs + first recommendation pass (so the UI renders immediately)
 *
 * Run on the droplet AFTER `seed-cogs-benchmark-stub.php`:
 *   php scripts/seed-cogs-benchmark-stub.php
 *   php scripts/seed-sample-restaurant.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\PrivateData\RestaurantRepository;
use App\PrivateData\MenuItemRepository;
use App\PrivateData\RecipeRepository;
use App\PrivateData\PlateCostRepository;
use App\PrivateData\RecommendationRepository;
use App\SharedRef\CogsBenchmarkRepository;
use App\Services\PlateCostService;
use App\Services\MenuEngineeringService;

Config::load(dirname(__DIR__));
$db = Database::getInstance();

$repo = new RestaurantRepository();
$existing = $repo->findSample();
if ($existing) {
    echo "Sample restaurant already exists: {$existing['id']} ({$existing['name']})\n";
    exit(0);
}

// Find or create a system org for sample data.
$org = $db->fetch("SELECT id FROM organizations WHERE name = 'System Restaurants' LIMIT 1");
if ($org) {
    $orgId = $org['id'];
} else {
    $orgId = Database::uuid();
    $db->query(
        'INSERT INTO organizations (id, name, plan, max_seats, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())',
        [$orgId, 'System Restaurants', 'enterprise', 0]
    );
    echo "Created System Restaurants org: $orgId\n";
}

$restaurantId = $repo->create($orgId, [
    'name'      => 'Demo: Trattoria Verde',
    'address'   => '1234 W Division St, Chicago IL',
    'lat'       => 41.9036,
    'lng'       => -87.6717,
    'timezone'  => 'America/Chicago',
    'region'    => 'US',
    'is_sample' => true,
]);
echo "Created sample restaurant: $restaurantId\n";

$recipes = new RecipeRepository();
$items   = new MenuItemRepository();

// Carbonara — moderate margin (this one will trigger a price-raise rec
// at typical wholesale prices for the stub).
$carbonaraRecipe = $recipes->create($orgId, $restaurantId, 'Carbonara');
$recipes->addIngredient($carbonaraRecipe, 'pasta_spaghetti', 0.25, 'lb');
$recipes->addIngredient($carbonaraRecipe, 'bacon_thick',     0.15, 'lb');
$recipes->addIngredient($carbonaraRecipe, 'egg_large',       2,    'each');
$recipes->addIngredient($carbonaraRecipe, 'parmesan_grated', 1,    'oz');
$recipes->addIngredient($carbonaraRecipe, 'pepper_black',    0.05, 'oz');
$items->createManual($orgId, $restaurantId, [
    'name'        => 'Spaghetti alla Carbonara',
    'category'    => 'Pasta',
    'price_cents' => 1800,
    'recipe_id'   => $carbonaraRecipe,
]);

// Caprese — healthy margin.
$capreseRecipe = $recipes->create($orgId, $restaurantId, 'Caprese');
$recipes->addIngredient($capreseRecipe, 'tomato_roma',      0.50, 'lb');
$recipes->addIngredient($capreseRecipe, 'mozzarella_fresh', 0.25, 'lb');
$recipes->addIngredient($capreseRecipe, 'basil_fresh',      0.5,  'oz');
$recipes->addIngredient($capreseRecipe, 'olive_oil_xv',     0.1,  'cup');
$items->createManual($orgId, $restaurantId, [
    'name'        => 'Insalata Caprese',
    'category'    => 'Antipasti',
    'price_cents' => 1400,
    'recipe_id'   => $capreseRecipe,
]);

// Salmon — premium-priced.
$salmonRecipe = $recipes->create($orgId, $restaurantId, 'Salmon');
$recipes->addIngredient($salmonRecipe, 'salmon_fillet', 0.40, 'lb');
$recipes->addIngredient($salmonRecipe, 'lemon',         0.5,  'each');
$recipes->addIngredient($salmonRecipe, 'butter_unsalted', 0.5, 'oz');
$recipes->addIngredient($salmonRecipe, 'parsley_fresh', 0.5, 'oz');
$items->createManual($orgId, $restaurantId, [
    'name'        => 'Salmone al Limone',
    'category'    => 'Secondi',
    'price_cents' => 2800,
    'recipe_id'   => $salmonRecipe,
]);

echo "  + Carbonara, Caprese, Salmon (recipes + menu items)\n";

// Kick the engines so the demo has data on first load.
$plateCostSvc = new PlateCostService(
    $items,
    $recipes,
    new PlateCostRepository(),
    new CogsBenchmarkRepository(),
);
$pcCount = $plateCostSvc->computeForRestaurant($restaurantId, $orgId, 'US');
echo "  Plate costs computed for $pcCount items\n";

$engine = new MenuEngineeringService(
    $items,
    new PlateCostRepository(),
    new RecommendationRepository(),
);
$recCount = $engine->recommendForRestaurant($restaurantId, $orgId);
echo "  Recommendations created: $recCount\n";

echo "\nSample restaurant seeded.\n";
echo "Verify: SELECT id, name, is_sample FROM restaurants WHERE is_sample = 1;\n";
