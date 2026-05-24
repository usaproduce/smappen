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

// B38: expired one-shot auth tokens (password reset, email verify) and
// revoked-JWT rows. Without this they accumulate forever — auth_tokens at
// 100 resets/day would still fit, but revoked_tokens grows per logout.
try {
    $authExpired = $db->pdo()->exec('DELETE FROM auth_tokens WHERE expires_at < NOW() OR used_at IS NOT NULL');
    echo "Cleared $authExpired expired/used auth_tokens rows\n";
} catch (\Throwable $e) { echo "auth_tokens cleanup skipped: " . $e->getMessage() . "\n"; }
try {
    $revoked = $db->pdo()->exec('DELETE FROM revoked_tokens WHERE expires_at < NOW()');
    echo "Cleared $revoked expired revoked_tokens rows\n";
} catch (\Throwable $e) { echo "revoked_tokens cleanup skipped: " . $e->getMessage() . "\n"; }
try {
    // Job rows: keep done/failed for 30 days, then drop. cancelled too.
    $oldJobs = $db->pdo()->exec("DELETE FROM jobs WHERE status IN ('done','failed','cancelled') AND finished_at < NOW() - INTERVAL 30 DAY");
    echo "Cleared $oldJobs old job rows\n";
} catch (\Throwable $e) { echo "jobs cleanup skipped: " . $e->getMessage() . "\n"; }
try {
    // Webhook deliveries: keep 30 days too.
    $oldDel = $db->pdo()->exec("DELETE FROM webhook_deliveries WHERE created_at < NOW() - INTERVAL 30 DAY");
    echo "Cleared $oldDel old webhook_deliveries rows\n";
} catch (\Throwable $e) { echo "webhook_deliveries cleanup skipped: " . $e->getMessage() . "\n"; }

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
