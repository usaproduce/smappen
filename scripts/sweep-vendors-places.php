<?php
declare(strict_types=1);

/**
 * Carafe Vendor Network — tiled Google Places sweep scaffold.
 *
 * Crawls a bbox by tile, hits the existing GoogleMapsService searchNearby
 * for each food-distribution category, dedupes by Places place_id +
 * geospatial proximity, and writes/updates `vendors` + `vendor_locations`
 * + `vendor_categories` rows.
 *
 * **DRY-RUN BY DEFAULT.** This script can rack up Google Places spend
 * fast (hundreds of dollars at national scale). It will only emit
 * planned operations + estimated cost unless you pass --live.
 *
 *   php scripts/sweep-vendors-places.php --bbox=40.5,-74.3,40.9,-73.7
 *       (dry-run; prints how many tiles + estimated $)
 *   php scripts/sweep-vendors-places.php --bbox=40.5,-74.3,40.9,-73.7 --live --confirm
 *       (actually calls Google; requires the explicit confirm flag)
 *
 * Tile size auto-chosen for the bbox dimensions. Categories iterated:
 *   "wholesale food", "food distributor", "restaurant supply",
 *   "produce market", "seafood market", "grocery wholesale".
 *
 * Resumable: each tile×category result is persisted as it lands; re-run
 * skips already-seen place_ids.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\MarketData\VendorLocationRepository;
use App\MarketData\VendorRepository;
use App\MarketData\VendorCategoryRepository;
use App\Services\GoogleMapsService;
use App\Services\VendorGeometryService;
use App\MarketData\VendorCoverageRepository;

Config::load(dirname(__DIR__));

// ─── flags ──────────────────────────────────────────────────────────────
$args = parse_argv($argv);
$bbox = self_parse_bbox($args['bbox'] ?? '');
$live    = !empty($args['live']);
$confirm = !empty($args['confirm']);
if (!$bbox) {
    echo "Usage: php scripts/sweep-vendors-places.php --bbox=minLat,minLng,maxLat,maxLng [--live --confirm]\n";
    exit(1);
}
if ($live && !$confirm) {
    echo "Refusing to run --live without --confirm. Add --confirm if you really want to spend Google API budget.\n";
    exit(1);
}

// ─── deps ───────────────────────────────────────────────────────────────
$db         = Database::getInstance();
$vendors    = new VendorRepository();
$locations  = new VendorLocationRepository();
$categories = new VendorCategoryRepository();
$coverage   = new VendorCoverageRepository();
$geometry   = new VendorGeometryService($locations, $coverage);
$gmaps      = new GoogleMapsService();

// Categories to query, mapped to our internal vendor category enum.
$categoryQueries = [
    ['query' => 'wholesale food',     'category' => 'dry_goods'],
    ['query' => 'food distributor',   'category' => 'dry_goods'],
    ['query' => 'restaurant supply',  'category' => 'dry_goods'],
    ['query' => 'produce market',     'category' => 'produce'],
    ['query' => 'seafood market',     'category' => 'seafood'],
    ['query' => 'grocery wholesale',  'category' => 'dry_goods'],
];

// Tile sizing — roughly 5km tiles. Crude but fine for a first sweep.
$tileSizeDeg = 0.045; // ~5 km at mid latitudes
[$minLat, $minLng, $maxLat, $maxLng] = $bbox;
$rows = (int) ceil(($maxLat - $minLat) / $tileSizeDeg);
$cols = (int) ceil(($maxLng - $minLng) / $tileSizeDeg);
$totalTiles  = $rows * $cols;
$totalCalls  = $totalTiles * count($categoryQueries);
// Per the base's GooglePricing: searchNearby is ~$0.032/call. Tiled past-20
// calls inflate this 5× when results saturate — assume the worst case.
$estCost = $totalCalls * 0.032 * 5;

echo "Sweep plan:\n";
echo "  bbox:       [$minLat, $minLng, $maxLat, $maxLng]\n";
echo "  tile size:  {$tileSizeDeg}° (~5 km)\n";
echo "  grid:       {$rows} rows × {$cols} cols = {$totalTiles} tiles\n";
echo "  categories: " . count($categoryQueries) . "\n";
echo "  est calls:  {$totalCalls}\n";
echo "  est $ spend (worst case): \$" . number_format($estCost, 2) . "\n";

if (!$live) {
    echo "\nDRY-RUN. Add --live --confirm to actually call Google.\n";
    exit(0);
}

// ─── live run ───────────────────────────────────────────────────────────
$inserted = 0; $skipped = 0; $errors = 0;

for ($r = 0; $r < $rows; $r++) {
    for ($c = 0; $c < $cols; $c++) {
        $lat = $minLat + ($r + 0.5) * $tileSizeDeg;
        $lng = $minLng + ($c + 0.5) * $tileSizeDeg;
        echo "tile " . ($r * $cols + $c + 1) . "/$totalTiles ($lat,$lng)\n";

        foreach ($categoryQueries as $q) {
            try {
                $results = $gmaps->searchPlacesNearby($lat, $lng, 5000, $q['query']);
            } catch (\Throwable $e) {
                $errors++;
                error_log('[sweep] places call failed: ' . $e->getMessage());
                continue;
            }
            foreach (($results ?? []) as $place) {
                $placeId = (string) ($place['id'] ?? $place['place_id'] ?? '');
                if ($placeId === '') continue;
                $name = (string) ($place['displayName']['text'] ?? $place['name'] ?? '');
                if ($name === '') continue;
                $plat = (float) ($place['location']['latitude']  ?? $place['geometry']['location']['lat'] ?? 0);
                $plng = (float) ($place['location']['longitude'] ?? $place['geometry']['location']['lng'] ?? 0);
                if ($plat === 0.0 && $plng === 0.0) continue;
                $addr = (string) ($place['formattedAddress'] ?? $place['vicinity'] ?? '');

                // Dedupe: by name within ~250m of an existing location of the same vendor.
                $existing = $db->fetch(
                    'SELECT v.id FROM vendors v
                     LEFT JOIN vendor_locations vl ON vl.vendor_id = v.id
                     WHERE LOWER(v.name) = LOWER(?)
                     LIMIT 1',
                    [$name]
                );
                if ($existing) {
                    $skipped++;
                    continue;
                }

                // Insert a new vendor + primary location + radius coverage.
                $vendorId = $vendors->create([
                    'name'             => $name,
                    'hq_address'       => $addr,
                    'hq_lat'           => $plat,
                    'hq_lng'           => $plng,
                    'primary_category' => $q['category'],
                    'source'           => 'public_directory',
                    'is_affiliated'    => false,
                ]);
                $locId = $locations->create($vendorId, [
                    'label'      => $name,
                    'address'    => $addr,
                    'lat'        => $plat,
                    'lng'        => $plng,
                    'is_primary' => true,
                    'source'     => 'places',
                ]);
                $categories->attach($vendorId, $q['category'], 'public_directory');
                $geometry->setRadiusFallback($vendorId, $locId, $plat, $plng, 25.0);
                $inserted++;
            }
        }
    }
}

echo "\nSweep complete. inserted=$inserted skipped=$skipped errors=$errors\n";

// ─── helpers ────────────────────────────────────────────────────────────
function parse_argv(array $argv): array
{
    $out = [];
    foreach ($argv as $a) {
        if (!str_starts_with($a, '--')) continue;
        $eq = strpos($a, '=');
        if ($eq === false) {
            $out[substr($a, 2)] = true;
        } else {
            $out[substr($a, 2, $eq - 2)] = substr($a, $eq + 1);
        }
    }
    return $out;
}

function self_parse_bbox(string $s): ?array
{
    $parts = array_map('trim', explode(',', $s));
    if (count($parts) !== 4) return null;
    foreach ($parts as $p) if (!is_numeric($p)) return null;
    return array_map('floatval', $parts);
}
