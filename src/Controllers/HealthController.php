<?php
namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * GET /api/health
 * Public — used by uptime monitors, deploy scripts, and the in-app banner.
 *
 * Output:
 *   { ok: true, db: true, version: "<git sha>", uptime_seconds: <process age> }
 *
 * Side effects: none. The DB check is `SELECT 1` so a query-cache hit makes
 * this effectively free.
 */
class HealthController
{
    public function show(Request $request): void
    {
        $started = microtime(true);
        $dbOk = false;
        $dbError = null;
        $connections = ['current' => null, 'max' => null];
        try {
            Database::getInstance()->fetch('SELECT 1 AS ok');
            $dbOk = true;
            // Expose MySQL connection pressure so external monitors can alert
            // before the ceiling. SHOW STATUS / SHOW VARIABLES need no perms
            // beyond what the app user already has.
            try {
                $cur = Database::getInstance()->fetch("SHOW STATUS LIKE 'Threads_connected'");
                $max = Database::getInstance()->fetch("SHOW VARIABLES LIKE 'max_connections'");
                $connections['current'] = isset($cur['Value']) ? (int) $cur['Value'] : null;
                $connections['max'] = isset($max['Value']) ? (int) $max['Value'] : null;
            } catch (\Throwable $_) {}
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }
        $version = self::gitSha();
        $payload = [
            'ok' => $dbOk,
            'db' => $dbOk,
            'version' => $version,
            'environment' => Config::isDevelopment() ? 'development' : 'production',
            'now' => date('c'),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'connections' => $connections,
            'pool' => ['persistent' => Database::getInstance()->isPersistent()],
        ];
        if ($dbError && Config::isDevelopment()) {
            $payload['db_error'] = $dbError;
        }
        // 503 if the DB check failed so monitoring tools auto-page instead of
        // requiring custom alerting on a 200/db:false combo.
        Response::json($payload, $dbOk ? 200 : 503);
    }

    /** Resolve git SHA without shelling out — read .git/HEAD ourselves. */
    private static function gitSha(): string
    {
        $base = dirname(__DIR__, 2);
        $cached = $base . '/storage/version.txt';
        if (is_file($cached)) {
            $v = trim((string) file_get_contents($cached));
            if ($v !== '') return $v;
        }
        $head = $base . '/.git/HEAD';
        if (!is_file($head)) return 'unknown';
        $contents = trim((string) file_get_contents($head));
        if (str_starts_with($contents, 'ref:')) {
            $ref = trim(substr($contents, 4));
            $refFile = $base . '/.git/' . $ref;
            if (is_file($refFile)) return substr(trim((string) file_get_contents($refFile)), 0, 7);
        }
        return substr($contents, 0, 7);
    }
}
