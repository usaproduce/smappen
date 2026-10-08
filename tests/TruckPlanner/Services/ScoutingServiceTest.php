<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Core\Database;
use App\Tests\TruckPlanner\Support\FakeHttp;
use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\ScoutLeadRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Data\VectorCodec;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\CalibrationProvider;
use App\TruckPlanner\Services\Contracts\LegProvider;
use App\TruckPlanner\Services\Fallback\SeedFuelPrice;
use App\TruckPlanner\Services\Fallback\StraightLineLegs;
use App\TruckPlanner\Services\Google\PlacesContactClient;
use App\TruckPlanner\Services\Http\UpstreamGuard;
use App\TruckPlanner\Services\ScoutingService;
use App\TruckPlanner\Services\SpotService;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Support\TpNotFound;
use App\TruckPlanner\Services\Support\TpRateLimited;
use App\TruckPlanner\Services\Support\TpUnavailable;
use PHPUnit\Framework\TestCase;

// The fixture region, its region service, the in-memory `tp_spots` and the truck value live with the spot
// service's test. They are not test cases, so the autoloader cannot find them by name: the file is loaded here.
require_once __DIR__ . '/SpotServiceTest.php';

/**
 * ScoutingService on fixture rows: the real lead and spot repositories over in-memory tables, the fixture
 * region of SpotServiceTest for capture, the places of that region with the host vectors the loader would
 * store for them, a leg provider that answers straight lines unless a test scripts a leg, and Google
 * behind a stubbed transport. No database, no network.
 *
 * The places: a taproom 890 m from the base (a visitor source with a phone and a website), a bar, a
 * farmers market and an office park beside the worked example of 02_MODEL.md 4.4, plus a restaurant
 * (hosts nothing) and an office outside the counties. Each of the four that host is of another kind, so
 * the plain list holds one place of each of four kinds; the tests that need many places of one kind add
 * offices north of the base.
 */
final class ScoutingServiceTest extends TestCase
{
    private const ORG = SpotServiceTest::ORG;
    private const OTHER_ORG = SpotServiceTest::OTHER_ORG;
    private const USER = SpotServiceTest::USER;
    private const TRUCK = SpotServiceTest::TRUCK;
    private const KEY = 'tp-test-key-0123456789abcdef';

    private const TAPROOM = FixtureRegion::TAPROOM_KEY;
    private const BAR = FixtureRegion::BAR_KEY;
    private const MARKET = FixtureRegion::MARKET_KEY;
    private const OFFICE = FixtureRegion::OFFICE_PARK_KEY;
    private const RESTAURANT = 'n9100';
    private const HALO_OFFICE = 'w9200';

    private const NOTICE = 'Permission to trade here and local rules are yours to check.';
    private const LEGALITY_WORDS = ['legal', 'permitted', 'allowed to park', 'approved', 'permit'];

    /** What would read as a statement that a place takes trucks. No payload says any of it. */
    private const HOSTING_CLAIMS = ['allows trucks', 'allowed to', 'trucks allowed', 'trucks welcome', 'welcomes', 'accepts trucks',
        'takes trucks', 'hosts trucks', 'truck friendly', 'authorised', 'authorized', 'eligible', 'cleared for', 'available for'];

    /** The kinds of place, those that host most commonly first (the seed file's host_fit, then its order). */
    private const KINDS = ['taproom', 'farmers_market', 'office_park', 'apartment_community', 'events_venue', 'industrial_site',
        'big_box', 'car_dealership', 'gym', 'park', 'shopping_centre', 'campus', 'hospital', 'bar', 'stadium', 'hotel',
        'attraction', 'transit_station'];

    private const PLACE_ID = 'ChIJN1t_tDeuEmsRUsoyG83frY4';
    /** What Google says about the taproom: no part of these texts may be found in anything that is kept. */
    private const GOOGLE_TRACES = ['555-0199', 'google-says', 'Google Way', '(Google)', 'cid=424242', 'maps.google.com'];

    private FixedClock $clock;
    private MemoryCache $cache;
    private FixtureRegion $region;
    private FixtureRegions $regions;
    private ScoutPlaces $places;
    private ScoutLeadTable $leadTable;
    private SpotTable $spotTable;
    private ScoutLegs $legs;
    private FakeHttp $http;
    private RecordingDatabase $ledgerRows;
    private ScoutGuard $guard;
    private ScoutingService $service;

    /** @var object{asOf: list<string>, factor: float}&CalibrationProvider */
    private object $calibration;

    /** @var list<array{0: string, 1: int, 2: int}> what was asked of the shared bucket */
    private array $bucketCalls = [];
    private bool $bucketAnswer = true;

    /** @var string|false */
    private $keyBefore;
    private ?string $envBefore;

    protected function setUp(): void
    {
        // 03:30 UTC on Thursday is still Wednesday evening where the truck is.
        $this->clock = new FixedClock('2026-10-08 03:30:00');
        $this->cache = new MemoryCache();
        TpCache::wire($this->clock, $this->cache);

        $this->region = FixtureRegion::standard();
        $this->regions = new FixtureRegions($this->region);
        $this->places = new ScoutPlaces();
        $this->standardPlaces();
        $this->leadTable = new ScoutLeadTable($this->clock);
        $this->spotTable = new SpotTable();
        $this->legs = new ScoutLegs();
        $this->calibration = new class implements CalibrationProvider {
            /** @var list<string> */
            public array $asOf = [];
            public float $factor = 1.0;

            public function state(string $orgId, array $truck, array $A, string $asOf): array
            {
                $this->asOf[] = $asOf;
                $state = Estimator::calibrate($A, [], $asOf);
                $state['truck_factor'] = $this->factor;
                return $state;
            }
        };

        Registry::reset();
        Registry::set('capture', $this->region);
        Registry::set('legs', $this->legs);
        Registry::set('calibration', $this->calibration);
        Registry::set('fuel', new SeedFuelPrice($this->regions));

        $this->keyBefore = getenv('GOOGLE_API_KEY');
        $this->envBefore = $_ENV['GOOGLE_API_KEY'] ?? null;
        unset($_ENV['GOOGLE_API_KEY']);
        putenv('GOOGLE_API_KEY=' . self::KEY);

        $this->http = new FakeHttp();
        $this->ledgerRows = new RecordingDatabase();
        $ledger = new ApiLedger($this->ledgerRows);
        $this->guard = new ScoutGuard($this->clock, $ledger, function (string $bucket, int $tokens, int $wait): bool {
            $this->bucketCalls[] = [$bucket, $tokens, $wait];
            return $this->bucketAnswer;
        });
        $spots = new SpotRepository($this->spotTable);
        $this->service = new ScoutingService(
            new ScoutLeadRepository($this->leadTable),
            $this->places,
            $spots,
            new SpotService($spots, new CountsRepository(new RecordingDatabase()), $this->regions),
            $this->regions,
            new PlacesContactClient($this->http, $ledger),
            $this->guard,
            $this->clock
        );
    }

    protected function tearDown(): void
    {
        putenv($this->keyBefore === false ? 'GOOGLE_API_KEY' : 'GOOGLE_API_KEY=' . $this->keyBefore);
        if ($this->envBefore !== null) {
            $_ENV['GOOGLE_API_KEY'] = $this->envBefore;
        }
        Registry::reset();
        TpCache::wire();
        TpConfig::replace(null);
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
     * The truck value, with profile fields changed.
     *
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    private static function truck(array $profile = [], string $regionId = FixtureRegion::REGION): array
    {
        $truck = SpotServiceTest::truck($regionId);
        $truck['profile'] = $profile + $truck['profile'];
        return $truck;
    }

    /** The places of the fixture region as rows of `tp_places`, each possible host with its stored vector. */
    private function standardPlaces(): void
    {
        $this->addRegionPlace(self::TAPROOM, 1.0, '51107', 'Example Brewing', [
            'phone' => '+17035550100', 'website' => 'https://example.com', 'addr_line' => '1 Example Rd', 'city' => 'Sterling',
            'state_code' => 'VA', 'postcode' => '20166', 'opening_hours_raw' => 'Mo-Su 12:00-22:00', 'brand' => 'Example',
        ]);
        $this->addRegionPlace(self::BAR, 0.3, '51107', 'Example Bar');
        $this->addRegionPlace(self::MARKET, 0.8, '51107', 'Saturday Market');
        $this->addRegionPlace(self::OFFICE, 0.8, '51059', 'Herndon Office Park', ['city' => 'Herndon', 'state_code' => 'VA']);
        // A restaurant hosts nothing, and a place outside the counties carries neither a county nor a vector.
        $this->places->add(['place_key' => self::RESTAURANT, 'place_type' => 'restaurant', 'name' => 'Example Diner',
            'lat' => 39.011, 'lng' => -77.41, 'county_fips' => '51107', 'host_fit' => 0.0, 'kitchen' => 'yes']);
        $this->places->add(['place_key' => self::HALO_OFFICE, 'place_type' => 'office_park', 'name' => 'Office over the line',
            'lat' => 39.02, 'lng' => -77.42, 'county_fips' => null, 'host_fit' => 0.8, 'kitchen' => 'no', 'in_region' => false]);
    }

    /**
     * A place the fixture region knows, as its `tp_places` row. The vector is the one the region loader
     * stores: capture at the place's point, visibility normal, the place's own source point left out.
     *
     * @param array<string, mixed> $display
     */
    private function addRegionPlace(string $key, float $hostFit, string $county, string $name, array $display = []): void
    {
        $place = $this->region->place(FixtureRegion::REGION, $key);
        self::assertNotNull($place);
        $A = Seeds::defaults();
        $own = $place['visitor_segment'] === null ? [] : ['p' . $key];
        $vectors = Estimator::captureAtPoint(
            $A,
            $place['lat'],
            $place['lng'],
            'normal',
            $this->region->sourcePoints(),
            $this->region->modelOutlets(),
            ['point_ids' => $own, 'segment' => null, 'amount' => 0.0]
        );
        $this->places->add($display + [
            'place_key' => $key, 'place_type' => $place['place_type'], 'name' => $name, 'lat' => $place['lat'], 'lng' => $place['lng'],
            'county_fips' => $county, 'host_fit' => $hostFit, 'kitchen' => $place['kitchen'], 'size_default' => $place['size_default'],
            'visitor_segment' => $place['visitor_segment'], 'host_vec' => pack('e50', ...VectorCodec::flat($vectors)),
        ]);
    }

    /**
     * A place `$metres` north of the base whose only people are `$workers` office workers around it.
     */
    private function addNorth(string $key, string $name, float $metres, float $workers, string $county = '51061', string $type = 'office_park'): void
    {
        $base = SpotServiceTest::truck()['profile']['base'];
        $capture = array_fill(0, 16, 0.0);
        $capture[1] = $workers;
        $vectors = ['capture' => ['day' => $capture, 'eve' => $capture], 'nearby' => $capture, 'rivals' => ['day' => 0.0, 'eve' => 0.0]];
        $this->places->add([
            'place_key' => $key, 'place_type' => $type, 'name' => $name,
            'lat' => FixtureRegion::north($base['lat'], $metres), 'lng' => $base['lng'],
            'county_fips' => $county, 'host_fit' => 0.8, 'kitchen' => 'no',
            'host_vec' => pack('e50', ...VectorCodec::flat($vectors)),
        ]);
    }

    /**
     * Office parks north of the base, the better the nearer: `g001` has the most workers around it. Each
     * has a name of its own ("Plaza 001 Offices"), so each is a site of its own.
     *
     * @return list<string> their keys, best first
     */
    private function addOffices(
        int $count,
        float $firstMetres = 500.0,
        float $stepMetres = 20.0,
        string $county = '51061',
        string $prefix = 'g',
        string $type = 'office_park'
    ): array {
        $keys = [];
        for ($i = 1; $i <= $count; $i++) {
            $key = sprintf('%s%03d', $prefix, $i);
            $this->addNorth($key, sprintf('Plaza %03d Offices', $i), $firstMetres + $stepMetres * $i, 400.0 - $i, $county, $type);
            $keys[] = $key;
        }
        return $keys;
    }

    /**
     * @param array<int|string, mixed> $query
     * @param array<string, mixed>|null $truck
     * @return array<string, mixed>
     */
    private function rank(array $query = [], ?array $truck = null, string $orgId = self::ORG): array
    {
        return $this->service->rank($orgId, $truck ?? self::truck(), self::A(), $query);
    }

    /**
     * @param array<string, mixed> $answer the answer of rank()
     * @return list<string> the place keys in the order of the list
     */
    private static function keys(array $answer, ?string $kind = null): array
    {
        $keys = [];
        foreach ($answer['candidates'] as $candidate) {
            if ($kind === null || $candidate['kind'] === $kind) {
                $keys[] = $candidate['place']['place_key'];
            }
        }
        return $keys;
    }

    /**
     * @param array<string, mixed> $answer the answer of rank()
     * @return array<string, mixed> the candidate of a place that is listed
     */
    private static function candidate(array $answer, string $key): array
    {
        foreach ($answer['candidates'] as $candidate) {
            if ($candidate['place']['place_key'] === $key) {
                return $candidate;
            }
        }
        self::fail($key . ' is not listed');
    }

    /**
     * @param array<string, mixed> $answer the answer of rank()
     * @return array<string, mixed> the counts of one kind
     */
    private static function kind(array $answer, string $kind): array
    {
        foreach ($answer['kinds'] as $row) {
            if ($row['kind'] === $kind) {
                return $row;
            }
        }
        self::fail($kind . ' was not asked for');
    }

    /**
     * The model's own result for one place, as the best of its kind.
     *
     * @param array<string, mixed> $truck
     * @return array<string, mixed>|null ScoutResult with position 1; null for a place outside the limit
     */
    private function modelResult(string $key, array $truck, ?array $cal = null): ?array
    {
        return $this->modelRanking([$key], $truck, $cal)[0] ?? null;
    }

    /**
     * The model's own answer: scoutEstimate for each of these places on the legs the provider gives, the
     * drive limit, scoutRank.
     *
     * @param list<string> $keys
     * @param array<string, mixed> $truck
     * @param array<string, mixed>|null $cal
     * @return list<array<string, mixed>> ScoutResult in rank order
     */
    private function modelRanking(array $keys, array $truck, ?array $cal = null): array
    {
        $A = self::A();
        $profile = $truck['profile'];
        $cal ??= Estimator::calibrate($A, [], '2026-10-07');
        $fuel = (float) Registry::fuel()->resolve($truck)['price_per_gal'];
        $results = [];
        foreach ($keys as $key) {
            $row = $this->places->rows[$key];
            $pointId = $row['visitor_segment'] === null ? null : 'p' . $key;
            $place = [
                'place_id' => $key, 'place_type' => $row['place_type'], 'point' => ['lat' => $row['lat'], 'lng' => $row['lng']],
                'point_id' => $pointId, 'size_default' => $row['size_default'], 'kitchen' => $row['kitchen'],
                'vectors' => VectorCodec::fromFlat(VectorCodec::fromBytes($row['host_vec'])) + [
                    'within' => null, 'visibility' => 'normal', 'in_region' => true, 'region_id' => FixtureRegion::REGION,
                    'exclusion' => ['point_ids' => $pointId === null ? [] : [$pointId], 'segment' => null, 'amount' => 0.0],
                    'excluded_amount' => 0.0, 'points_used' => null, 'dataset_version' => FixtureRegion::VERSION,
                    'model_version' => Estimator::MODEL_VERSION,
                ],
            ];
            $legs = [];
            foreach ($this->legs->answer($truck, $row, [['base', $key], [$key, 'base']]) as $leg) {
                $legs[$leg['from_id'] . '>' . $leg['to_id']] = $leg['leg_input'];
            }
            $result = Estimator::scoutEstimate($A, $profile, $place, $legs, $cal, $fuel);
            if ($result !== null && $result['round_trip']['minutes'] <= 2 * $profile['scout_drive_minutes_limit']) {
                $results[] = $result;
            }
        }
        return Estimator::scoutRank($results);
    }

    /** A place as Google answers it, `$metres` north of the fixture taproom. */
    private static function googlePlace(float $metres = 35.0, string $id = self::PLACE_ID): array
    {
        return [
            'id' => $id,
            'displayName' => ['text' => 'Example Brewing Co (Google)', 'languageCode' => 'en'],
            'formattedAddress' => '99 Google Way, Sterling, VA 20166, USA',
            'location' => ['latitude' => FixtureRegion::north(FixtureRegion::TAPROOM['lat'], $metres), 'longitude' => FixtureRegion::TAPROOM['lng']],
            'nationalPhoneNumber' => '(703) 555-0199',
            'websiteUri' => 'https://google-says.example.com/',
            'googleMapsUri' => 'https://maps.google.com/?cid=424242',
        ];
    }

    /** Google answers a search by name with the place. */
    private function found(float $metres = 35.0, string $id = self::PLACE_ID): void
    {
        $this->http->json(200, ['places' => [self::googlePlace($metres, $id)]]);
    }

    /** Google answers a request by id with the place: the place itself, without a location. */
    private function detailed(string $phone = '(703) 555-0199'): void
    {
        $place = ['nationalPhoneNumber' => $phone] + self::googlePlace();
        unset($place['location']);
        $this->http->json(200, $place);
    }

    /**
     * The statements the lead table has seen since `$from`, by their first word.
     *
     * @return list<string>
     */
    private function leadStatementsSince(int $from): array
    {
        return array_map(
            static fn (string $sql): string => (string) strtok($sql, ' '),
            array_slice($this->leadTable->statements, $from)
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

    /**
     * @param class-string<\Throwable> $class
     */
    private static function assertRefused(string $class, string $message, callable $fn): void
    {
        try {
            $fn();
            self::fail('expected ' . $class . ': ' . $message);
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e, get_class($e) . ': ' . $e->getMessage());
            self::assertSame($message, $e->getMessage());
        }
    }

    // ------------------------------------------------------------------------------------ the list

    public function testTheListIsTheBestOfEveryKindEachResultTheModels(): void
    {
        $answer = $this->rank();

        self::assertSame(
            ['candidates', 'kinds', 'quota', 'screened', 'truncated', 'limit_minutes', 'licence_counties', 'dataset_version', 'cached', 'notice', 'attribution'],
            array_keys($answer)
        );
        // the four possible hosts; the restaurant and the office outside the counties are no candidates
        self::assertSame(4, $answer['screened']);
        self::assertFalse($answer['truncated']);
        self::assertSame(45, $answer['limit_minutes']);
        self::assertSame([], $answer['licence_counties']);
        self::assertSame(FixtureRegion::VERSION, $answer['dataset_version']);
        self::assertFalse($answer['cached']);
        self::assertSame(8, $answer['quota'], 'eight places of every kind');

        // Kind by kind, the kinds that host most commonly first: a taproom, a market, an office park, a bar.
        self::assertSame([self::TAPROOM, self::MARKET, self::OFFICE, self::BAR], self::keys($answer));
        self::assertSame(['taproom', 'farmers_market', 'office_park', 'bar'], array_column($answer['candidates'], 'kind'));
        foreach (self::keys($answer) as $key) {
            // each is the best of its kind, and its result is the model's own
            self::assertSame($this->modelResult($key, self::truck()), self::candidate($answer, $key)['result'], $key);
            self::assertSame(1, self::candidate($answer, $key)['result']['position']);
        }

        // every kind there is is counted, also those the region has nothing of
        self::assertSame(self::KINDS, array_column($answer['kinds'], 'kind'));
        self::assertSame(self::KINDS, ScoutingService::kinds(self::A()));
        foreach ($answer['kinds'] as $row) {
            $has = in_array($row['kind'], ['taproom', 'farmers_market', 'office_park', 'bar'], true) ? 1 : 0;
            self::assertSame(['kind' => $row['kind'], 'screened' => $has, 'listed' => $has, 'merged' => 0], $row);
        }

        // The office park 20 m from the worked example of 02_MODEL.md 4.4: Tuesday lunch, about 70 orders.
        $office = self::candidate($answer, self::OFFICE);
        self::assertSame(['dow' => 1, 'open_minute' => 660, 'close_minute' => 840], $office['result']['best_window']);
        self::assertGreaterThan(60.0, $office['result']['orders']['value']);
        self::assertLessThan(75.0, $office['result']['orders']['value']);
        self::assertSame('rough', $office['result']['orders']['confidence']);
        self::assertSame(0.0, $office['result']['host_size'], 'an office park has no size of its own');
        // The taproom: its own 40 guests on Saturday evening, the truck being the only food.
        $taproom = self::candidate($answer, self::TAPROOM);
        self::assertSame(['dow' => 5, 'open_minute' => 1020, 'close_minute' => 1200], $taproom['result']['best_window']);
        self::assertEqualsWithDelta(21.516, $taproom['result']['orders']['value'], 1e-9);
        self::assertSame('very_rough', $taproom['result']['orders']['confidence']);
        self::assertSame('no', $taproom['result']['kitchen']);
        // The market has no hour of its own and nobody around it: no window. It is still the one market.
        $market = self::candidate($answer, self::MARKET);
        self::assertNull($market['result']['best_window']);
        self::assertSame(0.0, $market['result']['orders']['value']);

        foreach ($answer['candidates'] as $candidate) {
            self::assertSame(
                ['kind', 'result', 'place', 'lead', 'maps_url', 'leg_sources', 'at_capacity', 'demand_key', 'merged'],
                array_keys($candidate)
            );
            self::assertSame($candidate['kind'], $candidate['result']['place_type']);
            self::assertSame($candidate['kind'], $candidate['place']['place_type']);
            self::assertSame(
                ['place_id', 'place_type', 'position', 'host_fit', 'kitchen', 'host_segment', 'host_size', 'size_source', 'best_window',
                    'orders', 'contribution', 'round_trip', 'score'],
                array_keys($candidate['result'])
            );
            // every estimate is a range with its confidence label
            foreach (['orders', 'contribution'] as $estimate) {
                self::assertSame(['value', 'low', 'high', 'confidence'], array_keys($candidate['result'][$estimate]));
                self::assertLessThanOrEqual($candidate['result'][$estimate]['value'], $candidate['result'][$estimate]['low']);
                self::assertGreaterThanOrEqual($candidate['result'][$estimate]['value'], $candidate['result'][$estimate]['high']);
                self::assertContains($candidate['result'][$estimate]['confidence'], ['very_rough', 'rough', 'fair', 'good']);
            }
            self::assertSame(
                ['place_key', 'name', 'brand', 'place_type', 'lat', 'lng', 'county_fips', 'addr_line', 'city', 'state_code', 'postcode',
                    'phone', 'website', 'opening_hours_raw', 'kitchen'],
                array_keys($candidate['place'])
            );
            // a place the owner has not touched
            self::assertSame(
                ['id' => null, 'place_key' => $candidate['place']['place_key'], 'status' => 'new', 'notes' => null, 'spot_id' => null, 'google' => null],
                $candidate['lead']
            );
            self::assertSame(['out' => 'straight_line', 'back' => 'straight_line'], $candidate['leg_sources']);
            // none of them fills the truck, and none stands for a second place
            self::assertFalse($candidate['at_capacity']);
            self::assertNull($candidate['demand_key']);
            self::assertSame(0, $candidate['merged']);
        }
        self::assertSame(
            [
                'place_key' => self::TAPROOM, 'name' => 'Example Brewing', 'brand' => 'Example', 'place_type' => 'taproom',
                'lat' => 39.01, 'lng' => -77.41, 'county_fips' => '51107', 'addr_line' => '1 Example Rd', 'city' => 'Sterling',
                'state_code' => 'VA', 'postcode' => '20166', 'phone' => '+17035550100', 'website' => 'https://example.com',
                'opening_hours_raw' => 'Mo-Su 12:00-22:00', 'kitchen' => 'unknown',
            ],
            $taproom['place']
        );
        self::assertSame([], $this->leadTable->rows, 'reading the list creates no lead');
        self::assertSame(['SELECT'], array_values(array_unique($this->leadStatementsSince(0))), 'and writes nothing: there is nothing to sweep');
    }

    public function testAStoredZoneThisServerDoesNotKnowDoesNotTakeTheListDown(): void
    {
        $expected = $this->rank();
        $truck = self::truck();
        $truck['timezone'] = 'Mars/Olympus_Mons';
        $answer = null;
        $lines = LogCapture::during(function () use (&$answer, $truck): void {
            $answer = $this->rank([], $truck);
        });
        self::assertSame(['[tp] a truck has a time zone this server does not know, the default zone is used'], $lines);
        self::assertNotSame([], $answer['candidates']);
        self::assertSame(self::keys($expected), self::keys($answer), 'the list is the one of a truck on New York time');
        // today is read in the default zone: 03:30 UTC on the 8th is still the 7th in New York
        self::assertSame(['2026-10-07', '2026-10-07'], $this->calibration->asOf);
    }

    public function testEveryCandidateHasAMapsLinkThatNeedsNoApiCall(): void
    {
        $answer = $this->rank();
        self::assertCount(4, $answer['candidates']);
        foreach ($answer['candidates'] as $candidate) {
            self::assertSame(
                'https://www.google.com/maps/search/?api=1&query=' . sprintf('%.6F', $candidate['place']['lat']) . '%2C' . sprintf('%.6F', $candidate['place']['lng']),
                $candidate['maps_url']
            );
        }
        self::assertSame([], $this->http->requests);

        // Once Google's id of the place is kept on the lead, the link names the place ...
        $this->found();
        $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false);
        $taproom = self::candidate($this->rank(), self::TAPROOM);
        $link = 'https://www.google.com/maps/search/?api=1&query=Example%20Brewing&query_place_id=' . self::PLACE_ID;
        self::assertSame($link, $taproom['maps_url']);
        self::assertSame($link, $taproom['lead']['google']['maps_url']);
        // ... and goes on doing so: a place id may be kept, and nothing about it runs out.
        $this->clock->advance(400 * 86400);
        $later = self::candidate($this->rank(), self::TAPROOM);
        self::assertSame($link, $later['maps_url']);
        self::assertSame($taproom['lead'], $later['lead']);
        self::assertCount(1, $this->http->requests, 'a link is built, not asked for');
    }

    public function testTheStandingReminderAndTheSourcesTravelWithTheList(): void
    {
        foreach ([[], ['types' => 'taproom'], ['hide' => 'new']] as $query) {
            $answer = $this->rank($query);
            self::assertSame(self::NOTICE, $answer['notice']);
            self::assertSame(ScoutingService::NOTICE, $answer['notice']);
            self::assertSame(
                [
                    "\u{00A9} OpenStreetMap contributors",
                    "Place data \u{00A9} OpenStreetMap contributors, available under the Open Database License (ODbL).",
                    'Drive times and distances: Google Maps Platform. Kept for at most 30 days.',
                ],
                $answer['attribution']
            );
        }
    }

    public function testNothingInAScoutPayloadReadsAsAStatementAboutRulesOrAboutAPlaceTakingTrucks(): void
    {
        $this->addOffices(20);
        $this->found();
        $this->detailed();
        $payloads = [
            $this->rank(),
            $this->rank(['types' => 'office_park,taproom']),
            $this->service->saveLead(self::ORG, self::truck(), self::TAPROOM, ['status' => 'contacted', 'notes' => 'Call back Tuesday']),
            $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false),
            $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false),
            $this->service->saveAsSpot(self::ORG, self::truck(), self::USER, self::TAPROOM, []),
            $this->rank(['hide' => '']),
            $this->rank(['hide' => 'new']),
        ];
        foreach ($payloads as $n => $payload) {
            $text = (string) json_encode($payload);
            foreach (array_merge(self::LEGALITY_WORDS, self::HOSTING_CLAIMS) as $word) {
                self::assertStringNotContainsStringIgnoringCase($word, $text, 'payload ' . $n);
            }
        }
        foreach ([ScoutingService::NO_REGION, ScoutingService::PLACE_NOT_FOUND, ScoutingService::LOOKUP_UNAVAILABLE,
            ScoutingService::LOOKUP_BUSY, ScoutingService::ALREADY_SAVED, ScoutingService::NOTICE] as $sentence) {
            foreach (array_merge(self::LEGALITY_WORDS, self::HOSTING_CLAIMS) as $word) {
                self::assertStringNotContainsStringIgnoringCase($word, $sentence);
            }
        }
        // What the list adds to a candidate is its kind and three facts about its place in the list.
        $listed = $payloads[0]['candidates'][0];
        self::assertSame(
            ['kind', 'at_capacity', 'demand_key', 'merged'],
            array_values(array_diff(array_keys($listed), ['result', 'place', 'lead', 'maps_url', 'leg_sources']))
        );
        self::assertSame(['kind', 'screened', 'listed', 'merged'], array_keys($payloads[0]['kinds'][0]));
    }

    public function testTheScreenReadsEveryPageOfThePossibleHosts(): void
    {
        $config = TpConfig::all();
        $config['scout']['page_rows'] = 2;
        TpConfig::replace($config);
        $answer = $this->rank();
        self::assertSame(4, $answer['screened']);
        self::assertCount(4, $answer['candidates']);
        // two full pages and the empty one that ends the reading, each after the last key of the one before
        self::assertSame(['', 'w264230766', 'w6300'], array_column($this->places->pages, 'after'));
        foreach ($this->places->pages as $page) {
            self::assertSame(FixtureRegion::REGION, $page['region']);
            self::assertSame(FixtureRegion::VERSION, $page['version']);
        }
    }

    public function testTheBoxOfTheScreenIsTheReachOfTheDriveLimit(): void
    {
        $this->rank([], self::truck(['scout_drive_minutes_limit' => 30, 'truck_time_factor' => 1.1]));
        $box = $this->places->pages[0]['box'];
        // 1.25 x 30 / 1.1 = 34.09 free-flow minutes: 2 miles at 25 mph, the rest at 45 mph, over the detour factor
        $miles = 2.0 + (1.25 * 30 / 1.1 - 2.0 / 25.0 * 60.0) * 45.0 / 60.0;
        $metres = $miles * 1609.344 / 1.3;
        self::assertEqualsWithDelta(29.67, $metres / 1000.0, 0.01, 'about 30 km in a straight line');
        $dLat = rad2deg($metres / 6371008.8) * 1.01;
        self::assertEqualsWithDelta(39.003 - $dLat, $box['lat_min'], 1e-12);
        self::assertEqualsWithDelta(39.003 + $dLat, $box['lat_max'], 1e-12);
        self::assertEqualsWithDelta(-77.405 - $dLat / cos(deg2rad(39.003)), $box['lng_min'], 1e-12);
        self::assertEqualsWithDelta(-77.405 + $dLat / cos(deg2rad(39.003)), $box['lng_max'], 1e-12);

        // a short limit stays on local streets
        $this->places->pages = [];
        $this->rank([], self::truck(['scout_drive_minutes_limit' => 5, 'truck_time_factor' => 2.0]));
        $short = (1.25 * 5 / 2.0) * 25.0 / 60.0 * 1609.344 / 1.3;
        self::assertEqualsWithDelta(39.003 + rad2deg($short / 6371008.8) * 1.01, $this->places->pages[0]['box']['lat_max'], 1e-12);
    }

    // ------------------------------------------------------------------------------------ balanced by kind

    public function testOneKindDoesNotCrowdOutTheOthers(): void
    {
        // 130 office parks close to the base, each with more people around it than the taproom or the bar has.
        $this->addOffices(130);
        $answer = $this->rank();
        self::assertSame(134, $answer['screened']);
        self::assertSame(['kind' => 'office_park', 'screened' => 131, 'listed' => 8, 'merged' => 0], self::kind($answer, 'office_park'));
        // The other kinds are all still there, each with its best place, before and after the offices.
        self::assertSame(['taproom', 'farmers_market', 'office_park', 'bar'], array_values(array_unique(array_column($answer['candidates'], 'kind'))));
        self::assertSame([self::TAPROOM], self::keys($answer, 'taproom'));
        self::assertSame([self::MARKET], self::keys($answer, 'farmers_market'));
        self::assertSame([self::BAR], self::keys($answer, 'bar'));
        self::assertCount(11, $answer['candidates']);
        // Inside the kind the order is the model's, numbered from 1.
        $offices = array_values(array_filter($answer['candidates'], static fn (array $c): bool => $c['kind'] === 'office_park'));
        self::assertSame(range(1, 8), array_map(static fn (array $c): int => $c['result']['position'], $offices));
        for ($i = 1; $i < 8; $i++) {
            self::assertGreaterThanOrEqual(
                Estimator::qkey($offices[$i]['result']['score']),
                Estimator::qkey($offices[$i - 1]['result']['score'])
            );
        }
        // One global ranking would have been offices only: every one of them outranks the taproom.
        $taproomScore = self::candidate($answer, self::TAPROOM)['result']['score'];
        foreach ($offices as $office) {
            self::assertGreaterThan($taproomScore, $office['result']['score']);
        }
    }

    public function testAKindIsFilledFromItsBestScreensAndAnotherBatchIsReadOnlyWhenNeeded(): void
    {
        $offices = $this->addOffices(130);
        $truck = self::truck(['licence_counties' => ['51061'], 'scout_drive_minutes_limit' => 30]);

        // All of them are close: the first batch of the kind (its quota of 8 and 4 more) is enough.
        $answer = $this->rank([], $truck);
        self::assertSame(130, $answer['screened']);
        self::assertSame(array_slice($offices, 0, 8), self::keys($answer));
        self::assertSame(['kind' => 'office_park', 'screened' => 130, 'listed' => 8, 'merged' => 0], self::kind($answer, 'office_park'));
        self::assertCount(1, $this->legs->calls);
        self::assertCount(24, $this->legs->calls[0]['pairs']);
        self::assertSame(13, $this->legs->calls[0]['points'], 'the base and twelve places');
        self::assertSame($this->modelRanking(array_slice($offices, 0, 8), $truck), array_column($answer['candidates'], 'result'));

        // Routed legs put the best six outside the limit: the next batch fills the kind.
        foreach (array_slice($offices, 0, 6) as $key) {
            $this->legs->route('base', $key, 7200.0, 50000.0);
            $this->legs->route($key, 'base', 7200.0, 50000.0);
        }
        $this->legs->calls = [];
        $answer = $this->rank([], $truck);
        self::assertSame(array_slice($offices, 6, 8), self::keys($answer));
        self::assertCount(2, $this->legs->calls, 'a second batch, and no third');
        self::assertSame([['base', 'g013'], ['g013', 'base']], array_slice($this->legs->calls[1]['pairs'], 0, 2));
        self::assertCount(24, $this->legs->calls[1]['pairs']);
        self::assertSame(range(1, 8), array_map(static fn (array $c): int => $c['result']['position'], $answer['candidates']));
        foreach ($answer['candidates'] as $candidate) {
            self::assertStringStartsWith('https://www.google.com/maps/search/?api=1&query=', $candidate['maps_url']);
        }
    }

    public function testOnlyAKindThatIsShortIsAskedAboutAgain(): void
    {
        $offices = $this->addOffices(40);
        $sites = $this->addOffices(40, 5000.0, 20.0, '51061', 'i', 'industrial_site');
        $truck = self::truck(['licence_counties' => ['51061'], 'scout_drive_minutes_limit' => 30]);
        // The best six offices are outside the limit on their routed legs; the industrial sites are fine.
        foreach (array_slice($offices, 0, 6) as $key) {
            $this->legs->route('base', $key, 7200.0, 50000.0);
            $this->legs->route($key, 'base', 7200.0, 50000.0);
        }
        $answer = $this->rank([], $truck);
        self::assertSame(array_slice($offices, 6, 8), self::keys($answer, 'office_park'));
        self::assertSame(array_slice($sites, 0, 8), self::keys($answer, 'industrial_site'));
        self::assertCount(2, $this->legs->calls);
        self::assertCount(48, $this->legs->calls[0]['pairs'], 'twelve places of each of the two kinds');
        self::assertCount(24, $this->legs->calls[1]['pairs'], 'then twelve more offices, and no industrial site');
        foreach ($this->legs->calls[1]['pairs'] as [$from, $to]) {
            self::assertStringStartsWith('g', $from === 'base' ? $to : $from);
        }
    }

    public function testAtMostThreeBatchesOfAKindAreAskedAbout(): void
    {
        $offices = $this->addOffices(260, 300.0, 10.0);
        foreach ($offices as $key) {
            $this->legs->route('base', $key, 7200.0, 50000.0);
            $this->legs->route($key, 'base', 7200.0, 50000.0);
        }
        $answer = $this->rank([], self::truck(['licence_counties' => ['51061'], 'scout_drive_minutes_limit' => 30]));
        self::assertSame(260, $answer['screened']);
        self::assertSame(3, TpConfig::get('scout.max_batches'));
        self::assertCount(3, $this->legs->calls);
        self::assertSame([24, 24, 24], array_map(static fn (array $call): int => count($call['pairs']), $this->legs->calls));
        self::assertSame([], $answer['candidates'], 'every place that was asked about is outside the limit on its routed legs');
        self::assertSame(['kind' => 'office_park', 'screened' => 260, 'listed' => 0, 'merged' => 0], self::kind($answer, 'office_park'));
        self::assertFalse($answer['cached']);
        // the three batches are cached like any other
        $this->legs->calls = [];
        self::assertTrue($this->rank([], self::truck(['licence_counties' => ['51061'], 'scout_drive_minutes_limit' => 30]))['cached']);
        self::assertCount(3, $this->legs->calls);
    }

    public function testTypesChoosesTheKindsBeforeTheScreenAndLooksDeeperIntoThem(): void
    {
        $offices = $this->addOffices(130);
        $truck = self::truck(['scout_drive_minutes_limit' => 30, 'licence_counties' => ['51061', '51107']]);

        // Every kind: the 130 offices, and the taproom, the bar and the market of the other county.
        $all = $this->rank([], $truck);
        self::assertSame(133, $all['screened']);
        self::assertSame(8, $all['quota']);
        self::assertSame(array_slice($offices, 0, 8), self::keys($all, 'office_park'));

        // Offices only: nothing else is screened, and the kind is listed thirty deep.
        $this->legs->calls = [];
        $deep = $this->rank(['types' => 'office_park'], $truck);
        self::assertSame(130, $deep['screened'], 'the other kinds were left out before the screen');
        self::assertSame([['kind' => 'office_park', 'screened' => 130, 'listed' => 30, 'merged' => 0]], $deep['kinds']);
        self::assertSame(30, $deep['quota']);
        self::assertSame(array_slice($offices, 0, 30), self::keys($deep));
        self::assertSame(range(1, 30), array_map(static fn (array $c): int => $c['result']['position'], $deep['candidates']));
        self::assertCount(68, $this->legs->calls[0]['pairs'], 'thirty and four more, there and back');
        self::assertFalse($deep['cached'], 'another choice of kinds is another list');

        // Two kinds, in the order of the list whatever the order of the request; blanks and repeats do no harm.
        $two = $this->rank(['types' => ' bar , taproom,bar'], $truck);
        self::assertSame(['taproom', 'bar'], array_column($two['kinds'], 'kind'));
        self::assertSame(2, $two['screened']);
        self::assertSame(30, $two['quota']);
        self::assertSame([self::TAPROOM, self::BAR], self::keys($two));
        // a kind the reach holds nothing of is counted with zeros
        $none = $this->rank(['types' => 'hospital,stadium'], $truck);
        self::assertSame(0, $none['screened']);
        self::assertSame([], $none['candidates']);
        self::assertSame(
            [['kind' => 'hospital', 'screened' => 0, 'listed' => 0, 'merged' => 0], ['kind' => 'stadium', 'screened' => 0, 'listed' => 0, 'merged' => 0]],
            $none['kinds']
        );
        self::assertFalse($none['cached']);
    }

    public function testNoAnswerHoldsMoreThanTheOverallCap(): void
    {
        $offices = $this->addOffices(40);
        $sites = $this->addOffices(40, 5000.0, 20.0, '51061', 'i', 'industrial_site');
        $truck = self::truck(['licence_counties' => ['51061']]);
        $config = TpConfig::all();

        // Two kinds asked for in depth share a cap of 20: ten of each, not thirty.
        $config['scout']['max_listed'] = 20;
        TpConfig::replace($config);
        $answer = $this->rank(['types' => 'office_park,industrial_site'], $truck);
        self::assertSame(10, $answer['quota']);
        self::assertSame(array_slice($offices, 0, 10), self::keys($answer, 'office_park'));
        self::assertSame(array_slice($sites, 0, 10), self::keys($answer, 'industrial_site'));

        // A cap that does not divide: the quota is the share rounded up, and the last round is cut, the
        // first kind of the list keeping its place.
        $config['scout']['max_listed'] = 7;
        TpConfig::replace($config);
        $answer = $this->rank(['types' => 'office_park,industrial_site'], $truck);
        self::assertSame(4, $answer['quota']);
        self::assertCount(7, $answer['candidates']);
        self::assertSame(array_slice($offices, 0, 4), self::keys($answer, 'office_park'));
        self::assertSame(array_slice($sites, 0, 3), self::keys($answer, 'industrial_site'));
        self::assertSame(3, self::kind($answer, 'industrial_site')['listed']);

        // Every kind under a cap of 3: one place each, and the kinds that come first in the list keep theirs.
        $config['scout']['max_listed'] = 3;
        TpConfig::replace($config);
        $answer = $this->rank();
        self::assertSame(1, $answer['quota']);
        self::assertSame(['taproom', 'farmers_market', 'office_park'], array_column($answer['candidates'], 'kind'));

        // The quota of a kind is a setting, and never more than the model ranks at a time.
        $config['scout']['max_listed'] = 150;
        $config['scout']['kind_quota'] = 3;
        $config['scout']['kind_quota_deep'] = 500;
        TpConfig::replace($config);
        self::assertSame(3, $this->rank([], $truck)['quota']);
        $deep = $this->rank(['types' => 'office_park'], $truck);
        self::assertSame(50, $deep['quota'], 'the seed scout.max_results');
        self::assertSame($offices, self::keys($deep), 'all forty of them');
    }

    public function testASiteThatIsMappedAsSeveralBuildingsIsListedOnce(): void
    {
        // Four buildings of one employer in a row, 90 to 180 m apart, the first with the most people around it;
        // another employer among them, and a namesake of the first 3 km away.
        $names = ['h001' => 'Freddie Mac - HQ 1', 'h002' => 'Vencore', 'h003' => 'Freddie Mac - HQ 3', 'h004' => 'Freddie Mac - HQ 4',
            'h005' => 'Freddie Mac - Westbranch', 'h006' => 'Vencore', 'h007' => 'Freddie Mac - HQ 2'];
        $n = 0;
        foreach ($names as $key => $name) {
            $this->addNorth($key, $name, $key === 'h007' ? 3600.0 : 600.0 + 90.0 * $n, 400.0 - $n);
            $n++;
        }
        $truck = self::truck(['licence_counties' => ['51061']]);
        $answer = $this->rank([], $truck);

        // One entry for the employer's four buildings, one for the other employer's two, one for the namesake.
        self::assertSame(['h001', 'h002', 'h007'], self::keys($answer));
        self::assertSame(3, self::candidate($answer, 'h001')['merged'], 'HQ 3, HQ 4 and Westbranch stand for the same site');
        self::assertSame(1, self::candidate($answer, 'h002')['merged']);
        self::assertSame(0, self::candidate($answer, 'h007')['merged'], 'the same name three kilometres away is another site');
        self::assertSame(['kind' => 'office_park', 'screened' => 7, 'listed' => 3, 'merged' => 4], self::kind($answer, 'office_park'));
        self::assertSame(range(1, 3), array_map(static fn (array $c): int => $c['result']['position'], $answer['candidates']));
        // The place that is kept is the best of its site, with its own numbers.
        self::assertSame($this->modelRanking(['h001', 'h002', 'h007'], $truck), array_column($answer['candidates'], 'result'));
        // The model was asked about the three that are kept, not about the four that were merged.
        self::assertCount(1, $this->legs->calls);
        self::assertSame(4, $this->legs->calls[0]['points']);
        // Kinds are kept apart: a market of the same name beside the offices is another kind of place.
        $this->addNorth('h008', 'Freddie Mac - HQ 5', 650.0, 500.0, '51061', 'farmers_market');
        $with = $this->rank(['refresh' => '1'], $truck);
        self::assertSame(['h008', 'h001', 'h002', 'h007'], self::keys($with));
        self::assertSame(0, self::candidate($with, 'h008')['merged']);
        self::assertSame(3, self::candidate($with, 'h001')['merged']);

        // How far apart two places of one site may stand is a setting.
        $config = TpConfig::all();
        $config['scout']['same_site_m'] = 50.0;
        TpConfig::replace($config);
        self::assertSame(['h001', 'h002', 'h003', 'h004', 'h005', 'h006', 'h007'], self::keys($this->rank([], $truck), 'office_park'));
    }

    public function testAPlaceTheOwnerHasALeadForIsNeverMergedAway(): void
    {
        foreach (['h001' => 'Rotunda Building I', 'h002' => 'Rotunda Building II', 'h003' => 'Rotunda Building III', 'h004' => 'Rotunda Building IV'] as $key => $name) {
            $this->addNorth($key, $name, 600.0 + 50.0 * (int) substr($key, -1), 400.0 - (int) substr($key, -1));
        }
        $truck = self::truck(['licence_counties' => ['51061']]);
        self::assertSame(['h001'], self::keys($this->rank([], $truck)));
        self::assertSame(3, self::candidate($this->rank([], $truck), 'h001')['merged']);

        // The owner has spoken to the third building: it is listed in its own right, with its lead.
        $this->service->saveLead(self::ORG, $truck, 'h003', ['status' => 'contacted', 'notes' => 'The manager of III']);
        $answer = $this->rank([], $truck);
        self::assertSame(['h001', 'h003'], self::keys($answer));
        self::assertSame(2, self::candidate($answer, 'h001')['merged'], 'the two untouched buildings');
        self::assertSame(0, self::candidate($answer, 'h003')['merged']);
        self::assertSame('contacted', self::candidate($answer, 'h003')['lead']['status']);
        self::assertSame(['kind' => 'office_park', 'screened' => 4, 'listed' => 2, 'merged' => 2], self::kind($answer, 'office_park'));

        // Only the owner's own places: every one of them is listed, whatever site it belongs to.
        $this->service->saveLead(self::ORG, $truck, 'h002', ['status' => 'shortlisted']);
        $own = $this->rank(['hide' => 'new'], $truck);
        self::assertSame(['h002', 'h003'], self::keys($own));
        self::assertSame(50, $own['quota'], 'and no quota of eight keeps one of them back');
        self::assertSame(['kind' => 'office_park', 'screened' => 2, 'listed' => 2, 'merged' => 0], self::kind($own, 'office_park'));
        // A hidden place leaves the list like any other; the building beside it takes its place.
        $this->service->saveLead(self::ORG, $truck, 'h001', ['status' => 'hidden']);
        self::assertSame(['h002', 'h003', 'h004'], self::keys($this->rank([], $truck)));
    }

    public function testEveryOneOfTheOwnersPlacesIsListedWhenOnlyThoseAreAskedFor(): void
    {
        $offices = $this->addOffices(20);
        $truck = self::truck(['licence_counties' => ['51061']]);
        foreach (array_slice($offices, 0, 15) as $key) {
            $this->service->saveLead(self::ORG, $truck, $key, ['status' => 'shortlisted']);
        }
        $own = $this->rank(['hide' => 'new'], $truck);
        self::assertSame(array_slice($offices, 0, 15), self::keys($own), 'fifteen offices, not the eight of the balanced list');
        self::assertSame(15, $own['screened']);
        // The balanced list holds eight offices as before, the owner's among them where they rank.
        self::assertCount(8, $this->rank([], $truck)['candidates']);
    }

    // ------------------------------------------------------------------------------------ places that fill the truck

    public function testPlacesThatFillTheTruckAreOrderedByDemandThenByTheModel(): void
    {
        // Five offices with so many workers around them that every hour of the best window fills the truck:
        // the farther, the more workers. One office is quiet.
        $full = [];
        for ($i = 1; $i <= 5; $i++) {
            $full[] = $key = 'c00' . $i;
            $this->addNorth($key, sprintf('Summit %d Square', $i), 500.0 + 400.0 * $i, 2500.0 + 500.0 * $i, '51063');
        }
        $this->addNorth('c900', 'Quiet Court', 400.0, 200.0, '51063');
        $truck = self::truck(['licence_counties' => ['51063']]);

        // The model on its own: the five are tied on orders, so the nearest is first, and the quiet one last.
        $model = $this->modelRanking(array_merge($full, ['c900']), $truck);
        self::assertSame(['c001', 'c002', 'c003', 'c004', 'c005', 'c900'], array_column($model, 'place_id'));
        foreach (array_slice($model, 0, 5) as $result) {
            self::assertSame(135.0, $result['orders']['value'], 'three hours at the truck\'s capacity of 45');
        }
        self::assertLessThan(135.0, $model[5]['orders']['value']);

        // The list: the same five positions, taken in the order of demand; the quiet one where it was.
        $answer = $this->rank([], $truck);
        self::assertSame(['c005', 'c004', 'c003', 'c002', 'c001', 'c900'], self::keys($answer));
        self::assertSame(range(1, 6), array_map(static fn (array $c): int => $c['result']['position'], $answer['candidates']));
        $demand = [];
        foreach ($answer['candidates'] as $n => $candidate) {
            $key = $candidate['place']['place_key'];
            // the numbers of each place are the model's own; only the position is the list's
            $own = $this->modelResult($key, $truck);
            self::assertSame(['position' => $n + 1] + $own, ['position' => $n + 1] + $candidate['result'], $key);
            if ($key === 'c900') {
                self::assertFalse($candidate['at_capacity']);
                self::assertNull($candidate['demand_key']);
                continue;
            }
            self::assertTrue($candidate['at_capacity']);
            self::assertGreaterThan(135.0, $candidate['demand_key']);
            $demand[] = $candidate['demand_key'];
        }
        $sorted = $demand;
        rsort($sorted);
        self::assertSame($sorted, $demand, 'largest demand first');
        self::assertCount(5, array_unique($demand));

        // Equal demand: the model's order decides, which is the nearer place.
        $this->addNorth('c006', 'Twin A Square', 3000.0, 9000.0, '51063');
        $this->addNorth('c007', 'Twin B Square', 2900.0, 9000.0, '51063');
        self::assertSame(['c007', 'c006', 'c005'], array_slice(self::keys($this->rank(['refresh' => '1'], $truck)), 0, 3));
    }

    public function testAPlaceAtCapacityNeverPassesAPlaceTheModelRanksBetween(): void
    {
        // Two offices fill the truck, one of them 20 km away. A third, close by, stays just under capacity:
        // the model puts it between them, because the long drive costs more than its few orders less.
        $truck = self::truck(['licence_counties' => ['51063']]);
        $this->addNorth('d001', 'Near Full Court', 600.0, 3000.0, '51063');
        $this->addNorth('d003', 'Far Full Court', 20000.0, 9000.0, '51063');
        // How many workers leave the best three hours half an order short of 135: found by halving.
        $few = 300.0;
        $many = 3000.0;
        for ($i = 0; $i < 40; $i++) {
            $workers = ($few + $many) / 2.0;
            $this->addNorth('d002', 'Almost Full Court', 700.0, $workers, '51063');
            if ($this->modelResult('d002', $truck)['orders']['value'] < 134.5) {
                $few = $workers;
            } else {
                $many = $workers;
            }
        }
        $this->addNorth('d002', 'Almost Full Court', 700.0, $few, '51063');
        $model = $this->modelRanking(['d001', 'd002', 'd003'], $truck);
        self::assertSame(['d001', 'd002', 'd003'], array_column($model, 'place_id'));
        self::assertSame(135.0, $model[0]['orders']['value']);
        self::assertGreaterThan(134.0, $model[1]['orders']['value']);
        self::assertLessThan(135.0, $model[1]['orders']['value']);
        self::assertSame(135.0, $model[2]['orders']['value']);

        // The two at capacity are not neighbours in the model's order, so there is no tie to break: the far
        // one has the larger demand and still stays third. Moving it up would put it ahead of a place the
        // model ranks above it, and the full truck nearby behind a place the model ranks below it.
        $answer = $this->rank([], $truck);
        self::assertSame(['d001', 'd002', 'd003'], self::keys($answer));
        self::assertSame([true, false, true], array_column($answer['candidates'], 'at_capacity'));
        self::assertGreaterThan($answer['candidates'][0]['demand_key'], $answer['candidates'][2]['demand_key']);
        self::assertSame(array_column($model, 'score'), array_map(static fn (array $c): float => $c['result']['score'], $answer['candidates']));

        // Once the owner hides the place between them the two are neighbours, and the larger demand is first.
        $this->service->saveLead(self::ORG, $truck, 'd002', ['status' => 'hidden']);
        self::assertSame(['d003', 'd001'], self::keys($this->rank([], $truck)));
    }

    // ------------------------------------------------------------------------------------ licence counties and the drive limit

    public function testLicenceCountiesFilterThePlaces(): void
    {
        $answer = $this->rank([], self::truck(['licence_counties' => ['51059']]));
        self::assertSame([self::OFFICE], self::keys($answer));
        self::assertSame(1, $answer['screened']);
        self::assertSame(['51059'], $answer['licence_counties']);
        self::assertSame(['51059'], $this->places->pages[0]['counties'], 'the county list is part of the query');

        $loudoun = $this->rank([], self::truck(['licence_counties' => ['51107', '51107']]));
        self::assertSame([self::TAPROOM, self::MARKET, self::BAR], self::keys($loudoun));
        self::assertSame(['51107'], $loudoun['licence_counties']);

        self::assertSame([], self::keys($this->rank([], self::truck(['licence_counties' => ['24031']]))));
        // no county chosen: every county within reach
        self::assertCount(4, $this->rank()['candidates']);
        self::assertSame([], end($this->places->pages)['counties']);
    }

    public function testThePlacesBeyondTheDriveLimitAreNotListed(): void
    {
        // Round trips on straight-line legs, as the model's timeline gives them.
        $minutes = [];
        foreach ($this->rank()['candidates'] as $candidate) {
            $minutes[$candidate['place']['place_key']] = $candidate['result']['round_trip']['minutes'];
        }
        self::assertLessThan($minutes[self::OFFICE], $minutes[self::TAPROOM]);
        self::assertGreaterThan(8, $minutes[self::OFFICE]);
        // The market has no window, so the model times no trip for it: its straight-line estimate at
        // typical traffic stands in (16.7 free-flow minutes each way, times 1.249, times the truck's 1.1).
        self::assertSame(0, $minutes[self::MARKET]);
        $minutes[self::MARKET] = 45.9;

        foreach ([5, 10, 15, 20, 30, 60] as $limit) {
            $truck = self::truck(['scout_drive_minutes_limit' => $limit]);
            $answer = $this->rank([], $truck);
            self::assertSame($limit, $answer['limit_minutes']);
            $listed = self::keys($answer);
            foreach ($minutes as $key => $roundTrip) {
                self::assertSame($roundTrip <= 2 * $limit, in_array($key, $listed, true), $key . ' at a limit of ' . $limit . ' minutes');
            }
            foreach ($answer['candidates'] as $candidate) {
                self::assertLessThanOrEqual(2 * $limit, $candidate['result']['round_trip']['minutes']);
                self::assertSame($this->modelResult($candidate['place']['place_key'], $truck), $candidate['result']);
            }
        }
    }

    public function testTheLimitIsDecidedOnTheLegsOfTheLegProvider(): void
    {
        $truck = self::truck(['scout_drive_minutes_limit' => 10]);
        $before = self::keys($this->rank([], $truck));
        self::assertContains(self::TAPROOM, $before);
        self::assertNotContains(self::OFFICE, $before, 'estimated just over the limit');

        // A routed leg that is slower than the straight-line estimate takes the taproom out ...
        $this->legs->route('base', self::TAPROOM, 900.0, 4000.0);
        $this->legs->route(self::TAPROOM, 'base', 900.0, 4000.0);
        // ... and one that is quicker brings the office park, estimated a little over the limit, in.
        $this->legs->route('base', self::OFFICE, 420.0, 7000.0);
        $this->legs->route(self::OFFICE, 'base', 420.0, 7000.0);
        $answer = $this->rank([], $truck);
        $after = self::keys($answer);
        self::assertNotContains(self::TAPROOM, $after);
        self::assertContains(self::OFFICE, $after);
        $office = self::candidate($answer, self::OFFICE);
        self::assertSame(['out' => 'google_routes', 'back' => 'google_routes'], $office['leg_sources']);
        self::assertLessThanOrEqual(20, $office['result']['round_trip']['minutes']);
        self::assertSame([self::OFFICE], $after, 'the bar and the market are far over the limit and are not asked about');
        self::assertSame($this->modelRanking([self::OFFICE], $truck), array_column($answer['candidates'], 'result'));
        self::assertSame(['kind' => 'taproom', 'screened' => 1, 'listed' => 0, 'merged' => 0], self::kind($answer, 'taproom'));

        // Tolls are not asked for, and both directions of every place of a pool are.
        $call = end($this->legs->calls);
        self::assertSame(['tolls' => false], $call['options']);
        self::assertSame(self::ORG, $call['org']);
        self::assertContains(['base', self::OFFICE], $call['pairs']);
        self::assertContains([self::OFFICE, 'base'], $call['pairs']);
    }

    public function testAPlaceFarOverTheLimitIsNeverAskedAbout(): void
    {
        // The bar and the market are within reach of the box and far over a limit of five minutes.
        $this->rank([], self::truck(['scout_drive_minutes_limit' => 5]));
        $asked = [];
        foreach ($this->legs->calls as $call) {
            foreach ($call['pairs'] as [$from, $to]) {
                $asked[$from] = true;
                $asked[$to] = true;
            }
        }
        self::assertArrayHasKey(self::TAPROOM, $asked);
        self::assertArrayNotHasKey(self::BAR, $asked);
    }

    public function testALegCallNeverAsksForMoreElementsThanOneCallMayFetch(): void
    {
        $this->addOffices(40);
        $config = TpConfig::all();
        $config['routing']['max_elements_per_call'] = 10;
        TpConfig::replace($config);
        $this->rank([], self::truck(['licence_counties' => ['51061'], 'scout_drive_minutes_limit' => 30]));
        // twelve places of the one kind, five to a call
        self::assertSame([10, 10, 4], array_map(static fn (array $call): int => count($call['pairs']), $this->legs->calls));
    }

    public function testTooManyPlacesAreCutToTheNearest(): void
    {
        $offices = $this->addOffices(30, 300.0, 100.0, '51107');
        $config = TpConfig::all();
        $config['scout']['max_screen'] = 10;
        TpConfig::replace($config);
        $answer = $this->rank([], self::truck(['licence_counties' => ['51107']]));
        self::assertTrue($answer['truncated']);
        self::assertSame(10, $answer['screened']);
        // the taproom is 890 m away: it and the nine nearest offices are screened (g001 at 400 m ... g009 at
        // 1,200 m), and eight of the nine make the list of their kind
        self::assertSame(array_merge([self::TAPROOM], array_slice($offices, 0, 8)), self::keys($answer));
        self::assertSame(['kind' => 'office_park', 'screened' => 9, 'listed' => 8, 'merged' => 0], self::kind($answer, 'office_park'));
        // without the cut every place of the county is screened
        TpConfig::replace(null);
        $all = $this->rank([], self::truck(['licence_counties' => ['51107']]));
        self::assertFalse($all['truncated']);
        self::assertSame(33, $all['screened']);
    }

    public function testTheSameInputsGiveTheSameList(): void
    {
        $this->addOffices(60);
        $this->addOffices(30, 4000.0, 30.0, '51061', 'i', 'industrial_site');
        foreach (['h001' => 'Rotunda Building I', 'h002' => 'Rotunda Building II'] as $key => $name) {
            $this->addNorth($key, $name, 700.0, 5000.0);
        }
        $first = $this->rank();
        $warm = $this->rank();
        $again = $this->rank(['refresh' => '1']);
        self::assertTrue($warm['cached']);
        self::assertFalse($again['cached']);
        foreach ([$warm, $again] as $other) {
            self::assertSame(json_encode(array_diff_key($first, ['cached' => 1])), json_encode(array_diff_key($other, ['cached' => 1])));
        }
        // a second service with caches of its own, the places read in another order of insertion
        $this->cache->values = [];
        $rows = $this->places->rows;
        $this->places->rows = array_reverse($rows, true);
        $fresh = $this->rank();
        self::assertFalse($fresh['cached']);
        self::assertSame(json_encode(array_diff_key($first, ['cached' => 1])), json_encode(array_diff_key($fresh, ['cached' => 1])));
    }

    // ------------------------------------------------------------------------------------ hidden leads

    public function testHiddenLeadsLeaveTheList(): void
    {
        $truck = self::truck();
        $this->service->saveLead(self::ORG, $truck, self::OFFICE, ['status' => 'hidden']);
        $this->service->saveLead(self::ORG, $truck, self::BAR, ['status' => 'declined']);
        $this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['status' => 'contacted']);

        // by default the hidden ones go
        $answer = $this->rank();
        self::assertSame([self::TAPROOM, self::MARKET, self::BAR], self::keys($answer));
        self::assertSame(3, $answer['screened']);
        self::assertSame(['kind' => 'office_park', 'screened' => 0, 'listed' => 0, 'merged' => 0], self::kind($answer, 'office_park'));

        // an empty value hides nothing
        self::assertCount(4, $this->rank(['hide' => ''])['candidates']);
        // several statuses
        self::assertSame([self::TAPROOM, self::MARKET], self::keys($this->rank(['hide' => 'declined,hidden'])));
        self::assertSame([self::TAPROOM, self::MARKET], self::keys($this->rank(['hide' => ' hidden , declined '])));
        self::assertSame([self::MARKET, self::OFFICE, self::BAR], self::keys($this->rank(['hide' => 'contacted'])));
        // a place the owner has not touched is new: hiding `new` leaves the touched places that are shown
        self::assertSame([self::TAPROOM, self::OFFICE, self::BAR], self::keys($this->rank(['hide' => 'new'])));
        self::assertSame([self::TAPROOM], self::keys($this->rank(['hide' => 'new,declined,hidden'])));
        self::assertSame([], self::keys($this->rank(['hide' => 'new,shortlisted,contacted,booked,declined,hidden'])));

        // the lead of a listed place comes with it
        $taproom = self::candidate($this->rank(), self::TAPROOM)['lead'];
        self::assertSame('contacted', $taproom['status']);
        self::assertSame(36, strlen((string) $taproom['id']));
    }

    public function testAHiddenPlaceMakesRoomForTheNextOfItsKind(): void
    {
        $offices = $this->addOffices(12);
        $truck = self::truck(['licence_counties' => ['51061']]);
        self::assertSame(array_slice($offices, 0, 8), self::keys($this->rank([], $truck)));
        $this->service->saveLead(self::ORG, $truck, 'g001', ['status' => 'hidden']);
        $answer = $this->rank([], $truck);
        self::assertSame(array_slice($offices, 1, 8), self::keys($answer), 'the others move up, and the ninth comes in');
        self::assertSame(range(1, 8), array_map(static fn (array $c): int => $c['result']['position'], $answer['candidates']));
        self::assertSame(11, $answer['screened']);
    }

    public function testTheQueryIsValidated(): void
    {
        $statuses = 'new, shortlisted, contacted, booked, declined, hidden';
        foreach (['gone', 'hidden,gone', 'hidden,', ',', 'Hidden'] as $hide) {
            self::assertInvalid('hide must be one of: ' . $statuses, 'hide', 'V4', fn () => $this->rank(['hide' => $hide]));
        }
        self::assertInvalid('hide must be one of: ' . $statuses, 'hide', 'V4', fn () => $this->rank(['hide' => ['hidden']]));
        self::assertInvalid('refresh must be true or false', 'refresh', 'V6', fn () => $this->rank(['refresh' => 'yes']));
        // `types` takes the kinds of the list and nothing else: not a type that hosts nothing, not an empty value
        $kinds = implode(', ', self::KINDS);
        foreach (['restaurant', 'taproom,restaurant', 'taproom,', '', ',', 'Taproom', 'offices', 'taproom;bar'] as $types) {
            self::assertInvalid('types must be one of: ' . $kinds, 'types', 'V4', fn () => $this->rank(['types' => $types]));
        }
        self::assertInvalid('types must be one of: ' . $kinds, 'types', 'V4', fn () => $this->rank(['types' => ['taproom']]));
        self::assertSame([], $this->places->pages, 'a query that is refused reads nothing');
        self::assertCount(4, $this->rank(['refresh' => '0', 'unknown' => 'x'])['candidates']);
        self::assertSame([], $this->places->rows[self::TAPROOM]['touched'] ?? []);
    }

    public function testAnotherOrganizationsLeadsAreItsOwn(): void
    {
        $this->service->saveLead(self::OTHER_ORG, ['id' => 'other-truck', 'organization_id' => self::OTHER_ORG] + self::truck(), self::OFFICE, ['status' => 'hidden', 'notes' => 'theirs']);
        $answer = $this->rank();
        self::assertContains(self::OFFICE, self::keys($answer), 'another organization hid it, not this one');
        foreach ($answer['candidates'] as $candidate) {
            self::assertNull($candidate['lead']['id']);
            self::assertNull($candidate['lead']['notes']);
        }
        // and the caches are kept per organization
        foreach (array_keys($this->cache->values) as $key) {
            self::assertMatchesRegularExpression('/^tp:scout:[sr]:' . self::ORG . ':[0-9a-f]{40}$/', $key);
        }
    }

    // ------------------------------------------------------------------------------------ caches

    public function testBothStagesAreCachedForADayAndTheLeadsAreNot(): void
    {
        // The owner has touched the taproom before.
        $this->service->saveLead(self::ORG, self::truck(), self::TAPROOM, ['status' => 'shortlisted']);
        $first = $this->rank();
        self::assertFalse($first['cached']);
        self::assertCount(1, $this->places->pages);
        $keys = array_keys($this->cache->values);
        self::assertCount(2, $keys);
        self::assertMatchesRegularExpression('/^tp:scout:s:' . self::ORG . ':[0-9a-f]{40}$/', $keys[0]);
        self::assertMatchesRegularExpression('/^tp:scout:r:' . self::ORG . ':[0-9a-f]{40}$/', $keys[1]);
        self::assertSame([86400 + 93600, 86400 + 93600], array_values($this->cache->ttls));

        // warm: no place is read again, and the answer is the same
        $reads = count($this->places->keyReads);
        $second = $this->rank();
        self::assertTrue($second['cached']);
        self::assertCount(1, $this->places->pages);
        self::assertSame(array_diff_key($first, ['cached' => 1]), array_diff_key($second, ['cached' => 1]));
        self::assertCount(2, $this->cache->values);
        self::assertCount($reads + 1, $this->places->keyReads, 'the display columns of the listed places, and no names for the screen');

        // a lead is the owner's and is read fresh on a warm call: a new status or note recomputes nothing
        $this->service->saveLead(self::ORG, self::truck(), self::TAPROOM, ['status' => 'booked', 'notes' => 'Friday']);
        $third = $this->rank();
        self::assertTrue($third['cached']);
        self::assertSame('booked', self::candidate($third, self::TAPROOM)['lead']['status']);
        self::assertSame('Friday', self::candidate($third, self::TAPROOM)['lead']['notes']);

        // refresh computes both stages again
        $fourth = $this->rank(['refresh' => '1']);
        self::assertFalse($fourth['cached']);
        self::assertCount(2, $this->places->pages);
        self::assertSame(array_column($first['candidates'], 'result'), array_column($fourth['candidates'], 'result'));

        // after a day both are computed again
        $this->clock->advance(86400);
        self::assertFalse($this->rank()['cached']);
        self::assertCount(3, $this->places->pages);
    }

    public function testAChangedLegRecomputesTheModelStageOnly(): void
    {
        $this->rank();
        self::assertCount(2, $this->cache->values);
        // The owner corrected a drive time: the leg input differs, the pools do not.
        $this->legs->override('base', self::TAPROOM, 9);
        $answer = $this->rank();
        self::assertFalse($answer['cached']);
        self::assertCount(1, $this->places->pages, 'the screen was not run again');
        self::assertCount(3, $this->cache->values);
        self::assertGreaterThanOrEqual(9, self::candidate($answer, self::TAPROOM)['result']['round_trip']['minutes']);
        self::assertTrue($this->rank()['cached']);
    }

    public function testWhatTheScreenReadsIsPartOfItsKey(): void
    {
        $this->rank();
        $changes = [
            fn () => $this->rank([], self::truck(['avg_ticket' => 12.0])),
            fn () => $this->rank([], self::truck(['scout_drive_minutes_limit' => 44])),
            fn () => $this->rank([], self::truck(['licence_counties' => ['51107']])),
            fn () => $this->rank([], self::truck(['fuel_price_override' => 5.0])),
            fn () => $this->rank([], self::truck(['base' => ['lat' => 39.004, 'lng' => -77.405, 'address' => '']])),
            fn () => $this->rank(['types' => 'taproom,bar']),
            function () {
                $config = TpConfig::all();
                $config['scout']['kind_quota'] = 5;
                TpConfig::replace($config);
                return $this->rank();
            },
            function () {
                $this->calibration->factor = 0.5;
                return $this->rank();
            },
            function () {
                $this->region->switchTo('mini-20270101-bbbb2222', 1.0);
                return $this->rank();
            },
            fn () => $this->service->rank(self::ORG, self::truck(), Seeds::assumptions(['host.captive_share' => 0.5], self::A()['region']), []),
        ];
        $pages = 1;
        foreach ($changes as $i => $change) {
            $answer = $change();
            self::assertFalse($answer['cached'], 'change ' . $i);
            self::assertCount(++$pages, $this->places->pages, 'change ' . $i . ' runs the screen again');
        }
        $overridden = fn () => $this->service->rank(self::ORG, self::truck(), Seeds::assumptions(['host.captive_share' => 0.5], self::A()['region']), []);
        // A place the owner touches for the first time is kept apart from its site from then on, so the
        // screen runs once more ...
        $this->service->saveLead(self::ORG, self::truck(), self::BAR, ['status' => 'shortlisted']);
        self::assertFalse($overridden()['cached']);
        self::assertCount(++$pages, $this->places->pages);
        // ... and a later status that hides nothing changes nothing that is cached
        $this->service->saveLead(self::ORG, self::truck(), self::BAR, ['status' => 'contacted', 'notes' => 'rang twice']);
        self::assertTrue($overridden()['cached']);
        self::assertCount($pages, $this->places->pages);
        // a hidden one does
        $this->service->saveLead(self::ORG, self::truck(), self::BAR, ['status' => 'hidden']);
        self::assertFalse($overridden()['cached']);
        self::assertCount($pages + 1, $this->places->pages);
    }

    public function testTheTrucksOwnResultsReachTheListThroughTheCalibrationContract(): void
    {
        $plain = $this->rank();
        // today in the truck's zone, not in UTC
        self::assertSame(['2026-10-07'], $this->calibration->asOf);

        $this->calibration->factor = 0.5;
        $halved = $this->rank();
        $before = self::candidate($plain, self::TAPROOM)['result'];
        $after = self::candidate($halved, self::TAPROOM)['result'];
        self::assertEqualsWithDelta($before['orders']['value'] * 0.5, $after['orders']['value'], 1e-9);
        $cal = ['truck_factor' => 0.5] + Estimator::calibrate(self::A(), [], '2026-10-07');
        foreach (self::keys($halved) as $key) {
            self::assertSame($this->modelResult($key, self::truck(), $cal), self::candidate($halved, $key)['result'], $key);
        }
        self::assertSame(self::keys($plain), self::keys($halved));
    }

    // ------------------------------------------------------------------------------------ regions

    public function testScoutingNeedsALoadedRegionThatFitsTheServer(): void
    {
        self::assertRefused(TpConflict::class, 'Scouting needs a loaded region', fn () => $this->rank([], self::truck([], 'none')));
        self::assertRefused(TpConflict::class, 'Scouting needs a loaded region', fn () => $this->rank([], self::truck([], 'atlantis')));
        $this->region->unload();
        self::assertRefused(TpConflict::class, 'Scouting needs a loaded region', fn () => $this->rank());
        $this->region->loaded = true;
        $this->region->usable = false;
        self::assertRefused(TpConflict::class, 'Region data was built with different model constants', fn () => $this->rank());
        self::assertSame([], $this->places->pages, 'nothing was read');
        // the query is looked at first
        self::assertInvalid('refresh must be true or false', 'refresh', 'V6', fn () => $this->rank(['refresh' => 'x']));
        self::assertInvalid('types must be one of: ' . implode(', ', self::KINDS), 'types', 'V4', fn () => $this->rank(['types' => 'x']));
    }

    // ------------------------------------------------------------------------------------ leads

    public function testALeadIsCreatedOnFirstTouchAndChangedKeyByKey(): void
    {
        $truck = self::truck();
        $saved = $this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['status' => 'contacted', 'notes' => '  Spoke to the taproom manager  ']);
        self::assertSame(['lead'], array_keys($saved));
        $lead = $saved['lead'];
        self::assertSame(['id', 'place_key', 'status', 'notes', 'spot_id', 'google'], array_keys($lead));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $lead['id']);
        self::assertSame(
            ['place_key' => self::TAPROOM, 'status' => 'contacted', 'notes' => 'Spoke to the taproom manager', 'spot_id' => null, 'google' => null],
            array_diff_key($lead, ['id' => 1])
        );

        // the row: the owner's organization and truck, the region, and a snapshot of the place
        $row = $this->leadTable->rows[$lead['id']];
        self::assertSame(self::ORG, $row['organization_id']);
        self::assertSame(self::TRUCK, $row['truck_id']);
        self::assertSame(FixtureRegion::REGION, $row['region_id']);
        self::assertSame('Example Brewing', $row['place_name']);
        self::assertSame('taproom', $row['place_type']);
        self::assertSame('39.01', $row['lat']);
        self::assertSame('-77.41', $row['lng']);

        // one key at a time
        $noted = $this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['notes' => 'Call back Tuesday'])['lead'];
        self::assertSame($lead['id'], $noted['id']);
        self::assertSame('contacted', $noted['status']);
        self::assertSame('Call back Tuesday', $noted['notes']);
        $booked = $this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['status' => 'booked', 'colour' => 'red'])['lead'];
        self::assertSame('booked', $booked['status']);
        self::assertSame('Call back Tuesday', $booked['notes']);
        // null and an empty text clear the note
        self::assertNull($this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['notes' => null])['lead']['notes']);
        $this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['notes' => 'again']);
        self::assertNull($this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['notes' => '   '])['lead']['notes']);
        self::assertCount(1, $this->leadTable->rows);

        // a first touch with a note only starts as new
        $new = $this->service->saveLead(self::ORG, $truck, self::BAR, ['notes' => 'Has a kitchen'])['lead'];
        self::assertSame('new', $new['status']);
        self::assertCount(2, $this->leadTable->rows);
        foreach (ScoutingService::STATUSES as $status) {
            self::assertSame($status, $this->service->saveLead(self::ORG, $truck, self::BAR, ['status' => $status])['lead']['status']);
        }
    }

    public function testALeadBodyIsValidated(): void
    {
        $truck = self::truck();
        $save = fn (array $body) => fn () => $this->service->saveLead(self::ORG, $truck, self::TAPROOM, $body);
        self::assertInvalid('Nothing to update', null, 'V12', $save([]));
        self::assertInvalid('Nothing to update', null, 'V12', $save(['colour' => 'red']));
        self::assertInvalid('status must be one of: new, shortlisted, contacted, booked, declined, hidden', 'status', 'V4', $save(['status' => 'maybe']));
        self::assertInvalid('status must be one of: new, shortlisted, contacted, booked, declined, hidden', 'status', 'V4', $save(['status' => 3]));
        self::assertInvalid('status is required', 'status', 'V1', $save(['status' => null]));
        self::assertInvalid('notes must be text of at most 4000 characters', 'notes', 'V5', $save(['notes' => str_repeat('n', 4001)]));
        self::assertInvalid('notes must be text of at most 4000 characters', 'notes', 'V5', $save(['status' => 'booked', 'notes' => 12]));
        self::assertSame([], $this->leadTable->rows, 'a refused body writes nothing');
        self::assertSame(4000, mb_strlen((string) $this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['notes' => str_repeat('é', 4000)])['lead']['notes']));
    }

    public function testALeadBodyCannotSetWhatALookupLeaves(): void
    {
        // A body is read for `status` and `notes` and nothing else: no key of it reaches the columns of a lookup.
        $lead = $this->service->saveLead(self::ORG, self::truck(), self::TAPROOM, [
            'status' => 'contacted', 'google_place_id' => 'ChIJforged', 'g_lookup_state' => 'found', 'google' => ['place_id' => 'ChIJforged'],
            'phone' => '(703) 555-0199', 'website' => 'https://google-says.example.com/',
        ])['lead'];
        self::assertNull($lead['google']);
        $row = $this->leadTable->rows[$lead['id']];
        self::assertNull($row['google_place_id']);
        self::assertNull($row['g_lookup_state']);
        self::assertNull($row['g_fetched_at']);
        foreach (['ChIJforged', '555-0199', 'google-says'] as $trace) {
            self::assertStringNotContainsString($trace, (string) json_encode($row));
        }
    }

    public function testOnlyAPossibleHostOfTheTrucksRegionHasALead(): void
    {
        $truck = self::truck();
        $actions = [
            fn (string $key, array $t) => $this->service->saveLead(self::ORG, $t, $key, ['status' => 'hidden']),
            fn (string $key, array $t) => $this->service->lookupContact(self::ORG, $t, $key, false),
            fn (string $key, array $t) => $this->service->saveAsSpot(self::ORG, $t, self::USER, $key, []),
        ];
        foreach ($actions as $action) {
            // not in the dataset, a type that hosts nothing, a place outside the counties
            foreach (['n0', self::RESTAURANT, self::HALO_OFFICE] as $key) {
                self::assertRefused(TpNotFound::class, 'Place not found', fn () => $action($key, $truck));
            }
            // a truck without a loaded region has no places at all
            self::assertRefused(TpNotFound::class, 'Place not found', fn () => $action(self::TAPROOM, self::truck([], 'none')));
        }
        // a key that cannot be a key is not looked up
        $reads = count($this->places->keyReads);
        foreach (['', 'w 1', "w1'--", 'w264230766w264230766x', 'wé1', '../w1'] as $key) {
            self::assertRefused(TpNotFound::class, 'Place not found', fn () => $this->service->saveLead(self::ORG, $truck, $key, ['status' => 'hidden']));
        }
        self::assertCount($reads, $this->places->keyReads);
        self::assertSame([], $this->leadTable->rows);
        self::assertSame([], $this->spotTable->rows);
        self::assertSame([], $this->http->requests);
    }

    // ------------------------------------------------------------------------------------ contact lookup

    public function testWithoutAKeyTheLookupIsNotAvailableAndNothingIsWritten(): void
    {
        $this->guard->key = false;
        foreach ([false, true] as $force) {
            self::assertRefused(
                TpUnavailable::class,
                'Contact lookup is not available on this server',
                fn () => $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, $force)
            );
        }
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->leadTable->rows);
        self::assertSame([], $this->bucketCalls, 'no token is taken for a lookup that cannot be made');
        self::assertSame([], $this->ledgerRows->calls);
    }

    public function testWithTheSpendingAllowanceUsedUpTheLookupIsNotAvailable(): void
    {
        // Settings: 5 dollars a day. 4.98 are spent: the 2 cents left do not cover a search by name (3.5 cents).
        $this->ledgerRows->when('FROM api_cost_events', ['day_usd' => '4.980000', 'month_usd' => '4.980000']);
        foreach ([false, true] as $force) {
            self::assertRefused(
                TpUnavailable::class,
                'Contact lookup is not available on this server',
                fn () => $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, $force)
            );
        }
        self::assertSame([], $this->http->requests, 'Google is not asked');
        self::assertSame([], $this->leadTable->rows);
        self::assertSame([], $this->bucketCalls, 'no token is taken for a lookup that cannot be made');
        self::assertSame([], $this->ledgerRows->find('INSERT INTO api_cost_events'), 'and nothing is metered');
    }

    public function testTheFirstLookupSearchesByNameAndKeepsOnlyThePlaceId(): void
    {
        $this->found();
        $answer = $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false);

        // the request: the name and the point of the place, the mask with the location
        self::assertCount(1, $this->http->requests);
        $request = $this->http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://places.googleapis.com/v1/places:searchText', $request['url']);
        self::assertSame(
            [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . self::KEY,
                'X-Goog-FieldMask: places.id,places.displayName,places.formattedAddress,places.location,'
                    . 'places.nationalPhoneNumber,places.websiteUri,places.googleMapsUri',
            ],
            $request['headers']
        );
        self::assertSame(
            '{"textQuery":"Example Brewing","languageCode":"en","pageSize":1,'
            . '"locationBias":{"circle":{"center":{"latitude":39.01,"longitude":-77.41},"radius":500.0}}}',
            $request['body']
        );
        self::assertSame([['tp_places_lookup', 1, 2]], $this->bucketCalls);

        // the answer says plainly what it is: found, what was matched, when, from where, and not saved
        self::assertSame(['lead', 'contact'], array_keys($answer));
        self::assertSame(
            [
                'found' => true,
                'name' => 'Example Brewing Co (Google)',
                'address' => '99 Google Way, Sterling, VA 20166, USA',
                'phone' => '(703) 555-0199',
                'website' => 'https://google-says.example.com/',
                'maps_uri' => 'https://maps.google.com/?cid=424242',
                'fetched_at' => '2026-10-08T03:30:00Z',
                'source' => 'text_search',
                'saved' => false,
                'attribution' => 'Phone and website from Google Maps',
            ],
            $answer['contact']
        );
        // the lead: created as new, with Google's id of the place, the outcome, the time and a link
        self::assertSame('new', $answer['lead']['status'], 'a lookup creates the lead as new');
        self::assertSame(
            [
                'place_id' => self::PLACE_ID,
                'lookup_state' => 'found',
                'matched_at' => '2026-10-08 03:30:00',
                'maps_url' => 'https://www.google.com/maps/search/?api=1&query=Example%20Brewing&query_place_id=' . self::PLACE_ID,
            ],
            $answer['lead']['google']
        );

        // the row: the id, the outcome and the database's time; the place's own name and point; nothing of Google's
        $row = $this->leadTable->rows[$answer['lead']['id']];
        self::assertSame(
            ['id', 'organization_id', 'truck_id', 'region_id', 'place_key', 'place_name', 'place_type', 'lat', 'lng', 'lead_state', 'notes',
                'spot_id', 'google_place_id', 'g_lookup_state', 'g_fetched_at', 'created_at', 'updated_at'],
            array_keys($row)
        );
        self::assertSame(self::PLACE_ID, $row['google_place_id']);
        self::assertSame('found', $row['g_lookup_state']);
        self::assertSame('2026-10-08 03:30:00', $row['g_fetched_at']);
        self::assertSame('Example Brewing', $row['place_name'], 'the name of the lead is the place\'s own');
        self::assertSame('39.01', $row['lat'], 'and so is its point');
        $kept = (string) json_encode($this->leadTable->rows) . json_encode($this->cache->values) . json_encode($this->ledgerRows->calls);
        foreach (array_merge(self::GOOGLE_TRACES, ['39.0103']) as $trace) {
            self::assertStringNotContainsString($trace, $kept);
        }
        self::assertSame(['SELECT', 'SELECT', 'INSERT', 'UPDATE', 'SELECT'], $this->leadStatementsSince(0));

        // one ledger row, and nothing of Google's was written to a spot
        self::assertCount(1, $this->ledgerRows->find('INSERT INTO api_cost_events'));
        self::assertSame('tp_places_text', $this->ledgerRows->only('INSERT INTO api_cost_events')['params'][1]);
        self::assertSame([], $this->spotTable->rows);
    }

    public function testALaterLookupAsksByTheIdThatWasKeptAndWritesNothing(): void
    {
        $truck = self::truck();
        $this->found();
        $first = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        $rows = $this->leadTable->rows;
        $statements = count($this->leadTable->statements);
        $cache = $this->cache->values;

        // Two days later the place has another phone number. Nothing was kept, so Google is asked again.
        $this->clock->advance(2 * 86400);
        $this->detailed('(703) 555-0111');
        $later = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);

        self::assertCount(2, $this->http->requests);
        $request = $this->http->requests[1];
        self::assertSame('GET', $request['method']);
        self::assertSame('https://places.googleapis.com/v1/places/' . self::PLACE_ID . '?languageCode=en', $request['url']);
        self::assertSame(
            ['X-Goog-Api-Key: ' . self::KEY, 'X-Goog-FieldMask: id,displayName,formattedAddress,nationalPhoneNumber,websiteUri,googleMapsUri'],
            $request['headers']
        );
        self::assertNull($request['body']);
        self::assertSame([['tp_places_lookup', 1, 2], ['tp_places_lookup', 1, 2]], $this->bucketCalls, 'a token for every call');

        self::assertSame(
            [
                'found' => true,
                'name' => 'Example Brewing Co (Google)',
                'address' => '99 Google Way, Sterling, VA 20166, USA',
                'phone' => '(703) 555-0111',
                'website' => 'https://google-says.example.com/',
                'maps_uri' => 'https://maps.google.com/?cid=424242',
                'fetched_at' => '2026-10-10T03:30:00Z',
                'source' => 'place_details',
                'saved' => false,
                'attribution' => 'Phone and website from Google Maps',
            ],
            $later['contact']
        );
        // The lead is as the first lookup left it: the same id, and the time of the match, not of this call.
        self::assertSame($first['lead'], $later['lead']);
        self::assertSame($rows, $this->leadTable->rows);
        self::assertSame(['SELECT'], $this->leadStatementsSince($statements), 'the lead is read and nothing is written');
        self::assertSame($cache, $this->cache->values, 'no cache entry either');
        // metered under its own SKU
        $skus = array_map(static fn (array $call): string => (string) $call['params'][1], $this->ledgerRows->find('INSERT INTO api_cost_events'));
        self::assertSame(['tp_places_text', 'tp_places_details'], $skus);

        // and again, as often as the owner asks: there is no answer on the server to hand out
        $this->detailed();
        $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertCount(3, $this->http->requests);
        self::assertSame($rows, $this->leadTable->rows);
    }

    public function testAnIdGoogleNoLongerKnowsIsSearchedForByNameAgain(): void
    {
        $truck = self::truck();
        $this->found();
        $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        $this->clock->advance(86400);

        // Google gave the place another id: the request by the old one answers "not found", and the search
        // by name that follows brings the new one.
        $this->http->json(404, ['error' => ['code' => 404, 'message' => 'Place ID is no longer valid', 'status' => 'NOT_FOUND']]);
        $this->found(35.0, 'ChIJnewPlaceId_42');
        $lines = [];
        $answer = [];
        $lines = LogCapture::during(function () use (&$answer, $truck): void {
            $answer = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        });
        self::assertSame([], $lines);
        self::assertSame(['POST', 'GET', 'POST'], array_column($this->http->requests, 'method'));
        self::assertCount(3, $this->bucketCalls);
        self::assertTrue($answer['contact']['found']);
        self::assertSame('text_search', $answer['contact']['source']);
        self::assertSame('ChIJnewPlaceId_42', $answer['lead']['google']['place_id']);
        self::assertSame('2026-10-09 03:30:00', $answer['lead']['google']['matched_at']);
        self::assertSame('ChIJnewPlaceId_42', array_values($this->leadTable->rows)[0]['google_place_id']);

        // The place has gone altogether: no id is left on the lead, and the link is the pin again.
        $this->http->json(404, ['error' => ['code' => 404, 'message' => 'Place ID is no longer valid', 'status' => 'NOT_FOUND']]);
        $this->http->queue(200, '{}');
        $gone = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertFalse($gone['contact']['found']);
        self::assertSame(
            ['place_id' => null, 'lookup_state' => 'not_found', 'matched_at' => '2026-10-09 03:30:00', 'maps_url' => null],
            $gone['lead']['google']
        );
        self::assertNull(array_values($this->leadTable->rows)[0]['google_place_id']);
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=39.010000%2C-77.410000',
            self::candidate($this->rank(), self::TAPROOM)['maps_url']
        );
    }

    public function testForceSearchesByNameAgainAlthoughAnIdIsKept(): void
    {
        $truck = self::truck();
        $this->found();
        $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        // The match turned out to be another business in the same building: the owner asks for a new match.
        $this->clock->advance(3600);
        $this->found(20.0, 'ChIJtheRightOne');
        $forced = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, true);
        self::assertSame(['POST', 'POST'], array_column($this->http->requests, 'method'));
        self::assertSame('text_search', $forced['contact']['source']);
        self::assertSame('ChIJtheRightOne', $forced['lead']['google']['place_id']);
        self::assertSame('2026-10-08 04:30:00', $forced['lead']['google']['matched_at']);
        self::assertCount(1, $this->leadTable->rows);
        // a place that has no id yet is searched for by name with or without it
        $this->found();
        self::assertSame('text_search', $this->service->lookupContact(self::ORG, $truck, self::BAR, true)['contact']['source']);
    }

    public function testNoMatchKeepsNoIdAndTheNextLookupSearchesAgain(): void
    {
        $truck = self::truck();
        $this->http->queue(200, '{}');
        $answer = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertSame(
            [
                'found' => false, 'name' => null, 'address' => null, 'phone' => null, 'website' => null, 'maps_uri' => null,
                'fetched_at' => '2026-10-08T03:30:00Z', 'source' => 'text_search', 'saved' => false,
                'attribution' => 'Phone and website from Google Maps',
            ],
            $answer['contact']
        );
        self::assertSame(
            ['place_id' => null, 'lookup_state' => 'not_found', 'matched_at' => '2026-10-08 03:30:00', 'maps_url' => null],
            $answer['lead']['google']
        );
        $row = $this->leadTable->rows[$answer['lead']['id']];
        self::assertSame('not_found', $row['g_lookup_state']);
        self::assertNull($row['google_place_id']);

        // Nothing stands in the way of asking again, at once or later: each time it is a search by name.
        $this->http->queue(200, '{}');
        $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        $this->clock->advance(40 * 86400);
        $this->found();
        $found = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertTrue($found['contact']['found']);
        self::assertSame(['POST', 'POST', 'POST'], array_column($this->http->requests, 'method'));
        self::assertSame(self::PLACE_ID, $found['lead']['google']['place_id']);
        self::assertSame('2026-11-17 03:30:00', $found['lead']['google']['matched_at']);
    }

    public function testAMatchSomewhereElseIsNoConfidentMatchAndNothingOfItIsShownOrKept(): void
    {
        $this->found(450.0);
        $answer = $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false);
        self::assertFalse($answer['contact']['found']);
        self::assertSame('not_found', $answer['lead']['google']['lookup_state']);
        self::assertNull($answer['lead']['google']['place_id']);
        $out = (string) json_encode($answer) . json_encode($this->leadTable->rows);
        foreach (array_merge(self::GOOGLE_TRACES, ['ChIJ']) as $trace) {
            self::assertStringNotContainsString($trace, $out, 'the owner is not shown the details of another place');
        }
        // the map link stays the pin at the place's own point
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=39.010000%2C-77.410000',
            self::candidate($this->rank(), self::TAPROOM)['maps_url']
        );
    }

    public function testAPlaceFoundWithoutAUsableIdIsShownAndSearchedForAgainNextTime(): void
    {
        $truck = self::truck();
        $this->http->json(200, ['places' => [['id' => 'not an id!'] + self::googlePlace()]]);
        $answer = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertTrue($answer['contact']['found']);
        self::assertSame('(703) 555-0199', $answer['contact']['phone']);
        self::assertSame(
            ['place_id' => null, 'lookup_state' => 'found', 'matched_at' => '2026-10-08 03:30:00', 'maps_url' => null],
            $answer['lead']['google']
        );
        $this->found();
        $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertSame(['POST', 'POST'], array_column($this->http->requests, 'method'));
    }

    public function testAnEmptyBucketAnswersTooManyLookups(): void
    {
        $this->bucketAnswer = false;
        self::assertRefused(
            TpRateLimited::class,
            'Too many lookups right now. Try again in a minute',
            fn () => $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false)
        );
        self::assertSame([['tp_places_lookup', 1, 2]], $this->bucketCalls);
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->leadTable->rows);

        // the same for a lookup by a stored id: the id stays where it is
        $this->bucketAnswer = true;
        $this->found();
        $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false);
        $rows = $this->leadTable->rows;
        $this->bucketAnswer = false;
        self::assertRefused(
            TpRateLimited::class,
            'Too many lookups right now. Try again in a minute',
            fn () => $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false)
        );
        self::assertCount(1, $this->http->requests);
        self::assertSame($rows, $this->leadTable->rows);
    }

    public function testARefusalIsRememberedForAnHour(): void
    {
        $truck = self::truck();
        $unavailable = 'Contact lookup is not available on this server';
        $this->http->json(403, ['error' => ['code' => 403, 'message' => 'Places API (New) has not been used in project 1 before or it is disabled.', 'status' => 'PERMISSION_DENIED']]);
        $lines = LogCapture::during(function () use ($truck, $unavailable): void {
            self::assertRefused(TpUnavailable::class, $unavailable, fn () => $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false));
        });
        self::assertSame(['[tp] places lookup failed: http_403 PERMISSION_DENIED'], $lines);
        self::assertArrayHasKey('tp:places:refused:places', $this->cache->values);
        self::assertSame([], $this->leadTable->rows);

        // within the hour Google is not asked again
        $this->clock->advance(3599);
        self::assertRefused(TpUnavailable::class, $unavailable, fn () => $this->service->lookupContact(self::ORG, $truck, self::BAR, false));
        self::assertCount(1, $this->http->requests);
        // after it, it is
        $this->clock->advance(2);
        $this->found();
        self::assertTrue($this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false)['contact']['found']);
        self::assertCount(2, $this->http->requests);
    }

    public function testAnyOtherFailureBacksOffForAMinute(): void
    {
        $truck = self::truck();
        $unavailable = 'Contact lookup is not available on this server';
        $failures = [
            ['quota', fn () => $this->http->json(429, ['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']])],
            ['upstream', fn () => $this->http->queue(500, 'oops')],
            ['upstream', fn () => $this->http->fail('timeout')],
            ['upstream', fn () => $this->http->queue(200, 'not json')],
        ];
        foreach ($failures as $n => [$reason, $queue]) {
            $queue();
            $lines = LogCapture::during(function () use ($truck, $unavailable): void {
                self::assertRefused(TpUnavailable::class, $unavailable, fn () => $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false));
            });
            self::assertCount(1, $lines);
            self::assertStringStartsWith('[tp] places lookup failed: ', $lines[0]);
            self::assertCount($n + 1, $this->http->requests);
            self::assertSame($reason, $this->guard->backoffReason('places'));

            // inside the minute nothing is sent
            $this->clock->advance(59);
            self::assertRefused(TpUnavailable::class, $unavailable, fn () => $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false));
            self::assertCount($n + 1, $this->http->requests);
            $this->clock->advance(2);
        }
        self::assertSame([], $this->leadTable->rows, 'a failed lookup stores nothing');
        self::assertArrayNotHasKey('tp:places:refused:places', $this->cache->values);
    }

    public function testAFailedLookupByIdLeavesTheLeadAsItWas(): void
    {
        $truck = self::truck();
        $this->found();
        $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        $rows = $this->leadTable->rows;
        $this->clock->advance(600);
        foreach ([fn () => $this->http->queue(500, 'oops'), fn () => $this->http->queue(200, '{}'), fn () => $this->http->fail('timeout')] as $queue) {
            $queue();
            LogCapture::during(function () use ($truck): void {
                self::assertRefused(
                    TpUnavailable::class,
                    'Contact lookup is not available on this server',
                    fn () => $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false)
                );
            });
            self::assertSame($rows, $this->leadTable->rows, 'the id that was kept stays, and no search by name follows a failure');
            $this->clock->advance(61);
        }
        self::assertSame(['POST', 'GET', 'GET', 'GET'], array_column($this->http->requests, 'method'));
    }

    // ------------------------------------------------------------------------------------ save as spot

    public function testSavingATaproomAsASpotLinksThePlaceAndTheLead(): void
    {
        $truck = self::truck();
        $saved = $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::TAPROOM, []);
        self::assertSame(['spot', 'lead'], array_keys($saved));
        $spot = $saved['spot'];

        self::assertSame('Example Brewing', $spot['name']);
        self::assertSame(FixtureRegion::TAPROOM, $spot['point']);
        self::assertSame('1 Example Rd, Sterling, VA 20166', $spot['address']);
        self::assertSame(
            [
                'spot_id' => $spot['id'], 'visibility' => 'normal',
                // the host the type describes, at the place's default size, the truck being its only food
                'host' => ['segment' => 'v_nightlife', 'size' => 40.0, 'size_source' => 'default', 'only_food' => true,
                    'point_id' => 'p' . self::TAPROOM, 'place_type' => 'taproom'],
                'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null,
            ],
            $spot['terms']
        );
        // the OpenStreetMap columns, and the link
        self::assertSame(
            ['place_type' => 'taproom', 'name' => 'Example Brewing', 'contact' => null, 'phone' => '+17035550100',
                'website' => 'https://example.com', 'place_key' => self::TAPROOM, 'google_place_id' => null],
            $spot['host_details']
        );
        self::assertSame('fresh', $spot['vectors_state']);
        self::assertSame(['p' . self::TAPROOM], $spot['vectors']['normal']['exclusion']['point_ids'], 'the place\'s own point is left out of the catchment');
        self::assertSame(self::USER, $this->spotTable->rows[$spot['id']]['created_by']);

        // the lead: linked, and a new one is shortlisted
        self::assertSame($spot['id'], $saved['lead']['spot_id']);
        self::assertSame('shortlisted', $saved['lead']['status']);
        self::assertSame(self::TAPROOM, $saved['lead']['place_key']);
        self::assertSame($spot['id'], $this->leadTable->rows[$saved['lead']['id']]['spot_id']);
        self::assertSame('Example Brewing', $this->leadTable->rows[$saved['lead']['id']]['place_name']);

        // the list shows it as saved
        self::assertSame($spot['id'], self::candidate($this->rank(), self::TAPROOM)['lead']['spot_id']);
    }

    public function testTheOwnersFiguresGoIntoTheSpot(): void
    {
        $truck = self::truck();
        $this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['status' => 'contacted', 'notes' => 'Friday']);
        $saved = $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::TAPROOM, [
            'name' => '  Friday taproom  ', 'visibility' => 'prominent', 'host_size' => 80, 'only_food' => false, 'colour' => 'red',
        ]);
        self::assertSame('Friday taproom', $saved['spot']['name']);
        self::assertSame('prominent', $saved['spot']['terms']['visibility']);
        self::assertSame(
            ['segment' => 'v_nightlife', 'size' => 80.0, 'size_source' => 'owner', 'only_food' => false, 'point_id' => 'p' . self::TAPROOM, 'place_type' => 'taproom'],
            $saved['spot']['terms']['host']
        );
        // a status the owner chose stays; only a new lead is moved to shortlisted
        self::assertSame('contacted', $saved['lead']['status']);
        self::assertSame('Friday', $saved['lead']['notes']);
        self::assertSame($saved['spot']['id'], $saved['lead']['spot_id']);
        self::assertCount(1, $this->leadTable->rows);
    }

    public function testAPlaceWithoutATypicalSizeNeedsTheOwnersFigure(): void
    {
        $truck = self::truck();
        self::assertInvalid(
            'host_size is required for this kind of place',
            'host_size',
            null,
            fn () => $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::OFFICE, [])
        );
        self::assertInvalid(
            'host_size is required for this kind of place',
            'host_size',
            null,
            fn () => $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::OFFICE, ['host_size' => null, 'only_food' => true])
        );
        self::assertSame([], $this->spotTable->rows);
        self::assertSame([], $this->leadTable->rows);

        $saved = $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::OFFICE, ['host_size' => 600]);
        self::assertSame('Herndon Office Park', $saved['spot']['name']);
        self::assertSame('Herndon, VA', $saved['spot']['address']);
        self::assertSame(
            ['segment' => 'w_office', 'size' => 600.0, 'size_source' => 'owner', 'only_food' => true, 'point_id' => null, 'place_type' => 'office_park'],
            $saved['spot']['terms']['host']
        );
        self::assertSame(self::OFFICE, $saved['spot']['host_details']['place_key']);
        // the declared workers are taken out of the blocks around the truck, so they are not counted twice
        self::assertSame(['point_ids' => [], 'segment' => 'w_office', 'amount' => 600.0], $saved['spot']['vectors']['normal']['exclusion']);
        self::assertSame('shortlisted', $saved['lead']['status']);
    }

    public function testATypeWithoutAHostSegmentIsSavedWithoutAHost(): void
    {
        $saved = $this->service->saveAsSpot(self::ORG, self::truck(), self::USER, self::MARKET, ['host_size' => 500, 'only_food' => true]);
        self::assertNull($saved['spot']['terms']['host'], 'size and only-food are ignored');
        self::assertSame('Saturday Market', $saved['spot']['name']);
        self::assertSame('', $saved['spot']['address']);
        self::assertSame(
            ['place_type' => 'farmers_market', 'name' => 'Saturday Market', 'contact' => null, 'phone' => null, 'website' => null,
                'place_key' => self::MARKET, 'google_place_id' => null],
            $saved['spot']['host_details']
        );
        self::assertSame($saved['spot']['id'], $saved['lead']['spot_id']);
    }

    public function testOnlyThePlaceIdOfGoogleTravelsToTheSpot(): void
    {
        $truck = self::truck();
        $this->found();
        $looked = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertSame('(703) 555-0199', $looked['contact']['phone'], 'the browser was told');

        $saved = $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::TAPROOM, []);
        $spot = $saved['spot'];
        // the spot carries the OpenStreetMap phone and website, and Google's id of the place
        self::assertSame('+17035550100', $spot['host_details']['phone']);
        self::assertSame('https://example.com', $spot['host_details']['website']);
        self::assertSame('Example Brewing', $spot['host_details']['name']);
        self::assertSame(self::PLACE_ID, $spot['host_details']['google_place_id']);
        self::assertSame('1 Example Rd, Sterling, VA 20166', $spot['address']);

        // nothing else of Google's reached the spot or stayed on the lead: not in the answer, not in a row
        $row = $this->spotTable->rows[$spot['id']];
        self::assertSame(self::PLACE_ID, $row['google_place_id']);
        unset($row['vectors_bin'], $spot['vectors']);
        foreach ([(string) json_encode($row), (string) json_encode($spot), (string) json_encode($saved['lead']), (string) json_encode($this->leadTable->rows)] as $text) {
            foreach (self::GOOGLE_TRACES as $trace) {
                self::assertStringNotContainsString($trace, $text);
            }
        }
        self::assertSame(self::PLACE_ID, $saved['lead']['google']['place_id']);
        // and the places themselves were only read
        self::assertSame([], $this->places->writes);
    }

    public function testAPlaceIsSavedOnceUntilItsSpotIsArchived(): void
    {
        $truck = self::truck();
        $first = $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::TAPROOM, []);
        self::assertRefused(
            TpConflict::class,
            'This place is already saved as a spot',
            fn () => $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::TAPROOM, ['name' => 'Again'])
        );
        self::assertCount(1, $this->spotTable->rows);

        // Archiving the spot frees the place: the lead reads as not saved, and it can be saved again.
        $this->spotTable->rows[$first['spot']['id']]['archived_at'] = '2026-10-08 09:00:00';
        self::assertNull(self::candidate($this->rank(), self::TAPROOM)['lead']['spot_id']);
        self::assertNull($this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['notes' => 'moved'])['lead']['spot_id']);
        $second = $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::TAPROOM, []);
        self::assertNotSame($first['spot']['id'], $second['spot']['id']);
        self::assertSame($second['spot']['id'], $second['lead']['spot_id']);
        self::assertSame('shortlisted', $second['lead']['status'], 'the status the lead had is kept');
        self::assertCount(1, $this->leadTable->rows);
    }

    public function testASaveBodyIsValidatedBeforeAnythingIsWritten(): void
    {
        $truck = self::truck();
        $save = fn (array $body) => fn () => $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::TAPROOM, $body);
        self::assertInvalid('name must be text of at most 120 characters', 'name', 'V5', $save(['name' => str_repeat('n', 121)]));
        self::assertInvalid('visibility must be one of: hidden, normal, prominent', 'visibility', 'V4', $save(['visibility' => 'loud']));
        self::assertInvalid('host_size must be a number between 1 and 200000', 'host_size', 'V2', $save(['host_size' => 0]));
        self::assertInvalid('host_size must be a number between 1 and 200000', 'host_size', 'V2', $save(['host_size' => '80']));
        self::assertInvalid('only_food must be true or false', 'only_food', 'V6', $save(['only_food' => 'yes']));
        self::assertSame([], $this->spotTable->rows);
        self::assertSame([], $this->leadTable->rows);
    }

    public function testASpotThatCannotBeSavedLeavesTheLeadAlone(): void
    {
        $truck = self::truck();
        $this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['status' => 'new', 'notes' => 'first']);
        $config = TpConfig::all();
        $config['limits']['max_spots'] = 0;
        TpConfig::replace($config);
        self::assertRefused(TpConflict::class, 'You can keep at most 0 spots', fn () => $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::TAPROOM, []));
        TpConfig::replace(null);
        $this->region->usable = false;
        self::assertRefused(
            TpConflict::class,
            'Region data was built with different model constants',
            fn () => $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::TAPROOM, [])
        );
        $row = array_values($this->leadTable->rows)[0];
        self::assertSame('new', $row['lead_state']);
        self::assertNull($row['spot_id']);
        self::assertSame([], $this->spotTable->rows);
    }

    public function testALongPlaceNameIsCutForTheSpot(): void
    {
        $this->places->rows[self::MARKET]['name'] = str_repeat('é', 150);
        $saved = $this->service->saveAsSpot(self::ORG, self::truck(), self::USER, self::MARKET, []);
        self::assertSame(str_repeat('é', 120), $saved['spot']['name']);
        self::assertSame(str_repeat('é', 150), $saved['spot']['host_details']['name']);
    }
}

/**
 * The places of a region as `tp_places` holds them, answering the two reads Scout makes: the paged host
 * vectors (Q6) and the display columns by key (Q7). It has no way to write.
 */
final class ScoutPlaces extends PlaceRepository
{
    /** @var array<string, array<string, mixed>> rows by place_key */
    public array $rows = [];

    /** @var list<array{region: string, version: string, box: array<string, float>, counties: list<string>, after: string}> */
    public array $pages = [];

    /** @var list<list<string>> the key lists byKeys() was asked for */
    public array $keyReads = [];

    /** @var list<string> nothing ever lands here: the class offers no write */
    public array $writes = [];

    public function __construct()
    {
        parent::__construct(new RecordingDatabase());
    }

    /**
     * @param array<string, mixed> $row any of the columns; `host_vec` makes the place a possible host
     */
    public function add(array $row): void
    {
        $this->rows[(string) $row['place_key']] = $row + [
            'name' => null, 'brand' => null, 'county_fips' => null, 'host_fit' => 0.0, 'kitchen' => 'unknown', 'size_default' => 0.0,
            'visitor_segment' => null, 'phone' => null, 'website' => null, 'addr_line' => null, 'city' => null, 'state_code' => null,
            'postcode' => null, 'opening_hours_raw' => null, 'hours_mask' => null, 'in_region' => true, 'host_vec' => null,
        ];
    }

    public function hostVectorPage(string $regionId, string $version, array $box, array $countyFips, string $afterKey): array
    {
        $this->pages[] = ['region' => $regionId, 'version' => $version, 'box' => $box, 'counties' => $countyFips, 'after' => $afterKey];
        $keys = array_map('strval', array_keys($this->rows));
        sort($keys, SORT_STRING);
        $out = [];
        foreach ($keys as $key) {
            $row = $this->rows[$key];
            if (strcmp($key, $afterKey) <= 0 || !$row['in_region'] || !($row['host_fit'] > 0.0) || $row['host_vec'] === null) {
                continue;
            }
            if ($row['lat'] < $box['lat_min'] || $row['lat'] > $box['lat_max'] || $row['lng'] < $box['lng_min'] || $row['lng'] > $box['lng_max']) {
                continue;
            }
            if ($countyFips !== [] && !in_array($row['county_fips'], $countyFips, true)) {
                continue;
            }
            $out[] = [$key, $row['place_type'], (float) $row['lat'], (float) $row['lng'], $row['county_fips'], $row['kitchen'],
                $row['visitor_segment'], (float) $row['size_default'], $row['host_vec']];
            if (count($out) === self::pageRows()) {
                break;
            }
        }
        return $out;
    }

    public function byKeys(string $regionId, string $version, array $keys): array
    {
        $this->keyReads[] = array_values($keys);
        $out = [];
        foreach ($keys as $key) {
            if (!isset($this->rows[$key])) {
                continue;
            }
            $row = $this->rows[$key];
            $out[(string) $key] = [
                'place_key' => (string) $key, 'place_type' => $row['place_type'], 'name' => $row['name'], 'brand' => $row['brand'],
                'lat' => (float) $row['lat'], 'lng' => (float) $row['lng'], 'county_fips' => $row['county_fips'],
                'host_fit' => (float) $row['host_fit'], 'kitchen' => $row['kitchen'], 'size_default' => (float) $row['size_default'],
                'visitor_segment' => $row['visitor_segment'], 'phone' => $row['phone'], 'website' => $row['website'],
                'addr_line' => $row['addr_line'], 'city' => $row['city'], 'state_code' => $row['state_code'], 'postcode' => $row['postcode'],
                'opening_hours_raw' => $row['opening_hours_raw'], 'hours_mask' => $row['hours_mask'],
            ];
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}

/**
 * An in-memory `tp_scout_leads` behind the Database interface: it runs the statements ScoutLeadRepository
 * writes and answers its reads the way MySQL would, with NOW() taken from the test's clock. It has the
 * columns of the migration and no other, so a statement that named a column for Google's name, address,
 * phone, website or Maps link would fail here as it would in MySQL. The repository under test is the real one.
 */
final class ScoutLeadTable extends Database
{
    /** The columns of the table as migration 043 creates it. A statement that names another one fails. */
    public const COLUMNS = ['id', 'organization_id', 'truck_id', 'region_id', 'place_key', 'place_name', 'place_type', 'lat', 'lng',
        'lead_state', 'notes', 'spot_id', 'google_place_id', 'g_lookup_state', 'g_fetched_at', 'created_at', 'updated_at'];

    private const READ = 'SELECT id, organization_id, truck_id, region_id, place_key, place_name, place_type, lat, lng, lead_state, notes, '
        . 'spot_id, google_place_id, g_lookup_state, g_fetched_at, created_at, updated_at FROM tp_scout_leads ';

    /** @var array<string, array<string, mixed>> rows by id */
    public array $rows = [];

    /** @var list<string> every statement in order, whitespace squashed */
    public array $statements = [];

    private FixedClock $clock;

    public function __construct(FixedClock $clock)
    {
        $this->clock = $clock;
    }

    public function beginTransaction(): void
    {
        throw new \LogicException('ScoutLeadTable: no statement of a lead needs a transaction');
    }

    public function commit(): void
    {
        throw new \LogicException('ScoutLeadTable: no statement of a lead needs a transaction');
    }

    public function rollback(): void
    {
        throw new \LogicException('ScoutLeadTable: no statement of a lead needs a transaction');
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $sql = $this->record($sql);
        if ($sql === self::READ . 'WHERE organization_id = ? AND truck_id = ? AND region_id = ? ORDER BY place_key') {
            [$org, $truck, $region] = self::bound($params);
            $out = [];
            foreach ($this->rows as $row) {
                if ($row['organization_id'] === $org && $row['truck_id'] === $truck && $row['region_id'] === $region) {
                    $out[] = $row;
                }
            }
            usort($out, static fn (array $a, array $b): int => strcmp((string) $a['place_key'], (string) $b['place_key']));
            return $out;
        }
        throw new \LogicException('ScoutLeadTable: unexpected statement: ' . $sql);
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $sql = $this->record($sql);
        $params = self::bound($params);
        $onePlace = 'WHERE organization_id = ? AND truck_id = ? AND region_id = ? AND place_key = ?';
        if ($sql === self::READ . $onePlace) {
            return $this->one($params);
        }
        if ($sql === 'SELECT id FROM tp_scout_leads ' . $onePlace) {
            $row = $this->one($params);
            return $row === null ? null : ['id' => $row['id']];
        }
        throw new \LogicException('ScoutLeadTable: unexpected statement: ' . $sql);
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $sql = $this->record($sql);
        $params = self::bound($params);
        if (preg_match('/^INSERT INTO tp_scout_leads \((.+?)\) VALUES \((.+)\)$/', $sql, $m) === 1) {
            $columns = explode(', ', $m[1]);
            $expressions = explode(', ', $m[2]);
            if (count($columns) !== count($expressions)) {
                throw new \LogicException('ScoutLeadTable: columns and values differ in number');
            }
            $row = array_fill_keys(self::COLUMNS, null);
            foreach ($columns as $i => $column) {
                self::known($column);
                $row[$column] = $expressions[$i] === 'NOW()' ? $this->now() : array_shift($params);
            }
            if ($params !== []) {
                throw new \LogicException('ScoutLeadTable: more values than placeholders');
            }
            foreach ($this->rows as $other) {
                if ([$other['truck_id'], $other['region_id'], $other['place_key']] === [$row['truck_id'], $row['region_id'], $row['place_key']]) {
                    throw new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key uk_tpsc_place');
                }
            }
            $this->rows[(string) $row['id']] = $row;
            return new \PDOStatement();
        }
        if (preg_match('/^UPDATE tp_scout_leads SET (.+) WHERE id = \? AND organization_id = \?$/', $sql, $m) === 1) {
            $changes = $this->assignments($m[1], $params);
            if (count($params) !== 2) {
                throw new \LogicException('ScoutLeadTable: the WHERE of an UPDATE binds the id and the organization');
            }
            [$id, $org] = $params;
            if (isset($this->rows[$id]) && $this->rows[$id]['organization_id'] === $org) {
                $next = array_merge($this->rows[$id], $changes);
                if ($next !== $this->rows[$id]) {
                    $next['updated_at'] = $this->now();
                }
                $this->rows[$id] = $next;
            }
            return new \PDOStatement();
        }
        throw new \LogicException('ScoutLeadTable: unexpected statement: ' . $sql);
    }

    /**
     * The values of "a = ?, b = NULL, c = NOW()"; the bound ones are taken off the front of `$params`.
     *
     * @param list<mixed> $params
     * @return array<string, mixed>
     */
    private function assignments(string $list, array &$params): array
    {
        $changes = [];
        foreach (explode(', ', $list) as $assignment) {
            [$column, $expression] = explode(' = ', $assignment, 2);
            self::known($column);
            switch ($expression) {
                case '?':
                    if ($params === []) {
                        throw new \LogicException('ScoutLeadTable: more placeholders than values');
                    }
                    $changes[$column] = array_shift($params);
                    break;
                case 'NULL':
                    $changes[$column] = null;
                    break;
                case 'NOW()':
                    $changes[$column] = $this->now();
                    break;
                default:
                    throw new \LogicException('ScoutLeadTable: unexpected value expression: ' . $expression);
            }
        }
        return $changes;
    }

    /** What MySQL answers for a column the table does not have. */
    private static function known(string $column): void
    {
        if (!in_array($column, self::COLUMNS, true)) {
            throw new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column '" . $column . "' in 'field list'");
        }
    }

    /**
     * @param list<mixed> $params [organization, truck, region, place_key]
     * @return array<string, mixed>|null
     */
    private function one(array $params): ?array
    {
        [$org, $truck, $region, $key] = $params;
        foreach ($this->rows as $row) {
            if ([$row['organization_id'], $row['truck_id'], $row['region_id'], $row['place_key']] === [$org, $truck, $region, $key]) {
                return $row;
            }
        }
        return null;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<mixed>
     */
    private static function bound(array $params): array
    {
        foreach ($params as $value) {
            if (is_float($value) || is_bool($value) || is_array($value)) {
                throw new \LogicException('ScoutLeadTable: a float, a boolean or an array was bound');
            }
        }
        return array_values($params);
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s', $this->clock->epoch());
    }

    private function record(string $sql): string
    {
        $squashed = trim((string) preg_replace('/\s+/', ' ', $sql));
        $this->statements[] = $squashed;
        return $squashed;
    }
}

/**
 * A leg provider that answers the labelled straight line for every leg, unless the test scripted a routed
 * leg or an owner's correction for it. It remembers what it was asked.
 */
final class ScoutLegs implements LegProvider
{
    /** @var list<array{org: string, points: int, pairs: list<array{0: string, 1: string}>, options: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{duration_s: float, distance_m: float}> routed legs by "from>to" */
    private array $routed = [];

    /** @var array<string, int> the owner's minutes by "from>to" */
    private array $overrides = [];

    /** A leg as Google would have routed it. */
    public function route(string $from, string $to, float $durationS, float $distanceM): void
    {
        $this->routed[$from . '>' . $to] = ['duration_s' => $durationS, 'distance_m' => $distanceM];
    }

    /** The owner's own minutes for a leg. */
    public function override(string $from, string $to, int $minutes): void
    {
        $this->overrides[$from . '>' . $to] = $minutes;
    }

    public function legs(string $orgId, array $truck, array $points, array $pairs, array $options = []): array
    {
        $this->calls[] = ['org' => $orgId, 'points' => count($points), 'pairs' => array_values($pairs), 'options' => $options];
        $legs = (new StraightLineLegs())->legs($orgId, $truck, $points, $pairs, $options);
        foreach ($legs as $i => $leg) {
            $pair = $leg['from_id'] . '>' . $leg['to_id'];
            if (isset($this->routed[$pair])) {
                $routed = $this->routed[$pair];
                $legs[$i] = [
                    'from_id' => $leg['from_id'], 'to_id' => $leg['to_id'], 'source' => 'google_routes', 'fetched_on' => '2026-10-07',
                    'age_days' => 0, 'distance_m' => $routed['distance_m'], 'duration_s' => $routed['duration_s'],
                    'toll_state' => 'not_asked', 'google_toll' => null, 'toll_source' => 'none', 'override' => null,
                    'fallback_reason' => null,
                    'leg_input' => ['source' => 'google', 'distance_m' => $routed['distance_m'], 'duration_s' => $routed['duration_s'],
                        'override_minutes' => null, 'toll' => 0.0],
                ];
            }
            if (isset($this->overrides[$pair])) {
                $legs[$i]['leg_input']['override_minutes'] = $this->overrides[$pair];
            }
        }
        return $legs;
    }

    /**
     * The legs between the truck's base and one place, without being counted as a call.
     *
     * @param array<string, mixed> $truck
     * @param array<string, mixed> $place a row with `place_key`, `lat`, `lng`
     * @param list<array{0: string, 1: string}> $pairs
     * @return list<array<string, mixed>>
     */
    public function answer(array $truck, array $place, array $pairs): array
    {
        $calls = $this->calls;
        $base = $truck['profile']['base'];
        $legs = $this->legs(
            (string) $truck['organization_id'],
            $truck,
            [['id' => 'base', 'lat' => $base['lat'], 'lng' => $base['lng']], ['id' => (string) $place['place_key'], 'lat' => $place['lat'], 'lng' => $place['lng']]],
            $pairs
        );
        $this->calls = $calls;
        return $legs;
    }

    public function status(): array
    {
        return ['state' => 'ok'];
    }

    public function movePoint(string $orgId, array $truck, array $oldPoint, array $newPoint): void
    {
    }
}

/**
 * The upstream guard with a key that a test can take away. Refusal memory and back-off are the real ones.
 */
final class ScoutGuard extends UpstreamGuard
{
    public bool $key = true;

    public function hasGoogleKey(): bool
    {
        return $this->key;
    }
}
