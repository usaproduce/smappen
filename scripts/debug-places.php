<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\GoogleMapsService;
use App\Services\GeoUtils;

Config::load(dirname(__DIR__));

// Picks a recently-created area and runs the EXACT pipeline
// PlacesController::nearby uses, printing where results get filtered out.

$areaId = $argv[1] ?? null;
$db = Database::getInstance();
if (!$areaId) {
    $row = $db->fetch("SELECT id FROM areas WHERE area_type='isochrone' ORDER BY created_at DESC LIMIT 1");
    $areaId = $row['id'] ?? null;
    echo "No area_id arg — defaulting to most-recent isochrone: $areaId\n";
}
if (!$areaId) { echo "No areas in DB.\n"; exit(1); }

$area = \App\Models\Area::findById($areaId);
if (!$area) { echo "Area not found.\n"; exit(1); }

$lat = (float)$area['center_lat'];
$lng = (float)$area['center_lng'];
echo "Area: {$area['name']}  center=$lat,$lng\n";
echo "Geometry ring sample: ";
$ring = $area['geometry']['coordinates'][0] ?? [];
if ($ring) {
    echo json_encode($ring[0]) . " -> " . json_encode($ring[1] ?? null) . "\n";
} else {
    echo "(no ring)\n";
}

$svc = new GoogleMapsService();
$places = $svc->searchPlacesNearby($lat, $lng, 5000, 'restaurant');
echo "Google returned: " . count($places) . " places\n";
foreach (array_slice($places, 0, 3) as $p) {
    $loc = $p['location'] ?? null;
    $latP = $loc['latitude'] ?? null;
    $lngP = $loc['longitude'] ?? null;
    $name = $p['displayName']['text'] ?? '(no name)';
    echo "  - $name @ $latP, $lngP\n";
}

$polygon = $area['geometry'];
$kept = array_values(array_filter($places, function ($p) use ($polygon) {
    $loc = $p['location'] ?? null;
    if (!$loc) return false;
    return GeoUtils::pointInPolygon(
        (float)($loc['latitude'] ?? 0),
        (float)($loc['longitude'] ?? 0),
        $polygon
    );
}));
echo "After point-in-polygon filter: " . count($kept) . "\n";
if (count($kept) === 0 && count($places) > 0) {
    echo "FILTER DROPPED EVERYTHING.\n";
    echo "Try swapping arg order:\n";
    $alt = array_values(array_filter($places, function ($p) use ($polygon) {
        $loc = $p['location'] ?? null;
        if (!$loc) return false;
        return GeoUtils::pointInPolygon(
            (float)($loc['longitude'] ?? 0),
            (float)($loc['latitude'] ?? 0),
            $polygon
        );
    }));
    echo "  with (lng,lat) order: " . count($alt) . "\n";
}
