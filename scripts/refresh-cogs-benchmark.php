<?php
declare(strict_types=1);

/**
 * Carafe — nightly pull of the GreenDock-published COGS feed.
 * Silent no-op when COGS_FEED_URL / COGS_FEED_KEY aren't set (stub mode).
 *
 *   0 5 * * * php /var/www/smappen/scripts/refresh-cogs-benchmark.php >> /var/www/smappen/storage/logs/cogs.log 2>&1
 *
 * One-shot manual run: php scripts/refresh-cogs-benchmark.php
 * Optional since arg:  php scripts/refresh-cogs-benchmark.php 2026-05-01
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\CogsBenchmarkService;

Config::load(dirname(__DIR__));

$since = $argv[1] ?? null;
$svc = new CogsBenchmarkService();
$res = $svc->ingest($since);
echo "[" . date('c') . "] cogs-benchmark refresh: " . json_encode($res) . "\n";
