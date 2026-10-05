<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;

/**
 * The owner's overrides of seed assumptions (04_BACKEND.md 4.5, 02_MODEL.md 2.2).
 *
 * The overrides are one sparse map, seed path => value, kept with the truck. A save merges its changes into
 * the stored map and the whole result must validate against the seed file: nothing is clamped, an invalid
 * map is refused and nothing is stored. Every map this class stores is stamped with the present seeds
 * revision, which is what lets AssumptionsFactory trust it.
 *
 * A map that was stored under another seeds revision may hold paths the present seed file no longer
 * accepts. AssumptionsFactory leaves those out when it builds `A`, so the owner does not see them. A save
 * starts from that same map, and so removes them from the store.
 *
 * Overrides are owner-scope seeds: no location vector depends on them, so nothing is recomputed here. The
 * truck row changes, which is what marks stored plan results as stale.
 */
class AssumptionsService
{
    private TruckRepository $trucks;
    private RegionRepository $regions;
    private AssumptionsFactory $factory;

    public function __construct(?TruckRepository $trucks = null, ?RegionRepository $regions = null, ?AssumptionsFactory $factory = null)
    {
        $this->trucks = $trucks ?? new TruckRepository();
        $this->regions = $regions ?? new RegionRepository();
        $this->factory = $factory ?? new AssumptionsFactory();
    }

    /**
     * Merges changes into the truck's overrides and stores the result.
     *
     * @param array<string, mixed> $truck the truck value of TruckBaseController::truck()
     * @param array<int|string, mixed> $changes seed path => new value; null takes the path out of the map
     * @return array<string, mixed> AssumptionsInfo with the map as it is now
     * @throws \App\TruckPlanner\Model\ModelError `invalid_overrides` when the merged map does not validate.
     *         Its details() are the [{path, error}] list of 02_MODEL.md 2.2 in ascending path order, and
     *         nothing is stored. refusal() words the answer
     */
    public function merge(string $orgId, array $truck, array $changes): array
    {
        $merged = self::current($truck);
        foreach ($changes as $path => $value) {
            if ($value === null) {
                unset($merged[$path]);
            } else {
                $merged[$path] = $value;
            }
        }
        Seeds::withOverrides($merged);
        return $this->store($orgId, $truck, $merged);
    }

    /**
     * Takes paths out of the truck's overrides, or empties the map.
     *
     * @param array<string, mixed> $truck the truck value of TruckBaseController::truck()
     * @param list<string>|null $paths the seed paths to reset; a path that is not overridden is ignored.
     *                                 Null resets everything
     * @return array<string, mixed> AssumptionsInfo with the map as it is now
     */
    public function reset(string $orgId, array $truck, ?array $paths): array
    {
        $kept = [];
        if ($paths !== null) {
            $kept = self::current($truck);
            foreach ($paths as $path) {
                unset($kept[(string) $path]);
            }
        }
        return $this->store($orgId, $truck, $kept);
    }

    /**
     * The 422 of a map that does not validate: the sentence names the first problem in ascending path
     * order as `overrides.<path>: <code>`, and the details are the whole list.
     *
     * @param array<int|string, mixed> $problems the details() of the `invalid_overrides` error
     * @return array{message: string, details: list<array{path: string, error: string}>}
     */
    public static function refusal(array $problems): array
    {
        $details = [];
        foreach ($problems as $problem) {
            $details[] = ['path' => (string) $problem['path'], 'error' => (string) $problem['error']];
        }
        if ($details === []) {
            throw new \LogicException('a refused override map names at least one path');
        }
        return [
            'message' => 'overrides.' . $details[0]['path'] . ': ' . $details[0]['error'],
            'details' => $details,
        ];
    }

    /**
     * The stored overrides a save starts from. A map stamped with the present seeds revision was validated
     * when it was stored. One from another revision loses the paths that no longer validate.
     *
     * @param array<string, mixed> $truck
     * @return array<int|string, mixed>
     */
    private static function current(array $truck): array
    {
        $stored = is_array($truck['overrides'] ?? null) ? $truck['overrides'] : [];
        if ($stored === [] || (int) ($truck['overrides_seeds_rev'] ?? 0) === Seeds::revision()) {
            return $stored;
        }
        foreach (Estimator::validateOverrides(Seeds::data(), $stored) as $problem) {
            unset($stored[$problem['path']]);
        }
        return $stored;
    }

    /**
     * @param array<string, mixed> $truck
     * @param array<int|string, mixed> $overrides a map that validates against the present seed file
     * @return array<string, mixed> AssumptionsInfo
     */
    private function store(string $orgId, array $truck, array $overrides): array
    {
        ksort($overrides, SORT_STRING);
        $this->trucks->setOverrides((string) $truck['id'], $orgId, $overrides, Seeds::revision());

        $truck['overrides'] = $overrides;
        $truck['overrides_seeds_rev'] = Seeds::revision();
        $regionId = (string) ($truck['profile']['region_id'] ?? RegionService::NONE);
        $regionRow = $regionId === RegionService::NONE ? null : $this->regions->find($regionId);
        return $this->factory->info($this->factory->forTruck($truck, $regionRow));
    }
}
