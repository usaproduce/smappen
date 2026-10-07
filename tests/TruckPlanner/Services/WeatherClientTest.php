<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FakeHttp;
use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Upstream\WeatherClient;
use PHPUnit\Framework\TestCase;

/**
 * The weather client against stubbed HTTP. The payloads have the shape api.weather.gov answered with on
 * 2026-10-05 for the grid cell of Sterling, VA (LWX 83,74).
 */
final class WeatherClientTest extends TestCase
{
    private const CONTACT = 'ops@example.test';
    private const POINTS_URL = 'https://api.weather.gov/points/39.003,-77.405';
    private const HOURLY_URL = 'https://api.weather.gov/gridpoints/LWX/83,74/forecast/hourly';
    private const PAD = 93600;

    private FixedClock $clock;
    private MemoryCache $store;
    private FakeHttp $http;
    private RecordingDatabase $db;

    /** @var list<int> the seconds the client asked to wait */
    private array $waits = [];

    /** @var array<string, string|false> */
    private array $envBefore = [];

    protected function setUp(): void
    {
        $this->fresh();
        foreach (['TP_CONTACT_EMAIL', 'MAIL_FROM'] as $name) {
            $this->envBefore[$name] = getenv($name);
            unset($_ENV[$name]);
            putenv($name);
        }
        putenv('TP_CONTACT_EMAIL=' . self::CONTACT);
    }

    protected function tearDown(): void
    {
        TpCache::wire();
        foreach ($this->envBefore as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    /** An empty cache, an empty queue of answers and an empty ledger, at the moment the tests start from. */
    private function fresh(): void
    {
        $this->clock = new FixedClock('2026-10-05 09:36:06');
        $this->store = new MemoryCache();
        TpCache::wire($this->clock, $this->store);
        $this->http = new FakeHttp();
        $this->db = new RecordingDatabase();
        $this->waits = [];
    }

    private function client(): WeatherClient
    {
        return new WeatherClient($this->http, new ApiLedger($this->db), $this->clock, function (int $seconds): void {
            $this->waits[] = $seconds;
        });
    }

    /** The answer of the points request, cut down to what matters plus some of what does not. */
    private function queuePoints(): void
    {
        $this->http->json(200, [
            'id' => 'https://api.weather.gov/points/39.003,-77.405',
            'type' => 'Feature',
            'properties' => [
                'cwa' => 'LWX', 'gridId' => 'LWX', 'gridX' => 83, 'gridY' => 74,
                'forecastHourly' => 'https://api.weather.gov/gridpoints/LWX/83,74/forecast/hourly',
                'timeZone' => 'America/New_York',
            ],
        ], ['cache-control' => 'public, max-age=86400, s-maxage=120', 'expires' => 'Tue, 06 Oct 2026 09:35:56 GMT']);
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed> one period as the service sends it
     */
    private static function period(string $start, array $over = []): array
    {
        return $over + [
            'number' => 1, 'name' => '', 'startTime' => $start, 'endTime' => $start, 'isDaytime' => false,
            'temperature' => 58, 'temperatureUnit' => 'F', 'temperatureTrend' => null,
            'probabilityOfPrecipitation' => ['unitCode' => 'wmoUnit:percent', 'value' => 0],
            'dewpoint' => ['unitCode' => 'wmoUnit:degC', 'value' => 14.444444444444445],
            'relativeHumidity' => ['unitCode' => 'wmoUnit:percent', 'value' => 100],
            'windSpeed' => '1 mph', 'windDirection' => 'W',
            'icon' => 'https://api.weather.gov/icons/land/night/fog?size=small',
            'shortForecast' => 'Areas Of Fog', 'detailedForecast' => '',
        ];
    }

    /**
     * @param list<array<string, mixed>>|null $periods
     * @param array<string, string> $headers
     */
    private function queueHourly(?array $periods = null, array $headers = ['expires' => 'Mon, 05 Oct 2026 09:58:32 GMT'], string $generatedAt = '2026-10-05T08:58:32+00:00'): void
    {
        $this->http->json(200, ['type' => 'Feature', 'properties' => [
            'units' => 'us', 'forecastGenerator' => 'HourlyForecastGenerator', 'generatedAt' => $generatedAt,
            'updateTime' => '2026-10-05T08:53:03+00:00', 'validTimes' => '2026-10-05T02:00:00+00:00/P7DT23H',
            'periods' => $periods ?? [
                self::period('2026-10-05T04:00:00-04:00'),
                self::period('2026-10-05T05:00:00-04:00', ['temperature' => 57, 'windSpeed' => '5 to 10 mph', 'shortForecast' => 'Chance Rain Showers',
                    'probabilityOfPrecipitation' => ['unitCode' => 'wmoUnit:percent', 'value' => null]]),
            ],
        ]], $headers);
    }

    // ------------------------------------------------------------------------------------ the two requests

    public function testTwoRequestsWithTheContactAddressAndNoKey(): void
    {
        $this->queuePoints();
        $this->queueHourly();
        // More decimals than four are rounded away: the service redirects them, and redirects are not followed.
        $answer = $this->client()->hourly(39.00301, -77.40499);

        self::assertCount(2, $this->http->requests);
        [$points, $hourly] = $this->http->requests;
        self::assertSame('GET', $points['method']);
        self::assertSame(self::POINTS_URL, $points['url']);
        self::assertSame(self::HOURLY_URL, $hourly['url']);
        foreach ([$points, $hourly] as $request) {
            self::assertSame(['User-Agent: (TruckPlanner, ' . self::CONTACT . ')', 'Accept: application/geo+json'], $request['headers']);
            self::assertNull($request['body']);
            self::assertSame(3, $request['connect_timeout_s']);
            self::assertSame(6, $request['timeout_s']);
            self::assertStringNotContainsString('?', $request['url']);
            foreach ($request['headers'] as $header) {
                self::assertStringNotContainsStringIgnoringCase('feature-flags', $header);
            }
        }

        self::assertSame('fresh', $answer['state']);
        self::assertSame('2026-10-05T08:58:32+00:00', $answer['generated_at']);
        // exactly the six fields the day contexts are built from, in the service's order
        self::assertSame(
            [
                [
                    'startTime' => '2026-10-05T04:00:00-04:00', 'temperature' => 58, 'temperatureUnit' => 'F',
                    'probabilityOfPrecipitation' => 0, 'windSpeed' => '1 mph', 'shortForecast' => 'Areas Of Fog',
                ],
                [
                    'startTime' => '2026-10-05T05:00:00-04:00', 'temperature' => 57, 'temperatureUnit' => 'F',
                    'probabilityOfPrecipitation' => null, 'windSpeed' => '5 to 10 mph', 'shortForecast' => 'Chance Rain Showers',
                ],
            ],
            $answer['periods']
        );
        // one ledger row per call, at no cost
        $rows = array_map(static fn (array $call): array => array_slice($call['params'], 1), $this->db->find('INSERT INTO api_cost_events'));
        self::assertSame(
            [['tp_nws_points', 1, '0', '0', null, 200, 12, null], ['tp_nws_hourly', 1, '0', '0', null, 200, 12, null]],
            $rows
        );
    }

    public function testAProbabilityTheServiceDoesNotGiveStaysNull(): void
    {
        $this->queuePoints();
        $this->queueHourly([
            self::period('2026-10-05T04:00:00-04:00', ['probabilityOfPrecipitation' => ['unitCode' => 'wmoUnit:percent', 'value' => null]]),
            self::period('2026-10-05T05:00:00-04:00', ['probabilityOfPrecipitation' => null]),
            self::period('2026-10-05T06:00:00-04:00', ['probabilityOfPrecipitation' => ['unitCode' => 'wmoUnit:percent']]),
            self::period('2026-10-05T07:00:00-04:00', ['probabilityOfPrecipitation' => ['value' => 0]]),
            self::period('2026-10-05T08:00:00-04:00', ['probabilityOfPrecipitation' => ['value' => 35.5]]),
        ]);
        $chances = array_column($this->client()->hourly(39.003, -77.405)['periods'], 'probabilityOfPrecipitation');
        self::assertSame([null, null, null, 0, 35.5], $chances);
        // and null survives the cache: the second call reads the stored copy
        self::assertSame([null, null, null, 0, 35.5], array_column($this->client()->hourly(39.003, -77.405)['periods'], 'probabilityOfPrecipitation'));
        self::assertCount(2, $this->http->requests);
    }

    public function testAPeriodKeepsOnlyWellFormedFields(): void
    {
        $this->queuePoints();
        $this->queueHourly([
            self::period('2026-10-05T04:00:00-04:00', ['temperature' => null, 'temperatureUnit' => null, 'windSpeed' => null, 'shortForecast' => null]),
            self::period('2026-10-05T05:00:00-04:00', ['temperature' => '58', 'windSpeed' => 5, 'shortForecast' => ['Sunny']]),
            ['temperature' => 60],                                  // no start time: not a period
            'period',
            self::period('2026-10-05T06:00:00-04:00', ['temperature' => 14.5, 'temperatureUnit' => 'C']),
        ]);
        $periods = $this->client()->hourly(39.003, -77.405)['periods'];
        self::assertCount(3, $periods);
        self::assertSame(
            ['startTime' => '2026-10-05T04:00:00-04:00', 'temperature' => null, 'temperatureUnit' => null,
                'probabilityOfPrecipitation' => 0, 'windSpeed' => null, 'shortForecast' => null],
            $periods[0]
        );
        self::assertNull($periods[1]['temperature']);
        self::assertNull($periods[1]['windSpeed']);
        self::assertNull($periods[1]['shortForecast']);
        self::assertSame(14.5, $periods[2]['temperature']);
        self::assertSame('C', $periods[2]['temperatureUnit']);
    }

    // ------------------------------------------------------------------------------------ caching

    public function testTheGridCellOfAPointIsRememberedForFourteenDays(): void
    {
        $this->queuePoints();
        $this->queueHourly();
        $this->client()->hourly(39.003, -77.405);
        self::assertSame(1209600 + self::PAD, $this->store->ttls['tp:nws:pt:39.003,-77.405']);
        self::assertSame(
            ['gridId' => 'LWX', 'gridX' => 83, 'gridY' => 74],
            json_decode($this->store->values['tp:nws:pt:39.003,-77.405'], true)['v']
        );

        // 13 days later the forecast is fetched again, the grid cell is not
        $this->clock->advance(13 * 86400);
        $this->queueHourly();
        $this->client()->hourly(39.003, -77.405);
        self::assertSame(self::HOURLY_URL, $this->http->requests[2]['url']);

        // after 14 days the point is looked up again
        $this->clock->advance(86400 + 1);
        $this->queuePoints();
        $this->queueHourly();
        $this->client()->hourly(39.003, -77.405);
        self::assertSame([self::POINTS_URL, self::HOURLY_URL], [$this->http->requests[3]['url'], $this->http->requests[4]['url']]);
        self::assertSame(0, $this->http->pending());
    }

    public function testWithAnExpiresHeaderTheCopyIsFreshUntilThen(): void
    {
        $this->queuePoints();
        // fetched 09:36:06, expires 09:58:32: 1,346 seconds, more than the minimum of 600
        $this->queueHourly(null, ['expires' => 'Mon, 05 Oct 2026 09:58:32 GMT', 'cache-control' => 'public, max-age=1346, s-maxage=3600']);
        self::assertSame('fresh', $this->client()->hourly(39.003, -77.405)['state']);

        $stored = json_decode($this->store->values['tp:nws:hr:LWX:83,74'], true)['v'];
        self::assertSame($this->clock->epoch(), $stored['fetched']);
        self::assertSame($this->clock->epoch() + 1346, $stored['expires']);
        self::assertSame('2026-10-05T08:58:32+00:00', $stored['generated_at']);
        self::assertSame(604800 + self::PAD, $this->store->ttls['tp:nws:hr:LWX:83,74']);

        $this->clock->advance(1345);
        self::assertSame('fresh', $this->client()->hourly(39.003, -77.405)['state']);
        self::assertCount(2, $this->http->requests, 'still the stored copy');

        $this->clock->advance(1);
        $this->queueHourly(null, ['expires' => 'Mon, 05 Oct 2026 10:58:40 GMT'], '2026-10-05T09:58:40+00:00');
        $again = $this->client()->hourly(39.003, -77.405);
        self::assertCount(3, $this->http->requests, 'expired: fetched again');
        self::assertSame('fresh', $again['state']);
        self::assertSame('2026-10-05T09:58:40+00:00', $again['generated_at']);
    }

    public function testWithoutAnExpiresHeaderTheCopyIsFreshForAnHour(): void
    {
        $this->queuePoints();
        $this->queueHourly(null, []);
        $this->client()->hourly(39.003, -77.405);
        $stored = json_decode($this->store->values['tp:nws:hr:LWX:83,74'], true)['v'];
        self::assertSame($stored['fetched'] + 3600, $stored['expires']);

        $this->clock->advance(3599);
        self::assertSame('fresh', $this->client()->hourly(39.003, -77.405)['state']);
        self::assertCount(2, $this->http->requests);
        $this->clock->advance(1);
        $this->queueHourly(null, []);
        $this->client()->hourly(39.003, -77.405);
        self::assertCount(3, $this->http->requests);
    }

    public function testACopyIsFreshForAtLeastTenMinutesAndNeverLongerThanSixHours(): void
    {
        // an Expires that is already past, as the service sends with some answers
        $this->queuePoints();
        $this->queueHourly(null, ['expires' => 'Mon, 05 Oct 2026 09:36:00 GMT']);
        $this->client()->hourly(39.003, -77.405);
        $this->clock->advance(599);
        $this->client()->hourly(39.003, -77.405);
        self::assertCount(2, $this->http->requests);
        $this->clock->advance(1);
        // a malformed date counts as none; one far ahead is not believed
        $this->queueHourly(null, ['expires' => 'Fri, 01 Jan 2100 00:00:00 GMT']);
        $this->client()->hourly(39.003, -77.405);
        self::assertCount(3, $this->http->requests);
        $stored = json_decode($this->store->values['tp:nws:hr:LWX:83,74'], true)['v'];
        self::assertSame($stored['fetched'] + 21600, $stored['expires']);

        $this->clock->advance(21600);
        $this->queueHourly(null, ['expires' => 'tomorrow']);
        $this->client()->hourly(39.003, -77.405);
        $stored = json_decode($this->store->values['tp:nws:hr:LWX:83,74'], true)['v'];
        self::assertSame($stored['fetched'] + 3600, $stored['expires']);
    }

    // ------------------------------------------------------------------------------------ failures

    public function testAServerErrorIsRetriedOnceAfterASecond(): void
    {
        $this->queuePoints();
        $this->http->queue(503, 'Service Unavailable');
        $this->queueHourly();
        $answer = $this->client()->hourly(39.003, -77.405);
        self::assertSame('fresh', $answer['state']);
        self::assertSame([1], $this->waits);
        self::assertCount(3, $this->http->requests);
        self::assertSame(self::HOURLY_URL, $this->http->requests[2]['url']);
        // three calls, three ledger rows; the failed one carries its code
        $errors = array_map(static fn (array $call): mixed => $call['params'][8], $this->db->find('INSERT INTO api_cost_events'));
        self::assertSame([null, 'http_503', null], $errors);
    }

    public function testATimeOutIsRetriedOnceAndThenGivenUp(): void
    {
        $this->queuePoints();
        $this->http->fail('timeout');
        $this->http->fail('timeout');
        $lines = LogCapture::during(function (): void {
            self::assertSame(['state' => 'unavailable', 'generated_at' => null, 'periods' => []], $this->client()->hourly(39.003, -77.405));
        });
        self::assertSame([1], $this->waits);
        self::assertCount(3, $this->http->requests);
        self::assertSame(['[tp] weather hourly failed: timeout'], $lines);
    }

    public function testWhatIsNotAServerErrorIsNotRetried(): void
    {
        foreach ([400, 403, 404, 429] as $status) {
            $this->fresh();
            $this->queuePoints();
            $this->http->queue($status, '{"title":"problem"}');
            LogCapture::during(function (): void {
                self::assertSame('unavailable', $this->client()->hourly(39.003, -77.405)['state']);
            });
            self::assertSame([], $this->waits, (string) $status);
            self::assertCount(2, $this->http->requests);
        }
        $this->fresh();
        $this->queuePoints();
        $this->http->fail('connect');
        LogCapture::during(function (): void {
            self::assertSame('unavailable', $this->client()->hourly(39.003, -77.405)['state']);
        });
        self::assertSame([], $this->waits);
    }

    public function testWhileTheServiceIsDownTheLastCopyIsServedAsStaleForSixHours(): void
    {
        $this->queuePoints();
        $this->queueHourly();
        $first = $this->client()->hourly(39.003, -77.405);
        self::assertSame('fresh', $first['state']);

        // an hour later the copy has expired and the service fails twice
        $this->clock->advance(3600);
        $this->http->queue(500, 'Internal Server Error');
        $this->http->queue(500, 'Internal Server Error');
        $lines = LogCapture::during(function () use ($first): void {
            $stale = $this->client()->hourly(39.003, -77.405);
            self::assertSame('stale', $stale['state']);
            self::assertSame($first['generated_at'], $stale['generated_at']);
            self::assertSame($first['periods'], $stale['periods']);
        });
        self::assertSame(['[tp] weather hourly failed: http_500'], $lines);
        self::assertSame([1], $this->waits);

        // just under six hours after the copy was fetched it is still served
        $this->clock->advance(5 * 3600 - 1);
        $this->http->queue(500, '')->queue(500, '');
        LogCapture::during(function (): void {
            self::assertSame('stale', $this->client()->hourly(39.003, -77.405)['state']);
        });
        // at six hours it is not
        $this->clock->advance(61);
        $this->http->queue(500, '')->queue(500, '');
        LogCapture::during(function (): void {
            self::assertSame(['state' => 'unavailable', 'generated_at' => null, 'periods' => []], $this->client()->hourly(39.003, -77.405));
        });
        self::assertSame(0, $this->http->pending());
    }

    public function testAfterAFailureTheServiceIsLeftAloneForAMinute(): void
    {
        $this->queuePoints();
        $this->http->queue(500, '')->queue(500, '');
        LogCapture::during(function (): void {
            self::assertSame('unavailable', $this->client()->hourly(39.003, -77.405)['state']);
        });
        self::assertCount(3, $this->http->requests);

        // nothing is queued: a request now would fail the test
        $this->clock->advance(59);
        self::assertSame('unavailable', $this->client()->hourly(39.003, -77.405)['state']);
        self::assertCount(3, $this->http->requests);

        $this->clock->advance(1);
        $this->queueHourly();
        self::assertSame('fresh', $this->client()->hourly(39.003, -77.405)['state']);
        self::assertCount(4, $this->http->requests);
    }

    public function testAPointOutsideTheServicesCoverageIsRememberedForADay(): void
    {
        $this->http->queue(404, '{"title":"Data Unavailable For Requested Point","type":"https://api.weather.gov/problems/InvalidPoint","status":404}');
        self::assertSame(['state' => 'unavailable', 'generated_at' => null, 'periods' => []], $this->client()->hourly(51.5, -0.12));
        self::assertSame('https://api.weather.gov/points/51.5,-0.12', $this->http->requests[0]['url']);
        self::assertSame(86400 + self::PAD, $this->store->ttls['tp:nws:pt:51.5,-0.12']);

        $this->clock->advance(86399);
        self::assertSame('unavailable', $this->client()->hourly(51.5, -0.12)['state']);
        self::assertCount(1, $this->http->requests, 'no second request within the day');

        $this->clock->advance(1);
        $this->http->queue(404, '{}');
        $this->client()->hourly(51.5, -0.12);
        self::assertCount(2, $this->http->requests);
    }

    public function testAFailingPointsRequestGivesNoForecastAndIsNotRetried(): void
    {
        foreach ([[500, ''], [200, 'not json'], [200, '{"properties":{"gridId":"LWX"}}'], [200, '{"properties":{"gridId":"../x","gridX":1,"gridY":2}}']] as [$status, $body]) {
            $this->fresh();
            $this->http->queue($status, $body);
            $lines = LogCapture::during(function (): void {
                self::assertSame('unavailable', $this->client()->hourly(39.003, -77.405)['state']);
            });
            self::assertCount(1, $this->http->requests);
            self::assertSame([], $this->waits);
            self::assertCount(1, $lines);
            self::assertStringStartsWith('[tp] weather points failed: ', $lines[0]);
            self::assertArrayNotHasKey('tp:nws:pt:39.003,-77.405', $this->store->values);
        }
    }

    public function testAnAnswerWithoutPeriodsIsAFailure(): void
    {
        foreach (['{"properties":{"periods":[]}}', '{"properties":{}}', '[]', 'null', '<html>'] as $body) {
            $this->fresh();
            $this->queuePoints();
            $this->http->queue(200, $body);
            LogCapture::during(function (): void {
                self::assertSame('unavailable', $this->client()->hourly(39.003, -77.405)['state']);
            });
            self::assertArrayNotHasKey('tp:nws:hr:LWX:83,74', $this->store->values);
        }
    }

    public function testAGridCellTheServiceNoLongerKnowsIsLookedUpAgain(): void
    {
        $this->queuePoints();
        $this->http->queue(404, '{"type":"https://api.weather.gov/problems/InvalidGridpoint","status":404}');
        LogCapture::during(function (): void {
            self::assertSame('unavailable', $this->client()->hourly(39.003, -77.405)['state']);
        });
        self::assertNull(TpCache::get('tp:nws:pt:39.003,-77.405'));
    }

    public function testWithoutAContactAddressNothingIsRequested(): void
    {
        putenv('TP_CONTACT_EMAIL');
        self::assertSame(['state' => 'unavailable', 'generated_at' => null, 'periods' => []], $this->client()->hourly(39.003, -77.405));
        putenv('TP_CONTACT_EMAIL=');
        self::assertSame('unavailable', $this->client()->hourly(39.003, -77.405)['state']);
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->db->calls);
    }

    public function testTheSenderAddressOfTheAppStandsInForTheContact(): void
    {
        putenv('TP_CONTACT_EMAIL');
        putenv("MAIL_FROM=hello@example.test");
        $this->queuePoints();
        $this->queueHourly();
        $this->client()->hourly(39.003, -77.405);
        self::assertSame('User-Agent: (TruckPlanner, hello@example.test)', $this->http->requests[0]['headers'][0]);
    }

    public function testAContactCannotBreakOutOfItsHeader(): void
    {
        putenv("TP_CONTACT_EMAIL=ops@example.test\r\nX-Evil: 1");
        $this->queuePoints();
        $this->queueHourly();
        $this->client()->hourly(39.003, -77.405);
        self::assertSame('User-Agent: (TruckPlanner, ops@example.testX-Evil:1)', $this->http->requests[0]['headers'][0]);
    }

    public function testAPointThatIsNoPlaceOnEarthAsksNothing(): void
    {
        foreach ([[91.0, 0.0], [0.0, 181.0], [NAN, 0.0], [0.0, INF]] as [$lat, $lng]) {
            self::assertSame('unavailable', $this->client()->hourly($lat, $lng)['state']);
        }
        self::assertSame([], $this->http->requests);
    }

    public function testNothingTheClientDoesRaises(): void
    {
        // a store that fails on every read
        TpCache::wire($this->clock, new class implements \App\TruckPlanner\Services\Support\TpCacheStore {
            public function get(string $key): ?string
            {
                throw new \RuntimeException('the cache table is gone');
            }

            public function set(string $key, string $value, int $ttlSeconds): void
            {
            }

            public function flush(string $prefix): void
            {
            }
        });
        $lines = LogCapture::during(function (): void {
            self::assertSame(['state' => 'unavailable', 'generated_at' => null, 'periods' => []], $this->client()->hourly(39.003, -77.405));
        });
        self::assertSame(['[tp] weather failed: RuntimeException'], $lines);
    }
}
