<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Core\Database;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\CaptureProvider;
use App\TruckPlanner\Services\Contracts\LegProvider;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\SpotService;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Support\TpNotFound;
use PHPUnit\Framework\TestCase;

/**
 * SpotService on fixture rows: the real SpotRepository over an in-memory `tp_spots` (SpotTable), a small
 * region that answers the capture contract with the model's own functions (FixtureRegion) and a region
 * service that says which dataset version is active (FixtureRegions). No database, no network.
 *
 * The three fixture classes at the end of this file are shared with SimulateServiceTest.
 */
final class SpotServiceTest extends TestCase
{
    public const ORG = '11111111-1111-4111-8111-111111111111';
    public const OTHER_ORG = '99999999-9999-4999-8999-999999999999';
    public const USER = '22222222-2222-4222-8222-222222222222';
    public const TRUCK = '33333333-3333-4333-8333-333333333333';

    private SpotTable $table;
    private RecordingDatabase $logs;
    private FixtureRegion $region;
    private FixtureRegions $regions;
    private RecordingLegs $legs;
    private SpotService $service;

    protected function setUp(): void
    {
        $this->table = new SpotTable();
        $this->logs = new RecordingDatabase();
        $this->region = FixtureRegion::standard();
        $this->regions = new FixtureRegions($this->region);
        $this->legs = new RecordingLegs();
        Registry::reset();
        Registry::set('capture', $this->region);
        Registry::set('legs', $this->legs);
        $this->service = new SpotService(new SpotRepository($this->table), new CountsRepository($this->logs), $this->regions);
    }

    protected function tearDown(): void
    {
        Registry::reset();
        TpConfig::replace(null);
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * The truck value of TruckBaseController::truck() with the profile defaults.
     *
     * @return array<string, mixed>
     */
    public static function truck(string $regionId = FixtureRegion::REGION): array
    {
        return [
            'id' => self::TRUCK,
            'organization_id' => self::ORG,
            'timezone' => 'America/New_York',
            'base_state' => null,
            'base_county_fips' => null,
            'overrides' => [],
            'overrides_seeds_rev' => Seeds::revision(),
            'profile' => ['name' => 'Smoke & Ember', 'region_id' => $regionId, 'base' => ['lat' => 39.003, 'lng' => -77.405, 'address' => 'Sterling, VA']]
                + (new ProfileMapper())->defaults(),
            'created_at' => '2026-10-04 23:50:12',
            'updated_at' => '2026-10-04 23:50:12',
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> Spot
     */
    private function create(array $body = []): array
    {
        return $this->service->create(self::ORG, self::truck(), self::USER, $body + ['name' => 'Herndon office park', 'point' => FixtureRegion::OFFICE]);
    }

    /**
     * What the capture contract answers for one visibility at a point, straight from the model.
     *
     * @param array{lat: float, lng: float} $point
     * @param array<string, mixed>|null $host
     * @return array<string, mixed> LocationVectors
     */
    private function expectedVectors(array $point, string $level, ?array $host = null): array
    {
        $A = Seeds::defaults();
        return Estimator::captureAtPoint(
            $A,
            $point['lat'],
            $point['lng'],
            $level,
            $this->region->sourcePoints(),
            $this->region->modelOutlets(),
            Estimator::hostExclusion($A, $host)
        );
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

    // ------------------------------------------------------------------------------------ create

    public function testCreateComputesAndStoresTheThreeVisibilityLevels(): void
    {
        $spot = $this->create(['terms' => ['visibility' => 'prominent', 'fee_pct' => 0.1, 'fee_min' => 75]]);

        self::assertSame(
            ['id', 'name', 'point', 'address', 'county_fips', 'notes', 'terms', 'host_details', 'vectors', 'vectors_state',
                'logs', 'maps_url', 'archived', 'created_at', 'updated_at'],
            array_keys($spot)
        );
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $spot['id']);
        self::assertSame('Herndon office park', $spot['name']);
        self::assertSame(FixtureRegion::OFFICE, $spot['point']);
        self::assertSame('', $spot['address']);
        self::assertSame('51059', $spot['county_fips'], 'the county comes from the nearest block');
        self::assertNull($spot['notes']);
        self::assertSame(
            ['spot_id' => $spot['id'], 'visibility' => 'prominent', 'host' => null, 'fee_flat' => 0.0, 'fee_pct' => 0.1, 'fee_min' => 75.0, 'allowed' => null],
            $spot['terms']
        );
        self::assertNull($spot['host_details']);
        self::assertSame('fresh', $spot['vectors_state']);
        self::assertSame(['count' => 0, 'last_date' => null], $spot['logs']);
        self::assertSame('https://www.google.com/maps/search/?api=1&query=38.960000%2C-77.360000', $spot['maps_url']);
        self::assertFalse($spot['archived']);

        // One capture for the three levels, and what was stored reads back bit for bit.
        self::assertSame(1, $this->region->count('capture'));
        self::assertSame(['hidden', 'normal', 'prominent'], array_keys($spot['vectors']));
        foreach (SpotRepository::BLOCKS as $level) {
            $expected = $this->expectedVectors(FixtureRegion::OFFICE, $level);
            $stored = $spot['vectors'][$level];
            self::assertSame($expected['capture'], $stored['capture'], $level);
            self::assertSame($expected['nearby'], $stored['nearby'], $level);
            self::assertSame($expected['rivals'], $stored['rivals'], $level);
            self::assertSame(
                ['capture', 'nearby', 'within', 'rivals', 'visibility', 'in_region', 'region_id', 'exclusion', 'excluded_amount',
                    'points_used', 'dataset_version', 'model_version'],
                array_keys($stored)
            );
            self::assertNull($stored['within'], 'not among the 50 stored numbers');
            self::assertSame($level, $stored['visibility']);
            self::assertTrue($stored['in_region']);
            self::assertSame(FixtureRegion::REGION, $stored['region_id']);
            self::assertSame(['point_ids' => [], 'segment' => null, 'amount' => 0.0], $stored['exclusion']);
            self::assertSame(0.0, $stored['excluded_amount']);
            self::assertSame(8, $stored['points_used']);
            self::assertSame(FixtureRegion::VERSION, $stored['dataset_version']);
            self::assertSame('tps-0.1.0', $stored['model_version']);
        }
        // The worked example of 02_MODEL.md 4.4.
        self::assertEqualsWithDelta(273.891217, $spot['vectors']['hidden']['capture']['day'][1], 1e-6);
        self::assertEqualsWithDelta(417.134520, $spot['vectors']['normal']['capture']['day'][1], 1e-6);
        self::assertEqualsWithDelta(509.479077, $spot['vectors']['prominent']['capture']['day'][1], 1e-6);
        self::assertEqualsWithDelta(0.833985, $spot['vectors']['normal']['rivals']['day'], 1e-6);

        // The row itself: one INSERT, with the labels the freshness test reads.
        self::assertCount(1, $this->table->writes('INSERT INTO tp_spots'));
        self::assertCount(0, $this->table->writes('UPDATE tp_spots'));
        $row = $this->table->rows[$spot['id']];
        self::assertSame(self::ORG, $row['organization_id']);
        self::assertSame(self::TRUCK, $row['truck_id']);
        self::assertSame(self::USER, $row['created_by']);
        self::assertSame(1200, strlen($row['vectors_bin']));
        self::assertSame(FixtureRegion::REGION, $row['vec_region_id']);
        self::assertSame(FixtureRegion::VERSION, $row['vec_dataset']);
        self::assertSame(Seeds::revision(), $row['vec_seeds_rev']);
        self::assertSame(7500, $row['fee_min_cents']);
        self::assertNotNull($row['vec_at']);
    }

    public function testTheStoredVectorsServeTheModelAsTheyAre(): void
    {
        $spot = $this->create(['terms' => ['visibility' => 'prominent', 'host' => ['segment' => 'w_office', 'size' => 600, 'only_food' => true]]]);
        $A = Seeds::defaults();
        $profile = self::truck()['profile'];
        foreach (SpotRepository::BLOCKS as $level) {
            $terms = ['visibility' => $level] + $spot['terms'];
            self::assertTrue(Estimator::vectorsMatch($A, $terms, $spot['vectors'][$level]));
            $fromStored = Estimator::weekStrip($A, $profile, $terms, $spot['vectors'][$level], null);
            $fromModel = Estimator::weekStrip($A, $profile, $terms, $this->expectedVectors(FixtureRegion::OFFICE, $level, $spot['terms']['host']), null);
            self::assertSame($fromModel, $fromStored, $level);
        }
        // prominent with a host of 600 office workers, no cafeteria: Thursday 11:00 to 14:00 gives 77.59 orders
        $terms = $spot['terms'];
        $thursday = Estimator::windowOrders($A, $profile, $terms, $spot['vectors']['prominent'], null, Estimator::dayContext($A, '2026-10-08', null, null, null, null), null, 660, 840);
        self::assertEqualsWithDelta(77.59, $thursday['orders']['value'], 0.005);
        self::assertSame(600.0, $spot['vectors']['prominent']['excluded_amount']);
        self::assertSame(['point_ids' => [], 'segment' => 'w_office', 'amount' => 600.0], $spot['vectors']['prominent']['exclusion']);
    }

    public function testTheRegionLabelIsStoredAndNeverZeroesAVector(): void
    {
        // The nearest block is a halo block: the vectors are kept, labelled as outside the region.
        $halo = $this->create(['name' => 'Across the county line', 'point' => FixtureRegion::HALO]);
        self::assertSame('24033', $halo['county_fips']);
        self::assertSame('fresh', $halo['vectors_state']);
        foreach (SpotRepository::BLOCKS as $level) {
            self::assertFalse($halo['vectors'][$level]['in_region']);
            self::assertSame(FixtureRegion::REGION, $halo['vectors'][$level]['region_id']);
            self::assertSame(1, $halo['vectors'][$level]['points_used']);
            self::assertSame($this->expectedVectors(FixtureRegion::HALO, $level)['capture'], $halo['vectors'][$level]['capture']);
        }
        self::assertGreaterThan(50.0, $halo['vectors']['normal']['capture']['day'][1]);
        self::assertSame(0, $this->table->rows[$halo['id']]['vec_in_region']);

        // Nothing within reach: zero vectors, no county, and still a spot that can be saved.
        $empty = $this->create(['name' => 'Nowhere', 'point' => FixtureRegion::EMPTY]);
        self::assertNull($empty['county_fips']);
        self::assertSame('fresh', $empty['vectors_state']);
        self::assertSame(array_fill(0, 16, 0.0), $empty['vectors']['prominent']['capture']['day']);
        self::assertSame(array_fill(0, 16, 0.0), $empty['vectors']['prominent']['nearby']);
        self::assertSame(0, $empty['vectors']['prominent']['points_used']);
        self::assertFalse($empty['vectors']['prominent']['in_region']);
        self::assertSame(FixtureRegion::VERSION, $empty['vectors']['prominent']['dataset_version'], 'the dataset was read, it holds nothing here');
    }

    public function testCreateStoresWhatTheOwnerTyped(): void
    {
        $spot = $this->create([
            'name' => '  Lot 4  ',
            'address' => ' 13800 Example Rd ',
            'notes' => 'Loading dock side',
            'terms' => [
                'visibility' => 'hidden',
                'fee_flat' => 12.345,
                'fee_pct' => 0.05,
                'fee_min' => 30,
                'allowed' => ['days' => [true, true, true, true, true, false, false], 'open_minute' => 660, 'close_minute' => 840],
            ],
            'host_details' => ['name' => 'Example Plaza', 'contact' => 'Pat', 'phone' => '703-555-0100', 'website' => ''],
        ]);
        self::assertSame('Lot 4', $spot['name']);
        self::assertSame('13800 Example Rd', $spot['address']);
        self::assertSame('Loading dock side', $spot['notes']);
        self::assertSame('hidden', $spot['terms']['visibility']);
        self::assertSame(12.35, $spot['terms']['fee_flat'], 'money is stored in whole cents and shown as stored');
        self::assertSame(0.05, $spot['terms']['fee_pct']);
        self::assertSame(30.0, $spot['terms']['fee_min']);
        self::assertSame(
            ['days' => [true, true, true, true, true, false, false], 'open_minute' => 660, 'close_minute' => 840],
            $spot['terms']['allowed']
        );
        self::assertSame(
            ['place_type' => null, 'name' => 'Example Plaza', 'contact' => 'Pat', 'phone' => '703-555-0100', 'website' => null,
                'place_key' => null, 'google_place_id' => null],
            $spot['host_details']
        );
    }

    public function testCreateValidatesInTheOrderOfTheFieldTable(): void
    {
        $truck = self::truck();
        $create = fn (array $body) => fn () => $this->service->create(self::ORG, $truck, self::USER, $body);
        $point = FixtureRegion::OFFICE;

        self::assertInvalid('name is required', 'name', 'V1', $create([]));
        self::assertInvalid('name is required', 'name', 'V1', $create(['name' => '   ', 'point' => $point]));
        self::assertInvalid('name must be text of at most 120 characters', 'name', 'V5', $create(['name' => str_repeat('n', 121), 'point' => $point]));
        self::assertInvalid('point is required', 'point', 'V1', $create(['name' => 'x']));
        self::assertInvalid('point must have lat between -90 and 90 and lng between -180 and 180', 'point', 'V10', $create(['name' => 'x', 'point' => ['lat' => 91, 'lng' => 0]]));
        self::assertInvalid('address must be text of at most 255 characters', 'address', 'V5', $create(['name' => 'x', 'point' => $point, 'address' => 5]));
        self::assertInvalid('notes must be text of at most 4000 characters', 'notes', 'V5', $create(['name' => 'x', 'point' => $point, 'notes' => str_repeat('n', 4001)]));

        $terms = fn (array $t) => $create(['name' => 'x', 'point' => $point, 'terms' => $t]);
        self::assertInvalid('terms must be an object', 'terms', 'V9', $create(['name' => 'x', 'point' => $point, 'terms' => [1, 2]]));
        self::assertInvalid('terms.visibility must be one of: hidden, normal, prominent', 'terms.visibility', 'V4', $terms(['visibility' => 'loud']));
        self::assertInvalid('terms.fee_flat must be a number between 0 and 100000', 'terms.fee_flat', 'V2', $terms(['fee_flat' => -1]));
        self::assertInvalid('terms.fee_min must be a number between 0 and 100000', 'terms.fee_min', 'V2', $terms(['fee_min' => '75']));
        self::assertInvalid('terms.fee_pct must be a number between 0 and 1', 'terms.fee_pct', 'V2', $terms(['fee_pct' => 10]));
        self::assertInvalid('terms.allowed must be an object', 'terms.allowed', 'V9', $terms(['allowed' => 'weekdays']));
        self::assertInvalid('terms.allowed.days must be a list of 7 to 7 items', 'terms.allowed.days', 'V8', $terms(['allowed' => ['days' => [true, true], 'open_minute' => 0, 'close_minute' => 60]]));
        self::assertInvalid('terms.allowed.days[2] must be true or false', 'terms.allowed.days[2]', 'V6', $terms(['allowed' => ['days' => [true, true, 1, true, true, true, true], 'open_minute' => 0, 'close_minute' => 60]]));
        self::assertInvalid('terms.allowed.open_minute must be a whole number between 0 and 2880', 'terms.allowed.open_minute', 'V3', $terms(['allowed' => ['days' => array_fill(0, 7, true), 'open_minute' => 660.5, 'close_minute' => 840]]));
        self::assertInvalid('terms.allowed.close_minute is required', 'terms.allowed.close_minute', 'V1', $terms(['allowed' => ['days' => array_fill(0, 7, true), 'open_minute' => 660]]));
        self::assertInvalid('terms.allowed.close_minute must be after open_minute', 'terms.allowed.close_minute', null, $terms(['allowed' => ['days' => array_fill(0, 7, true), 'open_minute' => 840, 'close_minute' => 840]]));
        self::assertInvalid('host_details.phone must be text of at most 40 characters', 'host_details.phone', 'V5', $create(['name' => 'x', 'point' => $point, 'host_details' => ['phone' => str_repeat('5', 41)]]));

        self::assertSame([], $this->table->rows, 'nothing was stored');
        self::assertSame(0, $this->region->count('capture'));
    }

    public function testAHostIsValidatedAndCompletedFromThePlaceItLinks(): void
    {
        $truck = self::truck();
        $host = fn (mixed $h) => fn () => $this->service->create(self::ORG, $truck, self::USER, ['name' => 'x', 'point' => FixtureRegion::TAPROOM, 'terms' => ['host' => $h]]);

        self::assertInvalid('terms.host must be an object', 'terms.host', 'V9', $host('taproom'));
        self::assertInvalid('terms.host.place_key was not found', 'terms.host.place_key', 'V11', $host(['place_key' => 'w999']));
        self::assertInvalid('terms.host.place_key was not found', 'terms.host.place_key', 'V11', $host(['place_key' => 'wé1']));
        self::assertInvalid('terms.host.place_key must be text of at most 20 characters', 'terms.host.place_key', 'V5', $host(['place_key' => str_repeat('w', 21)]));
        self::assertInvalid('terms.host.segment is required', 'terms.host.segment', 'V1', $host(['size' => 100]));
        self::assertInvalid(
            'terms.host.segment must be one of: res, w_office, w_health, w_edu, w_retail, w_industrial, w_hospitality, w_public, '
            . 'v_nightlife, v_shopping, v_leisure, v_campus, v_hospital, v_transit, v_events, v_lodging',
            'terms.host.segment',
            'V4',
            $host(['segment' => 'v_taproom', 'size' => 100])
        );
        self::assertInvalid('terms.host.size is required for this kind of place', 'terms.host.size', null, $host(['segment' => 'w_office']));
        self::assertInvalid('terms.host.size is required for this kind of place', 'terms.host.size', null, $host(['place_key' => FixtureRegion::OFFICE_PARK_KEY]));
        self::assertInvalid('terms.host.size must be a number between 1 and 200000', 'terms.host.size', 'V2', $host(['segment' => 'w_office', 'size' => 0.5]));
        self::assertInvalid('terms.host.size_source must be one of: owner, default', 'terms.host.size_source', 'V4', $host(['segment' => 'w_office', 'size' => 10, 'size_source' => 'guess']));
        self::assertInvalid('terms.host.only_food must be true or false', 'terms.host.only_food', 'V6', $host(['segment' => 'w_office', 'size' => 10, 'only_food' => 'yes']));
        self::assertSame([], $this->table->rows);

        // A linked taproom: segment, size and "only food" come from the place and its type.
        $linked = $this->create(['point' => FixtureRegion::TAPROOM, 'terms' => ['host' => ['place_key' => FixtureRegion::TAPROOM_KEY]]]);
        self::assertSame(
            ['segment' => 'v_nightlife', 'size' => 40.0, 'size_source' => 'default', 'only_food' => true,
                'point_id' => 'p' . FixtureRegion::TAPROOM_KEY, 'place_type' => 'taproom'],
            $linked['terms']['host']
        );
        self::assertSame(FixtureRegion::TAPROOM_KEY, $linked['host_details']['place_key']);
        self::assertSame('taproom', $linked['host_details']['place_type']);

        // What the owner types wins over the defaults.
        $typed = $this->create(['point' => FixtureRegion::TAPROOM, 'terms' => ['host' => ['place_key' => FixtureRegion::TAPROOM_KEY, 'size' => 120, 'only_food' => false]]]);
        self::assertSame(120.0, $typed['terms']['host']['size']);
        self::assertSame('owner', $typed['terms']['host']['size_source']);
        self::assertFalse($typed['terms']['host']['only_food']);

        // A place with a kitchen of its own: the truck is not the only food by default.
        $bar = $this->create(['point' => FixtureRegion::BAR, 'terms' => ['host' => ['place_key' => FixtureRegion::BAR_KEY]]]);
        self::assertFalse($bar['terms']['host']['only_food']);
        self::assertSame(45.0, $bar['terms']['host']['size']);

        // A described host without a place.
        $described = $this->create(['terms' => ['host' => ['segment' => 'res', 'size' => 500, 'size_source' => 'default']]]);
        self::assertSame(
            ['segment' => 'res', 'size' => 500.0, 'size_source' => 'default', 'only_food' => false, 'point_id' => null, 'place_type' => null],
            $described['terms']['host']
        );
        self::assertNull($described['host_details']);
    }

    public function testALinkToAPlaceThatHostsNothingIsSavedWithoutAHost(): void
    {
        $spot = $this->create(['point' => FixtureRegion::MARKET, 'terms' => ['host' => ['place_key' => FixtureRegion::MARKET_KEY, 'size' => 300, 'only_food' => true]]]);
        self::assertNull($spot['terms']['host']);
        self::assertSame(FixtureRegion::MARKET_KEY, $spot['host_details']['place_key']);
        self::assertSame('farmers_market', $spot['host_details']['place_type']);
        $row = $this->table->rows[$spot['id']];
        self::assertNull($row['host_segment']);
        self::assertNull($row['host_size']);
        self::assertNull($row['host_point_id']);
        self::assertSame(['point_ids' => [], 'segment' => null, 'amount' => 0.0], $spot['vectors']['normal']['exclusion']);

        // With a segment the owner names, the same place does carry a host.
        $named = $this->create(['point' => FixtureRegion::MARKET, 'terms' => ['host' => ['place_key' => FixtureRegion::MARKET_KEY, 'segment' => 'v_shopping', 'size' => 300]]]);
        self::assertSame('v_shopping', $named['terms']['host']['segment']);
        self::assertSame('farmers_market', $named['terms']['host']['place_type']);
    }

    public function testTheLimitOfSpotsAnswersAConflict(): void
    {
        $config = TpConfig::all();
        $config['limits']['max_spots'] = 2;
        TpConfig::replace($config);
        $this->create();
        $second = $this->create(['name' => 'Second']);
        try {
            $this->create(['name' => 'Third']);
            self::fail('a third spot was saved');
        } catch (TpConflict $e) {
            self::assertSame('You can keep at most 2 spots', $e->getMessage());
        }
        self::assertCount(2, $this->table->rows);

        // An archived spot does not count.
        $this->service->archive(self::ORG, $second['id']);
        self::assertSame('Third', $this->create(['name' => 'Third'])['name']);
    }

    public function testAGooglePlaceIdIsKeptOnlyWhenTheCallerPassesOne(): void
    {
        $fromBody = $this->create(['host_details' => ['name' => 'Example Brewing', 'google_place_id' => 'ChIJfromTheBody']]);
        self::assertNull($fromBody['host_details']['google_place_id'], 'never read from a request body');

        $saved = $this->service->create(self::ORG, self::truck(), self::USER, ['name' => 'Example Brewing', 'point' => FixtureRegion::TAPROOM], 'ChIJexample');
        self::assertSame('ChIJexample', $saved['host_details']['google_place_id']);
    }

    // ------------------------------------------------------------------------------------ update

    public function testVectorsAreRecomputedOnlyWhenPointOrHostChanges(): void
    {
        $spot = $this->create(['terms' => ['host' => ['segment' => 'w_office', 'size' => 600, 'only_food' => true]]]);
        $id = $spot['id'];
        $truck = self::truck();
        $bytes = $this->table->rows[$id]['vectors_bin'];
        $stamp = $this->table->rows[$id]['vec_at'];
        self::assertSame(1, $this->region->count('capture'));

        // Nothing here touches what the vectors were computed from.
        $unchanged = [
            ['terms' => ['visibility' => 'prominent']],
            ['terms' => ['visibility' => 'hidden', 'fee_flat' => 50, 'fee_pct' => 0.1, 'fee_min' => 75]],
            ['name' => 'Renamed', 'address' => 'Somewhere', 'notes' => 'By the dock'],
            ['host_details' => ['name' => 'Example Plaza']],
            ['terms' => ['allowed' => ['days' => array_fill(0, 7, true), 'open_minute' => 600, 'close_minute' => 900]]],
            ['point' => FixtureRegion::OFFICE],
            ['terms' => ['host' => ['segment' => 'w_office', 'size' => 600, 'only_food' => true]]],
            ['terms' => ['host' => ['segment' => 'w_office', 'size' => 600, 'only_food' => false, 'size_source' => 'default']]],
        ];
        foreach ($unchanged as $i => $body) {
            $after = $this->service->update(self::ORG, $truck, $id, $body);
            self::assertSame(1, $this->region->count('capture'), 'no capture for change ' . $i);
            self::assertSame($bytes, $this->table->rows[$id]['vectors_bin']);
            self::assertSame($stamp, $this->table->rows[$id]['vec_at']);
            self::assertSame('fresh', $after['vectors_state']);
            self::assertSame($spot['vectors']['normal']['capture'], $after['vectors']['normal']['capture']);
        }
        $after = $this->service->get(self::ORG, $truck, $id);
        self::assertSame('hidden', $after['terms']['visibility']);
        self::assertSame('Renamed', $after['name']);
        self::assertFalse($after['terms']['host']['only_food']);
        self::assertSame('default', $after['terms']['host']['size_source']);
        self::assertSame(50.0, $after['terms']['fee_flat']);

        // Each of these does.
        $changes = [
            'host size' => ['terms' => ['host' => ['segment' => 'w_office', 'size' => 800, 'only_food' => true]]],
            'host segment' => ['terms' => ['host' => ['segment' => 'w_retail', 'size' => 800, 'only_food' => true]]],
            'host removed' => ['terms' => ['host' => null]],
            'host added' => ['terms' => ['host' => ['segment' => 'w_office', 'size' => 250]]],
            'point' => ['point' => ['lat' => FixtureRegion::north(38.96, 30.0), 'lng' => -77.36]],
        ];
        $captures = 1;
        foreach ($changes as $what => $body) {
            $stamp = $this->table->rows[$id]['vec_at'];
            $after = $this->service->update(self::ORG, $truck, $id, $body);
            self::assertSame(++$captures, $this->region->count('capture'), $what);
            self::assertNotSame($stamp, $this->table->rows[$id]['vec_at'], $what . ': the vectors were written');
            self::assertSame('fresh', $after['vectors_state']);
            foreach (SpotRepository::BLOCKS as $level) {
                $expected = $this->expectedVectors($after['point'], $level, $after['terms']['host']);
                self::assertSame($expected['capture'], $after['vectors'][$level]['capture'], $what . ' ' . $level);
                self::assertSame($expected['exclusion'], $after['vectors'][$level]['exclusion'], $what . ' ' . $level);
                self::assertSame($expected['excluded_amount'], $after['vectors'][$level]['excluded_amount'], $what . ' ' . $level);
            }
        }
        // Every update was one statement.
        self::assertCount(count($unchanged) + count($changes), $this->table->writes('UPDATE tp_spots'));
    }

    public function testUpdateChangesOnlyTheKeysItCarries(): void
    {
        $spot = $this->create([
            'notes' => 'By the dock',
            'terms' => ['visibility' => 'prominent', 'fee_flat' => 25, 'fee_pct' => 0.1, 'fee_min' => 75,
                'allowed' => ['days' => array_fill(0, 7, true), 'open_minute' => 600, 'close_minute' => 900]],
            'host_details' => ['name' => 'Example Plaza', 'contact' => 'Pat', 'phone' => '703-555-0100'],
        ]);
        $truck = self::truck();

        $after = $this->service->update(self::ORG, $truck, $spot['id'], ['terms' => ['fee_pct' => 0.2], 'host_details' => ['contact' => null]]);
        self::assertSame(0.2, $after['terms']['fee_pct']);
        self::assertSame(25.0, $after['terms']['fee_flat']);
        self::assertSame(75.0, $after['terms']['fee_min']);
        self::assertSame('prominent', $after['terms']['visibility']);
        self::assertSame($spot['terms']['allowed'], $after['terms']['allowed']);
        self::assertSame('Example Plaza', $after['host_details']['name']);
        self::assertNull($after['host_details']['contact']);
        self::assertSame('703-555-0100', $after['host_details']['phone']);
        self::assertSame('By the dock', $after['notes']);

        // null removes what may be removed
        $after = $this->service->update(self::ORG, $truck, $spot['id'], ['notes' => null, 'terms' => ['allowed' => null]]);
        self::assertNull($after['notes']);
        self::assertNull($after['terms']['allowed']);
        self::assertSame('Herndon office park', $after['name']);
        self::assertSame('prominent', $after['terms']['visibility']);

        // and is refused where nothing can be removed
        $refuse = fn (array $body) => fn () => $this->service->update(self::ORG, $truck, $spot['id'], $body);
        self::assertInvalid('name is required', 'name', 'V1', $refuse(['name' => null]));
        self::assertInvalid('point is required', 'point', 'V1', $refuse(['point' => null]));
        self::assertInvalid('address is required', 'address', 'V1', $refuse(['address' => null]));
        self::assertInvalid('terms is required', 'terms', 'V1', $refuse(['terms' => null]));
        self::assertInvalid('terms.visibility is required', 'terms.visibility', 'V1', $refuse(['terms' => ['visibility' => null]]));
        self::assertInvalid('terms.fee_pct is required', 'terms.fee_pct', 'V1', $refuse(['terms' => ['fee_pct' => null]]));
        self::assertInvalid('terms.host.size is required', 'terms.host.size', 'V1', $refuse(['terms' => ['host' => ['segment' => 'w_office', 'size' => null]]]));
        self::assertInvalid('terms.host.only_food is required', 'terms.host.only_food', 'V1', $refuse(['terms' => ['host' => ['segment' => 'w_office', 'size' => 5, 'only_food' => null]]]));
        self::assertInvalid('host_details is required', 'host_details', 'V1', $refuse(['host_details' => null]));
        self::assertSame($after, $this->service->get(self::ORG, $truck, $spot['id']));

        $after = $this->service->update(self::ORG, $truck, $spot['id'], ['host_details' => ['name' => null, 'phone' => '']]);
        self::assertNull($after['host_details']);
    }

    public function testAnUpdateWithNoKnownKeyIsRefusedAndAnEmptyNestedObjectChangesNothing(): void
    {
        $spot = $this->create();
        $truck = self::truck();
        self::assertInvalid('Nothing to update', null, 'V12', fn () => $this->service->update(self::ORG, $truck, $spot['id'], []));
        self::assertInvalid('Nothing to update', null, 'V12', fn () => $this->service->update(self::ORG, $truck, $spot['id'], ['colour' => 'red']));
        self::assertInvalid('name is required', 'name', 'V1', fn () => $this->service->update(self::ORG, $truck, $spot['id'], ['name' => ' ']));
        self::assertInvalid('terms.fee_pct must be a number between 0 and 1', 'terms.fee_pct', 'V2', fn () => $this->service->update(self::ORG, $truck, $spot['id'], ['terms' => ['fee_pct' => 2]]));

        $writes = count($this->table->writes('UPDATE tp_spots'));
        $after = $this->service->update(self::ORG, $truck, $spot['id'], ['terms' => []]);
        self::assertSame($spot, $after);
        self::assertCount($writes, $this->table->writes('UPDATE tp_spots'));
    }

    public function testCorrectionsFollowAShortMoveAndStayBehindOnALongOne(): void
    {
        $spot = $this->create();
        $truck = self::truck();
        $near = ['lat' => FixtureRegion::north(38.96, 200.0), 'lng' => -77.36];
        $far = ['lat' => FixtureRegion::north(38.96, 600.0), 'lng' => -77.36];

        $this->service->update(self::ORG, $truck, $spot['id'], ['name' => 'Same place']);
        $this->service->update(self::ORG, $truck, $spot['id'], ['point' => FixtureRegion::OFFICE]);
        self::assertSame([], $this->legs->moves, 'the pin did not move');

        $this->service->update(self::ORG, $truck, $spot['id'], ['point' => $near]);
        self::assertSame([[self::ORG, self::TRUCK, FixtureRegion::OFFICE, $near]], $this->legs->moves);

        $this->service->update(self::ORG, $truck, $spot['id'], ['point' => $far]);
        self::assertCount(1, $this->legs->moves, '400 m is more than the 250 m a correction follows');

        // A routing failure leaves the corrections behind; the spot is saved all the same.
        $this->legs->fail = true;
        $close = ['lat' => FixtureRegion::north(38.96, 500.0), 'lng' => -77.36];
        $lines = LogCapture::during(function () use ($truck, $spot, $close): void {
            $after = $this->service->update(self::ORG, $truck, $spot['id'], ['point' => $close, 'name' => 'Nearly back']);
            self::assertSame($close, $after['point']);
            self::assertSame('Nearly back', $after['name']);
        });
        self::assertCount(1, $lines);
        self::assertStringStartsWith('[tp] corrections did not follow a moved spot', $lines[0]);
    }

    // ------------------------------------------------------------------------------------ stale and fresh

    public function testADatasetSwitchMakesSpotsStaleAndARefreshMakesThemFresh(): void
    {
        $truck = self::truck();
        $first = $this->create();
        $second = $this->create(['name' => 'Second', 'point' => FixtureRegion::TAPROOM]);
        $archived = $this->create(['name' => 'Gone']);
        $this->service->archive(self::ORG, $archived['id']);
        self::assertSame(['refreshed' => 0, 'remaining' => 0], $this->service->refreshStale(self::ORG, $truck));

        // A new dataset version with more jobs in the blocks.
        $this->region->switchTo('mini-20270105-bbbb2222', 2.0);
        $list = $this->service->list(self::ORG, $truck);
        self::assertSame(['stale', 'stale'], array_column($list, 'vectors_state'));
        self::assertSame($first['vectors']['normal']['capture'], $list[0]['vectors']['normal']['capture'], 'stale vectors are still shown');
        self::assertSame(FixtureRegion::VERSION, $list[0]['vectors']['normal']['dataset_version']);

        $captures = $this->region->count('capture');
        self::assertSame(['refreshed' => 1, 'remaining' => 1], $this->service->refreshStale(self::ORG, $truck, 1));
        self::assertSame(['refreshed' => 1, 'remaining' => 0], $this->service->refreshStale(self::ORG, $truck));
        self::assertSame(['refreshed' => 0, 'remaining' => 0], $this->service->refreshStale(self::ORG, $truck));
        self::assertSame($captures + 2, $this->region->count('capture'), 'the archived spot is left to ensureFresh');

        $list = $this->service->list(self::ORG, $truck);
        self::assertSame(['fresh', 'fresh'], array_column($list, 'vectors_state'));
        self::assertSame('mini-20270105-bbbb2222', $list[0]['vectors']['normal']['dataset_version']);
        self::assertSame($this->expectedVectors(FixtureRegion::OFFICE, 'normal')['capture'], $list[0]['vectors']['normal']['capture']);
        self::assertEqualsWithDelta(2 * $first['vectors']['normal']['nearby'][1], $list[0]['vectors']['normal']['nearby'][1], 1e-9);
        self::assertSame('stale', $this->service->get(self::ORG, $truck, $archived['id'])['vectors_state']);
        self::assertSame($second['id'], $list[1]['id']);
    }

    public function testASpotIsStaleForAnotherSeedsRevisionOrRegionAndNoneWithoutVectors(): void
    {
        $truck = self::truck();
        $spot = $this->create();

        $this->table->rows[$spot['id']]['vec_seeds_rev'] = Seeds::revision() + 1;
        self::assertSame('stale', $this->service->get(self::ORG, $truck, $spot['id'])['vectors_state']);
        $this->table->rows[$spot['id']]['vec_seeds_rev'] = Seeds::revision();
        self::assertSame('fresh', $this->service->get(self::ORG, $truck, $spot['id'])['vectors_state']);

        // The truck moved to a region without data: vectors of the old region are stale there.
        $elsewhere = self::truck('none');
        self::assertSame('stale', $this->service->get(self::ORG, $elsewhere, $spot['id'])['vectors_state']);
        $refreshed = $this->service->refresh(self::ORG, $elsewhere, $spot['id']);
        self::assertSame('fresh', $refreshed['vectors_state']);
        self::assertSame(array_fill(0, 16, 0.0), $refreshed['vectors']['normal']['capture']['day']);
        self::assertNull($refreshed['vectors']['normal']['region_id']);
        self::assertNull($refreshed['vectors']['normal']['dataset_version']);
        self::assertFalse($refreshed['vectors']['normal']['in_region']);
        self::assertSame('none', $this->table->rows[$spot['id']]['vec_region_id']);
        self::assertSame('stale', $this->service->get(self::ORG, $truck, $spot['id'])['vectors_state']);

        // A row without vectors.
        $bare = (new SpotRepository($this->table))->create(self::ORG, self::TRUCK, self::USER, ['name' => 'Bare', 'lat' => 38.96, 'lng' => -77.36]);
        $read = $this->service->get(self::ORG, $truck, $bare);
        self::assertSame('none', $read['vectors_state']);
        self::assertNull($read['vectors']);
        self::assertSame(['refreshed' => 2, 'remaining' => 0], $this->service->refreshStale(self::ORG, $truck));
        self::assertSame('fresh', $this->service->get(self::ORG, $truck, $bare)['vectors_state']);
    }

    public function testRefreshRecomputesOneSpotWhateverItsState(): void
    {
        $truck = self::truck();
        $spot = $this->create();
        $stamp = $this->table->rows[$spot['id']]['vec_at'];
        $after = $this->service->refresh(self::ORG, $truck, $spot['id']);
        self::assertSame(2, $this->region->count('capture'));
        self::assertSame($spot['vectors'], $after['vectors']);
        self::assertNotSame($stamp, $this->table->rows[$spot['id']]['vec_at']);
    }

    public function testEnsureFreshHandsTheOtherServicesASpotValue(): void
    {
        $truck = self::truck();
        $spot = $this->create(['terms' => ['visibility' => 'prominent', 'host' => ['segment' => 'w_office', 'size' => 600]]]);
        $repository = new SpotRepository($this->table);
        $row = $repository->find($spot['id'], self::ORG, true);
        self::assertIsArray($row);

        $value = $this->service->ensureFresh(self::ORG, $truck, $row);
        self::assertSame(1, $this->region->count('capture'), 'fresh vectors are not computed again');
        self::assertSame(FixtureRegion::OFFICE, $value['point']);
        self::assertSame($spot['terms'], $value['terms']);
        self::assertSame($spot['terms'], $this->service->terms($row));
        self::assertSame($spot['terms'], $this->service->terms($value));
        self::assertSame('fresh', $value['vectors_state']);
        self::assertSame($spot['vectors'], $value['vectors']);
        self::assertSame($row['updated_at'], $value['updated_at'], 'the columns of the row are still there');
        self::assertSame($row['vectors_sha1'], $value['vectors_sha1']);
        self::assertTrue(Estimator::vectorsMatch(Seeds::defaults(), $value['terms'], $value['vectors'][$value['terms']['visibility']]));

        // After a switch the same call recomputes, stores and answers the new vectors. A value is accepted too.
        $this->region->switchTo('mini-20270105-bbbb2222', 2.0);
        $value = $this->service->ensureFresh(self::ORG, $truck, $value);
        self::assertSame(2, $this->region->count('capture'));
        self::assertSame('fresh', $value['vectors_state']);
        self::assertSame('mini-20270105-bbbb2222', $value['vectors']['prominent']['dataset_version']);
        self::assertSame($this->expectedVectors(FixtureRegion::OFFICE, 'prominent', $value['terms']['host'])['capture'], $value['vectors']['prominent']['capture']);
        self::assertSame('mini-20270105-bbbb2222', $this->table->rows[$spot['id']]['vec_dataset']);

        // An archived spot is served too: plans keep referring to it.
        $this->service->archive(self::ORG, $spot['id']);
        $this->region->switchTo('mini-20270406-cccc3333', 1.0);
        $archived = $repository->findMany([$spot['id']], self::ORG)[$spot['id']];
        self::assertSame('fresh', $this->service->ensureFresh(self::ORG, $truck, $archived)['vectors_state']);
    }

    // ------------------------------------------------------------------------------------ archive, tenants

    public function testArchiveKeepsTheRow(): void
    {
        $truck = self::truck();
        $keep = $this->create(['name' => 'B keep']);
        $gone = $this->create(['name' => 'A gone']);

        self::assertSame(['id' => $gone['id'], 'archived' => true], $this->service->archive(self::ORG, $gone['id']));
        self::assertArrayHasKey($gone['id'], $this->table->rows);
        self::assertNotNull($this->table->rows[$gone['id']]['archived_at']);
        self::assertCount(0, $this->table->writes('DELETE'));

        self::assertSame([$keep['id']], array_column($this->service->list(self::ORG, $truck), 'id'));
        $all = $this->service->list(self::ORG, $truck, true);
        self::assertSame(['A gone', 'B keep'], array_column($all, 'name'), 'ordered by name');
        self::assertSame([true, false], array_column($all, 'archived'));

        // Still addressed by its id, and archiving twice changes nothing.
        $read = $this->service->get(self::ORG, $truck, $gone['id']);
        self::assertTrue($read['archived']);
        self::assertSame($gone['vectors'], $read['vectors']);
        $stamp = $this->table->rows[$gone['id']]['archived_at'];
        self::assertSame(['id' => $gone['id'], 'archived' => true], $this->service->archive(self::ORG, $gone['id']));
        self::assertSame($stamp, $this->table->rows[$gone['id']]['archived_at']);
    }

    public function testTheListCarriesTheLogCountsOfOneGroupedQuery(): void
    {
        $truck = self::truck();
        $a = $this->create(['name' => 'a']);
        $b = $this->create(['name' => 'b']);
        $this->logs->when('FROM tp_service_logs', [['spot_id' => $b['id'], 'log_count' => 3, 'last_date' => '2026-10-01']]);

        $list = $this->service->list(self::ORG, $truck);
        self::assertSame(['count' => 0, 'last_date' => null], $list[0]['logs']);
        self::assertSame(['count' => 3, 'last_date' => '2026-10-01'], $list[1]['logs']);
        self::assertSame($a['id'], $list[0]['id']);
        $calls = $this->logs->find('FROM tp_service_logs');
        self::assertSame([self::ORG, self::TRUCK], $calls[count($calls) - 1]['params']);

        // No spot, no log query.
        $empty = new RecordingDatabase();
        $service = new SpotService(new SpotRepository(new SpotTable()), new CountsRepository($empty), $this->regions);
        self::assertSame([], $service->list(self::ORG, $truck));
        self::assertSame([], $empty->calls);
    }

    public function testAnotherTenantsSpotIsNotFound(): void
    {
        $truck = self::truck();
        $spot = $this->create();
        $otherTruck = ['id' => '77777777-7777-4777-8777-777777777777', 'organization_id' => self::OTHER_ORG] + $truck;
        $before = $this->table->rows;
        $writes = count($this->table->statements);
        $captures = $this->region->count('capture');

        $attempts = [
            'get' => fn () => $this->service->get(self::OTHER_ORG, $otherTruck, $spot['id']),
            'update' => fn () => $this->service->update(self::OTHER_ORG, $otherTruck, $spot['id'], ['name' => 'Mine now']),
            'archive' => fn () => $this->service->archive(self::OTHER_ORG, $spot['id']),
            'refresh' => fn () => $this->service->refresh(self::OTHER_ORG, $otherTruck, $spot['id']),
            'missing' => fn () => $this->service->get(self::ORG, $truck, '00000000-0000-4000-8000-000000000000'),
        ];
        foreach ($attempts as $what => $attempt) {
            try {
                $attempt();
                self::fail($what . ' reached another tenant');
            } catch (TpNotFound $e) {
                self::assertSame('Spot not found', $e->getMessage(), $what);
            }
        }
        self::assertSame($before, $this->table->rows);
        self::assertSame($captures, $this->region->count('capture'));
        self::assertSame([], $this->service->list(self::OTHER_ORG, $otherTruck));
        self::assertSame(['refreshed' => 0, 'remaining' => 0], $this->service->refreshStale(self::OTHER_ORG, $otherTruck));
        foreach (array_slice($this->table->statements, $writes) as $sql) {
            self::assertStringNotContainsString('UPDATE', $sql);
            self::assertStringContainsString('organization_id = ?', $sql);
        }
    }

    // ------------------------------------------------------------------------------------ the host link rule

    public function testADescribedTaproomHostAtAnExistingTaproomTakesThatPlacesPoint(): void
    {
        // The owner describes the host (120 people, no kitchen) and names no place.
        $spot = $this->create(['point' => FixtureRegion::TAPROOM, 'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120, 'only_food' => true]]]);
        $point = 'p' . FixtureRegion::TAPROOM_KEY;

        self::assertSame($point, $spot['terms']['host']['point_id']);
        self::assertSame('taproom', $spot['terms']['host']['place_type']);
        self::assertSame(FixtureRegion::TAPROOM_KEY, $spot['host_details']['place_key']);
        foreach (SpotRepository::BLOCKS as $level) {
            self::assertSame([$point], $spot['vectors'][$level]['exclusion']['point_ids']);
            self::assertSame(0.0, $spot['vectors'][$level]['capture']['eve'][8], 'the venue is not counted twice');
            self::assertSame(0, $spot['vectors'][$level]['points_used']);
        }
        // Thursday 17:00 to 20:00 is the anchor of 02_MODEL.md: 39.38 orders, all through the host term.
        $A = Seeds::defaults();
        $window = Estimator::windowOrders($A, self::truck()['profile'], $spot['terms'], $spot['vectors']['normal'], null, Estimator::dayContext($A, '2026-10-08', null, null, null, null), null, 1020, 1200);
        self::assertEqualsWithDelta(39.384, $window['orders']['value'], 1e-9);

        // Without the rule the venue's own 40 would stay in the catchment.
        $unlinked = $this->expectedVectors(FixtureRegion::TAPROOM, 'normal');
        self::assertGreaterThan(10.0, $unlinked['capture']['eve'][8]);
    }

    public function testALinkedPlaceGivesItsPointOnlyWhenItIsAVisitorSource(): void
    {
        $taproom = $this->create(['point' => FixtureRegion::TAPROOM, 'terms' => ['host' => ['place_key' => FixtureRegion::TAPROOM_KEY]]]);
        self::assertSame(['p' . FixtureRegion::TAPROOM_KEY], $taproom['vectors']['normal']['exclusion']['point_ids']);

        // An office park has no people of its own in the data: nothing to take out but the declared workers.
        $park = $this->create(['terms' => ['host' => ['place_key' => FixtureRegion::OFFICE_PARK_KEY, 'size' => 600]]]);
        self::assertSame('w_office', $park['terms']['host']['segment']);
        self::assertNull($park['terms']['host']['point_id']);
        self::assertSame('office_park', $park['terms']['host']['place_type']);
        self::assertSame(['point_ids' => [], 'segment' => 'w_office', 'amount' => 600.0], $park['vectors']['normal']['exclusion']);
        self::assertSame(600.0, $park['vectors']['normal']['excluded_amount']);
    }

    public function testADanglingLinkIsRelinkedToTheNearestPlaceOfItsTypeWithinAHundredMetres(): void
    {
        $truck = self::truck();
        $spot = $this->create(['point' => FixtureRegion::TAPROOM, 'terms' => ['host' => ['place_key' => FixtureRegion::TAPROOM_KEY, 'size' => 120]]]);

        // The next dataset knows the taproom under other keys: two candidates of the type, one bar nearer by.
        $this->region->switchTo('mini-20270105-bbbb2222', 1.0);
        $this->region->removePlace(FixtureRegion::TAPROOM_KEY);
        $this->region->addVenue('w800', 'taproom', 'v_nightlife', FixtureRegion::north(39.01, 60.0), -77.41, 40.0);
        $this->region->addVenue('w700', 'taproom', 'v_nightlife', FixtureRegion::north(39.01, 40.0), -77.41, 40.0);
        $this->region->addVenue('n650', 'bar', 'v_nightlife', FixtureRegion::north(39.01, 10.0), -77.41, 45.0);
        $this->region->addVenue('w900', 'taproom', 'v_nightlife', FixtureRegion::north(39.01, 140.0), -77.41, 40.0);

        self::assertSame(['refreshed' => 1, 'remaining' => 0], $this->service->refreshStale(self::ORG, $truck));
        $after = $this->service->get(self::ORG, $truck, $spot['id']);
        self::assertSame('w700', $after['host_details']['place_key'], 'the nearest of the stored type, not the nearer bar');
        self::assertSame('pw700', $after['terms']['host']['point_id']);
        self::assertSame('taproom', $after['terms']['host']['place_type']);
        self::assertSame(['pw700'], $after['vectors']['normal']['exclusion']['point_ids']);
        self::assertSame(120.0, $after['terms']['host']['size'], 'what the owner typed stays');
        self::assertSame($this->expectedVectors(FixtureRegion::TAPROOM, 'normal', $after['terms']['host'])['capture'], $after['vectors']['normal']['capture']);
    }

    public function testEqualDistancesGoToTheSmallerPlaceKey(): void
    {
        $truck = self::truck();
        $spot = $this->create(['point' => FixtureRegion::TAPROOM, 'terms' => ['host' => ['place_key' => FixtureRegion::TAPROOM_KEY]]]);
        $this->region->switchTo('mini-20270105-bbbb2222', 1.0);
        $this->region->removePlace(FixtureRegion::TAPROOM_KEY);
        $this->region->addVenue('w702', 'taproom', 'v_nightlife', FixtureRegion::north(39.01, 40.0), -77.41, 40.0);
        $this->region->addVenue('w701', 'taproom', 'v_nightlife', FixtureRegion::north(39.01, 40.0), -77.41, 40.0);
        $after = $this->service->refresh(self::ORG, $truck, $spot['id']);
        self::assertSame('w701', $after['host_details']['place_key']);
    }

    public function testADanglingLinkWithNothingNearIsClearedAndTheTypeStaysAsALabel(): void
    {
        $truck = self::truck();
        $spot = $this->create(['point' => FixtureRegion::TAPROOM, 'terms' => ['host' => ['place_key' => FixtureRegion::TAPROOM_KEY, 'size' => 120]]]);
        self::assertSame('p' . FixtureRegion::TAPROOM_KEY, $this->table->rows[$spot['id']]['host_point_id']);

        $this->region->switchTo('mini-20270105-bbbb2222', 1.0);
        $this->region->removePlace(FixtureRegion::TAPROOM_KEY);
        $this->region->addVenue('w900', 'taproom', 'v_nightlife', FixtureRegion::north(39.01, 140.0), -77.41, 40.0);

        $after = $this->service->refresh(self::ORG, $truck, $spot['id']);
        self::assertNull($this->table->rows[$spot['id']]['place_key']);
        self::assertNull($this->table->rows[$spot['id']]['host_point_id']);
        self::assertSame('taproom', $this->table->rows[$spot['id']]['host_place_type']);
        self::assertSame('taproom', $after['terms']['host']['place_type']);
        self::assertNull($after['terms']['host']['point_id']);
        self::assertSame('taproom', $after['host_details']['place_type']);
        self::assertNull($after['host_details']['place_key']);
        self::assertSame([], $after['vectors']['normal']['exclusion']['point_ids']);
        self::assertSame('v_nightlife', $after['terms']['host']['segment'], 'the host itself stays');
        self::assertSame('fresh', $after['vectors_state']);
    }

    public function testAHostBesideAnUnnamedVenueTakesTheNearestSourcePointOfItsSegment(): void
    {
        // A venue that is a visitor source and no possible host stands 50 m north: no place to link.
        $spot = $this->create(['point' => FixtureRegion::LONE, 'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120, 'only_food' => true]]]);
        $A = Seeds::defaults();
        $expected = Estimator::hostLinkPoint($A, FixtureRegion::LONE['lat'], FixtureRegion::LONE['lng'], ['segment' => 'v_nightlife'], $this->region->sourcePoints());
        self::assertSame('p' . FixtureRegion::LONE_VENUE_KEY, $expected);

        self::assertSame($expected, $spot['terms']['host']['point_id']);
        self::assertNull($spot['terms']['host']['place_type']);
        self::assertNull($spot['host_details'], 'the spot stays unlinked');
        self::assertNull($this->table->rows[$spot['id']]['place_key']);
        self::assertSame([$expected], $spot['vectors']['normal']['exclusion']['point_ids']);
        self::assertSame(0.0, $spot['vectors']['normal']['capture']['eve'][8]);

        // 90 m away is beyond the venue link radius: the point stays in the catchment.
        $far = ['lat' => FixtureRegion::north(FixtureRegion::LONE['lat'], -40.0), 'lng' => FixtureRegion::LONE['lng']];
        $apart = $this->create(['point' => $far, 'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120, 'only_food' => true]]]);
        self::assertNull($apart['terms']['host']['point_id']);
        self::assertSame([], $apart['vectors']['normal']['exclusion']['point_ids']);
        self::assertGreaterThan(0.0, $apart['vectors']['normal']['capture']['eve'][8]);

        // A host of workers or residents never takes a point this way.
        $workers = $this->create(['point' => FixtureRegion::LONE, 'terms' => ['host' => ['segment' => 'w_office', 'size' => 50]]]);
        self::assertNull($workers['terms']['host']['point_id']);
    }

    public function testTheRuleFollowsAMovedPin(): void
    {
        $truck = self::truck();
        $spot = $this->create(['point' => FixtureRegion::TAPROOM, 'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120]]]);
        self::assertSame(FixtureRegion::TAPROOM_KEY, $spot['host_details']['place_key']);

        // Moved next to the unnamed venue. The link to the taproom is one the dataset still knows, so it
        // stays; the owner picks another host by sending one.
        $moved = $this->service->update(self::ORG, $truck, $spot['id'], ['point' => FixtureRegion::LONE]);
        self::assertSame(FixtureRegion::TAPROOM_KEY, $moved['host_details']['place_key']);
        self::assertSame('p' . FixtureRegion::TAPROOM_KEY, $moved['terms']['host']['point_id']);

        $again = $this->service->update(self::ORG, $truck, $spot['id'], ['terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120]]]);
        self::assertNull($again['host_details']);
        self::assertSame('p' . FixtureRegion::LONE_VENUE_KEY, $again['terms']['host']['point_id']);
        self::assertSame(['p' . FixtureRegion::LONE_VENUE_KEY], $again['vectors']['normal']['exclusion']['point_ids']);
    }

    public function testWithoutAnActiveDatasetTheLinkIsLeftAsItIs(): void
    {
        $truck = self::truck();
        $spot = $this->create(['point' => FixtureRegion::TAPROOM, 'terms' => ['host' => ['place_key' => FixtureRegion::TAPROOM_KEY, 'size' => 120]]]);

        $this->region->unload();
        $after = $this->service->refresh(self::ORG, $truck, $spot['id']);
        self::assertSame(FixtureRegion::TAPROOM_KEY, $after['host_details']['place_key']);
        self::assertSame('p' . FixtureRegion::TAPROOM_KEY, $after['terms']['host']['point_id']);
        self::assertSame('taproom', $after['terms']['host']['place_type']);
        self::assertSame('fresh', $after['vectors_state'], 'nothing better can be computed');
        self::assertSame(array_fill(0, 16, 0.0), $after['vectors']['normal']['nearby']);
        self::assertNull($after['vectors']['normal']['dataset_version']);
        self::assertSame(1, $this->region->count('place'), 'the one lookup of the create, none since');

        // Nothing can be linked either: a key in a body is unknown.
        self::assertInvalid(
            'terms.host.place_key was not found',
            'terms.host.place_key',
            'V11',
            fn () => $this->service->update(self::ORG, $truck, $spot['id'], ['terms' => ['host' => ['place_key' => FixtureRegion::TAPROOM_KEY]]])
        );
    }

    public function testResolveHostLinkStepByStep(): void
    {
        $truck = self::truck();
        $taproom = FixtureRegion::TAPROOM;
        $sources = $this->region->sourcePoints();
        $host = ['segment' => 'v_nightlife', 'size' => 120.0, 'size_source' => 'owner', 'only_food' => true, 'point_id' => null, 'place_type' => null];
        $resolve = fn (array $at, ?array $h, ?string $key, ?string $type) => $this->service->resolveHostLink($truck, $at['lat'], $at['lng'], $h, $key, $type, $sources);

        // 1. a known link
        self::assertSame(['place_key' => FixtureRegion::TAPROOM_KEY, 'place_type' => 'taproom', 'point_id' => 'p' . FixtureRegion::TAPROOM_KEY], $resolve($taproom, $host, FixtureRegion::TAPROOM_KEY, 'bar'));
        self::assertSame(['place_key' => FixtureRegion::OFFICE_PARK_KEY, 'place_type' => 'office_park', 'point_id' => null], $resolve(FixtureRegion::OFFICE, ['segment' => 'w_office'] + $host, FixtureRegion::OFFICE_PARK_KEY, null));
        // a link without a host keeps the place and has no point
        self::assertSame(['place_key' => FixtureRegion::TAPROOM_KEY, 'place_type' => 'taproom', 'point_id' => null], $resolve($taproom, null, FixtureRegion::TAPROOM_KEY, null));
        // 2. an unknown link: the nearest place of the stored type, here the taproom itself
        self::assertSame(['place_key' => FixtureRegion::TAPROOM_KEY, 'place_type' => 'taproom', 'point_id' => 'p' . FixtureRegion::TAPROOM_KEY], $resolve($taproom, $host, 'w1', 'taproom'));
        // 2 then 3: no place of the stored type, but one of the host's visitor segment
        self::assertSame(['place_key' => FixtureRegion::TAPROOM_KEY, 'place_type' => 'taproom', 'point_id' => 'p' . FixtureRegion::TAPROOM_KEY], $resolve($taproom, $host, 'w1', 'events_venue'));
        // 2 without a host: cleared, the type stays
        self::assertSame(['place_key' => null, 'place_type' => 'events_venue', 'point_id' => null], $resolve($taproom, null, 'w1', 'events_venue'));
        // 3. no link, a visitor host
        self::assertSame(['place_key' => FixtureRegion::TAPROOM_KEY, 'place_type' => 'taproom', 'point_id' => 'p' . FixtureRegion::TAPROOM_KEY], $resolve($taproom, $host, null, null));
        // 3 does not apply to another segment, and 4 finds no source point of that segment
        self::assertSame(['place_key' => null, 'place_type' => null, 'point_id' => null], $resolve($taproom, ['segment' => 'v_events'] + $host, null, null));
        // 4. no place within 100 m, a source point of the segment within the venue link radius
        self::assertSame(['place_key' => null, 'place_type' => null, 'point_id' => 'p' . FixtureRegion::LONE_VENUE_KEY], $resolve(FixtureRegion::LONE, $host, null, null));
        // 4 after 1: a linked office park and a visitor host next to the unnamed venue
        $this->region->addPlace('w31', 'office_park', null, FixtureRegion::LONE['lat'], FixtureRegion::LONE['lng'], 0.0);
        self::assertSame(['place_key' => 'w31', 'place_type' => 'office_park', 'point_id' => 'p' . FixtureRegion::LONE_VENUE_KEY], $resolve(FixtureRegion::LONE, $host, 'w31', null));
        // no host at all
        self::assertSame(['place_key' => null, 'place_type' => null, 'point_id' => null], $resolve($taproom, null, null, null));
        // a truck without a region: nothing is looked up
        $lookups = count($this->region->calls);
        self::assertSame(
            ['place_key' => 'w1', 'place_type' => 'taproom', 'point_id' => 'pw1'],
            $this->service->resolveHostLink(self::truck('none'), $taproom['lat'], $taproom['lng'], ['point_id' => 'pw1'] + $host, 'w1', 'taproom', [])
        );
        self::assertCount($lookups, $this->region->calls);
    }

    // ------------------------------------------------------------------------------------ region data that does not fit

    public function testABuildMismatchRefusesWhatNeedsVectorsAndNothingElse(): void
    {
        $truck = self::truck();
        $spot = $this->create(['terms' => ['host' => ['segment' => 'w_office', 'size' => 600]]]);
        $this->region->usable = false;
        $before = $this->table->rows;
        $message = 'Region data was built with different model constants';

        $refused = [
            'create' => fn () => $this->create(['name' => 'Second']),
            'move' => fn () => $this->service->update(self::ORG, $truck, $spot['id'], ['name' => 'Moved', 'point' => FixtureRegion::TAPROOM]),
            'host' => fn () => $this->service->update(self::ORG, $truck, $spot['id'], ['terms' => ['host' => null]]),
            'refresh' => fn () => $this->service->refresh(self::ORG, $truck, $spot['id']),
        ];
        foreach ($refused as $what => $attempt) {
            try {
                $attempt();
                self::fail($what . ' went through');
            } catch (TpConflict $e) {
                self::assertSame($message, $e->getMessage(), $what);
            }
            self::assertSame($before, $this->table->rows, $what . ' stored nothing');
        }
        self::assertSame([], $this->legs->moves);

        // What needs no vectors still works, and fresh spots are left alone.
        $renamed = $this->service->update(self::ORG, $truck, $spot['id'], ['name' => 'Renamed', 'terms' => ['visibility' => 'hidden']]);
        self::assertSame('Renamed', $renamed['name']);
        self::assertSame('fresh', $renamed['vectors_state']);
        self::assertSame(['refreshed' => 0, 'remaining' => 0], $this->service->refreshStale(self::ORG, $truck));
        self::assertCount(1, $this->service->list(self::ORG, $truck));

        // A stale spot cannot be refreshed while the data does not fit.
        $this->table->rows[$spot['id']]['vec_seeds_rev'] = Seeds::revision() + 1;
        $this->expectException(TpConflict::class);
        $this->service->refreshStale(self::ORG, $truck);
    }
}

/**
 * An in-memory `tp_spots` behind the Database interface: it runs the statements SpotRepository writes and
 * keeps the rows as MySQL would return them (bound values as they were bound, UNHEX applied, NOW() from a
 * counter). The repository under test is the real one.
 */
final class SpotTable extends Database
{
    /** @var array<string, array<string, mixed>> rows by id */
    public array $rows = [];

    /** @var list<string> every statement in order, whitespace squashed */
    public array $statements = [];

    private int $ticks = 0;

    public function __construct()
    {
    }

    /**
     * The statements that start with `$prefix`.
     *
     * @return list<string>
     */
    public function writes(string $prefix): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => str_starts_with($sql, $prefix)));
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $sql = $this->record($sql);
        $params = array_values($params);
        if (preg_match('/^INSERT INTO tp_spots \((.+?)\) VALUES \((.+)\)$/', $sql, $m) === 1) {
            $columns = explode(', ', $m[1]);
            $expressions = explode(', ', $m[2]);
            if (count($columns) !== count($expressions)) {
                throw new \LogicException('SpotTable: columns and values differ in number');
            }
            $row = ['archived_at' => null];
            foreach ($columns as $i => $column) {
                $row[$column] = $this->evaluate($expressions[$i], $params);
            }
            if ($params !== []) {
                throw new \LogicException('SpotTable: more values than placeholders');
            }
            $this->rows[(string) $row['id']] = $row;
            return new \PDOStatement();
        }
        if (preg_match('/^UPDATE tp_spots SET (.+) WHERE id = \? AND organization_id = \?( AND archived_at IS NULL)?$/', $sql, $m) === 1) {
            $changes = [];
            foreach (explode(', ', $m[1]) as $assignment) {
                [$column, $expression] = explode(' = ', $assignment, 2);
                $changes[$column] = $this->evaluate($expression, $params);
            }
            if (count($params) !== 2) {
                throw new \LogicException('SpotTable: the WHERE of an UPDATE binds the id and the organization');
            }
            [$id, $org] = $params;
            $row = $this->rows[$id] ?? null;
            $activeOnly = ($m[2] ?? '') !== '';
            if ($row !== null && $row['organization_id'] === $org && !($activeOnly && $row['archived_at'] !== null)) {
                $next = array_merge($row, $changes);
                if ($next !== $row) {
                    $next['updated_at'] = $this->now();
                }
                $this->rows[$id] = $next;
            }
            return new \PDOStatement();
        }
        throw new \LogicException('SpotTable: unexpected statement: ' . $sql);
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $sql = $this->record($sql);
        if (str_contains($sql, 'COUNT(*) AS spot_count')) {
            [$org, $truck] = $params;
            $count = 0;
            foreach ($this->rows as $row) {
                if ($row['organization_id'] === $org && $row['truck_id'] === $truck && $row['archived_at'] === null) {
                    $count++;
                }
            }
            return ['spot_count' => $count];
        }
        if (str_contains($sql, 'WHERE id = ? AND organization_id = ?')) {
            [$id, $org] = $params;
            $row = $this->rows[$id] ?? null;
            if ($row === null || $row['organization_id'] !== $org) {
                return null;
            }
            if (str_contains($sql, 'archived_at IS NULL') && $row['archived_at'] !== null) {
                return null;
            }
            return $row;
        }
        throw new \LogicException('SpotTable: unexpected statement: ' . $sql);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $sql = $this->record($sql);
        $params = array_values($params);
        if (str_starts_with($sql, 'SELECT id FROM tp_spots')) {
            [$org, $truck, $region, $version, $revision, $limit] = $params;
            $ids = [];
            foreach ($this->rows as $row) {
                if ($row['organization_id'] !== $org || $row['truck_id'] !== $truck || $row['archived_at'] !== null) {
                    continue;
                }
                $fresh = $row['vectors_bin'] !== null && $row['vec_region_id'] === $region
                    && $row['vec_dataset'] === $version && $row['vec_seeds_rev'] === $revision;
                if (!$fresh) {
                    $ids[] = (string) $row['id'];
                }
            }
            sort($ids, SORT_STRING);
            return array_map(static fn (string $id): array => ['id' => $id], array_slice($ids, 0, (int) $limit));
        }
        if (str_contains($sql, 'AND id IN (')) {
            $org = array_shift($params);
            $out = [];
            foreach ($params as $id) {
                if (isset($this->rows[$id]) && $this->rows[$id]['organization_id'] === $org) {
                    $out[] = $this->rows[$id];
                }
            }
            return $out;
        }
        if (str_contains($sql, 'WHERE organization_id = ? AND truck_id = ?') && str_ends_with($sql, 'ORDER BY name, id')) {
            [$org, $truck] = $params;
            $out = [];
            foreach ($this->rows as $row) {
                if ($row['organization_id'] !== $org || $row['truck_id'] !== $truck) {
                    continue;
                }
                if (str_contains($sql, 'archived_at IS NULL') && $row['archived_at'] !== null) {
                    continue;
                }
                $out[] = $row;
            }
            usort($out, static fn (array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']) ?: strcmp((string) $a['id'], (string) $b['id']));
            return $out;
        }
        throw new \LogicException('SpotTable: unexpected statement: ' . $sql);
    }

    private function record(string $sql): string
    {
        $squashed = trim((string) preg_replace('/\s+/', ' ', $sql));
        $this->statements[] = $squashed;
        return $squashed;
    }

    /**
     * @param list<mixed> $params the values not yet used; the used ones are taken off the front
     */
    private function evaluate(string $expression, array &$params): mixed
    {
        switch ($expression) {
            case '?':
                return $this->take($params);
            case 'UNHEX(?)':
                $hex = $this->take($params);
                return $hex === null ? null : hex2bin((string) $hex);
            case 'NOW()':
                return $this->now();
            case 'NULL':
                return null;
        }
        throw new \LogicException('SpotTable: unexpected value expression: ' . $expression);
    }

    /**
     * @param list<mixed> $params
     */
    private function take(array &$params): mixed
    {
        if ($params === []) {
            throw new \LogicException('SpotTable: more placeholders than values');
        }
        $value = array_shift($params);
        if (is_float($value) || is_bool($value) || is_array($value)) {
            throw new \LogicException('SpotTable: a float, a boolean or an array was bound');
        }
        return $value;
    }

    private function now(): string
    {
        $this->ticks++;
        return sprintf('2026-10-05 12:%02d:%02d', intdiv($this->ticks, 60), $this->ticks % 60);
    }
}

/**
 * A small region that answers the capture contract from rows held in memory, with the model's own
 * functions. The standard layout:
 *
 *   OFFICE   the worked example of 02_MODEL.md 4.4: eight blocks of 250 office jobs to the north, two
 *            quick-service outlets to the south, and an office park (a place without people of its own)
 *   TAPROOM  a taproom that is a visitor source of 40 and a possible host, alone
 *   BAR      a bar with a kitchen of its own, a visitor source of 45 and a possible host
 *   MARKET   a farmers market: a possible host whose type hosts nothing by the hour
 *   LONE     a nightlife venue 50 m north that is a visitor source and no possible host
 *   HALO     a block outside the counties (in_region 0) 100 m north
 *   EMPTY    nothing within reach
 */
final class FixtureRegion implements CaptureProvider
{
    public const REGION = 'mini';
    public const VERSION = 'mini-20261003-aaaa1111';
    public const MISMATCH = 'Region data was built with different model constants';

    public const OFFICE = ['lat' => 38.96, 'lng' => -77.36];
    public const TAPROOM = ['lat' => 39.01, 'lng' => -77.41];
    public const BAR = ['lat' => 39.05, 'lng' => -77.45];
    public const MARKET = ['lat' => 39.1, 'lng' => -77.5];
    public const LONE = ['lat' => 38.9, 'lng' => -77.3];
    public const HALO = ['lat' => 38.8, 'lng' => -77.2];
    public const EMPTY = ['lat' => 38.5, 'lng' => -77.9];

    public const TAPROOM_KEY = 'w264230766';
    public const BAR_KEY = 'n4100';
    public const MARKET_KEY = 'w5200';
    public const OFFICE_PARK_KEY = 'w6300';
    public const LONE_VENUE_KEY = 'n7400';

    private const EARTH_RADIUS_M = 6371008.8;
    private const PI = 3.141592653589793;
    private const LOCATE_RADIUS_M = 2400.0;
    private const NEARBY_RADIUS_M = 250.0;
    private const NOWHERE = ['in_region' => false, 'region_id' => null, 'county_fips' => null, 'state' => null];

    public string $version = self::VERSION;
    public bool $loaded = true;
    public bool $usable = true;

    /** @var list<string> the contract methods that were called, in order */
    public array $calls = [];

    /** @var array<string, array{id: string, lat: float, lng: float, base: list<float>, in_region: bool}> */
    private array $points = [];

    /** @var array<string, array<string, mixed>> place rows by key */
    private array $places = [];

    public static function standard(): self
    {
        $region = new self();
        foreach ([75, 125, 175, 225, 275, 325, 375, 425] as $i => $metres) {
            $region->addBlock('b51059482500100' . ($i + 1), self::north(38.96, (float) $metres), -77.36, 1, 250.0, true);
        }
        $region->addRival('n100', 'fast_food', 'quick', self::north(38.96, -340.0), -77.36, 'Example Grill');
        $region->addRival('n101', 'fast_food', 'quick', self::north(38.96, -360.0), -77.36, null);
        $region->addPlace(self::OFFICE_PARK_KEY, 'office_park', null, self::north(38.96, 20.0), -77.36, 0.0);

        $region->addVenue(self::TAPROOM_KEY, 'taproom', 'v_nightlife', self::TAPROOM['lat'], self::TAPROOM['lng'], 40.0, 'Example Brewing');
        $region->addVenue(self::BAR_KEY, 'bar', 'v_nightlife', self::BAR['lat'], self::BAR['lng'], 45.0, 'Example Bar', 'yes');
        $region->places[self::BAR_KEY]['rival_kind'] = 'bar';
        $region->addPlace(self::MARKET_KEY, 'farmers_market', null, self::MARKET['lat'], self::MARKET['lng'], 0.0, 'Saturday Market');

        $region->addVenue(self::LONE_VENUE_KEY, 'bar', 'v_nightlife', self::north(self::LONE['lat'], 50.0), self::LONE['lng'], 40.0, null, 'unknown', false);
        $region->addBlock('b240338001001001', self::north(self::HALO['lat'], 100.0), self::HALO['lng'], 1, 250.0, false);
        return $region;
    }

    /** `$metres` north of a latitude (south when negative), the way 02_MODEL.md 4.4 writes it. */
    public static function north(float $lat, float $metres): float
    {
        return $lat + $metres / self::EARTH_RADIUS_M * 180.0 / self::PI;
    }

    // ---- building and changing the data

    public function addBlock(string $id, float $lat, float $lng, int $segment, float $amount, bool $inRegion): void
    {
        $base = array_fill(0, 16, 0.0);
        $base[$segment] = $amount;
        $this->points[$id] = ['id' => $id, 'lat' => $lat, 'lng' => $lng, 'base' => $base, 'in_region' => $inRegion];
    }

    public function addRival(string $key, string $type, string $kind, float $lat, float $lng, ?string $name): void
    {
        $this->addPlace($key, $type, null, $lat, $lng, 0.0, $name, 'yes', true, 0.0);
        $this->places[$key]['rival_kind'] = $kind;
    }

    /** A place without a source point of its own. */
    public function addPlace(
        string $key,
        string $type,
        ?string $visitorSegment,
        float $lat,
        float $lng,
        float $sizeDefault,
        ?string $name = null,
        string $kitchen = 'unknown',
        bool $possibleHost = true,
        ?float $hostFit = null
    ): void {
        $this->places[$key] = [
            'place_key' => $key, 'place_type' => $type, 'visitor_segment' => $visitorSegment, 'lat' => $lat, 'lng' => $lng,
            'size_default' => $sizeDefault, 'kitchen' => $kitchen, 'name' => $name, 'rival_kind' => null,
            'host_fit' => $hostFit ?? ($possibleHost ? 0.5 : 0.0),
        ];
    }

    /** A place that is a visitor source: it also gets the source point "p" + key. */
    public function addVenue(
        string $key,
        string $type,
        string $visitorSegment,
        float $lat,
        float $lng,
        float $sizeDefault,
        ?string $name = null,
        string $kitchen = 'unknown',
        bool $possibleHost = true
    ): void {
        $this->addPlace($key, $type, $visitorSegment, $lat, $lng, $sizeDefault, $name, $kitchen, $possibleHost);
        $base = array_fill(0, 16, 0.0);
        $base[array_search($visitorSegment, Estimator::seed(Seeds::defaults(), 'vocabulary.segments'), true)] = $sizeDefault;
        $this->points['p' . $key] = ['id' => 'p' . $key, 'lat' => $lat, 'lng' => $lng, 'base' => $base, 'in_region' => true];
    }

    public function removePlace(string $key): void
    {
        unset($this->places[$key], $this->points['p' . $key]);
    }

    /** Another dataset version becomes the active one; every block holds `$factor` times its people. */
    public function switchTo(string $version, float $factor): void
    {
        $this->version = $version;
        foreach ($this->points as $id => $point) {
            if (str_starts_with($id, 'b')) {
                foreach ($point['base'] as $s => $amount) {
                    $this->points[$id]['base'][$s] = $amount * $factor;
                }
            }
        }
    }

    /** The region loses its active version: nothing can be read. */
    public function unload(): void
    {
        $this->loaded = false;
    }

    public function count(string $method): int
    {
        return count(array_keys($this->calls, $method, true));
    }

    // ---- the rows as the model takes them

    /**
     * @return list<array{id: string, lat: float, lng: float, base: list<float>, rivals: array{day: float, eve: float}}>
     */
    public function sourcePoints(): array
    {
        $A = Seeds::defaults();
        $outlets = $this->modelOutlets();
        $ids = array_keys($this->points);
        sort($ids, SORT_STRING);
        $out = [];
        foreach ($ids as $id) {
            $point = $this->points[$id];
            $out[] = [
                'id' => (string) $id,
                'lat' => $point['lat'],
                'lng' => $point['lng'],
                'base' => $point['base'],
                'rivals' => Estimator::rivalsAtOrigin($A, $point['lat'], $point['lng'], $outlets),
            ];
        }
        return $out;
    }

    /**
     * @return list<array{id: string, lat: float, lng: float, kind: string}>
     */
    public function modelOutlets(): array
    {
        $out = [];
        foreach ($this->sortedPlaces() as $place) {
            if ($place['rival_kind'] !== null) {
                $out[] = ['id' => $place['place_key'], 'lat' => $place['lat'], 'lng' => $place['lng'], 'kind' => $place['rival_kind']];
            }
        }
        return $out;
    }

    // ---- the contract

    public function locate(string $regionId, float $lat, float $lng, ?string $version = null): array
    {
        $this->calls[] = 'locate';
        return $this->located($regionId, $lat, $lng);
    }

    public function capture(string $regionId, float $lat, float $lng, array $visibilities, ?array $host, ?string $version = null): array
    {
        $this->calls[] = 'capture';
        $A = Seeds::defaults();
        $reads = $this->reads($regionId);
        if ($reads && !$this->usable) {
            throw new TpConflict(self::MISMATCH);
        }
        $located = $this->located($regionId, $lat, $lng);
        $vectors = [];
        foreach ($visibilities as $level) {
            $one = Estimator::captureAtPoint(
                $A,
                $lat,
                $lng,
                (string) $level,
                $reads ? $this->sourcePoints() : [],
                $reads ? $this->modelOutlets() : [],
                Estimator::hostExclusion($A, $host)
            );
            $one['in_region'] = $located['in_region'];
            $one['region_id'] = $reads ? $regionId : null;
            $one['dataset_version'] = $reads ? $this->version : null;
            $vectors[(string) $level] = $one;
        }
        if (!$reads) {
            return ['located' => self::NOWHERE, 'vectors' => $vectors, 'outlets' => [], 'outlets_total' => 0, 'hosts_nearby' => []];
        }

        $cutoff = (float) Estimator::seed($A, 'kernel.walk_cutoff_m');
        $outlets = [];
        foreach ($this->within($lat, $lng, $cutoff, static fn (array $p): bool => $p['rival_kind'] !== null) as [$metres, $place]) {
            $outlets[] = [
                'place_key' => $place['place_key'], 'name' => $place['name'], 'place_type' => $place['place_type'],
                'rival_kind' => $place['rival_kind'], 'kitchen' => $place['kitchen'], 'lat' => $place['lat'], 'lng' => $place['lng'],
                'distance_m' => Estimator::roundHalfAway($metres, 1),
            ];
        }
        $hints = [];
        foreach ($this->within($lat, $lng, self::NEARBY_RADIUS_M, static fn (array $p): bool => $p['host_fit'] > 0.0) as [$metres, $place]) {
            $seed = Estimator::seed($A, 'place_types.rows.' . $place['place_type']);
            $kitchen = in_array($place['kitchen'], ['yes', 'no'], true) ? $place['kitchen'] : $seed['kitchen_default'];
            $hints[] = [
                'place_key' => $place['place_key'], 'name' => $place['name'], 'place_type' => $place['place_type'],
                'lat' => $place['lat'], 'lng' => $place['lng'], 'distance_m' => Estimator::roundHalfAway($metres, 1),
                'host_segment' => $seed['host_segment'], 'default_size' => $place['size_default'], 'kitchen' => $kitchen,
                'point_id' => $place['visitor_segment'] === null ? null : 'p' . $place['place_key'],
            ];
        }
        return [
            'located' => $located,
            'vectors' => $vectors,
            'outlets' => array_slice($outlets, 0, 60),
            'outlets_total' => count($outlets),
            'hosts_nearby' => array_slice($hints, 0, 10),
        ];
    }

    public function sources(string $regionId, float $lat, float $lng, ?string $version = null): array
    {
        $this->calls[] = 'sources';
        return $this->reads($regionId) ? $this->sourcePoints() : [];
    }

    public function place(string $regionId, string $placeKey, ?string $version = null): ?array
    {
        $this->calls[] = 'place';
        $place = $this->reads($regionId) ? ($this->places[$placeKey] ?? null) : null;
        if ($place === null) {
            return null;
        }
        return [
            'place_key' => $place['place_key'], 'place_type' => $place['place_type'], 'visitor_segment' => $place['visitor_segment'],
            'lat' => $place['lat'], 'lng' => $place['lng'], 'size_default' => $place['size_default'], 'kitchen' => $place['kitchen'],
        ];
    }

    public function hostsNear(string $regionId, float $lat, float $lng, float $radiusM, ?string $version = null): array
    {
        $this->calls[] = 'hostsNear';
        if (!$this->reads($regionId)) {
            return [];
        }
        $out = [];
        foreach ($this->within($lat, $lng, $radiusM, static fn (array $p): bool => $p['host_fit'] > 0.0) as [$metres, $place]) {
            $out[] = [
                'place_key' => $place['place_key'], 'place_type' => $place['place_type'], 'visitor_segment' => $place['visitor_segment'],
                'lat' => $place['lat'], 'lng' => $place['lng'], 'distance_m' => Estimator::roundHalfAway($metres, 1),
            ];
        }
        return $out;
    }

    // ---- internals

    private function reads(string $regionId): bool
    {
        return $this->loaded && $regionId === self::REGION;
    }

    /**
     * @return array{in_region: bool, region_id: ?string, county_fips: ?string, state: ?string}
     */
    private function located(string $regionId, float $lat, float $lng): array
    {
        if (!$this->reads($regionId)) {
            return self::NOWHERE;
        }
        $best = null;
        $bestMetres = 0.0;
        $ids = array_keys($this->points);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            if (!str_starts_with((string) $id, 'b')) {
                continue;
            }
            $point = $this->points[$id];
            $metres = Estimator::haversineM($lat, $lng, $point['lat'], $point['lng']);
            if ($metres <= self::LOCATE_RADIUS_M && ($best === null || $metres < $bestMetres)) {
                $best = $point;
                $bestMetres = $metres;
            }
        }
        if ($best === null) {
            return ['in_region' => false, 'region_id' => $regionId, 'county_fips' => null, 'state' => null];
        }
        $geoid = substr($best['id'], 1);
        return [
            'in_region' => $best['in_region'],
            'region_id' => $regionId,
            'county_fips' => substr($geoid, 0, 5),
            'state' => ['51' => 'VA', '24' => 'MD'][substr($geoid, 0, 2)] ?? null,
        ];
    }

    /**
     * The places within `$radius` metres that pass the test, nearest first, then by key.
     *
     * @return list<array{0: float, 1: array<string, mixed>}> [metres, place]
     */
    private function within(float $lat, float $lng, float $radius, callable $test): array
    {
        $found = [];
        foreach ($this->places as $place) {
            $metres = Estimator::haversineM($lat, $lng, $place['lat'], $place['lng']);
            if ($metres <= $radius && $test($place)) {
                $found[] = [$metres, $place];
            }
        }
        usort($found, static fn (array $a, array $b): int => (Estimator::qkey($a[0]) <=> Estimator::qkey($b[0])) ?: strcmp($a[1]['place_key'], $b[1]['place_key']));
        return $found;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sortedPlaces(): array
    {
        $keys = array_keys($this->places);
        sort($keys, SORT_STRING);
        $out = [];
        foreach ($keys as $key) {
            $out[] = $this->places[$key];
        }
        return $out;
    }
}

/**
 * The region service as the fixture region stands: which dataset version is active and whether it may be
 * used. It reads nothing.
 */
final class FixtureRegions extends RegionService
{
    private FixtureRegion $region;

    public function __construct(FixtureRegion $region)
    {
        parent::__construct(new RegionRepository(new RecordingDatabase()));
        $this->region = $region;
    }

    public function active(string $regionId): ?array
    {
        if ($regionId !== FixtureRegion::REGION || !$this->region->loaded) {
            return null;
        }
        return [
            'region_id' => FixtureRegion::REGION,
            'dataset_version' => $this->region->version,
            'timezone' => 'America/New_York',
            'h3_res' => 9,
            'usable' => $this->region->usable,
            'unusable_reason' => $this->region->usable ? null : RegionService::BUILD_MISMATCH,
            'config' => [],
        ];
    }
}

/**
 * A leg provider that only remembers which corrections it was asked to move.
 */
final class RecordingLegs implements LegProvider
{
    /** @var list<array{0: string, 1: string, 2: array<string, float>, 3: array<string, float>}> */
    public array $moves = [];
    public bool $fail = false;

    public function legs(string $orgId, array $truck, array $points, array $pairs, array $options = []): array
    {
        return [];
    }

    public function status(): array
    {
        return ['state' => 'no_key'];
    }

    public function movePoint(string $orgId, array $truck, array $oldPoint, array $newPoint): void
    {
        if ($this->fail) {
            throw new \RuntimeException('routing is down (test)');
        }
        $this->moves[] = [$orgId, (string) $truck['id'], $oldPoint, $newPoint];
    }
}
