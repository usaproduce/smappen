<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\BootstrapService;
use App\TruckPlanner\Services\Contracts\CalibrationProvider;
use App\TruckPlanner\Services\Contracts\FuelPriceProvider;
use App\TruckPlanner\Services\Contracts\LegProvider;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\Support\Clock;
use App\TruckPlanner\Services\Support\JsonSafe;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

final class BootstrapServiceTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';

    /** The fields of 04_BACKEND.md 4.3, in its order. */
    private const FIELDS = [
        'model_version', 'seeds_revision', 'has_truck', 'truck', 'profile_defaults', 'assumptions', 'region', 'regions',
        'calibration', 'fuel', 'timezone', 'today', 'now_minute', 'counts', 'routing', 'limits',
    ];

    private const FUEL = ['price_per_gal' => 4.195, 'source' => 'seed', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'];

    private RecordingDatabase $db;

    /** 03:30 UTC on Monday 2026-10-05: still Sunday evening in New York, Monday afternoon in Auckland. */
    private FixedClock $clock;

    /** @var object the calibration provider; `$calls` are the arguments it was given */
    private object $calibration;

    /** @var object the fuel provider; `$trucks` are the truck values it was asked about */
    private object $fuel;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-05 03:30:00');
        TpCache::wire($this->clock, new MemoryCache());
        RegionService::forgetVerdicts();
        Registry::reset();

        $this->db = new RecordingDatabase();
        $this->db->when('FROM tp_regions WHERE region_id = ?', self::regionRow());
        $this->db->when('FROM tp_regions ORDER BY region_id', [self::regionRow()]);
        $this->db->when('FROM tp_spots', ['spots' => '3', 'plans' => '2', 'services' => '12', 'leads' => '1']);

        $this->calibration = new class implements CalibrationProvider {
            /** @var list<array{0: string, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}> */
            public array $calls = [];

            public function state(string $orgId, array $truck, array $A, string $asOf): array
            {
                $this->calls[] = [$orgId, $truck, $A, $asOf];
                // A state no fallback would give, so the test sees that the provider's answer is passed on.
                return ['truck_factor' => 1.25, 'truck_n' => 7, 'spots' => []] + Estimator::calibrate($A, [], $asOf);
            }
        };
        $this->fuel = new class(self::FUEL) implements FuelPriceProvider {
            /** @var list<array<string, mixed>> */
            public array $trucks = [];

            /** @param array<string, mixed> $answer */
            public function __construct(private array $answer)
            {
            }

            public function resolve(array $truck): array
            {
                $this->trucks[] = $truck;
                return $this->answer;
            }
        };
        Registry::set('calibration', $this->calibration);
        Registry::set('fuel', $this->fuel);
        Registry::set('legs', new class implements LegProvider {
            public function legs(string $orgId, array $truck, array $points, array $pairs, array $options = []): array
            {
                throw new \LogicException('the bootstrap answer asks for no leg');
            }

            public function status(): array
            {
                return ['state' => 'backoff'];
            }

            public function movePoint(string $orgId, array $truck, array $oldPoint, array $newPoint): void
            {
            }
        });
    }

    protected function tearDown(): void
    {
        Registry::reset();
        TpCache::wire();
        RegionService::forgetVerdicts();
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * A region row of tp_regions whose data is not loaded yet (no active version, so no pack row is read).
     *
     * @return array<string, mixed>
     */
    private static function regionRow(): array
    {
        $config = [
            'id' => 'dc', 'traffic_matrix' => 'dc', 'holidays' => ['inauguration_day' => true],
            'fuel_area_by_state' => ['VA' => 'R1Z'],
            'counties' => [['fips' => '51107', 'name' => 'Loudoun County', 'state' => 'VA']],
        ];
        return [
            'region_id' => 'dc', 'name' => 'Washington, DC region', 'cbsa' => '47900', 'timezone' => 'America/New_York',
            'h3_res' => 9, 'bbox_lat_min' => 37.9907, 'bbox_lng_min' => -78.3947, 'bbox_lat_max' => 39.7201,
            'bbox_lng_max' => -76.6625, 'center_lat' => 38.9072, 'center_lng' => -77.0369,
            'active_version' => null, 'previous_version' => null, 'config_json' => json_encode($config),
            'created_at' => '2026-10-04 20:00:00', 'updated_at' => '2026-10-04 21:00:00',
        ];
    }

    /**
     * The truck value of TruckBaseController::truck(): the normalised row plus `profile`.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function truck(array $changes = []): array
    {
        $row = $changes + [
            'id' => '33333333-3333-4333-8333-333333333333',
            'organization_id' => self::ORG,
            'created_by' => null,
            'name' => 'Smoke & Ember', 'region_id' => 'dc', 'timezone' => 'America/New_York',
            'base_lat' => 39.003, 'base_lng' => -77.405, 'base_address' => 'Sterling, VA',
            'base_state' => 'VA', 'base_county_fips' => '51107',
            'avg_ticket' => 15.0, 'capacity_orders_per_hour' => 45.0, 'paid_crew' => 2, 'wage' => 18.0,
            'payroll_burden_pct' => 0.1, 'food_cost_pct' => 0.3, 'packaging' => 0.5, 'card_fee_pct' => 0.026,
            'card_fee_fixed' => 0.15, 'card_share' => 0.85, 'tips_include' => false, 'tips_pct' => 0.1, 'mpg' => 9.0,
            'fuel_type' => 'gasoline', 'fuel_price_override' => null, 'generator_gal_per_hour' => 0.6,
            'prep_minutes' => 45, 'setup_minutes' => 30, 'teardown_minutes' => 20, 'closeout_minutes' => 30,
            'fixed_cost_day' => 0.0, 'fit_breakfast' => 0.3, 'fit_lunch' => 1.0, 'fit_dinner' => 1.0, 'fit_late' => 0.8,
            'avoid_tolls' => false, 'avoid_highways' => false, 'truck_time_factor' => 1.1,
            'licence_counties' => ['51107'], 'scout_drive_minutes_limit' => 45,
            'overrides' => [], 'overrides_seeds_rev' => Seeds::revision(),
            'created_at' => '2026-10-04 23:50:12', 'updated_at' => '2026-10-04 23:58:40',
        ];
        return $row + ['profile' => (new ProfileMapper())->toRecord($row)['profile']];
    }

    private function service(?Clock $clock = null): BootstrapService
    {
        return new BootstrapService(new RegionRepository($this->db), null, new CountsRepository($this->db), $clock ?? $this->clock);
    }

    /** What the region service itself says, read through a database of its own. */
    private static function regions(): RegionService
    {
        $db = (new RecordingDatabase())
            ->when('FROM tp_regions WHERE region_id = ?', self::regionRow())
            ->when('FROM tp_regions ORDER BY region_id', [self::regionRow()]);
        return new RegionService(new RegionRepository($db));
    }

    // ------------------------------------------------------------------------------------ without a truck

    public function testWithoutATruckItAnswersEverythingTheFirstRunStepNeeds(): void
    {
        $answer = $this->service()->build(self::ORG, null);

        self::assertSame(self::FIELDS, array_keys($answer));
        self::assertSame('tps-0.1.0', $answer['model_version']);
        self::assertSame(Seeds::revision(), $answer['seeds_revision']);
        self::assertFalse($answer['has_truck']);
        self::assertNull($answer['truck']);
        self::assertSame((new ProfileMapper())->defaults(), $answer['profile_defaults']);
        self::assertSame(
            [
                'model_version' => 'tps-0.1.0', 'seeds_revision' => Seeds::revision(), 'overrides' => [],
                'region' => ['id' => 'none', 'traffic_matrix' => 'us_mean', 'flags' => ['inauguration_day' => false]],
            ],
            $answer['assumptions']
        );
        self::assertNull($answer['region']);
        self::assertSame(self::regions()->list(), $answer['regions']);
        self::assertCount(1, $answer['regions']);
        self::assertNull($answer['fuel']);
        self::assertNull($answer['timezone']);
        self::assertNull($answer['today']);
        self::assertNull($answer['now_minute']);
        self::assertSame(['spots' => 3, 'plans' => 2, 'services' => 12, 'leads' => 1], $answer['counts']);
        self::assertSame(['state' => 'backoff'], $answer['routing']);
    }

    public function testWithoutATruckTheCalibrationIsTheStateOfNoLoggedService(): void
    {
        $answer = $this->service()->build(self::ORG, null);
        // As of today in the default zone: 23:30 on Sunday in New York.
        self::assertSame(Estimator::calibrate(Seeds::defaults(), [], '2026-10-04'), $answer['calibration']);
        self::assertSame(1.0, $answer['calibration']['truck_factor']);
        self::assertSame(0, $answer['calibration']['truck_n']);
        self::assertSame([], $answer['calibration']['spots']);
        // Neither provider is asked about a truck that does not exist.
        self::assertSame([], $this->calibration->calls);
        self::assertSame([], $this->fuel->trucks);
    }

    public function testTheLimitsAreTheSevenOfTheConfiguration(): void
    {
        $limits = $this->service()->build(self::ORG, null)['limits'];
        self::assertSame(TpConfig::get('limits'), $limits);
        self::assertSame(
            ['max_spots', 'max_stops_per_plan', 'max_points_per_drive_request', 'max_pairs_per_drive_request',
                'max_body_bytes', 'day_context_max_days', 'max_suggest_spots'],
            array_keys($limits)
        );
    }

    public function testTheCountsAreThoseOfTheCallersOrganization(): void
    {
        $this->service()->build(self::ORG, null);
        $call = $this->db->only('FROM tp_spots');
        self::assertSame([self::ORG, self::ORG, self::ORG, self::ORG], $call['params']);
        self::assertSame(4, substr_count($call['sql'], 'organization_id = ?'));
    }

    // ------------------------------------------------------------------------------------ with a truck

    public function testWithATruckItAnswersTheTruckItsRegionAndItsClock(): void
    {
        $truck = self::truck(['overrides' => ['host.captive_share' => 0.6, 'weather.floor' => 1]]);
        $answer = $this->service()->build(self::ORG, $truck);

        self::assertSame(self::FIELDS, array_keys($answer));
        self::assertTrue($answer['has_truck']);
        self::assertSame((new ProfileMapper())->toRecord($truck), $answer['truck']);
        self::assertSame(
            ['id', 'timezone', 'base_state', 'base_county_fips', 'profile', 'created_at', 'updated_at'],
            array_keys($answer['truck'])
        );
        self::assertSame(
            [
                'model_version' => 'tps-0.1.0', 'seeds_revision' => Seeds::revision(),
                'overrides' => ['host.captive_share' => 0.6, 'weather.floor' => 1.0],
                'region' => ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => true]],
            ],
            $answer['assumptions']
        );
        self::assertSame(self::regions()->info('dc'), $answer['region']);
        self::assertSame('dc', $answer['region']['region_id']);
        self::assertSame(self::regions()->list(), $answer['regions']);
        self::assertSame(self::FUEL, $answer['fuel']);
        self::assertSame([$truck], $this->fuel->trucks);

        // 03:30 UTC is 23:30 the evening before in the truck's zone.
        self::assertSame('America/New_York', $answer['timezone']);
        self::assertSame('2026-10-04', $answer['today']);
        self::assertSame(23 * 60 + 30, $answer['now_minute']);
    }

    public function testTheCalibrationIsTheProvidersAsOfTodayInTheTrucksZone(): void
    {
        $truck = self::truck(['overrides' => ['host.captive_share' => 0.6]]);
        $answer = $this->service()->build(self::ORG, $truck);

        self::assertSame(1.25, $answer['calibration']['truck_factor']);
        self::assertSame(7, $answer['calibration']['truck_n']);
        self::assertCount(1, $this->calibration->calls);
        [$orgId, $given, $A, $asOf] = $this->calibration->calls[0];
        self::assertSame(self::ORG, $orgId);
        self::assertSame($truck, $given);
        self::assertSame('2026-10-04', $asOf);
        // `A` is the truck's: the seed file, its overrides, the block of its region.
        self::assertSame(Seeds::data(), $A['seeds']);
        self::assertSame(0.6, Estimator::seed($A, 'host.captive_share'));
        self::assertSame('dc', $A['region']['id']);
    }

    public function testTodayAndNowAreThoseOfTheTrucksZoneWhateverTheServersIs(): void
    {
        $truck = self::truck(['region_id' => 'none', 'timezone' => 'Pacific/Auckland']);
        $answer = $this->service()->build(self::ORG, $truck);
        // New Zealand is 13 hours ahead of UTC in October.
        self::assertSame('Pacific/Auckland', $answer['timezone']);
        self::assertSame('2026-10-05', $answer['today']);
        self::assertSame(16 * 60 + 30, $answer['now_minute']);
        self::assertSame('2026-10-05', $this->calibration->calls[0][3]);
        self::assertNull($answer['region']);
        self::assertSame('none', $answer['assumptions']['region']['id']);
        self::assertSame([], $this->db->find('FROM tp_regions WHERE region_id = ?'));
    }

    public function testTheDateAndTheMinuteBelongToOneMoment(): void
    {
        // Midnight in New York falls between two readings of the clock.
        $clock = new class(['2026-10-05 03:59:59', '2026-10-05 04:00:00']) extends Clock {
            private int $reads = 0;

            /** @param list<string> $instants UTC; the last one repeats */
            public function __construct(private array $instants)
            {
            }

            public function nowUtc(): \DateTimeImmutable
            {
                $at = $this->instants[min($this->reads++, count($this->instants) - 1)];
                return new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
            }
        };
        $answer = $this->service($clock)->build(self::ORG, self::truck());
        self::assertSame(['2026-10-05', 0], [$answer['today'], $answer['now_minute']]);
        self::assertSame('2026-10-05', $this->calibration->calls[0][3]);
    }

    public function testAZoneThisServerDoesNotKnowDoesNotTakeThePageDown(): void
    {
        $truck = self::truck(['timezone' => 'Mars/Olympus_Mons']);
        $answer = [];
        $lines = LogCapture::during(function () use ($truck, &$answer): void {
            $answer = $this->service()->build(self::ORG, $truck);
        });
        self::assertSame('America/New_York', $answer['timezone']);
        self::assertSame('2026-10-04', $answer['today']);
        self::assertCount(1, $lines);
        self::assertStringStartsWith('[tp] ', $lines[0]);
    }

    // ------------------------------------------------------------------------------------ what is sent

    public function testEmptyMapsAreSentAsObjects(): void
    {
        // The controller names these two paths when it answers.
        $paths = ['assumptions.overrides', 'calibration.spots'];
        foreach ([null, self::truck()] as $truck) {
            $answer = $this->service()->build(self::ORG, $truck);
            $json = json_encode(JsonSafe::clean($answer, $paths));
            self::assertIsString($json);
            self::assertStringContainsString('"overrides":{}', $json);
            self::assertStringContainsString('"spots":{}', $json);
            self::assertStringContainsString('"licence_counties":[', $json);
        }
    }

    public function testNothingOfTheServersOwnLeavesWithTheAnswer(): void
    {
        $json = (string) json_encode($this->service()->build(self::ORG, self::truck()));
        foreach (['organization_id', self::ORG, 'created_by', 'overrides_seeds_rev', '"seeds":', 'avg_ticket_cents', 'base_lat'] as $internal) {
            self::assertStringNotContainsString($internal, $json);
        }
    }
}
