<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;

/**
 * The regions with loaded data, their active dataset version, and whether that version can be used with
 * the model this server runs (04_BACKEND.md 5.2).
 *
 * A region's active version is usable when its pack row is `ready`, was built for this model version, and
 * was built with the build-scope seed values this server has: the kernel block recorded with the pack and
 * the parameters recorded in the manifest both equal the seeds. `unusable_reason` is `not_loaded` (no
 * active `ready` version) or `build_mismatch` (ready, but built with other constants: the region must be
 * rebuilt and reloaded).
 *
 * Structures are compared decoded: numbers as doubles, strings and lists exactly, objects key by key.
 * MySQL reorders the keys of a JSON column and reprints its numbers, so texts are never compared.
 */
class RegionService
{
    public const NONE = 'none';
    public const NOT_LOADED = 'not_loaded';
    public const BUILD_MISMATCH = 'build_mismatch';

    /** The form of a region id (03_DATA.md section 1). */
    private const ID_FORM = '/\A[a-z0-9]{1,24}\z/';

    /** Two units in the last place of a double, as a relative difference (2 x 2^-52). */
    private const JSON_NOISE = 4.5e-16;

    /** Kernel verdicts of this PHP process, by "region|version": the comparison runs once per process. */
    private static array $kernelVerdicts = [];

    private RegionRepository $regions;

    /** @var array<string, array<string, mixed>|null> region rows read by this instance */
    private array $rows = [];
    private bool $allRead = false;

    /** @var array<string, array<string, mixed>> version state by region id */
    private array $states = [];

    public function __construct(?RegionRepository $regions = null)
    {
        $this->regions = $regions ?? new RegionRepository();
    }

    /**
     * Every region, in ascending id.
     *
     * @return list<array<string, mixed>> RegionInfo
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->allRows() as $row) {
            $out[] = $this->infoOf($row);
        }
        return $out;
    }

    /**
     * RegionInfo = { region_id, name, timezone, h3_res, center: {lat, lng},
     *                bbox: {lat_min, lng_min, lat_max, lng_max}, dataset_version: string?, usable: bool,
     *                unusable_reason: null|"not_loaded"|"build_mismatch",
     *                pack: { url, format_version, bytes, gz_bytes, sha256, cell_count }?,
     *                vintages: { census_reference_date, lodes_year, osm_snapshot_date }?,
     *                counties: [ {fips, name, state} ] }
     *
     * `pack` is null unless the region is usable. `vintages` is null while nothing is loaded.
     *
     * @return array<string, mixed>|null null for region `none` and for an id that is not a region
     */
    public function info(string $regionId): ?array
    {
        $row = $this->row($regionId);
        return $row === null ? null : $this->infoOf($row);
    }

    /**
     * The active dataset version of a region, for readers of the region's rows.
     *
     * @return array{region_id: string, dataset_version: string, timezone: string, h3_res: int, usable: bool,
     *               unusable_reason: ?string, config: array<string, mixed>}|null
     *         null for region `none`, an unknown id, and a region without an active `ready` version
     *         (nothing can be read then). Otherwise the version to pass to every query of the request;
     *         `usable` false with reason `build_mismatch` means its rows exist but vectors must not be
     *         computed from them. `config` is the region definition (states, counties, fuel areas).
     */
    public function active(string $regionId): ?array
    {
        $row = $this->row($regionId);
        if ($row === null) {
            return null;
        }
        $state = $this->stateOf($row);
        if (!$state['ready']) {
            return null;
        }
        return [
            'region_id' => (string) $row['region_id'],
            'dataset_version' => (string) $row['active_version'],
            'timezone' => (string) $row['timezone'],
            'h3_res' => (int) $row['h3_res'],
            'usable' => $state['usable'],
            'unusable_reason' => $state['reason'],
            'config' => $row['config'],
        ];
    }

    /**
     * The default region of a new truck: the first region, in ascending id, whose bounding box contains
     * the point, else `none`. The box only chooses this default. It is never a membership test.
     */
    public function regionForPoint(float $lat, float $lng): string
    {
        foreach ($this->allRows() as $row) {
            if ($lat >= $row['bbox_lat_min'] && $lat <= $row['bbox_lat_max']
                && $lng >= $row['bbox_lng_min'] && $lng <= $row['bbox_lng_max']) {
                return (string) $row['region_id'];
            }
        }
        return self::NONE;
    }

    /**
     * The `kernel` block of a cell pack header (03_DATA.md 11), built from this server's seeds: the
     * build-scope constants a region's vectors were computed with.
     *
     * @return array{earth_radius_m: float, walk_decay_m: float, walk_cutoff_m: float, a0: float,
     *               visibility: float, regime_of_hour: list<string>,
     *               rival_weights: array{day: array<string, float>, eve: array<string, float>}}
     */
    public function kernelFromSeeds(): array
    {
        $A = Seeds::defaults();
        $weights = ['day' => [], 'eve' => []];
        foreach (Estimator::seed($A, 'vocabulary.rival_kinds') as $kind) {
            foreach (['day', 'eve'] as $regime) {
                $weights[$regime][$kind] = (float) Estimator::seed($A, 'kernel.rival_weight.' . $kind . '.' . $regime);
            }
        }
        return [
            'earth_radius_m' => (float) Estimator::seed($A, 'constants.earth_radius_m'),
            'walk_decay_m' => (float) Estimator::seed($A, 'kernel.walk_decay_m'),
            'walk_cutoff_m' => (float) Estimator::seed($A, 'kernel.walk_cutoff_m'),
            'a0' => (float) Estimator::seed($A, 'kernel.outside_option_a0'),
            'visibility' => (float) Estimator::seed($A, 'kernel.visibility.normal'),
            'regime_of_hour' => array_values(Estimator::seed($A, 'hours.regime_of_hour')),
            'rival_weights' => $weights,
        ];
    }

    /**
     * The kernel check of 03_DATA.md 9.3: does a recorded kernel block equal kernelFromSeeds()?
     *
     * @param array<string, mixed>|null $kernel the `kernel` of RegionRepository::packMeta(); null (no
     *                                          kernel recorded) does not match
     */
    public function kernelMatches(?array $kernel): bool
    {
        return $kernel !== null && self::same($kernel, $this->kernelFromSeeds());
    }

    /**
     * Was a dataset built with this server's build-scope seeds? True when the recorded kernel block equals
     * kernelFromSeeds() and every seed value in the manifest's `parameters` equals the seed file.
     *
     * @param array<string, mixed>|null $kernel the `kernel` object of `kernel_json`
     * @param array<string, mixed> $parameters `parameters` of the manifest (03_DATA.md 8.5)
     */
    public function buildScopeMatches(?array $kernel, array $parameters): bool
    {
        return $this->kernelMatches($kernel) && $this->parameterDifferences($parameters) === [];
    }

    /**
     * The names of the manifest's `parameters` (03_DATA.md 8.5) that are not this server's seed values, in
     * the order the pipeline records them: empty when the dataset was built with these seeds. `h3_res` and
     * `job_review` are not seeds and are not looked at. A missing value differs.
     *
     * @param array<string, mixed> $parameters
     * @return list<string>
     */
    public function parameterDifferences(array $parameters): array
    {
        $A = Seeds::defaults();
        $expected = [
            'walk_decay_m' => Estimator::seed($A, 'kernel.walk_decay_m'),
            'walk_cutoff_m' => Estimator::seed($A, 'kernel.walk_cutoff_m'),
            'earth_radius_m' => Estimator::seed($A, 'constants.earth_radius_m'),
            'cns04_weight' => Estimator::seed($A, 'etl.cns04_weight'),
            'cell_min_nearby' => Estimator::seed($A, 'etl.cell_min_nearby'),
            'cell_min_venue' => Estimator::seed($A, 'etl.cell_min_venue'),
            'segment_cns' => [],
            'place_types' => [],
        ];
        foreach (Estimator::seed($A, 'vocabulary.segments') as $segment) {
            if (str_starts_with($segment, 'w_')) {
                $expected['segment_cns'][$segment] = Estimator::seed($A, 'segments.' . $segment . '.lodes_cns');
            }
        }
        foreach (Estimator::seed($A, 'vocabulary.place_types') as $type) {
            $row = Estimator::seed($A, 'place_types.rows.' . $type);
            $expected['place_types'][$type] = [
                'visitor_segment' => $row['visitor_segment'],
                'default_size' => $row['default_size'],
                'rival_kind' => $row['rival_kind'],
                'host_fit' => $row['host_fit'],
                'kitchen_default' => $row['kitchen_default'],
            ];
        }

        $differing = [];
        foreach ($expected as $name => $value) {
            if (!array_key_exists($name, $parameters) || !self::same($parameters[$name], $value)) {
                $differing[] = $name;
            }
        }
        return $differing;
    }

    /**
     * The EIA area whose fuel price applies to a truck: the region's `fuel_area_by_state` entry for the
     * state of the base, else the national area.
     */
    public function fuelArea(?string $regionId, ?string $state): string
    {
        $national = (string) TpConfig::get('fuel.national_area');
        if ($regionId === null || $state === null || $state === '') {
            return $national;
        }
        $row = $this->row($regionId);
        $areas = $row['config']['fuel_area_by_state'] ?? null;
        $area = is_array($areas) ? ($areas[strtoupper($state)] ?? null) : null;
        return is_string($area) && $area !== '' ? $area : $national;
    }

    /**
     * The five-digit FIPS codes of the region's counties, in the order of the region definition.
     *
     * @return list<string> empty for region `none` and for an unknown id
     */
    public function countyFips(string $regionId): array
    {
        $row = $this->row($regionId);
        $codes = [];
        foreach ($this->counties($row) as $county) {
            $codes[] = $county['fips'];
        }
        return $codes;
    }

    /** Forget the kernel verdicts of this process (tests). */
    public static function forgetVerdicts(): void
    {
        self::$kernelVerdicts = [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed> RegionInfo
     */
    private function infoOf(array $row): array
    {
        $state = $this->stateOf($row);
        $meta = $state['meta'];
        $regionId = (string) $row['region_id'];
        $version = $row['active_version'];

        $pack = null;
        if ($state['usable'] && is_array($meta)) {
            $pack = [
                'url' => '/api/truck/regions/' . rawurlencode($regionId) . '/pack/' . rawurlencode((string) $version),
                'format_version' => (int) $meta['pack_format'],
                'bytes' => (int) $meta['pack_len'],
                'gz_bytes' => (int) $meta['pack_gz_len'],
                'sha256' => (string) $meta['pack_sha256'],
                'cell_count' => (int) $meta['cell_count'],
            ];
        }

        return [
            'region_id' => $regionId,
            'name' => (string) $row['name'],
            'timezone' => (string) $row['timezone'],
            'h3_res' => (int) $row['h3_res'],
            'center' => ['lat' => (float) $row['center_lat'], 'lng' => (float) $row['center_lng']],
            'bbox' => [
                'lat_min' => (float) $row['bbox_lat_min'],
                'lng_min' => (float) $row['bbox_lng_min'],
                'lat_max' => (float) $row['bbox_lat_max'],
                'lng_max' => (float) $row['bbox_lng_max'],
            ],
            'dataset_version' => $version === null ? null : (string) $version,
            'usable' => $state['usable'],
            'unusable_reason' => $state['reason'],
            'pack' => $pack,
            'vintages' => $state['ready'] && is_array($meta) ? $this->vintages($row, $meta) : null,
            'counties' => $this->counties($row),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ready: bool, usable: bool, reason: ?string, meta: ?array<string, mixed>}
     */
    private function stateOf(array $row): array
    {
        $regionId = (string) $row['region_id'];
        if (isset($this->states[$regionId])) {
            return $this->states[$regionId];
        }
        $state = ['ready' => false, 'usable' => false, 'reason' => self::NOT_LOADED, 'meta' => null];
        $version = $row['active_version'];
        if (is_string($version) && $version !== '') {
            $meta = $this->regions->packMeta($regionId, $version);
            if ($meta !== null && $meta['load_state'] === 'ready') {
                $usable = $this->versionUsable($regionId, $version, $meta);
                $state = [
                    'ready' => true,
                    'usable' => $usable,
                    'reason' => $usable ? null : self::BUILD_MISMATCH,
                    'meta' => $meta,
                ];
            }
        }
        return $this->states[$regionId] = $state;
    }

    /**
     * @param array<string, mixed> $meta a row of RegionRepository::packMeta()
     */
    private function versionUsable(string $regionId, string $version, array $meta): bool
    {
        $A = Seeds::defaults();
        if ($meta['model_version'] !== $A['model_version']) {
            return false;
        }

        $memo = $regionId . '|' . $version;
        if (!isset(self::$kernelVerdicts[$memo])) {
            self::$kernelVerdicts[$memo] = $this->kernelMatches(is_array($meta['kernel'] ?? null) ? $meta['kernel'] : null);
        }
        if (!self::$kernelVerdicts[$memo]) {
            return false;
        }

        // The manifest is large and its parameters cannot change for a version: the verdict is kept a day.
        $key = 'tp:regionok:' . $regionId . ':' . $version . ':' . (int) $A['seeds_revision'];
        $cached = TpCache::get($key);
        if (is_bool($cached['ok'] ?? null)) {
            return $cached['ok'];
        }
        $manifest = $this->regions->manifest($regionId, $version);
        $parameters = $manifest['parameters'] ?? null;
        $ok = is_array($parameters) && $this->parameterDifferences($parameters) === [];
        TpCache::put($key, ['ok' => $ok], (int) TpConfig::get('regions.usable_verdict_ttl_s'));
        return $ok;
    }

    /**
     * Decoded structures compared the way 03_DATA.md 9.3 asks: numbers as doubles (400 equals 400.0),
     * strings, booleans and null exactly, lists item by item, objects key by key in any key order.
     *
     * Two numbers also count as equal when they are within two units in the last place of each other:
     * a MySQL JSON column hands some 16- and 17-digit numbers back one unit off, and that is not a
     * region built with other constants.
     */
    private static function same(mixed $a, mixed $b): bool
    {
        $aNumber = is_int($a) || is_float($a);
        $bNumber = is_int($b) || is_float($b);
        if ($aNumber || $bNumber) {
            if (!$aNumber || !$bNumber) {
                return false;
            }
            $x = (float) $a;
            $y = (float) $b;
            return $x === $y || abs($x - $y) <= self::JSON_NOISE * max(abs($x), abs($y));
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::same($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $meta
     * @return array{census_reference_date: ?string, lodes_year: ?int, osm_snapshot_date: ?string}
     */
    private function vintages(array $row, array $meta): array
    {
        $manifest = is_array($meta['vintages'] ?? null) ? $meta['vintages'] : [];
        $config = $row['config'];
        $census = $manifest['census_reference_date'] ?? ($config['census']['reference_date'] ?? null);
        $lodes = $manifest['lodes_year'] ?? ($config['lodes']['year'] ?? null);
        $osm = $manifest['osm_snapshot_date'] ?? $meta['osm_snapshot'];
        return [
            'census_reference_date' => $census === null ? null : (string) $census,
            'lodes_year' => $lodes === null ? null : (int) $lodes,
            'osm_snapshot_date' => $osm === null ? null : (string) $osm,
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return list<array{fips: string, name: string, state: string}>
     */
    private function counties(?array $row): array
    {
        $out = [];
        $counties = $row['config']['counties'] ?? null;
        if (!is_array($counties)) {
            return $out;
        }
        foreach ($counties as $county) {
            if (!is_array($county) || !isset($county['fips'])) {
                continue;
            }
            $out[] = [
                'fips' => (string) $county['fips'],
                'name' => (string) ($county['name'] ?? ''),
                'state' => (string) ($county['state'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $regionId): ?array
    {
        // Text that is not a region id is no region and is not looked up: MySQL refuses to compare text
        // outside ASCII with the id column, and that refusal would surface as a server error.
        if ($regionId === self::NONE || preg_match(self::ID_FORM, $regionId) !== 1) {
            return null;
        }
        if (!array_key_exists($regionId, $this->rows)) {
            $this->rows[$regionId] = $this->allRead ? null : $this->regions->find($regionId);
        }
        return $this->rows[$regionId];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allRows(): array
    {
        if (!$this->allRead) {
            $this->rows = [];
            foreach ($this->regions->all() as $row) {
                $this->rows[(string) $row['region_id']] = $row;
            }
            $this->allRead = true;
        }
        $rows = [];
        foreach ($this->rows as $row) {
            if ($row !== null) {
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
