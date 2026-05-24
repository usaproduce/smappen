<?php
namespace App\Core;

use Monolog\Logger as MLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\UidProcessor;
use Monolog\Processor\WebProcessor;

/**
 * Centralized logging. Monolog with JSON formatter + rotating file handler
 * (14-day retention). Each entry carries a per-request UID so cross-file
 * correlation works during incident triage.
 *
 * Falls back to error_log() if Monolog isn't installed (dev hot-reload, etc.)
 * so logging never throws.
 */
class Logger
{
    private static ?MLogger $instance = null;

    public static function get(): MLogger
    {
        if (self::$instance) return self::$instance;
        if (!class_exists(MLogger::class)) {
            // Stub if monolog isn't installed — emit to error_log via a tiny adapter.
            return self::$instance = self::stub();
        }
        $base = dirname(__DIR__, 2);
        $logDir = $base . '/storage/logs';
        if (!is_dir($logDir)) @mkdir($logDir, 0775, true);

        $logger = new MLogger('smappen');
        $isDev = Config::isDevelopment();
        $level = $isDev ? MLogger::DEBUG : MLogger::INFO;

        $rotating = new RotatingFileHandler($logDir . '/app.log', 14, $level);
        $rotating->setFormatter(new JsonFormatter());
        $logger->pushHandler($rotating);

        // Errors and above also go to a separate file with full context, so
        // someone tailing for fires doesn't drown in INFO/DEBUG noise.
        $errFile = new RotatingFileHandler($logDir . '/error.log', 30, MLogger::ERROR);
        $errFile->setFormatter(new JsonFormatter());
        $logger->pushHandler($errFile);

        // Stderr in dev so `vite dev` + `php -S` show structured logs inline.
        if ($isDev) {
            $logger->pushHandler(new StreamHandler('php://stderr', MLogger::DEBUG));
        }

        $logger->pushProcessor(new UidProcessor());
        if (php_sapi_name() !== 'cli') {
            $logger->pushProcessor(new WebProcessor());
        }
        return self::$instance = $logger;
    }

    private static function stub(): MLogger
    {
        // Build a no-deps Monolog-like façade if the package is missing.
        return new class extends MLogger {
            public function __construct() { /* no parent init */ }
            public function info($msg, array $context = []): void { error_log('[info] ' . $msg . ' ' . json_encode($context)); }
            public function warning($msg, array $context = []): void { error_log('[warn] ' . $msg . ' ' . json_encode($context)); }
            public function error($msg, array $context = []): void { error_log('[error] ' . $msg . ' ' . json_encode($context)); }
            public function debug($msg, array $context = []): void {}
        };
    }
}
