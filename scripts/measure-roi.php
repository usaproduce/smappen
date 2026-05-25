<?php
declare(strict_types=1);

/**
 * Carafe ROI ledger — measure accepted recommendations against pos_sales.
 * Runs nightly. Promotes status accepted → measured for any rec that
 * has >=14 days of post-decision data.
 *
 *   0 4 * * * php /var/www/smappen/scripts/measure-roi.php >> /var/www/smappen/storage/logs/roi.log 2>&1
 *
 * Manual run: php scripts/measure-roi.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\PrivateData\PosSalesRepository;
use App\PrivateData\RecommendationRepository;
use App\Services\RoiService;

Config::load(dirname(__DIR__));

$startedAt = date('c');
echo "[$startedAt] measure-roi start\n";
$svc = new RoiService(new RecommendationRepository(), new PosSalesRepository());
$count = $svc->measurePending();
echo "  measured: $count\n";
echo "[" . date('c') . "] done\n";
