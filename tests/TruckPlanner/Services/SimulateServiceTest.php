<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\CalibrationProvider;
use App\TruckPlanner\Services\Contracts\DayContextProvider;
use App\TruckPlanner\Services\Fallback\PlainDayContexts;
use App\TruckPlanner\Services\Fallback\SeedFuelPrice;
use App\TruckPlanner\Services\SimulateService;
use App\TruckPlanner\Services\SpotService;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use PHPUnit\Framework\TestCase;

// The fixture region, its region service and the truck value live with the spot service's test. They are
// not test cases, so the autoloader cannot find them by name: the file is loaded here.
require_once __DIR__ . '/SpotServiceTest.php';

/**
 * SimulateService on the fixture region of SpotServiceTest: the capture contract is answered from rows in
 * memory with the model's own functions, so every number below can be recomputed from 02_MODEL.md.
 */
final class SimulateServiceTest extends TestCase
{
    private const ORG = SpotServiceTest::ORG;
    private const SPOT = '44444444-4444-4444-8444-444444444444';

    private FixtureRegion $region;
    private RecordingDatabase $spotRows;
    private RecordingDatabase $packRows;
    private FixedClock $clock;
    private SimulateService $service;

    /** @var object{asOf: list<string>, spotId: ?string}&CalibrationProvider */
    private object $calibration;

    /** @var object{asked: list<array{0: string, 1: int, 2: array<string, ?string>}>}&DayContextProvider */
    private object $contexts;

    protected function setUp(): void
    {
        $this->region = FixtureRegion::standard();
        $regions = new FixtureRegions($this->region);
        $this->spotRows = new RecordingDatabase();
        $this->packRows = new RecordingDatabase();
        // 03:30 UTC on Thursday is still Wednesday evening where the truck is.
        $this->clock = new FixedClock('2026-10-08 03:30:00');

        $this->calibration = new class implements CalibrationProvider {
            /** @var list<string> */
            public array $asOf = [];
            public ?string $spotId = null;

            public function state(string $orgId, array $truck, array $A, string $asOf): array
            {
                $this->asOf[] = $asOf;
                $state = Estimator::calibrate($A, [], $asOf);
                if ($this->spotId !== null) {
                    $state['truck_factor'] = 0.9;
                    $state['truck_weight'] = 6.0;
                    $state['spots'][$this->spotId] = ['factor' => 1.2, 'log_factor' => 0.1823215567939546, 'n' => 3, 'weight' => 2.5];
                }
                return $state;
            }
        };
        $this->contexts = new class implements DayContextProvider {
            /** @var list<array{0: string, 1: int, 2: array<string, ?string>}> */
            public array $asked = [];

            public function contexts(array $truck, array $A, string $from, int $days, array $treatAs = []): array
            {
                $this->asked[] = [$from, $days, $treatAs];
                return (new PlainDayContexts())->contexts($truck, $A, $from, $days, $treatAs);
            }
        };

        Registry::reset();
        Registry::set('capture', $this->region);
        Registry::set('calibration', $this->calibration);
        Registry::set('dayContexts', $this->contexts);
        Registry::set('fuel', new SeedFuelPrice($regions));
        Registry::set('legs', new RecordingLegs());

        $spots = new SpotRepository($this->spotRows);
        $this->service = new SimulateService(
            new SpotService($spots, new CountsRepository(new RecordingDatabase()), $regions),
            $spots,
            new RegionRepository($this->packRows),
            $this->clock
        );
    }

    protected function tearDown(): void
    {
        Registry::reset();
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * @return array<string, mixed> Assumptions of the fixture truck: no overrides, the fixture region
     */
    private static function A(): array
    {
        return Seeds::assumptions([], ['id' => FixtureRegion::REGION, 'traffic_matrix' => 'us_mean', 'flags' => ['inauguration_day' => false]]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function simulate(array $body, ?array $truck = null): array
    {
        return $this->service->run(self::ORG, $truck ?? SpotServiceTest::truck(), self::A(), $body);
    }

    /**
     * What capture answers for one visibility at a point: the model's vectors with the labels of the region.
     *
     * @param array{lat: float, lng: float} $point
     * @param array<string, mixed>|null $host
     * @return array<string, mixed> LocationVectors
     */
    private function expectedVectors(array $point, string $level, ?array $host = null, bool $inRegion = true): array
    {
        $A = Seeds::defaults();
        $vectors = Estimator::captureAtPoint(
            $A,
            $point['lat'],
            $point['lng'],
            $level,
            $this->region->sourcePoints(),
            $this->region->modelOutlets(),
            Estimator::hostExclusion($A, $host)
        );
        $vectors['in_region'] = $inRegion;
        $vectors['region_id'] = FixtureRegion::REGION;
        $vectors['dataset_version'] = FixtureRegion::VERSION;
        return $vectors;
    }

    /**
     * @param array<string, mixed>|null $host
     * @return array<string, mixed> SpotTerms without fee
     */
    private static function terms(string $level, ?array $host = null, ?string $spotId = null): array
    {
        return ['spot_id' => $spotId, 'visibility' => $level, 'host' => $host, 'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null];
    }

    private static function assertInvalid(string $message, ?string $field, ?string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail('no validation error, expected: ' . $message);
        } catch (TpInvalid $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame($field, $e->field());
            self::assertSame($code, $e->rule());
        }
    }

    // ------------------------------------------------------------------------------------ the answer

    public function testVectorsPerVisibilityOutletsByDistanceAndAnEstimateEqualToTheWeekStrip(): void
    {
        $answer = $this->simulate(['point' => FixtureRegion::OFFICE, 'visibilities' => ['normal', 'prominent']]);
        $profile = SpotServiceTest::truck()['profile'];
        $A = self::A();

        self::assertSame(
            ['located', 'vectors', 'host', 'outlets', 'outlets_total', 'hosts_nearby', 'estimate', 'calibration',
                'dataset_version', 'model_version', 'seeds_revision', 'attribution'],
            array_keys($answer)
        );
        self::assertSame(['in_region' => true, 'region_id' => 'mini', 'county_fips' => '51059', 'state' => 'VA'], $answer['located']);

        // one vector set per requested visibility, exactly the model's
        self::assertSame(['normal', 'prominent'], array_keys($answer['vectors']));
        foreach (['normal', 'prominent'] as $level) {
            self::assertSame($this->expectedVectors(FixtureRegion::OFFICE, $level), $answer['vectors'][$level]);
        }
        self::assertEqualsWithDelta(417.134520, $answer['vectors']['normal']['capture']['day'][1], 1e-6);
        self::assertEqualsWithDelta(509.479077, $answer['vectors']['prominent']['capture']['day'][1], 1e-6);
        self::assertEqualsWithDelta(1114.962841, $answer['vectors']['normal']['nearby'][1], 1e-6);
        self::assertSame(2000.0, $answer['vectors']['normal']['within'][1]);
        self::assertSame(8, $answer['vectors']['normal']['points_used']);
        self::assertNull($answer['host']);

        // outlets nearest first
        self::assertSame(2, $answer['outlets_total']);
        self::assertSame(['n100', 'n101'], array_column($answer['outlets'], 'place_key'));
        self::assertSame([340.0, 360.0], array_column($answer['outlets'], 'distance_m'));
        self::assertSame(
            ['place_key', 'name', 'place_type', 'rival_kind', 'kitchen', 'lat', 'lng', 'distance_m'],
            array_keys($answer['outlets'][0])
        );
        self::assertSame('Example Grill', $answer['outlets'][0]['name']);
        self::assertSame(
            [[
                'place_key' => FixtureRegion::OFFICE_PARK_KEY, 'name' => null, 'place_type' => 'office_park',
                'lat' => FixtureRegion::north(38.96, 20.0), 'lng' => -77.36, 'distance_m' => 20.0, 'host_segment' => 'w_office',
                'default_size' => 0.0, 'kitchen' => 'no', 'point_id' => null,
            ]],
            $answer['hosts_nearby']
        );

        // the estimate is the model on those vectors, with the first requested visibility
        $terms = self::terms('normal');
        $cal = Estimator::calibrate($A, [], '2026-10-07');
        $strip = Estimator::weekStrip($A, $profile, $terms, $answer['vectors']['normal'], $cal);
        $estimate = $answer['estimate'];
        self::assertSame(['week_strip', 'best_windows', 'typical', 'dated'], array_keys($estimate));
        self::assertCount(168, $estimate['week_strip']);
        self::assertSame($strip, $estimate['week_strip']);
        self::assertSame(Estimator::bestWindows($strip, 3, 3, true), $estimate['best_windows']);
        self::assertSame([35, 59, 83], array_column($estimate['best_windows'], 'start'), 'Tuesday, Wednesday, Thursday at 11:00');
        self::assertEqualsWithDelta(66.6553, $estimate['best_windows'][0]['total'], 5e-5);
        self::assertEqualsWithDelta(64.9749, $estimate['best_windows'][1]['total'], 5e-5);
        self::assertEqualsWithDelta(60.4938, $estimate['best_windows'][2]['total'], 5e-5);

        // the best window in full: a range, a label and the breakdown behind it
        $typical = $estimate['typical'];
        self::assertSame(['dow', 'open_minute', 'close_minute', 'window', 'money'], array_keys($typical));
        self::assertSame([1, 660, 840], [$typical['dow'], $typical['open_minute'], $typical['close_minute']]);
        $window = Estimator::windowOrders($A, $profile, $terms, $answer['vectors']['normal'], $cal, Estimator::typicalContext($A, 1), Estimator::typicalContext($A, 2), 660, 840);
        self::assertSame($window, $typical['window']);
        self::assertEqualsWithDelta(66.6553, $typical['window']['orders']['value'], 5e-5);
        self::assertEqualsWithDelta(36.60, $typical['window']['orders']['low'], 5e-3);
        self::assertEqualsWithDelta(97.93, $typical['window']['orders']['high'], 5e-3);
        self::assertSame('rough', $typical['window']['orders']['confidence']);
        self::assertCount(3, $typical['window']['hours']);
        self::assertCount(16, $typical['window']['hours'][0]['result']['segments']);
        self::assertSame('typical', $typical['window']['hours'][0]['result']['factors']['weather_state']);
        self::assertSame(Estimator::stopMoney($profile, $terms, $window['orders']), $typical['money']);
        self::assertLessThan($typical['money']['contribution']['value'], $typical['money']['contribution']['low']);
        self::assertGreaterThan($typical['money']['contribution']['value'], $typical['money']['contribution']['high']);
        self::assertNull($estimate['dated']);

        self::assertSame(['truck_factor' => 1.0, 'spot_factor' => 1.0], $answer['calibration']);
        self::assertSame(FixtureRegion::VERSION, $answer['dataset_version']);
        self::assertSame('tps-0.1.0', $answer['model_version']);
        self::assertSame(Seeds::revision(), $answer['seeds_revision']);

        // nothing was stored, nothing was read from the spot table, and capture ran once
        self::assertSame([], $this->spotRows->calls);
        self::assertSame(1, $this->region->count('capture'));
    }

    public function testTheFirstRequestedVisibilityDrivesTheEstimate(): void
    {
        $profile = SpotServiceTest::truck()['profile'];
        $A = self::A();
        $cal = Estimator::calibrate($A, [], '2026-10-07');

        $answer = $this->simulate(['point' => FixtureRegion::OFFICE, 'visibilities' => ['prominent', 'hidden', 'prominent'], 'terms' => ['visibility' => 'normal']]);
        self::assertSame(['prominent', 'hidden'], array_keys($answer['vectors']), 'each level once, in the order sent');
        self::assertSame(
            Estimator::weekStrip($A, $profile, self::terms('prominent'), $answer['vectors']['prominent'], $cal),
            $answer['estimate']['week_strip']
        );
        // Thursday 11:00 to 14:00 at "prominent" is 73.89 orders
        self::assertEqualsWithDelta(73.89, array_sum(array_slice($answer['estimate']['week_strip'], 83, 3)), 5e-3);

        // without `visibilities`: the visibility of the terms, else normal
        $fromTerms = $this->simulate(['point' => FixtureRegion::OFFICE, 'terms' => ['visibility' => 'hidden']]);
        self::assertSame(['hidden'], array_keys($fromTerms['vectors']));
        self::assertEqualsWithDelta(39.72, array_sum(array_slice($fromTerms['estimate']['week_strip'], 83, 3)), 5e-3);
        $default = $this->simulate(['point' => FixtureRegion::OFFICE]);
        self::assertSame(['normal'], array_keys($default['vectors']));
    }

    public function testFeesOfTheTermsReachTheMoney(): void
    {
        $answer = $this->simulate(['point' => FixtureRegion::OFFICE, 'terms' => ['fee_pct' => 0.1, 'fee_min' => 75]]);
        $money = $answer['estimate']['typical']['money'];
        $sales = $money['sales']['value'];
        self::assertEqualsWithDelta(0.1 * $sales, $money['spot_fee']['value'], 1e-9, 'ten percent of sales is above the minimum');
        self::assertEqualsWithDelta(8.041, $money['unit_margin']['at_percentage'], 1e-9);
        self::assertEqualsWithDelta(9.541, $money['unit_margin']['at_minimum'], 1e-9);
    }

    public function testAPointWithNoSourceInReachAnswersZeroVectors(): void
    {
        $answer = $this->simulate(['point' => FixtureRegion::EMPTY, 'visibilities' => ['hidden', 'normal', 'prominent']]);

        self::assertSame(['in_region' => false, 'region_id' => 'mini', 'county_fips' => null, 'state' => null], $answer['located']);
        $zeros = array_fill(0, 16, 0.0);
        foreach (['hidden', 'normal', 'prominent'] as $level) {
            $vectors = $answer['vectors'][$level];
            self::assertSame($zeros, $vectors['capture']['day']);
            self::assertSame($zeros, $vectors['capture']['eve']);
            self::assertSame($zeros, $vectors['nearby']);
            self::assertSame(['day' => 0.0, 'eve' => 0.0], $vectors['rivals']);
            self::assertSame(0, $vectors['points_used']);
            self::assertFalse($vectors['in_region']);
        }
        self::assertSame([], $answer['outlets']);
        self::assertSame(0, $answer['outlets_total']);
        self::assertSame([], $answer['hosts_nearby']);
        self::assertSame(array_fill(0, 168, 0.0), $answer['estimate']['week_strip']);
        self::assertSame([], $answer['estimate']['best_windows']);
        self::assertNull($answer['estimate']['typical']);
        self::assertSame(FixtureRegion::VERSION, $answer['dataset_version'], 'the region was read, it holds nothing here');
    }

    public function testAPointWhoseNearestBlockIsAHaloBlockKeepsItsVectors(): void
    {
        $answer = $this->simulate(['point' => FixtureRegion::HALO]);

        self::assertSame(['in_region' => false, 'region_id' => 'mini', 'county_fips' => '24033', 'state' => 'MD'], $answer['located']);
        self::assertSame($this->expectedVectors(FixtureRegion::HALO, 'normal', null, false), $answer['vectors']['normal']);
        self::assertFalse($answer['vectors']['normal']['in_region']);
        self::assertSame(1, $answer['vectors']['normal']['points_used']);
        self::assertGreaterThan(50.0, $answer['vectors']['normal']['capture']['day'][1], 'the label never zeroes a vector');
        self::assertNotSame([], $answer['estimate']['best_windows']);
        self::assertGreaterThan(5.0, $answer['estimate']['typical']['window']['orders']['value']);
    }

    public function testATruckWithoutARegionGetsTheSameShapeWithNothingInIt(): void
    {
        $answer = $this->simulate(['point' => FixtureRegion::OFFICE], SpotServiceTest::truck('none'));
        self::assertSame(['in_region' => false, 'region_id' => null, 'county_fips' => null, 'state' => null], $answer['located']);
        self::assertSame(array_fill(0, 16, 0.0), $answer['vectors']['normal']['nearby']);
        self::assertNull($answer['vectors']['normal']['dataset_version']);
        self::assertNull($answer['dataset_version']);
        self::assertNull($answer['estimate']['typical']);
        self::assertCount(2, $answer['attribution']);
        self::assertSame([], $this->packRows->calls, 'no dataset, no manifest');
    }

    // ------------------------------------------------------------------------------------ hosts

    public function testADescribedTaproomHostIsLinkedToTheTaproomItStandsAt(): void
    {
        $answer = $this->simulate([
            'point' => FixtureRegion::TAPROOM,
            'visibilities' => ['normal'],
            'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120, 'only_food' => true]],
        ]);
        $point = 'p' . FixtureRegion::TAPROOM_KEY;

        self::assertSame(
            ['segment' => 'v_nightlife', 'size' => 120.0, 'size_source' => 'owner', 'only_food' => true, 'point_id' => $point, 'place_type' => 'taproom'],
            $answer['host']
        );
        self::assertSame(['point_ids' => [$point], 'segment' => null, 'amount' => 0.0], $answer['vectors']['normal']['exclusion']);
        // The venue stands alone: no census block within reach, so the point is labelled outside. The label
        // changes nothing in the numbers.
        self::assertFalse($answer['located']['in_region']);
        self::assertSame($this->expectedVectors(FixtureRegion::TAPROOM, 'normal', $answer['host'], false), $answer['vectors']['normal']);
        self::assertTrue(Estimator::vectorsMatch(Seeds::defaults(), self::terms('normal', $answer['host']), $answer['vectors']['normal']));

        // anchor A2 of 02_MODEL.md: 98 open hours, 441.4130 orders a week, Saturday 17:00 first
        $strip = $answer['estimate']['week_strip'];
        self::assertCount(98, array_filter($strip, static fn (float $x): bool => $x > 0.0));
        self::assertEqualsWithDelta(441.4130, array_sum($strip), 5e-5);
        self::assertSame([137, 113, 89], array_column($answer['estimate']['best_windows'], 'start'));
        self::assertEqualsWithDelta(64.5480, $answer['estimate']['best_windows'][0]['total'], 5e-5);
        $typical = $answer['estimate']['typical'];
        self::assertSame([5, 1020, 1200], [$typical['dow'], $typical['open_minute'], $typical['close_minute']]);
        self::assertEqualsWithDelta(64.5480, $typical['window']['host_orders'], 5e-5);
        self::assertSame('captive', $typical['window']['hours'][0]['result']['host']['mode']);
        self::assertSame('rough', $typical['window']['orders']['confidence']);

        // the place is offered to the form as a host nearby
        self::assertSame([FixtureRegion::TAPROOM_KEY], array_column($answer['hosts_nearby'], 'place_key'));
        self::assertSame($point, $answer['hosts_nearby'][0]['point_id']);
        self::assertSame(40.0, $answer['hosts_nearby'][0]['default_size']);
        self::assertSame('no', $answer['hosts_nearby'][0]['kitchen']);
        self::assertSame(0.0, $answer['hosts_nearby'][0]['distance_m']);
    }

    public function testALinkedHostTakesItsDefaultsFromThePlaceAndSaysSoInTheLabel(): void
    {
        $answer = $this->simulate(['point' => FixtureRegion::TAPROOM, 'terms' => ['host' => ['place_key' => FixtureRegion::TAPROOM_KEY]]]);
        self::assertSame(
            ['segment' => 'v_nightlife', 'size' => 40.0, 'size_source' => 'default', 'only_food' => true,
                'point_id' => 'p' . FixtureRegion::TAPROOM_KEY, 'place_type' => 'taproom'],
            $answer['host']
        );
        // a host size from a place-type default makes the estimate "very rough" (02_MODEL.md 4.8)
        $typical = $answer['estimate']['typical'];
        self::assertSame('very_rough', $typical['window']['orders']['confidence']);
        self::assertEqualsWithDelta(1.0, $typical['window']['evidence']['default_size_share'], 1e-12);
        self::assertLessThan($typical['window']['orders']['value'], $typical['window']['orders']['low']);

        // a linked place whose type hosts nothing: no host, the request does not fail
        $market = $this->simulate(['point' => FixtureRegion::MARKET, 'terms' => ['host' => ['place_key' => FixtureRegion::MARKET_KEY]]]);
        self::assertNull($market['host']);
        self::assertSame([], $market['vectors']['normal']['exclusion']['point_ids']);

        // a host beside an unnamed venue takes that venue's point
        $lone = $this->simulate(['point' => FixtureRegion::LONE, 'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120]]]);
        self::assertSame('p' . FixtureRegion::LONE_VENUE_KEY, $lone['host']['point_id']);
        self::assertNull($lone['host']['place_type']);
        self::assertSame(['p' . FixtureRegion::LONE_VENUE_KEY], $lone['vectors']['normal']['exclusion']['point_ids']);
    }

    public function testAHostOfWorkersTakesItsPeopleOutOfTheBlocks(): void
    {
        $answer = $this->simulate([
            'point' => FixtureRegion::OFFICE,
            'visibilities' => ['prominent'],
            'terms' => ['host' => ['segment' => 'w_office', 'size' => 600, 'only_food' => true]],
        ]);
        $vectors = $answer['vectors']['prominent'];
        self::assertSame(['point_ids' => [], 'segment' => 'w_office', 'amount' => 600.0], $vectors['exclusion']);
        self::assertSame(600.0, $vectors['excluded_amount']);
        self::assertEqualsWithDelta(326.105662, $vectors['capture']['day'][1], 1e-6);
        self::assertSame(1400.0, $vectors['within'][1]);
        // Thursday 11:00 to 14:00: 77.59 orders, 30.29 of them through the host
        self::assertEqualsWithDelta(77.59, array_sum(array_slice($answer['estimate']['week_strip'], 83, 3)), 5e-3);
    }

    // ------------------------------------------------------------------------------------ a date

    public function testADateAddsThatDaysWindowWithItsContext(): void
    {
        $answer = $this->simulate(['point' => FixtureRegion::OFFICE, 'date' => '2026-10-08', 'open_minute' => 660, 'close_minute' => 840]);
        $dated = $answer['estimate']['dated'];

        self::assertSame(['date', 'window', 'money', 'context'], array_keys($dated));
        self::assertSame('2026-10-08', $dated['date']);
        self::assertEqualsWithDelta(60.4938, $dated['window']['orders']['value'], 5e-5);
        self::assertEqualsWithDelta(33.01, $dated['window']['orders']['low'], 5e-3);
        self::assertEqualsWithDelta(93.19, $dated['window']['orders']['high'], 5e-3);
        self::assertSame('rough', $dated['window']['orders']['confidence']);
        self::assertSame('missing', $dated['window']['hours'][0]['result']['factors']['weather_state'], 'no forecast, said so');
        self::assertEqualsWithDelta(577.17, $dated['money']['contribution']['value'], 5e-3);
        self::assertSame('2026-10-08', $dated['context']['date']);
        self::assertSame(3, $dated['context']['dow']);
        self::assertFalse($dated['context']['typical']);
        self::assertNull($dated['context']['treat_as']);
        self::assertSame('seed', $dated['context']['fuel_price_source']);
        // a window inside one civil date asks for one day context
        self::assertSame([['2026-10-08', 1, ['2026-10-08' => null]]], $this->contexts->asked);
        // the typical week is still there
        self::assertSame([1, 660, 840], [$answer['estimate']['typical']['dow'], $answer['estimate']['typical']['open_minute'], $answer['estimate']['typical']['close_minute']]);
    }

    public function testTreatAsAndAWindowPastMidnight(): void
    {
        $saturday = $this->simulate(['point' => FixtureRegion::OFFICE, 'date' => '2026-10-08', 'open_minute' => 660, 'close_minute' => 840, 'treat_as' => 'sat']);
        self::assertSame('sat', $saturday['estimate']['dated']['context']['treat_as']);
        self::assertSame(5, $saturday['estimate']['dated']['context']['eff_dow']);
        self::assertEqualsWithDelta(3.5421, $saturday['estimate']['dated']['window']['orders']['value'], 5e-5);

        // Columbus Day is a holiday the context knows by itself
        $holiday = $this->simulate(['point' => FixtureRegion::OFFICE, 'date' => '2026-10-12', 'open_minute' => 660, 'close_minute' => 840, 'treat_as' => null]);
        self::assertSame('columbus', $holiday['estimate']['dated']['context']['holiday']['id']);

        // Friday 21:30 to 01:00 at the taproom: the hour after midnight is Saturday's
        $this->contexts->asked = [];
        $night = $this->simulate([
            'point' => FixtureRegion::TAPROOM,
            'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120, 'only_food' => true]],
            'date' => '2026-10-09',
            'open_minute' => 1290,
            'close_minute' => 1500,
        ]);
        self::assertSame([['2026-10-09', 2, ['2026-10-09' => null]]], $this->contexts->asked);
        $window = $night['estimate']['dated']['window'];
        self::assertEqualsWithDelta(5.1651, $window['orders']['value'], 5e-5);
        self::assertSame([0, 0, 0, 1], array_column($window['hours'], 'day_index'));
        self::assertSame('2026-10-10', $window['hours'][3]['result']['date']);
    }

    // ------------------------------------------------------------------------------------ calibration

    public function testASpotIdAppliesThatSpotsFactorAndTodayIsTheTrucksDay(): void
    {
        $this->calibration->spotId = self::SPOT;
        $this->spotRows->when('FROM tp_spots', [
            'id' => self::SPOT, 'organization_id' => self::ORG, 'truck_id' => SpotServiceTest::TRUCK, 'created_by' => null,
            'name' => 'Saved', 'lat' => 38.96, 'lng' => -77.36, 'address' => '', 'county_fips' => null, 'notes' => null,
            'visibility' => 'hidden', 'host_segment' => null, 'host_size' => null, 'host_size_source' => null, 'host_only_food' => 0,
            'host_place_type' => null, 'host_name' => null, 'host_contact' => null, 'host_phone' => null, 'host_website' => null,
            'place_key' => null, 'host_point_id' => null, 'google_place_id' => null, 'fee_flat_cents' => 9900, 'fee_pct' => 0.5,
            'fee_min_cents' => 9900, 'allowed_json' => null, 'vectors_bin' => null, 'vec_in_region' => 0, 'vec_points_used' => 0,
            'vec_excluded' => 0.0, 'vec_region_id' => null, 'vec_dataset' => null, 'vec_seeds_rev' => null, 'vec_at' => null,
            'archived_at' => '2026-10-01 10:00:00', 'created_at' => '2026-09-01 10:00:00', 'updated_at' => '2026-10-01 10:00:00',
        ]);

        $answer = $this->simulate(['point' => FixtureRegion::OFFICE, 'spot_id' => self::SPOT]);

        // the wall clock is read in the truck's zone, whatever zone the process runs in
        self::assertSame(['2026-10-07'], $this->calibration->asOf);
        self::assertSame(['truck_factor' => 0.9, 'spot_factor' => 1.2], $answer['calibration']);
        $call = $this->spotRows->only('FROM tp_spots');
        self::assertSame([self::SPOT, self::ORG], $call['params']);
        self::assertStringNotContainsString('archived_at IS NULL', $call['sql'], 'an archived spot keeps its factor');

        $A = self::A();
        $profile = SpotServiceTest::truck()['profile'];
        $cal = $this->calibration->state(self::ORG, [], $A, '2026-10-07');
        $terms = self::terms('normal', null, self::SPOT);
        self::assertSame(Estimator::weekStrip($A, $profile, $terms, $answer['vectors']['normal'], $cal), $answer['estimate']['week_strip']);
        // the terms come from the body, not from the spot: no fee, visibility normal
        self::assertSame(0.0, $answer['estimate']['typical']['money']['spot_fee']['value']);
        self::assertSame(['normal'], array_keys($answer['vectors']));
        $factors = $answer['estimate']['typical']['window']['hours'][0]['result']['factors'];
        self::assertSame(0.9, $factors['truck_factor']);
        self::assertSame(1.2, $factors['spot_factor']);
        self::assertEqualsWithDelta(66.6553 * 0.9 * 1.2, $answer['estimate']['typical']['window']['orders']['value'], 5e-4);
        self::assertSame(2.5, $answer['estimate']['typical']['window']['evidence']['spot_weight']);

        // an id that is not a spot of this organization
        $unknown = new SpotRepository(new RecordingDatabase());
        $service = new SimulateService(new SpotService($unknown, null, new FixtureRegions($this->region)), $unknown, new RegionRepository($this->packRows), $this->clock);
        self::assertInvalid('spot_id was not found', 'spot_id', 'V11', static fn () => $service->run(self::ORG, SpotServiceTest::truck(), $A, ['point' => FixtureRegion::OFFICE, 'spot_id' => self::SPOT]));
    }

    // ------------------------------------------------------------------------------------ validation

    public function testTheBodyIsValidatedInTheOrderOfTheFieldTable(): void
    {
        $point = FixtureRegion::OFFICE;
        $run = fn (array $body) => fn () => $this->simulate($body);

        self::assertInvalid('point is required', 'point', 'V1', $run([]));
        self::assertInvalid('point must have lat between -90 and 90 and lng between -180 and 180', 'point', 'V10', $run(['point' => ['lat' => '38.96', 'lng' => -77.36]]));
        self::assertInvalid('visibilities must be a list of 1 to 3 items', 'visibilities', 'V8', $run(['point' => $point, 'visibilities' => []]));
        self::assertInvalid('visibilities must be a list of 1 to 3 items', 'visibilities', 'V8', $run(['point' => $point, 'visibilities' => 'normal']));
        self::assertInvalid('visibilities must be a list of 1 to 3 items', 'visibilities', 'V8', $run(['point' => $point, 'visibilities' => ['hidden', 'normal', 'prominent', 'normal']]));
        self::assertInvalid('visibilities[1] must be one of: hidden, normal, prominent', 'visibilities[1]', 'V4', $run(['point' => $point, 'visibilities' => ['normal', 'bright']]));
        self::assertInvalid('terms.fee_pct must be a number between 0 and 1', 'terms.fee_pct', 'V2', $run(['point' => $point, 'terms' => ['fee_pct' => 1.5]]));
        self::assertInvalid('terms.host.segment is required', 'terms.host.segment', 'V1', $run(['point' => $point, 'terms' => ['host' => ['size' => 10]]]));
        self::assertInvalid('terms.host.place_key was not found', 'terms.host.place_key', 'V11', $run(['point' => $point, 'terms' => ['host' => ['place_key' => 'w404']]]));
        self::assertInvalid('terms.host.size is required for this kind of place', 'terms.host.size', null, $run(['point' => $point, 'terms' => ['host' => ['place_key' => FixtureRegion::OFFICE_PARK_KEY]]]));
        self::assertInvalid('visibilities is required', 'visibilities', 'V1', $run(['point' => $point, 'visibilities' => null]));
        self::assertInvalid('terms is required', 'terms', 'V1', $run(['point' => $point, 'terms' => null]));
        self::assertInvalid('spot_id is required', 'spot_id', 'V1', $run(['point' => $point, 'spot_id' => null]));
        self::assertInvalid('open_minute is required', 'open_minute', 'V1', $run(['point' => $point, 'date' => '2026-10-08', 'open_minute' => null, 'close_minute' => 60]));
        self::assertInvalid('spot_id must be text of at most 36 characters', 'spot_id', 'V5', $run(['point' => $point, 'spot_id' => 44]));
        self::assertInvalid('spot_id was not found', 'spot_id', 'V11', $run(['point' => $point, 'spot_id' => '']));
        self::assertInvalid('date must be a date in the form YYYY-MM-DD', 'date', 'V7', $run(['point' => $point, 'date' => '2026-02-30', 'open_minute' => 0, 'close_minute' => 60]));
        self::assertInvalid('open_minute must be a whole number between 0 and 2880', 'open_minute', 'V3', $run(['point' => $point, 'date' => '2026-10-08', 'open_minute' => -1, 'close_minute' => 60]));
        self::assertInvalid('close_minute must be a whole number between 0 and 2880', 'close_minute', 'V3', $run(['point' => $point, 'date' => '2026-10-08', 'open_minute' => 0, 'close_minute' => 2881]));
        self::assertInvalid(
            'treat_as must be one of: normal, holiday, mon, tue, wed, thu, fri, sat, sun',
            'treat_as',
            'V4',
            $run(['point' => $point, 'date' => '2026-10-08', 'open_minute' => 0, 'close_minute' => 60, 'treat_as' => 'weekend'])
        );
        foreach ([['date' => '2026-10-08'], ['open_minute' => 660, 'close_minute' => 840], ['date' => '2026-10-08', 'close_minute' => 840]] as $partial) {
            self::assertInvalid('date, open_minute and close_minute must be given together', null, null, $run(['point' => $point] + $partial));
        }
        self::assertInvalid('close_minute must be after open_minute', 'close_minute', null, $run(['point' => $point, 'date' => '2026-10-08', 'open_minute' => 840, 'close_minute' => 840]));
        // the last date the model knows has no next day for a window past midnight
        self::assertInvalid('date must be a date in the form YYYY-MM-DD', 'date', 'V7', $run(['point' => $point, 'date' => '2199-12-31', 'open_minute' => 1380, 'close_minute' => 1500]));
        self::assertSame(1380, $this->simulate(['point' => $point, 'date' => '2199-12-31', 'open_minute' => 1380, 'close_minute' => 1440])['estimate']['dated']['window']['open_minute']);

        self::assertSame(1, $this->region->count('capture'), 'a body that fails is never captured');
    }

    // ------------------------------------------------------------------------------------ sources, conflicts

    public function testTheSourceLinesAreFilledFromTheManifestOfTheDatasetThatWasRead(): void
    {
        $osm = '© OpenStreetMap contributors';
        $residents = 'Residents: U.S. Census Bureau, 2020 Census Redistricting Data (Public Law 94-171). Counts as of April 1, 2020, not adjusted for growth.';

        // no manifest row: the two lines without placeholders
        $answer = $this->simulate(['point' => FixtureRegion::OFFICE]);
        self::assertSame([$osm, $residents], $answer['attribution']);
        $call = $this->packRows->only('manifest_json');
        self::assertSame([FixtureRegion::REGION, FixtureRegion::VERSION], $call['params']);

        $this->packRows->when('manifest_json', ['manifest_json' => json_encode([
            'inputs' => ['corrections_version' => '2026-10-04.1'],
            'parameters' => ['cns04_weight' => 0.3],
            'totals' => ['jobs_spread' => 196940.5, 'blocks_adjusted' => 31],
        ])]);
        $answer = $this->simulate(['point' => FixtureRegion::OFFICE]);
        self::assertSame(
            [
                $osm,
                $residents,
                'Jobs: U.S. Census Bureau, LEHD Origin-Destination Employment Statistics (LODES), version 8.4, 2023, all jobs. '
                . 'Job counts are jobs of record with statistical noise added by the Census Bureau, not people present. '
                . '31 payroll-address blocks holding 196941 jobs were spread over their county (corrections 2026-10-04.1); '
                . 'construction jobs count at 30 %.',
            ],
            $answer['attribution']
        );
        foreach ($answer['attribution'] as $line) {
            self::assertStringNotContainsString('{', $line);
        }

        // a manifest that cannot fill a placeholder: the line is left out, not sent with a hole
        $partial = new RecordingDatabase();
        $partial->when('manifest_json', ['manifest_json' => '{"totals": {"jobs_spread": 196941}, "parameters": {"cns04_weight": 0.3}}']);
        $spots = new SpotRepository($this->spotRows);
        $service = new SimulateService(new SpotService($spots, null, new FixtureRegions($this->region)), $spots, new RegionRepository($partial), $this->clock);
        $answer = $service->run(self::ORG, SpotServiceTest::truck(), self::A(), ['point' => FixtureRegion::OFFICE]);
        self::assertSame([$osm, $residents], $answer['attribution']);
    }

    public function testRegionDataBuiltWithOtherConstantsIsAConflict(): void
    {
        $this->region->usable = false;
        try {
            $this->simulate(['point' => FixtureRegion::OFFICE]);
            self::fail('vectors were computed from data that does not fit');
        } catch (TpConflict $e) {
            self::assertSame('Region data was built with different model constants', $e->getMessage());
        }
        self::assertSame([], $this->calibration->asOf, 'nothing was estimated');
    }
}
