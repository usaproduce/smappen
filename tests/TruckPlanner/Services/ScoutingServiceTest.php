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
 * (hosts nothing) and an office outside the counties.
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
     * Office parks north of the base, the better the nearer: `g001` has the most workers around it.
     *
     * @return list<string> their keys, best first
     */
    private function addOffices(int $count, float $firstMetres = 500.0, float $stepMetres = 20.0, string $county = '51061'): array
    {
        $keys = [];
        $base = SpotServiceTest::truck()['profile']['base'];
        for ($i = 1; $i <= $count; $i++) {
            $key = sprintf('g%03d', $i);
            $capture = array_fill(0, 16, 0.0);
            $capture[1] = 400.0 - $i;
            $vectors = ['capture' => ['day' => $capture, 'eve' => $capture], 'nearby' => $capture, 'rivals' => ['day' => 0.0, 'eve' => 0.0]];
            $this->places->add([
                'place_key' => $key, 'place_type' => 'office_park', 'name' => 'Office ' . $i,
                'lat' => FixtureRegion::north($base['lat'], $firstMetres + $stepMetres * $i), 'lng' => $base['lng'],
                'county_fips' => $county, 'host_fit' => 0.8, 'kitchen' => 'no',
                'host_vec' => pack('e50', ...VectorCodec::flat($vectors)),
            ]);
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
     * @return list<string> the place keys in rank order
     */
    private static function keys(array $answer): array
    {
        return array_map(static fn (array $c): string => $c['place']['place_key'], $answer['candidates']);
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
    private static function googlePlace(float $metres = 35.0): array
    {
        return [
            'id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
            'displayName' => ['text' => 'Example Brewing Co (Google)', 'languageCode' => 'en'],
            'formattedAddress' => '99 Google Way, Sterling, VA 20166, USA',
            'location' => ['latitude' => FixtureRegion::north(FixtureRegion::TAPROOM['lat'], $metres), 'longitude' => FixtureRegion::TAPROOM['lng']],
            'nationalPhoneNumber' => '(703) 555-0199',
            'websiteUri' => 'https://google-says.example.com/',
            'googleMapsUri' => 'https://maps.google.com/?cid=424242',
        ];
    }

    private function found(float $metres = 35.0): void
    {
        $this->http->json(200, ['places' => [self::googlePlace($metres)]]);
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

    // ------------------------------------------------------------------------------------ the ranked list

    public function testTheListIsTheModelsRankingOfThePossibleHostsWithinReach(): void
    {
        $answer = $this->rank();

        self::assertSame(
            ['candidates', 'screened', 'truncated', 'limit_minutes', 'licence_counties', 'dataset_version', 'cached', 'notice', 'attribution'],
            array_keys($answer)
        );
        // the four possible hosts; the restaurant and the office outside the counties are no candidates
        self::assertSame(4, $answer['screened']);
        self::assertFalse($answer['truncated']);
        self::assertSame(45, $answer['limit_minutes']);
        self::assertSame([], $answer['licence_counties']);
        self::assertSame(FixtureRegion::VERSION, $answer['dataset_version']);
        self::assertFalse($answer['cached']);

        $expected = $this->modelRanking([self::TAPROOM, self::BAR, self::MARKET, self::OFFICE], self::truck());
        self::assertCount(4, $expected);
        self::assertSame($expected, array_column($answer['candidates'], 'result'), 'each result is the model\'s, in the model\'s order');
        self::assertSame([1, 2, 3, 4], array_map(static fn (array $c): int => $c['result']['position'], $answer['candidates']));

        // The office park 20 m from the worked example of 02_MODEL.md 4.4: Tuesday lunch, about 70 orders.
        $office = $answer['candidates'][0];
        self::assertSame(self::OFFICE, $office['result']['place_id']);
        self::assertSame(['dow' => 1, 'open_minute' => 660, 'close_minute' => 840], $office['result']['best_window']);
        self::assertGreaterThan(60.0, $office['result']['orders']['value']);
        self::assertLessThan(75.0, $office['result']['orders']['value']);
        self::assertSame('rough', $office['result']['orders']['confidence']);
        self::assertSame(0.0, $office['result']['host_size'], 'an office park has no size of its own');
        // The taproom: its own 40 guests on Saturday evening, the truck being the only food.
        $taproom = $answer['candidates'][1];
        self::assertSame(self::TAPROOM, $taproom['result']['place_id']);
        self::assertSame(['dow' => 5, 'open_minute' => 1020, 'close_minute' => 1200], $taproom['result']['best_window']);
        self::assertEqualsWithDelta(21.516, $taproom['result']['orders']['value'], 1e-9);
        self::assertSame('very_rough', $taproom['result']['orders']['confidence']);
        self::assertSame('no', $taproom['result']['kitchen']);
        // The market has no hour of its own and nobody around it: no window, and it ranks last but one or last.
        $market = $answer['candidates'][array_search(self::MARKET, self::keys($answer), true)];
        self::assertNull($market['result']['best_window']);
        self::assertSame(0.0, $market['result']['orders']['value']);

        foreach ($answer['candidates'] as $candidate) {
            self::assertSame(['result', 'place', 'lead', 'maps_url', 'leg_sources'], array_keys($candidate));
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

        // Once Google's id of the place is stored on the lead, the link names the place.
        $this->found();
        $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false);
        $taproom = $this->rank()['candidates'][1];
        self::assertSame(self::TAPROOM, $taproom['place']['place_key']);
        self::assertSame(
            'https://www.google.com/maps/search/?api=1&query=Example%20Brewing&query_place_id=ChIJN1t_tDeuEmsRUsoyG83frY4',
            $taproom['maps_url']
        );
        // ... and still does after the looked-up details have expired: the id may be kept.
        $this->clock->advance(31 * 86400);
        $later = $this->rank()['candidates'][1];
        self::assertNull($later['lead']['google']);
        self::assertSame($taproom['maps_url'], $later['maps_url']);
    }

    public function testTheStandingReminderAndTheSourcesTravelWithTheList(): void
    {
        $answer = $this->rank();
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

    public function testNothingInAScoutPayloadReadsAsAStatementAboutRules(): void
    {
        $this->found();
        $payloads = [
            $this->rank(),
            $this->service->saveLead(self::ORG, self::truck(), self::TAPROOM, ['status' => 'contacted', 'notes' => 'Call back Tuesday']),
            $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false),
            $this->service->saveAsSpot(self::ORG, self::truck(), self::USER, self::TAPROOM, []),
            $this->rank(['hide' => '']),
        ];
        foreach ($payloads as $payload) {
            $text = (string) json_encode($payload);
            foreach (self::LEGALITY_WORDS as $word) {
                self::assertStringNotContainsStringIgnoringCase($word, $text);
            }
        }
        foreach ([ScoutingService::NO_REGION, ScoutingService::PLACE_NOT_FOUND, ScoutingService::LOOKUP_UNAVAILABLE,
            ScoutingService::LOOKUP_BUSY, ScoutingService::ALREADY_SAVED, ScoutingService::NOTICE] as $sentence) {
            foreach (self::LEGALITY_WORDS as $word) {
                self::assertStringNotContainsStringIgnoringCase($word, $sentence);
            }
        }
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

    // ------------------------------------------------------------------------------------ licence counties and the drive limit

    public function testLicenceCountiesFilterThePlaces(): void
    {
        $answer = $this->rank([], self::truck(['licence_counties' => ['51059']]));
        self::assertSame([self::OFFICE], self::keys($answer));
        self::assertSame(1, $answer['screened']);
        self::assertSame(['51059'], $answer['licence_counties']);
        self::assertSame(['51059'], $this->places->pages[0]['counties'], 'the county list is part of the query');

        $loudoun = $this->rank([], self::truck(['licence_counties' => ['51107', '51107']]));
        self::assertEqualsCanonicalizing([self::TAPROOM, self::BAR, self::MARKET], self::keys($loudoun));
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
            $expected = [];
            foreach ($minutes as $key => $roundTrip) {
                self::assertSame($roundTrip <= 2 * $limit, in_array($key, $listed, true), $key . ' at a limit of ' . $limit . ' minutes');
                if ($roundTrip <= 2 * $limit) {
                    $expected[] = $key;
                }
            }
            foreach ($answer['candidates'] as $candidate) {
                self::assertLessThanOrEqual(2 * $limit, $candidate['result']['round_trip']['minutes']);
            }
            self::assertSame(array_column($this->modelRanking($expected, $truck), 'place_id'), $listed);
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
        $office = $answer['candidates'][array_search(self::OFFICE, $after, true)];
        self::assertSame(['out' => 'google_routes', 'back' => 'google_routes'], $office['leg_sources']);
        self::assertLessThanOrEqual(20, $office['result']['round_trip']['minutes']);
        self::assertSame([self::OFFICE], $after, 'the bar and the market are far over the limit and are not asked about');
        self::assertSame($this->modelRanking([self::OFFICE], $truck), array_column($answer['candidates'], 'result'));

        // Tolls are not asked for, and both directions of every shortlisted place are.
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

    public function testAFullListComesFromTheBestScreensAndAnotherBatchIsReadOnlyWhenNeeded(): void
    {
        $offices = $this->addOffices(130);
        $truck = self::truck(['licence_counties' => ['51061'], 'scout_drive_minutes_limit' => 30]);

        // All of them are close: the first batch (the list of 50 and 10 more) is enough.
        $answer = $this->rank([], $truck);
        self::assertSame(130, $answer['screened']);
        self::assertSame(array_slice($offices, 0, 50), self::keys($answer));
        self::assertCount(1, $this->legs->calls);
        self::assertCount(120, $this->legs->calls[0]['pairs']);
        self::assertSame(61, $this->legs->calls[0]['points'], 'the base and sixty places');

        // Routed legs put the best twenty outside the limit: the next batch fills the list.
        foreach (array_slice($offices, 0, 20) as $key) {
            $this->legs->route('base', $key, 7200.0, 50000.0);
            $this->legs->route($key, 'base', 7200.0, 50000.0);
        }
        $this->legs->calls = [];
        $answer = $this->rank([], $truck);
        self::assertSame(array_slice($offices, 20, 50), self::keys($answer));
        self::assertCount(2, $this->legs->calls, 'a second batch, and no third');
        self::assertSame([['base', 'g061'], ['g061', 'base']], array_slice($this->legs->calls[1]['pairs'], 0, 2));
        self::assertSame(range(1, 50), array_map(static fn (array $c): int => $c['result']['position'], $answer['candidates']));
        foreach ($answer['candidates'] as $candidate) {
            self::assertStringStartsWith('https://www.google.com/maps/search/?api=1&query=', $candidate['maps_url']);
        }
    }

    public function testAtMostThreeBatchesAreAskedAbout(): void
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
        self::assertSame([120, 120, 120], array_map(static fn (array $call): int => count($call['pairs']), $this->legs->calls));
        self::assertSame([], $answer['candidates'], 'every place that was asked about is outside the limit on its routed legs');
        self::assertFalse($answer['cached']);
        // the three batches are cached like any other
        $this->legs->calls = [];
        self::assertTrue($this->rank([], self::truck(['licence_counties' => ['51061'], 'scout_drive_minutes_limit' => 30]))['cached']);
        self::assertCount(3, $this->legs->calls);
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
        // the taproom is 890 m away: it and the nine nearest offices stay (g001 at 400 m ... g009 at 1,200 m)
        self::assertEqualsCanonicalizing(array_merge([self::TAPROOM], array_slice($offices, 0, 9)), self::keys($answer));
        // without the cut every place of the county is screened
        TpConfig::replace(null);
        $all = $this->rank([], self::truck(['licence_counties' => ['51107']]));
        self::assertFalse($all['truncated']);
        self::assertSame(33, $all['screened']);
    }

    // ------------------------------------------------------------------------------------ hidden leads

    public function testHiddenLeadsLeaveTheRanking(): void
    {
        $truck = self::truck();
        $this->service->saveLead(self::ORG, $truck, self::OFFICE, ['status' => 'hidden']);
        $this->service->saveLead(self::ORG, $truck, self::BAR, ['status' => 'declined']);
        $this->service->saveLead(self::ORG, $truck, self::TAPROOM, ['status' => 'contacted']);

        // by default the hidden ones go, and the others move up
        $answer = $this->rank();
        self::assertNotContains(self::OFFICE, self::keys($answer));
        self::assertSame(3, $answer['screened']);
        self::assertSame(self::TAPROOM, $answer['candidates'][0]['place']['place_key']);
        self::assertSame(1, $answer['candidates'][0]['result']['position']);
        self::assertSame(range(1, 3), array_map(static fn (array $c): int => $c['result']['position'], $answer['candidates']));

        // an empty value hides nothing
        self::assertCount(4, $this->rank(['hide' => ''])['candidates']);
        // several statuses
        self::assertEqualsCanonicalizing([self::TAPROOM, self::MARKET], self::keys($this->rank(['hide' => 'declined,hidden'])));
        self::assertEqualsCanonicalizing([self::TAPROOM, self::MARKET], self::keys($this->rank(['hide' => ' hidden , declined '])));
        self::assertEqualsCanonicalizing([self::BAR, self::MARKET, self::OFFICE], self::keys($this->rank(['hide' => 'contacted'])));
        // a place the owner has not touched is new: hiding `new` leaves the touched places that are shown
        self::assertEqualsCanonicalizing([self::TAPROOM, self::BAR, self::OFFICE], self::keys($this->rank(['hide' => 'new'])));
        self::assertSame([self::TAPROOM], self::keys($this->rank(['hide' => 'new,declined,hidden'])));
        self::assertSame([], self::keys($this->rank(['hide' => 'new,shortlisted,contacted,booked,declined,hidden'])));

        // the lead of a listed place comes with it
        $taproom = $this->rank()['candidates'][0]['lead'];
        self::assertSame('contacted', $taproom['status']);
        self::assertSame(36, strlen((string) $taproom['id']));
    }

    public function testTheQueryIsValidated(): void
    {
        $statuses = 'new, shortlisted, contacted, booked, declined, hidden';
        foreach (['gone', 'hidden,gone', 'hidden,', ',', 'Hidden'] as $hide) {
            self::assertInvalid('hide must be one of: ' . $statuses, 'hide', 'V4', fn () => $this->rank(['hide' => $hide]));
        }
        self::assertInvalid('hide must be one of: ' . $statuses, 'hide', 'V4', fn () => $this->rank(['hide' => ['hidden']]));
        self::assertInvalid('refresh must be true or false', 'refresh', 'V6', fn () => $this->rank(['refresh' => 'yes']));
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
        $first = $this->rank();
        self::assertFalse($first['cached']);
        self::assertCount(1, $this->places->pages);
        $keys = array_keys($this->cache->values);
        self::assertCount(2, $keys);
        self::assertMatchesRegularExpression('/^tp:scout:s:' . self::ORG . ':[0-9a-f]{40}$/', $keys[0]);
        self::assertMatchesRegularExpression('/^tp:scout:r:' . self::ORG . ':[0-9a-f]{40}$/', $keys[1]);
        self::assertSame([86400 + 93600, 86400 + 93600], array_values($this->cache->ttls));

        // warm: no place is read again, and the answer is the same
        $second = $this->rank();
        self::assertTrue($second['cached']);
        self::assertCount(1, $this->places->pages);
        self::assertSame(array_diff_key($first, ['cached' => 1]), array_diff_key($second, ['cached' => 1]));
        self::assertCount(2, $this->cache->values);

        // a lead is the owner's and is read fresh on a warm call
        $this->service->saveLead(self::ORG, self::truck(), self::TAPROOM, ['status' => 'booked', 'notes' => 'Friday']);
        $third = $this->rank();
        self::assertTrue($third['cached']);
        self::assertSame('booked', $third['candidates'][1]['lead']['status']);
        self::assertSame('Friday', $third['candidates'][1]['lead']['notes']);

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
        // The owner corrected a drive time: the leg input differs, the shortlist does not.
        $this->legs->override('base', self::TAPROOM, 9);
        $answer = $this->rank();
        self::assertFalse($answer['cached']);
        self::assertCount(1, $this->places->pages, 'the screen was not run again');
        self::assertCount(3, $this->cache->values);
        $taproom = $answer['candidates'][array_search(self::TAPROOM, self::keys($answer), true)];
        self::assertGreaterThanOrEqual(9, $taproom['result']['round_trip']['minutes']);
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
        // a status that is not hidden changes nothing that is cached
        $this->service->saveLead(self::ORG, self::truck(), self::BAR, ['status' => 'shortlisted']);
        $this->service->rank(self::ORG, self::truck(), Seeds::assumptions(['host.captive_share' => 0.5], self::A()['region']), []);
        self::assertCount($pages, $this->places->pages);
        // a hidden one does
        $this->service->saveLead(self::ORG, self::truck(), self::BAR, ['status' => 'hidden']);
        $this->service->rank(self::ORG, self::truck(), Seeds::assumptions(['host.captive_share' => 0.5], self::A()['region']), []);
        self::assertCount($pages + 1, $this->places->pages);
    }

    public function testTheTrucksOwnResultsReachTheListThroughTheCalibrationContract(): void
    {
        $plain = $this->rank();
        // today in the truck's zone, not in UTC
        self::assertSame(['2026-10-07'], $this->calibration->asOf);

        $this->calibration->factor = 0.5;
        $halved = $this->rank();
        $before = $plain['candidates'][1]['result'];
        $after = $halved['candidates'][array_search(self::TAPROOM, self::keys($halved), true)]['result'];
        self::assertSame(self::TAPROOM, $before['place_id']);
        self::assertEqualsWithDelta($before['orders']['value'] * 0.5, $after['orders']['value'], 1e-9);
        self::assertSame(
            array_column($this->modelRanking([self::TAPROOM, self::BAR, self::MARKET, self::OFFICE], self::truck(), ['truck_factor' => 0.5] + Estimator::calibrate(self::A(), [], '2026-10-07')), 'place_id'),
            self::keys($halved)
        );
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
    }

    public function testEveryListFirstEmptiesGoogleDetailsThatAreOlderThanThirtyDays(): void
    {
        $this->found();
        $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false);
        $id = array_key_first($this->leadTable->rows);
        self::assertSame('(703) 555-0199', $this->leadTable->rows[$id]['g_phone']);

        $this->clock->advance(30 * 86400 - 60);
        $this->rank();
        self::assertSame('(703) 555-0199', $this->leadTable->rows[$id]['g_phone'], 'still inside its 30 days');

        $this->clock->advance(120);
        $answer = $this->rank(['hide' => '']);
        $row = $this->leadTable->rows[$id];
        foreach (['g_lookup_state', 'g_name', 'g_address', 'g_phone', 'g_website', 'g_maps_uri', 'g_fetched_at'] as $column) {
            self::assertNull($row[$column], $column);
        }
        self::assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $row['google_place_id'], 'the place id may stay');
        $lead = $answer['candidates'][array_search(self::TAPROOM, self::keys($answer), true)]['lead'];
        self::assertNull($lead['google']);
        self::assertStringNotContainsString('555-0199', (string) json_encode($answer));
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
        self::assertRefused(
            TpUnavailable::class,
            'Contact lookup is not available on this server',
            fn () => $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false)
        );
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->leadTable->rows);
        self::assertSame([], $this->bucketCalls, 'no token is taken for a lookup that cannot be made');
        self::assertSame([], $this->ledgerRows->calls);
    }

    public function testALookupAsksGoogleOnceAndKeepsTheMatchOnTheLead(): void
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

        // the answer
        self::assertSame(['lead', 'lookup'], array_keys($answer));
        self::assertSame('found', $answer['lookup']);
        self::assertSame('new', $answer['lead']['status'], 'a lookup creates the lead as new');
        self::assertSame(
            [
                'place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
                'lookup_state' => 'found',
                'name' => 'Example Brewing Co (Google)',
                'address' => '99 Google Way, Sterling, VA 20166, USA',
                'phone' => '(703) 555-0199',
                'website' => 'https://google-says.example.com/',
                'maps_uri' => 'https://maps.google.com/?cid=424242',
                'fetched_on' => '2026-10-08',
            ],
            $answer['lead']['google']
        );

        // the row: the place id and the texts, stamped by the database, and no coordinate of Google's
        $row = $this->leadTable->rows[$answer['lead']['id']];
        self::assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $row['google_place_id']);
        self::assertSame('found', $row['g_lookup_state']);
        self::assertSame('(703) 555-0199', $row['g_phone']);
        self::assertSame($this->clock->epoch(), $row['g_fetched_at']);
        self::assertSame('39.01', $row['lat'], 'the point of the lead is the place\'s own');
        self::assertStringNotContainsString('39.0103', (string) json_encode($row));

        // one ledger row, and nothing of Google's was written to a spot
        self::assertCount(1, $this->ledgerRows->find('INSERT INTO api_cost_events'));
        self::assertSame('tp_places_text', $this->ledgerRows->only('INSERT INTO api_cost_events')['params'][1]);
        self::assertSame([], $this->spotTable->rows);
    }

    public function testAFoundLookupIsServedFromTheLeadForThirtyDays(): void
    {
        $truck = self::truck();
        $this->found();
        $first = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);

        $again = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertSame('cached', $again['lookup']);
        self::assertSame($first['lead'], $again['lead']);
        // asking again by force is honoured once the answer is a day old
        $this->clock->advance(23 * 3600);
        self::assertSame('cached', $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, true)['lookup']);
        $this->clock->advance(29 * 86400);
        self::assertSame('cached', $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false)['lookup']);
        self::assertCount(1, $this->http->requests, 'one call in thirty days');
        self::assertCount(1, $this->bucketCalls);

        // the cached answer is served without a key as well
        $this->guard->key = false;
        self::assertSame('cached', $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false)['lookup']);
        $this->guard->key = true;

        // after thirty days the details are gone from the lead, and the next lookup asks again
        $this->clock->advance(3601);
        $this->found();
        $later = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertSame('found', $later['lookup']);
        self::assertCount(2, $this->http->requests);
        self::assertSame('2026-11-07', $later['lead']['google']['fetched_on']);
    }

    public function testForceAsksAgainOnceTheAnswerIsADayOld(): void
    {
        $truck = self::truck();
        $this->found();
        $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        $this->clock->advance(24 * 3600);
        self::assertSame('cached', $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false)['lookup']);
        $this->http->queue(200, '{}');
        $forced = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, true);
        self::assertSame('not_found', $forced['lookup']);
        self::assertCount(2, $this->http->requests);
        // the id of the earlier match stays, the details of it do not
        self::assertSame(
            ['place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4', 'lookup_state' => 'not_found', 'name' => null, 'address' => null, 'phone' => null,
                'website' => null, 'maps_uri' => null, 'fetched_on' => '2026-10-09'],
            $forced['lead']['google']
        );
    }

    public function testNoMatchIsStoredAndCachedLikeAMatch(): void
    {
        $truck = self::truck();
        $this->http->queue(200, '{}');
        $answer = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertSame('not_found', $answer['lookup']);
        self::assertSame(
            ['place_id' => null, 'lookup_state' => 'not_found', 'name' => null, 'address' => null, 'phone' => null, 'website' => null,
                'maps_uri' => null, 'fetched_on' => '2026-10-08'],
            $answer['lead']['google']
        );
        $row = $this->leadTable->rows[$answer['lead']['id']];
        self::assertSame('not_found', $row['g_lookup_state']);
        self::assertNull($row['google_place_id']);

        $this->clock->advance(29 * 86400);
        $cached = $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false);
        self::assertSame('cached', $cached['lookup']);
        self::assertSame('not_found', $cached['lead']['google']['lookup_state']);
        self::assertCount(1, $this->http->requests);

        $this->clock->advance(2 * 86400);
        $this->found();
        self::assertSame('found', $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false)['lookup']);
        self::assertCount(2, $this->http->requests);
    }

    public function testAMatchSomewhereElseIsNoConfidentMatchAndNothingOfItIsKept(): void
    {
        $this->found(450.0);
        $answer = $this->service->lookupContact(self::ORG, self::truck(), self::TAPROOM, false);
        self::assertSame('not_found', $answer['lookup']);
        self::assertSame('not_found', $answer['lead']['google']['lookup_state']);
        self::assertNull($answer['lead']['google']['place_id']);
        $stored = (string) json_encode($this->leadTable->rows);
        foreach (['ChIJ', '555-0199', 'google-says', 'Google Way', '(Google)', 'cid=424242'] as $trace) {
            self::assertStringNotContainsString($trace, $stored);
        }
        // the map link stays the pin at the place's own point
        $taproom = $this->rank()['candidates'][1];
        self::assertSame('https://www.google.com/maps/search/?api=1&query=39.010000%2C-77.410000', $taproom['maps_url']);
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
        self::assertSame('found', $this->service->lookupContact(self::ORG, $truck, self::TAPROOM, false)['lookup']);
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
        $listed = $this->rank()['candidates'][1]['lead'];
        self::assertSame($spot['id'], $listed['spot_id']);
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
        self::assertSame('(703) 555-0199', $looked['lead']['google']['phone']);

        $saved = $this->service->saveAsSpot(self::ORG, $truck, self::USER, self::TAPROOM, []);
        $spot = $saved['spot'];
        // the spot carries the OpenStreetMap phone and website, and Google's id of the place
        self::assertSame('+17035550100', $spot['host_details']['phone']);
        self::assertSame('https://example.com', $spot['host_details']['website']);
        self::assertSame('Example Brewing', $spot['host_details']['name']);
        self::assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $spot['host_details']['google_place_id']);
        self::assertSame('1 Example Rd, Sterling, VA 20166', $spot['address']);

        // nothing else of Google's reached the spot: not in the answer, not in the row
        $row = $this->spotTable->rows[$spot['id']];
        self::assertSame('ChIJN1t_tDeuEmsRUsoyG83frY4', $row['google_place_id']);
        unset($row['vectors_bin'], $spot['vectors']);
        foreach ([(string) json_encode($row), (string) json_encode($spot)] as $text) {
            foreach (['555-0199', 'google-says', 'Google Way', '(Google)', 'cid=424242', 'maps.google.com'] as $trace) {
                self::assertStringNotContainsString($trace, $text);
            }
        }
        // the lead keeps what was looked up
        self::assertSame('(703) 555-0199', $saved['lead']['google']['phone']);
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
        self::assertNull($this->rank()['candidates'][1]['lead']['spot_id']);
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
 * writes and answers its reads the way MySQL would, with NOW() taken from the test's clock (the table keeps
 * `g_fetched_at` as that clock's epoch). The repository under test is the real one.
 */
final class ScoutLeadTable extends Database
{
    /** @var array<string, array<string, mixed>> rows by id */
    public array $rows = [];

    /** @var list<string> every statement in order, whitespace squashed */
    public array $statements = [];

    private FixedClock $clock;
    private bool $inTransaction = false;

    public function __construct(FixedClock $clock)
    {
        $this->clock = $clock;
    }

    public function beginTransaction(): void
    {
        if ($this->inTransaction) {
            throw new \LogicException('ScoutLeadTable: there is already an active transaction');
        }
        $this->inTransaction = true;
    }

    public function commit(): void
    {
        $this->inTransaction = false;
    }

    public function rollback(): void
    {
        $this->inTransaction = false;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $sql = $this->record($sql);
        if (str_starts_with($sql, 'SELECT id, organization_id, truck_id,')
            && str_ends_with($sql, 'FROM tp_scout_leads WHERE organization_id = ? AND truck_id = ? AND region_id = ? ORDER BY place_key')) {
            [$ttl, $org, $truck, $region] = self::bound($params);
            $out = [];
            foreach ($this->rows as $row) {
                if ($row['organization_id'] === $org && $row['truck_id'] === $truck && $row['region_id'] === $region) {
                    $out[] = $this->read($row, (int) $ttl);
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
        $onePlace = 'FROM tp_scout_leads WHERE organization_id = ? AND truck_id = ? AND region_id = ? AND place_key = ?';
        if (str_starts_with($sql, 'SELECT id, organization_id, truck_id,') && str_ends_with($sql, $onePlace)) {
            $ttl = (int) array_shift($params);
            $row = $this->one($params);
            return $row === null ? null : $this->read($row, $ttl);
        }
        if ($sql === 'SELECT id ' . $onePlace) {
            $row = $this->one($params);
            return $row === null ? null : ['id' => $row['id']];
        }
        if (preg_match('/^SELECT COUNT\(\*\) AS lead_count FROM tp_scout_leads WHERE (organization_id = \? AND )?g_fetched_at IS NOT NULL AND g_fetched_at < NOW\(\) - INTERVAL \? DAY$/', $sql, $m) === 1) {
            return ['lead_count' => count($this->expired($params, ($m[1] ?? '') !== ''))];
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
            $row = [
                'google_place_id' => null, 'g_lookup_state' => null, 'g_name' => null, 'g_address' => null, 'g_phone' => null,
                'g_website' => null, 'g_maps_uri' => null, 'g_fetched_at' => null,
            ];
            foreach ($columns as $i => $column) {
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
        if (preg_match('/^UPDATE tp_scout_leads SET (.+) WHERE (organization_id = \? AND )?g_fetched_at IS NOT NULL AND g_fetched_at < NOW\(\) - INTERVAL \? DAY$/', $sql, $m) === 1) {
            if (!$this->inTransaction) {
                throw new \LogicException('ScoutLeadTable: the purge counts and empties in one transaction');
            }
            $changes = $this->assignments($m[1], $params);
            foreach ($this->expired($params, ($m[2] ?? '') !== '') as $id) {
                $this->rows[$id] = array_merge($this->rows[$id], $changes, ['updated_at' => $this->now()]);
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
                    $changes[$column] = $column === 'g_fetched_at' ? $this->clock->epoch() : $this->now();
                    break;
                default:
                    throw new \LogicException('ScoutLeadTable: unexpected value expression: ' . $expression);
            }
        }
        return $changes;
    }

    /**
     * The ids of the rows whose lookup is older than the lifetime: [organization, days] or [days].
     *
     * @param list<mixed> $params
     * @return list<string>
     */
    private function expired(array $params, bool $oneOrganization): array
    {
        $org = $oneOrganization ? array_shift($params) : null;
        $days = (int) array_shift($params);
        $ids = [];
        foreach ($this->rows as $id => $row) {
            if ($oneOrganization && $row['organization_id'] !== $org) {
                continue;
            }
            if ($row['g_fetched_at'] !== null && $row['g_fetched_at'] < $this->clock->epoch() - $days * 86400) {
                $ids[] = (string) $id;
            }
        }
        return $ids;
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
     * A row as the read statement of the repository returns it.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function read(array $row, int $ttlDays): array
    {
        $fetched = $row['g_fetched_at'];
        $now = $this->clock->epoch();
        unset($row['g_fetched_at']);
        return $row + [
            'g_fetched_on' => $fetched === null ? null : gmdate('Y-m-d', $fetched),
            'g_age_hours' => $fetched === null ? null : intdiv($now - $fetched, 3600),
            'g_fresh' => $fetched !== null && $fetched >= $now - $ttlDays * 86400 ? 1 : 0,
        ];
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
