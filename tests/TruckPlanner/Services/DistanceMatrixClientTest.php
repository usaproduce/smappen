<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FakeHttp;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Services\Google\DistanceMatrixClient;
use PHPUnit\Framework\TestCase;

/**
 * The legacy Distance Matrix client against stubbed HTTP: the request as Google's reference describes it
 * (read 2026-10-05), the statuses of the answer and of its elements, and a ledger and a log that never
 * hold the address of the request, because the key is in it.
 */
final class DistanceMatrixClientTest extends TestCase
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

    private function client(): DistanceMatrixClient
    {
        return new DistanceMatrixClient($this->http, new ApiLedger($this->db));
    }

    /**
     * @return array<string, mixed>
     */
    private static function cell(int $seconds, int $metres): array
    {
        return [
            'distance' => ['text' => '7.8 km', 'value' => $metres],
            'duration' => ['text' => '10 mins', 'value' => $seconds],
            'status' => 'OK',
        ];
    }

    public function testTheRequest(): void
    {
        $this->http->json(200, [
            'destination_addresses' => ['x', 'y'],
            'origin_addresses' => ['z'],
            'rows' => [['elements' => [self::cell(600, 7805), self::cell(1975, 37100)]]],
            'status' => 'OK',
        ]);
        $answer = $this->client()->matrix([self::BASE], [self::SPOT, self::MALL], 'dth');

        self::assertCount(1, $this->http->requests);
        $request = $this->http->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertNull($request['body']);
        self::assertSame(
            'https://maps.googleapis.com/maps/api/distancematrix/json'
            . '?origins=39.0030%2C-77.4050&destinations=38.9600%2C-77.3600%7C38.9072%2C-77.0369'
            . '&mode=driving&units=metric&avoid=tolls%7Chighways&key=' . self::KEY,
            $request['url']
        );
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        self::assertSame(
            [
                'origins' => '39.0030,-77.4050',
                'destinations' => '38.9600,-77.3600|38.9072,-77.0369',
                'mode' => 'driving',
                'units' => 'metric',
                'avoid' => 'tolls|highways',
                'key' => self::KEY,
            ],
            $query
        );
        self::assertSame(['Accept: application/json'], $request['headers']);
        self::assertSame(3, $request['connect_timeout_s']);
        self::assertSame(8, $request['timeout_s']);

        self::assertSame(
            [
                'ok' => true,
                'error' => null,
                'elements' => [
                    ['o' => 0, 'd' => 0, 'found' => true, 'duration_s' => 600, 'distance_m' => 7805, 'toll_state' => 0, 'toll_cents' => null],
                    ['o' => 0, 'd' => 1, 'found' => true, 'duration_s' => 1975, 'distance_m' => 37100, 'toll_state' => 0, 'toll_cents' => null],
                ],
            ],
            $answer
        );
    }

    public function testAvoidHoldsOnlyTheOptionsThatAreOn(): void
    {
        $avoid = static function (string $routeKey): ?string {
            parse_str(DistanceMatrixClient::query([self::BASE], [self::SPOT], $routeKey, 'k'), $query);
            return $query['avoid'] ?? null;
        };
        self::assertNull($avoid('d'));
        self::assertSame('tolls', $avoid('dt'));
        self::assertSame('highways', $avoid('dh'));
        self::assertSame('tolls|highways', $avoid('dth'));
        // no departure time and no traffic model: the duration does not depend on the hour of the request
        self::assertStringNotContainsString('departure_time', DistanceMatrixClient::query([self::BASE], [self::SPOT], 'd', 'k'));
        self::assertStringNotContainsString('traffic_model', DistanceMatrixClient::query([self::BASE], [self::SPOT], 'd', 'k'));
    }

    public function testAPointKeyIsWrittenWithFourDecimals(): void
    {
        foreach ([
            [[390030, -774050], '39.0030,-77.4050'],
            [[389600, -773600], '38.9600,-77.3600'],
            [[0, 0], '0.0000,0.0000'],
            [[-1, 1], '-0.0001,0.0001'],
            [[900000, -1800000], '90.0000,-180.0000'],
            [[-338688, 1512093], '-33.8688,151.2093'],
            [[5, -99], '0.0005,-0.0099'],
        ] as [$key, $text]) {
            self::assertSame($text, DistanceMatrixClient::place($key));
        }
    }

    public function testElementsFollowTheRowsAndColumnsOfTheRequest(): void
    {
        $decoded = ['rows' => [
            ['elements' => [self::cell(10, 100), ['status' => 'ZERO_RESULTS'], self::cell(30, 300)]],
            ['elements' => [['status' => 'NOT_FOUND'], self::cell(50, 500), ['status' => 'MAX_ROUTE_LENGTH_EXCEEDED']]],
        ]];
        $elements = DistanceMatrixClient::elements($decoded, 2, 3);
        self::assertSame(
            [[0, 0, true, 10, 100], [0, 1, false, 0, 0], [0, 2, true, 30, 300], [1, 0, false, 0, 0], [1, 1, true, 50, 500]],
            array_map(static fn (array $e): array => [$e['o'], $e['d'], $e['found'], $e['duration_s'], $e['distance_m']], $elements)
        );
        foreach ($elements as $element) {
            // this API knows no toll amounts
            self::assertSame(0, $element['toll_state']);
            self::assertNull($element['toll_cents']);
        }
    }

    public function testAnElementThatCannotBeReadIsLeftOut(): void
    {
        $bad = [
            ['status' => 'OK', 'duration' => ['value' => 10]],                                   // no distance
            ['status' => 'OK', 'distance' => ['value' => 10]],                                   // no duration
            ['status' => 'OK', 'duration' => ['value' => '10'], 'distance' => ['value' => 10]],  // text
            ['status' => 'OK', 'duration' => ['value' => -1], 'distance' => ['value' => 10]],
            ['status' => 'UNKNOWN_ERROR'],
            ['duration' => ['value' => 10], 'distance' => ['value' => 10]],                      // no status
            'OK',
            null,
        ];
        $decoded = ['rows' => [['elements' => array_merge($bad, [self::cell(7, 70)])]]];
        $elements = DistanceMatrixClient::elements($decoded, 1, count($bad) + 1);
        self::assertCount(1, $elements);
        self::assertSame(count($bad), $elements[0]['d']);

        // rows or elements that are missing are simply not there
        self::assertSame([], DistanceMatrixClient::elements([], 2, 2));
        self::assertSame([], DistanceMatrixClient::elements(['rows' => 'none'], 2, 2));
        self::assertCount(1, DistanceMatrixClient::elements(['rows' => [['elements' => [self::cell(1, 1)]]]], 2, 2));
        // and more than was asked for is not taken
        self::assertCount(1, DistanceMatrixClient::elements(['rows' => [['elements' => [self::cell(1, 1), self::cell(2, 2)]], ['elements' => [self::cell(3, 3)]]]], 1, 1));
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string, 3: string}> status, body, error, ledger code
     */
    public static function failures(): array
    {
        $refusal = static fn (string $status, string $message = ''): string => (string) json_encode(
            ['destination_addresses' => [], 'origin_addresses' => [], 'rows' => [], 'status' => $status, 'error_message' => $message]
        );
        return [
            'the key may not use this API' => [200, $refusal('REQUEST_DENIED', 'This API project is not authorized to use this API.'), 'refused', 'REQUEST_DENIED'],
            'a refusal that arrives with 403' => [403, $refusal('REQUEST_DENIED'), 'refused', 'REQUEST_DENIED'],
            'a 403 without a readable body' => [403, 'Forbidden', 'refused', 'http_403'],
            'too many requests' => [200, $refusal('OVER_QUERY_LIMIT'), 'quota', 'OVER_QUERY_LIMIT'],
            'the daily limit' => [200, $refusal('OVER_DAILY_LIMIT'), 'quota', 'OVER_DAILY_LIMIT'],
            'a 429 without a body' => [429, '', 'quota', 'http_429'],
            'an invalid request' => [200, $refusal('INVALID_REQUEST'), 'bad_request', 'INVALID_REQUEST'],
            'too many elements' => [200, $refusal('MAX_ELEMENTS_EXCEEDED'), 'bad_request', 'MAX_ELEMENTS_EXCEEDED'],
            'too many origins' => [200, $refusal('MAX_DIMENSIONS_EXCEEDED'), 'bad_request', 'MAX_DIMENSIONS_EXCEEDED'],
            'a server error of theirs' => [200, $refusal('UNKNOWN_ERROR'), 'upstream', 'UNKNOWN_ERROR'],
            'a status nobody knows' => [200, $refusal('SOMETHING_NEW'), 'upstream', 'SOMETHING_NEW'],
            'a 500' => [500, 'Internal Server Error', 'upstream', 'http_500'],
            'a 200 that is not JSON' => [200, '<html>', 'upstream', 'bad_body'],
            'a 200 without a status' => [200, '{"rows":[]}', 'upstream', 'bad_body'],
            'OK with a failing transport status' => [502, '{"status":"OK","rows":[]}', 'upstream', 'http_502'],
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
            $answer = $this->client()->matrix([self::BASE], [self::SPOT], 'd');
        });
        self::assertSame(['ok' => false, 'error' => $kind, 'elements' => []], $answer);
        $params = $this->db->only('INSERT INTO api_cost_events')['params'];
        self::assertSame(['tp_distance_matrix', 0, '0.005', '0', null, $status, 12, $code], array_slice($params, 1));
        self::assertCount(1, $lines);
        self::assertStringStartsWith('[tp] distance matrix ', $lines[0]);
        self::assertStringContainsString($code, $lines[0]);
    }

    public function testTransportFailures(): void
    {
        foreach (['timeout' => 'timeout', 'connect' => 'upstream', 'curl_35' => 'upstream'] as $transport => $kind) {
            $http = (new FakeHttp())->fail($transport);
            $db = new RecordingDatabase();
            $answer = null;
            $lines = LogCapture::during(static function () use ($http, $db, &$answer): void {
                $answer = (new DistanceMatrixClient($http, new ApiLedger($db)))->matrix([self::BASE], [self::SPOT], 'd');
            });
            self::assertSame(['ok' => false, 'error' => $kind, 'elements' => []], $answer);
            $params = $db->only('INSERT INTO api_cost_events')['params'];
            self::assertNull($params[6]);
            self::assertSame($transport, $params[8]);
            self::assertSame(['[tp] distance matrix ' . $kind . ': ' . $transport], $lines);
        }
    }

    public function testASuccessfulCallIsMeteredByElement(): void
    {
        $this->http->json(200, ['status' => 'OK', 'rows' => []]);
        self::assertSame(['ok' => true, 'error' => null, 'elements' => []], $this->client()->matrix([self::BASE, self::SPOT], [self::MALL, self::BASE, self::SPOT], 'd'));
        $params = $this->db->only('INSERT INTO api_cost_events')['params'];
        self::assertSame(['tp_distance_matrix', 6, '0.005', '0.03', null, 200, 12, null], array_slice($params, 1));
    }

    public function testTheLimitsOfTheApiAreKept(): void
    {
        $one = [self::BASE];
        foreach ([[26, 1], [1, 26], [11, 10], [5, 21]] as [$origins, $destinations]) {
            try {
                $this->client()->matrix(array_fill(0, $origins, self::BASE), array_fill(0, $destinations, self::SPOT), 'd');
                self::fail($origins . ' x ' . $destinations . ' was accepted');
            } catch (\LogicException $e) {
                self::assertSame([], $this->http->requests);
            }
        }
        $this->http->json(200, ['status' => 'OK', 'rows' => []]);
        $this->http->json(200, ['status' => 'OK', 'rows' => []]);
        self::assertTrue($this->client()->matrix(array_fill(0, 10, self::BASE), array_fill(0, 10, self::SPOT), 'd')['ok']);
        self::assertTrue($this->client()->matrix($one, array_fill(0, 25, self::SPOT), 'd')['ok']);
    }

    public function testWithoutAKeyNothingIsSent(): void
    {
        putenv('GOOGLE_API_KEY');
        self::assertSame(['ok' => false, 'error' => 'no_key', 'elements' => []], $this->client()->matrix([self::BASE], [self::SPOT], 'd'));
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->db->calls);
    }

    public function testTheAddressWithTheKeyNeverReachesTheLedgerOrTheLog(): void
    {
        $this->http->json(200, ['status' => 'OK', 'rows' => [['elements' => [self::cell(600, 7805)]]]]);
        $this->http->json(200, [
            'status' => 'REQUEST_DENIED',
            'error_message' => 'The provided API key is invalid. See https://developers.google.com/maps?key=' . self::KEY . ' key=' . self::KEY,
        ]);
        $this->http->json(200, ['status' => 'INVALID_REQUEST', 'error_message' => "Invalid 'origins' parameter. key=" . self::KEY]);
        $this->http->fail('timeout');
        $this->http->queue(500, 'https://maps.googleapis.com/maps/api/distancematrix/json?key=' . self::KEY);
        $lines = LogCapture::during(function (): void {
            for ($i = 0; $i < 5; $i++) {
                $this->client()->matrix([self::BASE], [self::SPOT], 'dt');
            }
        });
        self::assertCount(5, $this->db->find('INSERT INTO api_cost_events'));
        $written = (string) json_encode([$this->db->calls, $lines]);
        self::assertStringNotContainsString(self::KEY, $written);
        self::assertStringNotContainsString('googleapis.com', $written);
        self::assertStringNotContainsString('https:', $written);
        self::assertStringNotContainsString('origins=', $written);

        // the refusal and the bad request say why, in the API's own sentence, without the address
        self::assertSame('[tp] distance matrix refused: REQUEST_DENIED The provided API key is invalid. See [url] key=[redacted]', $lines[0]);
        self::assertSame("[tp] distance matrix bad request: INVALID_REQUEST Invalid 'origins' parameter. key=[redacted]", $lines[1]);
        self::assertSame('[tp] distance matrix timeout: timeout', $lines[2]);
        self::assertSame('[tp] distance matrix upstream: http_500', $lines[3]);
    }
}
