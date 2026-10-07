<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FakeHttp;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Services\Google\RoutesMatrixClient;
use PHPUnit\Framework\TestCase;

/**
 * The Routes API client against stubbed HTTP: the request as Google's reference describes it (read
 * 2026-10-05), every parsing rule of 04_BACKEND.md 5.3, the error bodies, and the ledger row of each call.
 */
final class RoutesMatrixClientTest extends TestCase
{
    private const KEY = 'test-key-not-real';
    private const BASE = [390030, -774050];
    private const SPOT = [389600, -773600];
    private const MALL = [389072, -770369];

    private FakeHttp $http;
    private RecordingDatabase $db;
    private string|false $keyBefore;
    private ?string $envBefore;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $this->db = new RecordingDatabase();
        $this->keyBefore = getenv('GOOGLE_API_KEY');
        $this->envBefore = $_ENV['GOOGLE_API_KEY'] ?? null;
        unset($_ENV['GOOGLE_API_KEY']);
        putenv('GOOGLE_API_KEY=' . self::KEY);
    }

    protected function tearDown(): void
    {
        putenv($this->keyBefore === false ? 'GOOGLE_API_KEY' : 'GOOGLE_API_KEY=' . $this->keyBefore);
        if ($this->envBefore !== null) {
            $_ENV['GOOGLE_API_KEY'] = $this->envBefore;
        }
    }

    private function client(): RoutesMatrixClient
    {
        return new RoutesMatrixClient($this->http, new ApiLedger($this->db));
    }

    /**
     * The elements of an answer with one origin and one destination per item of `$items`.
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function parsed(array $items, bool $tolls = false, int $origins = 2, int $destinations = 2): array
    {
        return RoutesMatrixClient::elements($items, $origins, $destinations, $tolls);
    }

    /**
     * The ledger rows written so far, as [sku, units, unit cost, total, mask hash, status, latency, error].
     *
     * @return list<list<mixed>>
     */
    private function ledger(): array
    {
        return array_map(
            static fn (array $call): array => array_slice($call['params'], 1),
            $this->db->find('INSERT INTO api_cost_events')
        );
    }

    // ------------------------------------------------------------------------------------ the request

    public function testTheRequestIsTheOneOfTheSpecification(): void
    {
        $this->http->json(200, [[
            'originIndex' => 0, 'destinationIndex' => 0, 'status' => new \stdClass(), 'condition' => 'ROUTE_EXISTS',
            'distanceMeters' => 7805, 'duration' => '600s',
            'travelAdvisory' => ['tollInfo' => ['estimatedPrice' => [['currencyCode' => 'USD', 'units' => '3', 'nanos' => 750000000]]]],
        ]]);
        $answer = $this->client()->matrix([self::BASE], [self::SPOT], 'dh', true);

        self::assertCount(1, $this->http->requests);
        $request = $this->http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://routes.googleapis.com/distanceMatrix/v2:computeRouteMatrix', $request['url']);
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
        self::assertSame(3, $request['connect_timeout_s']);
        self::assertSame(8, $request['timeout_s']);

        self::assertSame(
            [
                'ok' => true,
                'error' => null,
                'elements' => [[
                    'o' => 0, 'd' => 0, 'found' => true, 'duration_s' => 600, 'distance_m' => 7805,
                    'toll_state' => 2, 'toll_cents' => 375,
                ]],
            ],
            $answer
        );
    }

    public function testWithoutRoutingOptionsAndWithoutTollsNothingExtraIsSent(): void
    {
        $this->http->json(200, []);
        $this->client()->matrix([self::BASE, self::SPOT], [self::MALL], 'd', false);
        $request = $this->http->requests[0];
        self::assertSame(
            '{"origins":[{"waypoint":{"location":{"latLng":{"latitude":39.003,"longitude":-77.405}}}},'
            . '{"waypoint":{"location":{"latLng":{"latitude":38.96,"longitude":-77.36}}}}],'
            . '"destinations":[{"waypoint":{"location":{"latLng":{"latitude":38.9072,"longitude":-77.0369}}}}],'
            . '"travelMode":"DRIVE","routingPreference":"TRAFFIC_UNAWARE"}',
            $request['body']
        );
        self::assertSame(
            'X-Goog-FieldMask: originIndex,destinationIndex,status,condition,distanceMeters,duration',
            $request['headers'][2]
        );
        // No departure time, no traffic model: the duration cannot depend on the hour of the request.
        self::assertStringNotContainsString('departureTime', (string) $request['body']);
        self::assertStringNotContainsString('TRAFFIC_AWARE', (string) $request['body']);
    }

    public function testRouteModifiersGoOnEveryOriginAndOnlyWhenAnOptionIsOn(): void
    {
        $body = static fn (string $routeKey): array => json_decode(
            RoutesMatrixClient::body([self::BASE, self::SPOT], [self::MALL], $routeKey, false),
            true
        );
        foreach ($body('d')['origins'] as $origin) {
            self::assertArrayNotHasKey('routeModifiers', $origin);
        }
        foreach ([
            'dt' => ['avoidTolls' => true, 'avoidHighways' => false],
            'dh' => ['avoidTolls' => false, 'avoidHighways' => true],
            'dth' => ['avoidTolls' => true, 'avoidHighways' => true],
        ] as $routeKey => $modifiers) {
            $decoded = $body($routeKey);
            self::assertCount(2, $decoded['origins']);
            foreach ($decoded['origins'] as $origin) {
                self::assertSame($modifiers, $origin['routeModifiers']);
            }
            foreach ($decoded['destinations'] as $destination) {
                self::assertSame(['waypoint'], array_keys($destination));
            }
        }
        // tolls are asked with the request flag, not with "avoid tolls"
        self::assertArrayNotHasKey('extraComputations', $body('dt'));
        self::assertSame(['TOLLS'], json_decode(RoutesMatrixClient::body([self::BASE], [self::SPOT], 'd', true), true)['extraComputations']);
    }

    public function testCoordinatesAreTheRoundedKeyValues(): void
    {
        $decoded = json_decode(RoutesMatrixClient::body([[389600, -773600], [-338688, 1512093], [0, 1800000]], [[900000, -1]], 'd', false), true);
        self::assertSame(['latitude' => 38.96, 'longitude' => -77.36], $decoded['origins'][0]['waypoint']['location']['latLng']);
        self::assertSame(['latitude' => -33.8688, 'longitude' => 151.2093], $decoded['origins'][1]['waypoint']['location']['latLng']);
        self::assertSame(['latitude' => 0.0, 'longitude' => 180.0], $decoded['origins'][2]['waypoint']['location']['latLng']);
        self::assertSame(['latitude' => 90.0, 'longitude' => -0.0001], $decoded['destinations'][0]['waypoint']['location']['latLng']);
    }

    public function testATextThatIsNotARouteKeyIsAProgrammingError(): void
    {
        foreach (['', 'x', 'dhh', 'dht', 'D', 'd '] as $bad) {
            try {
                RoutesMatrixClient::avoids($bad);
                self::fail('"' . $bad . '" was taken as a route key');
            } catch (\LogicException $e) {
                self::assertStringContainsString('route key', $e->getMessage());
            }
        }
        self::assertSame([false, false], RoutesMatrixClient::avoids('d'));
        self::assertSame([true, true], RoutesMatrixClient::avoids('dth'));
    }

    public function testMoreThan625ElementsAreNeverSent(): void
    {
        $many = array_fill(0, 26, self::BASE);
        try {
            $this->client()->matrix($many, $many, 'd', false);
            self::fail('676 elements were accepted');
        } catch (\LogicException $e) {
            self::assertSame([], $this->http->requests);
        }
        $this->http->json(200, []);
        self::assertTrue($this->client()->matrix(array_fill(0, 25, self::BASE), array_fill(0, 25, self::SPOT), 'd', false)['ok']);
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        putenv('GOOGLE_API_KEY');
        self::assertSame(['ok' => false, 'error' => 'no_key', 'elements' => []], $this->client()->matrix([self::BASE], [self::SPOT], 'd', true));
        putenv('GOOGLE_API_KEY=');
        self::assertSame('no_key', $this->client()->matrix([self::BASE], [self::SPOT], 'd', true)['error']);
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->db->calls);
    }

    public function testNothingToAskAnswersWithoutACall(): void
    {
        self::assertSame(['ok' => true, 'error' => null, 'elements' => []], $this->client()->matrix([], [self::SPOT], 'd', true));
        self::assertSame([], $this->http->requests);
    }

    // ------------------------------------------------------------------------------------ parsing

    public function testDurationIsSecondsAsTextAndRoundsAtTheFirstFractionalDigit(): void
    {
        foreach ([
            '713s' => 713, '0s' => 0, '160s' => 160, '160.4s' => 160, '160.5s' => 161, '160.49s' => 160,
            '160.999999999s' => 161, '5382s' => 5382, '0.5s' => 1, '86400s' => 86400,
        ] as $text => $seconds) {
            self::assertSame($seconds, RoutesMatrixClient::seconds($text), $text);
        }
        foreach (['713', '713 s', 's', '-5s', '1e3s', '12.s', '.5s', '1.0000000001s', '713S', ' 713s', '713s ', '', null, 713, 713.0, ['713s']] as $bad) {
            self::assertNull(RoutesMatrixClient::seconds($bad), var_export($bad, true));
        }
    }

    public function testElementsComeInAnyOrderAndAMissingIndexIsZero(): void
    {
        $elements = $this->parsed([
            ['originIndex' => 1, 'destinationIndex' => 1, 'status' => [], 'condition' => 'ROUTE_EXISTS', 'distanceMeters' => 5598, 'duration' => '402s'],
            // origin 0, destination 1: JSON leaves the default value out
            ['destinationIndex' => 1, 'status' => [], 'condition' => 'ROUTE_EXISTS', 'distanceMeters' => 7259, 'duration' => '712s'],
            // origin 1, destination 0
            ['originIndex' => 1, 'status' => [], 'condition' => 'ROUTE_EXISTS', 'distanceMeters' => 2919, 'duration' => '361s'],
            // both 0, and no status at all
            ['condition' => 'ROUTE_EXISTS', 'distanceMeters' => 822, 'duration' => '160s'],
        ]);
        self::assertSame(
            [[1, 1, 402, 5598], [0, 1, 712, 7259], [1, 0, 361, 2919], [0, 0, 160, 822]],
            array_map(static fn (array $e): array => [$e['o'], $e['d'], $e['duration_s'], $e['distance_m']], $elements)
        );
        foreach ($elements as $element) {
            self::assertTrue($element['found']);
            self::assertSame(0, $element['toll_state']);
            self::assertNull($element['toll_cents']);
        }
    }

    public function testAMissingDistanceIsZeroMetres(): void
    {
        $elements = $this->parsed([['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '0s']]);
        self::assertSame(0, $elements[0]['distance_m']);
        self::assertSame(0, $elements[0]['duration_s']);
        self::assertTrue($elements[0]['found']);
    }

    public function testNoRouteIsAnAnswerOfItsOwn(): void
    {
        $plain = $this->parsed([['originIndex' => 1, 'status' => [], 'condition' => 'ROUTE_NOT_FOUND']]);
        self::assertSame(
            [['o' => 1, 'd' => 0, 'found' => false, 'duration_s' => 0, 'distance_m' => 0, 'toll_state' => 0, 'toll_cents' => null]],
            $plain
        );
        // asked with tolls: there is no route, so there is no toll to ask for again
        $asked = $this->parsed([['status' => [], 'condition' => 'ROUTE_NOT_FOUND']], true);
        self::assertSame(1, $asked[0]['toll_state']);
        self::assertFalse($asked[0]['found']);
    }

    public function testAnElementThatFailedIsLeftOutAndTheOthersStay(): void
    {
        $good = ['originIndex' => 1, 'destinationIndex' => 1, 'status' => [], 'condition' => 'ROUTE_EXISTS', 'distanceMeters' => 10, 'duration' => '5s'];
        $failed = [
            'a status code' => ['status' => ['code' => 3, 'message' => 'bad waypoint'], 'condition' => 'ROUTE_EXISTS', 'duration' => '5s'],
            'no condition' => ['status' => [], 'duration' => '5s', 'distanceMeters' => 10],
            'an unspecified condition' => ['status' => [], 'condition' => 'ROUTE_MATRIX_ELEMENT_CONDITION_UNSPECIFIED', 'duration' => '5s'],
            'a new condition' => ['status' => [], 'condition' => 'ROUTE_PARTIAL', 'duration' => '5s'],
            'no duration' => ['status' => [], 'condition' => 'ROUTE_EXISTS', 'distanceMeters' => 10],
            'a malformed duration' => ['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '5 min', 'distanceMeters' => 10],
            'a duration that is a number' => ['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => 5, 'distanceMeters' => 10],
            'a distance that is text' => ['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '5s', 'distanceMeters' => 'far'],
            'a negative distance' => ['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '5s', 'distanceMeters' => -1],
            'a status that is not an object' => ['status' => 'OK', 'condition' => 'ROUTE_EXISTS', 'duration' => '5s'],
            'an origin outside the request' => ['originIndex' => 2, 'status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '5s'],
            'a destination outside the request' => ['destinationIndex' => 7, 'status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '5s'],
            'an index that is text' => ['originIndex' => 'first', 'status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '5s'],
            'an item that is not an object' => 'ROUTE_EXISTS',
        ];
        foreach ($failed as $what => $item) {
            $elements = $this->parsed([$item, $good]);
            self::assertCount(1, $elements, $what);
            self::assertSame([1, 1], [$elements[0]['o'], $elements[0]['d']], $what);
        }
    }

    public function testOfTwoElementsForOnePairTheFirstIsKept(): void
    {
        $elements = $this->parsed([
            ['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '100s', 'distanceMeters' => 1],
            ['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '999s', 'distanceMeters' => 9],
            ['status' => [], 'condition' => 'ROUTE_NOT_FOUND'],
        ]);
        self::assertCount(1, $elements);
        self::assertSame(100, $elements[0]['duration_s']);
    }

    public function testTollStates(): void
    {
        $element = static fn (array $extra): array => $extra + ['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '600s', 'distanceMeters' => 7805];
        $toll = fn (array $extra, bool $asked = true): array => array_values(array_intersect_key(
            $this->parsed([$element($extra)], $asked)[0],
            ['toll_state' => 0, 'toll_cents' => 0]
        ));
        $priced = static fn (array ...$prices): array => ['travelAdvisory' => ['tollInfo' => ['estimatedPrice' => $prices]]];

        // not asked: 0, whatever the answer holds
        self::assertSame([0, null], $toll([], false));
        self::assertSame([0, null], $toll($priced(['currencyCode' => 'USD', 'units' => '3']), false));
        // asked and no toll information: no toll on the route
        self::assertSame([1, null], $toll([]));
        self::assertSame([1, null], $toll(['travelAdvisory' => []]));
        self::assertSame([1, null], $toll(['travelAdvisory' => ['speedReadingIntervals' => []]]));
        // a price in US dollars: units are text, nanos a number, either may be missing
        self::assertSame([2, 375], $toll($priced(['currencyCode' => 'USD', 'units' => '3', 'nanos' => 750000000])));
        self::assertSame([2, 440], $toll($priced(['currencyCode' => 'USD', 'units' => '4', 'nanos' => 400000000])));
        self::assertSame([2, 300], $toll($priced(['currencyCode' => 'USD', 'units' => '3'])));
        self::assertSame([2, 75], $toll($priced(['currencyCode' => 'USD', 'nanos' => 750000000])));
        self::assertSame([2, 0], $toll($priced(['currencyCode' => 'USD'])));
        self::assertSame([2, 1200], $toll($priced(['currencyCode' => 'USD', 'units' => 12])));
        // half a cent rounds away from zero; a fraction of a cent below that is dropped
        self::assertSame([2, 2], $toll($priced(['currencyCode' => 'USD', 'units' => '0', 'nanos' => 15000000])));
        self::assertSame([2, 1], $toll($priced(['currencyCode' => 'USD', 'units' => '0', 'nanos' => 14999999])));
        // the dollar price among others
        self::assertSame([2, 250], $toll($priced(['currencyCode' => 'CAD', 'units' => '9'], ['currencyCode' => 'USD', 'units' => '2', 'nanos' => 500000000])));
        // tolls on the route and no dollar price: unknown
        self::assertSame([3, null], $toll(['travelAdvisory' => ['tollInfo' => []]]));
        self::assertSame([3, null], $toll(['travelAdvisory' => ['tollInfo' => ['estimatedPrice' => []]]]));
        self::assertSame([3, null], $toll($priced(['currencyCode' => 'CAD', 'units' => '9'])));
        self::assertSame([3, null], $toll($priced(['currencyCode' => 'USD', 'units' => 'three'])));
        self::assertSame([3, null], $toll($priced(['currencyCode' => 'USD', 'units' => '-3'])));
        self::assertSame([3, null], $toll($priced(['currencyCode' => 'USD', 'units' => '3', 'nanos' => 1000000000])));
        self::assertSame([3, null], $toll($priced(['currencyCode' => 'USD', 'units' => '999999999'])));
    }

    // ------------------------------------------------------------------------------------ failures

    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3: string}> status, body, error, ledger code
     */
    public static function failures(): array
    {
        $error = static fn (int $code, string $status, string $message = 'x'): string => (string) json_encode(
            ['error' => ['code' => $code, 'message' => $message, 'status' => $status]]
        );
        return [
            'the API is not enabled for the project' => [403, (string) json_encode(['error' => [
                'code' => 403,
                'message' => 'Routes API has not been used in project 123 before or it is disabled.',
                'status' => 'PERMISSION_DENIED',
                'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'SERVICE_DISABLED', 'domain' => 'googleapis.com']],
            ]]), 'refused', 'PERMISSION_DENIED'],
            'the key may not call this API' => [403, $error(403, 'PERMISSION_DENIED', 'Requests to this API are blocked.'), 'refused', 'PERMISSION_DENIED'],
            'a 403 without a readable body' => [403, '<html>Forbidden</html>', 'refused', 'http_403'],
            'an error inside the array' => [403, '[' . $error(403, 'PERMISSION_DENIED') . ']', 'refused', 'PERMISSION_DENIED'],
            'a refusal that arrives with 200' => [200, '[' . $error(403, 'PERMISSION_DENIED') . ']', 'refused', 'PERMISSION_DENIED'],
            'too many requests' => [429, $error(429, 'RESOURCE_EXHAUSTED', 'Quota exceeded'), 'quota', 'RESOURCE_EXHAUSTED'],
            'a 429 without a body' => [429, '', 'quota', 'http_429'],
            'quota named with another status' => [403, $error(403, 'RESOURCE_EXHAUSTED'), 'quota', 'RESOURCE_EXHAUSTED'],
            'a bad request' => [400, $error(400, 'INVALID_ARGUMENT', 'Invalid waypoint'), 'bad_request', 'INVALID_ARGUMENT'],
            // a key Google does not know arrives as an invalid argument: its reason word says it is the key
            'a key that is not known' => [400, (string) json_encode(['error' => [
                'code' => 400, 'message' => 'API key not valid. Please pass a valid API key.', 'status' => 'INVALID_ARGUMENT',
                'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'API_KEY_INVALID', 'domain' => 'googleapis.com']],
            ]]), 'refused', 'INVALID_ARGUMENT'],
            'a key restricted to other callers' => [400, (string) json_encode(['error' => [
                'code' => 400, 'message' => 'x', 'status' => 'FAILED_PRECONDITION',
                'details' => [['reason' => 'API_KEY_IP_ADDRESS_BLOCKED']],
            ]]), 'refused', 'FAILED_PRECONDITION'],
            'quota named only by its reason' => [400, (string) json_encode(['error' => [
                'code' => 400, 'message' => 'x', 'status' => 'FAILED_PRECONDITION',
                'details' => [['reason' => 'RATE_LIMIT_EXCEEDED']],
            ]]), 'quota', 'FAILED_PRECONDITION'],
            'a server error' => [500, $error(500, 'INTERNAL'), 'upstream', 'INTERNAL'],
            'unavailable' => [503, 'Service Unavailable', 'upstream', 'http_503'],
            'a deadline on their side' => [504, $error(504, 'DEADLINE_EXCEEDED'), 'upstream', 'DEADLINE_EXCEEDED'],
            'not found' => [404, '', 'upstream', 'http_404'],
            'a 200 that is not JSON' => [200, 'OK', 'upstream', 'bad_body'],
            'a 200 that is an object with an error' => [200, $error(500, 'UNKNOWN'), 'upstream', 'UNKNOWN'],
            'a 200 that is a JSON text' => [200, '"ROUTE_EXISTS"', 'upstream', 'bad_body'],
            'a 200 that is an object' => [200, '{"routes":[]}', 'upstream', 'bad_body'],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testAFailedCallNamesItsKindAndCostsNothing(int $status, string $body, string $kind, string $code): void
    {
        $this->http->queue($status, $body);
        $answer = null;
        $lines = LogCapture::during(function () use (&$answer): void {
            $answer = $this->client()->matrix([self::BASE], [self::SPOT], 'd', false);
        });
        self::assertSame(['ok' => false, 'error' => $kind, 'elements' => []], $answer);
        self::assertSame(
            [['tp_routes_matrix', 0, '0.005', '0', ApiLedger::maskHash(RoutesMatrixClient::FIELD_MASK), $status, 12, $code]],
            $this->ledger()
        );
        self::assertCount(1, $lines);
        self::assertStringStartsWith('[tp] routes ', $lines[0]);
        self::assertStringNotContainsString(self::KEY, $lines[0]);
        self::assertStringNotContainsString('http', str_replace('http_', '', $lines[0]));
    }

    public function testTransportFailures(): void
    {
        foreach (['timeout' => 'timeout', 'connect' => 'upstream', 'curl_56' => 'upstream'] as $transport => $kind) {
            $http = (new FakeHttp())->fail($transport, 8001);
            $db = new RecordingDatabase();
            $answer = null;
            $lines = LogCapture::during(static function () use ($http, $db, &$answer): void {
                $answer = (new RoutesMatrixClient($http, new ApiLedger($db)))->matrix([self::BASE], [self::SPOT], 'd', true);
            });
            self::assertSame(['ok' => false, 'error' => $kind, 'elements' => []], $answer);
            $params = $db->only('INSERT INTO api_cost_events')['params'];
            self::assertSame('tp_routes_matrix_ent', $params[1]);
            self::assertSame(0, $params[2]);
            self::assertNull($params[6], 'no HTTP answer: no status');
            self::assertSame(8001, $params[7]);
            self::assertSame($transport, $params[8]);
            self::assertSame(['[tp] routes ' . $kind . ': ' . $transport], $lines);
        }
    }

    public function testARefusalIsLoggedWithGooglesReasonWord(): void
    {
        $this->http->json(403, ['error' => [
            'code' => 403,
            'message' => 'Routes API has not been used in project 123 before or it is disabled. Enable it by visiting https://console.developers.google.com/apis/api/routes.googleapis.com/overview?project=123 then retry.',
            'status' => 'PERMISSION_DENIED',
            'details' => [
                ['@type' => 'type.googleapis.com/google.rpc.Help', 'links' => [['url' => 'https://console.developers.google.com/x?project=123']]],
                ['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'SERVICE_DISABLED', 'domain' => 'googleapis.com'],
            ],
        ]]);
        $lines = LogCapture::during(function (): void {
            $this->client()->matrix([self::BASE], [self::SPOT], 'd', false);
        });
        // a status word and a reason word: no sentence of theirs, no address
        self::assertSame(['[tp] routes refused: PERMISSION_DENIED SERVICE_DISABLED'], $lines);
    }

    public function testABadRequestIsLoggedWithGooglesSentenceAndNoAddressOrKey(): void
    {
        $this->http->json(400, ['error' => [
            'code' => 400,
            'message' => "Invalid value at 'origins[0]' see https://example.org/help?key=" . self::KEY . "&x=1\nAPI key not valid: " . self::KEY,
            'status' => 'INVALID_ARGUMENT',
        ]]);
        $lines = LogCapture::during(function (): void {
            $this->client()->matrix([self::BASE], [self::SPOT], 'd', false);
        });
        self::assertCount(1, $lines);
        self::assertStringStartsWith("[tp] routes bad request: Invalid value at 'origins[0]' see [url]", $lines[0]);
        self::assertStringNotContainsString('https://', $lines[0]);
        self::assertStringNotContainsString('example.org', $lines[0]);
        self::assertLessThanOrEqual(230, strlen($lines[0]));

        // without a sentence the line still says what happened
        $this->http->queue(400, '{}');
        $lines = LogCapture::during(function (): void {
            $this->client()->matrix([self::BASE], [self::SPOT], 'd', false);
        });
        self::assertSame(['[tp] routes bad request: (no message)'], $lines);
    }

    // ------------------------------------------------------------------------------------ the ledger

    public function testASuccessfulCallIsMeteredByElementWithTheSkuOfWhatWasAsked(): void
    {
        $item = ['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '600s', 'distanceMeters' => 7805];
        // plain: Essentials
        $this->http->json(200, [$item]);
        $this->client()->matrix([self::BASE, self::SPOT], [self::MALL, self::BASE, self::SPOT], 'd', false);
        // avoid tolls or highways alone does not change what Google bills: still Essentials
        $this->http->json(200, [$item]);
        $this->client()->matrix([self::BASE], [self::SPOT], 'dth', false);
        // toll calculation: Enterprise
        $this->http->json(200, [$item]);
        $this->client()->matrix([self::BASE], [self::SPOT, self::MALL], 'd', true);

        $plainMask = ApiLedger::maskHash('originIndex,destinationIndex,status,condition,distanceMeters,duration');
        $tollMask = ApiLedger::maskHash('originIndex,destinationIndex,status,condition,distanceMeters,duration,travelAdvisory.tollInfo');
        self::assertNotSame($plainMask, $tollMask);
        self::assertSame(
            [
                ['tp_routes_matrix', 6, '0.005', '0.03', $plainMask, 200, 12, null],
                ['tp_routes_matrix', 1, '0.005', '0.005', $plainMask, 200, 12, null],
                ['tp_routes_matrix_ent', 2, '0.015', '0.03', $tollMask, 200, 12, null],
            ],
            $this->ledger()
        );
    }

    public function testNeitherTheKeyNorAnAddressReachesTheLedgerOrTheLog(): void
    {
        $this->http->json(200, [['status' => [], 'condition' => 'ROUTE_EXISTS', 'duration' => '600s']]);
        $this->http->json(403, ['error' => ['code' => 403, 'message' => 'key=' . self::KEY, 'status' => 'PERMISSION_DENIED']]);
        $this->http->fail('timeout');
        $this->http->queue(500, 'https://routes.googleapis.com/?key=' . self::KEY);
        $lines = LogCapture::during(function (): void {
            for ($i = 0; $i < 4; $i++) {
                $this->client()->matrix([self::BASE], [self::SPOT], 'dt', true);
            }
        });
        self::assertCount(4, $this->db->find('INSERT INTO api_cost_events'));
        $written = json_encode([$this->db->calls, $lines]);
        self::assertStringNotContainsString(self::KEY, (string) $written);
        self::assertStringNotContainsString('googleapis.com', (string) $written);
        self::assertStringNotContainsString('https:', (string) $written);
        // the key travels in its header and nowhere else
        foreach ($this->http->requests as $request) {
            self::assertStringNotContainsString(self::KEY, $request['url']);
            self::assertStringNotContainsString(self::KEY, (string) $request['body']);
            self::assertSame(1, count(array_filter($request['headers'], static fn (string $h): bool => str_contains($h, self::KEY))));
        }
    }
}
