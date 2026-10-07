<?php
declare(strict_types=1);

/**
 * Recomputes the stored vectors of saved spots (docs/truck-planner/04_BACKEND.md 7.2).
 *
 *     php scripts/truck/refresh-spots.php [--region=<id>] [--org=<organization id>] [--dry-run]
 *
 * A saved spot keeps the location vectors that were computed for it, labelled with the region, the dataset
 * version and the seeds revision they came from. After a dataset switch (or a new seeds revision) those of
 * every spot are out of date. Requests refresh them lazily, a spot at a time; this script does all of them
 * at once, so that the first request after a switch does not wait.
 *
 * For every spot that is not archived and whose label is not current, the host link rule of 4.8 is applied
 * and the vectors of the three visibility levels are recomputed and stored, 200 spots at a time. A spot
 * that fails is counted and the run goes on.
 *
 *   --region=<id>   only the trucks of this region
 *   --org=<id>      only the truck of this organization
 *   --dry-run       count the spots that would be refreshed and write nothing
 *
 * It prints `checked` (spots looked at), `refreshed` (with --dry-run: `to refresh`) and `failed`.
 * Exit codes: 0 success, 1 usage error, 2 when a spot could not be refreshed.
 */

require __DIR__ . '/_bootstrap.php';

use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Data\TruckDataRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\SpotService;
use App\TruckPlanner\Services\Support\Redactor;
use App\TruckPlanner\Services\Support\TpConfig;

$usage = <<<'TXT'
Usage: php scripts/truck/refresh-spots.php [--region=<id>] [--org=<organization id>] [--dry-run]

  --region=<id>   only the trucks of this region
  --org=<id>      only the truck of this organization
  --dry-run       count the spots that would be refreshed and write nothing
TXT;

$options = tp_args(['region' => 'value', 'org' => 'value', 'dry-run' => 'flag'], $usage);
$onlyRegion = isset($options['region']) ? (string) $options['region'] : null;
$onlyOrg = isset($options['org']) ? (string) $options['org'] : null;
$dryRun = isset($options['dry-run']);

$regionRows = new RegionRepository();
$regions = new RegionService($regionRows);
if ($onlyRegion !== null && $onlyRegion !== RegionService::NONE && $regionRows->find($onlyRegion) === null) {
    tp_fail('refresh-spots: there is no region ' . $onlyRegion, TP_EXIT_USAGE);
}

$data = new TruckDataRepository();
$trucks = new TruckRepository();
$spots = new SpotRepository();
$service = new SpotService($spots, null, $regions);
$mapper = new ProfileMapper();
$batch = max(1, (int) TpConfig::get('requests.refresh_script_batch'));
$revision = Seeds::revision();

$totals = ['trucks' => 0, 'checked' => 0, 'refreshed' => 0, 'failed' => 0];
$reported = 0;

/**
 * The spots of one organization's truck. Answers false when the organization has no truck (in the region).
 */
$refreshTruck = static function (string $orgId) use (
    $trucks, $spots, $service, $mapper, $regions, $batch, $revision, $onlyRegion, $dryRun, &$totals, &$reported
): bool {
    $truck = $trucks->findByOrg($orgId);
    if ($truck === null || ($onlyRegion !== null && $truck['region_id'] !== $onlyRegion)) {
        return false;
    }
    $truck['profile'] = $mapper->toRecord($truck)['profile'];
    $truckId = (string) $truck['id'];
    $regionId = (string) $truck['region_id'];
    $active = $regions->active($regionId);
    $version = $active === null ? null : (string) $active['dataset_version'];

    $totals['trucks']++;
    $spotCount = $spots->countActive($orgId, $truckId);
    $totals['checked'] += $spotCount;
    if ($dryRun) {
        $totals['refreshed'] += count($spots->staleIds($orgId, $truckId, $regionId, $version, $revision, max(1, $spotCount)));
        return true;
    }

    // A spot that failed stays stale and would come back with every batch: it is tried once.
    $failed = [];
    while (true) {
        $todo = [];
        foreach ($spots->staleIds($orgId, $truckId, $regionId, $version, $revision, $batch + count($failed)) as $id) {
            if (!isset($failed[$id])) {
                $todo[] = $id;
            }
        }
        if ($todo === []) {
            break;
        }
        foreach ($todo as $id) {
            try {
                $spot = $spots->find($id, $orgId);
                if ($spot !== null) {
                    $service->ensureFresh($orgId, $truck, $spot);
                    $totals['refreshed']++;
                }
            } catch (Throwable $e) {
                $failed[$id] = true;
                $totals['failed']++;
                if ($reported < 20) {
                    fwrite(STDERR, 'refresh-spots: spot ' . $id . ': ' . get_class($e) . ': ' . Redactor::text($e->getMessage()) . "\n");
                    $reported++;
                }
            }
        }
    }
    return true;
};

if ($onlyOrg !== null) {
    if (!$refreshTruck($onlyOrg)) {
        tp_fail('refresh-spots: organization ' . $onlyOrg . ' has no truck' . ($onlyRegion === null ? '' : ' in region ' . $onlyRegion), TP_EXIT_USAGE);
    }
} else {
    $after = '';
    do {
        $page = $data->truckOrganizations($onlyRegion, $after, $batch);
        foreach ($page as $orgId) {
            $refreshTruck($orgId);
            $after = $orgId;
        }
    } while (count($page) === $batch);
}

tp_out('refresh-spots' . ($dryRun ? ' (dry run, nothing written)' : '') . ': ' . $totals['trucks'] . ($totals['trucks'] === 1 ? ' truck' : ' trucks')
    . ($onlyRegion === null ? '' : ' in region ' . $onlyRegion));
tp_out('checked ' . $totals['checked']);
tp_out(($dryRun ? 'to refresh ' : 'refreshed ') . $totals['refreshed']);
tp_out('failed ' . $totals['failed']);

exit($totals['failed'] > 0 ? TP_EXIT_FAILED : TP_EXIT_OK);
