<?php
declare(strict_types=1);

/**
 * Seed the "Demo: Downtown Chicago" sample project.
 *
 * OnboardingController::cloneSample() looks for projects.is_sample = 1. This
 * script creates that row + four pre-built areas + a folder so first-run
 * users can clone something interesting into their workspace in one click.
 *
 * Idempotent: bails out if a sample project already exists. Re-run only after
 * `DELETE FROM projects WHERE is_sample = 1` (cascades to areas/folders via FK).
 *
 * Run on the droplet: php scripts/seed-sample-project.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));
$db = Database::getInstance();

$existing = $db->fetch('SELECT id, name FROM projects WHERE is_sample = 1 LIMIT 1');
if ($existing) {
    echo "Sample project already exists: {$existing['id']} ({$existing['name']})\n";
    echo "To re-seed: DELETE FROM projects WHERE is_sample = 1;\n";
    exit(0);
}

$org = $db->fetch("SELECT id FROM organizations WHERE name = 'System' LIMIT 1");
if ($org) {
    $orgId = $org['id'];
    echo "Using existing System organization: $orgId\n";
} else {
    $orgId = Database::uuid();
    $db->query(
        'INSERT INTO organizations (id, name, plan, max_seats, created_at, updated_at)
         VALUES (?, ?, ?, ?, NOW(), NOW())',
        [$orgId, 'System', 'enterprise', 0]
    );
    echo "Created System organization: $orgId\n";
}

$projectId = Database::uuid();
$db->query(
    'INSERT INTO projects (id, organization_id, name, description, center_lat, center_lng, zoom_level, is_sample, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())',
    [
        $projectId, $orgId,
        'Demo: Downtown Chicago',
        'Explore drive-time areas, demographics, and competitor data around downtown Chicago.',
        41.8781, -87.6298, 12,
    ]
);
echo "Created sample project: $projectId\n";

$folderId = Database::uuid();
$db->query(
    'INSERT INTO folders (id, project_id, name, color, sort_order, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
    [$folderId, $projectId, 'Chicago Areas', '#7848BB', 0]
);
echo "Created folder: Chicago Areas\n";

// Areas. WKT axis order is (lat lng) — matches the project's X=lat, Y=lng
// SRID 4326 storage convention (see AUDIT.md axis-order fix). Polygons are
// hand-approximated and meant for demo purposes, not real isochrone shapes.
$areas = [
    [
        'name'        => 'The Loop — 10 min drive',
        'area_type'   => 'isochrone',
        'center_lat'  => 41.8819,
        'center_lng'  => -87.6278,
        'travel_mode' => 'driving-car',
        'travel_time' => 10,
        'fill_color'  => '#7848BB',
        'folder'      => $folderId,
        'wkt'         => 'POLYGON((41.9050 -87.6550, 41.9050 -87.6000, 41.8900 -87.5850, 41.8650 -87.5850, 41.8500 -87.6000, 41.8500 -87.6550, 41.8650 -87.6700, 41.8900 -87.6700, 41.9050 -87.6550))',
    ],
    [
        'name'        => 'Wicker Park — 15 min drive',
        'area_type'   => 'isochrone',
        'center_lat'  => 41.9088,
        'center_lng'  => -87.6796,
        'travel_mode' => 'driving-car',
        'travel_time' => 15,
        'fill_color'  => '#2196F3',
        'folder'      => $folderId,
        'wkt'         => 'POLYGON((41.9400 -87.7150, 41.9400 -87.6400, 41.9200 -87.6200, 41.8900 -87.6200, 41.8700 -87.6400, 41.8700 -87.7150, 41.8900 -87.7350, 41.9200 -87.7350, 41.9400 -87.7150))',
    ],
    [
        'name'        => 'Hyde Park — 10 min walk',
        'area_type'   => 'isochrone',
        'center_lat'  => 41.7943,
        'center_lng'  => -87.5907,
        'travel_mode' => 'foot-walking',
        'travel_time' => 10,
        'fill_color'  => '#4CAF50',
        'folder'      => $folderId,
        'wkt'         => 'POLYGON((41.8000 -87.5980, 41.7990 -87.5850, 41.7950 -87.5820, 41.7900 -87.5820, 41.7880 -87.5850, 41.7880 -87.5980, 41.7900 -87.6010, 41.7950 -87.6010, 41.8000 -87.5980))',
    ],
    [
        'name'        => "O'Hare Radius — 5 mi",
        'area_type'   => 'radius',
        'center_lat'  => 41.9742,
        'center_lng'  => -87.9073,
        'travel_mode' => null,
        'travel_time' => null,
        'fill_color'  => '#FF9800',
        'folder'      => null,
        'wkt'         => 'POLYGON((42.0465 -87.9073, 42.0376 -87.8517, 42.0103 -87.8087, 41.9742 -87.7920, 41.9381 -87.8087, 41.9108 -87.8517, 41.9019 -87.9073, 41.9108 -87.9629, 41.9381 -88.0059, 41.9742 -88.0226, 42.0103 -88.0059, 42.0376 -87.9629, 42.0465 -87.9073))',
    ],
];

foreach ($areas as $a) {
    $areaId = Database::uuid();
    $db->query(
        'INSERT INTO areas
            (id, project_id, name, area_type, geometry, fill_color, stroke_color,
             fill_opacity, stroke_weight, center_lat, center_lng,
             travel_mode, travel_time_minutes, folder_id, created_at, updated_at)
         VALUES (?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, 0.30, 2, ?, ?, ?, ?, ?, NOW(), NOW())',
        [
            $areaId, $projectId, $a['name'], $a['area_type'], $a['wkt'],
            $a['fill_color'], $a['fill_color'],
            $a['center_lat'], $a['center_lng'],
            $a['travel_mode'], $a['travel_time'], $a['folder'],
        ]
    );
    echo "  + {$a['name']}\n";
}

echo "\nSample project seeded.\n";
echo "Verify: SELECT id, name, is_sample FROM projects WHERE is_sample = 1;\n";
