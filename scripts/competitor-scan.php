<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\CompetitorScanner;

Config::load(dirname(__DIR__));

$db = Database::getInstance();
// MySQL: emulate NULLS FIRST via (col IS NULL) sort.
$due = $db->fetchAll(
    "SELECT * FROM competitor_monitors
     WHERE is_active = 1 AND (next_run_at IS NULL OR next_run_at <= NOW())
     ORDER BY (next_run_at IS NULL) DESC, next_run_at ASC
     LIMIT 50"
);

if (empty($due)) {
    echo "No monitors due.\n";
    exit(0);
}

$scanner = new CompetitorScanner();
foreach ($due as $m) {
    echo "Scanning monitor {$m['id']} ({$m['name']})...\n";
    try {
        $s = $scanner->scan($m);
        echo "  places={$s['place_count']} new={$s['new_count']} gone={$s['gone_count']} moved={$s['moved_count']} ratings={$s['rating_change_count']}\n";
    } catch (\Throwable $e) {
        echo "  FAILED: " . $e->getMessage() . "\n";
        error_log('[competitor-scan] ' . $e->getMessage());
    }
}
echo "Done.\n";
