<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\PointRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\CaptureProvider;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;

/**
 * Exact capture at one point from the region's rows, and the readers of the host link rule
 * (04_BACKEND.md 5.1; the math is 02_MODEL.md 4.4).
 *
 * One request path for everything that needs location vectors: a click on the map, a saved spot, and the
 * region loader's self-check, which proves that this path reproduces the cell pack.
 *
 *   1. The dataset version is the region's active one, resolved once and passed to every query. Region
 *      `none`, an unknown region and a region without an active `ready` version have no rows: the answer is
 *      the model's own for an empty neighbourhood (zero vectors, `in_region` false), and nothing is read.
 *   2. Source points (Q1) and rival outlets (Q2) are read in a box around the point. SQL only narrows: the
 *      model applies the walking cutoff, and it adds the rows up in the order the queries return them.
 *   3. Where the point lies comes from the nearest census block within 2,400 m (Q4). That is a label only:
 *      a point just outside a county line keeps its vectors with `in_region` false. The region's bounding
 *      box is never a test.
 *   4. `A` is Seeds::defaults(): capture reads build- and fixed-scope seeds only, so an owner's overrides
 *      cannot change vectors.
 *
 * A version that was built with other model constants must not produce vectors: capture() then raises
 * TpConflict. locate() and the three readers need only a `ready` version.
 *
 * An explicit `$version` is used as given, without the `ready` and build tests. Only the region loader
 * passes one: the version it checks is still loading.
 */
class CaptureService implements CaptureProvider
{
    public const BUILD_MISMATCH = 'Region data was built with different model constants';

    private const NOWHERE = ['in_region' => false, 'region_id' => null, 'county_fips' => null, 'state' => null];

    private ?RegionService $regions;
    private ?PointRepository $points;
    private ?PlaceRepository $places;
    private ?RegionRepository $regionRows;

    /** @var array<string, array<string, mixed>|null> region definitions read for an explicit version */
    private array $configs = [];

    /** The Q1 and Q2 rows of the last point that was read. */
    private ?string $memoKey = null;
    /** @var list<list<mixed>>|null */
    private ?array $memoPoints = null;
    /** @var list<list<mixed>>|null */
    private ?array $memoRivals = null;

    public function __construct(
        ?RegionService $regions = null,
        ?PointRepository $points = null,
        ?PlaceRepository $places = null,
        ?RegionRepository $regionRows = null
    ) {
        $this->regions = $regions;
        $this->points = $points;
        $this->places = $places;
        $this->regionRows = $regionRows;
    }

    public function locate(string $regionId, float $lat, float $lng, ?string $version = null): array
    {
        $scope = $this->scope($regionId, $version);
        return $scope === null ? self::NOWHERE : $this->locateIn($scope, $lat, $lng);
    }

    public function capture(
        string $regionId,
        float $lat,
        float $lng,
        array $visibilities,
        ?array $host,
        ?string $version = null
    ): array {
        $A = Seeds::defaults();
        $exclusion = Estimator::hostExclusion($A, $host);
        $scope = $this->scope($regionId, $version);

        if ($scope === null) {
            $vectors = [];
            foreach ($visibilities as $visibility) {
                $one = Estimator::captureAtPoint($A, $lat, $lng, (string) $visibility, [], [], $exclusion);
                $one['in_region'] = false;
                $vectors[(string) $visibility] = $one;
            }
            return ['located' => self::NOWHERE, 'vectors' => $vectors, 'outlets' => [], 'outlets_total' => 0, 'hosts_nearby' => []];
        }
        if (!$scope['usable']) {
            throw new TpConflict(self::BUILD_MISMATCH);
        }

        $regionId = $scope['region_id'];
        $version = $scope['dataset_version'];
        $pointRows = $this->pointRows($regionId, $version, $lat, $lng);
        $rivalRows = $this->rivalRows($regionId, $version, $lat, $lng);
        $located = $this->locateIn($scope, $lat, $lng);

        $sources = self::sourcePoints($pointRows);
        $outlets = [];
        foreach ($rivalRows as $row) {
            $outlets[] = [
                'id' => $row[PlaceRepository::RIVAL_KEY],
                'lat' => $row[PlaceRepository::RIVAL_LAT],
                'lng' => $row[PlaceRepository::RIVAL_LNG],
                'kind' => $row[PlaceRepository::RIVAL_KIND],
            ];
        }

        $vectors = [];
        foreach ($visibilities as $visibility) {
            $one = Estimator::captureAtPoint($A, $lat, $lng, (string) $visibility, $sources, $outlets, $exclusion);
            $one['in_region'] = $located['in_region'];
            $one['region_id'] = $regionId;
            $one['dataset_version'] = $version;
            $one['model_version'] = (string) $A['model_version'];
            $vectors[(string) $visibility] = $one;
        }

        [$outletRows, $outletsTotal] = $this->outletRows($A, $lat, $lng, $rivalRows);
        return [
            'located' => $located,
            'vectors' => $vectors,
            'outlets' => $outletRows,
            'outlets_total' => $outletsTotal,
            'hosts_nearby' => $this->hostHints($A, $regionId, $version, $lat, $lng),
        ];
    }

    public function sources(string $regionId, float $lat, float $lng, ?string $version = null): array
    {
        $scope = $this->scope($regionId, $version);
        if ($scope === null) {
            return [];
        }
        return self::sourcePoints($this->pointRows($scope['region_id'], $scope['dataset_version'], $lat, $lng));
    }

    public function place(string $regionId, string $placeKey, ?string $version = null): ?array
    {
        $scope = $this->scope($regionId, $version);
        if ($scope === null) {
            return null;
        }
        $row = $this->places()->findHost($scope['region_id'], $scope['dataset_version'], $placeKey);
        if ($row === null) {
            return null;
        }
        $display = $this->places()->byKeys($scope['region_id'], $scope['dataset_version'], [$placeKey])[$placeKey] ?? null;
        $row['size_default'] = $display === null ? 0.0 : (float) $display['size_default'];
        $row['kitchen'] = $display === null ? 'unknown' : (string) $display['kitchen'];
        return $row;
    }

    public function hostsNear(string $regionId, float $lat, float $lng, float $radiusM, ?string $version = null): array
    {
        $scope = $this->scope($regionId, $version);
        if ($scope === null) {
            return [];
        }
        $out = [];
        foreach ($this->hostsWithin($scope['region_id'], $scope['dataset_version'], $lat, $lng, $radiusM) as [$d, $row]) {
            $out[] = [
                'place_key' => $row[PlaceRepository::HOST_KEY],
                'place_type' => $row[PlaceRepository::HOST_TYPE],
                'visitor_segment' => $row[PlaceRepository::HOST_SEGMENT],
                'lat' => $row[PlaceRepository::HOST_LAT],
                'lng' => $row[PlaceRepository::HOST_LNG],
                'distance_m' => Estimator::roundHalfAway($d, 1),
            ];
        }
        return $out;
    }

    /**
     * Drops the rows kept from the last point. A long-running script calls it when the rows of a version
     * may have been rewritten since.
     */
    public function forget(): void
    {
        $this->memoKey = null;
        $this->memoPoints = null;
        $this->memoRivals = null;
    }

    /**
     * Which rows to read: the region and the dataset version, whether vectors may be computed from them,
     * and the region definition.
     *
     * @return array{region_id: string, dataset_version: string, usable: bool, config: array<string, mixed>}|null
     *         null when there is nothing to read
     */
    private function scope(string $regionId, ?string $version): ?array
    {
        if ($regionId === '' || $regionId === RegionService::NONE) {
            return null;
        }
        if ($version !== null && $version !== '') {
            if (!array_key_exists($regionId, $this->configs)) {
                $row = $this->regionRows()->find($regionId);
                $this->configs[$regionId] = $row === null ? null : (array) $row['config'];
            }
            $config = $this->configs[$regionId];
            if ($config === null) {
                return null;
            }
            return ['region_id' => $regionId, 'dataset_version' => $version, 'usable' => true, 'config' => $config];
        }
        $active = $this->regions()->active($regionId);
        if ($active === null) {
            return null;
        }
        return [
            'region_id' => (string) $active['region_id'],
            'dataset_version' => (string) $active['dataset_version'],
            'usable' => (bool) $active['usable'],
            'config' => (array) $active['config'],
        ];
    }

    /**
     * Q4: the nearest census block within 2,400 m decides the label. Ties go to the first in point_id order.
     *
     * @param array{region_id: string, dataset_version: string, usable: bool, config: array<string, mixed>} $scope
     * @return array{in_region: bool, region_id: ?string, county_fips: ?string, state: ?string}
     */
    private function locateIn(array $scope, float $lat, float $lng): array
    {
        $radius = (float) TpConfig::get('regions.locate_radius_m');
        $best = null;
        $bestDistance = 0.0;
        foreach ($this->points()->nearestBlock($scope['region_id'], $scope['dataset_version'], $lat, $lng) as $row) {
            $d = Estimator::haversineM($lat, $lng, $row[PointRepository::BLOCK_LAT], $row[PointRepository::BLOCK_LNG]);
            if ($d > $radius) {
                continue;
            }
            if ($best === null || $d < $bestDistance) {
                $best = $row;
                $bestDistance = $d;
            }
        }
        if ($best === null) {
            return ['in_region' => false, 'region_id' => $scope['region_id'], 'county_fips' => null, 'state' => null];
        }
        $ref = (string) $best[PointRepository::BLOCK_REF];
        return [
            'in_region' => (int) $best[PointRepository::BLOCK_IN_REGION] === 1,
            'region_id' => $scope['region_id'],
            'county_fips' => substr($ref, 0, 5),
            'state' => self::stateOf($scope['config'], substr($ref, 0, 2)),
        ];
    }

    /**
     * The upper-case postal code of the state with this FIPS code in the region definition, or null.
     *
     * @param array<string, mixed> $config
     */
    private static function stateOf(array $config, string $stateFips): ?string
    {
        $states = $config['states'] ?? null;
        if (!is_array($states)) {
            return null;
        }
        foreach ($states as $state) {
            if (is_array($state) && (string) ($state['fips'] ?? '') === $stateFips && isset($state['usps'])) {
                return strtoupper((string) $state['usps']);
            }
        }
        return null;
    }

    /**
     * Q1 rows as the SourcePoint list the model takes.
     *
     * @param list<list<mixed>> $rows
     * @return list<array{id: string, lat: float, lng: float, base: list<float>, rivals: array{day: float, eve: float}}>
     */
    private static function sourcePoints(array $rows): array
    {
        $sources = [];
        foreach ($rows as $row) {
            $sources[] = [
                'id' => $row[PointRepository::ID],
                'lat' => $row[PointRepository::LAT],
                'lng' => $row[PointRepository::LNG],
                'base' => array_slice($row, PointRepository::BASE, PointRepository::SEGMENTS),
                'rivals' => ['day' => $row[PointRepository::RIVALS_DAY], 'eve' => $row[PointRepository::RIVALS_EVE]],
            ];
        }
        return $sources;
    }

    /**
     * The rival outlets within the walking cutoff, nearest first, then by place_key, cut to the first 60.
     *
     * @param array<string, mixed> $A
     * @param list<list<mixed>> $rivalRows
     * @return array{0: list<array<string, mixed>>, 1: int} the OutletRow list and the number before the cut
     */
    private function outletRows(array $A, float $lat, float $lng, array $rivalRows): array
    {
        $cutoff = (float) Estimator::seed($A, 'kernel.walk_cutoff_m');
        $within = [];
        foreach ($rivalRows as $row) {
            $d = Estimator::haversineM($lat, $lng, $row[PlaceRepository::RIVAL_LAT], $row[PlaceRepository::RIVAL_LNG]);
            if ($d <= $cutoff) {
                $within[] = [Estimator::qkey($d), $row[PlaceRepository::RIVAL_KEY], $d, $row];
            }
        }
        usort($within, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1]));
        $out = [];
        foreach (array_slice($within, 0, max(0, (int) TpConfig::get('requests.simulate_max_outlets'))) as [, , $d, $row]) {
            $out[] = [
                'place_key' => $row[PlaceRepository::RIVAL_KEY],
                'name' => $row[PlaceRepository::RIVAL_NAME],
                'place_type' => $row[PlaceRepository::RIVAL_TYPE],
                'rival_kind' => $row[PlaceRepository::RIVAL_KIND],
                'kitchen' => $row[PlaceRepository::RIVAL_KITCHEN],
                'lat' => $row[PlaceRepository::RIVAL_LAT],
                'lng' => $row[PlaceRepository::RIVAL_LNG],
                'distance_m' => Estimator::roundHalfAway($d, 1),
            ];
        }
        return [$out, count($within)];
    }

    /**
     * The possible hosts within 250 m as HostHint, nearest first, at most 10.
     *
     * @param array<string, mixed> $A
     * @return list<array<string, mixed>>
     */
    private function hostHints(array $A, string $regionId, string $version, float $lat, float $lng): array
    {
        $radius = (float) TpConfig::get('requests.hosts_nearby_radius_m');
        $limit = max(0, (int) TpConfig::get('requests.simulate_max_hosts_nearby'));
        $types = Estimator::seed($A, 'vocabulary.place_types');
        $out = [];
        foreach (array_slice($this->hostsWithin($regionId, $version, $lat, $lng, $radius), 0, $limit) as [$d, $row]) {
            $type = $row[PlaceRepository::HOST_TYPE];
            $seed = in_array($type, $types, true) ? Estimator::seed($A, 'place_types.rows.' . $type) : [];
            $kitchen = $row[PlaceRepository::HOST_KITCHEN];
            if ($kitchen !== 'yes' && $kitchen !== 'no') {
                $kitchen = ($seed['kitchen_default'] ?? 'no') === 'yes' ? 'yes' : 'no';
            }
            $segment = $row[PlaceRepository::HOST_SEGMENT];
            $out[] = [
                'place_key' => $row[PlaceRepository::HOST_KEY],
                'name' => $row[PlaceRepository::HOST_NAME],
                'place_type' => $type,
                'lat' => $row[PlaceRepository::HOST_LAT],
                'lng' => $row[PlaceRepository::HOST_LNG],
                'distance_m' => Estimator::roundHalfAway($d, 1),
                'host_segment' => $seed['host_segment'] ?? null,
                'default_size' => $row[PlaceRepository::HOST_SIZE],
                'kitchen' => $kitchen,
                'point_id' => $segment === null ? null : 'p' . $row[PlaceRepository::HOST_KEY],
            ];
        }
        return $out;
    }

    /**
     * Q3 rows within `$radiusM` metres, nearest first, then by place_key.
     *
     * @return list<array{0: float, 1: list<mixed>}> [distance in metres, row]
     */
    private function hostsWithin(string $regionId, string $version, float $lat, float $lng, float $radiusM): array
    {
        $within = [];
        foreach ($this->places()->hostsNear($regionId, $version, $lat, $lng, $radiusM) as $row) {
            $d = Estimator::haversineM($lat, $lng, $row[PlaceRepository::HOST_LAT], $row[PlaceRepository::HOST_LNG]);
            if ($d <= $radiusM) {
                $within[] = [Estimator::qkey($d), $row[PlaceRepository::HOST_KEY], $d, $row];
            }
        }
        usort($within, static fn (array $a, array $b): int => ($a[0] <=> $b[0]) ?: strcmp($a[1], $b[1]));
        $out = [];
        foreach ($within as [, , $d, $row]) {
            $out[] = [$d, $row];
        }
        return $out;
    }

    /**
     * @return list<list<mixed>> Q1 rows around the point
     */
    private function pointRows(string $regionId, string $version, float $lat, float $lng): array
    {
        $this->remember($regionId, $version, $lat, $lng);
        return $this->memoPoints ??= $this->points()->near($regionId, $version, $lat, $lng, $this->cutoff());
    }

    /**
     * @return list<list<mixed>> Q2 rows around the point
     */
    private function rivalRows(string $regionId, string $version, float $lat, float $lng): array
    {
        $this->remember($regionId, $version, $lat, $lng);
        return $this->memoRivals ??= $this->places()->rivalsNear($regionId, $version, $lat, $lng, $this->cutoff());
    }

    /** The rows that are kept belong to one point of one version: another point starts afresh. */
    private function remember(string $regionId, string $version, float $lat, float $lng): void
    {
        $key = $regionId . '|' . $version . '|' . JsonSafe::float($lat) . '|' . JsonSafe::float($lng);
        if ($key !== $this->memoKey) {
            $this->memoKey = $key;
            $this->memoPoints = null;
            $this->memoRivals = null;
        }
    }

    private function cutoff(): float
    {
        return (float) Estimator::seed(Seeds::defaults(), 'kernel.walk_cutoff_m');
    }

    private function regions(): RegionService
    {
        return $this->regions ??= new RegionService();
    }

    private function points(): PointRepository
    {
        return $this->points ??= new PointRepository();
    }

    private function places(): PlaceRepository
    {
        return $this->places ??= new PlaceRepository();
    }

    private function regionRows(): RegionRepository
    {
        return $this->regionRows ??= new RegionRepository();
    }
}
