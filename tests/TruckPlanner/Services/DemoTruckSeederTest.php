<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\DemoTruckSeeder;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\ProfileService;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use PHPUnit\Framework\TestCase;

// TruckWorld and its in-memory tables live with the planning service's test.
require_once __DIR__ . '/PlanningServiceTest.php';

/**
 * The demo seeder over the real services and in-memory tables. The fixture region gets people and places
 * at the demo's five points, so the seeded numbers are real model output and not zeros.
 */
final class DemoTruckSeederTest extends TestCase
{
    private const ORG = TruckWorld::ORG;
    private const AS_OF = '2026-10-05';

    /** The dates the twelve services fall on for an as-of date in the week of Monday 2026-10-05. */
    private const SERVICE_DATES = [
        '2026-08-13', '2026-08-21', '2026-08-25', '2026-08-27', '2026-09-04', '2026-09-09', '2026-09-10', '2026-09-18',
        '2026-09-19', '2026-09-22', '2026-09-24', '2026-10-02',
    ];

    private TruckWorld $world;
    private MemoryTrucks $trucks;

    protected function setUp(): void
    {
        $this->world = new TruckWorld();
        $this->trucks = new MemoryTrucks();

        // What the loaded Washington DC region has at the demo's points, in small.
        $segments = Estimator::seed(Seeds::defaults(), 'vocabulary.segments');
        $index = static fn (string $segment): int => (int) array_search($segment, $segments, true);
        $region = $this->world->region;
        $spots = DemoTruckSeeder::SPOTS;
        $region->addBlock('b510594809021003', FixtureRegion::north($spots['office']['point']['lat'], 60.0), $spots['office']['point']['lng'], $index('w_office'), 1770.0, true);
        $region->addVenue('n13375152159', 'taproom', 'v_nightlife', 38.962971, -77.499294, 40.0, 'A brewery taproom');
        $region->addBlock('b511076110041001', FixtureRegion::north($spots['apartments']['point']['lat'], 40.0), $spots['apartments']['point']['lng'], $index('res'), 785.0, true);
        $region->addPlace('r21417142', 'apartment_community', null, 38.9671988, -77.4211944, 0.0, 'An apartment community');
        $region->addVenue('w69604316', 'hospital', 'v_hospital', 38.9625596, -77.3650282, 120.0, 'A hospital', 'yes');
        $region->addBlock('b510594822032001', FixtureRegion::north($spots['hospital']['point']['lat'], -80.0), $spots['hospital']['point']['lng'], $index('w_health'), 1790.0, true);
    }

    protected function tearDown(): void
    {
        Registry::reset();
        TpConfig::replace(null);
    }

    // ------------------------------------------------------------------------------------ fixtures

    private function seeder(?string $environment = 'development'): DemoTruckSeeder
    {
        return new DemoTruckSeeder(
            $this->trucks,
            new RegionRepository(new RecordingDatabase()),
            new FixtureProfiles($this->trucks),
            $this->world->spots,
            $this->world->spotService,
            $this->world->plans,
            $this->world->logs,
            $this->world->regions,
            $environment
        );
    }

    /**
     * The truck value and the Assumptions the seeder works with for an organization.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function truckAndA(string $orgId): array
    {
        $row = $this->trucks->findByOrg($orgId);
        self::assertIsArray($row);
        $truck = $row + ['profile' => (new ProfileMapper())->toRecord($row)['profile']];
        return [$truck, Seeds::defaults()];
    }

    /**
     * What was written for an organization with everything that names a row taken out, so that two
     * seedings can be compared.
     *
     * @param array<string, mixed> $seeded the answer of seed()
     * @return array<string, mixed>
     */
    private function written(string $orgId, array $seeded): array
    {
        [$truck, $A] = $this->truckAndA($orgId);
        $keys = array_column($seeded['spots'], 'key', 'id');
        $spots = [];
        foreach ($this->world->spotService->list($orgId, $truck) as $spot) {
            $spot['terms']['spot_id'] = null;
            $spots[$keys[$spot['id']]] = array_diff_key($spot, ['id' => true, 'created_at' => true, 'updated_at' => true]);
        }
        ksort($spots);
        $services = [];
        foreach ($this->world->logging->list($orgId, $truck, ['from' => '2026-01-01', 'to' => '2026-12-31']) as $service) {
            $service['spot_id'] = $keys[$service['spot_id']];
            $service['prediction']['detail']['terms']['spot_id'] = null;
            $services[$service['date']] = array_diff_key($service, ['id' => true, 'created_at' => true, 'updated_at' => true]);
        }
        ksort($services);
        $factors = [];
        foreach ($seeded['calibration']['spots'] as $spotId => $factor) {
            $factors[$keys[$spotId]] = $factor;
        }
        ksort($factors);
        $plan = $this->world->planning->get($orgId, $truck, $A, $seeded['plan']['id']);
        return [
            'profile' => $truck['profile'],
            'spots' => $spots,
            'services' => $services,
            'plan' => [
                'date' => $plan['date'], 'name' => $plan['name'], 'status' => $plan['status'],
                'stops' => array_map(static fn (array $stop): array => [$keys[$stop['spot_id']], $stop['open_minute'], $stop['close_minute']], $plan['stops']),
                'timeline' => array_diff_key($plan['result']['timeline'], ['events' => true, 'legs' => true, 'stops' => true]),
                'totals' => $plan['result']['totals'],
                'calibration' => $plan['context']['calibration'],
            ],
            'calibration' => array_diff_key($seeded['calibration'], ['spots' => true]),
            'spot_factors' => $factors,
        ];
    }

    // ------------------------------------------------------------------------------------ what is written

    public function testItWritesATruckFiveSpotsTwelveServicesAndAPlannedThursday(): void
    {
        $seeded = $this->seeder()->seed(self::ORG, TruckWorld::USER, self::AS_OF);
        [$truck, $A] = $this->truckAndA(self::ORG);

        self::assertSame(['as_of', 'truck', 'spots', 'plan', 'services', 'calibration'], array_keys($seeded));
        self::assertSame(self::AS_OF, $seeded['as_of']);

        // The truck: based in Sterling, Virginia, every other field the profile default.
        $profile = $seeded['truck']['profile'];
        self::assertSame('Smoke & Ember (demo)', $profile['name']);
        self::assertSame(['lat' => 39.003, 'lng' => -77.405, 'address' => 'Sterling, VA'], $profile['base']);
        self::assertSame(15.0, $profile['avg_ticket']);
        self::assertSame((new ProfileMapper())->defaults(), array_diff_key($profile, ['name' => true, 'region_id' => true, 'base' => true]));

        // Five spots, named for what stands at their points.
        self::assertSame(['office', 'taproom', 'apartments', 'hospital', 'town_center'], array_column($seeded['spots'], 'key'));
        self::assertSame(
            ['Herndon office area, Spring Street', 'Sterling taproom, Overland Drive', 'Apartment community, Innovation Avenue',
                'Hospital, Town Center Parkway', 'Reston Town Center'],
            array_column($seeded['spots'], 'name')
        );
        $ids = array_column($seeded['spots'], 'id', 'key');
        $spots = array_column($this->world->spotService->list(self::ORG, $truck), null, 'id');
        self::assertCount(5, $spots);
        self::assertSame(['lat' => 38.9625, 'lng' => -77.3781], $spots[$ids['office']]['point']);
        self::assertSame(['lat' => 38.963, 'lng' => -77.4993], $spots[$ids['taproom']]['point']);
        self::assertSame(['lat' => 38.9672, 'lng' => -77.4212], $spots[$ids['apartments']]['point']);
        self::assertSame(['lat' => 38.9626, 'lng' => -77.365], $spots[$ids['hospital']]['point']);
        self::assertSame(['lat' => 38.96, 'lng' => -77.36], $spots[$ids['town_center']]['point'], 'the point the first draft called a Herndon office park');
        self::assertSame('Spring Street, Herndon, VA 20170', $spots[$ids['office']]['address']);
        self::assertSame('Overland Drive, Sterling, VA 20166', $spots[$ids['taproom']]['address']);
        foreach ($spots as $spot) {
            self::assertSame('fresh', $spot['vectors_state']);
            self::assertStringStartsWith('Demo spot.', (string) $spot['notes']);
        }
        // The taproom: 120 people in its busiest hour, the truck its only food, linked to the taproom that stands there.
        self::assertSame(
            ['segment' => 'v_nightlife', 'size' => 120.0, 'size_source' => 'owner', 'only_food' => true, 'point_id' => 'pn13375152159', 'place_type' => 'taproom'],
            $spots[$ids['taproom']]['terms']['host']
        );
        self::assertSame(['pn13375152159'], $spots[$ids['taproom']]['vectors']['normal']['exclusion']['point_ids'], 'its own guests are not counted twice');
        self::assertSame('res', $spots[$ids['apartments']]['terms']['host']['segment']);
        self::assertSame(600.0, $spots[$ids['apartments']]['terms']['host']['size']);
        self::assertNull($spots[$ids['office']]['terms']['host']);
        self::assertNull($spots[$ids['hospital']]['terms']['host']);
        self::assertSame(['prominent', 0.1, 75.0], [$spots[$ids['town_center']]['terms']['visibility'], $spots[$ids['town_center']]['terms']['fee_pct'], $spots[$ids['town_center']]['terms']['fee_min']]);
        self::assertGreaterThan(0.0, $spots[$ids['office']]['vectors']['normal']['nearby'][1], 'the offices of Spring Street are in reach');

        // Twelve services in the eight calendar weeks before the week of the as-of date, no two on one date.
        self::assertCount(12, $seeded['services']);
        self::assertSame(self::SERVICE_DATES, array_column($seeded['services'], 'date'));
        self::assertSame(
            ['office', 'taproom', 'hospital', 'office', 'taproom', 'apartments', 'office', 'taproom', 'town_center', 'hospital', 'office', 'taproom'],
            array_column($seeded['services'], 'spot')
        );
        $names = array_column($seeded['spots'], 'name', 'key');
        $rows = $this->world->db->logs;
        self::assertCount(12, $rows);
        foreach ($seeded['services'] as $i => $service) {
            $row = $rows[$service['id']];
            self::assertSame($service['date'], $row['service_date']);
            self::assertSame($ids[$service['spot']], $row['spot_id']);
            self::assertSame('log', $row['pred_basis']);
            self::assertSame('manual', $row['src']);
            self::assertGreaterThan(0.0, $service['predicted_raw'], $service['spot'] . ' has people in reach');
            // The orders: the model's own number for the window, scaled by a factor fixed by the spot's name and the date.
            $scale = 0.75 + (crc32($names[$service['spot']] . $service['date']) % 500) / 1000.0;
            self::assertSame($scale, DemoTruckSeeder::scale($names[$service['spot']], $service['date']));
            self::assertGreaterThanOrEqual(0.75, $scale);
            self::assertLessThan(1.25, $scale);
            self::assertSame((int) Estimator::roundHalfAway($service['predicted_raw'] * $scale, 0), $service['actual']);
            self::assertSame($service['actual'], $row['actual_orders']);
            self::assertSame((string) json_encode($service['predicted_raw']), $row['predicted_raw']);
            if ($i === 0) {
                self::assertSame($service['predicted_raw'], $service['predicted'], 'nothing was logged before the first service');
            }
        }
        self::assertNotSame($seeded['services'][11]['predicted_raw'], $seeded['services'][11]['predicted'], 'the last estimate uses the eleven before it');
        self::assertSame([660, 840], [$seeded['services'][0]['open_minute'], $seeded['services'][0]['close_minute']]);
        self::assertSame([1020, 1200], [$seeded['services'][1]['open_minute'], $seeded['services'][1]['close_minute']]);

        // The planned day: the Thursday on or after the as-of date, lunch at the offices, then the taproom.
        self::assertSame('2026-10-08', $seeded['plan']['date']);
        self::assertSame('fresh', $seeded['plan']['result_state']);
        $plan = $this->world->planning->get(self::ORG, $truck, $A, $seeded['plan']['id']);
        self::assertSame('planned', $plan['status']);
        self::assertSame('Herndon lunch, then the Sterling taproom', $plan['name']);
        self::assertSame([[$ids['office'], 660, 840], [$ids['taproom'], 1020, 1200]], array_map(static fn (array $stop): array => [$stop['spot_id'], $stop['open_minute'], $stop['close_minute']], $plan['stops']));
        self::assertCount(2, $plan['result']['stops']);
        self::assertSame(self::AS_OF, $plan['context']['calibration']['as_of']);
        self::assertSame(12, $plan['context']['calibration']['truck_n']);
        self::assertSame($seeded['calibration']['truck_factor'], $plan['context']['calibration']['truck_factor']);
        self::assertGreaterThan(0.0, $plan['result']['totals']['orders']['value']);
        self::assertLessThanOrEqual($plan['result']['totals']['take_home']['value'], $plan['result']['totals']['take_home']['low']);
        self::assertContains($plan['result']['totals']['take_home']['confidence'], ['very_rough', 'rough', 'fair', 'good']);

        // The results the services lead to, as of the as-of date.
        self::assertSame(Estimator::calibrate($A, $this->world->calibration->entries(self::ORG, $truck, $A), self::AS_OF), $seeded['calibration']);
        self::assertSame(12, $seeded['calibration']['truck_n']);
        self::assertCount(5, $seeded['calibration']['spots']);
        self::assertNotSame(1.0, $seeded['calibration']['truck_factor']);
    }

    public function testTheSameAsOfDateGivesTheSameDemoIdsApart(): void
    {
        $first = $this->written(self::ORG, $this->seeder()->seed(self::ORG, TruckWorld::USER, self::AS_OF));
        $second = $this->written(TruckWorld::OTHER_ORG, $this->seeder()->seed(TruckWorld::OTHER_ORG, null, self::AS_OF));

        self::assertSame($first, $second, 'no random number, no clock: a plain function of the date and the region data');
        self::assertCount(5, $first['spots']);
        self::assertCount(12, $first['services']);
        // Two organizations, two sets of rows.
        self::assertCount(10, $this->world->db->spots->rows);
        self::assertCount(24, $this->world->db->logs);
        self::assertCount(2, $this->world->db->plans);
    }

    public function testTheAsOfDateStandsForToday(): void
    {
        // Two months after the clock of the services: the whole demo moves with the date it is given.
        $seeded = $this->seeder()->seed(self::ORG, TruckWorld::USER, '2026-12-06');
        self::assertSame('2026-12-06', $seeded['as_of']);
        self::assertSame('2026-12-10', $seeded['plan']['date'], 'the Thursday after a Sunday');
        self::assertSame('2026-10-08', $seeded['services'][0]['date']);
        self::assertSame('2026-11-27', $seeded['services'][11]['date'], 'the Friday before the week of the as-of date');
        self::assertSame('2026-12-06', $seeded['calibration']['as_of']);
        self::assertSame(12, $seeded['calibration']['truck_n'], 'none of the services is in the future of the as-of date');
        self::assertSame('fresh', $seeded['plan']['result_state']);
    }

    public function testTheDatesFollowTheWeekOfTheAsOfDate(): void
    {
        // Monday to Sunday of one week: the same twelve dates; the plan is that week's Thursday, or the next one.
        foreach (['2026-10-05' => '2026-10-08', '2026-10-08' => '2026-10-08', '2026-10-09' => '2026-10-15', '2026-10-11' => '2026-10-15'] as $asOf => $thursday) {
            [$planDate, $dates] = DemoTruckSeeder::dates($asOf);
            self::assertSame($thursday, $planDate, $asOf);
            self::assertSame(self::SERVICE_DATES, $dates, $asOf);
            self::assertSame(3, Estimator::dayOfWeek($planDate));
        }
        [, $dates] = DemoTruckSeeder::dates('2026-10-12');
        self::assertSame('2026-08-20', $dates[0], 'a week later, every service is a week later');
        self::assertCount(12, array_unique($dates));
        foreach ($dates as $date) {
            self::assertLessThan('2026-10-12', $date);
            self::assertGreaterThanOrEqual('2026-08-17', $date, 'inside the eight calendar weeks before the week');
        }
        self::assertSame(DemoTruckSeeder::SERVICES, array_values(DemoTruckSeeder::SERVICES));
        $sorted = $dates;
        sort($sorted);
        self::assertSame($sorted, $dates, 'the services are written oldest first');

        foreach (['2026-10-5', 'today', '1970-01-05', '2199-12-30', ''] as $bad) {
            try {
                DemoTruckSeeder::dates($bad);
                self::fail('accepted as an as-of date: ' . $bad);
            } catch (TpInvalid $e) {
                self::assertSame('as_of', $e->field());
                self::assertSame('V7', $e->rule());
            }
        }
    }

    // ------------------------------------------------------------------------------------ refusals

    public function testItNeverRunsInProduction(): void
    {
        $seeder = $this->seeder('production');
        self::assertFalse($seeder->allowed());
        try {
            $seeder->seed(self::ORG, TruckWorld::USER, self::AS_OF);
            self::fail('the demo truck was written in production');
        } catch (\LogicException $e) {
            self::assertStringContainsString('production', $e->getMessage());
        }
        self::assertSame([], $this->trucks->rows);
        self::assertSame([], $this->world->db->spots->rows);
        self::assertSame([], $this->world->db->statements, 'nothing was read or written');

        self::assertTrue($this->seeder('development')->allowed());
        self::assertTrue($this->seeder('staging')->allowed());

        // Without a name given, the environment is APP_ENV, and no value at all counts as production.
        $before = [$_ENV['APP_ENV'] ?? null, getenv('APP_ENV')];
        try {
            unset($_ENV['APP_ENV']);
            putenv('APP_ENV');
            self::assertFalse($this->seeder(null)->allowed(), 'APP_ENV is not set');
            $_ENV['APP_ENV'] = 'production';
            self::assertFalse($this->seeder(null)->allowed());
            $_ENV['APP_ENV'] = 'development';
            self::assertTrue($this->seeder(null)->allowed());
        } finally {
            if ($before[0] === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $before[0];
            }
            if ($before[1] !== false) {
                putenv('APP_ENV=' . $before[1]);
            }
        }
    }

    public function testAnOrganizationThatHasATruckIsLeftAlone(): void
    {
        $this->seeder()->seed(self::ORG, TruckWorld::USER, self::AS_OF);
        $before = [$this->trucks->rows, $this->world->db->spots->rows, $this->world->db->plans, $this->world->db->logs];
        try {
            $this->seeder()->seed(self::ORG, TruckWorld::USER, self::AS_OF);
            self::fail('a second demo truck was written');
        } catch (TpConflict $e) {
            self::assertSame('This workspace already has a truck', $e->getMessage());
            self::assertSame(DemoTruckSeeder::HAS_TRUCK, $e->getMessage());
        }
        self::assertSame($before, [$this->trucks->rows, $this->world->db->spots->rows, $this->world->db->plans, $this->world->db->logs]);
    }

    public function testABadAsOfDateWritesNothing(): void
    {
        try {
            $this->seeder()->seed(self::ORG, TruckWorld::USER, '2026-13-01');
            self::fail('a date that does not exist was accepted');
        } catch (TpInvalid $e) {
            self::assertSame('as_of', $e->field());
        }
        self::assertSame([], $this->trucks->rows);
    }
}

/**
 * `tp_trucks` in memory, for the one thing the seeder needs of it: is there a truck, and which.
 */
final class MemoryTrucks extends TruckRepository
{
    /** @var array<string, array<string, mixed>> normalised rows by organization */
    public array $rows = [];

    public function __construct()
    {
        parent::__construct(new RecordingDatabase());
    }

    public function findByOrg(string $orgId): ?array
    {
        return $this->rows[$orgId] ?? null;
    }

    public function create(string $orgId, ?string $userId, array $row): string
    {
        $id = sprintf('%08d-0000-4000-8000-000000000000', count($this->rows) + 1);
        $this->rows[$orgId] = [
            'id' => $id,
            'organization_id' => $orgId,
            'created_by' => $userId,
        ] + $row + [
            'base_state' => null,
            'base_county_fips' => null,
            'fuel_price_override' => null,
            'overrides' => [],
            'overrides_seeds_rev' => Seeds::revision(),
            'created_at' => '2026-10-05 11:59:00',
            'updated_at' => '2026-10-05 11:59:00',
        ];
        return $id;
    }
}

/**
 * The profile service as far as the seeder uses it: a first save that creates the truck in the fixture
 * region, with every field it does not carry at its default.
 */
final class FixtureProfiles extends ProfileService
{
    private MemoryTrucks $memory;

    public function __construct(MemoryTrucks $trucks)
    {
        parent::__construct($trucks);
        $this->memory = $trucks;
    }

    public function upsert(string $orgId, ?string $userId, array $body): array
    {
        $mapper = new ProfileMapper();
        $profile = array_replace($mapper->defaults(), $body, ['region_id' => FixtureRegion::REGION]);
        $columns = $mapper->toColumns($profile) + ['timezone' => 'America/New_York', 'base_state' => 'VA', 'base_county_fips' => '51107'];
        $this->memory->create($orgId, $userId, $columns);
        return ['created' => true, 'truck' => $mapper->toRecord((array) $this->memory->findByOrg($orgId)), 'region' => null, 'fuel' => FixtureContexts::FUEL, 'warnings' => []];
    }
}
