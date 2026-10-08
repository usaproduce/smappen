<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Guard;

use App\Tests\TruckPlanner\Services\FixtureRegion;
use App\Tests\TruckPlanner\Services\FixtureRegions;
use App\Tests\TruckPlanner\Services\ScoutGuard;
use App\Tests\TruckPlanner\Services\ScoutPlaces;
use App\Tests\TruckPlanner\Services\SpotServiceTest;
use App\Tests\TruckPlanner\Support\FakeHttp;
use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\ScoutLeadRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Services\Google\PlacesContactClient;
use App\TruckPlanner\Services\ScoutingService;
use App\TruckPlanner\Services\SpotService;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

// The fixture region, its region service, the places and the guard with a key a test can take away live
// with the tests of the Scout and the spot service. They are not test cases, so the autoloader cannot find
// them by name: the files are loaded here.
require_once __DIR__ . '/../Services/ScoutingServiceTest.php';

/**
 * Of Google Places only the place id is stored (DECISIONS sections 0 and 9, 04_BACKEND.md 5.4).
 *
 * Google's Places policy lets a customer keep a place id and nothing else of Places content. So the name,
 * address, phone, website and Maps link of a contact lookup are passed to the browser and are kept nowhere
 * on the server: no column, no cache entry, no ledger text, no log line. This guard fails when a change
 * opens a way for them to be kept.
 *
 *   - the table has no column for them: migration 043 lists the columns of `tp_scout_leads`, and adding
 *     one changes this test
 *   - no code names the five columns that once held them
 *   - the lead repository names no column and no key they could travel under
 *   - the contact lookup of the service hands the answer to nothing but the response
 *   - a found lookup, run for real against a database double that records every statement, writes the
 *     place id, the outcome and the time, and no text of the answer reaches a statement, a cache entry,
 *     a ledger row or a log line
 *
 * A guard looks at code, never at what a comment says about code (SourceScan drops comments).
 */
final class PlacesContentNotStoredTest extends TestCase
{
    private const ORG = SpotServiceTest::ORG;
    private const KEY = 'tp-test-key-0123456789abcdef';

    /** The columns that held looked-up contact details while they were kept for 30 days. */
    private const REMOVED_COLUMNS = ['g_name', 'g_address', 'g_phone', 'g_website', 'g_maps_uri'];

    /** `tp_scout_leads` as migration 043 creates it. */
    private const LEAD_COLUMNS = ['id', 'organization_id', 'truck_id', 'region_id', 'place_key', 'place_name', 'place_type', 'lat', 'lng',
        'lead_state', 'notes', 'spot_id', 'google_place_id', 'g_lookup_state', 'g_fetched_at', 'created_at', 'updated_at'];

    /** Of those, what a contact lookup may write. */
    private const LOOKUP_COLUMNS = ['google_place_id', 'g_lookup_state', 'g_fetched_at'];

    /** The names Google and the Places client give the fields of an answer. */
    private const CONTENT_KEYS = ['name', 'address', 'phone', 'website', 'maps_uri', 'displayName', 'formattedAddress',
        'nationalPhoneNumber', 'websiteUri', 'googleMapsUri', 'location'];

    private const PLACE_ID = 'ChIJ_SENTINEL_place_id_0001';

    /** What Google says about the place. Each text is one that nothing else in the test could produce. */
    private const ANSWER = [
        'displayName' => ['text' => 'SENTINEL-NAME Brewing Co', 'languageCode' => 'en'],
        'formattedAddress' => '99 SENTINEL-ADDRESS Way, Sterling, VA 20166, USA',
        'nationalPhoneNumber' => '(703) 555-SENTINEL-PHONE',
        'websiteUri' => 'https://sentinel-website.example.com/',
        'googleMapsUri' => 'https://maps.google.com/?cid=SENTINELCID',
    ];

    private FixedClock $clock;
    private MemoryCache $cache;
    private FakeHttp $http;
    private RecordingDatabase $leadRows;
    private RecordingDatabase $ledgerRows;
    private ScoutingService $service;

    /** @var string|false */
    private $keyBefore;
    private ?string $envBefore;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-08 03:30:00');
        $this->cache = new MemoryCache();
        TpCache::wire($this->clock, $this->cache);
        Registry::reset();

        $this->keyBefore = getenv('GOOGLE_API_KEY');
        $this->envBefore = $_ENV['GOOGLE_API_KEY'] ?? null;
        unset($_ENV['GOOGLE_API_KEY']);
        putenv('GOOGLE_API_KEY=' . self::KEY);

        $region = FixtureRegion::standard();
        $regions = new FixtureRegions($region);
        $places = new ScoutPlaces();
        $places->add([
            'place_key' => FixtureRegion::TAPROOM_KEY, 'place_type' => 'taproom', 'name' => 'Example Brewing',
            'lat' => FixtureRegion::TAPROOM['lat'], 'lng' => FixtureRegion::TAPROOM['lng'], 'county_fips' => '51107',
            'host_fit' => 1.0, 'kitchen' => 'unknown', 'size_default' => 40.0, 'visitor_segment' => 'v_nightlife',
            'phone' => '+17035550100', 'website' => 'https://example.com',
        ]);
        $this->http = new FakeHttp();
        $this->leadRows = new RecordingDatabase();
        $this->ledgerRows = new RecordingDatabase();
        $ledger = new ApiLedger($this->ledgerRows);
        $spots = new SpotRepository(new RecordingDatabase());
        $this->service = new ScoutingService(
            new ScoutLeadRepository($this->leadRows),
            $places,
            $spots,
            new SpotService($spots, new CountsRepository(new RecordingDatabase()), $regions),
            $regions,
            new PlacesContactClient($this->http, $ledger),
            new ScoutGuard($this->clock, $ledger, static fn (string $bucket, int $tokens, int $wait): bool => true),
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

    // ------------------------------------------------------------------------------------ the static scan

    public function testTheLeadTableHasNoColumnForPlacesContent(): void
    {
        $sql = str_replace("\r\n", "\n", (string) file_get_contents(SourceScan::root() . '/src/Migrations/043_truck_planner_core.sql'));
        self::assertSame(1, preg_match('/CREATE TABLE IF NOT EXISTS tp_scout_leads \((.*?)\n\) ENGINE=/s', $sql, $m), 'the table of migration 043');
        $columns = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (preg_match('/^\s{2}([a-z_0-9]+)\s+[A-Z]/', $line, $c) === 1) {
                $columns[] = $c[1];
            }
        }
        // A column that is added here is a column that could hold what Google answered: adding it means
        // deciding that it does not, and changing this list.
        self::assertSame(self::LEAD_COLUMNS, $columns);
        foreach (self::REMOVED_COLUMNS as $column) {
            self::assertStringNotContainsString($column, $sql, $column . ' is back in the migration');
        }
        self::assertDoesNotMatchRegularExpression('/\b(phone|website|address|maps_uri)\b/', $m[1]);
    }

    public function testNoCodeNamesAColumnThatHeldPlacesContent(): void
    {
        // The backend, the controllers, the settings and the operator scripts. The steps of the smoke test
        // are left out: one of them names the old columns to check that an export holds none of them.
        $files = array_merge(
            SourceScan::phpFilesUnder('src/TruckPlanner'),
            SourceScan::controllers(),
            ['config/truck_planner.php'],
            array_values(array_filter(SourceScan::phpFilesUnder('scripts/truck'), static fn (string $f): bool => !str_starts_with($f, 'scripts/truck/smoke/')))
        );
        self::assertGreaterThan(80, count($files));
        foreach ($files as $file) {
            $code = SourceScan::code($file);
            foreach (self::REMOVED_COLUMNS as $column) {
                self::assertStringNotContainsString($column, $code, $file . ' names ' . $column);
            }
            // nothing keeps contact details for a number of days any more
            self::assertStringNotContainsString('contact_ttl', $code, $file);
            self::assertStringNotContainsString('purgeExpiredGoogle', $code, $file);
        }
    }

    public function testTheLeadRepositoryNamesNoColumnAndNoKeyOfAnAnswer(): void
    {
        $file = 'src/TruckPlanner/Data/ScoutLeadRepository.php';
        $words = [];
        foreach (SourceScan::strings($file) as [$text]) {
            // every word of every literal: SQL fragments, column names, array keys
            foreach (preg_split('/[^A-Za-z0-9_]+/', $text) ?: [] as $word) {
                if ($word !== '') {
                    $words[$word] = true;
                }
            }
        }
        self::assertArrayHasKey('tp_scout_leads', $words, 'the scan reads the SQL of the repository');
        foreach (self::CONTENT_KEYS as $key) {
            self::assertArrayNotHasKey($key, $words, 'the lead repository names "' . $key . '"');
        }
        // The only Google columns it names are the three a lookup leaves.
        $google = array_values(array_filter(array_keys($words), static fn (string $w): bool => str_starts_with($w, 'g_') || str_starts_with($w, 'google')));
        sort($google);
        $expected = self::LOOKUP_COLUMNS;
        sort($expected);
        self::assertSame($expected, $google);
        // Every name with an underscore in it is the table, a column of the table, the outcome `not_found`,
        // or one of the two keys under which a read row hands on the outcome and the time of a lookup.
        $known = array_merge(self::LEAD_COLUMNS, ['tp_scout_leads', 'not_found', 'lookup_state', 'matched_at']);
        foreach (array_keys($words) as $word) {
            if (preg_match('/^[a-z]+(_[a-z0-9]+)+$/', (string) $word) === 1) {
                self::assertContains($word, $known, (string) $word);
            }
        }
        // Its one write of a lookup takes a flag and a place id: there is no parameter a text could ride in on.
        $setMatch = new \ReflectionMethod(ScoutLeadRepository::class, 'setMatch');
        self::assertSame(['id', 'orgId', 'found', 'placeId'], array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $setMatch->getParameters()));
        self::assertSame(['status', 'notes', 'spot_id', 'place_name', 'place_type', 'lat', 'lng'], array_keys(ScoutLeadRepository::COLUMNS));
    }

    public function testTheContactLookupHandsTheAnswerToNothingButTheResponse(): void
    {
        $file = 'src/TruckPlanner/Services/ScoutingService.php';
        $lookup = self::methodCode($file, ScoutingService::class, 'lookupContact');
        // No cache and no log line inside the lookup.
        self::assertStringNotContainsString('TpCache', $lookup);
        self::assertStringNotContainsString('error_log', $lookup);
        self::assertStringNotContainsString('->put(', $lookup);
        // What it calls on the lead repository: the read, the lead row with the place's own snapshot, and the match.
        preg_match_all('/\$this->leads->(\w+)\(/', $lookup, $calls);
        self::assertSame(['find', 'upsert', 'setMatch'], $calls[1]);
        self::assertStringContainsString('$this->leads->upsert($orgId, $truckId, $regionId, $placeKey, self::snapshot($place))', $lookup);
        self::assertStringContainsString("\$this->leads->setMatch(\$leadId, \$orgId, \$match !== null, \$match['place_id'] ?? null)", $lookup);
        // Google's answer (`$answer`, `$match`) goes to contact(), which shapes the response, and of it only
        // the place id goes anywhere else.
        foreach (['$answer', '$match'] as $variable) {
            preg_match_all('/' . preg_quote($variable, '/') . '\b[^;]*;/', $lookup, $uses);
            foreach ($uses[0] as $statement) {
                self::assertDoesNotMatchRegularExpression('/->leads->upsert|TpCache|error_log|->spots|spotService/', $statement, $statement);
            }
        }
        // contact() builds an array and stores nothing.
        $contact = self::methodCode($file, ScoutingService::class, 'contact');
        self::assertDoesNotMatchRegularExpression('/\$this->(leads|spots|spotService|places)\b|TpCache|error_log/', $contact);
        self::assertStringContainsString("'saved' => false", $contact);

        // The snapshot a lead keeps is the place's own: its OpenStreetMap name, its type and its point.
        $snapshot = self::methodCode($file, ScoutingService::class, 'snapshot');
        preg_match_all("/'([a-z_]+)' =>/", $snapshot, $keys);
        self::assertSame(['place_name', 'place_type', 'lat', 'lng'], $keys[1]);

        // The client has no store of its own.
        $client = SourceScan::code('src/TruckPlanner/Services/Google/PlacesContactClient.php');
        self::assertDoesNotMatchRegularExpression('/TpCache|CacheService|Database\b|file_put_contents|fwrite/', $client);
    }

    public function testTheExportAndThePurgeKnowNothingOfLookedUpDetails(): void
    {
        $export = SourceScan::code('src/TruckPlanner/Services/ExportService.php');
        self::assertSame(1, preg_match('/private static function lead\(array \$row\): array\s*\{(.*?)\n    \}/s', $export, $m));
        preg_match_all("/'([a-z_]+)' => \\\$row/", $m[1], $keys);
        self::assertSame(['place_key', 'place_name', 'place_type', 'lat', 'lng', 'status', 'notes', 'spot_id', 'google_place_id'], $keys[1]);

        $data = SourceScan::code('src/TruckPlanner/Data/TruckDataRepository.php');
        self::assertSame(1, preg_match('/private const LEAD_COLUMNS = \'(.*?)\';/s', $data, $m));
        $selected = array_values(array_filter(array_map('trim', explode(',', (string) preg_replace('/\s+/', ' ', $m[1])))));
        self::assertSame(
            ['id', 'truck_id', 'region_id', 'place_key', 'place_name', 'place_type', 'lat', 'lng', 'lead_state', 'notes', 'spot_id', 'google_place_id', 'created_at', 'updated_at'],
            $selected
        );
        // The daily sweep has two parts, and the leads are not one of them.
        $sweeps = (new \ReflectionClassConstant(\App\TruckPlanner\Services\DataPurgeService::class, 'SWEEPS'))->getValue();
        self::assertSame(['drive_legs', 'plan_snapshots'], array_keys($sweeps));
    }

    // ------------------------------------------------------------------------------------ a lookup, for real

    public function testAFoundLookupWritesThePlaceIdTheOutcomeAndTheTimeAndNothingElse(): void
    {
        $truck = SpotServiceTest::truck();
        $this->http->json(200, ['places' => [self::ANSWER + [
            'id' => self::PLACE_ID,
            'location' => ['latitude' => FixtureRegion::north(FixtureRegion::TAPROOM['lat'], 35.0), 'longitude' => FixtureRegion::TAPROOM['lng']],
        ]]]);
        // The reads of the lookup, in order: no lead yet, no row to change, then the lead as it was written.
        $this->leadRows->queue(null, null, self::leadRow());

        $answer = [];
        $lines = LogCapture::during(function () use (&$answer, $truck): void {
            $answer = $this->service->lookupContact(self::ORG, $truck, FixtureRegion::TAPROOM_KEY, false);
        });

        // The browser is told everything ...
        self::assertTrue($answer['contact']['found']);
        self::assertSame('SENTINEL-NAME Brewing Co', $answer['contact']['name']);
        self::assertSame('99 SENTINEL-ADDRESS Way, Sterling, VA 20166, USA', $answer['contact']['address']);
        self::assertSame('(703) 555-SENTINEL-PHONE', $answer['contact']['phone']);
        self::assertSame('https://sentinel-website.example.com/', $answer['contact']['website']);
        self::assertSame('https://maps.google.com/?cid=SENTINELCID', $answer['contact']['maps_uri']);
        self::assertFalse($answer['contact']['saved']);

        // ... and the database two things: the lead row with the place's own snapshot, and the match.
        $writes = array_values(array_filter($this->leadRows->calls, static fn (array $call): bool => $call['kind'] === 'query'));
        self::assertCount(2, $writes);
        self::assertSame(
            'INSERT INTO tp_scout_leads (id, organization_id, truck_id, region_id, place_key, lead_state, notes, spot_id, '
            . 'place_name, place_type, lat, lng, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            $writes[0]['sql']
        );
        $leadId = (string) $writes[0]['params'][0];
        self::assertSame(
            [$leadId, self::ORG, SpotServiceTest::TRUCK, FixtureRegion::REGION, FixtureRegion::TAPROOM_KEY, 'new', null, null,
                'Example Brewing', 'taproom', '39.01', '-77.41'],
            $writes[0]['params']
        );
        self::assertSame(
            'UPDATE tp_scout_leads SET google_place_id = ?, g_lookup_state = ?, g_fetched_at = NOW() WHERE id = ? AND organization_id = ?',
            $writes[1]['sql']
        );
        self::assertSame([self::PLACE_ID, 'found', $leadId, self::ORG], $writes[1]['params']);
        self::assertSame(['fetch', 'fetch', 'query', 'query', 'fetch'], $this->leadRows->kinds(), 'no transaction, no other statement');

        // No text of the answer is in any statement, bound value, cache entry, ledger row or log line.
        $this->assertNothingOfTheAnswerWasKept($lines);
        // The place id is kept once: in the one statement that is there to keep it.
        self::assertSame(1, substr_count((string) json_encode($this->leadRows->calls), self::PLACE_ID));
        self::assertStringNotContainsString(self::PLACE_ID, (string) json_encode($this->ledgerRows->calls) . json_encode($this->cache->values));
        self::assertSame([], $this->cache->values, 'a lookup that worked leaves no cache entry at all');
        // The ledger is read once (what is left of the spending allowance) and written once (the call).
        self::assertSame(['fetch', 'query'], $this->ledgerRows->kinds());
        self::assertCount(1, $this->ledgerRows->find('INSERT INTO api_cost_events'), 'one metered call');
    }

    public function testALaterLookupByTheKeptIdWritesNothingAtAll(): void
    {
        $truck = SpotServiceTest::truck();
        $this->http->json(200, self::ANSWER + ['id' => self::PLACE_ID]);
        $this->leadRows->queue(self::leadRow());

        $answer = [];
        $lines = LogCapture::during(function () use (&$answer, $truck): void {
            $answer = $this->service->lookupContact(self::ORG, $truck, FixtureRegion::TAPROOM_KEY, false);
        });

        self::assertSame('GET', $this->http->requests[0]['method']);
        self::assertStringEndsWith('/v1/places/' . self::PLACE_ID . '?languageCode=en', $this->http->requests[0]['url']);
        self::assertTrue($answer['contact']['found']);
        self::assertSame('(703) 555-SENTINEL-PHONE', $answer['contact']['phone']);
        self::assertSame('place_details', $answer['contact']['source']);
        self::assertFalse($answer['contact']['saved']);
        // one read of the lead, and not one write
        self::assertSame(['fetch'], $this->leadRows->kinds());
        $this->assertNothingOfTheAnswerWasKept($lines);
        self::assertSame([], $this->cache->values);
    }

    public function testASearchThatFindsNothingWritesTheOutcomeAndNoId(): void
    {
        $truck = SpotServiceTest::truck();
        // Google's best answer is a place of the same name four kilometres away: it is not the place.
        $this->http->json(200, ['places' => [self::ANSWER + [
            'id' => self::PLACE_ID,
            'location' => ['latitude' => FixtureRegion::north(FixtureRegion::TAPROOM['lat'], 4000.0), 'longitude' => FixtureRegion::TAPROOM['lng']],
        ]]]);
        $this->leadRows->queue(null, null, ['google_place_id' => null, 'g_lookup_state' => 'not_found'] + self::leadRow());

        $answer = [];
        $lines = LogCapture::during(function () use (&$answer, $truck): void {
            $answer = $this->service->lookupContact(self::ORG, $truck, FixtureRegion::TAPROOM_KEY, false);
        });

        self::assertFalse($answer['contact']['found']);
        $update = $this->leadRows->only('UPDATE tp_scout_leads');
        self::assertSame([null, 'not_found'], array_slice($update['params'], 0, 2));
        $this->assertNothingOfTheAnswerWasKept($lines);
        // not even the id of the place that was not taken, and nothing of it in the answer
        $everything = (string) json_encode($this->leadRows->calls) . json_encode($answer);
        self::assertStringNotContainsStringIgnoringCase('sentinel', $everything);
    }

    public function testAFailedLookupWritesNothing(): void
    {
        $truck = SpotServiceTest::truck();
        $this->http->queue(500, json_encode(['error' => ['message' => 'SENTINEL-NAME upstream text', 'status' => 'INTERNAL']]) ?: '');
        $this->leadRows->queue(null);
        $lines = LogCapture::during(function () use ($truck): void {
            try {
                $this->service->lookupContact(self::ORG, $truck, FixtureRegion::TAPROOM_KEY, false);
                self::fail('a failed lookup was answered');
            } catch (\App\TruckPlanner\Services\Support\TpUnavailable $e) {
                self::assertSame('Contact lookup is not available on this server', $e->getMessage());
            }
        });
        self::assertSame(['fetch'], $this->leadRows->kinds());
        $this->assertNothingOfTheAnswerWasKept($lines);
        // what is remembered of the failure is that there was one
        self::assertSame(['tp:places:backoff'], array_keys($this->cache->values));
    }

    // ------------------------------------------------------------------------------------ helpers

    /**
     * No text Google answered is in a statement or a bound value of the lead table, in a ledger row, in a
     * cache entry or in a log line.
     *
     * @param list<string> $lines what was logged
     */
    private function assertNothingOfTheAnswerWasKept(array $lines): void
    {
        $kept = [
            'the statements of the lead table' => (string) json_encode($this->leadRows->calls),
            'the ledger' => (string) json_encode($this->ledgerRows->calls),
            'the cache' => (string) json_encode($this->cache->values),
            'the log' => implode("\n", $lines),
        ];
        foreach ($kept as $where => $text) {
            foreach (['SENTINEL-NAME', 'SENTINEL-ADDRESS', 'SENTINEL-PHONE', 'sentinel-website', 'SENTINELCID', '(703)', 'maps.google.com'] as $trace) {
                self::assertStringNotContainsString($trace, $text, $trace . ' in ' . $where);
            }
        }
    }

    /**
     * The lead of the taproom as MySQL returns it after a lookup that matched.
     *
     * @return array<string, mixed>
     */
    private static function leadRow(): array
    {
        return [
            'id' => '55555555-5555-4555-8555-555555555555', 'organization_id' => self::ORG, 'truck_id' => SpotServiceTest::TRUCK,
            'region_id' => FixtureRegion::REGION, 'place_key' => FixtureRegion::TAPROOM_KEY, 'place_name' => 'Example Brewing',
            'place_type' => 'taproom', 'lat' => 39.01, 'lng' => -77.41, 'lead_state' => 'new', 'notes' => null, 'spot_id' => null,
            'google_place_id' => self::PLACE_ID, 'g_lookup_state' => 'found', 'g_fetched_at' => '2026-10-08 03:30:00',
            'created_at' => '2026-10-08 03:30:00', 'updated_at' => '2026-10-08 03:30:00',
        ];
    }

    /**
     * The code of one method without its comments.
     *
     * @param class-string $class
     */
    private static function methodCode(string $file, string $class, string $method): string
    {
        $reflection = new \ReflectionMethod($class, $method);
        $code = '';
        foreach (SourceScan::tokens($file) as $token) {
            if ($token[2] >= $reflection->getStartLine() && $token[2] <= $reflection->getEndLine()) {
                $code .= $token[1];
            }
        }
        self::assertNotSame('', trim($code), $class . '::' . $method);
        return $code;
    }
}
