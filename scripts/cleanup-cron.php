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
    // Stuck-job sweeper: if a job's been "running" for >30 minutes it almost
    // certainly died (OOM, segfault, manual kill). Mark it failed so the UI
    // stops showing a perpetual spinner and the user can retry. 30min is
    // generous — the heaviest job (territory generation for a state) is
    // typically <60s under the new memory caps. set_time_limit(0) jobs that
    // legitimately run longer should not use the `jobs` table.
    $stuck = $db->pdo()->exec(
        "UPDATE jobs SET status = 'failed',
                         error_message = 'Killed by sweeper after 30min stuck running',
                         finished_at = NOW()
          WHERE status = 'running' AND started_at < NOW() - INTERVAL 30 MINUTE"
    );
    if ($stuck > 0) echo "Swept $stuck stuck-running jobs\n";
    // Same for territory_generation_jobs (parallel jobs table).
    try {
        $stuckTerr = $db->pdo()->exec(
            "UPDATE territory_generation_jobs SET status = 'failed',
                                                  error_message = 'Killed by sweeper after 30min stuck running',
                                                  finished_at = NOW()
              WHERE status = 'running' AND started_at < NOW() - INTERVAL 30 MINUTE"
        );
        if ($stuckTerr > 0) echo "Swept $stuckTerr stuck territory_generation_jobs\n";
    } catch (\Throwable $e) { /* table may not exist on older deploys */ }

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
