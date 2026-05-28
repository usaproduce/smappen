<?php
declare(strict_types=1);

/**
 * Seed cuisine-tagged sample restaurants so the Carafe first-run wizard's
 * "Try with sample" step can offer Italian / Mexican / Asian / American.
 *
 * Idempotent:
 *   - Skips creating a sample for any cuisine that already has one
 *     (`SELECT 1 FROM restaurants WHERE is_sample = 1 AND cuisine = ? LIMIT 1`)
 *   - For the legacy "Demo: Trattoria Verde" row created by
 *     seed-sample-restaurant.php, back-fills cuisine = 'italian' if NULL
 *
 * Run on the droplet AFTER 037_carafe_wizard_v2.sql migration:
 *   php scripts/seed-cogs-benchmark-stub.php
 *   php scripts/migrate.php
 *   php scripts/seed-sample-restaurants-by-cuisine.php
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

// Back-fill the legacy Italian sample so the cuisine picker can find it.
$db->query("UPDATE restaurants SET cuisine = 'italian' WHERE is_sample = 1 AND cuisine IS NULL AND name LIKE '%Trattoria%'");

// Find or create system org.
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

$repo       = new RestaurantRepository();
$recipes    = new RecipeRepository();
$items      = new MenuItemRepository();
$plateCosts = new PlateCostRepository();
$bench      = new CogsBenchmarkRepository();
$pc         = new PlateCostService($items, $recipes, $plateCosts, $bench);
$engine     = new MenuEngineeringService($items, $plateCosts, new RecommendationRepository());

$cuisines = [
    'italian' => [
        'name'    => 'Demo: Trattoria Verde',
        'address' => '1234 W Division St, Chicago IL',
        'lat'     => 41.9036, 'lng' => -87.6717, 'tz' => 'America/Chicago',
        'items'   => [
            ['name' => 'Spaghetti alla Carbonara', 'category' => 'Pasta',     'price_cents' => 1800,
             'recipe' => [['pasta_spaghetti', 0.25, 'lb'], ['bacon_thick', 0.15, 'lb'], ['egg_large', 2, 'each'], ['parmesan_grated', 1, 'oz'], ['pepper_black', 0.05, 'oz']]],
            // Intentionally underpriced so the menu-engineering engine fires
            // a price_raise rec on first wizard run — that's the whole point
            // of the step-4 "We found you these moves" screen.
            ['name' => 'Insalata Caprese',        'category' => 'Antipasti', 'price_cents' => 650,
             'recipe' => [['tomato_roma', 0.50, 'lb'], ['mozzarella_fresh', 0.25, 'lb'], ['basil_fresh', 0.5, 'oz'], ['olive_oil_xv', 0.1, 'cup']]],
            ['name' => 'Salmone al Limone',       'category' => 'Secondi',   'price_cents' => 2800,
             'recipe' => [['salmon_fillet', 0.40, 'lb'], ['lemon', 0.5, 'each'], ['butter_unsalted', 0.5, 'oz'], ['parsley_fresh', 0.5, 'oz']]],
        ],
    ],
    'mexican' => [
        'name'    => 'Demo: La Tortilla',
        'address' => '2200 N Western Ave, Chicago IL',
        'lat'     => 41.9210, 'lng' => -87.6878, 'tz' => 'America/Chicago',
        'items'   => [
            // Intentionally underpriced to trigger a price-raise rec.
            ['name' => 'Carne Asada Tacos', 'category' => 'Tacos', 'price_cents' => 750,
             'recipe' => [['beef_skirt', 0.30, 'lb'], ['tortilla_corn', 3, 'each'], ['onion_white', 1, 'oz'], ['cilantro_fresh', 0.25, 'oz'], ['lime', 0.25, 'each']]],
            ['name' => 'Guacamole + Chips', 'category' => 'Sides', 'price_cents' => 900,
             'recipe' => [['avocado', 1.5, 'each'], ['onion_white', 1, 'oz'], ['tomato_roma', 0.25, 'lb'], ['lime', 0.5, 'each'], ['cilantro_fresh', 0.25, 'oz']]],
            ['name' => 'Chicken Enchiladas', 'category' => 'Entrees', 'price_cents' => 1900,
             'recipe' => [['chicken_breast', 0.40, 'lb'], ['tortilla_corn', 3, 'each'], ['cheese_cheddar', 2, 'oz'], ['onion_white', 1, 'oz']]],
        ],
    ],
    'asian' => [
        'name'    => 'Demo: Bao + Noodle',
        'address' => '2018 S Wentworth Ave, Chicago IL',
        'lat'     => 41.8528, 'lng' => -87.6321, 'tz' => 'America/Chicago',
        'items'   => [
            // Intentionally underpriced to trigger a price-raise rec.
            ['name' => 'Pork Belly Bao', 'category' => 'Bao', 'price_cents' => 750,
             'recipe' => [['pork_belly', 0.20, 'lb'], ['bao_bun', 2, 'each'], ['cucumber', 1, 'oz'], ['cilantro_fresh', 0.25, 'oz']]],
            ['name' => 'Dan Dan Noodles', 'category' => 'Noodles', 'price_cents' => 1600,
             'recipe' => [['noodles_wheat', 0.30, 'lb'], ['pork_ground', 0.20, 'lb'], ['peanut_butter', 0.5, 'oz'], ['chili_oil', 0.25, 'oz']]],
            ['name' => 'Crispy Tofu Bowl', 'category' => 'Bowls', 'price_cents' => 1400,
             'recipe' => [['tofu_firm', 0.30, 'lb'], ['rice_jasmine', 0.30, 'lb'], ['broccoli', 0.20, 'lb'], ['ginger', 0.1, 'oz']]],
        ],
    ],
    'american' => [
        'name'    => 'Demo: Hearth + Grill',
        'address' => '500 N Michigan Ave, Chicago IL',
        'lat'     => 41.8913, 'lng' => -87.6243, 'tz' => 'America/Chicago',
        'items'   => [
            // Intentionally underpriced to trigger a price-raise rec.
            ['name' => 'Smash Burger',   'category' => 'Burgers', 'price_cents' => 750,
             'recipe' => [['beef_ground_80_20', 0.30, 'lb'], ['bun_brioche', 1, 'each'], ['cheese_american', 1, 'oz'], ['onion_white', 0.5, 'oz']]],
            ['name' => 'Caesar Salad',   'category' => 'Salads',  'price_cents' => 1200,
             'recipe' => [['romaine', 0.40, 'lb'], ['parmesan_grated', 1, 'oz'], ['crouton', 0.5, 'oz'], ['anchovy', 0.1, 'oz']]],
            ['name' => 'Buttermilk Fried Chicken', 'category' => 'Entrees', 'price_cents' => 2200,
             'recipe' => [['chicken_breast', 0.40, 'lb'], ['buttermilk', 2, 'oz'], ['flour_ap', 2, 'oz'], ['butter_unsalted', 0.5, 'oz']]],
        ],
    ],
];

foreach ($cuisines as $cuisine => $spec) {
    $existing = $db->fetch(
        'SELECT id, name FROM restaurants WHERE is_sample = 1 AND cuisine = ? LIMIT 1',
        [$cuisine]
    );
    if ($existing) {
        echo "[skip] $cuisine — already seeded as {$existing['name']} ({$existing['id']})\n";
        continue;
    }

    $restaurantId = $repo->create($orgId, [
        'name'      => $spec['name'],
        'address'   => $spec['address'],
        'lat'       => $spec['lat'],
        'lng'       => $spec['lng'],
        'timezone'  => $spec['tz'],
        'region'    => 'US',
        'is_sample' => true,
    ]);
    // RestaurantRepository::create doesn't write cuisine — back-fill it.
    $db->query('UPDATE restaurants SET cuisine = ? WHERE id = ?', [$cuisine, $restaurantId]);
    echo "Created [$cuisine] $restaurantId — {$spec['name']}\n";

    foreach ($spec['items'] as $it) {
        $recipeId = $recipes->create($orgId, $restaurantId, $it['name']);
        foreach ($it['recipe'] as [$key, $qty, $unit]) {
            $recipes->addIngredient($recipeId, $key, (float) $qty, $unit);
        }
        $items->createManual($orgId, $restaurantId, [
            'name'        => $it['name'],
            'category'    => $it['category'],
            'price_cents' => $it['price_cents'],
            'recipe_id'   => $recipeId,
        ]);
    }

    $pcCount = $pc->computeForRestaurant($restaurantId, $orgId, 'US');
    $recCount = $engine->recommendForRestaurant($restaurantId, $orgId);
    echo "  Plate costs: $pcCount   Recommendations: $recCount\n";
}

echo "\nCuisine samples ready.\n";
echo "Verify: SELECT id, name, cuisine FROM restaurants WHERE is_sample = 1;\n";
