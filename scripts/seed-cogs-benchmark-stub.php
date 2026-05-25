<?php
declare(strict_types=1);

/**
 * Seed `cogs_benchmark` with ~30 common-restaurant ingredients at plausible
 * wholesale prices. Source = 'stub' so the live GreenDock-published rows
 * (when that pipe exists — see spec §1a Pipe A) supersede via the
 * source-preference ordering in CogsBenchmarkRepository.
 *
 * Idempotent: UNIQUE(ingredient_key, region, source, as_of) means re-runs
 * are no-ops. Cleanup the stub later with:
 *   DELETE FROM cogs_benchmark WHERE source = 'stub';
 *
 * Run: php scripts/seed-cogs-benchmark-stub.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));
$db = Database::getInstance();

$asOf = date('Y-m-d');

// Prices are illustrative — single-source wholesale ballparks in 2024,
// per stated unit, in cents. Replace with live USDA / landed-cost data
// when the GreenDock feed comes online.
$items = [
    // produce
    ['tomato_roma',      'lb',   180, 'US'],
    ['tomato_cherry',    'lb',   320, 'US'],
    ['onion_yellow',     'lb',    90, 'US'],
    ['garlic_fresh',     'lb',   420, 'US'],
    ['basil_fresh',      'oz',    95, 'US'],
    ['parsley_fresh',    'oz',    60, 'US'],
    ['lemon',            'each',  55, 'US'],
    ['lime',             'each',  45, 'US'],
    ['avocado',          'each', 110, 'US'],
    ['lettuce_romaine',  'lb',   180, 'US'],
    ['spinach_baby',     'lb',   320, 'US'],
    ['mushroom_button',  'lb',   260, 'US'],
    // dairy
    ['mozzarella_fresh', 'lb',   480, 'US'],
    ['parmesan_grated',  'lb',   980, 'US'],
    ['butter_unsalted',  'lb',   460, 'US'],
    ['cream_heavy',      'cup',  140, 'US'],
    ['egg_large',        'each',  35, 'US'],
    ['milk_whole',       'cup',   40, 'US'],
    // protein
    ['chicken_breast',   'lb',   380, 'US'],
    ['ground_beef_80_20','lb',   520, 'US'],
    ['salmon_fillet',    'lb',  1280, 'US'],
    ['shrimp_16_20',     'lb',  1080, 'US'],
    ['bacon_thick',      'lb',   780, 'US'],
    // pantry
    ['pasta_spaghetti',  'lb',   140, 'US'],
    ['rice_jasmine',     'lb',   160, 'US'],
    ['flour_ap',         'lb',    65, 'US'],
    ['sugar_granulated', 'lb',    80, 'US'],
    ['olive_oil_xv',     'cup',  240, 'US'],
    ['salt_kosher',      'oz',     8, 'US'],
    ['pepper_black',     'oz',    45, 'US'],
];

$inserted = 0;
$skipped  = 0;
foreach ($items as [$key, $unit, $cents, $region]) {
    try {
        $db->query(
            'INSERT INTO cogs_benchmark (id, ingredient_key, region, market_price_cents, unit, source, as_of, created_at)
             VALUES (?, ?, ?, ?, ?, "stub", ?, NOW())',
            [Database::uuid(), $key, $region, $cents, $unit, $asOf]
        );
        $inserted++;
        echo "  + $key  $unit  " . number_format($cents / 100, 2) . "/$unit ($region)\n";
    } catch (\Throwable $e) {
        // Likely UNIQUE violation — already seeded today.
        if (str_contains($e->getMessage(), '1062')) {
            $skipped++;
        } else {
            echo "  ! $key skipped: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nDone. $inserted inserted, $skipped already present.\n";
echo "These are STUB prices. Real GreenDock-published USDA + landed cost feed\n";
echo "supersedes them automatically via cogs_benchmark.source preference.\n";
