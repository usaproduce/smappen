<?php
declare(strict_types=1);

/**
 * Build a fully-populated "Trattoria Verde" sample restaurant.
 *
 * Calls SampleDataService for the work — same code the
 * POST /api/restaurants/sample endpoint runs. Idempotent: re-running for
 * the same org upserts the same identifiers.
 *
 * Usage:
 *   # Seed into the legacy "System Restaurants" template org:
 *   php scripts/seed-sample-restaurant.php
 *
 *   # Seed into a specific org (useful for QA / dev):
 *   php scripts/seed-sample-restaurant.php --org=<organization_id>
 *
 * Prereqs (run once):
 *   php scripts/migrate.php
 *   php scripts/seed-cogs-benchmark-stub.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
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

Config::load(dirname(__DIR__));
$db = Database::getInstance();

// Parse --org=<uuid> if present.
$orgArg = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--org=')) {
        $orgArg = substr($arg, 6);
    }
}

if ($orgArg) {
    $orgRow = $db->fetch('SELECT id, name FROM organizations WHERE id = ?', [$orgArg]);
    if (!$orgRow) {
        fwrite(STDERR, "Org $orgArg not found.\n");
        exit(2);
    }
    $orgId = (string) $orgRow['id'];
    echo "Seeding into org: {$orgRow['name']} ($orgId)\n";
} else {
    $org = $db->fetch("SELECT id FROM organizations WHERE name = 'System Restaurants' LIMIT 1");
    if ($org) {
        $orgId = (string) $org['id'];
    } else {
        $orgId = Database::uuid();
        $db->query(
            'INSERT INTO organizations (id, name, plan, max_seats, created_at, updated_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())',
            [$orgId, 'System Restaurants', 'enterprise', 0]
        );
        echo "Created System Restaurants org: $orgId\n";
    }
}

$service = new SampleDataService(
    new RestaurantRepository(),
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

$start = microtime(true);
$result = $service->seedForOrganization($orgId);
$elapsed = number_format(microtime(true) - $start, 2);

echo "\n";
echo ($result['created'] ? "Created" : "Updated") . " sample restaurant {$result['restaurant_id']} in {$elapsed}s\n";
foreach (($result['counts'] ?? []) as $k => $v) {
    echo "  $k: $v\n";
}
echo "\nVerify: SELECT id, name, is_sample, organization_id FROM restaurants WHERE id = '{$result['restaurant_id']}';\n";
