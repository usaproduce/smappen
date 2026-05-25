<?php
declare(strict_types=1);

/**
 * Carafe Vendor Network — seed national chain branches.
 *
 * Hand-curated set of well-known broadline / warehouse / specialty
 * branches in major metros so the map has real pins on day one
 * without a Places API spend. Full national chain data requires either
 * a paid dataset or a web scrape; this is the bootstrap layer.
 *
 * Each branch gets a vendor_locations row + a vendor_coverage radius
 * fallback so the "drop a pin → who serves me" query returns sensible
 * results immediately. Categories attached via vendor_categories.
 *
 * Idempotent: skips if a vendor already has a location at the same
 * address. Safe to re-run after adding more rows below.
 *
 * Run: php scripts/seed-vendor-chains.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\MarketData\VendorCategoryRepository;
use App\MarketData\VendorLocationRepository;
use App\MarketData\VendorRepository;
use App\Services\VendorGeometryService;
use App\MarketData\VendorCoverageRepository;

Config::load(dirname(__DIR__));
$db = Database::getInstance();
$vendors    = new VendorRepository();
$locations  = new VendorLocationRepository();
$categories = new VendorCategoryRepository();
$coverage   = new VendorCoverageRepository();
$geometry   = new VendorGeometryService($locations, $coverage);

// Branch dataset: handful per chain in major metros.
// lat/lng are real coordinates; verify before publishing — these are
// approximate addresses sourced from each chain's public locator.
$dataset = [
    'Sysco' => [
        'type' => 'broadline',
        'categories' => ['produce','meat','poultry','seafood','dairy','dry_goods','frozen','beverage','paper_disposables'],
        'branches' => [
            ['label' => 'Sysco NJ',          'address' => '101 Cedar Lane, Teterboro, NJ',      'lat' => 40.8638, 'lng' => -74.0584],
            ['label' => 'Sysco Boston',      'address' => '99 Spring St, Plympton, MA',         'lat' => 41.9462, 'lng' => -70.8131],
            ['label' => 'Sysco Chicago',     'address' => '250 Wieboldt Dr, Des Plaines, IL',   'lat' => 42.0233, 'lng' => -87.9192],
            ['label' => 'Sysco Atlanta',     'address' => '2225 Riverside Pkwy, College Park, GA','lat' => 33.6273, 'lng' => -84.4536],
            ['label' => 'Sysco Houston',     'address' => '7950 Bingle Rd, Houston, TX',         'lat' => 29.8569, 'lng' => -95.5184],
            ['label' => 'Sysco Los Angeles', 'address' => '20701 Currier Rd, Walnut, CA',        'lat' => 34.0140, 'lng' => -117.8439],
            ['label' => 'Sysco Seattle',     'address' => '22820 54th Ave S, Kent, WA',          'lat' => 47.3942, 'lng' => -122.2440],
        ],
    ],
    'US Foods' => [
        'type' => 'broadline',
        'categories' => ['produce','meat','poultry','seafood','dairy','dry_goods','frozen','beverage','paper_disposables'],
        'branches' => [
            ['label' => 'US Foods Perth Amboy',  'address' => '100 East Grant Ave, Perth Amboy, NJ',  'lat' => 40.5051, 'lng' => -74.2682],
            ['label' => 'US Foods Boston',       'address' => '375 Highland Ave, Seabrook, NH',        'lat' => 42.8901, 'lng' => -70.8729],
            ['label' => 'US Foods Chicago',      'address' => '300 W Western Ave, Chicago, IL',        'lat' => 41.8918, 'lng' => -87.6878],
            ['label' => 'US Foods Atlanta',      'address' => '4267 Wendell Dr SW, Atlanta, GA',       'lat' => 33.7100, 'lng' => -84.5142],
            ['label' => 'US Foods Houston',      'address' => '5400 Brittmoore Rd, Houston, TX',       'lat' => 29.8347, 'lng' => -95.5675],
            ['label' => 'US Foods Los Angeles',  'address' => '17398 Slover Ave, Fontana, CA',         'lat' => 34.0606, 'lng' => -117.4488],
        ],
    ],
    'Performance Food Group' => [
        'type' => 'broadline',
        'categories' => ['produce','meat','poultry','seafood','dairy','dry_goods','frozen','beverage'],
        'branches' => [
            ['label' => 'PFG Richmond HQ',  'address' => '12500 West Creek Pkwy, Richmond, VA',  'lat' => 37.6478, 'lng' => -77.6620],
            ['label' => 'PFG Elizabeth NJ', 'address' => '600 York St, Elizabeth, NJ',           'lat' => 40.6614, 'lng' => -74.2080],
            ['label' => 'PFG Chicago',      'address' => '1 PFG Way, Bensenville, IL',           'lat' => 41.9550, 'lng' => -87.9290],
            ['label' => 'PFG Atlanta',      'address' => '1480 N Brown Rd, Lawrenceville, GA',   'lat' => 33.9670, 'lng' => -84.0490],
        ],
    ],
    'Gordon Food Service' => [
        'type' => 'broadline',
        'categories' => ['produce','meat','poultry','seafood','dairy','dry_goods','frozen','beverage'],
        'branches' => [
            ['label' => 'Gordon Grand Rapids HQ', 'address' => '1300 Gezon Pkwy SW, Grand Rapids, MI', 'lat' => 42.8420, 'lng' => -85.7188],
            ['label' => 'Gordon Aurora IL',       'address' => '1295 W Diehl Rd, Aurora, IL',          'lat' => 41.7714, 'lng' => -88.2825],
            ['label' => 'Gordon Tampa FL',        'address' => '6225 Highway 50, Plant City, FL',      'lat' => 28.0061, 'lng' => -82.0867],
        ],
    ],
    'Restaurant Depot' => [
        'type' => 'warehouse',
        'categories' => ['produce','meat','poultry','seafood','dairy','dry_goods','frozen','beverage','paper_disposables','cleaning_chemical'],
        'branches' => [
            ['label' => 'Restaurant Depot Bronx',       'address' => '777 Bronx River Rd, Yonkers, NY',     'lat' => 40.9170, 'lng' => -73.8588],
            ['label' => 'Restaurant Depot Brooklyn',    'address' => '1351 Hamilton Ave, Brooklyn, NY',     'lat' => 40.6577, 'lng' => -74.0058],
            ['label' => 'Restaurant Depot Boston',      'address' => '50 Terminal St, Charlestown, MA',     'lat' => 42.3784, 'lng' => -71.0617],
            ['label' => 'Restaurant Depot Chicago N',   'address' => '4555 N California Ave, Chicago, IL',  'lat' => 41.9636, 'lng' => -87.6996],
            ['label' => 'Restaurant Depot Los Angeles', 'address' => '1925 W Beverly Blvd, Los Angeles, CA','lat' => 34.0613, 'lng' => -118.2710],
            ['label' => 'Restaurant Depot Atlanta',     'address' => '4651 Atlanta Rd SE, Smyrna, GA',      'lat' => 33.8742, 'lng' => -84.5061],
        ],
    ],
    'Baldor Specialty Foods' => [
        'type' => 'produce',
        'categories' => ['produce','specialty_imported','dairy'],
        'branches' => [
            ['label' => 'Baldor Bronx HQ',   'address' => '155 Food Center Dr, Bronx, NY', 'lat' => 40.8081, 'lng' => -73.8767],
            ['label' => 'Baldor Boston',     'address' => '99 W 1st St, Boston, MA',       'lat' => 42.3431, 'lng' => -71.0489],
            ['label' => 'Baldor Washington', 'address' => '7707 Lockport Pl, Lorton, VA',  'lat' => 38.6985, 'lng' => -77.2278],
        ],
    ],
    // Affiliated row — the entire spec hinges on USA Produce being honestly
    // ranked next to the big boys. Without these branches the affiliated
    // vendor was a directory row with zero map presence.
    'USA Produce' => [
        'type' => 'produce',
        'categories' => ['produce','specialty_imported'],
        'branches' => [
            ['label' => 'USA Produce Newark HQ',  'address' => '101 Avenue P, Newark, NJ',           'lat' => 40.7357, 'lng' => -74.1724],
            ['label' => 'USA Produce Boston',     'address' => '12 New England Produce Ctr, Chelsea, MA','lat' => 42.3996, 'lng' => -71.0383],
            ['label' => 'USA Produce Philadelphia','address' => '6700 Essington Ave, Philadelphia, PA','lat' => 39.8836, 'lng' => -75.2274],
        ],
    ],
];

$summary = ['vendors_touched' => 0, 'locations_inserted' => 0, 'locations_skipped' => 0, 'coverage_rows' => 0];

foreach ($dataset as $name => $info) {
    $vendor = $vendors->findByName($name);
    if (!$vendor) {
        echo "  ! $name not in vendors table — run scripts/seed-vendors-manual.php first\n";
        continue;
    }
    $vendorId = (string) $vendor['id'];
    $summary['vendors_touched']++;

    // Type backfill on the vendor row.
    if (empty($vendor['type'])) {
        $db->query('UPDATE vendors SET type = ? WHERE id = ?', [$info['type'], $vendorId]);
    }

    // Categories (idempotent via UNIQUE on (vendor_id, category)).
    foreach ($info['categories'] as $cat) $categories->attach($vendorId, $cat, 'chain_seed');

    // Existing locations to dedupe against by address.
    $existing = $locations->listForVendor($vendorId);
    $existingAddresses = array_map(fn($l) => strtolower(trim((string) ($l['address'] ?? ''))), $existing);

    foreach ($info['branches'] as $b) {
        $addrLower = strtolower(trim($b['address']));
        if (in_array($addrLower, $existingAddresses, true)) {
            $summary['locations_skipped']++;
            continue;
        }
        $locId = $locations->create($vendorId, [
            'label'      => $b['label'],
            'address'    => $b['address'],
            'lat'        => $b['lat'],
            'lng'        => $b['lng'],
            'is_primary' => false, // existing HQ from prior seed keeps is_primary=1
            'source'     => 'chain_seed',
        ]);
        $summary['locations_inserted']++;
        echo "  + $name :: {$b['label']}\n";
    }

    // Ensure each location has at least a radius-fallback coverage row.
    $summary['coverage_rows'] += $geometry->ensureCoverageForVendor($vendorId, $info['type']);
}

echo "\nDone.\n";
echo "  vendors touched:    {$summary['vendors_touched']}\n";
echo "  locations inserted: {$summary['locations_inserted']}\n";
echo "  locations skipped:  {$summary['locations_skipped']}\n";
echo "  coverage rows added: {$summary['coverage_rows']}\n";
echo "Verify: SELECT v.name, COUNT(vl.id) FROM vendors v LEFT JOIN vendor_locations vl ON vl.vendor_id = v.id GROUP BY v.id;\n";
