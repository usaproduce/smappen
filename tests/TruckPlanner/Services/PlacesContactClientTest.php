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
 * The two Google Places (New) calls of the contact lookup, against a stubbed transport (04_BACKEND.md 5.4):
 * the Text Search by name of a first lookup, and the Place Details request by id of a later one. Both
 * requests below were checked against Google's reference on 2026-10-05: method and address, headers, the
 * body fields of the search and the names of the two masks.
 *
 * The client hands an answer to its caller and keeps none of it: the last tests look for the texts of an
 * answer in every log line and every ledger row.
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
     * The same place as Google answers it for a request by id: the place itself, without a location.
     *
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function googleDetails(array $over = []): array
    {
        $place = $over + self::googlePlace();
        unset($place['location']);
        return $place;
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

    public function testWhatIsPassedOnIsCleanAndBounded(): void
    {
        $long = 'https://example.com/' . str_repeat('a', 240);
        $this->http->json(200, ['places' => [self::googlePlace(10.0, [
            'displayName' => ['text' => "  " . str_repeat('é', 200) . "  "],
            'formattedAddress' => "1 Example Rd,\nSterling " . str_repeat('x', 300),
            'nationalPhoneNumber' => str_repeat('5', 60),
            'websiteUri' => $long,
            'googleMapsUri' => 'https://maps.google.com/?cid=1',
        ])]]);
        $place = $this->find()['place'];
        self::assertSame(str_repeat('é', 160), $place['name']);
        self::assertSame(255, mb_strlen($place['address']));
        self::assertStringStartsWith('1 Example Rd, Sterling x', $place['address']);
        self::assertSame(str_repeat('5', 40), $place['phone']);
        // No column holds the answer, so an address of ordinary length passes whole ...
        self::assertSame($long, $place['website']);
        self::assertSame('https://maps.google.com/?cid=1', $place['maps_uri']);

        // ... and only one that is no web address any more is dropped: a cut address would lead nowhere.
        $this->http->json(200, ['places' => [self::googlePlace(10.0, ['websiteUri' => 'https://example.com/' . str_repeat('a', 2040)])]]);
        self::assertNull($this->find()['place']['website']);
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

    // ------------------------------------------------------------------------------------ a place by its id

    public function testTheRequestByIdIsTheOneOfTheSpecification(): void
    {
        $this->http->json(200, self::googleDetails());
        $this->client->details('ChIJN1t_tDeuEmsRUsoyG83frY4');

        self::assertCount(1, $this->http->requests);
        $request = $this->http->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertSame('https://places.googleapis.com/v1/places/ChIJN1t_tDeuEmsRUsoyG83frY4?languageCode=en', $request['url']);
        self::assertSame(
            [
                'X-Goog-Api-Key: ' . self::KEY,
                'X-Goog-FieldMask: id,displayName,formattedAddress,nationalPhoneNumber,websiteUri,googleMapsUri',
            ],
            $request['headers']
        );
        self::assertNull($request['body']);
        self::assertSame([3, 6], [$request['connect_timeout_s'], $request['timeout_s']]);
        self::assertStringNotContainsString(self::KEY, $request['url']);
    }

    public function testTheMaskOfARequestByIdNamesTheContactFieldsWithoutAPrefixAndNoLocation(): void
    {
        self::assertSame(
            ['id', 'displayName', 'formattedAddress', 'nationalPhoneNumber', 'websiteUri', 'googleMapsUri'],
            explode(',', PlacesContactClient::DETAILS_FIELD_MASK)
        );
        self::assertSame('https://places.googleapis.com/v1/places/', PlacesContactClient::DETAILS_URL);
        // the same fields as the search, less the location that only the search needs for its check
        $search = array_map(static fn (string $name): string => substr($name, strlen('places.')), explode(',', PlacesContactClient::FIELD_MASK));
        self::assertSame(array_values(array_diff($search, ['location'])), explode(',', PlacesContactClient::DETAILS_FIELD_MASK));
    }

    public function testAPlaceAskedForByItsIdIsFound(): void
    {
        $this->http->json(200, self::googleDetails());
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
            $this->client->details('ChIJN1t_tDeuEmsRUsoyG83frY4')
        );
        // a field Google does not have is left out of its answer
        $this->http->json(200, ['id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4', 'displayName' => ['text' => 'Example Brewing Co']]);
        self::assertSame(
            ['place_id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4', 'name' => 'Example Brewing Co', 'address' => null, 'phone' => null, 'website' => null, 'maps_uri' => null],
            $this->client->details('ChIJN1t_tDeuEmsRUsoyG83frY4')['place']
        );
    }

    public function testAnIdGoogleNoLongerKnowsIsAnAnswerAndNotAFailure(): void
    {
        $gone = ['ok' => true, 'error' => null, 'match' => 'gone', 'place' => null];
        $cases = [
            fn () => $this->http->json(404, ['error' => ['code' => 404, 'message' => 'Place ID is no longer valid.', 'status' => 'NOT_FOUND']]),
            fn () => $this->http->queue(404, ''),
            fn () => $this->http->json(400, ['error' => ['code' => 400, 'message' => 'The place is gone', 'status' => 'NOT_FOUND']]),
        ];
        foreach ($cases as $queue) {
            $this->ledgerRows->calls = [];
            $queue();
            $answer = null;
            $lines = LogCapture::during(function () use (&$answer): void {
                $answer = $this->client->details('ChIJN1t_tDeuEmsRUsoyG83frY4');
            });
            self::assertSame($gone, $answer);
            self::assertSame([], $lines, 'an id that is gone is not a failure to log');
            $row = $this->ledgerRow();
            self::assertSame('tp_places_details', $row['sku']);
            self::assertSame(0, $row['billable_units']);
        }
        // a search has no id to be gone: its 404 is a failure like any other
        $this->http->json(404, ['error' => ['code' => 404, 'message' => 'Not found', 'status' => 'NOT_FOUND']]);
        $answer = null;
        LogCapture::during(function () use (&$answer): void {
            $answer = $this->find();
        });
        self::assertSame('upstream', $answer['error']);
    }

    public function testATextThatCannotBeAnIdIsNotSent(): void
    {
        foreach (['', 'not a place id!', 'places/ChIJ', '../ChIJ', 'ChIJ?fields=*', str_repeat('a', 256)] as $text) {
            self::assertSame(['ok' => true, 'error' => null, 'match' => 'gone', 'place' => null], $this->client->details($text));
        }
        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->ledgerRows->calls);
    }

    public function testFailuresOfARequestByIdAreTheOnesOfASearch(): void
    {
        $cases = [
            ['quota', 'RESOURCE_EXHAUSTED', fn () => $this->http->json(429, ['error' => ['code' => 429, 'message' => 'Quota exceeded', 'status' => 'RESOURCE_EXHAUSTED']])],
            ['refused', 'PERMISSION_DENIED', fn () => $this->http->json(403, ['error' => ['code' => 403, 'message' => 'Not enabled', 'status' => 'PERMISSION_DENIED']])],
            ['upstream', 'INVALID_ARGUMENT', fn () => $this->http->json(400, ['error' => ['code' => 400, 'message' => 'Invalid field mask', 'status' => 'INVALID_ARGUMENT']])],
            ['upstream', 'http_500', fn () => $this->http->queue(500, 'oops')],
            ['timeout', 'timeout', fn () => $this->http->fail('timeout')],
            ['upstream', 'bad_body', fn () => $this->http->queue(200, 'not json')],
            // a place without the id that was asked for in the mask is no place
            ['upstream', 'bad_body', fn () => $this->http->queue(200, '{}')],
            ['upstream', 'bad_body', fn () => $this->http->queue(200, '[]')],
        ];
        foreach ($cases as [$error, $code, $queue]) {
            $this->ledgerRows->calls = [];
            $queue();
            $answer = null;
            $lines = LogCapture::during(function () use (&$answer): void {
                $answer = $this->client->details('ChIJN1t_tDeuEmsRUsoyG83frY4');
            });
            self::assertSame(['ok' => false, 'error' => $error, 'match' => null, 'place' => null], $answer, $code);
            self::assertCount(1, $lines);
            self::assertStringStartsWith('[tp] places lookup failed: ', $lines[0]);
            self::assertSame($code, $this->ledgerRow()['error_message']);
        }
        putenv('GOOGLE_API_KEY');
        self::assertSame('no_key', $this->client->details('ChIJN1t_tDeuEmsRUsoyG83frY4')['error']);
    }

    public function testARequestByIdIsMeteredUnderItsOwnSku(): void
    {
        $this->http->json(200, self::googleDetails());
        $this->client->details('ChIJN1t_tDeuEmsRUsoyG83frY4');
        $row = $this->ledgerRow();
        self::assertSame('tp_places_details', $row['sku']);
        self::assertSame(1, $row['billable_units']);
        self::assertSame('0.02', $row['unit_cost_usd']);
        self::assertSame('0.02', $row['total_cost_usd']);
        self::assertSame(ApiLedger::maskHash(PlacesContactClient::DETAILS_FIELD_MASK), $row['field_mask_hash']);
        self::assertNotSame(ApiLedger::maskHash(PlacesContactClient::FIELD_MASK), $row['field_mask_hash']);
        self::assertSame(200, $row['http_status']);
        self::assertNull($row['error_message']);
    }

    // ------------------------------------------------------------------------------------ nothing of an answer is kept

    public function testNoTextOfAnAnswerReachesALogLineOrTheLedger(): void
    {
        // Every text of the place is one that cannot be mistaken for anything else.
        $place = [
            'id' => 'ChIJ_SENTINEL_placeid',
            'displayName' => ['text' => 'SENTINEL-NAME Brewing', 'languageCode' => 'en'],
            'formattedAddress' => '1 SENTINEL-ADDRESS Rd, Sterling, VA 20166, USA',
            'location' => ['latitude' => self::LAT, 'longitude' => self::LNG],
            'nationalPhoneNumber' => '(703) 555-SENTINEL',
            'websiteUri' => 'https://sentinel-website.example.com/',
            'googleMapsUri' => 'https://maps.google.com/?cid=SENTINELCID',
        ];
        $calls = [
            'a search that matches' => function () use ($place): array {
                $this->http->json(200, ['places' => [$place]]);
                return $this->find();
            },
            'a search whose match is elsewhere' => function () use ($place): array {
                $this->http->json(200, ['places' => [['location' => ['latitude' => self::LAT + 0.1, 'longitude' => self::LNG]] + $place]]);
                return $this->find();
            },
            'a request by id' => function () use ($place): array {
                $details = $place;
                unset($details['location']);
                $this->http->json(200, $details);
                return $this->client->details('ChIJ_SENTINEL_placeid');
            },
        ];
        foreach ($calls as $what => $call) {
            $this->ledgerRows->calls = [];
            $answer = [];
            $lines = LogCapture::during(function () use (&$answer, $call): void {
                $answer = $call();
            });
            self::assertTrue($answer['ok'], $what);
            self::assertSame([], $lines, $what . ': an answered call logs nothing');
            self::assertCount(1, $this->ledgerRows->calls, $what . ': one ledger row and no other statement');
            $kept = json_encode($this->ledgerRows->calls);
            // The traces are texts that no id, hash, number or time of a ledger row can hold by chance. (A
            // row has a random id: three digits of the phone number would turn up in one now and then.)
            self::assertStringNotContainsStringIgnoringCase('sentinel', (string) $kept, $what);
            self::assertStringNotContainsString('(703)', (string) $kept, $what);
            self::assertStringNotContainsString('maps.google.com', (string) $kept, $what);
        }
        // The class has nothing to keep an answer in: no property but its two collaborators, and no cache.
        $properties = array_map(static fn (\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass(PlacesContactClient::class))->getProperties());
        self::assertSame(['http', 'ledger'], $properties);
    }
}
