<?php
declare(strict_types=1);

/**
 * Extend the cogs_benchmark stub with ingredients used by the Mexican,
 * Asian, and American cuisine sample restaurants (seed-sample-restaurants
 * -by-cuisine.php). Without these the plate-cost engine returns 0%
 * coverage for those samples and the wizard's step-4 "We found you these
 * moves" screen shows nothing.
 *
 * Idempotent: UNIQUE(ingredient_key, region, source, as_of) means re-runs
 * just bump the existing rows.
 *
 *   php scripts/seed-cogs-benchmark-cuisines.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));
$db = Database::getInstance();

$asOf = date('Y-m-d');

$items = [
    // Mexican
    ['beef_skirt',       'lb',   980, 'US'],
    ['tortilla_corn',    'each',  18, 'US'],
    ['onion_white',      'oz',     8, 'US'],
    ['cilantro_fresh',   'oz',    35, 'US'],
    ['cheese_cheddar',   'oz',    65, 'US'],
    // Asian
    ['pork_belly',       'lb',   720, 'US'],
    ['bao_bun',          'each',  85, 'US'],
    ['cucumber',         'oz',    14, 'US'],
    ['noodles_wheat',    'lb',   180, 'US'],
    ['pork_ground',      'lb',   480, 'US'],
    ['peanut_butter',    'oz',    24, 'US'],
    ['chili_oil',        'oz',    65, 'US'],
    ['tofu_firm',        'lb',   220, 'US'],
    ['broccoli',         'lb',   240, 'US'],
    ['ginger',           'oz',    35, 'US'],
    // American
    ['beef_ground_80_20','lb',   520, 'US'],  // alias of ground_beef_80_20, recipe uses this name
    ['bun_brioche',      'each', 120, 'US'],
    ['cheese_american',  'oz',    45, 'US'],
    ['romaine',          'lb',   180, 'US'],  // alias of lettuce_romaine
    ['crouton',          'oz',    35, 'US'],
    ['anchovy',          'oz',   140, 'US'],
    ['buttermilk',       'oz',     8, 'US'],
];

$inserted = 0;
$skipped = 0;
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
        if (str_contains($e->getMessage(), '1062')) {
            $skipped++;
        } else {
            echo "  ! $key skipped: " . $e->getMessage() . "\n";
        }
    }
}

echo "\nDone. $inserted inserted, $skipped already present.\n";
