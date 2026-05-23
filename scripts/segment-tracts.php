<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Services\SegmentationService;

Config::load(dirname(__DIR__));

$svc = new SegmentationService();
$start = microtime(true);
$n = $svc->recomputeAll(function (int $done, int $total) {
    echo "  classified {$done}/{$total}\n";
});
$elapsed = round(microtime(true) - $start, 2);
echo "Done. Classified {$n} tracts in {$elapsed}s.\n";
