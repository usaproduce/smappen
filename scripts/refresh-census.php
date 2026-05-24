<?php
declare(strict_types=1);
/**
 * Annual Census refresh — run from cron in mid-January so ACS 5-year vintage N
 * is available (Census publishes early December).
 *
 *   crontab:  0 4 15 1 * /usr/bin/php /var/www/smappen/scripts/refresh-census.php
 *
 * Runs the seed → aggregate → segment pipeline in sequence. Logs to
 * storage/logs/census-refresh.log so failures don't disappear into stderr.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;

Config::load(dirname(__DIR__));

$base = dirname(__DIR__);
$logDir = $base . '/storage/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
$logFile = $logDir . '/census-refresh.log';

function logMsg(string $logFile, string $msg): void
{
    $line = '[' . date('c') . '] ' . $msg . "\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

$steps = [
    'seed' => 'seed-census.php',
    'aggregate' => 'aggregate-geographies.php',
    'segment' => 'segment-tracts.php',
];
$failed = false;
foreach ($steps as $key => $script) {
    $path = __DIR__ . '/' . $script;
    if (!is_file($path)) {
        logMsg($logFile, "SKIP $key: $script not found");
        continue;
    }
    $start = microtime(true);
    logMsg($logFile, "BEGIN $key ($script)");
    $exit = 0;
    passthru('php ' . escapeshellarg($path), $exit);
    $elapsed = round(microtime(true) - $start, 1);
    if ($exit !== 0) {
        $failed = true;
        logMsg($logFile, "FAIL $key (exit=$exit, elapsed={$elapsed}s)");
        break;
    }
    logMsg($logFile, "OK $key (elapsed={$elapsed}s)");
}
if ($failed) {
    logMsg($logFile, 'Refresh aborted with failure');
    exit(1);
}
logMsg($logFile, 'Refresh completed successfully');
