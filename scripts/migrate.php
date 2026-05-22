<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));

$db = Database::getInstance();

// Tracking table
$db->pdo()->exec("CREATE TABLE IF NOT EXISTS migrations (
    name VARCHAR(255) PRIMARY KEY,
    run_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$migrationsDir = __DIR__ . '/../src/Migrations';
$files = glob($migrationsDir . '/*.sql');
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    $exists = $db->fetch('SELECT name FROM migrations WHERE name = ?', [$name]);
    if ($exists) {
        echo "[skip] $name\n";
        continue;
    }
    echo "[run]  $name ... ";
    try {
        $sql = file_get_contents($file);
        // split on semicolons that end statements (naive but works for our schema)
        $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]/', $sql)));
        foreach ($statements as $stmt) {
            if ($stmt === '' || $stmt === ';') continue;
            $db->pdo()->exec($stmt);
        }
        $db->query('INSERT INTO migrations (name, run_at) VALUES (?, ?)', [$name, date('Y-m-d H:i:s')]);
        echo "ok\n";
    } catch (\Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "All migrations applied.\n";
