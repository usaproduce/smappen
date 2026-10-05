<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FakeHttp;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Services\Upstream\FuelClient;
use PHPUnit\Framework\TestCase;

/**
 * The fuel price client against stubbed HTTP. The service wants its key in the address of the request,
 * so what matters most here is where that address does not go.
 */
final class FuelClientTest extends TestCase
{
    private const KEY = 'test-eia-key-not-real';

    private FakeHttp $http;
    private RecordingDatabase $db;
    private string|false $keyBefore;
    private ?string $envBefore;

    protected function setUp(): void
    {
        $this->http = new FakeHttp();
        $this->db = new RecordingDatabase();
        $this->keyBefore = getenv('EIA_API_KEY');
        $this->envBefore = $_ENV['EIA_API_KEY'] ?? null;
        unset($_ENV['EIA_API_KEY']);
        putenv('EIA_API_KEY=' . self::KEY);
    }

    protected function tearDown(): void
    {
        putenv($this->keyBefore === false ? 'EIA_API_KEY' : 'EIA_API_KEY=' . $this->keyBefore);
        if ($this->envBefore !== null) {
            $_ENV['EIA_API_KEY'] = $this->envBefore;
        }
    }

    private function client(): FuelClient
    {
        return new FuelClient($this->http, new ApiLedger($this->db));
    }

    /**
     * A row as the service sends it: every number is text.
     *
     * @return array<string, string>
     */
    private static function row(string $period, string $area, string $product, string $value): array
    {
        return [
            'period' => $period, 'duoarea' => $area, 'area-name' => 'PADD', 'product' => $product,
            'product-name' => 'Fuel', 'process' => 'PTE', 'process-name' => 'Retail Sales',
            'series' => ($product === 'EPMR' ? 'EMM_EPMR_PTE_' : 'EMD_EPD2D_PTE_') . $area . '_DPG',
            'series-description' => 'x', 'value' => $value, 'units' => '$/GAL',
        ];
    }

    public function testTheRequest(): void
    {
        $this->http->json(200, [
            'response' => [
                'total' => '3', 'dateFormat' => 'YYYY-MM-DD', 'frequency' => 'weekly',
                'data' => [
                    self::row('2026-09-28', 'R1Z', 'EPMR', '4.195'),
                    self::row('2026-09-28', 'R1Y', 'EPD2D', '6.531'),
                    self::row('2026-09-21', 'NUS', 'EPMR', '4.47'),
                ],
            ],
            'request' => ['command' => '/v2/petroleum/pri/gnd/data/', 'params' => ['api_key' => self::KEY]],
            'apiVersion' => '2.1.10',
        ]);
        $answer = $this->client()->weekly(['R1Y', 'R1Z', 'NUS'], '2026-08-31');

        self::assertCount(1, $this->http->requests);
        $request = $this->http->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertNull($request['body']);
        self::assertSame(['Accept: application/json'], $request['headers']);
        self::assertSame(3, $request['connect_timeout_s']);
        self::assertSame(5, $request['timeout_s']);
        self::assertStringStartsWith('https://api.eia.gov/v2/petroleum/pri/gnd/data/?api_key=' . self::KEY . '&frequency=weekly&', $request['url']);
        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);
        self::assertSame(
            [
                'api_key' => self::KEY,
                'frequency' => 'weekly',
                'data' => ['value'],
                'facets' => ['duoarea' => ['R1Y', 'R1Z', 'NUS'], 'product' => ['EPMR', 'EPD2D']],
                'start' => '2026-08-31',
                'sort' => [['column' => 'period', 'direction' => 'desc']],
                'offset' => '0',
                'length' => '100',
            ],
            $query
        );

        self::assertSame(
            [
                'ok' => true,
                'error' => null,
                'rows' => [
                    ['duoarea' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28', 'price' => 4.195, 'series_id' => 'EMM_EPMR_PTE_R1Z_DPG'],
                    ['duoarea' => 'R1Y', 'product' => 'EPD2D', 'period' => '2026-09-28', 'price' => 6.531, 'series_id' => 'EMD_EPD2D_PTE_R1Y_DPG'],
                    ['duoarea' => 'NUS', 'product' => 'EPMR', 'period' => '2026-09-21', 'price' => 4.47, 'series_id' => 'EMM_EPMR_PTE_NUS_DPG'],
                ],
            ],
            $answer
        );
        $params = $this->db->only('INSERT INTO api_cost_events')['params'];
        self::assertSame(['tp_eia_weekly', 1, '0', '0', null, 200, 12, null], array_slice($params, 1));
    }

    public function testValuesAreTextAndOnlyNumbersAreTaken(): void
    {
        $rows = FuelClient::rows(
            [
                self::row('2026-09-28', 'R1Z', 'EPMR', '4.195'),
                ['value' => 4.411] + self::row('2026-09-28', 'R1Y', 'EPMR', ''),          // a number is taken as well
                ['value' => 5] + self::row('2026-09-28', 'R1Y', 'EPD2D', ''),
                self::row('2026-09-28', 'NUS', 'EPMR', ''),                               // empty
                self::row('2026-09-28', 'NUS', 'EPD2D', 'NA'),
                ['value' => null] + self::row('2026-09-21', 'NUS', 'EPD2D', ''),
                ['value' => ['4.2']] + self::row('2026-09-14', 'NUS', 'EPD2D', ''),
                self::row('2026-09-28', 'R1Z', 'EPD2D', '0'),                             // no price
                self::row('2026-09-28', 'R1Z', 'EPD2D', '-4.2'),
                self::row('2026-09-28', 'R1Z', 'EPD2D', '412.5'),                         // cents, or a mistake
                self::row('2026-09-28', 'R1X', 'EPMR', '4.3'),                            // an area that was not asked for
                self::row('2026-09-28', 'R1Z', 'EPMP', '4.9'),                            // premium: not a product we price
                self::row('2026-9-28', 'R1Z', 'EPMR', '4.1'),                             // not a date
                self::row('2026-02-30', 'R1Z', 'EPMR', '4.1'),
                ['series' => "x'; DROP"] + self::row('2026-09-07', 'R1Z', 'EPMR', '4.111'),
                'row',
            ],
            ['R1Y', 'R1Z', 'NUS'],
            ['EPMR', 'EPD2D']
        );
        self::assertSame(
            [
                ['R1Z', 'EPMR', '2026-09-28', 4.195, 'EMM_EPMR_PTE_R1Z_DPG'],
                ['R1Y', 'EPMR', '2026-09-28', 4.411, 'EMM_EPMR_PTE_R1Y_DPG'],
                ['R1Y', 'EPD2D', '2026-09-28', 5.0, 'EMD_EPD2D_PTE_R1Y_DPG'],
                ['R1Z', 'EPMR', '2026-09-07', 4.111, ''],
            ],
            array_map('array_values', $rows)
        );
    }

    public function testWithoutAKeyNothingIsRequested(): void
    {
        putenv('EIA_API_KEY');
        self::assertFalse($this->client()->hasKey());
        self::assertSame(['ok' => false, 'error' => 'no_key', 'rows' => []], $this->client()->weekly(['NUS'], '2026-08-31'));
        putenv('EIA_API_KEY=');
        self::assertFalse($this->client()->hasKey());
        self::assertSame('no_key', $this->client()->weekly(['NUS'], '2026-08-31')['error']);
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->db->calls);

        putenv('EIA_API_KEY=' . self::KEY);
        self::assertTrue($this->client()->hasKey());
        // and without an area there is nothing to ask
        self::assertSame(['ok' => true, 'error' => null, 'rows' => []], $this->client()->weekly([], '2026-08-31'));
        self::assertSame([], $this->http->requests);
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: string}> status, body, error code
     */
    public static function failures(): array
    {
        return [
            'a key the service does not know' => [403, '{"error":{"code":"API_KEY_INVALID","message":"An invalid api_key was supplied."}}', 'API_KEY_INVALID'],
            'a missing key' => [403, '{"error":{"code":"API_KEY_MISSING","message":"No api_key was supplied."}}', 'API_KEY_MISSING'],
            'a suspended key' => [429, '{"error":{"code":"OVER_RATE_LIMIT","message":"You have exceeded your rate limit."}}', 'OVER_RATE_LIMIT'],
            'a bad parameter' => [400, '{"error":"Invalid frequency \'millenially\' provided.","code":400}', 'http_400'],
            'a server error' => [500, '<html>error</html>', 'http_500'],
            'a 200 that is not JSON' => [200, 'weekly prices', 'bad_body'],
            'a 200 without data' => [200, '{"response":{"total":"0"}}', 'bad_body'],
            'a 200 whose data is not a list' => [200, '{"response":{"data":"none"}}', 'bad_body'],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testAFailureIsACodeAndNothingMore(int $status, string $body, string $code): void
    {
        $this->http->queue($status, $body);
        $answer = null;
        $lines = LogCapture::during(function () use (&$answer): void {
            $answer = $this->client()->weekly(['R1Z', 'NUS'], '2026-08-31');
        });
        self::assertSame(['ok' => false, 'error' => $code, 'rows' => []], $answer);
        self::assertSame(['[tp] eia ' . $code], $lines);
        $params = $this->db->only('INSERT INTO api_cost_events')['params'];
        self::assertSame(['tp_eia_weekly', 0, '0', '0', null, $status, 12, $code], array_slice($params, 1));
    }

    public function testTransportFailures(): void
    {
        foreach (['timeout', 'connect', 'curl_28'] as $transport) {
            $http = (new FakeHttp())->fail($transport, 5000);
            $db = new RecordingDatabase();
            $answer = null;
            $lines = LogCapture::during(static function () use ($http, $db, &$answer): void {
                $answer = (new FuelClient($http, new ApiLedger($db)))->weekly(['NUS'], '2026-08-31');
            });
            self::assertSame(['ok' => false, 'error' => $transport, 'rows' => []], $answer);
            self::assertSame(['[tp] eia ' . $transport], $lines);
            $params = $db->only('INSERT INTO api_cost_events')['params'];
            self::assertNull($params[6]);
            self::assertSame(5000, $params[7]);
            self::assertSame($transport, $params[8]);
        }
    }

    public function testTheKeyNeverReachesALogLineOrTheLedger(): void
    {
        $url = 'https://api.eia.gov/v2/petroleum/pri/gnd/data/?api_key=' . self::KEY;
        $this->http->json(200, ['response' => ['data' => [self::row('2026-09-28', 'NUS', 'EPMR', '4.465')]]]);
        $this->http->queue(403, '{"error":{"code":"API_KEY_INVALID","message":"api_key=' . self::KEY . ' is not valid"}}');
        $this->http->queue(500, 'failed: ' . $url);
        $this->http->queue(200, '{"error":"' . $url . '"}');
        $this->http->fail('timeout');
        $lines = LogCapture::during(function (): void {
            for ($i = 0; $i < 5; $i++) {
                $this->client()->weekly(['NUS'], '2026-08-31');
            }
        });
        self::assertSame(['[tp] eia API_KEY_INVALID', '[tp] eia http_500', '[tp] eia bad_body', '[tp] eia timeout'], $lines);
        self::assertCount(5, $this->db->find('INSERT INTO api_cost_events'));
        $written = (string) json_encode([$this->db->calls, $lines]);
        self::assertStringNotContainsString(self::KEY, $written);
        self::assertStringNotContainsString('api_key', $written);
        self::assertStringNotContainsString('api.eia.gov', $written);
        self::assertStringNotContainsString('https:', $written);
        // the key is in the address of the request, which is where the service reads it, and in no header
        foreach ($this->http->requests as $request) {
            self::assertStringContainsString('api_key=' . self::KEY, $request['url']);
            self::assertStringNotContainsString(self::KEY, implode("\n", $request['headers']));
        }
    }
}
