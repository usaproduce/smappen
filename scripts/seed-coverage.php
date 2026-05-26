<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\MarketData\VendorCoverageRepository;
use App\MarketData\VendorLocationRepository;
use App\Services\VendorGeometryService;

/**
 * Carafe coverage-geometry worker. Spec v3 §4.5 + §9 step 9.
 *
 * For every vendor lacking coverage geometry, derive one:
 *
 *   - Delivery / wholesale types  → ORS drive-time isochrone (60–90 min)
 *   - cash_carry / warehouse      → 30-min isochrone
 *   - local_grocery / smallwares  → radius fallback
 *   - On ANY ORS failure          → radius fallback (so a single
 *                                   ORS outage doesn't leave the
 *                                   vendor permanently uncovered)
 *
 * After coverage exists, optionally simplify (Douglas-Peucker at three
 * tolerances) for the vector-tile pipeline (§12.5).
 *
 * Args:
 *   --batch-size=N      max vendors per invocation (default 200)
 *   --simplify-only     skip ensureCoverage; just run the simplifier
 *                       on already-existing rows
 *   --quiet
 */

Config::load(dirname(__DIR__));
ini_set('memory_limit', '512M');
set_time_limit(0);

$opts        = getopt('', ['batch-size::', 'simplify-only', 'quiet']);
$batchSize   = max(1, (int) ($opts['batch-size'] ?? 200));
$simplifyOnly= array_key_exists('simplify-only', $opts);
$quiet       = array_key_exists('quiet', $opts);

$svc = new VendorGeometryService(
    new VendorLocationRepository(),
    new VendorCoverageRepository(),
);

$started = microtime(true);

if (!$simplifyOnly) {
    $db = Database::getInstance();
    // Vendors with at least one primary location but no coverage row yet.
    $rows = $db->fetchAll(
        "SELECT v.id, v.type
         FROM vendors v
         JOIN vendor_locations vl ON vl.vendor_id = v.id AND vl.is_primary = 1
         LEFT JOIN vendor_coverage vc ON vc.vendor_id = v.id
         WHERE v.merged_into IS NULL
           AND vc.id IS NULL
         GROUP BY v.id
         LIMIT ?",
        [$batchSize]
    );
    $covered = 0;
    foreach ($rows as $r) {
        try {
            $n = $svc->ensureCoverageForVendor((string) $r['id'], (string) ($r['type'] ?? ''));
            $covered += $n;
        } catch (\Throwable $e) {
            error_log('[seed-coverage] vendor ' . $r['id'] . ': ' . $e->getMessage());
        }
    }
    if (!$quiet) {
        $elapsed = round(microtime(true) - $started, 1);
        echo "Coverage created for $covered location(s) across " . count($rows) . " vendor(s) in {$elapsed}s\n";
    }
}

$simStart = microtime(true);
$simplified = $svc->simplifyPending($batchSize);
if (!$quiet) {
    $elapsed = round(microtime(true) - $simStart, 1);
    echo "Simplified $simplified coverage row(s) in {$elapsed}s\n";
}
