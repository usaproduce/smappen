<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FakeHttp;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Services\Google\PlacesContactClient;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

/**
 * The Google Places (New) Text Search call of the contact lookup, against a stubbed transport
 * (04_BACKEND.md 5.4). The request below was checked against Google's reference on 2026-10-05: the method
 * and address, the three headers, the body fields and the names of the mask.
 */
final class PlacesContactClientTest extends TestCase
{
    private const KEY = 'tp-test-key-0123456789abcdef';
    private const NAME = 'Example Brewing';
    private const LAT = 39.01;
    private const LNG = -77.41;

    private FakeHttp $http;
    private RecordingDatabase $ledgerRows;
    private PlacesContactClient $client;

    /** @var string|false */
    private $keyBefore;
    private ?string $envBefore;

    protected function setUp(): void
    {
        $this->keyBefore = getenv('GOOGLE_API_KEY');
        $this->envBefore = $_ENV['GOOGLE_API_KEY'] ?? null;
        unset($_ENV['GOOGLE_API_KEY']);
        putenv('GOOGLE_API_KEY=' . self::KEY);

        $this->http = new FakeHttp();
        $this->ledgerRows = new RecordingDatabase();
        $this->client = new PlacesContactClient($this->http, new ApiLedger($this->ledgerRows));
    }

    protected function tearDown(): void
    {
        putenv($this->keyBefore === false ? 'GOOGLE_API_KEY' : 'GOOGLE_API_KEY=' . $this->keyBefore);
        if ($this->envBefore !== null) {
            $_ENV['GOOGLE_API_KEY'] = $this->envBefore;
        }
        TpConfig::replace(null);
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * A place as Google answers it, `$metres` north of the candidate.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function googlePlace(float $metres = 40.0, array $over = []): array
    {
        return $over + [
            'id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
            'displayName' => ['text' => 'Example Brewing Co', 'languageCode' => 'en'],
            'formattedAddress' => '1 Example Rd, Sterling, VA 20166, USA',
            'location' => ['latitude' => self::LAT + $metres / 6371008.8 * 180.0 / 3.141592653589793, 'longitude' => self::LNG],
            'nationalPhoneNumber' => '(703) 555-0100',
            'websiteUri' => 'https://example.com/',
            'googleMapsUri' => 'https://maps.google.com/?cid=1234567890',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function find(): array
    {
        return $this->client->find(self::NAME, self::LAT, self::LNG);
    }

    /**
     * The one ledger row of the test, by column.
     *
     * @return array<string, mixed>
     */
    private function ledgerRow(): array
    {
        $call = $this->ledgerRows->only('INSERT INTO api_cost_events');
        $names = ['id', 'sku', 'billable_units', 'unit_cost_usd', 'total_cost_usd', 'field_mask_hash', 'http_status', 'latency_ms', 'error_message'];
        self::assertCount(count($names), $call['params']);
        return array_combine($names, array_values($call['params']));
    }

    /**
     * Everything that left the client apart from the request to Google itself: what it answered, what it
     * logged and what it wrote to the ledger.
     *
     * @param array<string, mixed> $answer
     * @param list<string> $lines
     */
    private function everythingButTheRequest(array $answer, array $lines): string
    {
        return json_encode($answer) . "\n" . implode("\n", $lines) . "\n" . json_encode($this->ledgerRows->calls);
    }

    // ------------------------------------------------------------------------------------ the request

    public function testTheRequestIsTheOneOfTheSpecification(): void
    {
        $this->http->json(200, ['places' => [self::googlePlace()]]);
        $this->find();

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
        self::assertSame(3, $request['connect_timeout_s']);
        self::assertSame(6, $request['timeout_s']);
        // the key travels in its header only
        self::assertStringNotContainsString(self::KEY, $request['url']);
        self::assertStringNotContainsString(self::KEY, (string) $request['body']);
    }

    public function testTheMaskAsksForTheSevenFieldsAndNothingElse(): void
    {
        self::assertSame(
            ['places.id', 'places.displayName', 'places.formattedAddress', 'places.location', 'places.nationalPhoneNumber',
                'places.websiteUri', 'places.googleMapsUri'],
            explode(',', PlacesContactClient::FIELD_MASK)
        );
        self::assertSame('https://places.googleapis.com/v1/places:searchText', PlacesContactClient::URL);
        self::assertSame(300.0, TpConfig::get('places.match_radius_m'));
    }

    public function testTheBodyKeepsTheNameAndThePointAsTheyAre(): void
    {
        $this->http->json(200, []);
        $this->client->find("  Café \"Zoë\" & Sons / Brauerei  ", 38.0, -77.123456789012);
        $body = json_decode((string) $this->http->requests[0]['body'], true);
        self::assertSame('Café "Zoë" & Sons / Brauerei', $body['textQuery']);
        self::assertSame(['latitude' => 38.0, 'longitude' => -77.123456789012], $body['locationBias']['circle']['center']);
        // a whole number of degrees is still sent as a real, and nothing is escaped that need not be
        self::assertStringContainsString('"latitude":38.0,"longitude":-77.123456789012', (string) $this->http->requests[0]['body']);
        self::assertStringContainsString('Café \"Zoë\" & Sons / Brauerei', (string) $this->http->requests[0]['body']);
    }

    public function testTheBiasRadiusAndTheTimeoutsAreSettings(): void
    {
        $config = TpConfig::all();
        $config['places']['bias_radius_m'] = 250.0;
        $config['places']['connect_timeout_s'] = 2;
        $config['places']['timeout_s'] = 4;
        TpConfig::replace($config);
        $this->http->json(200, []);
        $this->find();
        $request = $this->http->requests[0];
        self::assertSame(250.0, json_decode((string) $request['body'], true)['locationBias']['circle']['radius']);
        self::assertSame([2, 4], [$request['connect_timeout_s'], $request['timeout_s']]);
    }

    // ------------------------------------------------------------------------------------ the answer

    public function testAPlaceAtTheCandidatesPointIsFound(): void
    {
        $this->http->json(200, ['places' => [self::googlePlace()]]);
        self::assertSame(
            [
                'ok' => true,
                'error' => null,
                'match' => 'found',
                'place' => [
                    'place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
                    'name' => 'Example Brewing Co',
                    'address' => '1 Example Rd, Sterling, VA 20166, USA',
                    'phone' => '(703) 555-0100',
                    'website' => 'https://example.com/',
                    'maps_uri' => 'https://maps.google.com/?cid=1234567890',
                ],
            ],
            $this->find()
        );
    }

    public function testTheLocationIsUsedForTheCheckAndIsNotHandedOn(): void
    {
        $this->http->json(200, ['places' => [self::googlePlace(120.0)]]);
        $answer = $this->find();
        self::assertSame('found', $answer['match']);
        self::assertSame(['place_id', 'name', 'address', 'phone', 'website', 'maps_uri'], array_keys($answer['place']));
        $text = json_encode($answer);
        self::assertStringNotContainsString('latitude', $text);
        self::assertStringNotContainsString('39.01', $text);
    }

    public function testAPlaceFartherThanThreeHundredMetresIsNoConfidentMatch(): void
    {
        foreach ([[299.0, 'found'], [301.0, 'far'], [5000.0, 'far']] as [$metres, $match]) {
            $this->http->json(200, ['places' => [self::googlePlace($metres)]]);
            $answer = $this->find();
            self::assertTrue($answer['ok']);
            self::assertSame($match, $answer['match'], $metres . ' m');
            self::assertSame($match === 'found', $answer['place'] !== null);
        }
        // east and west count like north and south
        $east = self::googlePlace(0.0);
        $east['location']['longitude'] = self::LNG + 0.01;
        $this->http->json(200, ['places' => [$east]]);
        self::assertSame('far', $this->find()['match']);
    }

    public function testAPlaceWithoutAUsableLocationIsNoConfidentMatch(): void
    {
        $withoutLocation = self::googlePlace();
        unset($withoutLocation['location']);
        foreach ([
            $withoutLocation,
            self::googlePlace(0.0, ['location' => ['latitude' => '39.01', 'longitude' => '-77.41']]),
            self::googlePlace(0.0, ['location' => ['latitude' => 139.0, 'longitude' => -77.41]]),
            self::googlePlace(0.0, ['location' => 'here']),
        ] as $place) {
            $this->http->json(200, ['places' => [$place]]);
            $answer = $this->find();
            self::assertSame(['ok' => true, 'error' => null, 'match' => 'far', 'place' => null], $answer);
        }
    }

    public function testNoResultIsAnAnswerAndNotAnError(): void
    {
        // Google leaves an empty list out of its JSON.
        $this->http->queue(200, '{}');
        self::assertSame(['ok' => true, 'error' => null, 'match' => 'none', 'place' => null], $this->find());
        $this->http->json(200, ['places' => []]);
        self::assertSame('none', $this->find()['match']);
    }

    public function testOnlyTheFirstPlaceIsRead(): void
    {
        $this->http->json(200, ['places' => [self::googlePlace(900.0), self::googlePlace(10.0)]]);
        self::assertSame('far', $this->find()['match']);
    }

    public function testWhatComesBackIsFittedToTheColumnsOfTheLead(): void
    {
        $this->http->json(200, ['places' => [self::googlePlace(10.0, [
            'displayName' => ['text' => "  " . str_repeat('é', 200) . "  "],
            'formattedAddress' => "1 Example Rd,\nSterling " . str_repeat('x', 300),
            'nationalPhoneNumber' => str_repeat('5', 60),
            'websiteUri' => 'https://example.com/' . str_repeat('a', 240),
            'googleMapsUri' => 'https://maps.google.com/?cid=1',
        ])]]);
        $place = $this->find()['place'];
        self::assertSame(str_repeat('é', 160), $place['name']);
        self::assertSame(255, mb_strlen($place['address']));
        self::assertStringStartsWith('1 Example Rd, Sterling x', $place['address']);
        self::assertSame(str_repeat('5', 40), $place['phone']);
        // a cut address would lead nowhere: one that does not fit is dropped
        self::assertNull($place['website']);
        self::assertSame('https://maps.google.com/?cid=1', $place['maps_uri']);
    }

    public function testMissingAndMalformedFieldsAreNull(): void
    {
        $this->http->json(200, ['places' => [[
            'id' => 'not a place id!',
            'displayName' => 'Example',
            'formattedAddress' => '   ',
            'location' => ['latitude' => self::LAT, 'longitude' => self::LNG],
            'nationalPhoneNumber' => 7035550100,
            'websiteUri' => 'javascript:alert(1)',
            'googleMapsUri' => 'https://maps.google.com/?q=two words',
        ]]]);
        self::assertSame(
            ['place_id' => null, 'name' => null, 'address' => null, 'phone' => null, 'website' => null, 'maps_uri' => null],
            $this->find()['place']
        );

        $this->http->json(200, ['places' => [['location' => ['latitude' => self::LAT, 'longitude' => self::LNG]]]]);
        $answer = $this->find();
        self::assertSame('found', $answer['match']);
        self::assertSame(['place_id' => null, 'name' => null, 'address' => null, 'phone' => null, 'website' => null, 'maps_uri' => null], $answer['place']);

        // a plain http address is an address too
        $this->http->json(200, ['places' => [self::googlePlace(0.0, ['websiteUri' => 'http://example.com/menu?x=1'])]]);
        self::assertSame('http://example.com/menu?x=1', $this->find()['place']['website']);
    }

    // ------------------------------------------------------------------------------------ failures

    public function testWithoutAKeyNothingIsSent(): void
    {
        putenv('GOOGLE_API_KEY');
        self::assertSame(['ok' => false, 'error' => 'no_key', 'match' => null, 'place' => null], $this->find());
        putenv('GOOGLE_API_KEY=');
        self::assertSame('no_key', $this->find()['error']);
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->ledgerRows->calls);
    }

    public function testWithoutANameThereIsNothingToAsk(): void
    {
        self::assertSame(['ok' => true, 'error' => null, 'match' => 'none', 'place' => null], $this->client->find('   ', self::LAT, self::LNG));
        self::assertSame('none', $this->client->find("\xC3\x28 not text", self::LAT, self::LNG)['match']);
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->ledgerRows->calls);
    }

    public function testARefusalIsReportedAsRefused(): void
    {
        // What Google answers while the API is not enabled for the key's project.
        $this->http->json(403, ['error' => [
            'code' => 403,
            'message' => 'Places API (New) has not been used in project 123 before or it is disabled. Enable it by visiting '
                . 'https://console.developers.google.com/apis/api/places.googleapis.com/overview?project=123 then retry.',
            'status' => 'PERMISSION_DENIED',
            'details' => [['@type' => 'type.googleapis.com/google.rpc.ErrorInfo', 'reason' => 'SERVICE_DISABLED', 'domain' => 'googleapis.com']],
        ]]);
        $answer = null;
        $lines = LogCapture::during(function () use (&$answer): void {
            $answer = $this->find();
        });
        self::assertSame(['ok' => false, 'error' => 'refused', 'match' => null, 'place' => null], $answer);
        self::assertSame(['[tp] places lookup failed: http_403 PERMISSION_DENIED'], $lines);
        $row = $this->ledgerRow();
        self::assertSame(0, $row['billable_units']);
        self::assertSame(403, $row['http_status']);
        self::assertSame('PERMISSION_DENIED', $row['error_message']);
    }

    public function testFailuresAreQuotaRefusedTimeoutOrUpstream(): void
    {
        $cases = [
            ['quota', 'RESOURCE_EXHAUSTED', '[tp] places lookup failed: http_429 RESOURCE_EXHAUSTED',
                fn () => $this->http->json(429, ['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']])],
            // Either sign counts: the HTTP status without a readable body, and the status word under another code.
            ['quota', 'http_429', '[tp] places lookup failed: http_429', fn () => $this->http->queue(429, '')],
            ['quota', 'RESOURCE_EXHAUSTED', '[tp] places lookup failed: http_403 RESOURCE_EXHAUSTED',
                fn () => $this->http->json(403, ['error' => ['code' => 403, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']])],
            ['refused', 'http_403', '[tp] places lookup failed: http_403', fn () => $this->http->queue(403, '<html>Forbidden</html>')],
            ['refused', 'PERMISSION_DENIED', '[tp] places lookup failed: http_401 PERMISSION_DENIED',
                fn () => $this->http->json(401, ['error' => ['code' => 401, 'message' => 'Not for this key', 'status' => 'PERMISSION_DENIED']])],
            ['upstream', 'UNAUTHENTICATED', '[tp] places lookup failed: http_401 UNAUTHENTICATED',
                fn () => $this->http->json(401, ['error' => ['code' => 401, 'message' => 'No credentials', 'status' => 'UNAUTHENTICATED']])],
            ['upstream', 'http_500', '[tp] places lookup failed: http_500', fn () => $this->http->queue(500, '<html>Internal error</html>')],
            ['upstream', 'http_503', '[tp] places lookup failed: http_503', fn () => $this->http->queue(503, '')],
            ['timeout', 'timeout', '[tp] places lookup failed: timeout', fn () => $this->http->fail('timeout')],
            ['upstream', 'connect', '[tp] places lookup failed: connect', fn () => $this->http->fail('connect')],
            ['upstream', 'curl_56', '[tp] places lookup failed: curl_56', fn () => $this->http->fail('curl_56')],
            ['upstream', 'bad_body', '[tp] places lookup failed: bad_body', fn () => $this->http->queue(200, 'not json')],
            ['upstream', 'bad_body', '[tp] places lookup failed: bad_body', fn () => $this->http->queue(200, '{"places":"none"}')],
        ];
        foreach ($cases as [$error, $code, $line, $queue]) {
            $this->ledgerRows->calls = [];
            $queue();
            $answer = null;
            $lines = LogCapture::during(function () use (&$answer): void {
                $answer = $this->find();
            });
            self::assertSame(['ok' => false, 'error' => $error, 'match' => null, 'place' => null], $answer, $code);
            self::assertSame([$line], $lines);
            self::assertSame($code, $this->ledgerRow()['error_message']);
        }
    }

    public function testABadRequestIsLoggedWithGooglesSentenceAndWithoutSecrets(): void
    {
        $this->http->json(400, ['error' => [
            'code' => 400,
            'message' => "Invalid field mask: places.fooBar\nkey=" . self::KEY . ' AIzaSyD-ExampleExampleExample1234567',
            'status' => 'INVALID_ARGUMENT',
        ]]);
        $answer = null;
        $lines = LogCapture::during(function () use (&$answer): void {
            $answer = $this->find();
        });
        self::assertSame('upstream', $answer['error']);
        self::assertCount(1, $lines);
        self::assertStringStartsWith('[tp] places lookup failed: http_400 INVALID_ARGUMENT Invalid field mask: places.fooBar', $lines[0]);
        self::assertStringNotContainsString(self::KEY, $lines[0]);
        self::assertStringNotContainsString('AIza', $lines[0]);
        self::assertSame('INVALID_ARGUMENT', $this->ledgerRow()['error_message']);
    }

    // ------------------------------------------------------------------------------------ ledger and secrets

    public function testEveryCallWritesOneLedgerRowWithItsSkuAndNoAddress(): void
    {
        $this->http->json(200, ['places' => [self::googlePlace()]]);
        $this->find();
        $row = $this->ledgerRow();
        self::assertSame('tp_places_text', $row['sku']);
        self::assertSame(1, $row['billable_units']);
        self::assertSame('0.035', $row['unit_cost_usd']);
        self::assertSame('0.035', $row['total_cost_usd']);
        self::assertSame(ApiLedger::maskHash(PlacesContactClient::FIELD_MASK), $row['field_mask_hash']);
        self::assertSame(16, strlen((string) $row['field_mask_hash']));
        self::assertSame(200, $row['http_status']);
        self::assertSame(12, $row['latency_ms']);
        self::assertNull($row['error_message']);

        // Google bills an answered search whether or not it matched
        foreach ([[], ['places' => [self::googlePlace(4000.0)]]] as $payload) {
            $this->ledgerRows->calls = [];
            $this->http->queue(200, (string) json_encode($payload === [] ? new \stdClass() : $payload));
            $this->find();
            self::assertSame(1, $this->ledgerRow()['billable_units']);
        }
        self::assertSame(0, $this->http->pending());
    }

    public function testTheKeyAndTheAddressNeverLeaveThroughAnswersLogsOrTheLedger(): void
    {
        $queues = [
            fn () => $this->http->json(200, ['places' => [self::googlePlace()]]),
            fn () => $this->http->json(403, ['error' => ['code' => 403, 'message' => 'API key ' . self::KEY . ' is not permitted', 'status' => 'PERMISSION_DENIED']]),
            fn () => $this->http->json(400, ['error' => ['code' => 400, 'message' => 'https://places.googleapis.com/v1/places:searchText?key=' . self::KEY, 'status' => 'INVALID_ARGUMENT']]),
            fn () => $this->http->queue(500, 'key=' . self::KEY),
            fn () => $this->http->fail('timeout'),
        ];
        foreach ($queues as $queue) {
            $queue();
            $answer = [];
            $lines = LogCapture::during(function () use (&$answer): void {
                $answer = $this->find();
            });
            $out = $this->everythingButTheRequest($answer, $lines);
            self::assertStringNotContainsString(self::KEY, $out);
            self::assertStringNotContainsString('googleapis.com', $out);
            foreach ($lines as $line) {
                self::assertStringStartsWith('[tp] ', $line);
            }
        }
        self::assertCount(count($queues), $this->ledgerRows->find('INSERT INTO api_cost_events'));
    }

    public function testNothingIsRetried(): void
    {
        $this->http->fail('timeout');
        LogCapture::during(function (): void {
            $this->find();
        });
        self::assertCount(1, $this->http->requests);
    }
}
