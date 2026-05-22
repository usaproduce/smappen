<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
Config::load(dirname(__DIR__));

$baseUrl = rtrim((string)(Config::get('APP_URL') ?? 'http://localhost:8080'), '/');

$pass = 0; $fail = 0;
$token = null;
$projectId = null;
$folderId = null;
$areaId = null;
$batchId = null;

function color(string $text, string $c): string {
    $codes = ['green' => '32', 'red' => '31', 'cyan' => '36', 'yellow' => '33'];
    return "\033[" . ($codes[$c] ?? '0') . "m" . $text . "\033[0m";
}

function request(string $method, string $path, array $body = [], ?string $token = null, array $extraHeaders = []): array {
    global $baseUrl;
    $ch = curl_init($baseUrl . $path);
    $headers = ['Accept: application/json'];
    if ($body) $headers[] = 'Content-Type: application/json';
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    $headers = array_merge($headers, $extraHeaders);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body ? json_encode($body) : null,
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$resp, true) ?: ['raw' => $resp]];
}

function expect(string $label, bool $cond, $detail = null): void {
    global $pass, $fail;
    if ($cond) { echo color("  ✔ $label\n", 'green'); $pass++; }
    else {
        echo color("  ✘ $label\n", 'red');
        if ($detail !== null) echo "    " . (is_string($detail) ? $detail : json_encode($detail)) . "\n";
        $fail++;
    }
}

echo color("=== AUTH ===\n", 'cyan');
$email = 'test+' . time() . '@example.com';
[$code, $r] = request('POST', '/api/auth/register', ['email' => $email, 'password' => 'password123', 'name' => 'Test User']);
expect("register returns 201", $code === 201, $r);
$token = $r['data']['token'] ?? null;
expect("register returns token", !empty($token));

[$code, $r] = request('POST', '/api/auth/login', ['email' => $email, 'password' => 'password123']);
expect("login returns 200", $code === 200);
$token = $r['data']['token'] ?? $token;

[$code, $r] = request('GET', '/api/auth/me', [], $token);
expect("me with token = 200", $code === 200);

[$code, $r] = request('GET', '/api/auth/me');
expect("me without token = 401", $code === 401);

echo color("=== PROJECTS ===\n", 'cyan');
[$code, $r] = request('POST', '/api/projects', ['name' => 'Test Project'], $token);
expect("create project = 201", $code === 201, $r);
$projectId = $r['data']['id'] ?? null;

[$code, $r] = request('GET', '/api/projects', [], $token);
expect("list projects = 200", $code === 200);

[$code, $r] = request('GET', "/api/projects/$projectId", [], $token);
expect("get project = 200", $code === 200);

[$code, $r] = request('PUT', "/api/projects/$projectId", ['name' => 'Updated'], $token);
expect("update project = 200", $code === 200);

echo color("=== FOLDERS ===\n", 'cyan');
[$code, $r] = request('POST', "/api/projects/$projectId/folders", ['name' => 'Test Folder'], $token);
expect("create folder = 201", $code === 201, $r);
$folderId = $r['data']['id'] ?? null;

echo color("=== ISOCHRONE ===\n", 'cyan');
[$code, $r] = request('POST', '/api/isochrone/calculate', [
    'lat' => 38.9072, 'lng' => -77.0369, 'time_minutes' => 15, 'travel_mode' => 'driving-car',
], $token);
if ($code === 502) {
    echo color("  ⚠ ORS not configured — skipping isochrone-dependent tests\n", 'yellow');
} else {
    expect("calculate isochrone = 200", $code === 200, $r);
    $geo = $r['data']['geojson'] ?? null;

    echo color("=== AREAS ===\n", 'cyan');
    [$code, $r] = request('POST', "/api/projects/$projectId/areas", [
        'name' => 'DC 15min', 'area_type' => 'isochrone',
        'center_lat' => 38.9072, 'center_lng' => -77.0369,
        'travel_mode' => 'driving-car', 'travel_time_minutes' => 15,
        'geometry' => $geo,
    ], $token);
    expect("create area = 201", $code === 201, $r);
    $areaId = $r['data']['id'] ?? null;

    [$code, $r] = request('GET', "/api/projects/$projectId/areas", [], $token);
    expect("list areas = 200", $code === 200);

    [$code, $r] = request('GET', "/api/areas/$areaId", [], $token);
    expect("get area = 200", $code === 200);

    [$code, $r] = request('PUT', "/api/areas/$areaId", ['name' => 'Renamed', 'fill_color' => '#22c55e'], $token);
    expect("update area = 200", $code === 200);

    echo color("=== DEMOGRAPHICS ===\n", 'cyan');
    [$code, $r] = request('GET', "/api/areas/$areaId/demographics", [], $token);
    expect("demographics = 200", $code === 200);
}

echo color("=== GEOCODING ===\n", 'cyan');
[$code, $r] = request('POST', '/api/geocode', ['address' => '1600 Pennsylvania Ave, Washington DC'], $token);
if ($code === 502) echo color("  ⚠ Google API not configured\n", 'yellow');
else expect("geocode = 200", $code === 200);

echo color("=== PLACES ===\n", 'cyan');
[$code, $r] = request('POST', '/api/places/nearby', [
    'lat' => 38.9072, 'lng' => -77.0369, 'radius_meters' => 5000, 'type' => 'restaurant',
], $token);
if ($code === 502) echo color("  ⚠ Google API not configured\n", 'yellow');
else expect("places nearby = 200", $code === 200);

echo color("=== EXPORTS ===\n", 'cyan');
[$code, $r] = request('GET', "/api/projects/$projectId/export/areas?format=csv", [], $token);
expect("export areas csv = 200", $code === 200);

echo color("=== CLEANUP ===\n", 'cyan');
if ($areaId) {
    [$code, $r] = request('DELETE', "/api/areas/$areaId", [], $token);
    expect("delete area = 200", $code === 200);
}
[$code, $r] = request('DELETE', "/api/projects/$projectId", [], $token);
expect("delete project = 200", $code === 200);

echo "\n" . color("SUMMARY: $pass passed, $fail failed\n", $fail ? 'red' : 'green');
exit($fail > 0 ? 1 : 0);
