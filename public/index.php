<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

$basePath = dirname(__DIR__);
Config::load($basePath);

if (Config::isDevelopment()) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}
ini_set('log_errors', '1');
ini_set('error_log', $basePath . '/storage/logs/php-error.log');

// Security headers — applied to every response. Embed pages disable the
// frame-ancestors restriction (they're meant to be iframed); everything
// else stays clickjacking-protected.
$isEmbed = str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/public/');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
if ($isEmbed) {
    header("Content-Security-Policy: frame-ancestors *");
} else {
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    Response::corsHeaders();
    http_response_code(204);
    exit;
}

try {
    $request = new Request();
    $router = new Router();
    $routes = require $basePath . '/config/routes.php';
    $routes($router);
    $router->dispatch($request);
} catch (\Throwable $e) {
    error_log('[smappen] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    if (Config::isDevelopment()) {
        Response::error($e->getMessage(), 500, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);
    } else {
        Response::error('Internal server error', 500);
    }
}
