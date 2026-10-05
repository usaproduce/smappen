<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\PointRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\CaptureService;
use App\TruckPlanner\Services\Contracts\CaptureProvider;
use App\TruckPlanner\Services\Fallback\NoCapture;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConflict;
use PHPUnit\Framework\TestCase;

/**
 * Exact capture from fixture rows (04_BACKEND.md 5.1): the worked layout of 02_MODEL.md 4.4, the locate
 * rules, the lists that come with the vectors, and the readers of the host link rule.
 *
 * The layout: a truck at (38.96, -77.36); eight census blocks b1..b8 with 250 office jobs each, due north at
 * 75, 125, ... 425 m; two quick-service outlets due south at 340 m and 360 m.
 */
final class CaptureServiceTest extends TestCase
{
    private const VERSION = 'dc-20261003-d0514a63';
    private const LAT = 38.96;
    private const LNG = -77.36;
    private const NOWHERE = ['in_region' => false, 'region_id' => null, 'county_fips' => null, 'state' => null];

    private const BASES = [
        'b_res', 'b_w_office', 'b_w_health', 'b_w_edu', 'b_w_retail', 'b_w_industrial', 'b_w_hospitality', 'b_w_public',
        'b_v_nightlife', 'b_v_shopping', 'b_v_leisure', 'b_v_campus', 'b_v_hospital', 'b_v_transit', 'b_v_events', 'b_v_lodging',
    ];

    private const Q1 = 'SELECT point_id, src_kind, src_ref, lat, lng, rivals_day';
    private const Q2 = 'SELECT place_key, place_type, rival_kind';
    private const Q3 = 'AND in_region = 1 AND host_fit > 0 AND place_key > ?';
    private const Q4 = 'SELECT point_id, src_ref, in_region, lat, lng';
    private const Q5 = 'AND place_key = ?';
    private const Q7 = 'AND place_key IN (';

    protected function setUp(): void
    {
        TpCache::wire(new FixedClock('2026-10-05 12:00:00'), new MemoryCache());
        RegionService::forgetVerdicts();
    }

    protected function tearDown(): void
    {
        TpCache::wire();
        RegionService::forgetVerdicts();
    }

    // ------------------------------------------------------------------------------------ fixtures

    /** The latitude `$metres` north of the truck (south when negative): the formula of 02_MODEL.md 4.4. */
    private static function north(float $metres): float
    {
        return self::LAT + $metres / 6371008.8 * 180.0 / 3.141592653589793;
    }

    /**
     * @return list<array{id: string, lat: float, lng: float, kind: string}> the two outlets of the layout
     */
    private static function layoutOutlets(): array
    {
        return [
            ['id' => 'n1', 'lat' => self::north(-340.0), 'lng' => self::LNG, 'kind' => 'quick'],
            ['id' => 'n2', 'lat' => self::north(-360.0), 'lng' => self::LNG, 'kind' => 'quick'],
        ];
    }

    /**
     * A row of tp_points as PDO hands it over, with the rival pull the loader would have stored.
     *
     * @param array<string, float> $bases
     * @return array<string, mixed>
     */
    private static function pointRow(string $id, float $lat, float $lng, array $bases, string $ref = '510594825021000'): array
    {
        $rivals = Estimator::rivalsAtOrigin(Seeds::defaults(), $lat, $lng, self::layoutOutlets());
        $row = [
            'point_id' => $id, 'src_kind' => $id[0] === 'b' ? 'block' : 'place', 'src_ref' => $id[0] === 'b' ? $ref : substr($id, 1),
            'lat' => $lat, 'lng' => $lng, 'rivals_day' => $rivals['day'], 'rivals_eve' => $rivals['eve'],
        ];
        foreach (self::BASES as $column) {
            $row[$column] = $bases[$column] ?? 0.0;
        }
        return $row;
    }

    /**
     * The eight blocks of the layout, and one more inside the query box but beyond the walking cutoff.
     *
     * @return list<array<string, mixed>>
     */
    private static function layoutPoints(): array
    {
        $rows = [];
        foreach ([75.0, 125.0, 175.0, 225.0, 275.0, 325.0, 375.0, 425.0] as $i => $d) {
            $rows[] = self::pointRow('b' . ($i + 1), self::north($d), self::LNG, ['b_w_office' => 250.0]);
        }
        $rows[] = self::pointRow('b9', self::north(1205.0), self::LNG, ['b_w_office' => 99999.0]);
        return $rows;
    }

    /**
     * @return list<array<string, mixed>> the two outlets as rows of query Q2, with one beyond the cutoff
     */
    private static function layoutRivals(): array
    {
        return [
            ['place_key' => 'n1', 'place_type' => 'fast_food', 'rival_kind' => 'quick', 'lat' => self::north(-340.0), 'lng' => self::LNG,
                'name' => 'Example Grill', 'kitchen' => 'yes', 'hours_mask' => null],
            ['place_key' => 'n2', 'place_type' => 'fast_food', 'rival_kind' => 'quick', 'lat' => self::north(-360.0), 'lng' => self::LNG,
                'name' => null, 'kitchen' => 'yes', 'hours_mask' => str_repeat('ff', 21)],
            ['place_key' => 'n3', 'place_type' => 'restaurant', 'rival_kind' => 'full', 'lat' => self::north(-1204.0), 'lng' => self::LNG,
                'name' => 'Too Far', 'kitchen' => 'yes', 'hours_mask' => null],
        ];
    }

    /**
     * @return array<string, mixed> a row of query Q4
     */
    private static function blockRow(string $ref, int $inRegion, float $lat, float $lng): array
    {
        return ['point_id' => 'b' . $ref, 'src_ref' => $ref, 'in_region' => $inRegion, 'lat' => $lat, 'lng' => $lng];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed> a row of query Q3
     */
    private static function hostRow(array $changes = []): array
    {
        return array_replace([
            'place_key' => 'w100', 'place_type' => 'taproom', 'name' => 'Example Brewing', 'brand' => null, 'lat' => self::LAT, 'lng' => self::LNG,
            'county_fips' => '51059', 'host_fit' => 1.0, 'kitchen' => 'unknown', 'size_default' => 40.0, 'visitor_segment' => 'v_nightlife',
            'phone' => null, 'website' => null, 'addr_line' => null, 'city' => null, 'state_code' => null, 'postcode' => null,
            'opening_hours_raw' => null, 'hours_mask' => null, 'host_vec' => str_repeat("\0", 400),
        ], $changes);
    }

    /**
     * @return array<string, mixed>
     */
    private static function regionRow(?string $active = self::VERSION): array
    {
        $config = [
            'id' => 'dc', 'traffic_matrix' => 'dc',
            'states' => [['usps' => 'dc', 'fips' => '11'], ['usps' => 'md', 'fips' => '24'], ['usps' => 'va', 'fips' => '51']],
            'counties' => [['fips' => '51059', 'name' => 'Fairfax County', 'state' => 'VA']],
        ];
        return [
            'region_id' => 'dc', 'name' => 'Washington, DC region', 'cbsa' => '47900', 'timezone' => 'America/New_York', 'h3_res' => 9,
            'bbox_lat_min' => 37.9907, 'bbox_lng_min' => -78.3947, 'bbox_lat_max' => 39.7201, 'bbox_lng_max' => -76.6625,
            'center_lat' => 38.9072, 'center_lng' => -77.0369, 'active_version' => $active, 'previous_version' => null,
            'config_json' => json_encode($config), 'created_at' => '2026-10-05 04:00:00', 'updated_at' => '2026-10-05 04:00:00',
        ];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function packRow(array $changes = []): array
    {
        $kernel = (new RegionService(new RegionRepository(new RecordingDatabase())))->kernelFromSeeds();
        return $changes + [
            'region_id' => 'dc', 'dataset_version' => self::VERSION, 'load_state' => 'ready', 'model_version' => Seeds::defaults()['model_version'],
            'pipeline_version' => 'tp-etl-1.0.0', 'osm_snapshot' => '2026-10-03', 'point_count' => 9, 'place_count' => 3, 'cell_count' => 1,
            'pack_format' => 1, 'pack_len' => 1000, 'pack_gz_len' => 500, 'pack_sha256' => str_repeat('ab', 32),
            'kernel_json' => json_encode(['seeds_revision' => 1, 'kernel' => $kernel]),
            'vintages_json' => '{"census_reference_date": "2020-04-01", "lodes_year": 2023, "osm_snapshot_date": "2026-10-03"}',
            'loaded_at' => '2026-10-05 04:00:00', 'activated_at' => '2026-10-05 04:01:00',
        ];
    }

    /**
     * `parameters` of a manifest as the pipeline writes it from the seed file.
     *
     * @return array<string, mixed>
     */
    private static function parameters(): array
    {
        $A = Seeds::defaults();
        $sectors = [];
        foreach (Estimator::seed($A, 'vocabulary.segments') as $segment) {
            if (str_starts_with($segment, 'w_')) {
                $sectors[$segment] = Estimator::seed($A, 'segments.' . $segment . '.lodes_cns');
            }
        }
        $types = [];
        foreach (Estimator::seed($A, 'vocabulary.place_types') as $type) {
            $row = Estimator::seed($A, 'place_types.rows.' . $type);
            $types[$type] = ['visitor_segment' => $row['visitor_segment'], 'default_size' => $row['default_size'],
                'rival_kind' => $row['rival_kind'], 'host_fit' => $row['host_fit'], 'kitchen_default' => $row['kitchen_default']];
        }
        return [
            'walk_decay_m' => Estimator::seed($A, 'kernel.walk_decay_m'), 'walk_cutoff_m' => Estimator::seed($A, 'kernel.walk_cutoff_m'),
            'earth_radius_m' => Estimator::seed($A, 'constants.earth_radius_m'), 'cns04_weight' => Estimator::seed($A, 'etl.cns04_weight'),
            'cell_min_nearby' => Estimator::seed($A, 'etl.cell_min_nearby'), 'cell_min_venue' => Estimator::seed($A, 'etl.cell_min_venue'),
            'segment_cns' => $sectors, 'place_types' => $types, 'h3_res' => 9,
        ];
    }

    /**
     * A database that holds region dc with a ready, live version and answers the box queries with the
     * given rows. Null for a list leaves the layout in place.
     *
     * @param array<string, mixed> $rows `points` (Q1), `rivals` (Q2), `hosts` (Q3), `blocks` (Q4), `place` (Q5),
     *                                   `display` (Q7), `region`, `pack`, `parameters`
     */
    private static function database(array $rows = []): RecordingDatabase
    {
        $db = new RecordingDatabase();
        $db->when(self::Q1, $rows['points'] ?? self::layoutPoints());
        $db->when(self::Q4, $rows['blocks'] ?? [self::blockRow('510594825021000', 1, self::north(75.0), self::LNG)]);
        $db->when(self::Q2, $rows['rivals'] ?? self::layoutRivals());
        $db->when(self::Q3, $rows['hosts'] ?? []);
        $db->when(self::Q7, $rows['display'] ?? []);
        $db->when(self::Q5, $rows['place'] ?? null);
        $db->when('FROM tp_regions WHERE region_id = ?', array_key_exists('region', $rows) ? $rows['region'] : self::regionRow());
        $db->when('SELECT manifest_json FROM tp_region_packs', ['manifest_json' => json_encode(['schema' => 1, 'parameters' => $rows['parameters'] ?? self::parameters()])]);
        $db->when('FROM tp_region_packs', array_key_exists('pack', $rows) ? $rows['pack'] : self::packRow());
        return $db;
    }

    private static function service(RecordingDatabase $db): CaptureService
    {
        $regions = new RegionRepository($db);
        return new CaptureService(new RegionService($regions), new PointRepository($db), new PlaceRepository($db), $regions);
    }

    /**
     * @return list<array<string, mixed>> the SourcePoint list of the layout rows
     */
    private static function layoutSources(): array
    {
        $sources = [];
        foreach (self::layoutPoints() as $row) {
            $base = [];
            foreach (self::BASES as $column) {
                $base[] = (float) $row[$column];
            }
            $sources[] = ['id' => $row['point_id'], 'lat' => $row['lat'], 'lng' => $row['lng'], 'base' => $base,
                'rivals' => ['day' => $row['rivals_day'], 'eve' => $row['rivals_eve']]];
        }
        return $sources;
    }

    // ------------------------------------------------------------------------------------ the worked layout

    public function testItIsTheCaptureProviderTheRegistryBuildsWithoutArguments(): void
    {
        self::assertInstanceOf(CaptureProvider::class, new CaptureService());
    }

    public function testCaptureReproducesTheWorkedLayoutOfTheModel(): void
    {
        $answer = self::service(self::database())->capture('dc', self::LAT, self::LNG, ['normal', 'hidden', 'prominent'], null);

        self::assertSame(['located', 'vectors', 'outlets', 'outlets_total', 'hosts_nearby'], array_keys($answer));
        self::assertSame(['normal', 'hidden', 'prominent'], array_keys($answer['vectors']));
        $office = 1;                                                    // index of w_office

        // 02_MODEL.md 4.4, the table of results
        $expected = ['normal' => 417.134520, 'hidden' => 273.891217, 'prominent' => 509.479077];
        foreach ($expected as $visibility => $capture) {
            $v = $answer['vectors'][$visibility];
            self::assertEqualsWithDelta($capture, $v['capture']['day'][$office], 1e-6, $visibility);
            self::assertEqualsWithDelta($capture, $v['capture']['eve'][$office], 1e-6, $visibility);
            self::assertEqualsWithDelta(1114.962841, $v['nearby'][$office], 1e-6);
            self::assertEqualsWithDelta(2000.0, $v['within'][$office], 1e-9);
            self::assertEqualsWithDelta(0.833985, $v['rivals']['day'], 1e-6);
            self::assertEqualsWithDelta(0.833985, $v['rivals']['eve'], 1e-6);
            self::assertSame(8, $v['points_used'], 'the ninth block is in the box and beyond the cutoff');
            self::assertSame($visibility, $v['visibility']);
            // the labels the service sets
            self::assertTrue($v['in_region']);
            self::assertSame('dc', $v['region_id']);
            self::assertSame(self::VERSION, $v['dataset_version']);
            self::assertSame('tps-0.1.0', $v['model_version']);
            self::assertSame(['point_ids' => [], 'segment' => null, 'amount' => 0.0], $v['exclusion']);
            self::assertSame(0.0, $v['excluded_amount']);
            foreach ([0, 2, 3, 15] as $other) {
                self::assertSame(0.0, $v['capture']['day'][$other]);
                self::assertSame(0.0, $v['nearby'][$other]);
            }
        }
        self::assertSame(['in_region' => true, 'region_id' => 'dc', 'county_fips' => '51059', 'state' => 'VA'], $answer['located']);
    }

    public function testTheVectorsAreTheModelsOnTheRowsOfTheTwoQueries(): void
    {
        $A = Seeds::defaults();
        $answer = self::service(self::database())->capture('dc', self::LAT, self::LNG, ['hidden', 'normal', 'prominent'], null);
        foreach (['hidden', 'normal', 'prominent'] as $visibility) {
            $expected = Estimator::captureAtPoint(
                $A,
                self::LAT,
                self::LNG,
                $visibility,
                self::layoutSources(),
                array_merge(self::layoutOutlets(), [['id' => 'n3', 'lat' => self::north(-1204.0), 'lng' => self::LNG, 'kind' => 'full']]),
                Estimator::hostExclusion($A, null)
            );
            $expected['in_region'] = true;
            $expected['region_id'] = 'dc';
            $expected['dataset_version'] = self::VERSION;
            self::assertSame($expected, $answer['vectors'][$visibility], 'to the last bit');
        }
    }

    public function testAHostTakesItsPeopleOutOfTheCatchment(): void
    {
        $service = self::service(self::database());
        $office = 1;

        // a host that declares 600 office workers: 250 from b1, 250 from b2, 100 from b3
        $declared = ['segment' => 'w_office', 'size' => 600.0, 'size_source' => 'owner', 'only_food' => false, 'point_id' => null, 'place_type' => null];
        $v = $service->capture('dc', self::LAT, self::LNG, ['prominent'], $declared)['vectors']['prominent'];
        self::assertSame(['point_ids' => [], 'segment' => 'w_office', 'amount' => 600.0], $v['exclusion']);
        self::assertEqualsWithDelta(326.105662, $v['capture']['day'][$office], 1e-6);
        self::assertEqualsWithDelta(660.236802, $v['nearby'][$office], 1e-6);
        self::assertEqualsWithDelta(1400.0, $v['within'][$office], 1e-9);
        self::assertEqualsWithDelta(600.0, $v['excluded_amount'], 1e-9);
        self::assertEqualsWithDelta(0.833985, $v['rivals']['day'], 1e-6, 'exclusion never touches the rival pull');

        // a venue host linked to a source point: that point leaves the catchment
        $linked = ['segment' => 'v_nightlife', 'size' => 120.0, 'size_source' => 'owner', 'only_food' => true, 'point_id' => 'b1', 'place_type' => 'taproom'];
        $v = $service->capture('dc', self::LAT, self::LNG, ['normal'], $linked)['vectors']['normal'];
        self::assertSame(['point_ids' => ['b1'], 'segment' => null, 'amount' => 0.0], $v['exclusion']);
        self::assertEqualsWithDelta(350.714987, $v['capture']['day'][$office], 1e-6);
        self::assertEqualsWithDelta(907.705562, $v['nearby'][$office], 1e-6);
        self::assertEqualsWithDelta(1750.0, $v['within'][$office], 1e-9);
        self::assertSame(7, $v['points_used']);
    }

    public function testEveryQueryNamesTheLiveVersionAndBindsTheBoxAsText(): void
    {
        $db = self::database();
        self::service($db)->capture('dc', self::LAT, self::LNG, ['normal'], null);

        $cutoff = PointRepository::box(self::LAT, self::LNG, 1200.0);
        $q1 = $db->only(self::Q1);
        self::assertSame(
            ['dc', self::VERSION, json_encode($cutoff['lat_min']), json_encode($cutoff['lat_max']), json_encode($cutoff['lng_min']), json_encode($cutoff['lng_max'])],
            $q1['params']
        );
        self::assertSame($q1['params'], $db->only(self::Q2)['params'], 'Q1 and Q2 read the same box');
        $locate = PointRepository::box(self::LAT, self::LNG, 2400.0);
        self::assertSame(json_encode($locate['lat_min']), $db->only(self::Q4)['params'][2], 'Q4 reads 2,400 m');
        $hosts = PointRepository::box(self::LAT, self::LNG, 250.0);
        self::assertSame(json_encode($hosts['lat_min']), $db->only(self::Q3)['params'][2], 'the hosts nearby are read within 250 m');
        foreach ([self::Q1, self::Q2, self::Q3, self::Q4] as $query) {
            $params = $db->only($query)['params'];
            self::assertSame(['dc', self::VERSION], array_slice($params, 0, 2));
            foreach (array_slice($params, 2, 4) as $bound) {
                self::assertIsString($bound, 'a float is never bound as a PHP float');
            }
        }
    }

    // ------------------------------------------------------------------------------------ regions without rows

    public function testRegionNoneAnswersLikeTheFallbackAndReadsNothing(): void
    {
        $db = self::database();
        $service = self::service($db);
        $host = ['segment' => 'w_office', 'size' => 800.0, 'size_source' => 'owner', 'only_food' => false, 'point_id' => null, 'place_type' => null];
        foreach (['none', ''] as $region) {
            self::assertSame(
                (new NoCapture())->capture($region, self::LAT, self::LNG, ['hidden', 'normal', 'prominent'], $host),
                $service->capture($region, self::LAT, self::LNG, ['hidden', 'normal', 'prominent'], $host)
            );
            self::assertSame(self::NOWHERE, $service->locate($region, self::LAT, self::LNG));
            self::assertSame([], $service->sources($region, self::LAT, self::LNG));
            self::assertNull($service->place($region, 'w100'));
            self::assertSame([], $service->hostsNear($region, self::LAT, self::LNG, 100.0));
        }
        self::assertSame([], $db->calls, 'region none needs no query');
    }

    public function testARegionWithoutAReadyLiveVersionHasNoRows(): void
    {
        $cases = [
            'unknown region' => ['region' => null],
            'nothing activated yet' => ['region' => self::regionRow(null)],
            'the live version is still loading' => ['pack' => self::packRow(['load_state' => 'loading', 'kernel_json' => null])],
            'the load failed' => ['pack' => self::packRow(['load_state' => 'failed'])],
            'the ledger row is gone' => ['pack' => null],
        ];
        foreach ($cases as $name => $rows) {
            RegionService::forgetVerdicts();
            $db = self::database($rows);
            $service = self::service($db);
            $answer = $service->capture('dc', self::LAT, self::LNG, ['normal'], null);
            self::assertSame((new NoCapture())->capture('dc', self::LAT, self::LNG, ['normal'], null), $answer, $name);
            self::assertSame(0, $answer['vectors']['normal']['points_used'], $name);
            self::assertSame(self::NOWHERE, $service->locate('dc', self::LAT, self::LNG), $name);
            self::assertSame([], $service->sources('dc', self::LAT, self::LNG), $name);
            self::assertNull($service->place('dc', 'w100'), $name);
            self::assertSame([], $service->hostsNear('dc', self::LAT, self::LNG, 100.0), $name);
            self::assertSame([], $db->find('FROM tp_points'), $name . ': no point is read');
            self::assertSame([], $db->find('FROM tp_places'), $name . ': no place is read');
        }
    }

    // ------------------------------------------------------------------------------------ another build

    public function testAVersionBuiltWithOtherConstantsGivesNoVectors(): void
    {
        $kernel = (new RegionService(new RegionRepository(new RecordingDatabase())))->kernelFromSeeds();
        $kernel['a0'] = 1.8;
        $otherParameters = self::parameters();
        $otherParameters['cns04_weight'] = 0.5;
        $cases = [
            'another kernel' => ['pack' => self::packRow(['kernel_json' => json_encode(['seeds_revision' => 2, 'kernel' => $kernel])])],
            'another model version' => ['pack' => self::packRow(['model_version' => 'tps-0.2.0'])],
            'other manifest parameters' => ['parameters' => $otherParameters],
        ];
        foreach ($cases as $name => $rows) {
            RegionService::forgetVerdicts();
            TpCache::wire(new FixedClock('2026-10-05 12:00:00'), new MemoryCache());
            $db = self::database($rows + ['place' => ['place_key' => 'w100', 'place_type' => 'taproom', 'visitor_segment' => 'v_nightlife', 'lat' => self::LAT, 'lng' => self::LNG]]);
            $service = self::service($db);
            try {
                $service->capture('dc', self::LAT, self::LNG, ['normal'], null);
                self::fail($name . ': vectors were computed');
            } catch (TpConflict $e) {
                self::assertSame('Region data was built with different model constants', $e->getMessage(), $name);
            }
            self::assertSame([], $db->find('FROM tp_points'), $name . ': refused before any row is read');

            // the rows exist: where a point lies and the readers of the host link rule still answer
            self::assertSame(['in_region' => true, 'region_id' => 'dc', 'county_fips' => '51059', 'state' => 'VA'], $service->locate('dc', self::LAT, self::LNG), $name);
            self::assertCount(9, $service->sources('dc', self::LAT, self::LNG), $name);
            self::assertSame('taproom', $service->place('dc', 'w100')['place_type'], $name);
            self::assertSame([], $service->hostsNear('dc', self::LAT, self::LNG, 100.0), $name);
        }
        self::assertSame('Region data was built with different model constants', CaptureService::BUILD_MISMATCH);
    }

    // ------------------------------------------------------------------------------------ an explicit version

    public function testAnExplicitVersionIsUsedAsGivenWithoutTheReadyAndBuildTests(): void
    {
        // what the loader's self-check meets: the version is not live, its ledger row is still loading
        $db = self::database(['region' => self::regionRow(null), 'pack' => self::packRow(['load_state' => 'loading', 'kernel_json' => null])]);
        $service = self::service($db);
        $answer = $service->capture('dc', self::LAT, self::LNG, ['normal'], null, 'dc-20270105-0badc0de');

        self::assertEqualsWithDelta(417.134520, $answer['vectors']['normal']['capture']['day'][1], 1e-6);
        self::assertSame('dc-20270105-0badc0de', $answer['vectors']['normal']['dataset_version']);
        self::assertSame(['in_region' => true, 'region_id' => 'dc', 'county_fips' => '51059', 'state' => 'VA'], $answer['located']);
        foreach ([self::Q1, self::Q2, self::Q3, self::Q4] as $query) {
            self::assertSame(['dc', 'dc-20270105-0badc0de'], array_slice($db->only($query)['params'], 0, 2));
        }
        self::assertSame([], $db->find('FROM tp_region_packs'), 'neither the state nor the kernel of the version is looked at');
        self::assertCount(1, $db->find('FROM tp_regions'), 'the region definition is read once, for the state of a block');

        self::assertSame('dc', $service->locate('dc', self::LAT, self::LNG, 'dc-20270105-0badc0de')['region_id']);
        self::assertCount(9, $service->sources('dc', self::LAT, self::LNG, 'dc-20270105-0badc0de'));
        self::assertCount(1, $db->find('FROM tp_regions'));

        // a region that does not exist has nothing to read, whatever version is named
        $unknown = self::service(self::database(['region' => null]));
        self::assertSame(self::NOWHERE, $unknown->locate('zz', self::LAT, self::LNG, 'zz-20270105-0badc0de'));
        self::assertSame(self::NOWHERE, $unknown->locate('none', self::LAT, self::LNG, 'zz-20270105-0badc0de'));
    }

    // ------------------------------------------------------------------------------------ where a point lies

    public function testLocateTakesTheNearestBlockWithinTwoThousandFourHundredMetres(): void
    {
        $blocks = [
            self::blockRow('110010062021000', 1, self::north(900.0), self::LNG),
            self::blockRow('240317050005004', 1, self::north(-300.0), self::LNG),          // the nearest
            self::blockRow('510594825021000', 1, self::north(301.0), self::LNG),
            self::blockRow('540370001001000', 0, self::north(2500.0), self::LNG),          // in the box, beyond 2,400 m
        ];
        $service = self::service(self::database(['blocks' => $blocks]));
        self::assertSame(
            ['in_region' => true, 'region_id' => 'dc', 'county_fips' => '24031', 'state' => 'MD'],
            $service->locate('dc', self::LAT, self::LNG)
        );
    }

    public function testEqualDistancesGoToTheFirstBlockInIdOrder(): void
    {
        // the same coordinates twice: exactly the same distance
        $blocks = [
            self::blockRow('110010062021000', 1, self::north(200.0), self::LNG),
            self::blockRow('510594825021000', 1, self::north(200.0), self::LNG),
        ];
        self::assertSame('11001', self::service(self::database(['blocks' => $blocks]))->locate('dc', self::LAT, self::LNG)['county_fips']);
    }

    public function testNoBlockWithinReachIsOutsideTheRegion(): void
    {
        foreach ([[], [self::blockRow('510594825021000', 1, self::north(2401.0), self::LNG)]] as $blocks) {
            $service = self::service(self::database(['blocks' => $blocks, 'points' => [], 'rivals' => []]));
            self::assertSame(
                ['in_region' => false, 'region_id' => 'dc', 'county_fips' => null, 'state' => null],
                $service->locate('dc', self::LAT, self::LNG),
                'the region whose rows were read is still named'
            );
            $answer = $service->capture('dc', self::LAT, self::LNG, ['normal'], null);
            self::assertFalse($answer['vectors']['normal']['in_region']);
            self::assertSame(0, $answer['vectors']['normal']['points_used']);
            self::assertSame(array_fill(0, 16, 0.0), $answer['vectors']['normal']['nearby']);
            self::assertSame(self::VERSION, $answer['vectors']['normal']['dataset_version']);
        }
        // a block exactly at the limit still counts
        $edge = self::service(self::database(['blocks' => [self::blockRow('510594825021000', 1, self::north(2399.9), self::LNG)]]));
        self::assertTrue($edge->locate('dc', self::LAT, self::LNG)['in_region']);
    }

    public function testAPointNextToAHaloBlockKeepsItsVectorsAndIsLabelledOutside(): void
    {
        // the nearest block is a halo block of a county outside the region, in one of the region's states
        $blocks = [
            self::blockRow('240270601001000', 0, self::north(40.0), self::LNG),
            self::blockRow('510594825021000', 1, self::north(75.0), self::LNG),
        ];
        $answer = self::service(self::database(['blocks' => $blocks]))->capture('dc', self::LAT, self::LNG, ['normal'], null);
        self::assertSame(['in_region' => false, 'region_id' => 'dc', 'county_fips' => '24027', 'state' => 'MD'], $answer['located']);
        self::assertFalse($answer['vectors']['normal']['in_region'], 'the label only');
        self::assertEqualsWithDelta(417.134520, $answer['vectors']['normal']['capture']['day'][1], 1e-6, 'in_region never zeroes vectors');
        self::assertSame(8, $answer['vectors']['normal']['points_used']);
    }

    public function testAStateTheRegionDefinitionDoesNotListIsUnknown(): void
    {
        $blocks = [self::blockRow('420010301011000', 0, self::north(40.0), self::LNG)];
        self::assertSame(
            ['in_region' => false, 'region_id' => 'dc', 'county_fips' => '42001', 'state' => null],
            self::service(self::database(['blocks' => $blocks]))->locate('dc', self::LAT, self::LNG)
        );
    }

    // ------------------------------------------------------------------------------------ the lists

    public function testOutletsAreTheRivalsWithinTheCutoffNearestFirst(): void
    {
        $answer = self::service(self::database())->capture('dc', self::LAT, self::LNG, ['normal'], null);
        self::assertSame(2, $answer['outlets_total'], 'the third outlet is beyond the cutoff');
        self::assertSame(
            [
                ['place_key' => 'n1', 'name' => 'Example Grill', 'place_type' => 'fast_food', 'rival_kind' => 'quick', 'kitchen' => 'yes',
                    'lat' => self::north(-340.0), 'lng' => self::LNG, 'distance_m' => 340.0],
                ['place_key' => 'n2', 'name' => null, 'place_type' => 'fast_food', 'rival_kind' => 'quick', 'kitchen' => 'yes',
                    'lat' => self::north(-360.0), 'lng' => self::LNG, 'distance_m' => 360.0],
            ],
            $answer['outlets']
        );
    }

    public function testOutletsAreCutToSixtyAndTiesGoByPlaceKey(): void
    {
        $rivals = [];
        for ($i = 0; $i < 70; $i++) {
            // keys in an order that is not the order of distance; four outlets at the same distance
            $metres = $i < 4 ? 505.0 : 100.0 + $i * 10.0;
            $rivals[] = ['place_key' => sprintf('n%03d', 200 - $i), 'place_type' => 'cafe', 'rival_kind' => 'cafe', 'lat' => self::north($metres),
                'lng' => self::LNG, 'name' => 'Cafe ' . $i, 'kitchen' => 'yes', 'hours_mask' => null];
        }
        usort($rivals, static fn (array $a, array $b): int => strcmp($a['place_key'], $b['place_key']));
        $answer = self::service(self::database(['rivals' => $rivals]))->capture('dc', self::LAT, self::LNG, ['normal'], null);

        self::assertSame(70, $answer['outlets_total']);
        self::assertCount(60, $answer['outlets']);
        $distances = array_column($answer['outlets'], 'distance_m');
        $sorted = $distances;
        sort($sorted);
        self::assertSame($sorted, $distances, 'nearest first');
        self::assertSame(140.0, $distances[0]);
        // the four outlets 505 m away come in place_key order
        $tied = array_values(array_filter($answer['outlets'], static fn (array $o): bool => $o['distance_m'] === 505.0));
        self::assertSame(['n197', 'n198', 'n199', 'n200'], array_column($tied, 'place_key'));
    }

    public function testDistancesAreRoundedToATenthOfAMetre(): void
    {
        $rivals = [['place_key' => 'n1', 'place_type' => 'cafe', 'rival_kind' => 'cafe', 'lat' => self::north(123.449), 'lng' => self::LNG,
            'name' => 'A', 'kitchen' => 'yes', 'hours_mask' => null]];
        $answer = self::service(self::database(['rivals' => $rivals]))->capture('dc', self::LAT, self::LNG, ['normal'], null);
        self::assertSame(123.4, $answer['outlets'][0]['distance_m']);
    }

    public function testHostsNearbyAreThePossibleHostsWithinTwoHundredFiftyMetres(): void
    {
        $hosts = [
            self::hostRow(['place_key' => 'w300', 'place_type' => 'office_park', 'name' => 'Example Court', 'lat' => self::north(200.0),
                'kitchen' => 'no', 'size_default' => 0.0, 'visitor_segment' => null]),
            self::hostRow(['place_key' => 'w100', 'lat' => self::north(30.0)]),
            self::hostRow(['place_key' => 'n200', 'place_type' => 'bar', 'name' => 'Example Bar', 'lat' => self::north(-120.0),
                'kitchen' => 'unknown', 'size_default' => 45.0]),
            self::hostRow(['place_key' => 'w400', 'name' => 'In the box, too far', 'lat' => self::north(251.0)]),
        ];
        $answer = self::service(self::database(['hosts' => $hosts]))->capture('dc', self::LAT, self::LNG, ['normal'], null);

        $A = Seeds::defaults();
        self::assertSame(
            [
                ['place_key' => 'w100', 'name' => 'Example Brewing', 'place_type' => 'taproom', 'lat' => self::north(30.0), 'lng' => self::LNG,
                    'distance_m' => 30.0, 'host_segment' => Estimator::seed($A, 'place_types.rows.taproom')['host_segment'], 'default_size' => 40.0,
                    'kitchen' => 'no', 'point_id' => 'pw100'],
                ['place_key' => 'n200', 'name' => 'Example Bar', 'place_type' => 'bar', 'lat' => self::north(-120.0), 'lng' => self::LNG,
                    'distance_m' => 120.0, 'host_segment' => Estimator::seed($A, 'place_types.rows.bar')['host_segment'], 'default_size' => 45.0,
                    'kitchen' => 'yes', 'point_id' => 'pn200'],
                ['place_key' => 'w300', 'name' => 'Example Court', 'place_type' => 'office_park', 'lat' => self::north(200.0), 'lng' => self::LNG,
                    'distance_m' => 200.0, 'host_segment' => Estimator::seed($A, 'place_types.rows.office_park')['host_segment'], 'default_size' => 0.0,
                    'kitchen' => 'no', 'point_id' => null],
            ],
            $answer['hosts_nearby']
        );
        // an unknown kitchen is resolved with the default of the place type: a taproom has none, a bar has one
        self::assertSame('no', Estimator::seed($A, 'place_types.rows.taproom')['kitchen_default']);
        self::assertSame('yes', Estimator::seed($A, 'place_types.rows.bar')['kitchen_default']);
        self::assertSame('v_nightlife', $answer['hosts_nearby'][0]['host_segment']);
    }

    public function testAtMostTenHostsNearby(): void
    {
        $hosts = [];
        for ($i = 0; $i < 14; $i++) {
            $hosts[] = self::hostRow(['place_key' => sprintf('w%03d', $i), 'lat' => self::north(10.0 + $i)]);
        }
        $answer = self::service(self::database(['hosts' => $hosts]))->capture('dc', self::LAT, self::LNG, ['normal'], null);
        self::assertCount(10, $answer['hosts_nearby']);
        self::assertSame('w000', $answer['hosts_nearby'][0]['place_key']);
        self::assertSame('w009', $answer['hosts_nearby'][9]['place_key']);
    }

    // ------------------------------------------------------------------------------------ the readers

    public function testSourcesAreTheSourcePointsCaptureSumsOver(): void
    {
        $sources = self::service(self::database())->sources('dc', self::LAT, self::LNG);
        self::assertSame(self::layoutSources(), $sources);
        self::assertSame(['id', 'lat', 'lng', 'base', 'rivals'], array_keys($sources[0]));
        self::assertCount(16, $sources[0]['base']);
        self::assertSame(250.0, $sources[0]['base'][1]);
        self::assertEqualsWithDelta(0.691398, $sources[0]['rivals']['day'], 1e-6, 'b1: 02_MODEL.md 4.4');
        // the model takes them as they are
        self::assertNull(Estimator::hostLinkPoint(Seeds::defaults(), self::LAT, self::LNG, ['segment' => 'v_nightlife', 'size' => 40.0], $sources));
    }

    public function testCaptureAfterSourcesDoesNotReadTheRowsAgain(): void
    {
        $db = self::database();
        $service = self::service($db);
        $service->sources('dc', self::LAT, self::LNG);
        $service->hostsNear('dc', self::LAT, self::LNG, 100.0);
        $service->capture('dc', self::LAT, self::LNG, ['hidden', 'normal', 'prominent'], null);
        self::assertCount(1, $db->find(self::Q1), 'Q1 ran once for sources() and capture()');
        self::assertCount(1, $db->find(self::Q2));
        $service->capture('dc', self::LAT, self::LNG, ['normal'], null);
        self::assertCount(1, $db->find(self::Q1), 'the same point again');

        // another point, another version or forget() reads afresh
        $service->sources('dc', self::LAT + 0.001, self::LNG);
        self::assertCount(2, $db->find(self::Q1));
        $service->sources('dc', self::LAT + 0.001, self::LNG, 'dc-20270105-0badc0de');
        self::assertCount(3, $db->find(self::Q1));
        $service->forget();
        $service->sources('dc', self::LAT + 0.001, self::LNG, 'dc-20270105-0badc0de');
        self::assertCount(4, $db->find(self::Q1));
    }

    public function testPlaceIsQueryFiveWithTheSizeAndTheKitchenOfItsDisplayRow(): void
    {
        $display = self::hostRow(['place_key' => 'w100', 'kitchen' => 'unknown', 'size_default' => 40.0]);
        unset($display['host_vec']);
        $db = self::database([
            'place' => ['place_key' => 'w100', 'place_type' => 'taproom', 'visitor_segment' => 'v_nightlife', 'lat' => 39.0101, 'lng' => -77.4102],
            'display' => [$display],
        ]);
        self::assertSame(
            ['place_key' => 'w100', 'place_type' => 'taproom', 'visitor_segment' => 'v_nightlife', 'lat' => 39.0101, 'lng' => -77.4102,
                'size_default' => 40.0, 'kitchen' => 'unknown'],
            self::service($db)->place('dc', 'w100')
        );
        self::assertSame(['dc', self::VERSION, 'w100'], $db->only(self::Q5)['params']);
        self::assertSame(['dc', self::VERSION, 'w100'], $db->only(self::Q7)['params']);

        // a key the dataset does not hold: a dangling link
        $gone = self::database(['place' => null]);
        self::assertNull(self::service($gone)->place('dc', 'w999'));
        self::assertSame([], $gone->find(self::Q7));
    }

    public function testHostsNearAreThePossibleHostsWithinTheRadiusNearestFirst(): void
    {
        $hosts = [
            self::hostRow(['place_key' => 'w300', 'place_type' => 'office_park', 'lat' => self::north(90.0), 'visitor_segment' => null]),
            self::hostRow(['place_key' => 'w100', 'lat' => self::north(60.0)]),
            self::hostRow(['place_key' => 'n050', 'lat' => self::north(-60.0)]),
            self::hostRow(['place_key' => 'w400', 'lat' => self::north(100.5)]),
        ];
        $db = self::database(['hosts' => $hosts]);
        $near = self::service($db)->hostsNear('dc', self::LAT, self::LNG, 100.0);
        self::assertSame(
            [
                ['place_key' => 'n050', 'place_type' => 'taproom', 'visitor_segment' => 'v_nightlife', 'lat' => self::north(-60.0), 'lng' => self::LNG, 'distance_m' => 60.0],
                ['place_key' => 'w100', 'place_type' => 'taproom', 'visitor_segment' => 'v_nightlife', 'lat' => self::north(60.0), 'lng' => self::LNG, 'distance_m' => 60.0],
                ['place_key' => 'w300', 'place_type' => 'office_park', 'visitor_segment' => null, 'lat' => self::north(90.0), 'lng' => self::LNG, 'distance_m' => 90.0],
            ],
            $near
        );
        $box = PointRepository::box(self::LAT, self::LNG, 100.0);
        $call = $db->only(self::Q3);
        self::assertSame(json_encode($box['lng_max']), $call['params'][5]);
        self::assertSame('', $call['params'][6], 'one page, from the start');
    }
}
