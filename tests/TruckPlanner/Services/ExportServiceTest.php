<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Data\TruckDataFixtures;
use App\Tests\TruckPlanner\Data\TruckTables;
use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Data\TruckDataRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\ExportService;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\SourcesService;
use App\TruckPlanner\Services\SpotService;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use PHPUnit\Framework\TestCase;

// The in-memory owner tables and their fixture rows live with the repository's test.
require_once dirname(__DIR__) . '/Data/TruckDataRepositoryTest.php';

/**
 * ExportService over the real repositories and an in-memory copy of the owner tables (TruckTables): what
 * the document holds, what it must never hold, and that it is valid JSON whatever happens on the way.
 */
final class ExportServiceTest extends TestCase
{
    private const ORG = TruckDataFixtures::ORG;
    private const OTHER_ORG = TruckDataFixtures::OTHER_ORG;
    private const KEYS = [
        'export', 'export_version', 'exported_at', 'model_version', 'seeds_revision', 'truck', 'overrides',
        'spots', 'plans', 'services', 'drive_overrides', 'scout_leads', 'attribution', 'incomplete',
    ];

    private TruckTables $tables;
    private FixedClock $clock;

    /** The dataset version that is active for the region `dc`. */
    private ?string $activeDataset = TruckDataFixtures::DATASET;

    /** @var list<array{0: string, 1: bool}> every piece the service wrote, with its "send now" flag */
    private array $pieces = [];

    /** @var list<string> the file names the service announced before writing */
    private array $begun = [];

    protected function setUp(): void
    {
        $this->tables = TruckDataFixtures::tables();
        // 16:00 UTC on Monday 2026-10-05 is noon where the truck is.
        $this->clock = new FixedClock('2026-10-05 16:00:00');
    }

    protected function tearDown(): void
    {
        TpConfig::replace(null);
    }

    // ------------------------------------------------------------------------------------ fixtures

    private function service(): ExportService
    {
        $test = $this;
        $regions = new class(new RegionRepository(new RecordingDatabase()), $test) extends RegionService {
            private ExportServiceTest $test;

            public function __construct(RegionRepository $rows, ExportServiceTest $test)
            {
                parent::__construct($rows);
                $this->test = $test;
            }

            public function active(string $regionId): ?array
            {
                $version = $this->test->activeDataset();
                if ($regionId !== 'dc' || $version === null) {
                    return null;
                }
                return [
                    'region_id' => 'dc', 'dataset_version' => $version, 'timezone' => 'America/New_York', 'h3_res' => 9,
                    'usable' => true, 'unusable_reason' => null, 'config' => [],
                ];
            }
        };
        $counts = new CountsRepository($this->tables);
        return new ExportService(
            new TruckDataRepository($this->tables),
            new TruckRepository($this->tables),
            $counts,
            new SpotService(new SpotRepository(new RecordingDatabase()), $counts, $regions),
            $regions,
            $this->clock,
            function (string $piece, bool $send): void {
                $this->pieces[] = [$piece, $send];
            }
        );
    }

    public function activeDataset(): ?string
    {
        return $this->activeDataset;
    }

    /** Runs the export of an organization and answers the document as text. */
    private function export(string $org = self::ORG): string
    {
        $this->pieces = [];
        $this->begun = [];
        $this->service()->stream($org, function (string $filename): void {
            self::assertSame([], $this->pieces, 'the file name is announced before the first byte');
            $this->begun[] = $filename;
        });
        return implode('', array_column($this->pieces, 0));
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $text): array
    {
        $document = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        return $document;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return array<string, array<string, mixed>>
     */
    private static function byId(array $list, string $key = 'id'): array
    {
        $out = [];
        foreach ($list as $item) {
            $out[(string) $item[$key]] = $item;
        }
        return $out;
    }

    /**
     * Every file below a directory with its size and modification time.
     *
     * @return array<string, string>
     */
    private static function listing(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            // The PHP error log is written by whatever logs; an export is not a log line.
            if ($file->isFile() && !str_ends_with($file->getFilename(), '.log')) {
                $files[$file->getPathname()] = $file->getSize() . '@' . $file->getMTime();
            }
        }
        ksort($files);
        return $files;
    }

    // ------------------------------------------------------------------------------------ the document

    public function testTheExportIsOneJsonDocumentThatSaysItIsComplete(): void
    {
        $text = $this->export();
        $document = self::decode($text);

        self::assertSame(self::KEYS, array_keys($document));
        self::assertSame('truck-planner', $document['export']);
        self::assertSame(1, $document['export_version']);
        self::assertSame('2026-10-05T16:00:00Z', $document['exported_at']);
        self::assertSame(Estimator::MODEL_VERSION, $document['model_version']);
        self::assertSame(Seeds::revision(), $document['seeds_revision']);
        self::assertFalse($document['incomplete']);
        self::assertStringEndsWith(',"incomplete":false}', $text);
        // The header carries the sentence for place data (03_DATA.md section 14, string 2).
        self::assertSame([SourcesService::TEXTS[2]], $document['attribution']);
        self::assertSame(["Place data \u{00A9} OpenStreetMap contributors, available under the Open Database License (ODbL)."], $document['attribution']);
        // The file is named for today where the truck is, and announced once.
        self::assertSame(['truck-planner-export-20261005.json'], $this->begun);
        // It is a bare document, not the house envelope.
        self::assertArrayNotHasKey('success', $document);
        self::assertArrayNotHasKey('data', $document);
    }

    public function testTheTruckAndItsOverridesAreTheOwnersAsStored(): void
    {
        $text = $this->export();
        $document = self::decode($text);

        // The API's TruckRecord, as JSON carries it.
        $row = (new TruckRepository($this->tables))->findByOrg(self::ORG);
        $record = json_decode((string) json_encode((new ProfileMapper())->toRecord((array) $row)), true);
        self::assertSame($record, $document['truck']);
        self::assertSame(['id', 'timezone', 'base_state', 'base_county_fips', 'profile', 'created_at', 'updated_at'], array_keys($document['truck']));
        self::assertSame(TruckDataFixtures::TRUCK, $document['truck']['id']);
        self::assertSame('Smoke & Ember', $document['truck']['profile']['name']);
        self::assertSame(15.0, (float) $document['truck']['profile']['avg_ticket']);
        self::assertSame(['51107', '51059'], $document['truck']['profile']['licence_counties']);
        self::assertSame(['host.captive_share' => 0.6, 'weather.floor' => 0.20000010000000001], $document['overrides']);
        // A number of seventeen digits leaves as it was saved.
        self::assertStringContainsString('"overrides":{"host.captive_share":0.6,"weather.floor":0.20000010000000001}', $text);
        self::assertArrayNotHasKey('organization_id', $document['truck']);

        // Without overrides the map is an empty object, not an empty list.
        $this->tables->rows['tp_trucks'][0]['overrides_json'] = '{}';
        self::assertStringContainsString('"overrides":{},"spots":[', $this->export());
    }

    public function testSpotsComeWithoutTheirVectors(): void
    {
        $document = self::decode($this->export());

        self::assertSame(['s1', 's2', 's3'], array_column($document['spots'], 'id'), 'every spot, the archived one too, by id');
        foreach ($document['spots'] as $spot) {
            self::assertSame(
                ['id', 'name', 'point', 'address', 'county_fips', 'notes', 'terms', 'host_details', 'logs', 'maps_url', 'archived', 'created_at', 'updated_at'],
                array_keys($spot)
            );
        }
        self::assertSame([
            'id' => 's1',
            'name' => 'Sterling taproom',
            'point' => ['lat' => 39.01, 'lng' => -77.41],
            'address' => '1 Example Rd',
            'county_fips' => '51107',
            'notes' => null,
            'terms' => [
                'spot_id' => 's1',
                'visibility' => 'normal',
                'host' => [
                    'segment' => 'v_nightlife', 'size' => 120.0, 'size_source' => 'owner', 'only_food' => true,
                    'point_id' => 'pw264230766', 'place_type' => 'taproom',
                ],
                'fee_flat' => 0.0,
                'fee_pct' => 0.1,
                'fee_min' => 75.0,
                'allowed' => null,
            ],
            'host_details' => [
                'place_type' => 'taproom', 'name' => 'Example Brewing', 'contact' => null, 'phone' => '(703) 555-0100',
                'website' => null, 'place_key' => 'w264230766', 'google_place_id' => 'ChIJexample',
            ],
            'logs' => ['count' => 1, 'last_date' => '2026-09-24'],
            'maps_url' => 'https://www.google.com/maps/search/?api=1&query=39.010000%2C-77.410000',
            'archived' => false,
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-01 08:00:00',
        ], self::normalise($document['spots'][0]));
        $plain = $document['spots'][1];
        self::assertNull($plain['terms']['host']);
        self::assertNull($plain['host_details']);
        self::assertSame(['count' => 0, 'last_date' => null], $plain['logs']);
        self::assertTrue($document['spots'][2]['archived']);
    }

    public function testPlansComeWithoutResultAndContextAndWithTheSummaryOfACurrentResult(): void
    {
        $text = $this->export();
        $plans = self::byId(self::decode($text)['plans']);

        self::assertSame(['p-expired', 'p-fresh', 'p-none', 'p-old', 'p-stale'], array_keys($plans));
        foreach ($plans as $plan) {
            self::assertSame(
                ['id', 'date', 'name', 'treat_as', 'notes', 'status', 'stops', 'result_state', 'evaluated_at', 'summary', 'maps_route_url', 'created_at', 'updated_at'],
                array_keys($plan)
            );
        }
        self::assertSame(
            ['p-expired' => 'expired', 'p-fresh' => 'fresh', 'p-none' => 'none', 'p-old' => 'stale', 'p-stale' => 'stale'],
            array_map(static fn (array $plan): string => $plan['result_state'], $plans)
        );

        // Only the current result gives its three figures.
        $fresh = $plans['p-fresh'];
        self::assertSame([
            'orders' => ['value' => 99.87, 'low' => 53.77, 'high' => 155.39, 'confidence' => 'rough'],
            'take_home' => ['value' => 482.2, 'low' => 42.35, 'high' => 1011.86, 'confidence' => 'rough'],
            'day_hours' => 11.283333333333333,
        ], $fresh['summary']);
        self::assertStringContainsString('"day_hours":11.283333333333333', $text);
        foreach (['p-expired', 'p-none', 'p-old', 'p-stale'] as $id) {
            self::assertNull($plans[$id]['summary'], $id);
        }
        self::assertSame(['2026-10-08', 'Thursday', null, 'Bring the awning', 'planned', '2026-10-04 23:50:12'], [
            $fresh['date'], $fresh['name'], $fresh['treat_as'], $fresh['notes'], $fresh['status'], $fresh['evaluated_at'],
        ]);

        // Its stops, in visiting order, each in the shape of the API.
        self::assertSame(['st1', 'st2', 'st3'], array_column($fresh['stops'], 'id'));
        self::assertSame([
            'id' => 'st1', 'kind' => 'spot', 'spot_id' => 's1', 'label' => '', 'point' => null, 'address' => '',
            'open_minute' => 1020, 'close_minute' => 1200, 'gap_before_unpaid' => false, 'setup_minutes' => null, 'teardown_minutes' => null,
            'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'event' => null, 'catering' => null,
        ], self::normalise($fresh['stops'][0]));
        self::assertSame(['lat' => 38.95, 'lng' => -77.35], $fresh['stops'][1]['point']);
        self::assertSame(['attendance' => 4000.0, 'vendors' => 12, 'event_type' => 'general'], self::normalise($fresh['stops'][1]['event']));
        self::assertSame([100.0, 0.1, 150.0], [(float) $fresh['stops'][1]['fee_flat'], $fresh['stops'][1]['fee_pct'], (float) $fresh['stops'][1]['fee_min']]);
        self::assertNull($fresh['stops'][1]['catering']);
        self::assertSame(
            ['headcount' => 80.0, 'price_per_head' => 14.0, 'guarantee' => 1000.0, 'food_cost' => null],
            self::normalise($fresh['stops'][2]['catering'])
        );
        self::assertTrue($fresh['stops'][2]['gap_before_unpaid']);

        // The route link: base, the stops in order (a spot stop at its spot), base.
        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&origin=39.003000%2C-77.405000&destination=39.003000%2C-77.405000'
            . '&waypoints=39.010000%2C-77.410000%7C38.950000%2C-77.350000%7C38.900000%2C-77.300000&travelmode=driving',
            $fresh['maps_route_url']
        );
        self::assertNull($plans['p-none']['maps_route_url'], 'a plan without stops has no route');
        self::assertSame([], $plans['p-none']['stops']);
        // A stop of another organization that names this plan does not join it.
        self::assertCount(3, $fresh['stops']);
    }

    public function testAResultIsCurrentOnlyWhileNothingItRestsOnHasMoved(): void
    {
        $state = fn (): string => self::byId(self::decode($this->export())['plans'])['p-fresh']['result_state'];
        $restore = function (): void {
            $this->tables = TruckDataFixtures::tables();
            $this->activeDataset = TruckDataFixtures::DATASET;
        };
        self::assertSame('fresh', $state());

        // The truck was changed after the result was computed.
        $this->tables->rows['tp_trucks'][0]['updated_at'] = '2026-10-04 23:50:13';
        self::assertSame('stale', $state());
        $restore();
        // In the very second of the evaluation it is not "after".
        $this->tables->rows['tp_trucks'][0]['updated_at'] = '2026-10-04 23:50:12';
        self::assertSame('fresh', $state());

        // A service was logged or changed after it.
        $restore();
        $this->tables->rows['tp_service_logs'][1]['updated_at'] = '2026-10-05 09:00:00';
        self::assertSame('stale', $state());

        // A spot of the plan changed after it. (A spot the plan does not visit may change freely.)
        $restore();
        $this->tables->rows['tp_spots'][1]['updated_at'] = '2026-10-05 09:00:00';
        self::assertSame('fresh', $state());
        $this->tables->rows['tp_spots'][0]['updated_at'] = '2026-10-05 09:00:00';
        self::assertSame('stale', $state());

        // The region's data, the seeds or the model moved on.
        $restore();
        $this->activeDataset = 'dc-20270104-0badf00d';
        self::assertSame('stale', $state());
        $restore();
        $this->activeDataset = null;
        self::assertSame('stale', $state());
        $restore();
        $this->tables->rows['tp_plans'][0]['seeds_revision'] = Seeds::revision() + 1;
        self::assertSame('stale', $state());
        $restore();
        $this->tables->rows['tp_plans'][0]['model_version'] = 'tps-0.0.9';
        self::assertSame('stale', $state());

        // Google legs and thirty days: expired. Until that second the result is still shown.
        $restore();
        $this->tables->now = '2026-11-03 23:50:13';
        self::assertSame('expired', $state());
        $this->tables->now = '2026-11-03 23:50:12';
        self::assertSame('fresh', $state());

        // A result whose text is gone or broken gives no summary, and the document still stands.
        $restore();
        $this->tables->rows['tp_plans'][0]['result_json'] = '{"totals": {"orders": 3}}';
        $plan = self::byId(self::decode($this->export())['plans'])['p-fresh'];
        self::assertSame(['fresh', null], [$plan['result_state'], $plan['summary']]);

        // A result that was never dated counts as none, as it does on the plan routes.
        $restore();
        $this->tables->rows['tp_plans'][0]['evaluated_at'] = null;
        $plan = self::byId(self::decode($this->export())['plans'])['p-fresh'];
        self::assertSame(['none', null], [$plan['result_state'], $plan['summary']]);
    }

    public function testServicesCorrectionsAndLeadsAreTheOwnersOwnData(): void
    {
        $document = self::decode($this->export());

        self::assertSame(['l1', 'l2'], array_column($document['services'], 'id'));
        self::assertSame([
            'id' => 'l1', 'kind' => 'spot', 'spot_id' => 's1', 'plan_id' => null, 'plan_stop_id' => null, 'date' => '2026-09-24',
            'open_minute' => 660, 'close_minute' => 840, 'actual' => 52, 'sales' => 780.5, 'sold_out' => true, 'notes' => 'Rain at noon',
            'source' => 'manual', 'external_key' => null, 'treat_as' => null,
            'prediction' => [
                'predicted_raw' => 39.384, 'predicted' => 38.1, 'low' => 20.1, 'high' => 60.2, 'confidence' => 'rough', 'basis' => 'log',
                'model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => TruckDataFixtures::DATASET,
                'detail' => ['basis' => 'log', 'plan_id' => null, 'holiday' => null, 'spread' => ['sigma' => 0.41], 'evidence' => ['truck_weight' => 0]],
            ],
            'created_at' => '2026-10-01 08:00:00', 'updated_at' => '2026-09-24 21:00:00',
        ], $document['services'][0]);
        self::assertSame(['catering', 80, null, null], [
            $document['services'][1]['kind'], $document['services'][1]['actual'], $document['services'][1]['sales'], $document['services'][1]['prediction'],
        ]);

        self::assertSame([[
            'id' => 'c1',
            'from' => ['lat' => 38.96, 'lng' => -77.36],
            'to' => ['lat' => 39.003, 'lng' => -77.405],
            'minutes' => 14,
            'toll' => 3.75,
            'note' => 'School zone',
            'updated_at' => '2026-10-01 08:00:00',
        ]], $document['drive_overrides']);

        self::assertSame(['w264230766', 'n4100', 'w5200'], array_column($document['scout_leads'], 'place_key'));
        foreach ($document['scout_leads'] as $lead) {
            self::assertSame(['place_key', 'place_name', 'place_type', 'lat', 'lng', 'status', 'notes', 'spot_id', 'google_place_id'], array_keys($lead));
        }
        self::assertSame([
            'place_key' => 'w264230766', 'place_name' => 'Example Brewing', 'place_type' => 'taproom', 'lat' => 39.01, 'lng' => -77.41,
            'status' => 'contacted', 'notes' => 'Called Tuesday', 'spot_id' => 's1', 'google_place_id' => 'ChIJexample',
        ], $document['scout_leads'][0]);
    }

    public function testAPredictionWithoutDetailIsStillAnObject(): void
    {
        $this->tables->rows['tp_service_logs'][0]['prediction_json'] = null;
        $text = $this->export();
        self::assertStringContainsString('"detail":{}', $text);
        self::decode($text);
    }

    // ------------------------------------------------------------------------------------ what it must not hold

    public function testNoGoogleContentLeavesThroughTheExport(): void
    {
        // The fixture marks every stored piece of Google content: the looked-up contact fields of the
        // leads, the legs in the context and in the result of each plan, the shared leg cache.
        $marked = 0;
        foreach ($this->tables->rows as $rows) {
            $marked += substr_count((string) json_encode($rows), TruckDataFixtures::SENTINEL);
        }
        self::assertGreaterThan(20, $marked, 'the fixture holds Google content to leak');

        $text = $this->export();

        self::assertStringNotContainsString(TruckDataFixtures::SENTINEL, $text);
        foreach (['g_name', 'g_address', 'g_phone', 'g_website', 'g_maps_uri', 'g_fetched_at', 'g_lookup_state',
                  '"result"', '"context"', '"legs"', '"timeline"', 'duration_s', 'distance_m', 'google_routes', 'uses_google_legs',
                  '"vectors"', 'vectors_state', 'vectors_bin', 'WEATHER-OF-THE-DAY', '"weather"', 'weather_json'] as $never) {
            self::assertStringNotContainsString($never, $text, $never);
        }
        // No statement of the export read them either.
        foreach ($this->tables->statements() as $sql) {
            self::assertDoesNotMatchRegularExpression('/\bg_[a-z_]+\b|context_json|weather_json|vectors_bin|tp_drive_legs/', $sql);
        }
        // The one read of a result text is for the plan whose result is current.
        self::assertSame(['SELECT result_json FROM tp_plans WHERE id = ? AND organization_id = ?'], $this->tables->statements('result_json FROM'));
        // A place id of Google's may travel: the terms allow keeping it.
        self::assertStringContainsString('"google_place_id":"ChIJexample"', $text);
    }

    public function testNothingOfAnotherOrganizationIsInTheDocument(): void
    {
        $text = $this->export();
        self::assertStringNotContainsString('THEIR', $text);
        self::assertStringNotContainsString(self::OTHER_ORG, $text);
        self::assertStringNotContainsString(TruckDataFixtures::OTHER_TRUCK, $text);
        foreach ($this->tables->statements() as $sql) {
            self::assertStringContainsString('organization_id = ?', $sql);
        }

        // And the other organization gets its own data, not ours.
        $theirs = self::decode($this->export(self::OTHER_ORG));
        self::assertSame('THEIR TRUCK', $theirs['truck']['profile']['name']);
        self::assertSame(['t-s1'], array_column($theirs['spots'], 'id'));
        self::assertSame(['t-p1'], array_column($theirs['plans'], 'id'));
        self::assertSame(['t-st1'], array_column($theirs['plans'][0]['stops'], 'id'));
        self::assertSame(['t-l1'], array_column($theirs['services'], 'id'));
        self::assertSame(['t-c1'], array_column($theirs['drive_overrides'], 'id'));
        self::assertCount(1, $theirs['scout_leads']);
    }

    public function testNothingIsWrittenUnderStorage(): void
    {
        $storage = dirname(__DIR__, 3) . '/storage';
        $before = self::listing($storage);

        self::decode($this->export());

        self::assertSame($before, self::listing($storage), 'the export goes to the client and nowhere else');

        // And the class has no way to write a file: it names no file function and no storage service.
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/TruckPlanner/Services/ExportService.php');
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        foreach (['fopen', 'fwrite', 'fputs', 'file_put_contents', 'tmpfile', 'tempnam', 'touch(', 'mkdir', 'rename(', 'copy(',
                  'SplFileObject', 'StorageService', 'storage', 'php://'] as $never) {
            self::assertStringNotContainsStringIgnoringCase($never, $code, $never);
        }
    }

    // ------------------------------------------------------------------------------------ streaming

    public function testRowsAreReadAndSentAPageAtATime(): void
    {
        // With the page of 200 rows of the settings, each of the five tables is one read.
        $whole = $this->export();
        self::assertSame(200, TpConfig::get('requests.export_page_rows'));
        self::assertCount(5, $this->tables->statements(' ORDER BY id LIMIT ?'));

        $config = TpConfig::all();
        $config['requests']['export_page_rows'] = 2;
        TpConfig::replace($config);
        $this->tables = TruckDataFixtures::tables();
        $paged = $this->export();

        self::assertSame($whole, $paged, 'the page size does not change the document');
        // 3 spots, 5 plans, 2 services, 1 correction, 3 leads in pages of two: 2 + 3 + 2 + 1 + 2 page reads.
        self::assertCount(10, $this->tables->statements(' ORDER BY id LIMIT ?'));
        // Every page that holds rows is one piece, sent at once; so is the end of the document.
        $sent = array_values(array_filter($this->pieces, static fn (array $piece): bool => $piece[1]));
        self::assertCount(2 + 3 + 1 + 1 + 2 + 1, $sent);
        self::assertStringEndsWith('"incomplete":false}', $sent[count($sent) - 1][0]);
        // A page is whole rows: each piece that is sent on closes the objects it opened. (The last piece
        // closes the document itself.)
        foreach (array_slice($sent, 0, -1) as [$piece]) {
            self::assertSame(substr_count($piece, '{'), substr_count($piece, '}'), $piece);
        }
    }

    public function testATruckWithNothingElseExportsEmptyLists(): void
    {
        $this->tables = new TruckTables();
        $this->tables->add('tp_trucks', TruckDataFixtures::truck());

        $text = $this->export();
        $document = self::decode($text);

        foreach (['spots', 'plans', 'services', 'drive_overrides', 'scout_leads'] as $list) {
            self::assertSame([], $document[$list]);
            self::assertStringContainsString('"' . $list . '":[]', $text);
        }
        self::assertFalse($document['incomplete']);
        self::assertSame([], $this->tables->statements('tp_plan_stops'), 'no plan, no question about stops');
    }

    public function testTheFileIsNamedForTodayWhereTheTruckIs(): void
    {
        // 03:30 UTC on Tuesday is still Monday evening in New York.
        $this->clock->set('2026-10-06 03:30:00');
        $this->export();
        self::assertSame(['truck-planner-export-20261005.json'], $this->begun);

        $this->tables->rows['tp_trucks'][0]['timezone'] = 'Pacific/Auckland';
        $this->export();
        self::assertSame(['truck-planner-export-20261006.json'], $this->begun);

        // A zone this server does not know: the default zone names the file, the export goes on.
        $this->tables->rows['tp_trucks'][0]['timezone'] = 'Mars/Olympus_Mons';
        self::decode($this->export());
        self::assertSame(['truck-planner-export-20261005.json'], $this->begun);
        self::assertSame('truck-planner-export-20261005.json', $this->service()->filename(['timezone' => 'America/New_York']));
    }

    public function testWhatCannotTravelAsJsonIsSentAsNull(): void
    {
        $this->tables->rows['tp_spots'][1]['name'] = "Herndon \xC3\x28 park";         // not valid UTF-8
        $this->tables->rows['tp_service_logs'][0]['prediction_json'] = '{"spread": {"sigma": 1e999}}';

        $text = '';
        $lines = LogCapture::during(function () use (&$text): void {
            $text = $this->export();
        });

        $document = self::decode($text);
        self::assertFalse($document['incomplete']);
        self::assertNull($document['spots'][1]['name']);
        self::assertNull($document['services'][0]['prediction']['detail']['spread']['sigma']);
        self::assertContains('[tp] invalid text at name', $lines);
    }

    // ------------------------------------------------------------------------------------ failures

    public function testWithoutATruckNothingIsWritten(): void
    {
        try {
            $this->export('an-organization-without-a-truck');
            self::fail('an export without a truck');
        } catch (TpConflict $e) {
            self::assertSame('Set up your truck first', $e->getMessage());
        }
        self::assertSame([], $this->pieces);
        self::assertSame([], $this->begun, 'no header may go out before an error answer');
    }

    public function testAFailureBeforeTheFirstByteIsAnOrdinaryError(): void
    {
        // What is read up front: the truck, the newest log change, the log counts by spot.
        foreach (['FROM tp_trucks WHERE organization_id = ?', 'MAX(updated_at) AS last_change', 'COUNT(*) AS log_count'] as $statement) {
            $this->tables = TruckDataFixtures::tables();
            $this->tables->failOn($statement);
            try {
                $this->export();
                self::fail('the failure of "' . $statement . '" was swallowed');
            } catch (\RuntimeException $e) {
                self::assertSame('database failure (test)', $e->getMessage());
            }
            self::assertSame([], $this->pieces, $statement);
            self::assertSame([], $this->begun, $statement);
        }
    }

    public function testAFailureAfterTheFirstByteClosesTheDocumentAsIncomplete(): void
    {
        $this->tables->failOn('FROM tp_service_logs WHERE organization_id = ? AND id > ?');

        $text = '';
        $lines = LogCapture::during(function () use (&$text): void {
            $text = $this->export();
        });

        $document = self::decode($text);
        self::assertTrue($document['incomplete']);
        self::assertStringEndsWith('"services":[],"incomplete":true}', $text);
        // What was written before the failure is whole, what comes after it is absent.
        self::assertSame(
            ['export', 'export_version', 'exported_at', 'model_version', 'seeds_revision', 'truck', 'overrides', 'spots', 'plans', 'services', 'incomplete'],
            array_keys($document)
        );
        self::assertCount(3, $document['spots']);
        self::assertCount(5, $document['plans']);
        self::assertSame([], $document['services']);
        self::assertSame(['truck-planner-export-20261005.json'], $this->begun);
        self::assertSame(['[tp] export ended early: RuntimeException: database failure (test)'], $lines);
    }

    public function testTheDocumentIsValidJsonWhicheverReadFails(): void
    {
        $config = TpConfig::all();
        $config['requests']['export_page_rows'] = 2;
        TpConfig::replace($config);

        // The statements of a clean run, in order. The first three are read before the first byte.
        $this->export();
        $statements = $this->tables->statements();
        self::assertGreaterThan(14, count($statements));
        $upFront = 3;

        $seen = [];
        foreach ($statements as $i => $sql) {
            $occurrence = $seen[$sql] ?? 0;
            $seen[$sql] = $occurrence + 1;
            if ($i < $upFront) {
                continue;
            }
            $this->tables = TruckDataFixtures::tables();
            $this->tables->failOn($sql, $occurrence);
            $text = '';
            $lines = LogCapture::during(function () use (&$text): void {
                $text = $this->export();
            });

            $document = self::decode($text);
            self::assertTrue($document['incomplete'], 'statement ' . $i . ': ' . $sql);
            self::assertStringEndsWith(',"incomplete":true}', $text);
            self::assertCount(1, $lines, 'statement ' . $i);
            self::assertSame(array_slice(self::KEYS, 0, count($document) - 1), array_slice(array_keys($document), 0, -1), 'statement ' . $i);
            // Every row that was written is a whole row.
            foreach (['spots', 'plans', 'services', 'drive_overrides', 'scout_leads'] as $list) {
                foreach ($document[$list] ?? [] as $row) {
                    self::assertIsArray($row);
                    self::assertNotSame([], $row);
                }
            }
        }
    }

    /**
     * Whole numbers that JSON carried as integers, read as the floats the API types say they are.
     *
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private static function normalise(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::normalise($item);
            } elseif (is_int($item) && in_array($key, ['size', 'fee_flat', 'fee_pct', 'fee_min', 'attendance', 'headcount', 'price_per_head', 'guarantee', 'food_cost'], true)) {
                $value[$key] = (float) $item;
            }
        }
        return $value;
    }
}
