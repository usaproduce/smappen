<?php
declare(strict_types=1);

/**
 * Region loader of Truck Planner (docs/truck-planner/03_DATA.md section 10).
 *
 *     php scripts/truck/load-region.php --build=storage/truck/build/dc/<dataset_version> [--activate] [--dry-run]
 *     php scripts/truck/load-region.php --region=dc --activate=<dataset_version>
 *     php scripts/truck/load-region.php --region=dc --list
 *     php scripts/truck/load-region.php --region=dc --prune
 *
 * A build is what `node tools/truck-etl/bin/build-region.mjs` writes: manifest.json, points.tsv,
 * places.ndjson, cells.tsv and job_review.csv in one directory. Loading it fills tp_regions, tp_points,
 * tp_places and the ledger row with the cell pack in tp_region_packs, under the build's own dataset version.
 * The version that is live keeps serving until --activate switches, and the switch is one statement: to go
 * back, activate the previous version.
 *
 * Exit codes: 0 success, 1 usage or input/output error, 2 a failed check (the version stays `failed` and
 * is never activated).
 *
 * Run it with the PHP of the web tier (php8.3 on the server): the numbers of the pack come from this
 * process. Nothing here is scheduled.
 */

require __DIR__ . '/_bootstrap.php';

use App\TruckPlanner\Services\RegionLoader;

ini_set('memory_limit', '1024M');

$usage = <<<'TXT'
Usage:
  php scripts/truck/load-region.php --build=<dir> [--activate] [--dry-run]
  php scripts/truck/load-region.php --region=<id> --activate=<dataset_version>
  php scripts/truck/load-region.php --region=<id> --list
  php scripts/truck/load-region.php --region=<id> --prune

  --build=<dir>          the directory of one build: storage/truck/build/<region>/<dataset_version>
  --activate             with --build: make the version live when every check has passed, then prune
  --dry-run              with --build: read the files and run every check that needs no database; write nothing
  --region=<id>          the region the other forms act on
  --activate=<version>   make a loaded, ready version live (also the way back to the previous version)
  --list                 the loaded versions of the region
  --prune                delete every version of the region but the live and the previous one
TXT;

$options = tp_args(
    ['build' => 'value', 'activate' => 'optional', 'dry-run' => 'flag', 'region' => 'value', 'list' => 'flag', 'prune' => 'flag'],
    $usage
);

$activate = $options['activate'] ?? null;
if (isset($options['build'])) {
    if (isset($options['region']) || isset($options['list']) || isset($options['prune'])) {
        tp_usage('--build goes with --activate and --dry-run only', $usage);
    }
    if (is_string($activate)) {
        tp_usage('with --build, --activate takes no value: the version is the one of the build', $usage);
    }
} elseif (isset($options['region'])) {
    $forms = (int) is_string($activate) + (int) isset($options['list']) + (int) isset($options['prune']);
    if ($activate === true) {
        tp_usage('with --region, --activate needs the dataset version: --activate=<dataset_version>', $usage);
    }
    if ($forms !== 1 || isset($options['dry-run'])) {
        tp_usage('--region goes with exactly one of --activate=<dataset_version>, --list and --prune', $usage);
    }
} else {
    tp_usage('give --build=<dir> or --region=<id>', $usage);
}

exit((new RegionLoader())->run($options));
