<?php
declare(strict_types=1);

/**
 * Carafe Phase 2 — seed the vendor directory with a small manually-curated
 * set so the marketplace UI has something to browse on day one.
 *
 * USA Produce is the affiliated row (is_affiliated = 1) so the
 * comparison engine's mandatory disclosure label (spec §1.4) gets
 * exercised end-to-end.
 *
 * NO PRICING DATA is published here. Spec §6.1 + §13 Q3 are explicit:
 * publishing confidential supplier pricing is a separate legal question.
 * Directory rows carry only name/location/categories.
 *
 * Auto-scraping a wider universe (USDA, public directories, web) is
 * deliberately deferred — a follow-up will pick a source and run it
 * once the licensing question is settled.
 *
 * Idempotent: skips any vendor whose name already exists.
 *
 * Run: php scripts/seed-vendors-manual.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\MarketData\VendorRepository;

Config::load(dirname(__DIR__));
$repo = new VendorRepository();

$vendors = [
    [
        'name'             => 'USA Produce',
        'legal_name'       => 'USA Produce LLC',
        'hq_address'       => 'Newark, NJ',
        'primary_category' => 'produce',
        'is_affiliated'    => true,
        'source'           => 'greendock_affiliate',
        'listings' => [
            ['category' => 'produce', 'region' => 'US-NE', 'service_radius_mi' => 250, 'min_order_cents' => 25000],
            ['category' => 'produce', 'region' => 'US-MA', 'service_radius_mi' => 250, 'min_order_cents' => 25000],
        ],
    ],
    [
        'name'             => 'Sysco',
        'primary_category' => 'broadline',
        'source'           => 'public_directory',
        'listings' => [
            ['category' => 'broadline', 'region' => null, 'service_radius_mi' => null, 'min_order_cents' => 50000],
            ['category' => 'produce',   'region' => null],
            ['category' => 'protein',   'region' => null],
            ['category' => 'dairy',     'region' => null],
        ],
    ],
    [
        'name'             => 'US Foods',
        'primary_category' => 'broadline',
        'source'           => 'public_directory',
        'listings' => [
            ['category' => 'broadline', 'region' => null, 'min_order_cents' => 50000],
            ['category' => 'produce',   'region' => null],
            ['category' => 'protein',   'region' => null],
        ],
    ],
    [
        'name'             => 'Performance Food Group',
        'primary_category' => 'broadline',
        'source'           => 'public_directory',
        'listings' => [['category' => 'broadline', 'region' => null]],
    ],
    [
        'name'             => 'Baldor Specialty Foods',
        'hq_address'       => 'Bronx, NY',
        'primary_category' => 'produce',
        'source'           => 'public_directory',
        'listings' => [
            ['category' => 'produce',   'region' => 'US-NE', 'service_radius_mi' => 200],
            ['category' => 'specialty', 'region' => 'US-NE'],
        ],
    ],
    [
        'name'             => 'Restaurant Depot',
        'primary_category' => 'broadline',
        'source'           => 'public_directory',
        'listings' => [
            ['category' => 'broadline', 'region' => null, 'min_order_cents' => 0],
            ['category' => 'produce',   'region' => null],
            ['category' => 'protein',   'region' => null],
            ['category' => 'dairy',     'region' => null],
        ],
    ],
];

$inserted = 0;
$skipped  = 0;
foreach ($vendors as $v) {
    if ($repo->findByName($v['name'])) {
        $skipped++;
        echo "  - {$v['name']} already present, skipped\n";
        continue;
    }
    $id = $repo->create([
        'name'             => $v['name'],
        'legal_name'       => $v['legal_name'] ?? null,
        'hq_address'       => $v['hq_address'] ?? null,
        'primary_category' => $v['primary_category'] ?? null,
        'is_affiliated'    => !empty($v['is_affiliated']),
        'source'           => $v['source'] ?? 'manual',
    ]);
    foreach ($v['listings'] ?? [] as $l) {
        $repo->addListing($id, $l + ['source' => 'public_directory']);
    }
    $inserted++;
    echo "  + {$v['name']} ($id)\n";
}

echo "\nDone. $inserted inserted, $skipped already present.\n";
echo "Browse: GET /api/vendors  (Phase 2 endpoints land with Chunk 13 routes).\n";
