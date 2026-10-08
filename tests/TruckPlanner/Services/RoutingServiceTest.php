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
use App\TruckPlanner\Data\DriveLegRepository;
use App\TruckPlanner\Data\DriveOverrideRepository;
use App\TruckPlanner\Data\LegKey;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\LegProvider;
use App\TruckPlanner\Services\Fallback\StraightLineLegs;
use App\TruckPlanner\Services\Google\DistanceMatrixClient;
use App\TruckPlanner\Services\Google\RoutesMatrixClient;
use App\TruckPlanner\Services\Http\UpstreamGuard;
use App\TruckPlanner\Services\RoutingService;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Support\TpNotFound;
use PHPUnit\Framework\TestCase;

/**
 * The routing service with everything real except Google and MySQL: the two clients talk to stubbed HTTP,
 * the two repositories run their statements against tables held in memory (LegTables, below), and the
 * guard keeps its memory in an in-memory cache under a clock the test moves.
 */
final class RoutingServiceTest extends TestCase
{
    private const KEY = 'test-key-not-real';
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER_ORG = '22222222-2222-4222-8222-222222222222';
    private const TRUCK = '33333333-3333-4333-8333-333333333333';
    private const OTHER_TRUCK = '44444444-4444-4444-8444-444444444444';

    private const ROUTES_URL = 'https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix';
    private const LEGACY_URL = 'https://maps.googleapis.com/maps/api/distancematrix/json';

    // Sterling, Herndon, the National Mall, and three more places around them
    private const BASE = ['id' => 'base', 'lat' => 39.003, 'lng' => -77.405];
    private const S1 = ['id' => 's1', 'lat' => 38.96, 'lng' => -77.36];
    private const S2 = ['id' => 's2', 'lat' => 38.9072, 'lng' => -77.0369];
    private const S3 = ['id' => 's3', 'lat' => 39.0458, 'lng' => -77.4875];
    private const S4 = ['id' => 's4', 'lat' => 38.8048, 'lng' => -77.0469];
    private const S5 = ['id' => 's5', 'lat' => 39.1157, 'lng' => -77.5636];

    private const BASE_KEY = [390030, -774050];
    private const S1_KEY = [389600, -773600];
    private const S2_KEY = [389072, -770369];

    private FixedClock $clock;
    private MemoryCache $store;
    private LegTables $tables;
    private FakeHttp $http;
    private UpstreamGuard $guard;

    /** What was written to `tp_trucks`: the stamp a correction leaves for the plans. */
    private RecordingDatabase $truckDb;

    /** @var list<array{0: string, 1: int, 2: int}> what the token bucket was asked for */
    private array $bucketCalls = [];
    private bool $bucketAnswer = true;

    /** Seconds the stopwatch shows before any request, and how many each HTTP request adds. */
    private float $elapsed = 0.0;
    private float $secondsPerRequest = 0.0;

    private string|false $keyBefore;
    private ?string $envBefore;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-05 15:00:00');
        $this->store = new MemoryCache();
        TpCache::wire($this->clock, $this->store);
        $this->tables = new LegTables($this->clock);
        $this->truckDb = new RecordingDatabase();
        $this->http = new FakeHttp();
        $this->guard = new UpstreamGuard($this->clock, new ApiLedger($this->tables), function (string $bucket, int $tokens, int $wait): bool {
            $this->bucketCalls[] = [$bucket, $tokens, $wait];
            return $this->bucketAnswer;
        });
        $this->keyBefore = getenv('GOOGLE_API_KEY');
        $this->envBefore = $_ENV['GOOGLE_API_KEY'] ?? null;
        unset($_ENV['GOOGLE_API_KEY']);
        putenv('GOOGLE_API_KEY=' . self::KEY);
    }

    protected function tearDown(): void
    {
        TpCache::wire();
        TpConfig::replace(null);
        putenv($this->keyBefore === false ? 'GOOGLE_API_KEY' : 'GOOGLE_API_KEY=' . $this->keyBefore);
        if ($this->envBefore !== null) {
            $_ENV['GOOGLE_API_KEY'] = $this->envBefore;
        }
    }

    private function service(): RoutingService
    {
        $ledger = new ApiLedger($this->tables);
        return new RoutingService(
            new DriveLegRepository($this->tables),
            new DriveOverrideRepository($this->tables),
            $this->guard,
            new RoutesMatrixClient($this->http, $ledger),
            new DistanceMatrixClient($this->http, $ledger),
            $this->clock,
            fn (): float => $this->elapsed + $this->secondsPerRequest * count($this->http->requests),
            new TruckRepository($this->truckDb)
        );
    }

    private function noKey(): void
    {
        putenv('GOOGLE_API_KEY');
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed> the truck value the service reads
     */
    private static function truck(array $profile = [], string $id = self::TRUCK): array
    {
        return [
            'id' => $id,
            'timezone' => 'America/New_York',
            'profile' => $profile + ['avoid_tolls' => false, 'avoid_highways' => false, 'base' => ['lat' => 39.003, 'lng' => -77.405, 'address' => '']],
        ];
    }

    /**
     * Queue a Routes answer.
     *
     * @param list<array{0: int, 1: int, 2?: int, 3?: int, 4?: array<string, mixed>}> $cells [origin index,
     *        destination index, seconds, metres, extra fields]; only the first two are needed
     */
    private function queueRoutes(array $cells): void
    {
        $items = [];
        foreach ($cells as $cell) {
            $item = ['status' => new \stdClass(), 'condition' => 'ROUTE_EXISTS', 'distanceMeters' => $cell[3] ?? 7805, 'duration' => ($cell[2] ?? 600) . 's'];
            if ($cell[0] !== 0) {
                $item['originIndex'] = $cell[0];
            }
            if ($cell[1] !== 0) {
                $item['destinationIndex'] = $cell[1];
            }
            $items[] = ($cell[4] ?? []) + $item;
        }
        $this->http->json(200, $items);
    }

    /**
     * Queue a Routes answer that holds every element of a grid.
     */
    private function queueRoutesGrid(int $origins, int $destinations): void
    {
        $cells = [];
        for ($o = 0; $o < $origins; $o++) {
            for ($d = 0; $d < $destinations; $d++) {
                $cells[] = [$o, $d, 100 + 10 * $o + $d, 1000 + 100 * $o + $d];
            }
        }
        $this->queueRoutes($cells);
    }

    /**
     * Queue a legacy answer that holds every element of a grid.
     */
    private function queueLegacyGrid(int $origins, int $destinations): void
    {
        $rows = [];
        for ($o = 0; $o < $origins; $o++) {
            $elements = [];
            for ($d = 0; $d < $destinations; $d++) {
                $elements[] = ['status' => 'OK', 'duration' => ['value' => 700 + 10 * $o + $d], 'distance' => ['value' => 9000 + 100 * $o + $d]];
            }
            $rows[] = ['elements' => $elements];
        }
        $this->http->json(200, ['status' => 'OK', 'rows' => $rows]);
    }

    private function queueRoutesRefusal(): void
    {
        $this->http->json(403, ['error' => ['code' => 403, 'message' => 'Routes API has not been used in project 1 before or it is disabled.', 'status' => 'PERMISSION_DENIED']]);
    }

    /**
     * The origins and destinations of the request with this number, as point keys.
     *
     * @return array{0: list<array{0: int, 1: int}>, 1: list<array{0: int, 1: int}>}
     */
    private function asked(int $index): array
    {
        $request = $this->http->requests[$index];
        $keys = static function (array $waypoints): array {
            $out = [];
            foreach ($waypoints as $waypoint) {
                $at = $waypoint['waypoint']['location']['latLng'];
                $out[] = LegKey::of((float) $at['latitude'], (float) $at['longitude']);
            }
            return $out;
        };
        if ($request['method'] === 'POST') {
            $body = json_decode((string) $request['body'], true);
            return [$keys($body['origins']), $keys($body['destinations'])];
        }
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        $places = static function (string $list): array {
            $out = [];
            foreach (explode('|', $list) as $place) {
                [$lat, $lng] = explode(',', $place);
                $out[] = LegKey::of((float) $lat, (float) $lng);
            }
            return $out;
        };
        return [$places((string) $query['origins']), $places((string) $query['destinations'])];
    }

    /**
     * @return list<string> "routes" or "legacy" per request, in order
     */
    private function apis(): array
    {
        return array_map(
            static fn (array $request): string => str_starts_with($request['url'], self::ROUTES_URL) ? 'routes' : 'legacy',
            $this->http->requests
        );
    }

    /**
     * @param list<array<string, mixed>> $legs
     * @return list<string> "source" or "source/reason" per leg
     */
    private static function labels(array $legs): array
    {
        return array_map(
            static fn (array $leg): string => $leg['source'] . ($leg['fallback_reason'] === null ? '' : '/' . $leg['fallback_reason']),
            $legs
        );
    }

    /**
     * @param list<array<string, mixed>> $points
     * @return list<array<string, mixed>>
     */
    private function loop(array $points, array $options = [], ?array $truck = null, string $org = self::ORG): array
    {
        return $this->service()->legs($org, $truck ?? self::truck(), $points, RoutingService::pairs('loop', array_column($points, 'id')), $options);
    }

    // ---------------------------------------------------------------------------------------------------
    // The contract, and a server without a key
    // ---------------------------------------------------------------------------------------------------

    public function testItIsALegProviderThatNeedsNoArguments(): void
    {
        self::assertInstanceOf(LegProvider::class, new RoutingService());
    }

    public function testWithoutAKeyEveryLegIsALabelledStraightLine(): void
    {
        $this->noKey();
        $twin = ['id' => 'twin', 'lat' => 38.96004, 'lng' => -77.35996];          // the same rounded point as s1
        $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1, $twin], [['base', 's1'], ['s1', 'twin'], ['twin', 'base']]);

        self::assertSame(
            [
                StraightLineLegs::straightLine('base', 's1', self::BASE, self::S1, 'no_key'),
                StraightLineLegs::samePoint('s1', 'twin'),
                // the estimate is made from the exact point, not from the rounded one
                StraightLineLegs::straightLine('twin', 'base', $twin, self::BASE, 'no_key'),
            ],
            $legs
        );
        self::assertSame(['straight_line/no_key', 'same_point', 'straight_line/no_key'], self::labels($legs));
        self::assertSame('fallback', $legs[0]['leg_input']['source']);
        self::assertSame(Estimator::fallbackLeg(Seeds::defaults(), 39.003, -77.405, 38.96, -77.36), $legs[0]['leg_input']);
        self::assertEqualsWithDelta(8012.82, $legs[0]['distance_m'], 0.01);
        self::assertEqualsWithDelta(526.315, $legs[0]['duration_s'], 0.001);
        // a leg between one and the same place is handed to the model as a routed leg of no length
        self::assertSame(['source' => 'google', 'distance_m' => 0.0, 'duration_s' => 0.0, 'override_minutes' => null, 'toll' => 0.0], $legs[1]['leg_input']);

        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->tables->legs);
        self::assertSame([], $this->tables->written('INSERT INTO tp_drive_legs'));
        self::assertSame([], $this->tables->ledger);
        self::assertSame(['state' => 'no_key'], $this->service()->status());
        self::assertSame([], $this->store->values, 'without a key not even the guard is asked');
    }

    public function testALegOfAPointThatWasNotGivenIsAProgrammingError(): void
    {
        $this->expectException(\LogicException::class);
        $this->service()->legs(self::ORG, self::truck(), [self::BASE], [['base', 'nowhere']]);
    }

    public function testNoPairsNoLegs(): void
    {
        self::assertSame([], $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], []));
        self::assertSame([], $this->http->requests);
    }

    // ---------------------------------------------------------------------------------------------------
    // Google legs and the cache
    // ---------------------------------------------------------------------------------------------------

    public function testAGoogleLegIsFetchedStoredAndThenServedFromTheCache(): void
    {
        // two single legs, asked in ascending order of their origin: s1 (38.96) before the base (39.003)
        $this->queueRoutes([[0, 0, 713, 8100]]);
        $this->queueRoutes([[0, 0, 600, 7805]]);
        $legs = $this->loop([self::BASE, self::S1]);

        self::assertSame(['routes', 'routes'], $this->apis());
        self::assertSame([[self::S1_KEY], [self::BASE_KEY]], $this->asked(0));
        self::assertSame([[self::BASE_KEY], [self::S1_KEY]], $this->asked(1));
        self::assertSame(
            [
                'from_id' => 'base', 'to_id' => 's1', 'source' => 'google_routes', 'fetched_on' => '2026-10-05', 'age_days' => 0,
                'distance_m' => 7805.0, 'duration_s' => 600.0, 'toll_state' => 'none', 'google_toll' => null, 'toll_source' => 'none',
                'override' => null, 'fallback_reason' => null,
                'leg_input' => ['source' => 'google', 'distance_m' => 7805.0, 'duration_s' => 600.0, 'override_minutes' => null, 'toll' => 0.0],
            ],
            $legs[0]
        );
        self::assertSame(713.0, $legs[1]['duration_s']);
        self::assertSame(8100.0, $legs[1]['leg_input']['distance_m']);

        // what the cache holds is what Google said, under the rounded keys and the route key
        self::assertSame(['389600,-773600,390030,-774050|d', '390030,-774050,389600,-773600|d'], array_keys($this->tables->legs));
        self::assertSame(
            ['src' => 'google_routes', 'route_found' => 1, 'duration_s' => 600, 'distance_m' => 7805, 'toll_state' => 1, 'toll_cents' => null],
            array_intersect_key($this->tables->legs['390030,-774050,389600,-773600|d'], ['src' => 0, 'route_found' => 0, 'duration_s' => 0, 'distance_m' => 0, 'toll_state' => 0, 'toll_cents' => 0])
        );

        // three days later the same question costs nothing and says how old the answer is
        $this->clock->advance(3 * 86400 + 60);
        $again = $this->loop([self::BASE, self::S1]);
        self::assertCount(2, $this->http->requests);
        self::assertSame(3, $again[0]['age_days']);
        self::assertSame('2026-10-05', $again[0]['fetched_on']);
        self::assertSame($legs[0]['leg_input'], $again[0]['leg_input']);
    }

    public function testTheRequestThatReachesGoogleIsTheOneOfTheSpecification(): void
    {
        $this->queueRoutes([[0, 0, 600, 7805, ['travelAdvisory' => ['tollInfo' => ['estimatedPrice' => [['currencyCode' => 'USD', 'units' => '3', 'nanos' => 750000000]]]]]]]);
        // the exact points are not on the rounded grid: Google is asked about the rounded ones
        $legs = $this->service()->legs(
            self::ORG,
            self::truck(['avoid_highways' => true]),
            [['id' => 'base', 'lat' => 39.00304, 'lng' => -77.40496], ['id' => 's1', 'lat' => 38.95996, 'lng' => -77.36004]],
            [['base', 's1']]
        );
        $request = $this->http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame(self::ROUTES_URL, $request['url']);
        self::assertSame(
            [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . self::KEY,
                'X-Goog-FieldMask: originIndex,destinationIndex,status,condition,distanceMeters,duration,travelAdvisory.tollInfo',
            ],
            $request['headers']
        );
        self::assertSame(
            '{"origins":[{"waypoint":{"location":{"latLng":{"latitude":39.003,"longitude":-77.405}}},'
            . '"routeModifiers":{"avoidTolls":false,"avoidHighways":true}}],'
            . '"destinations":[{"waypoint":{"location":{"latLng":{"latitude":38.96,"longitude":-77.36}}}}],'
            . '"travelMode":"DRIVE","routingPreference":"TRAFFIC_UNAWARE","extraComputations":["TOLLS"]}',
            $request['body']
        );
        // Google's toll estimate pre-fills the toll
        self::assertSame('estimate', $legs[0]['toll_state']);
        self::assertSame(3.75, $legs[0]['google_toll']);
        self::assertSame('google', $legs[0]['toll_source']);
        self::assertSame(3.75, $legs[0]['leg_input']['toll']);
        // the routing options are part of the cache key
        self::assertSame(['390030,-774050,389600,-773600|dh'], array_keys($this->tables->legs));
        self::assertSame(375, $this->tables->legs['390030,-774050,389600,-773600|dh']['toll_cents']);
    }

    public function testALegIsCachedPerSetOfRoutingOptions(): void
    {
        $this->queueRoutes([[0, 0, 600, 7805]]);
        $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        // the same pair for a truck that avoids tolls is another question
        $this->queueRoutes([[0, 0, 900, 9100]]);
        $legs = $this->service()->legs(self::ORG, self::truck(['avoid_tolls' => true]), [self::BASE, self::S1], [['base', 's1']]);
        self::assertCount(2, $this->http->requests);
        self::assertSame(900.0, $legs[0]['duration_s']);
        self::assertSame(['avoidTolls' => true, 'avoidHighways' => false], json_decode((string) $this->http->requests[1]['body'], true)['origins'][0]['routeModifiers']);
        self::assertSame(['390030,-774050,389600,-773600|d', '390030,-774050,389600,-773600|dt'], array_keys($this->tables->legs));
        // and each is served from its own row afterwards
        self::assertSame(600.0, $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']])[0]['duration_s']);
        self::assertCount(2, $this->http->requests);
    }

    public function testTollsThatGoogleKnowsOfButCannotPriceAreUnknownNotZero(): void
    {
        $this->queueRoutes([[0, 0, 600, 7805, ['travelAdvisory' => ['tollInfo' => new \stdClass()]]]]);
        $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        self::assertSame('unknown', $legs[0]['toll_state']);
        self::assertNull($legs[0]['google_toll']);
        self::assertSame('none', $legs[0]['toll_source']);
        self::assertSame(0.0, $legs[0]['leg_input']['toll']);
    }

    public function testACachedLegWithoutTollInformationIsAskedAgainOnlyWhenTollsAreWanted(): void
    {
        // first without tolls: the cheaper request
        $this->queueRoutes([[0, 0, 600, 7805]]);
        $plain = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']], ['tolls' => false]);
        self::assertSame('not_asked', $plain[0]['toll_state']);
        self::assertStringNotContainsString('TOLLS', (string) $this->http->requests[0]['body']);
        self::assertSame('tp_routes_matrix', $this->tables->ledger[0]['sku']);

        // the cache alone, with tolls wanted: the leg that is there is served, as it is
        $cached = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']], ['tolls' => true, 'fetch' => false]);
        self::assertSame(['google_routes'], self::labels($cached));
        self::assertSame('not_asked', $cached[0]['toll_state']);
        self::assertCount(1, $this->http->requests);

        // with tolls wanted and fetching: asked again, with the toll computation
        $this->queueRoutes([[0, 0, 600, 7805, ['travelAdvisory' => ['tollInfo' => ['estimatedPrice' => [['currencyCode' => 'USD', 'units' => '1', 'nanos' => 250000000]]]]]]]);
        $tolled = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        self::assertCount(2, $this->http->requests);
        self::assertSame(1.25, $tolled[0]['google_toll']);
        self::assertSame('tp_routes_matrix_ent', $this->tables->ledger[1]['sku']);

        // and from then on either question is answered by the cache
        $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']], ['tolls' => false]);
        self::assertCount(2, $this->http->requests);
    }

    public function testNoRouteIsCachedAndServedAsAStraightLineThatSaysSo(): void
    {
        $this->http->json(200, [['status' => new \stdClass(), 'condition' => 'ROUTE_NOT_FOUND']]);
        $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']], ['tolls' => false]);
        self::assertEquals([StraightLineLegs::straightLine('base', 's1', self::BASE, self::S1, 'route_not_found')], $legs);
        self::assertSame(0, $this->tables->legs['390030,-774050,389600,-773600|d']['route_found']);
        self::assertSame('google_routes', $this->tables->legs['390030,-774050,389600,-773600|d']['src']);

        // Google is not asked again for 30 days, with or without tolls: there is no route to have a toll
        $again = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        self::assertSame(['straight_line/route_not_found'], self::labels($again));
        self::assertCount(1, $this->http->requests);
    }

    public function testWhenTheCacheCannotBeWrittenTheAnswerIsStillServed(): void
    {
        $this->tables->failOn('INSERT INTO tp_drive_legs');
        $this->queueRoutes([[0, 0, 600, 7805]]);
        $legs = [];
        $lines = LogCapture::during(function () use (&$legs): void {
            $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        });
        self::assertSame(['google_routes'], self::labels($legs));
        self::assertSame(600.0, $legs[0]['duration_s']);
        self::assertSame('2026-10-05', $legs[0]['fetched_on']);
        self::assertSame(0, $legs[0]['age_days']);
        self::assertSame(['[tp] drive legs were not cached: RuntimeException'], $lines);
        self::assertSame([], $this->tables->legs);
    }

    // ---------------------------------------------------------------------------------------------------
    // The batch plan
    // ---------------------------------------------------------------------------------------------------

    /**
     * @param list<array{0: array<int, int>, 1: array<int, int>}> $pairs [origin key, destination key]
     * @return array<string, array<int, int>>
     */
    private static function missing(array $pairs): array
    {
        $out = [];
        foreach ($pairs as [$o, $d]) {
            $pairKey = [$o[0], $o[1], $d[0], $d[1]];
            $out[DriveLegRepository::pairId($pairKey)] = $pairKey;
        }
        return $out;
    }

    public function testALoopIsAskedLegByLegInAscendingOrderOfTheOrigin(): void
    {
        $a = [10, 0];
        $b = [20, 0];
        $c = [30, 0];
        // c -> a, a -> b, b -> c: three pairs in a grid of nine
        $batches = RoutingService::batches(self::missing([[$c, $a], [$a, $b], [$b, $c]]));
        self::assertSame(
            [['o' => [$a], 'd' => [$b]], ['o' => [$b], 'd' => [$c]], ['o' => [$c], 'd' => [$a]]],
            $batches
        );
    }

    public function testADenseSetIsAskedAsOneGrid(): void
    {
        $a = [10, 0];
        $b = [20, 0];
        $c = [30, 0];
        // every ordered pair of three points: 6 of 9 elements, more than 0.6 of the grid
        $batches = RoutingService::batches(self::missing([[$a, $b], [$a, $c], [$b, $a], [$b, $c], [$c, $a], [$c, $b]]));
        self::assertSame([['o' => [$a, $b, $c], 'd' => [$a, $b, $c]]], $batches);
        // one pair alone is a grid of one
        self::assertSame([['o' => [$b], 'd' => [$a]]], RoutingService::batches(self::missing([[$b, $a]])));
        // five of nine are fewer than 0.6: stars
        self::assertGreaterThan(1, count(RoutingService::batches(self::missing([[$a, $b], [$a, $c], [$b, $a], [$b, $c], [$c, $a]]))));
    }

    public function testFromTheBaseToEverySpotAndBackIsTwoRequests(): void
    {
        $base = [500, 500];
        $pairs = [];
        $spots = [];
        for ($i = 1; $i <= 60; $i++) {
            $spots[] = [$i * 7, -$i];
            $pairs[] = [$base, [$i * 7, -$i]];
            $pairs[] = [[$i * 7, -$i], $base];
        }
        $batches = RoutingService::batches(self::missing($pairs));
        self::assertCount(2, $batches);
        // in ascending order of the first origin: the spots (from 7) come before the base (500)
        self::assertSame(['o' => $spots, 'd' => [$base]], $batches[0]);
        self::assertSame(['o' => [$base], 'd' => $spots], $batches[1]);
    }

    public function testTheLegsOfADayPlanAreAskedAsStarsWithoutAnyPairTwice(): void
    {
        // base and three stops: the loop and the legs that skip each stop (02_MODEL.md 4.11)
        $base = [0, 0];
        $x = [1, 0];
        $y = [2, 0];
        $z = [3, 0];
        $wanted = [[$base, $x], [$x, $y], [$y, $z], [$z, $base], [$base, $y], [$x, $z], [$y, $base]];
        $batches = RoutingService::batches(self::missing($wanted));
        $covered = [];
        foreach ($batches as $batch) {
            self::assertTrue(count($batch['o']) === 1 || count($batch['d']) === 1, 'a star has one centre');
            foreach ($batch['o'] as $o) {
                foreach ($batch['d'] as $d) {
                    $covered[] = DriveLegRepository::pairId([$o[0], $o[1], $d[0], $d[1]]);
                }
            }
        }
        $expected = array_keys(self::missing($wanted));
        sort($expected);
        sort($covered);
        self::assertSame($expected, $covered, 'exactly the missing pairs, each once');
        self::assertLessThanOrEqual(4, count($batches));
        // first origins never go down
        $firsts = array_map(static fn (array $batch): array => $batch['o'][0], $batches);
        $sorted = $firsts;
        sort($sorted);
        self::assertSame($sorted, $firsts);
    }

    public function testAGridLargerThan25By25IsCutIntoChunks(): void
    {
        $points = [];
        for ($i = 0; $i < 26; $i++) {
            $points[] = [100 + $i, 0];
        }
        $pairs = [];
        foreach ($points as $o) {
            foreach ($points as $d) {
                if ($o !== $d) {
                    $pairs[] = [$o, $d];
                }
            }
        }
        self::assertCount(650, $pairs);
        $sizes = array_map(
            static fn (array $batch): array => [count($batch['o']), count($batch['d'])],
            RoutingService::batches(self::missing($pairs))
        );
        self::assertSame([[25, 25], [25, 1], [1, 25], [1, 1]], $sizes);
    }

    public function testAStarWithMoreThan625LeavesIsCut(): void
    {
        $base = [0, 0];
        $out = [];
        $back = [];
        for ($i = 1; $i <= 630; $i++) {
            $out[] = [$base, [$i, 0]];
            $back[] = [[$i, 0], $base];
        }
        $sizes = static fn (array $batches): array => array_map(static fn (array $b): array => [count($b['o']), count($b['d'])], $batches);
        // one origin and 630 destinations: 625 elements fit one request, whatever its shape
        self::assertSame([[1, 625], [1, 5]], $sizes(RoutingService::batches(self::missing($out))));
        self::assertSame([[625, 1], [5, 1]], $sizes(RoutingService::batches(self::missing($back))));
        // there and back: two stars of 630, four requests
        self::assertSame([[1, 625], [1, 5], [625, 1], [5, 1]], $sizes(RoutingService::batches(self::missing(array_merge($out, $back)))));
    }

    public function testAGridIsCutToWhatAnApiTakes(): void
    {
        $points = static function (int $n, int $offset = 0): array {
            $out = [];
            for ($i = 0; $i < $n; $i++) {
                $out[] = [$offset + $i, 0];
            }
            return $out;
        };
        $sizes = static fn (array $parts): array => array_map(static fn (array $p): array => [count($p['o']), count($p['d'])], $parts);
        // Routes: 625 elements a request, no limit on a side
        $routes = static fn (int $o, int $d): array => $sizes(RoutingService::parts($points($o), $points($d, 1000), 25, 625, 625));
        self::assertSame([[1, 1]], $routes(1, 1));
        self::assertSame([[25, 25]], $routes(25, 25));
        self::assertSame([[3, 208], [3, 92]], $routes(3, 300));
        self::assertSame([[208, 3], [92, 3]], $routes(300, 3));
        self::assertSame([[30, 20]], $routes(30, 20));
        self::assertSame([[25, 25], [25, 3], [5, 25], [5, 3]], $routes(30, 28));
        // the legacy API: 100 elements a request and 25 points on a side
        $legacy = static fn (int $o, int $d): array => $sizes(RoutingService::parts($points($o), $points($d, 1000), 10, 100, 25));
        self::assertSame([[1, 25], [1, 25], [1, 10]], $legacy(1, 60));
        self::assertSame([[25, 1], [5, 1]], $legacy(30, 1));
        self::assertSame([[4, 25], [4, 5]], $legacy(4, 30));
        self::assertSame([[5, 20]], $legacy(5, 20));
        self::assertSame([[10, 10], [10, 2], [2, 10], [2, 2]], $legacy(12, 12));
        // every part keeps to the limits, and together they are the grid
        foreach ([[1, 700], [7, 90], [26, 26], [60, 60], [100, 3]] as [$o, $d]) {
            foreach ([[25, 625, 625], [10, 100, 25]] as [$side, $most, $longest]) {
                $parts = RoutingService::parts($points($o), $points($d, 1000), $side, $most, $longest);
                $elements = 0;
                foreach ($parts as $part) {
                    self::assertLessThanOrEqual($most, count($part['o']) * count($part['d']));
                    self::assertLessThanOrEqual($longest, max(count($part['o']), count($part['d'])));
                    $elements += count($part['o']) * count($part['d']);
                }
                self::assertSame($o * $d, $elements);
            }
        }
        self::assertSame([], RoutingService::parts([], $points(3), 25, 625, 625));
    }

    public function testADenseSetCostsOneRequestAndTheDiagonalIsNotStored(): void
    {
        $this->queueRoutesGrid(3, 3);
        $points = [self::BASE, self::S1, self::S2];
        $legs = $this->service()->legs(self::ORG, self::truck(), $points, RoutingService::pairs('matrix', ['base', 's1', 's2']), ['tolls' => false]);

        self::assertCount(1, $this->http->requests);
        // origins and destinations in ascending key order: s2 (38.9072), s1 (38.96), base (39.003)
        self::assertSame([[self::S2_KEY, self::S1_KEY, self::BASE_KEY], [self::S2_KEY, self::S1_KEY, self::BASE_KEY]], $this->asked(0));
        self::assertCount(6, $legs);
        self::assertSame(array_fill(0, 6, 'google_routes'), self::labels($legs));
        // base -> s1 is origin 2, destination 1 of the grid
        self::assertSame(121.0, $legs[0]['duration_s']);
        self::assertSame(1201.0, $legs[0]['distance_m']);
        // six legs are stored: a point to itself is not a leg
        self::assertCount(6, $this->tables->legs);
        // Google bills the nine elements it was asked for, and so do the ledger and the budget
        self::assertSame(9, $this->tables->ledger[0]['billable_units']);
        self::assertSame(3000 - 9, $this->guard->orgElementsLeft(self::ORG));
        self::assertSame([['tp_routes_elements', 9, 2]], $this->bucketCalls);
    }

    public function testWhatAGridAnswersBeyondTheQuestionIsKeptForLater(): void
    {
        // base -> s1, base -> s2 and s3 -> s1 are wanted: three of the four elements of a 2 x 2 grid
        $this->queueRoutesGrid(2, 2);
        $points = [self::BASE, self::S1, self::S2, self::S3];
        $legs = $this->service()->legs(self::ORG, self::truck(), $points, [['base', 's1'], ['base', 's2'], ['s3', 's1']], ['tolls' => false]);
        self::assertSame(['google_routes', 'google_routes', 'google_routes'], self::labels($legs));
        self::assertCount(1, $this->http->requests);
        self::assertCount(4, $this->tables->legs);
        // the fourth element, s3 -> s2, answers a later question without a request
        $later = $this->service()->legs(self::ORG, self::truck(), $points, [['s3', 's2']], ['tolls' => false]);
        self::assertSame(['google_routes'], self::labels($later));
        self::assertCount(1, $this->http->requests);
    }

    public function testEveryCandidateBackToTheBaseIsOneRequest(): void
    {
        $spots = [self::S1, self::S2, self::S3, self::S4, self::S5];
        $pairs = [];
        foreach ($spots as $spot) {
            $pairs[] = ['base', $spot['id']];
            $pairs[] = [$spot['id'], 'base'];
        }
        // ascending first origin: the five spots to the base (s4 at 38.8048 is the smallest), then the base to the five
        $this->queueRoutesGrid(5, 1);
        $this->queueRoutesGrid(1, 5);
        $legs = $this->service()->legs(self::ORG, self::truck(), array_merge([self::BASE], $spots), $pairs, ['tolls' => false]);
        self::assertCount(2, $this->http->requests);
        self::assertSame([5, 1], array_map('count', $this->asked(0)));
        self::assertSame([1, 5], array_map('count', $this->asked(1)));
        self::assertSame(array_fill(0, 10, 'google_routes'), self::labels($legs));
        self::assertCount(10, $this->tables->legs);
    }

    // ---------------------------------------------------------------------------------------------------
    // Thirty days
    // ---------------------------------------------------------------------------------------------------

    public function testALegOlderThanThirtyDaysIsNeverServedAndIsGoneAfterTheCall(): void
    {
        $this->noKey();
        $this->tables->seedLeg([390030, -774050, 389600, -773600], 'd', 31 * 86400);
        $this->tables->seedLeg([389600, -773600, 390030, -774050], 'd', 30 * 86400 - 1);
        self::assertCount(2, $this->tables->legs);

        $legs = $this->loop([self::BASE, self::S1]);
        // Google cannot be reached (no key): the 31-day-old answer is still not used
        self::assertSame(['straight_line/no_key', 'google_routes'], self::labels($legs));
        self::assertSame(29, $legs[1]['age_days']);
        self::assertSame(['389600,-773600,390030,-774050|d'], array_keys($this->tables->legs), 'the expired row is deleted, not only hidden');
        self::assertSame(
            'DELETE FROM tp_drive_legs WHERE fetched_at < NOW() - INTERVAL 30 DAY LIMIT 500',
            $this->tables->written('DELETE FROM tp_drive_legs')[0]
        );
    }

    public function testTheLastSecondOfTheThirtiethDayAndTheFirstAfterIt(): void
    {
        $this->noKey();
        $this->tables->seedLeg([390030, -774050, 389600, -773600], 'd', 30 * 86400);
        $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        self::assertSame(['google_routes'], self::labels($legs));
        self::assertSame(30, $legs[0]['age_days']);
        self::assertCount(1, $this->tables->legs);

        $this->clock->advance(1);
        $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        self::assertSame(['straight_line/no_key'], self::labels($legs));
        self::assertSame([], $this->tables->legs);
    }

    public function testAnExpiredLegIsAskedForAgain(): void
    {
        $this->tables->seedLeg([390030, -774050, 389600, -773600], 'd', 45 * 86400, ['duration_s' => 111]);
        $this->queueRoutes([[0, 0, 640, 7805]]);
        $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        self::assertSame(640.0, $legs[0]['duration_s']);
        self::assertSame(0, $legs[0]['age_days']);
        self::assertCount(1, $this->tables->legs);
        self::assertSame(640, $this->tables->legs['390030,-774050,389600,-773600|d']['duration_s']);
    }

    public function testEveryCallEndsWithThePurgeOfAtMostFiveHundredRows(): void
    {
        $this->noKey();
        for ($i = 0; $i < 600; $i++) {
            $this->tables->seedLeg([100000 + $i, 0, 200000, 0], 'd', 40 * 86400);
        }
        // a call that wants nothing still purges
        $this->service()->legs(self::ORG, self::truck(), [self::BASE], []);
        self::assertCount(100, $this->tables->legs);
        $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        self::assertSame([], $this->tables->legs);
        // and with nothing expired nothing is deleted
        $this->tables->statements = [];
        $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        self::assertSame([], $this->tables->written('DELETE'));
        self::assertStringStartsWith('SELECT COUNT(*) AS expired_legs', (string) end($this->tables->statements));
    }

    public function testAPurgeThatFailsDoesNotFailTheCall(): void
    {
        $this->noKey();
        $this->tables->failOn('COUNT(*) AS expired_legs');
        $legs = [];
        $lines = LogCapture::during(function () use (&$legs): void {
            $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        });
        self::assertSame(['straight_line/no_key'], self::labels($legs));
        self::assertSame(['[tp] expired drive legs were not deleted: RuntimeException'], $lines);
    }

    // ---------------------------------------------------------------------------------------------------
    // Refusals
    // ---------------------------------------------------------------------------------------------------

    public function testARefusalOfRoutesIsRememberedForAnHourWithExactlyOneLegacyAttempt(): void
    {
        $this->queueRoutesRefusal();
        $this->queueLegacyGrid(1, 1);
        $legs = [];
        LogCapture::during(function () use (&$legs): void {
            $legs = $this->service()->legs(self::ORG, self::truck(['avoid_tolls' => true]), [self::BASE, self::S1], [['base', 's1']]);
        });
        // one request to Routes, then exactly one to the legacy API for the same batch
        self::assertSame(['routes', 'legacy'], $this->apis());
        self::assertSame([[self::BASE_KEY], [self::S1_KEY]], $this->asked(1));
        self::assertStringContainsString('avoid=tolls', $this->http->requests[1]['url']);
        self::assertSame(
            [
                'from_id' => 'base', 'to_id' => 's1', 'source' => 'google_distance_matrix', 'fetched_on' => '2026-10-05', 'age_days' => 0,
                'distance_m' => 9000.0, 'duration_s' => 700.0, 'toll_state' => 'not_asked', 'google_toll' => null, 'toll_source' => 'none',
                'override' => null, 'fallback_reason' => null,
                'leg_input' => ['source' => 'google', 'distance_m' => 9000.0, 'duration_s' => 700.0, 'override_minutes' => null, 'toll' => 0.0],
            ],
            $legs[0]
        );
        self::assertSame('google_distance_matrix', $this->tables->legs['390030,-774050,389600,-773600|dt']['src']);
        self::assertSame(3600 + 93600, $this->store->ttls['tp:routes:refused:routes']);
        self::assertSame(['state' => 'ok'], $this->service()->status(), 'the legacy API still answers');

        // within the hour: Routes is not asked. The legacy leg answers, although tolls are wanted and it has none
        $this->clock->advance(3599);
        $this->service()->legs(self::ORG, self::truck(['avoid_tolls' => true]), [self::BASE, self::S1], [['base', 's1']]);
        self::assertCount(2, $this->http->requests);
        // a new pair goes straight to the legacy API
        $this->queueLegacyGrid(1, 1);
        $this->service()->legs(self::ORG, self::truck(['avoid_tolls' => true]), [self::BASE, self::S2], [['base', 's2']]);
        self::assertSame(['routes', 'legacy', 'legacy'], $this->apis());

        // after the hour Routes is asked again, also for the legs the legacy API answered without tolls
        $this->clock->advance(1);
        $this->queueRoutes([[0, 0, 610, 7805]]);
        $after = $this->service()->legs(self::ORG, self::truck(['avoid_tolls' => true]), [self::BASE, self::S1], [['base', 's1']]);
        self::assertSame(['routes', 'legacy', 'legacy', 'routes'], $this->apis());
        self::assertSame(['google_routes'], self::labels($after));
        self::assertSame('none', $after[0]['toll_state']);
    }

    public function testWhenBothRefuseEveryLegIsAStraightLineAndNothingIsAskedForAnHour(): void
    {
        $this->queueRoutesRefusal();
        $this->http->json(200, ['status' => 'REQUEST_DENIED', 'error_message' => 'This API project is not authorized to use this API.', 'rows' => []]);
        $legs = [];
        LogCapture::during(function () use (&$legs): void {
            // three single legs: the first meets both refusals, the others are not sent at all
            $legs = $this->loop([self::BASE, self::S1, self::S2]);
        });
        self::assertSame(['routes', 'legacy'], $this->apis());
        self::assertSame(['straight_line/refused', 'straight_line/refused', 'straight_line/refused'], self::labels($legs));
        self::assertEquals(StraightLineLegs::straightLine('base', 's1', self::BASE, self::S1, 'refused'), $legs[0]);
        self::assertSame([], $this->tables->legs);
        self::assertSame(['state' => 'refused'], $this->service()->status());

        // for an hour nobody asks Google
        $this->clock->advance(3599);
        self::assertSame(['straight_line/refused'], self::labels($this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']])));
        self::assertSame(['straight_line/refused'], self::labels($this->service()->legs(self::OTHER_ORG, self::truck([], self::OTHER_TRUCK), [self::BASE, self::S2], [['base', 's2']])));
        self::assertCount(2, $this->http->requests);

        // then Routes is tried again
        $this->clock->advance(1);
        self::assertSame(['state' => 'ok'], $this->service()->status());
        $this->queueRoutes([[0, 0]]);
        self::assertSame(['google_routes'], self::labels($this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']])));
        self::assertSame(['routes', 'legacy', 'routes'], $this->apis());
    }

    public function testARefusalInTheMiddleOfACallSendsTheRestToTheLegacyApi(): void
    {
        // three single legs in ascending order of the origin: s2 -> base, s1 -> s2, base -> s1
        $this->queueRoutes([[0, 0, 500, 5000]]);
        $this->queueRoutesRefusal();
        $this->queueLegacyGrid(1, 1);
        $this->queueLegacyGrid(1, 1);
        $legs = [];
        LogCapture::during(function () use (&$legs): void {
            $legs = $this->loop([self::BASE, self::S1, self::S2]);
        });
        self::assertSame(['routes', 'routes', 'legacy', 'legacy'], $this->apis());
        // pair order: base -> s1, s1 -> s2, s2 -> base
        self::assertSame(['google_distance_matrix', 'google_distance_matrix', 'google_routes'], self::labels($legs));
    }

    public function testTheLegacyApiIsAskedInPartsOfAtMostTenByTen(): void
    {
        // twelve points, every ordered pair: one Routes request of 12 x 12, refused
        $points = [];
        for ($i = 0; $i < 12; $i++) {
            $points[] = ['id' => 'p' . $i, 'lat' => 38.0 + $i / 100.0, 'lng' => -77.0];
        }
        $this->queueRoutesRefusal();
        $this->queueLegacyGrid(10, 10);
        $this->queueLegacyGrid(10, 2);
        $this->queueLegacyGrid(2, 10);
        $this->queueLegacyGrid(2, 2);
        $legs = [];
        LogCapture::during(function () use (&$legs, $points): void {
            $legs = $this->service()->legs(self::ORG, self::truck(), $points, RoutingService::pairs('matrix', array_column($points, 'id')));
        });
        self::assertSame(['routes', 'legacy', 'legacy', 'legacy', 'legacy'], $this->apis());
        self::assertSame([[12, 12], [10, 10], [10, 2], [2, 10], [2, 2]], array_map(fn (int $i): array => array_map('count', $this->asked($i)), [0, 1, 2, 3, 4]));
        self::assertCount(132, $legs);
        self::assertSame(['google_distance_matrix'], array_values(array_unique(self::labels($legs))));
        self::assertCount(132, $this->tables->legs);
        self::assertSame(0, $this->http->pending());
    }

    public function testAStarGoesToTheLegacyApiInPartsOfTwentyFive(): void
    {
        // the base to thirty places, while Routes is refused
        $this->guard->markRefused('routes');
        $points = [self::BASE];
        $pairs = [];
        for ($i = 0; $i < 30; $i++) {
            $points[] = ['id' => 'p' . $i, 'lat' => 38.0 + $i / 100.0, 'lng' => -77.0];
            $pairs[] = ['base', 'p' . $i];
        }
        $this->queueLegacyGrid(1, 25);
        $this->queueLegacyGrid(1, 5);
        $legs = $this->service()->legs(self::ORG, self::truck(), $points, $pairs);
        self::assertSame(['legacy', 'legacy'], $this->apis());
        self::assertSame([[1, 25], [1, 5]], [array_map('count', $this->asked(0)), array_map('count', $this->asked(1))]);
        self::assertSame(array_fill(0, 30, 'google_distance_matrix'), self::labels($legs));
    }

    // ---------------------------------------------------------------------------------------------------
    // Every reason a leg can be a straight line
    // ---------------------------------------------------------------------------------------------------

    public function testQuotaStopsTheCallAndTheNextTwoMinutes(): void
    {
        $this->http->json(429, ['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']]);
        $legs = [];
        LogCapture::during(function () use (&$legs): void {
            $legs = $this->loop([self::BASE, self::S1, self::S2]);
        });
        // the first batch meets the quota, the others are not sent
        self::assertCount(1, $this->http->requests);
        self::assertSame(['straight_line/quota', 'straight_line/quota', 'straight_line/quota'], self::labels($legs));
        self::assertSame(120 + 93600, $this->store->ttls['tp:routes:backoff']);
        self::assertSame(['state' => 'backoff'], $this->service()->status());

        $this->clock->advance(119);
        self::assertSame(['straight_line/quota'], self::labels($this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']])));
        self::assertCount(1, $this->http->requests);

        $this->clock->advance(1);
        $this->queueRoutes([[0, 0]]);
        self::assertSame(['google_routes'], self::labels($this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']])));
        self::assertSame(['state' => 'ok'], $this->service()->status());
    }

    public function testAQuotaAnswerOfTheLegacyApiBacksOffTheSameWay(): void
    {
        $this->guard->markRefused('routes');
        $this->http->json(200, ['status' => 'OVER_QUERY_LIMIT', 'rows' => []]);
        $legs = [];
        LogCapture::during(function () use (&$legs): void {
            $legs = $this->loop([self::BASE, self::S1]);
        });
        self::assertSame(['legacy'], $this->apis());
        self::assertSame(['straight_line/quota', 'straight_line/quota'], self::labels($legs));
        self::assertSame('quota', $this->guard->backoffReason('routes'));
    }

    public function testAServerErrorIsUpstreamAndBacksOffForThirtySeconds(): void
    {
        $this->http->queue(503, 'Service Unavailable');
        $legs = [];
        LogCapture::during(function () use (&$legs): void {
            $legs = $this->loop([self::BASE, self::S1, self::S2]);
        });
        self::assertCount(1, $this->http->requests, 'no retry, and the other batches are not sent');
        self::assertSame(['straight_line/upstream', 'straight_line/upstream', 'straight_line/upstream'], self::labels($legs));
        self::assertSame(30 + 93600, $this->store->ttls['tp:routes:backoff']);
        self::assertSame('upstream', $this->guard->backoffReason('routes'));

        $this->clock->advance(29);
        self::assertSame(['straight_line/upstream'], self::labels($this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']])));
        $this->clock->advance(1);
        $this->queueRoutes([[0, 0]]);
        self::assertSame(['google_routes'], self::labels($this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']])));
    }

    public function testABodyThatCannotBeReadIsUpstream(): void
    {
        $this->http->queue(200, '<html>maintenance</html>');
        $legs = [];
        LogCapture::during(function () use (&$legs): void {
            $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
        });
        self::assertSame(['straight_line/upstream'], self::labels($legs));
        self::assertTrue($this->guard->inBackoff('routes'));
    }

    public function testATimeOutIsNamedAndTheRestOfTheCallWaits(): void
    {
        $this->http->fail('timeout');
        $legs = [];
        LogCapture::during(function () use (&$legs): void {
            // ascending origin: s1 -> base is the batch that times out, base -> s1 is not sent
            $legs = $this->loop([self::BASE, self::S1]);
        });
        self::assertCount(1, $this->http->requests);
        self::assertSame(['straight_line/upstream', 'straight_line/timeout'], self::labels($legs));
        self::assertSame('upstream', $this->guard->backoffReason('routes'));
    }

    public function testACallSpendsAtMostTwelveSecondsOnGoogle(): void
    {
        // each request takes five seconds: after the third, more than twelve are gone
        $this->secondsPerRequest = 5.0;
        $spots = [self::S1, self::S2, self::S3, self::S4, self::S5];
        foreach ($spots as $spot) {
            $this->queueRoutes([[0, 0]]);
        }
        $points = array_merge([self::BASE], $spots);
        // six single legs around the loop
        $legs = $this->loop($points);
        self::assertCount(3, $this->http->requests);
        $labels = self::labels($legs);
        self::assertSame(3, count(array_keys($labels, 'google_routes', true)));
        self::assertSame(3, count(array_keys($labels, 'straight_line/timeout', true)));
        // running out of time is nobody's failure: no back-off
        self::assertFalse($this->guard->inBackoff('routes'));
        self::assertSame(2, $this->http->pending());
    }

    public function testABadRequestIsUpstreamForItsBatchOnly(): void
    {
        $this->http->json(400, ['error' => ['code' => 400, 'message' => 'Invalid waypoint', 'status' => 'INVALID_ARGUMENT']]);
        $this->queueRoutes([[0, 0]]);
        $legs = [];
        $lines = LogCapture::during(function () use (&$legs): void {
            $legs = $this->loop([self::BASE, self::S1]);
        });
        self::assertCount(2, $this->http->requests, 'the next batch is still sent');
        self::assertSame(['google_routes', 'straight_line/upstream'], self::labels($legs));
        self::assertFalse($this->guard->inBackoff('routes'));
        self::assertSame(['[tp] routes bad request: Invalid waypoint'], $lines);
    }

    public function testASecondBadRequestInOneCallStopsTheRest(): void
    {
        $bad = ['error' => ['code' => 400, 'message' => 'Invalid waypoint', 'status' => 'INVALID_ARGUMENT']];
        $this->http->json(400, $bad);
        $this->http->json(400, $bad);
        $legs = [];
        LogCapture::during(function () use (&$legs): void {
            // three single legs: the third is not sent any more
            $legs = $this->loop([self::BASE, self::S1, self::S2]);
        });
        self::assertCount(2, $this->http->requests);
        self::assertSame(['straight_line/upstream', 'straight_line/upstream', 'straight_line/upstream'], self::labels($legs));
        self::assertSame('upstream', $this->guard->backoffReason('routes'));
        self::assertSame([], $this->tables->legs);
    }

    public function testAKeyGoogleDoesNotKnowIsARefusalAndIsNotTriedAgainForAnHour(): void
    {
        // Routes says "invalid argument" with the reason API_KEY_INVALID; the legacy API says REQUEST_DENIED
        $this->http->json(400, ['error' => [
            'code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID']],
        ]]);
        $this->http->json(200, ['status' => 'REQUEST_DENIED', 'error_message' => 'The provided API key is invalid.', 'rows' => []]);
        $legs = [];
        $lines = LogCapture::during(function () use (&$legs): void {
            $legs = $this->loop([self::BASE, self::S1, self::S2]);
        });
        self::assertSame(['routes', 'legacy'], $this->apis());
        self::assertSame(['straight_line/refused', 'straight_line/refused', 'straight_line/refused'], self::labels($legs));
        self::assertSame(['state' => 'refused'], $this->service()->status());
        self::assertSame(
            ['[tp] routes refused: INVALID_ARGUMENT API_KEY_INVALID', '[tp] distance matrix refused: REQUEST_DENIED The provided API key is invalid.'],
            $lines
        );
        $this->clock->advance(1800);
        $this->loop([self::BASE, self::S1, self::S2]);
        self::assertCount(2, $this->http->requests);
    }

    public function testAnElementGoogleCouldNotComputeIsUpstreamAndTheOthersAreServed(): void
    {
        // a 3 x 3 grid in which one element failed and one is missing altogether
        $cells = [];
        for ($o = 0; $o < 3; $o++) {
            for ($d = 0; $d < 3; $d++) {
                $cells[] = [$o, $d];
            }
        }
        // origin 2 (the base) to destination 1 (s1) failed; origin 1 (s1) to destination 0 (s2) is not there
        $cells[2 * 3 + 1][4] = ['status' => ['code' => 13, 'message' => 'internal'], 'condition' => 'ROUTE_MATRIX_ELEMENT_CONDITION_UNSPECIFIED'];
        unset($cells[1 * 3 + 0]);
        $this->queueRoutes(array_values($cells));
        $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1, self::S2], RoutingService::pairs('matrix', ['base', 's1', 's2']));
        self::assertSame(
            ['base>s1' => 'straight_line/upstream', 'base>s2' => 'google_routes', 's1>base' => 'google_routes',
                's1>s2' => 'straight_line/upstream', 's2>base' => 'google_routes', 's2>s1' => 'google_routes'],
            array_combine(array_map(static fn (array $leg): string => $leg['from_id'] . '>' . $leg['to_id'], $legs), self::labels($legs))
        );
        self::assertCount(4, $this->tables->legs, 'what failed is not stored');
        self::assertFalse($this->guard->inBackoff('routes'), 'the call itself succeeded');
    }

    public function testTheDailyBudgetOfAnOrganization(): void
    {
        $this->guard->spendOrgElements(self::ORG, 2999);
        // two single legs: the first fits the last element of the day, the second does not
        $this->queueRoutes([[0, 0]]);
        $legs = $this->loop([self::BASE, self::S1]);
        self::assertCount(1, $this->http->requests);
        self::assertSame(['straight_line/budget', 'google_routes'], self::labels($legs));
        self::assertSame(0, $this->guard->orgElementsLeft(self::ORG));
        // another organization has a budget of its own
        $this->queueRoutes([[0, 0]]);
        self::assertSame(['google_routes'], self::labels($this->service()->legs(self::OTHER_ORG, self::truck([], self::OTHER_TRUCK), [self::BASE, self::S2], [['base', 's2']])));
        // the next UTC day the count starts again
        $this->clock->set('2026-10-06 00:00:00');
        self::assertSame(3000, $this->guard->orgElementsLeft(self::ORG));
    }

    public function testTheDailyBudgetOfTheServer(): void
    {
        // 19,999 elements were fetched today by all tenants together
        $this->tables->unitsBefore = 19999;
        $this->queueRoutes([[0, 0]]);
        $legs = $this->loop([self::BASE, self::S1]);
        self::assertCount(1, $this->http->requests);
        self::assertSame(['straight_line/budget', 'google_routes'], self::labels($legs));
        // the element that was just fetched is in the ledger: now nothing is left for anybody
        self::assertSame(0, $this->guard->globalElementsLeft());
        self::assertSame(['straight_line/budget'], self::labels($this->service()->legs(self::OTHER_ORG, self::truck([], self::OTHER_TRUCK), [self::BASE, self::S2], [['base', 's2']])));
        self::assertCount(1, $this->http->requests);
    }

    public function testTheSpendingAllowanceOfTheDay(): void
    {
        // Settings: 5 dollars a day. 4.975 are spent, so 2.5 cents are left: one element with toll estimates
        // (1.5 cents) fits, a second does not.
        $this->tables->usdToday = 4.975;
        $this->queueRoutes([[0, 0]]);
        $legs = $this->loop([self::BASE, self::S1]);
        self::assertCount(1, $this->http->requests);
        self::assertSame(['straight_line/budget', 'google_routes'], self::labels($legs));
        self::assertEqualsWithDelta(0.01, $this->guard->spendLeftUsd(), 1e-9);

        // One cent is left for everybody: an element without toll estimates (half a cent) still fits...
        $other = self::truck([], self::OTHER_TRUCK);
        $this->queueRoutes([[0, 0]]);
        self::assertSame(['google_routes'], self::labels($this->service()->legs(self::OTHER_ORG, $other, [self::BASE, self::S2], [['base', 's2']], ['tolls' => false])));
        self::assertCount(2, $this->http->requests);
        // ...and one with them does not, whoever asks.
        self::assertSame(['straight_line/budget'], self::labels($this->service()->legs(self::OTHER_ORG, $other, [self::BASE, self::S2], [['s2', 'base']])));
        self::assertCount(2, $this->http->requests);
    }

    public function testTheSpendingAllowanceOfTheMonth(): void
    {
        // Settings: 40 dollars a month. 39.975 went earlier this month and nothing yet today.
        $this->tables->usdEarlierThisMonth = 39.975;
        $this->queueRoutes([[0, 0]]);
        $legs = $this->loop([self::BASE, self::S1]);
        self::assertCount(1, $this->http->requests);
        self::assertSame(['straight_line/budget', 'google_routes'], self::labels($legs));
    }

    public function testAnAllowanceOfZeroAsksGoogleForNothing(): void
    {
        $before = getenv('TP_GOOGLE_DAILY_USD');
        putenv('TP_GOOGLE_DAILY_USD=0');
        try {
            $legs = $this->loop([self::BASE, self::S1]);
            self::assertSame([], $this->http->requests);
            self::assertSame(['straight_line/budget', 'straight_line/budget'], self::labels($legs));
            self::assertSame([], $this->tables->legs, 'nothing to cache');
            self::assertSame([], $this->tables->ledger, 'and nothing to meter');
        } finally {
            putenv($before === false ? 'TP_GOOGLE_DAILY_USD' : 'TP_GOOGLE_DAILY_USD=' . $before);
        }
    }

    public function testACallFetchesAtMost650Elements(): void
    {
        // 26 points, every ordered pair: 650 legs in a grid of 676 elements, cut into 625 + 25 + 25 + 1
        $points = [];
        for ($i = 0; $i < 26; $i++) {
            $points[] = ['id' => 'p' . $i, 'lat' => 38.0 + $i / 100.0, 'lng' => -77.0];
        }
        $this->queueRoutesGrid(25, 25);
        $this->queueRoutesGrid(25, 1);
        $legs = $this->service()->legs(self::ORG, self::truck(), $points, RoutingService::pairs('matrix', array_column($points, 'id')), ['tolls' => false]);
        self::assertCount(2, $this->http->requests, 'the third batch would be the 651st to 675th element');
        self::assertCount(650, $legs);
        $labels = array_count_values(self::labels($legs));
        self::assertSame(['google_routes' => 625, 'straight_line/budget' => 25], $labels);
        // the legs that were left out are those of the last origin
        foreach ($legs as $leg) {
            self::assertSame($leg['from_id'] === 'p25', $leg['source'] === 'straight_line');
        }
        self::assertSame([625, 25], array_column($this->tables->ledger, 'billable_units'));
        self::assertSame(3000 - 650, $this->guard->orgElementsLeft(self::ORG));
    }

    public function testAnEmptyTokenBucketIsRate(): void
    {
        $this->bucketAnswer = false;
        $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1, self::S2], RoutingService::pairs('matrix', ['base', 's1', 's2']));
        self::assertSame([], $this->http->requests);
        self::assertSame(array_fill(0, 6, 'straight_line/rate'), self::labels($legs));
        // the bucket of matrix elements was asked for the nine elements of the grid, waiting two seconds at most
        self::assertSame([['tp_routes_elements', 9, 2]], $this->bucketCalls);
        self::assertSame(3000, $this->guard->orgElementsLeft(self::ORG), 'nothing was fetched, nothing is spent');
    }

    public function testTheCacheAloneIsCacheOnly(): void
    {
        $this->tables->seedLeg([390030, -774050, 389600, -773600], 'd', 86400);
        $legs = $this->loop([self::BASE, self::S1], ['fetch' => false]);
        self::assertSame(['google_routes', 'straight_line/cache_only'], self::labels($legs));
        self::assertSame(1, $legs[0]['age_days']);
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->bucketCalls);
        self::assertSame([], $this->store->values, 'nothing is asked of the guard');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function reasons(): array
    {
        return [
            'no key' => ['no_key'], 'both APIs refuse' => ['refused'], 'quota' => ['quota'], 'budget' => ['budget'],
            'rate' => ['rate'], 'time-out' => ['timeout'], 'upstream' => ['upstream'], 'cache only' => ['cache_only'],
        ];
    }

    /**
     * @dataProvider reasons
     */
    public function testAStraightLineIsLabelledWithItsReasonAndNeverWrittenToTheCache(string $reason): void
    {
        $options = [];
        switch ($reason) {
            case 'no_key':
                $this->noKey();
                break;
            case 'refused':
                $this->guard->markRefused('routes');
                $this->guard->markRefused('legacy');
                break;
            case 'quota':
                $this->guard->backoff('routes', 120, 'quota');
                break;
            case 'budget':
                $this->guard->spendOrgElements(self::ORG, 3000);
                break;
            case 'rate':
                $this->bucketAnswer = false;
                break;
            case 'timeout':
                $this->elapsed = 0.0;
                $this->http->fail('timeout');
                break;
            case 'upstream':
                $this->http->queue(500, '');
                break;
            case 'cache_only':
                $options = ['fetch' => false];
                break;
        }
        $legs = [];
        LogCapture::during(function () use (&$legs, $options): void {
            $legs = $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']], $options);
        });
        // the same estimate whatever the reason, with the reason on it
        self::assertEquals([StraightLineLegs::straightLine('base', 's1', self::BASE, self::S1, $reason)], $legs);
        self::assertSame('straight_line', $legs[0]['source']);
        self::assertSame('fallback', $legs[0]['leg_input']['source']);
        self::assertNull($legs[0]['fetched_on']);
        self::assertNull($legs[0]['age_days']);
        self::assertSame($reason, $legs[0]['fallback_reason']);
        // nothing of it is in the cache of Google legs
        self::assertSame([], $this->tables->legs);
        self::assertSame([], $this->tables->written('INSERT INTO tp_drive_legs'));
    }

    // ---------------------------------------------------------------------------------------------------
    // The ledger
    // ---------------------------------------------------------------------------------------------------

    public function testEveryCallToGoogleLeavesALedgerRowWithItsSkuAndNoAddress(): void
    {
        LogCapture::during(function (): void {
            // Routes with tolls, Routes without, Routes with routing options, a refusal and its legacy attempt, a failure
            $this->queueRoutes([[0, 0]]);
            $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']]);
            $this->queueRoutes([[0, 0]]);
            $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S2], [['base', 's2']], ['tolls' => false]);
            $this->queueRoutes([[0, 0]]);
            $this->service()->legs(self::ORG, self::truck(['avoid_tolls' => true, 'avoid_highways' => true]), [self::BASE, self::S3], [['base', 's3']], ['tolls' => false]);
            $this->queueRoutesRefusal();
            $this->queueLegacyGrid(1, 1);
            $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S4], [['base', 's4']]);
            $this->http->queue(500, 'https://maps.googleapis.com/maps/api/distancematrix/json?key=' . self::KEY);
            $this->service()->legs(self::ORG, self::truck(), [self::BASE, self::S5], [['base', 's5']]);
        });
        self::assertCount(6, $this->http->requests);
        self::assertSame(
            [
                ['tp_routes_matrix_ent', 1, 200, null],
                ['tp_routes_matrix', 1, 200, null],
                ['tp_routes_matrix', 1, 200, null],
                ['tp_routes_matrix_ent', 0, 403, 'PERMISSION_DENIED'],
                ['tp_distance_matrix', 1, 200, null],
                ['tp_distance_matrix', 0, 500, 'http_500'],
            ],
            array_map(static fn (array $row): array => [$row['sku'], $row['billable_units'], $row['http_status'], $row['error_message']], $this->tables->ledger)
        );
        // unit cost and total follow the SKU; the mask is a hash; nothing is an address or the key
        self::assertSame(['0.015', '0.015'], [$this->tables->ledger[0]['unit_cost_usd'], $this->tables->ledger[0]['total_cost_usd']]);
        self::assertSame(['0.005', '0'], [$this->tables->ledger[5]['unit_cost_usd'], $this->tables->ledger[5]['total_cost_usd']]);
        $all = (string) json_encode($this->tables->ledger);
        self::assertStringNotContainsString(self::KEY, $all);
        self::assertStringNotContainsString('googleapis', $all);
        self::assertStringNotContainsString('://', $all);
        self::assertStringNotContainsString('origins=', $all);
        foreach ($this->tables->ledger as $row) {
            self::assertSame(36, strlen((string) $row['id']));
            self::assertTrue($row['field_mask_hash'] === null || preg_match('/^[0-9a-f]{16}$/', (string) $row['field_mask_hash']) === 1);
        }
        // and what was stored in the leg cache holds no address either
        self::assertStringNotContainsString('://', (string) json_encode($this->tables->legs));
    }

    // ---------------------------------------------------------------------------------------------------
    // The owner's corrections
    // ---------------------------------------------------------------------------------------------------

    public function testACorrectionSitsOnTopOfWhateverLegThereIs(): void
    {
        $service = $this->service();
        $minutes = $service->saveOverride(self::ORG, self::truck(), ['from' => self::S1, 'to' => self::BASE, 'minutes' => 14, 'toll' => null]);
        $toll = $service->saveOverride(self::ORG, self::truck(), ['from' => self::BASE, 'to' => self::S1, 'toll' => 2.5]);

        // on Google legs
        $this->queueRoutes([[0, 0, 713, 8100]]);
        $this->queueRoutes([[0, 0, 600, 7805, ['travelAdvisory' => ['tollInfo' => ['estimatedPrice' => [['currencyCode' => 'USD', 'units' => '3', 'nanos' => 750000000]]]]]]]);
        [$out, $back] = $this->loop([self::BASE, self::S1]);
        // the owner's toll replaces Google's estimate; Google's stays visible beside it
        self::assertSame(['id' => $toll['id'], 'minutes' => null, 'toll' => 2.5, 'note' => ''], $out['override']);
        self::assertSame('owner', $out['toll_source']);
        self::assertSame(3.75, $out['google_toll']);
        self::assertSame(['source' => 'google', 'distance_m' => 7805.0, 'duration_s' => 600.0, 'override_minutes' => null, 'toll' => 2.5], $out['leg_input']);
        // the owner's minutes are handed to the model, which then uses them at every hour
        self::assertSame(['id' => $minutes['id'], 'minutes' => 14, 'toll' => null, 'note' => ''], $back['override']);
        self::assertSame(14, $back['leg_input']['override_minutes']);
        self::assertSame('none', $back['toll_source']);
        self::assertSame(0.0, $back['leg_input']['toll']);
        self::assertSame(713.0, $back['duration_s'], 'the estimate itself is not changed');

        // and on straight lines, whatever the reason
        $this->noKey();
        $this->tables->legs = [];
        [$out, $back] = $this->loop([self::BASE, self::S1]);
        self::assertSame(['straight_line/no_key', 'straight_line/no_key'], self::labels([$out, $back]));
        self::assertSame(2.5, $out['leg_input']['toll']);
        self::assertSame('owner', $out['toll_source']);
        self::assertSame(14, $back['leg_input']['override_minutes']);
        self::assertSame('fallback', $back['leg_input']['source']);

        // a toll of nothing is a correction too: "there is no toll on my way"
        $service->saveOverride(self::ORG, self::truck(), ['from' => self::BASE, 'to' => self::S1, 'toll' => 0]);
        $free = $this->loop([self::BASE, self::S1])[0];
        self::assertSame(0.0, $free['leg_input']['toll']);
        self::assertSame('owner', $free['toll_source']);
    }

    public function testACorrectionBelongsToOneTruckOfOneOrganizationAndOneDirection(): void
    {
        $this->noKey();
        $this->service()->saveOverride(self::ORG, self::truck(), ['from' => self::BASE, 'to' => self::S1, 'minutes' => 14]);
        // the other direction, another organization, and another truck see none
        self::assertNull($this->loop([self::BASE, self::S1])[1]['override']);
        self::assertNull($this->loop([self::BASE, self::S1], [], self::truck([], self::OTHER_TRUCK), self::OTHER_ORG)[0]['override']);
        self::assertNull($this->loop([self::BASE, self::S1], [], self::truck([], self::OTHER_TRUCK))[0]['override']);
        self::assertNotNull($this->loop([self::BASE, self::S1])[0]['override']);
        // a pin a few metres away is the same rounded place and has the correction
        $near = ['id' => 'near', 'lat' => 38.96003, 'lng' => -77.36002];
        self::assertSame(14, $this->service()->legs(self::ORG, self::truck(), [self::BASE, $near], [['base', 'near']])[0]['leg_input']['override_minutes']);
    }

    public function testSavingReadingAndDeletingCorrections(): void
    {
        $service = $this->service();
        $saved = $service->saveOverride(self::ORG, self::truck(), [
            'from' => ['lat' => 38.96004, 'lng' => -77.35996], 'to' => ['lat' => 39.003, 'lng' => -77.405],
            'minutes' => 14, 'toll' => 3.755, 'note' => '  school run  ',
        ]);
        // the two points are the rounded keys, the toll is stored to the cent
        self::assertSame(['id', 'from', 'to', 'minutes', 'toll', 'note', 'updated_at'], array_keys($saved));
        self::assertSame(['lat' => 38.96, 'lng' => -77.36], $saved['from']);
        self::assertSame(['lat' => 39.003, 'lng' => -77.405], $saved['to']);
        self::assertSame(14, $saved['minutes']);
        self::assertSame(3.76, $saved['toll']);
        self::assertSame('school run', $saved['note']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $saved['updated_at']);
        self::assertSame(376, $this->tables->overrides[$saved['id']]['toll_cents']);

        // a second save of the same rounded pair changes the row that is there
        $again = $service->saveOverride(self::ORG, self::truck(), ['from' => self::S1, 'to' => self::BASE, 'minutes' => 16, 'toll' => null]);
        self::assertSame($saved['id'], $again['id']);
        self::assertSame(16, $again['minutes']);
        self::assertNull($again['toll'], 'null clears a value');
        self::assertSame('school run', $again['note'], 'a key that is not sent keeps its value');
        self::assertCount(1, $this->tables->overrides);

        // the other direction is another correction
        $other = $service->saveOverride(self::ORG, self::truck(), ['from' => self::BASE, 'to' => self::S1, 'toll' => 1]);
        self::assertNotSame($saved['id'], $other['id']);
        self::assertNull($other['minutes']);
        self::assertSame('', $other['note']);

        // listed in id order, as the export lists them
        $both = [$again, $other];
        usort($both, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        self::assertSame($both, $service->overrides(self::ORG, self::truck()));

        self::assertSame(['id' => $saved['id'], 'deleted' => true], $service->deleteOverride(self::ORG, $saved['id']));
        self::assertSame([$other], $service->overrides(self::ORG, self::truck()));
        try {
            $service->deleteOverride(self::ORG, $saved['id']);
            self::fail('a correction was deleted twice');
        } catch (TpNotFound $e) {
            self::assertSame('Correction not found', $e->getMessage());
        }
    }

    public function testAKeyThatIsNotSentKeepsItsValueAndNullClearsIt(): void
    {
        $service = $this->service();
        $leg = ['from' => self::S1, 'to' => self::BASE];
        $service->saveOverride(self::ORG, self::truck(), $leg + ['minutes' => 14, 'toll' => 2.0, 'note' => 'n']);
        self::assertSame([20, 2.0, 'n'], array_values(array_intersect_key($service->saveOverride(self::ORG, self::truck(), $leg + ['minutes' => 20]), ['minutes' => 0, 'toll' => 0, 'note' => 0])));
        self::assertSame([20, null, 'n'], array_values(array_intersect_key($service->saveOverride(self::ORG, self::truck(), $leg + ['toll' => null]), ['minutes' => 0, 'toll' => 0, 'note' => 0])));
        self::assertSame([20, null, ''], array_values(array_intersect_key($service->saveOverride(self::ORG, self::truck(), $leg + ['note' => null]), ['minutes' => 0, 'toll' => 0, 'note' => 0])));
        // nothing but the leg: nothing changes
        self::assertSame(20, $service->saveOverride(self::ORG, self::truck(), $leg)['minutes']);
        // clearing the last value is refused: a correction that corrects nothing is deleted instead
        try {
            $service->saveOverride(self::ORG, self::truck(), $leg + ['minutes' => null]);
            self::fail('an empty correction was saved');
        } catch (TpInvalid $e) {
            self::assertSame('Give minutes or toll', $e->getMessage());
        }
        self::assertSame(20, $service->overrides(self::ORG, self::truck())[0]['minutes']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: ?string, 3: ?string}> body, message, field, rule
     */
    public static function refusedCorrections(): array
    {
        $from = ['lat' => 38.96, 'lng' => -77.36];
        $to = ['lat' => 39.003, 'lng' => -77.405];
        return [
            'no from' => [['to' => $to, 'minutes' => 14], 'from is required', 'from', 'V1'],
            'no to' => [['from' => $from, 'minutes' => 14], 'to is required', 'to', 'V1'],
            'from is not a point' => [['from' => ['lat' => 138.96, 'lng' => -77.36], 'to' => $to, 'minutes' => 14], 'from must have lat between -90 and 90 and lng between -180 and 180', 'from', 'V10'],
            'to is text' => [['from' => $from, 'to' => 'Sterling', 'minutes' => 14], 'to must have lat between -90 and 90 and lng between -180 and 180', 'to', 'V10'],
            'no minutes at all' => [['from' => $from, 'to' => $to, 'minutes' => 0], 'minutes must be a whole number between 1 and 600', 'minutes', 'V3'],
            'too many minutes' => [['from' => $from, 'to' => $to, 'minutes' => 601], 'minutes must be a whole number between 1 and 600', 'minutes', 'V3'],
            'half a minute' => [['from' => $from, 'to' => $to, 'minutes' => 14.5], 'minutes must be a whole number between 1 and 600', 'minutes', 'V3'],
            'minutes as text' => [['from' => $from, 'to' => $to, 'minutes' => '14'], 'minutes must be a whole number between 1 and 600', 'minutes', 'V3'],
            'a negative toll' => [['from' => $from, 'to' => $to, 'toll' => -1], 'toll must be a number between 0 and 500', 'toll', 'V2'],
            'a toll beyond belief' => [['from' => $from, 'to' => $to, 'toll' => 500.01], 'toll must be a number between 0 and 500', 'toll', 'V2'],
            'a long note' => [['from' => $from, 'to' => $to, 'minutes' => 14, 'note' => str_repeat('n', 161)], 'note must be text of at most 160 characters', 'note', 'V5'],
            'neither minutes nor toll' => [['from' => $from, 'to' => $to], 'Give minutes or toll', null, null],
            'both null' => [['from' => $from, 'to' => $to, 'minutes' => null, 'toll' => null, 'note' => 'x'], 'Give minutes or toll', null, null],
            'the same place' => [['from' => $from, 'to' => $from, 'minutes' => 5], 'from and to are the same place', null, null],
            'the same rounded place' => [['from' => $from, 'to' => ['lat' => 38.96004, 'lng' => -77.36004], 'minutes' => 5], 'from and to are the same place', null, null],
        ];
    }

    /**
     * @dataProvider refusedCorrections
     * @param array<string, mixed> $body
     */
    public function testACorrectionThatIsRefusedStoresNothing(array $body, string $message, ?string $field, ?string $rule): void
    {
        try {
            $this->service()->saveOverride(self::ORG, self::truck(), $body);
            self::fail('the correction was saved');
        } catch (TpInvalid $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame($field, $e->field());
            self::assertSame($rule, $e->rule());
        }
        self::assertSame([], $this->tables->overrides);
    }

    public function testACorrectionThatChangesWhatAPlanDrivesStampsTheTruck(): void
    {
        $service = $this->service();
        $stamps = fn (): array => $this->truckDb->find('UPDATE tp_trucks SET updated_at = NOW() WHERE id = ? AND organization_id = ?');

        $saved = $service->saveOverride(self::ORG, self::truck(), ['from' => self::S1, 'to' => self::BASE, 'minutes' => 14]);
        self::assertCount(1, $stamps(), 'a new correction');
        self::assertSame([self::TRUCK, self::ORG], $stamps()[0]['params']);

        $service->saveOverride(self::ORG, self::truck(), ['from' => self::S1, 'to' => self::BASE, 'minutes' => 14, 'note' => 'school run']);
        self::assertCount(1, $stamps(), 'the same minutes and toll, another note: no plan would change');

        $service->saveOverride(self::ORG, self::truck(), ['from' => self::S1, 'to' => self::BASE, 'toll' => 3.5]);
        self::assertCount(2, $stamps(), 'another toll');
        $service->saveOverride(self::ORG, self::truck(), ['from' => self::S1, 'to' => self::BASE, 'minutes' => 16]);
        self::assertCount(3, $stamps(), 'other minutes');

        // what is refused stamps nothing
        foreach ([['from' => self::S1, 'to' => self::S1, 'minutes' => 5], ['from' => self::BASE, 'to' => self::S1], ['from' => self::S1, 'to' => self::BASE, 'minutes' => 0]] as $body) {
            try {
                $service->saveOverride(self::ORG, self::truck(), $body);
                self::fail('a correction that cannot be was saved');
            } catch (TpInvalid $e) {
                self::assertCount(3, $stamps());
            }
        }
        try {
            $service->deleteOverride(self::OTHER_ORG, $saved['id']);
            self::fail('another organization deleted the correction');
        } catch (TpNotFound $e) {
            self::assertCount(3, $stamps());
        }

        // a deleted correction leaves no row to carry the time: the truck carries it
        $service->deleteOverride(self::ORG, $saved['id']);
        self::assertCount(4, $stamps());
        self::assertSame([self::TRUCK, self::ORG], $stamps()[3]['params']);
        self::assertCount(4, $this->truckDb->calls, 'and nothing else was written to the truck');
    }

    public function testAnotherOrganizationNeitherSeesNorDeletesACorrection(): void
    {
        $service = $this->service();
        $mine = $service->saveOverride(self::ORG, self::truck(), ['from' => self::S1, 'to' => self::BASE, 'minutes' => 14]);
        self::assertSame([], $service->overrides(self::OTHER_ORG, self::truck([], self::OTHER_TRUCK)));
        // even when it names the first organization's truck
        self::assertSame([], $service->overrides(self::OTHER_ORG, self::truck()));
        try {
            $service->deleteOverride(self::OTHER_ORG, $mine['id']);
            self::fail('another organization deleted the correction');
        } catch (TpNotFound $e) {
            // the same sentence as for an id that does not exist
            self::assertSame('Correction not found', $e->getMessage());
        }
        try {
            $service->deleteOverride(self::ORG, '00000000-0000-4000-8000-000000000000');
            self::fail('an unknown id was deleted');
        } catch (TpNotFound $e) {
            self::assertSame('Correction not found', $e->getMessage());
        }
        self::assertCount(1, $service->overrides(self::ORG, self::truck()));
        // its own save of the same leg is a correction of its own
        $theirs = $service->saveOverride(self::OTHER_ORG, self::truck([], self::OTHER_TRUCK), ['from' => self::S1, 'to' => self::BASE, 'minutes' => 30]);
        self::assertNotSame($mine['id'], $theirs['id']);
        self::assertSame(14, $service->overrides(self::ORG, self::truck())[0]['minutes']);
        // every statement on the table carried an organization
        foreach ($this->tables->statements as $sql) {
            if (str_contains($sql, 'tp_drive_overrides') && !str_starts_with($sql, 'INSERT')) {
                self::assertStringContainsString('organization_id = ?', $sql);
            }
        }
    }

    public function testCorrectionsFollowAPinThatMovedAShortWay(): void
    {
        $service = $this->service();
        $out = $service->saveOverride(self::ORG, self::truck(), ['from' => self::BASE, 'to' => self::S1, 'minutes' => 11]);
        $back = $service->saveOverride(self::ORG, self::truck(), ['from' => self::S1, 'to' => self::BASE, 'minutes' => 12]);
        $elsewhere = $service->saveOverride(self::ORG, self::truck(), ['from' => self::BASE, 'to' => self::S2, 'minutes' => 40]);
        $moved = ['lat' => 38.9604, 'lng' => -77.3597];

        $service->movePoint(self::ORG, self::truck(), self::S1, $moved);
        $after = array_column($service->overrides(self::ORG, self::truck()), null, 'id');
        self::assertSame(['lat' => 38.9604, 'lng' => -77.3597], $after[$out['id']]['to']);
        self::assertSame(['lat' => 38.9604, 'lng' => -77.3597], $after[$back['id']]['from']);
        self::assertSame(['lat' => 38.9072, 'lng' => -77.0369], $after[$elsewhere['id']]['to'], 'a correction of another leg stays');
        self::assertSame([11, 12, 40], [$after[$out['id']]['minutes'], $after[$back['id']]['minutes'], $after[$elsewhere['id']]['minutes']]);

        // the legs of the moved pin have them, the old place has none
        $this->noKey();
        $pin = ['id' => 'pin', 'lat' => 38.9604, 'lng' => -77.3597];
        self::assertSame(11, $service->legs(self::ORG, self::truck(), [self::BASE, $pin], [['base', 'pin']])[0]['leg_input']['override_minutes']);
        self::assertNull($service->legs(self::ORG, self::truck(), [self::BASE, self::S1], [['base', 's1']])[0]['override']);
        // another organization's corrections do not move
        $theirs = $service->saveOverride(self::OTHER_ORG, self::truck([], self::OTHER_TRUCK), ['from' => self::BASE, 'to' => self::S2, 'minutes' => 9]);
        $service->movePoint(self::ORG, self::truck(), self::S2, $moved);
        self::assertSame(['lat' => 38.9072, 'lng' => -77.0369], $service->overrides(self::OTHER_ORG, self::truck([], self::OTHER_TRUCK))[0]['to']);
        self::assertSame($theirs['id'], $service->overrides(self::OTHER_ORG, self::truck([], self::OTHER_TRUCK))[0]['id']);
    }

    // ---------------------------------------------------------------------------------------------------
    // Route 18
    // ---------------------------------------------------------------------------------------------------

    public function testDriveTimesAnswersTheLegsOfALoopByDefault(): void
    {
        $this->noKey();
        $answer = $this->service()->driveTimes(self::ORG, self::truck(['avoid_highways' => true]), ['points' => [self::BASE, self::S1, self::S2]]);
        self::assertSame(['legs', 'routing'], array_keys($answer));
        self::assertSame(
            [['base', 's1'], ['s1', 's2'], ['s2', 'base']],
            array_map(static fn (array $leg): array => [$leg['from_id'], $leg['to_id']], $answer['legs'])
        );
        self::assertSame(
            [
                'state' => 'no_key',
                'route_key' => 'dh',
                'leg_ttl_days' => 30,
                'attribution' => 'Drive times and distances: Google Maps Platform. Kept for at most 30 days.',
            ],
            $answer['routing']
        );
    }

    public function testTheModesOfDriveTimes(): void
    {
        $this->noKey();
        $points = [self::BASE, self::S1, self::S2];
        $ends = fn (array $body): array => array_map(
            static fn (array $leg): string => $leg['from_id'] . '>' . $leg['to_id'],
            $this->service()->driveTimes(self::ORG, self::truck(), $body + ['points' => $points])['legs']
        );
        self::assertSame(['base>s1', 's1>s2', 's2>base'], $ends(['mode' => 'loop']));
        self::assertSame(['base>s1', 's1>s2'], $ends(['mode' => 'chain']));
        self::assertSame(['base>s1', 'base>s2', 's1>base', 's1>s2', 's2>base', 's2>s1'], $ends(['mode' => 'matrix']));
        // pairs: as given, in the order given, a pair twice if it is given twice
        self::assertSame(['s2>base', 'base>s2', 's2>base'], $ends(['mode' => 'pairs', 'pairs' => [['s2', 'base'], ['base', 's2'], ['s2', 'base']]]));
        // `pairs` is read only in its own mode
        self::assertSame(['base>s1', 's1>s2'], $ends(['mode' => 'chain', 'pairs' => 'ignored']));
        // two points: there and back
        self::assertSame(['base>s1', 's1>base'], array_map(
            static fn (array $leg): string => $leg['from_id'] . '>' . $leg['to_id'],
            $this->service()->driveTimes(self::ORG, self::truck(), ['points' => [self::BASE, self::S1]])['legs']
        ));
    }

    public function testDriveTimesAsksForTollsAndFetchesUnlessToldOtherwise(): void
    {
        $this->queueRoutes([[0, 0]]);
        $this->service()->driveTimes(self::ORG, self::truck(), ['points' => [self::BASE, self::S1], 'mode' => 'chain']);
        self::assertStringContainsString('"extraComputations":["TOLLS"]', (string) $this->http->requests[0]['body']);

        $this->queueRoutes([[0, 0]]);
        $this->service()->driveTimes(self::ORG, self::truck(), ['points' => [self::BASE, self::S2], 'mode' => 'chain', 'tolls' => false]);
        self::assertStringNotContainsString('TOLLS', (string) $this->http->requests[1]['body']);

        $answer = $this->service()->driveTimes(self::ORG, self::truck(), ['points' => [self::BASE, self::S3], 'mode' => 'chain', 'fetch' => false]);
        self::assertCount(2, $this->http->requests);
        self::assertSame(['straight_line/cache_only'], self::labels($answer['legs']));
        self::assertSame('ok', $answer['routing']['state']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: ?string, 3: ?string}> body, message, field, rule
     */
    public static function refusedRequests(): array
    {
        $a = ['id' => 'a', 'lat' => 39.003, 'lng' => -77.405];
        $b = ['id' => 'b', 'lat' => 38.96, 'lng' => -77.36];
        $many = [];
        for ($i = 0; $i < 61; $i++) {
            $many[] = ['id' => 'p' . $i, 'lat' => 38.0 + $i / 100.0, 'lng' => -77.0];
        }
        return [
            'no points' => [[], 'points is required', 'points', 'V1'],
            'one point' => [['points' => [$a]], 'points must be a list of 2 to 60 items', 'points', 'V8'],
            'sixty-one points' => [['points' => $many], 'points must be a list of 2 to 60 items', 'points', 'V8'],
            'points is an object' => [['points' => ['a' => $a, 'b' => $b]], 'points must be a list of 2 to 60 items', 'points', 'V8'],
            'a point that is text' => [['points' => [$a, 'b']], 'points[1] must be an object', 'points[1]', 'V9'],
            'a point without an id' => [['points' => [$a, ['lat' => 38.96, 'lng' => -77.36]]], 'points[1].id is required', 'points[1].id', 'V1'],
            'an empty id' => [['points' => [$a, ['id' => '  '] + $b]], 'points[1].id is required', 'points[1].id', 'V1'],
            'an id that is a number' => [['points' => [$a, ['id' => 7] + $b]], 'points[1].id must be text of at most 64 characters', 'points[1].id', 'V5'],
            'a long id' => [['points' => [['id' => str_repeat('x', 65)] + $a, $b]], 'points[0].id must be text of at most 64 characters', 'points[0].id', 'V5'],
            'an id twice' => [['points' => [$a, $b, ['id' => 'a'] + $b]], 'points[2].id is repeated', 'points[2].id', null],
            'a point without lng' => [['points' => [$a, ['id' => 'b', 'lat' => 38.96]]], 'points[1] must have lat between -90 and 90 and lng between -180 and 180', 'points[1]', 'V10'],
            'a point off the globe' => [['points' => [['id' => 'a', 'lat' => 91, 'lng' => 0], $b]], 'points[0] must have lat between -90 and 90 and lng between -180 and 180', 'points[0]', 'V10'],
            'coordinates as text' => [['points' => [$a, ['id' => 'b', 'lat' => '38.96', 'lng' => '-77.36']]], 'points[1] must have lat between -90 and 90 and lng between -180 and 180', 'points[1]', 'V10'],
            'an unknown mode' => [['points' => [$a, $b], 'mode' => 'star'], 'mode must be one of: loop, chain, matrix, pairs', 'mode', 'V4'],
            'pairs without pairs' => [['points' => [$a, $b], 'mode' => 'pairs'], 'pairs is required', 'pairs', 'V1'],
            'no pair' => [['points' => [$a, $b], 'mode' => 'pairs', 'pairs' => []], 'pairs must be a list of 1 to 650 items', 'pairs', 'V8'],
            'a pair of three' => [['points' => [$a, $b], 'mode' => 'pairs', 'pairs' => [['a', 'b', 'a']]], 'pairs[0] must be a list of 2 to 2 items', 'pairs[0]', 'V8'],
            'a pair that is text' => [['points' => [$a, $b], 'mode' => 'pairs', 'pairs' => [['a', 'b'], 'a>b']], 'pairs[1] must be a list of 2 to 2 items', 'pairs[1]', 'V8'],
            'an end that is a number' => [['points' => [$a, $b], 'mode' => 'pairs', 'pairs' => [['a', 2]]], 'pairs[0][1] must be text of at most 64 characters', 'pairs[0][1]', 'V5'],
            'an end that is no point' => [['points' => [$a, $b], 'mode' => 'pairs', 'pairs' => [['a', 'b'], ['c', 'a']]], 'pairs[1][0] was not found', 'pairs[1][0]', 'V11'],
            'tolls as text' => [['points' => [$a, $b], 'tolls' => 'yes'], 'tolls must be true or false', 'tolls', 'V6'],
            'fetch as a number' => [['points' => [$a, $b], 'fetch' => 1], 'fetch must be true or false', 'fetch', 'V6'],
        ];
    }

    /**
     * @dataProvider refusedRequests
     * @param array<string, mixed> $body
     */
    public function testADriveTimesRequestThatIsRefused(array $body, string $message, ?string $field, ?string $rule): void
    {
        try {
            $this->service()->driveTimes(self::ORG, self::truck(), $body);
            self::fail('the request was accepted');
        } catch (TpInvalid $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame($field, $e->field());
            self::assertSame($rule, $e->rule());
        }
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->tables->statements, 'a refused request reads and writes nothing');
    }

    public function testMoreThan650LegsInOneRequestAreRefused(): void
    {
        $points = [];
        for ($i = 0; $i < 27; $i++) {
            $points[] = ['id' => 'p' . $i, 'lat' => 38.0 + $i / 100.0, 'lng' => -77.0];
        }
        try {
            $this->service()->driveTimes(self::ORG, self::truck(), ['points' => $points, 'mode' => 'matrix']);
            self::fail('702 legs were accepted');
        } catch (TpInvalid $e) {
            self::assertSame('Too many legs in one request (at most 650)', $e->getMessage());
        }
        // 26 points are 650 legs, and sixty points in a loop are sixty
        $this->noKey();
        self::assertCount(650, $this->service()->driveTimes(self::ORG, self::truck(), ['points' => array_slice($points, 0, 26), 'mode' => 'matrix'])['legs']);
        $sixty = [];
        for ($i = 0; $i < 60; $i++) {
            $sixty[] = ['id' => 'q' . $i, 'lat' => 38.0 + $i / 100.0, 'lng' => -77.0];
        }
        self::assertCount(60, $this->service()->driveTimes(self::ORG, self::truck(), ['points' => $sixty])['legs']);
    }

    // ---------------------------------------------------------------------------------------------------
    // Helpers of the other services
    // ---------------------------------------------------------------------------------------------------

    public function testPairs(): void
    {
        self::assertSame([['a', 'b'], ['b', 'c']], RoutingService::pairs('chain', ['a', 'b', 'c']));
        self::assertSame([['a', 'b'], ['b', 'c'], ['c', 'a']], RoutingService::pairs('loop', ['a', 'b', 'c']));
        self::assertSame([['a', 'b'], ['b', 'a']], RoutingService::pairs('loop', ['a', 'b']));
        self::assertSame([['a', 'b'], ['a', 'c'], ['b', 'a'], ['b', 'c'], ['c', 'a'], ['c', 'b']], RoutingService::pairs('matrix', ['a', 'b', 'c']));
        foreach (['chain', 'loop', 'matrix'] as $mode) {
            self::assertSame([], RoutingService::pairs($mode, []));
            self::assertSame([], RoutingService::pairs($mode, ['only']));
        }
        self::assertCount(650, RoutingService::pairs('matrix', range(1, 26)));
        $this->expectException(\LogicException::class);
        RoutingService::pairs('pairs', ['a', 'b']);
    }

    public function testTheLegMapOfTheModel(): void
    {
        $this->noKey();
        $this->service()->saveOverride(self::ORG, self::truck(), ['from' => self::S1, 'to' => self::BASE, 'minutes' => 14]);
        $legs = $this->loop([self::BASE, self::S1]);
        $map = RoutingService::legInputMap($legs);
        self::assertSame(['base>s1', 's1>base'], array_keys($map));
        self::assertSame($legs[0]['leg_input'], $map['base>s1']);
        self::assertSame(14, $map['s1>base']['override_minutes']);
        // exactly the shape the model takes
        foreach ($map as $input) {
            self::assertSame(['source', 'distance_m', 'duration_s', 'override_minutes', 'toll'], array_keys($input));
            self::assertIsFloat($input['distance_m']);
            self::assertIsFloat($input['duration_s']);
            self::assertIsFloat($input['toll']);
        }
        self::assertSame([], RoutingService::legInputMap([]));
    }

    public function testStatus(): void
    {
        self::assertSame(['state' => 'ok'], $this->service()->status());
        $this->guard->markRefused('routes');
        self::assertSame(['state' => 'ok'], $this->service()->status(), 'Routes alone refused: the legacy API is still tried');
        $this->guard->backoff('routes', 30);
        self::assertSame(['state' => 'backoff'], $this->service()->status());
        $this->guard->markRefused('legacy');
        self::assertSame(['state' => 'refused'], $this->service()->status());
        $this->noKey();
        self::assertSame(['state' => 'no_key'], $this->service()->status());
    }
}

/**
 * `tp_drive_legs`, `tp_drive_overrides` and `api_cost_events` in memory behind the Database interface. It
 * runs the statements the repositories and the ledger write, so the repositories under test are the real
 * ones. NOW() is the test's clock, and the database lives in UTC.
 */
final class LegTables extends Database
{
    private const TTL_S = 30 * 86400;
    private const OVERRIDE_COLUMNS = 'id, organization_id, truck_id, o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4, override_minutes, toll_cents, note, created_at, updated_at';

    /** @var array<string, array<string, mixed>> cached legs by "pair id|route key"; `fetched_at` is an epoch */
    public array $legs = [];

    /** @var array<string, array<string, mixed>> corrections by id */
    public array $overrides = [];

    /** @var list<array<string, mixed>> ledger rows in order */
    public array $ledger = [];

    /** Billable units "already in the ledger today" before the test wrote any. */
    public int $unitsBefore = 0;

    /** Dollars "already in the ledger" before the test wrote any: today, and earlier this month. */
    public float $usdToday = 0.0;
    public float $usdEarlierThisMonth = 0.0;

    /** @var list<string> every statement in order, whitespace squashed */
    public array $statements = [];

    /** @var list<string> */
    private array $failing = [];
    private FixedClock $clock;
    private bool $inTransaction = false;

    public function __construct(FixedClock $clock)
    {
        $this->clock = $clock;
    }

    /** Every statement that contains `$needle` fails from now on. */
    public function failOn(string $needle): void
    {
        $this->failing[] = $needle;
    }

    /**
     * A leg as if Google had answered it `$ageSeconds` ago.
     *
     * @param array<int, int> $pair
     * @param array<string, mixed> $over
     */
    public function seedLeg(array $pair, string $routeKey, int $ageSeconds, array $over = []): void
    {
        $this->legs[DriveLegRepository::pairId($pair) . '|' . $routeKey] = $over + [
            'o_lat_e4' => $pair[0], 'o_lng_e4' => $pair[1], 'd_lat_e4' => $pair[2], 'd_lng_e4' => $pair[3],
            'route_key' => $routeKey, 'src' => 'google_routes', 'route_found' => 1, 'duration_s' => 600, 'distance_m' => 7805,
            'toll_state' => 1, 'toll_cents' => null, 'fetched_at' => $this->clock->epoch() - $ageSeconds,
        ];
    }

    /**
     * The statements that start with `$prefix`.
     *
     * @return list<string>
     */
    public function written(string $prefix): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => str_starts_with($sql, $prefix)));
    }

    public function beginTransaction(): void
    {
        if ($this->inTransaction) {
            throw new \LogicException('LegTables: there is already an active transaction');
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

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $sql = $this->record($sql, $params);
        $params = array_values($params);

        if (str_starts_with($sql, 'INSERT INTO tp_drive_legs (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4, route_key, src, route_found, duration_s, distance_m, toll_state, toll_cents, fetched_at) VALUES ')) {
            if (!str_ends_with($sql, 'ON DUPLICATE KEY UPDATE src = VALUES(src), route_found = VALUES(route_found), duration_s = VALUES(duration_s), distance_m = VALUES(distance_m), toll_state = VALUES(toll_state), toll_cents = VALUES(toll_cents), fetched_at = NOW()')) {
                throw new \LogicException('LegTables: an upsert that does not replace every column: ' . $sql);
            }
            if (count($params) % 11 !== 0 || substr_count($sql, 'NOW())') !== count($params) / 11) {
                throw new \LogicException('LegTables: values and placeholders of the leg upsert differ');
            }
            foreach (array_chunk($params, 11) as $v) {
                if (!in_array($v[5], ['google_routes', 'google_distance_matrix'], true)) {
                    throw new \LogicException('LegTables: a row that is not a Google answer reached the cache');
                }
                $this->legs[$v[0] . ',' . $v[1] . ',' . $v[2] . ',' . $v[3] . '|' . $v[4]] = [
                    'o_lat_e4' => $v[0], 'o_lng_e4' => $v[1], 'd_lat_e4' => $v[2], 'd_lng_e4' => $v[3], 'route_key' => $v[4],
                    'src' => $v[5], 'route_found' => $v[6], 'duration_s' => $v[7], 'distance_m' => $v[8],
                    'toll_state' => $v[9], 'toll_cents' => $v[10], 'fetched_at' => $this->clock->epoch(),
                ];
            }
            return new \PDOStatement();
        }
        if (preg_match('/^DELETE FROM tp_drive_legs WHERE fetched_at < NOW\(\) - INTERVAL 30 DAY(?: LIMIT (\d+))?$/', $sql, $m) === 1) {
            $limit = isset($m[1]) ? (int) $m[1] : PHP_INT_MAX;
            foreach ($this->legs as $key => $leg) {
                if ($limit > 0 && $leg['fetched_at'] < $this->clock->epoch() - self::TTL_S) {
                    unset($this->legs[$key]);
                    $limit--;
                }
            }
            return new \PDOStatement();
        }
        if ($sql === 'INSERT INTO tp_drive_overrides (id, organization_id, truck_id, o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4, override_minutes, toll_cents, note, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())') {
            [$id, $org, $truck, $oLat, $oLng, $dLat, $dLng, $minutes, $cents, $note] = $params;
            foreach ($this->overrides as $row) {
                if ($row['truck_id'] === $truck && [$row['o_lat_e4'], $row['o_lng_e4'], $row['d_lat_e4'], $row['d_lng_e4']] === [$oLat, $oLng, $dLat, $dLng]) {
                    throw new \PDOException('Duplicate entry for key uk_tpdo_leg', 23000);
                }
            }
            $this->overrides[$id] = [
                'id' => $id, 'organization_id' => $org, 'truck_id' => $truck, 'o_lat_e4' => $oLat, 'o_lng_e4' => $oLng,
                'd_lat_e4' => $dLat, 'd_lng_e4' => $dLng, 'override_minutes' => $minutes, 'toll_cents' => $cents, 'note' => $note,
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ];
            return new \PDOStatement();
        }
        if ($sql === 'UPDATE tp_drive_overrides SET override_minutes = ?, toll_cents = ?, note = ? WHERE id = ? AND organization_id = ?') {
            [$minutes, $cents, $note, $id, $org] = $params;
            $this->change($id, $org, ['override_minutes' => $minutes, 'toll_cents' => $cents, 'note' => $note]);
            return new \PDOStatement();
        }
        if ($sql === 'UPDATE tp_drive_overrides SET o_lat_e4 = ?, o_lng_e4 = ?, d_lat_e4 = ?, d_lng_e4 = ? WHERE id = ? AND organization_id = ?') {
            [$oLat, $oLng, $dLat, $dLng, $id, $org] = $params;
            $this->change($id, $org, ['o_lat_e4' => $oLat, 'o_lng_e4' => $oLng, 'd_lat_e4' => $dLat, 'd_lng_e4' => $dLng]);
            return new \PDOStatement();
        }
        if ($sql === 'DELETE FROM tp_drive_overrides WHERE id = ? AND organization_id = ?') {
            [$id, $org] = $params;
            if (isset($this->overrides[$id]) && $this->overrides[$id]['organization_id'] === $org) {
                unset($this->overrides[$id]);
            }
            return new \PDOStatement();
        }
        if ($sql === 'INSERT INTO api_cost_events (id, sku, billable_units, unit_cost_usd, total_cost_usd, field_mask_hash, http_status, latency_ms, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)') {
            $this->ledger[] = array_combine(
                ['id', 'sku', 'billable_units', 'unit_cost_usd', 'total_cost_usd', 'field_mask_hash', 'http_status', 'latency_ms', 'error_message'],
                $params
            );
            return new \PDOStatement();
        }
        throw new \LogicException('LegTables: unexpected statement: ' . $sql);
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $sql = $this->record($sql, $params);
        $params = array_values($params);

        if ($sql === 'SELECT COUNT(*) AS expired_legs FROM tp_drive_legs WHERE fetched_at < NOW() - INTERVAL 30 DAY') {
            $count = 0;
            foreach ($this->legs as $leg) {
                $count += $leg['fetched_at'] < $this->clock->epoch() - self::TTL_S ? 1 : 0;
            }
            return ['expired_legs' => $count];
        }
        if ($sql === 'SELECT ' . self::OVERRIDE_COLUMNS . ' FROM tp_drive_overrides WHERE id = ? AND organization_id = ?') {
            [$id, $org] = $params;
            $row = $this->overrides[$id] ?? null;
            return $row !== null && $row['organization_id'] === $org ? $row : null;
        }
        if ($sql === 'SELECT id FROM tp_drive_overrides WHERE organization_id = ? AND truck_id = ? AND o_lat_e4 = ? AND o_lng_e4 = ? AND d_lat_e4 = ? AND d_lng_e4 = ?') {
            [$org, $truck, $oLat, $oLng, $dLat, $dLng] = $params;
            foreach ($this->overrides as $row) {
                if ($row['organization_id'] === $org && $row['truck_id'] === $truck
                    && [$row['o_lat_e4'], $row['o_lng_e4'], $row['d_lat_e4'], $row['d_lng_e4']] === [$oLat, $oLng, $dLat, $dLng]) {
                    return ['id' => $row['id']];
                }
            }
            return null;
        }
        if (preg_match('/^SELECT COALESCE\(SUM\(billable_units\), 0\) AS units FROM api_cost_events WHERE sku IN \([?, ]+\) AND called_at >= CURDATE\(\)$/', $sql) === 1) {
            $units = $this->unitsBefore;
            foreach ($this->ledger as $row) {
                $units += in_array($row['sku'], $params, true) ? (int) $row['billable_units'] : 0;
            }
            return ['units' => $units];
        }
        $spent = 'SELECT COALESCE(SUM(CASE WHEN called_at >= CURDATE() THEN total_cost_usd ELSE 0 END), 0) AS day_usd, '
            . 'COALESCE(SUM(total_cost_usd), 0) AS month_usd FROM api_cost_events WHERE sku IN (';
        if (str_starts_with($sql, $spent)
            && str_ends_with($sql, ') AND called_at >= DATE_SUB(CURDATE(), INTERVAL DAYOFMONTH(CURDATE()) - 1 DAY)')) {
            // every row the test wrote is of today
            $usd = $this->usdToday;
            foreach ($this->ledger as $row) {
                $usd += in_array($row['sku'], $params, true) ? (float) $row['total_cost_usd'] : 0.0;
            }
            // MySQL hands a DECIMAL sum back as text
            return ['day_usd' => sprintf('%.6F', $usd), 'month_usd' => sprintf('%.6F', $this->usdEarlierThisMonth + $usd)];
        }
        throw new \LogicException('LegTables: unexpected statement: ' . $sql);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $sql = $this->record($sql, $params);
        $params = array_values($params);

        $legRead = 'SELECT o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4, src, route_found, duration_s, distance_m, toll_state, toll_cents, '
            . 'DATE(fetched_at) AS fetched_on, TIMESTAMPDIFF(DAY, fetched_at, NOW()) AS age_days FROM tp_drive_legs '
            . 'WHERE route_key = ? AND (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4) IN (';
        if (str_starts_with($sql, $legRead) && str_ends_with($sql, ') AND fetched_at >= NOW() - INTERVAL 30 DAY')) {
            $routeKey = array_shift($params);
            $out = [];
            foreach (array_chunk($params, 4) as $pair) {
                $leg = $this->legs[implode(',', $pair) . '|' . $routeKey] ?? null;
                if ($leg === null || $leg['fetched_at'] < $this->clock->epoch() - self::TTL_S) {
                    continue;
                }
                $out[] = [
                    'o_lat_e4' => $leg['o_lat_e4'], 'o_lng_e4' => $leg['o_lng_e4'], 'd_lat_e4' => $leg['d_lat_e4'], 'd_lng_e4' => $leg['d_lng_e4'],
                    'src' => $leg['src'], 'route_found' => $leg['route_found'], 'duration_s' => $leg['duration_s'],
                    'distance_m' => $leg['distance_m'], 'toll_state' => $leg['toll_state'], 'toll_cents' => $leg['toll_cents'],
                    'fetched_on' => gmdate('Y-m-d', $leg['fetched_at']),
                    'age_days' => intdiv($this->clock->epoch() - $leg['fetched_at'], 86400),
                ];
            }
            return $out;
        }
        if ($sql === 'SELECT ' . self::OVERRIDE_COLUMNS . ' FROM tp_drive_overrides WHERE organization_id = ? AND truck_id = ? ORDER BY id') {
            [$org, $truck] = $params;
            $out = array_values(array_filter($this->overrides, static fn (array $row): bool => $row['organization_id'] === $org && $row['truck_id'] === $truck));
            usort($out, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
            return $out;
        }
        $pairRead = 'SELECT ' . self::OVERRIDE_COLUMNS . ' FROM tp_drive_overrides WHERE organization_id = ? AND truck_id = ? AND (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4) IN (';
        if (str_starts_with($sql, $pairRead)) {
            $org = array_shift($params);
            $truck = array_shift($params);
            $wanted = array_chunk($params, 4);
            return array_values(array_filter($this->overrides, static fn (array $row): bool => $row['organization_id'] === $org && $row['truck_id'] === $truck
                && in_array([$row['o_lat_e4'], $row['o_lng_e4'], $row['d_lat_e4'], $row['d_lng_e4']], $wanted, true)));
        }
        if (str_starts_with($sql, 'SELECT id, o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4 FROM tp_drive_overrides WHERE organization_id = ? AND truck_id = ? AND ((o_lat_e4 = ? AND o_lng_e4 = ?) OR (d_lat_e4 = ? AND d_lng_e4 = ?) OR (o_lat_e4 = ? AND o_lng_e4 = ?) OR (d_lat_e4 = ? AND d_lng_e4 = ?)) ORDER BY id')) {
            [$org, $truck, $oldLat, $oldLng, , , $newLat, $newLng] = $params;
            $out = [];
            foreach ($this->overrides as $row) {
                $from = [$row['o_lat_e4'], $row['o_lng_e4']];
                $to = [$row['d_lat_e4'], $row['d_lng_e4']];
                if ($row['organization_id'] === $org && $row['truck_id'] === $truck
                    && (in_array($from, [[$oldLat, $oldLng], [$newLat, $newLng]], true) || in_array($to, [[$oldLat, $oldLng], [$newLat, $newLng]], true))) {
                    $out[] = array_intersect_key($row, ['id' => 0, 'o_lat_e4' => 0, 'o_lng_e4' => 0, 'd_lat_e4' => 0, 'd_lng_e4' => 0]);
                }
            }
            usort($out, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
            return $out;
        }
        throw new \LogicException('LegTables: unexpected statement: ' . $sql);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function change(string $id, string $org, array $changes): void
    {
        $row = $this->overrides[$id] ?? null;
        if ($row === null || $row['organization_id'] !== $org) {
            return;
        }
        $next = array_merge($row, $changes);
        if ($next !== $row) {
            $next['updated_at'] = $this->now();
        }
        $this->overrides[$id] = $next;
    }

    /**
     * @param array<int|string, mixed> $params
     */
    private function record(string $sql, array $params): string
    {
        $squashed = trim((string) preg_replace('/\s+/', ' ', $sql));
        $this->statements[] = $squashed;
        foreach ($params as $value) {
            if (is_float($value) || is_bool($value) || is_array($value)) {
                throw new \LogicException('LegTables: a float, a boolean or an array was bound: ' . $squashed);
            }
        }
        foreach ($this->failing as $needle) {
            if (str_contains($squashed, $needle)) {
                throw new \RuntimeException('database failure (test)');
            }
        }
        return $squashed;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s', $this->clock->epoch());
    }
}
