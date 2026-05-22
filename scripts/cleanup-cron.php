<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;

Config::load(dirname(__DIR__));
$db = Database::getInstance();
$base = dirname(__DIR__);

$expired = $db->pdo()->exec('DELETE FROM cache WHERE expires_at IS NOT NULL AND expires_at < NOW()');
echo "Cleared $expired expired cache rows\n";

$exportDir = $base . '/storage/exports';
$cutoff = time() - 3600;
$exportsDeleted = 0;
if (is_dir($exportDir)) {
    foreach (glob($exportDir . '/*') as $f) {
        if (is_file($f) && filemtime($f) < $cutoff) {
            unlink($f); $exportsDeleted++;
        }
    }
}
echo "Deleted $exportsDeleted old export files\n";

$uploadDir = $base . '/storage/uploads';
$uploadCutoff = time() - 86400;
$uploadsDeleted = 0;
if (is_dir($uploadDir)) {
    foreach (glob($uploadDir . '/*') as $f) {
        if (is_file($f) && filemtime($f) < $uploadCutoff) {
            unlink($f); $uploadsDeleted++;
        }
    }
}
echo "Deleted $uploadsDeleted orphan upload files\n";
