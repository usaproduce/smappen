<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\PointRepository;
use App\TruckPlanner\Data\RegionLoadRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\VectorCodec;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\CaptureService;
use App\TruckPlanner\Services\CellPackWriter;
use App\TruckPlanner\Services\RegionLoader;
use App\TruckPlanner\Services\RegionService;
use PHPUnit\Framework\TestCase;

/**
 * The region loader on the committed mini region (03_DATA.md section 10): Falls Church city, built by the
 * geodata pipeline itself (tests/fixtures/truck-planner/region-mini), 363 source points, 420 places, 197 cells.
 *
 * No database: the tables are arrays behind the repositories (the doubles at the end of this file), and
 * the request path of gate G21 is the real CaptureService reading those arrays through the same boxes the
 * SQL reads. The statements themselves are held by the repository tests and by the load on MySQL in CI.
 */
final class RegionLoaderTest extends TestCase
{
    private const VERSION = 'mini-20261003-8d5536a4';
    private const FILES = ['manifest.json', 'points.tsv', 'places.ndjson', 'cells.tsv', 'job_review.csv'];

    private MemoryTables $tables;

    /** @var list<string> */
    private array $out = [];
    /** @var list<string> */
    private array $err = [];
    /** @var list<string> */
    private array $temporary = [];

    protected function setUp(): void
    {
        $this->tables = new MemoryTables();
        $this->out = [];
        $this->err = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
        $this->temporary = [];
    }

    // ------------------------------------------------------------------------------------ helpers

    private static function fixture(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2)) . '/fixtures/truck-planner/region-mini/build';
    }

    private function loader(?PointRepository $points = null, ?PlaceRepository $places = null): RegionLoader
    {
        $regions = new MemoryRegionRepository($this->tables);
        $points ??= new MemoryPointRepository($this->tables);
        $places ??= new MemoryPlaceRepository($this->tables);
        return new RegionLoader(
            new MemoryLoadRepository($this->tables),
            $regions,
            new RegionService($regions),
            new CaptureService(null, $points, $places, $regions),
            $places,
            function (string $line): void {
                $this->out[] = $line;
            },
            function (string $line): void {
                $this->err[] = $line;
            }
        );
    }

    /**
     * @param array<string, string|bool> $opts
     */
    private function runLoader(array $opts, ?RegionLoader $loader = null): int
    {
        $this->out = [];
        $this->err = [];
        return ($loader ?? $this->loader())->run($opts);
    }

    private function printed(): string
    {
        return implode("\n", $this->out);
    }

    private function errors(): string
    {
        return implode("\n", $this->err);
    }

    /**
     * A copy of the mini build in a temporary directory, with some files rewritten. When a data file
     * changes, its size, hash and row count in the manifest follow, as if the pipeline had written it.
     *
     * @param array<string, callable(string): string> $edits file name => new content from old content
     */
    private function copyOfBuild(array $edits = [], bool $keepManifestInStep = true): string
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/tp-loader-test-' . bin2hex(random_bytes(6));
        mkdir($dir);
        $this->temporary[] = $dir;
        foreach (self::FILES as $file) {
            copy(self::fixture() . '/' . $file, $dir . '/' . $file);
        }
        foreach ($edits as $file => $edit) {
            if ($file === 'manifest.json') {
                continue;
            }
            $content = $edit((string) file_get_contents($dir . '/' . $file));
            file_put_contents($dir . '/' . $file, $content);
            if ($keepManifestInStep) {
                $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true);
                foreach ($manifest['outputs'] as &$output) {
                    if ($output['file'] === $file) {
                        $output['bytes'] = strlen($content);
                        $output['sha256'] = hash('sha256', $content);
                        $output['rows'] = substr_count($content, "\n") - ($file === 'places.ndjson' ? 0 : 1);
                    }
                }
                unset($output);
                file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
            }
        }
        if (isset($edits['manifest.json'])) {
            file_put_contents($dir . '/manifest.json', $edits['manifest.json']((string) file_get_contents($dir . '/manifest.json')));
        }
        return $dir;
    }

    /**
     * Rewrites the manifest of a copy through its decoded form.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @return callable(string): string
     */
    private static function manifestEdit(callable $change): callable
    {
        return static fn (string $text): string => json_encode($change(json_decode($text, true)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }

    /**
     * Rewrites the first line of places.ndjson through its decoded form.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $change
     * @return callable(string): string
     */
    private static function firstPlaceEdit(callable $change): callable
    {
        return static function (string $text) use ($change): string {
            $lines = explode("\n", $text);
            $lines[0] = json_encode($change(json_decode($lines[0], true)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return implode("\n", $lines);
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function pack(string $version = self::VERSION): array
    {
        return $this->tables->packs['mini|' . $version];
    }

    /** A loaded version in the tables, without going through a load. */
    private function seedVersion(string $version, string $state = 'ready', ?array $kernel = null): void
    {
        $regions = new MemoryRegionRepository($this->tables);
        $this->tables->regions['mini'] ??= [
            'region_id' => 'mini', 'name' => 'Falls Church test region', 'cbsa' => null, 'timezone' => 'America/New_York', 'h3_res' => 9,
            'bbox_lat_min' => 38.87, 'bbox_lng_min' => -77.2, 'bbox_lat_max' => 38.9, 'bbox_lng_max' => -77.15, 'center_lat' => 38.88,
            'center_lng' => -77.17, 'active_version' => null, 'previous_version' => null, 'config' => ['id' => 'mini'],
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ];
        $this->tables->packs['mini|' . $version] = [
            'region_id' => 'mini', 'dataset_version' => $version, 'load_state' => $state, 'model_version' => 'tps-0.1.0',
            'pipeline_version' => 'tp-etl-1.0.0', 'osm_snapshot' => '2026-01-01', 'point_count' => 2, 'place_count' => 1, 'cell_count' => 1,
            'pack_format' => 1, 'pack_len' => 10, 'pack_gz_len' => 5, 'pack_sha256' => str_repeat('00', 32), 'pack_gz' => 'gz',
            'kernel' => $state === 'ready' ? ['seeds_revision' => 1, 'kernel' => $kernel ?? (new RegionService($regions))->kernelFromSeeds()] : null,
            'manifest' => null, 'loaded_at' => '2026-01-01 00:00:00', 'activated_at' => null,
        ];
        $this->tables->points['mini|' . $version] = ['b1' => ['point_id' => 'b1'], 'b2' => ['point_id' => 'b2']];
        $this->tables->places['mini|' . $version] = ['n1' => ['place_key' => 'n1']];
    }

    // ------------------------------------------------------------------------------------ a dry run

    public function testADryRunRunsEveryCheckThatNeedsNoDatabaseAndWritesNothing(): void
    {
        // the real repositories over a database that records: a dry run must not even ask it anything
        $db = new RecordingDatabase();
        $regions = new RegionRepository($db);
        $loader = new RegionLoader(
            new RegionLoadRepository($db),
            $regions,
            new RegionService($regions),
            new CaptureService(new RegionService($regions), new PointRepository($db), new PlaceRepository($db), $regions),
            new PlaceRepository($db),
            function (string $line): void {
                $this->out[] = $line;
            },
            function (string $line): void {
                $this->err[] = $line;
            }
        );
        $code = $loader->run(['build' => self::fixture(), 'dry-run' => true]);

        self::assertSame('', $this->errors());
        self::assertSame(0, $code);
        self::assertSame([], $db->calls, 'a dry run reads the files and writes nothing');

        $output = $this->printed();
        self::assertStringContainsString('Dry run of ' . self::VERSION . ' (region mini)', $output);
        self::assertMatchesRegularExpression('/^ok\s+files\s+points\.tsv: 363 rows, 51374 bytes/m', $output);
        self::assertMatchesRegularExpression('/^ok\s+files\s+places\.ndjson: 420 rows/m', $output);
        self::assertMatchesRegularExpression('/^ok\s+files\s+cells\.tsv: 197 rows/m', $output);
        self::assertMatchesRegularExpression('/^ok\s+files\s+job_review\.csv: 7 rows/m', $output);
        self::assertMatchesRegularExpression('/^ok\s+pipeline\s+every fail-level gate of the pipeline passed \(59 checks, warnings: G12\.hours, G12\.types, G15\.entries, G15\.unconfirmed\)/m', $output);
        self::assertMatchesRegularExpression('/^ok\s+G19\.model_version\s+model version tps-0\.1\.0/m', $output);
        self::assertMatchesRegularExpression('/^ok\s+G19\.parameters /m', $output);
        // the mini region names no traffic matrix: it uses us_mean
        self::assertMatchesRegularExpression('/^ok\s+G19\.traffic_matrix\s+traffic matrix us_mean /m', $output);
        self::assertMatchesRegularExpression('/^ok\s+G20\s+pruning cross-check on 197 cells/m', $output);
        self::assertMatchesRegularExpression('/^skip\s+G21\s+request-path self-check: not run in a dry run/m', $output);
        self::assertMatchesRegularExpression('/^ok\s+G22\s+pack of 197 cells: 24340 bytes, \d+ gzipped; every value decodes within the quantisation bound/m', $output);
        self::assertStringContainsString("points           363\n", $output);
        self::assertStringContainsString('places           420 (rivals 289, possible hosts 83)', $output);
        self::assertStringContainsString("cells            197\n", $output);
        self::assertMatchesRegularExpression('/^timings\s+verify \d+\.\d s, places \d+\.\d s, /m', $output);
        self::assertMatchesRegularExpression('/^wall clock\s+\d+\.\d s   peak memory \d+ MiB$/m', $output);
        self::assertStringEndsWith('state            nothing written (dry run)', $output);
    }

    public function testADryRunWithActivateSaysThatNothingWasActivated(): void
    {
        self::assertSame(0, $this->runLoader(['build' => self::fixture(), 'dry-run' => true, 'activate' => true]));
        self::assertSame([], $this->tables->log);
        self::assertStringEndsWith('--activate was not applied: a dry run writes nothing.', $this->printed());
    }

    // ------------------------------------------------------------------------------------ a load

    public function testALoadFillsTheTablesStoresThePackAndPassesEveryGate(): void
    {
        $code = $this->runLoader(['build' => self::fixture()]);
        self::assertSame('', $this->errors());
        self::assertSame(0, $code);
        $output = $this->printed();
        foreach (['G19.model_version', 'G19.parameters', 'G19.traffic_matrix', 'G20', 'G21.cells', 'G21.hosts', 'G22'] as $gate) {
            self::assertMatchesRegularExpression('/^ok\s+' . preg_quote($gate, '/') . '\s/m', $output, $gate);
        }
        self::assertStringNotContainsString('FAIL', $output);
        self::assertStringNotContainsString('skip', $output);
        self::assertMatchesRegularExpression('/^ok\s+G21\.cells\s+request path equals the bulk result at 197 of 197 cells \(one in 1, the 20 with most people nearby, 1 at anchor blocks\): largest relative difference 0\.00e\+0/m', $output);
        self::assertMatchesRegularExpression('/^ok\s+G21\.hosts\s+request path equals the stored vector of 83 of 83 possible hosts \(one in 1\): largest relative difference 0\.00e\+0/m', $output);
        self::assertStringContainsString("state            ready\n", $output);
        self::assertStringEndsWith('To make it live: php scripts/truck/load-region.php --region=mini --activate=' . self::VERSION, $output);

        // ---- step 2: the region row, from the manifest. Nothing is live yet.
        $region = $this->tables->regions['mini'];
        self::assertSame('Falls Church test region', $region['name']);
        self::assertNull($region['cbsa']);
        self::assertSame('America/New_York', $region['timezone']);
        self::assertSame(9, $region['h3_res']);
        self::assertSame([38.87247, -77.195, 38.89989, -77.1497], [$region['bbox_lat_min'], $region['bbox_lng_min'], $region['bbox_lat_max'], $region['bbox_lng_max']]);
        self::assertSame([38.8847, -77.1756], [$region['center_lat'], $region['center_lng']]);
        self::assertNull($region['active_version']);
        $manifestText = (string) file_get_contents(self::fixture() . '/manifest.json');
        $manifest = json_decode($manifestText, true);
        self::assertSame($manifest['region'], $region['config'], 'config_json is the region definition');

        // ---- steps 4 and 7: every row of the build, under its dataset version
        $points = $this->tables->points['mini|' . self::VERSION];
        $places = $this->tables->places['mini|' . self::VERSION];
        self::assertCount(363, $points);
        self::assertCount(420, $places);
        self::assertSame(38.8813384, $points['b510131007003009']['lat']);
        self::assertSame(80.0, $points['b510131007003009']['base'][0]);
        self::assertSame(0, $points['b510131007003009']['in_region'], 'a halo block');
        self::assertSame('2026-10-03', reset($places)['snapshot_date']);

        // ---- step 6: the rival pull of a point is the model's, over the rivals of the build
        $outlets = [];
        foreach ($places as $key => $place) {
            if ($place['rival_kind'] !== null) {
                $outlets[] = ['id' => $key, 'lat' => (float) $place['lat'], 'lng' => (float) $place['lng'], 'kind' => $place['rival_kind']];
            }
        }
        self::assertCount(289, $outlets);
        foreach (['b510131007003009', 'b516105002004003', array_key_last($points)] as $id) {
            $expected = Estimator::rivalsAtOrigin(Seeds::defaults(), $points[$id]['lat'], $points[$id]['lng'], $outlets);
            self::assertSame($expected['day'], $points[$id]['rivals_day'], $id);
            self::assertSame($expected['eve'], $points[$id]['rivals_eve'], $id);
        }

        // ---- step 8a: a vector for every possible host and for nothing else
        $hosts = 0;
        foreach ($places as $key => $place) {
            $possible = $place['in_region'] === 1 && $place['host_fit'] > 0;
            self::assertSame($possible, isset($place['host_vec']), $key);
            if ($possible) {
                self::assertSame(400, strlen($place['host_vec']));
                $hosts++;
            }
        }
        self::assertSame(83, $hosts);

        // ---- step 12: the ledger row
        $pack = $this->pack();
        self::assertSame('ready', $pack['load_state']);
        self::assertSame([363, 420, 197], [$pack['point_count'], $pack['place_count'], $pack['cell_count']]);
        self::assertSame(1, $pack['pack_format']);
        self::assertSame('tps-0.1.0', $pack['model_version']);
        self::assertSame('tp-etl-1.0.0', $pack['pipeline_version']);
        self::assertSame('2026-10-03', $pack['osm_snapshot']);
        self::assertSame($manifestText, $pack['manifest'], 'the manifest is stored as the pipeline wrote it');
        $kernel = (new RegionService(new MemoryRegionRepository($this->tables)))->kernelFromSeeds();
        self::assertSame(['seeds_revision' => Seeds::revision(), 'kernel' => $kernel], $pack['kernel']);
        self::assertNull($pack['activated_at']);

        // ---- step 11: the pack that will be served
        $bytes = (string) gzdecode($pack['pack_gz']);
        self::assertSame($pack['pack_gz_len'], strlen($pack['pack_gz']));
        self::assertSame($pack['pack_len'], strlen($bytes));
        self::assertSame(24340, strlen($bytes));
        self::assertSame($pack['pack_sha256'], hash('sha256', $bytes), 'the hash is of the uncompressed bytes');
        $decoded = CellPackWriter::decode($bytes);
        $header = $decoded['header'];
        self::assertSame('mini', $header['region_id']);
        self::assertSame(self::VERSION, $header['dataset_version']);
        self::assertSame('tps-0.1.0', $header['model_version']);
        self::assertSame('tp-etl-1.0.0', $header['pipeline_version']);
        self::assertSame(9, $header['h3_res']);
        self::assertSame(197, $header['cell_count']);
        self::assertEquals($kernel, $header['kernel'], 'the kernel block is the PHP model\'s build-scope seeds');
        self::assertSame(['census_reference_date' => '2020-04-01', 'lodes_year' => 2023, 'osm_snapshot_date' => '2026-10-03'], $header['vintages']);
        self::assertSame(
            ['© OpenStreetMap contributors', 'U.S. Census Bureau, 2020 Census', 'U.S. Census Bureau, LEHD LODES 8.4 (2023)'],
            $header['attribution']
        );

        // the ids and the bounds are those of cells.tsv
        $cells = [];
        foreach (array_slice(file(self::fixture() . '/cells.tsv', FILE_IGNORE_NEW_LINES) ?: [], 1) as $line) {
            $cells[] = explode("\t", $line);
        }
        self::assertSame(array_column($cells, 0), $decoded['ids']);
        self::assertSame(min(array_map('floatval', array_column($cells, 1))), $header['bounds']['lat_min']);
        self::assertSame(max(array_map('floatval', array_column($cells, 2))), $header['bounds']['lng_max']);

        // ---- the request path at a cell centre gives the pack's row within the quantisation bound
        $regions = new MemoryRegionRepository($this->tables);
        $capture = new CaptureService(null, new MemoryPointRepository($this->tables), new MemoryPlaceRepository($this->tables), $regions);
        foreach ([0, 57, 196] as $i) {
            $exact = VectorCodec::flat($capture->capture('mini', (float) $cells[$i][1], (float) $cells[$i][2], ['normal'], null, self::VERSION)['vectors']['normal']);
            self::assertEqualsWithDelta((float) $cells[$i][3], array_sum(array_slice($exact, 32, 16)), 1e-9 * max(1.0, (float) $cells[$i][3]), 'nearby_etl of ' . $cells[$i][0]);
            foreach ($exact as $j => $v) {
                $bound = CellPackWriter::errorBound($v, (float) $header['scale'][$j]) * (1.0 + 1e-9);
                self::assertLessThanOrEqual($bound, abs($decoded['columns'][$j][$i] - $v), 'cell ' . $cells[$i][0] . ' column ' . $j);
            }
        }

        // ---- the order of the writes: region, ledger row, places, points, host vectors in one transaction, pack
        self::assertSame(
            ['upsertRegion mini', 'insertPackRow ' . self::VERSION, 'insertPlaces 420', 'insertPoints 363', 'begin', 'setHostVec x83', 'commit', 'finishPack ' . self::VERSION],
            $this->tables->summary()
        );
    }

    public function testTheHostVectorOfAVenueLeavesItsOwnSourcePointOut(): void
    {
        self::assertSame(0, $this->runLoader(['build' => self::fixture()]));
        $places = $this->tables->places['mini|' . self::VERSION];
        $points = $this->tables->points['mini|' . self::VERSION];
        $capture = new CaptureService(null, new MemoryPointRepository($this->tables), new MemoryPlaceRepository($this->tables), new MemoryRegionRepository($this->tables));

        $withPoint = null;
        $without = null;
        foreach ($places as $key => $place) {
            if (!isset($place['host_vec'])) {
                continue;
            }
            if ($place['visitor_segment'] !== null && $withPoint === null) {
                $withPoint = $key;
            }
            if ($place['visitor_segment'] === null && $without === null) {
                $without = $key;
            }
        }
        self::assertNotNull($withPoint);
        self::assertNotNull($without);
        self::assertArrayHasKey('p' . $withPoint, $points, 'a visitor source has a point row of its own');
        self::assertArrayNotHasKey('p' . $without, $points);

        // a venue: its own visitors are not in its vector (they come back through the host term)
        $place = $places[$withPoint];
        $stored = VectorCodec::fromBytes($place['host_vec']);
        $segment = array_search($place['visitor_segment'], Estimator::seed(Seeds::defaults(), 'vocabulary.segments'), true);
        $plain = VectorCodec::flat($capture->capture('mini', (float) $place['lat'], (float) $place['lng'], ['normal'], null, self::VERSION)['vectors']['normal']);
        self::assertEqualsWithDelta((float) $place['size_default'], $plain[32 + $segment] - $stored[32 + $segment], 1e-9, 'nearby without the venue is smaller by its size: it stands at distance 0');
        $host = ['segment' => $place['visitor_segment'], 'size' => (float) $place['size_default'], 'size_source' => 'default', 'only_food' => false,
            'point_id' => 'p' . $withPoint, 'place_type' => $place['place_type']];
        self::assertSame($stored, VectorCodec::flat($capture->capture('mini', (float) $place['lat'], (float) $place['lng'], ['normal'], $host, self::VERSION)['vectors']['normal']));

        // a host without a source point: the plain vector at its coordinates
        $place = $places[$without];
        self::assertSame(
            VectorCodec::fromBytes($place['host_vec']),
            VectorCodec::flat($capture->capture('mini', (float) $place['lat'], (float) $place['lng'], ['normal'], null, self::VERSION)['vectors']['normal'])
        );
    }

    public function testASecondLoadReplacesAVersionThatIsNotLive(): void
    {
        self::assertSame(0, $this->runLoader(['build' => self::fixture()]));
        $first = $this->pack();
        $this->tables->log = [];

        self::assertSame(0, $this->runLoader(['build' => self::fixture()]));
        self::assertStringContainsString('Replacing the rows of an earlier load of ' . self::VERSION . ' (ready).', $this->printed());
        self::assertSame(
            ['upsertRegion mini', 'delete tp_points 363', 'delete tp_places 420', 'delete tp_region_packs 1',
                'insertPackRow ' . self::VERSION, 'insertPlaces 420', 'insertPoints 363', 'begin', 'setHostVec x83', 'commit', 'finishPack ' . self::VERSION],
            $this->tables->summary(),
            'points, then places, then the ledger row, and then the load starts again'
        );
        self::assertCount(363, $this->tables->points['mini|' . self::VERSION]);
        self::assertCount(420, $this->tables->places['mini|' . self::VERSION]);
        self::assertSame($first['pack_gz'], $this->pack()['pack_gz'], 'the same build gives the same pack');
        self::assertSame('ready', $this->pack()['load_state']);
        self::assertNull($this->tables->regions['mini']['active_version']);
    }

    public function testActivateSwitchesAndThenPrunes(): void
    {
        $this->seedVersion('mini-20250101-aaaaaaaa');
        $this->seedVersion('mini-20260101-bbbbbbbb');
        $this->seedVersion('mini-20260401-cccccccc', 'failed');
        $this->tables->regions['mini']['active_version'] = 'mini-20260101-bbbbbbbb';
        $this->tables->regions['mini']['previous_version'] = 'mini-20250101-aaaaaaaa';

        self::assertSame(0, $this->runLoader(['build' => self::fixture(), 'activate' => true]));
        self::assertSame(self::VERSION, $this->tables->regions['mini']['active_version']);
        self::assertSame('mini-20260101-bbbbbbbb', $this->tables->regions['mini']['previous_version'], 'the version that was live is the way back');
        self::assertNotNull($this->pack()['activated_at']);
        self::assertSame(['mini|mini-20260101-bbbbbbbb', 'mini|' . self::VERSION], array_keys($this->tables->packs), 'every other version is pruned');
        self::assertArrayNotHasKey('mini|mini-20250101-aaaaaaaa', $this->tables->points);
        self::assertArrayNotHasKey('mini|mini-20260401-cccccccc', $this->tables->places);
        self::assertCount(2, $this->tables->points['mini|mini-20260101-bbbbbbbb'], 'the previous version keeps its rows');
        self::assertStringContainsString('state            ready and live (removed: mini-20250101-aaaaaaaa, mini-20260401-cccccccc)', $this->printed());
        self::assertStringNotContainsString('To make it live', $this->printed());
    }

    public function testTheLiveVersionIsNotLoadedAgain(): void
    {
        self::assertSame(0, $this->runLoader(['build' => self::fixture(), 'activate' => true]));
        $pack = $this->pack();
        $this->tables->log = [];

        foreach ([[], ['activate' => true]] as $more) {
            self::assertSame(0, $this->runLoader(['build' => self::fixture()] + $more));
            self::assertStringEndsWith(self::VERSION . ' is the live version of mini: nothing to load.', $this->printed());
        }
        self::assertSame(['upsertRegion mini', 'upsertRegion mini'], $this->tables->summary(), 'only the region row is brought up to date');
        self::assertSame($pack, $this->pack());
        self::assertSame(self::VERSION, $this->tables->regions['mini']['active_version']);
    }

    public function testAVersionLoadedWithOtherBuildScopeSeedsIsNotReloaded(): void
    {
        $regions = new MemoryRegionRepository($this->tables);
        $other = (new RegionService($regions))->kernelFromSeeds();
        $other['a0'] = 1.8;
        $this->seedVersion(self::VERSION, 'ready', $other);
        $before = $this->tables->packs;

        self::assertSame(2, $this->runLoader(['build' => self::fixture()]));
        self::assertSame('FAILED: build-scope seeds changed: raise seeds_revision and rebuild the region', $this->errors());
        self::assertSame(RegionLoader::SEEDS_CHANGED, 'build-scope seeds changed: raise seeds_revision and rebuild the region');
        self::assertSame($before, $this->tables->packs, 'the earlier load is left as it was');
        self::assertCount(2, $this->tables->points['mini|' . self::VERSION]);
        self::assertSame(['upsertRegion mini'], $this->tables->summary());
    }

    public function testAnEarlierLoadThatFailedOrNeverFinishedIsReplaced(): void
    {
        foreach (['failed', 'loading'] as $state) {
            $this->tables = new MemoryTables();
            $this->seedVersion(self::VERSION, $state);                  // no kernel recorded
            self::assertSame(0, $this->runLoader(['build' => self::fixture()]), $state);
            self::assertSame('ready', $this->pack()['load_state'], $state);
            self::assertCount(363, $this->tables->points['mini|' . self::VERSION], $state);
        }
    }

    // ------------------------------------------------------------------------------------ step 1 refuses

    public function testG19ABuildMadeWithOtherSeedValuesIsRefusedBeforeAnythingIsWritten(): void
    {
        $cases = [
            'G19.parameters' => [
                static function (array $m): array {
                    $m['parameters']['cns04_weight'] = 0.5;
                    return $m;
                },
                'differs in: cns04_weight: rebuild the region',
            ],
            'G19.parameters ' => [
                static function (array $m): array {
                    $m['parameters']['place_types']['taproom']['default_size'] = 41;
                    unset($m['parameters']['walk_cutoff_m']);
                    return $m;
                },
                'differs in: walk_cutoff_m, place_types: rebuild the region',
            ],
            'G19.model_version' => [
                static function (array $m): array {
                    $m['model_version'] = 'tps-0.2.0';
                    return $m;
                },
                'this server runs tps-0.1.0: rebuild the region',
            ],
            'G19.traffic_matrix' => [
                static function (array $m): array {
                    $m['region']['traffic_matrix'] = 'atl';
                    return $m;
                },
                'the seeds hold no traffic.atl or no traffic.atl_typical',
            ],
        ];
        foreach ($cases as $gate => [$change, $message]) {
            $gate = trim($gate);
            $this->tables = new MemoryTables();
            $dir = $this->copyOfBuild(['manifest.json' => self::manifestEdit($change)]);
            foreach ([['dry-run' => true], []] as $more) {
                self::assertSame(2, $this->runLoader(['build' => $dir] + $more), $gate);
                self::assertStringStartsWith('FAILED: ' . $gate . ': ', $this->errors());
                self::assertStringContainsString($message, $this->errors(), $gate);
                self::assertMatchesRegularExpression('/^FAIL\s+' . preg_quote($gate, '/') . '\s/m', $this->printed(), $gate);
                self::assertSame([], $this->tables->log, $gate . ': nothing is written, not even the region row');
            }
        }
    }

    public function testARegionWhoseTrafficMatrixIsInTheSeedsPasses(): void
    {
        $dir = $this->copyOfBuild(['manifest.json' => self::manifestEdit(static function (array $m): array {
            $m['region']['traffic_matrix'] = 'dc';
            return $m;
        })]);
        self::assertSame(0, $this->runLoader(['build' => $dir, 'dry-run' => true]));
        self::assertMatchesRegularExpression('/^ok\s+G19\.traffic_matrix\s+traffic matrix dc /m', $this->printed());
    }

    public function testFilesThatAreNotTheOnesThePipelineWroteAreRefused(): void
    {
        // one byte more, and the manifest still records the old file
        $dir = $this->copyOfBuild(['points.tsv' => static fn (string $text): string => $text . "\n"], false);
        self::assertSame(2, $this->runLoader(['build' => $dir]));
        self::assertStringStartsWith('FAILED: files: points.tsv', $this->errors());
        self::assertStringContainsString('the manifest records 363 rows, 51374 bytes', $this->errors());
        self::assertSame([], $this->tables->log);

        // the same size and the same number of rows, another content
        $dir = $this->copyOfBuild(
            ['cells.tsv' => static fn (string $text): string => substr($text, 0, -2) . ($text[strlen($text) - 2] === '9' ? '8' : '9') . "\n"],
            false
        );
        self::assertSame(2, $this->runLoader(['build' => $dir, 'dry-run' => true]));
        self::assertStringStartsWith('FAILED: files: cells.tsv', $this->errors());

        // a row less in the review list
        $dir = $this->copyOfBuild(['manifest.json' => self::manifestEdit(static function (array $m): array {
            $m['outputs'][3]['rows'] = 8;
            return $m;
        })]);
        self::assertSame(2, $this->runLoader(['build' => $dir, 'dry-run' => true]));
        self::assertStringStartsWith('FAILED: files: job_review.csv', $this->errors());
    }

    public function testAMissingFileOrDirectoryIsAnInputError(): void
    {
        $dir = $this->copyOfBuild();
        unlink($dir . '/cells.tsv');
        self::assertSame(1, $this->runLoader(['build' => $dir]));
        self::assertStringContainsString('cells.tsv is missing', $this->errors());

        self::assertSame(1, $this->runLoader(['build' => $dir . '/nowhere']));
        self::assertStringContainsString('no manifest.json in', $this->errors());

        file_put_contents($dir . '/manifest.json', 'not json');
        self::assertSame(1, $this->runLoader(['build' => $dir]));
        self::assertStringContainsString('manifest.json cannot be read as JSON', $this->errors());
        self::assertSame([], $this->tables->log);
    }

    public function testAManifestThatIsNotOneIsRefused(): void
    {
        foreach (['schema' => 2, 'dataset_version' => 'Mini 1', 'region_id' => 'other'] as $key => $value) {
            $dir = $this->copyOfBuild(['manifest.json' => self::manifestEdit(static function (array $m) use ($key, $value): array {
                $m[$key] = $value;
                return $m;
            })]);
            self::assertSame(2, $this->runLoader(['build' => $dir]), $key);
            self::assertStringContainsString('manifest.json is not a manifest of schema 1', $this->errors(), $key);
        }
    }

    public function testABuildWhoseFailLevelGateDidNotPassIsRefused(): void
    {
        $dir = $this->copyOfBuild(['manifest.json' => self::manifestEdit(static function (array $m): array {
            foreach ($m['gates'] as &$gate) {
                if ($gate['id'] === 'G8' && $gate['part'] === 'residents') {
                    $gate['pass'] = false;
                }
            }
            return $m;
        })]);
        self::assertSame(2, $this->runLoader(['build' => $dir]));
        self::assertStringStartsWith('FAILED: pipeline: ', $this->errors());
        self::assertStringContainsString('not passed: G8.residents', $this->errors());
        self::assertSame([], $this->tables->log);

        $dir = $this->copyOfBuild(['manifest.json' => self::manifestEdit(static function (array $m): array {
            $m['gates'] = [];
            return $m;
        })]);
        self::assertSame(2, $this->runLoader(['build' => $dir]));
        self::assertStringContainsString('the manifest records no gates', $this->errors());
    }

    public function testBuildFilesThatBreakTheirFormatAreRefused(): void
    {
        $swapFirstTwoRows = static function (string $text): string {
            $lines = explode("\n", $text);
            [$lines[1], $lines[2]] = [$lines[2], $lines[1]];
            return implode("\n", $lines);
        };
        $cases = [
            'points.tsv line 3: point ids must be valid and in ascending byte order' => ['points.tsv' => $swapFirstTwoRows],
            'cells.tsv line 3: cell ids must be 15 hexadecimal characters, ascending' => ['cells.tsv' => $swapFirstTwoRows],
            'places.ndjson is not sorted by place_key at line 2' => ['places.ndjson' => static function (string $text): string {
                $lines = explode("\n", $text);
                [$lines[0], $lines[1]] = [$lines[1], $lines[0]];
                return implode("\n", $lines);
            }],
            'points.tsv does not start with the header row' => ['points.tsv' => static fn (string $text): string => str_replace("\tb_res\t", "\tb_residents\t", $text)],
            'points.tsv line 2: lat is not a number' => ['points.tsv' => static fn (string $text): string => (string) preg_replace('/38\.8813384/', 'north', $text, 1)],
            'cells.tsv line 2 has 3 fields, not 4' => ['cells.tsv' => static fn (string $text): string => (string) preg_replace('/^(892\S+\t\S+\t\S+)\t\S+$/m', '$1', $text, 1)],
            'places.ndjson line 1 does not have the shape' => ['places.ndjson' => self::firstPlaceEdit(static function (array $place): array {
                $place['rival_kind'] = 'street';
                return $place;
            })],
            'places.ndjson line 1 has no hours_mask' => ['places.ndjson' => self::firstPlaceEdit(static function (array $place): array {
                unset($place['hours_mask']);
                return $place;
            })],
            'places.ndjson line 1 does not have the shape of 03_DATA.md 8.2' => ['places.ndjson' => self::firstPlaceEdit(static function (array $place): array {
                $place['name'] = ['not', 'text'];
                return $place;
            })],
            'points.tsv line 2 does not have the shape of 03_DATA.md 8.1' => ['points.tsv' => static fn (string $text): string => (string) preg_replace('/\tblock\t/', "\tplace\t", $text, 1)],
            'points.tsv line 2 does not have the shape of 03_DATA.md 8.1 ' => ['points.tsv' => static fn (string $text): string => (string) preg_replace('/\tblock\t(\d+)\t0\t/', "\tblock\t\$1\tno\t", $text, 1)],
        ];
        foreach ($cases as $message => $edits) {
            $message = trim($message);
            $this->tables = new MemoryTables();
            $dir = $this->copyOfBuild($edits);
            self::assertSame(2, $this->runLoader(['build' => $dir]), $message);
            self::assertStringContainsString($message, $this->errors());
            $state = $this->tables->packs['mini|' . self::VERSION]['load_state'] ?? null;
            self::assertSame('failed', $state, $message . ': the version stays failed');
            self::assertNull($this->tables->regions['mini']['active_version']);
        }
    }

    // ------------------------------------------------------------------------------------ the loader's own gates

    public function testG20AFailedPruningCrossCheckLeavesTheVersionFailedAndNeverActivatesIt(): void
    {
        // the pipeline's sum for the first cell is off by one part in a million
        $dir = $this->copyOfBuild(['cells.tsv' => static function (string $text): string {
            $lines = explode("\n", $text);
            $fields = explode("\t", $lines[1]);
            $fields[3] = json_encode((float) $fields[3] * 1.000001);
            $lines[1] = implode("\t", $fields);
            return implode("\n", $lines);
        }]);

        self::assertSame(2, $this->runLoader(['build' => $dir, 'dry-run' => true]));
        self::assertStringStartsWith('FAILED: G20: ', $this->errors());
        self::assertStringContainsString('the JavaScript and PHP distance or decay code disagree, for example at 892aa84c323ffff', $this->errors());
        self::assertSame([], $this->tables->log);

        self::assertSame(2, $this->runLoader(['build' => $dir, 'activate' => true]));
        self::assertMatchesRegularExpression('/^FAIL\s+G20\s/m', $this->printed());
        self::assertSame('failed', $this->pack()['load_state']);
        self::assertNull($this->pack()['pack_gz'] ?? null, 'no pack is stored');
        self::assertNull($this->tables->regions['mini']['active_version'], 'a failed version is never activated');
        self::assertSame('setState failed', $this->tables->summary()[count($this->tables->summary()) - 1]);

        // and it cannot be activated by hand either
        self::assertSame(2, $this->runLoader(['region' => 'mini', 'activate' => self::VERSION]));
        self::assertStringContainsString(self::VERSION . ' is failed, not ready', $this->errors());
        self::assertNull($this->tables->regions['mini']['active_version']);
    }

    public function testATinyDifferenceInThePruningSumIsWithinTheTolerance(): void
    {
        $dir = $this->copyOfBuild(['cells.tsv' => static function (string $text): string {
            $lines = explode("\n", $text);
            $fields = explode("\t", $lines[1]);
            $fields[3] = json_encode((float) $fields[3] * (1.0 + 5e-10));
            $lines[1] = implode("\t", $fields);
            return implode("\n", $lines);
        }]);
        self::assertSame(0, $this->runLoader(['build' => $dir, 'dry-run' => true]), $this->errors());
        self::assertMatchesRegularExpression('/^ok\s+G20\s.*largest relative difference [45]\.\d\de-10 \(limit 1e-9\)/m', $this->printed());
    }

    public function testG21ARequestPathThatDoesNotReproduceTheCellsFailsTheLoad(): void
    {
        // a request path that loses the nearest source point of every box
        $points = new class ($this->tables) extends MemoryPointRepository {
            public function near(string $regionId, string $version, float $lat, float $lng, float $radiusM): array
            {
                $rows = parent::near($regionId, $version, $lat, $lng, $radiusM);
                $nearest = null;
                foreach ($rows as $i => $row) {
                    $d = Estimator::haversineM($lat, $lng, $row[PointRepository::LAT], $row[PointRepository::LNG]);
                    if ($nearest === null || $d < $nearest[1]) {
                        $nearest = [$i, $d];
                    }
                }
                if ($nearest !== null) {
                    unset($rows[$nearest[0]]);
                }
                return array_values($rows);
            }
        };
        self::assertSame(2, $this->runLoader(['build' => self::fixture(), 'activate' => true], $this->loader($points)));
        self::assertStringStartsWith('FAILED: G21.cells: ', $this->errors());
        self::assertMatchesRegularExpression('/differs, for example at cell 892aa84c323ffff \w+ \(request \S+, bulk \S+\)/', $this->errors());
        self::assertMatchesRegularExpression('/^ok\s+G20\s/m', $this->printed(), 'the gates that passed are still printed');
        self::assertMatchesRegularExpression('/^FAIL\s+G21\.cells\s/m', $this->printed());
        self::assertSame('failed', $this->pack()['load_state']);
        self::assertNull($this->tables->regions['mini']['active_version']);
    }

    public function testG21AStoredHostVectorThatTheRequestPathDoesNotReproduceFailsTheLoad(): void
    {
        // the database hands a host vector back with one number changed in its last bits
        $places = new class ($this->tables) extends MemoryPlaceRepository {
            public function hostsNear(string $regionId, string $version, float $lat, float $lng, float $radiusM): array
            {
                $rows = parent::hostsNear($regionId, $version, $lat, $lng, $radiusM);
                foreach ($rows as &$row) {
                    if (is_string($row[PlaceRepository::HOST_VEC])) {
                        $values = VectorCodec::fromBytes($row[PlaceRepository::HOST_VEC]);
                        $values[32] = $values[32] * (1.0 + 1e-9) + 1e-9;
                        $row[PlaceRepository::HOST_VEC] = pack('e50', ...$values);
                    }
                }
                return $rows;
            }
        };
        self::assertSame(2, $this->runLoader(['build' => self::fixture()], $this->loader(null, $places)));
        self::assertStringStartsWith('FAILED: G21.hosts: ', $this->errors());
        self::assertMatchesRegularExpression('/differs, for example at host \w+ n_res \(request \S+, stored \S+\)/', $this->errors());
        self::assertMatchesRegularExpression('/^ok\s+G21\.cells\s/m', $this->printed());
        self::assertSame('failed', $this->pack()['load_state']);
    }

    public function testADatabaseErrorIsAnInputOutputErrorAndLeavesTheVersionFailed(): void
    {
        $writes = new class ($this->tables) extends MemoryLoadRepository {
            public function insertPoints(string $regionId, string $version, array $rows): void
            {
                throw new \PDOException('SQLSTATE[HY000]: the server has gone away');
            }
        };
        $regions = new MemoryRegionRepository($this->tables);
        $loader = new RegionLoader($writes, $regions, new RegionService($regions), null, null, function (string $l): void {
            $this->out[] = $l;
        }, function (string $l): void {
            $this->err[] = $l;
        });
        self::assertSame(1, $loader->run(['build' => self::fixture()]));
        self::assertStringStartsWith('ERROR: PDOException: ', $this->errors());
        self::assertSame('failed', $this->pack()['load_state']);
    }

    // ------------------------------------------------------------------------------------ --region

    public function testActivateMakesAReadyVersionLiveAndIsTheWayBack(): void
    {
        $this->seedVersion('mini-20260101-bbbbbbbb');
        $this->seedVersion('mini-20260701-dddddddd');

        self::assertSame(0, $this->runLoader(['region' => 'mini', 'activate' => 'mini-20260101-bbbbbbbb']));
        self::assertSame('mini-20260101-bbbbbbbb is now the live version of mini.', $this->printed());
        self::assertSame(0, $this->runLoader(['region' => 'mini', 'activate' => 'mini-20260701-dddddddd']));
        self::assertSame('mini-20260701-dddddddd is now the live version of mini. To go back: --region=mini --activate=mini-20260101-bbbbbbbb', $this->printed());
        self::assertSame('mini-20260701-dddddddd', $this->tables->regions['mini']['active_version']);
        self::assertSame('mini-20260101-bbbbbbbb', $this->tables->regions['mini']['previous_version']);
        self::assertCount(2, $this->tables->packs, 'activating by hand prunes nothing');

        // the way back is the same command with the previous version
        self::assertSame(0, $this->runLoader(['region' => 'mini', 'activate' => 'mini-20260101-bbbbbbbb']));
        self::assertSame('mini-20260101-bbbbbbbb', $this->tables->regions['mini']['active_version']);
        self::assertSame('mini-20260701-dddddddd', $this->tables->regions['mini']['previous_version']);

        // activating the live version changes nothing
        self::assertSame(0, $this->runLoader(['region' => 'mini', 'activate' => 'mini-20260101-bbbbbbbb']));
        self::assertSame('mini-20260101-bbbbbbbb is already the live version of mini.', $this->printed());
        self::assertSame('mini-20260701-dddddddd', $this->tables->regions['mini']['previous_version']);
    }

    public function testActivateRefusesWhatIsNotAReadyVersionOfTheRegion(): void
    {
        $this->seedVersion('mini-20260101-bbbbbbbb', 'loading');
        self::assertSame(2, $this->runLoader(['region' => 'mini', 'activate' => 'mini-20260101-bbbbbbbb']));
        self::assertSame('FAILED: mini-20260101-bbbbbbbb is loading, not ready: load it again before activating it', $this->errors());
        self::assertSame(1, $this->runLoader(['region' => 'mini', 'activate' => 'mini-20990101-ffffffff']));
        self::assertSame('load-region: mini has no dataset version mini-20990101-ffffffff (see --list)', $this->errors());
        self::assertSame(1, $this->runLoader(['region' => 'zz', 'activate' => 'zz-20260101-bbbbbbbb']));
        self::assertSame('load-region: unknown region: zz', $this->errors());
        self::assertNull($this->tables->regions['mini']['active_version']);
    }

    public function testActivatingAVersionBuiltWithOtherConstantsWarns(): void
    {
        $regions = new MemoryRegionRepository($this->tables);
        $other = (new RegionService($regions))->kernelFromSeeds();
        $other['walk_decay_m'] = 450.0;
        $this->seedVersion('mini-20260101-bbbbbbbb', 'ready', $other);
        self::assertSame(0, $this->runLoader(['region' => 'mini', 'activate' => 'mini-20260101-bbbbbbbb']));
        self::assertStringContainsString('WARNING: mini-20260101-bbbbbbbb was built with other model constants than this server has.', $this->printed());
        self::assertStringContainsString('409 "Region data was built with different model constants"', $this->printed());
        self::assertSame('mini-20260101-bbbbbbbb', $this->tables->regions['mini']['active_version'], 'a way back may come before the code goes back');
    }

    public function testListShowsTheLedger(): void
    {
        $this->seedVersion('mini-20250101-aaaaaaaa');
        $this->seedVersion('mini-20260101-bbbbbbbb');
        $this->seedVersion('mini-20260401-cccccccc', 'failed');
        $this->tables->regions['mini']['active_version'] = 'mini-20260101-bbbbbbbb';
        $this->tables->regions['mini']['previous_version'] = 'mini-20250101-aaaaaaaa';
        $this->tables->packs['mini|mini-20260101-bbbbbbbb']['activated_at'] = '2026-01-02 08:00:00';

        self::assertSame(0, $this->runLoader(['region' => 'mini', 'list' => true]));
        self::assertSame('Region mini (Falls Church test region): 3 dataset versions', $this->out[0]);
        self::assertMatchesRegularExpression('/^  mini-20250101-aaaaaaaa\s+previous\s+ready\s+points 2, places 1, cells 1, pack 10 bytes \(5 gzipped\), loaded 2026-01-01 00:00:00$/', $this->out[1]);
        self::assertMatchesRegularExpression('/^  mini-20260101-bbbbbbbb\s+live\s+ready\s+.*, activated 2026-01-02 08:00:00$/', $this->out[2]);
        self::assertMatchesRegularExpression('/^  mini-20260401-cccccccc\s+-\s+failed\s+/', $this->out[3]);
        self::assertSame([], $this->tables->log, 'listing writes nothing');

        self::assertSame(1, $this->runLoader(['region' => 'zz', 'list' => true]));
        self::assertSame('load-region: unknown region: zz', $this->errors());
    }

    public function testPruneKeepsTheLiveAndThePreviousVersion(): void
    {
        $this->seedVersion('mini-20250101-aaaaaaaa');
        $this->seedVersion('mini-20260101-bbbbbbbb');
        $this->seedVersion('mini-20260401-cccccccc', 'failed');
        $this->seedVersion('mini-20260701-dddddddd');
        $this->tables->regions['mini']['active_version'] = 'mini-20260701-dddddddd';
        $this->tables->regions['mini']['previous_version'] = 'mini-20260101-bbbbbbbb';

        self::assertSame(0, $this->runLoader(['region' => 'mini', 'prune' => true]));
        self::assertSame('Removed from mini: mini-20250101-aaaaaaaa, mini-20260401-cccccccc', $this->printed());
        self::assertSame(['mini|mini-20260101-bbbbbbbb', 'mini|mini-20260701-dddddddd'], array_keys($this->tables->packs));
        self::assertSame(['mini|mini-20260101-bbbbbbbb', 'mini|mini-20260701-dddddddd'], array_keys($this->tables->points));
        self::assertSame(['mini|mini-20260101-bbbbbbbb', 'mini|mini-20260701-dddddddd'], array_keys($this->tables->places));
        self::assertSame(
            ['delete tp_points 2', 'delete tp_places 1', 'delete tp_region_packs 1', 'delete tp_points 2', 'delete tp_places 1', 'delete tp_region_packs 1'],
            $this->tables->summary(),
            'per version: points, places, then the ledger row'
        );

        self::assertSame(0, $this->runLoader(['region' => 'mini', 'prune' => true]));
        self::assertSame('Nothing to prune for mini.', $this->printed());
    }

    // ------------------------------------------------------------------------------------ the command forms

    public function testAnythingButTheFourCommandFormsIsAUsageError(): void
    {
        $cases = [
            [],
            ['dry-run' => true],
            ['activate' => true],
            ['list' => true],
            ['build' => ''],
            ['build' => self::fixture(), 'region' => 'mini'],
            ['build' => self::fixture(), 'list' => true],
            ['build' => self::fixture(), 'prune' => true],
            ['build' => self::fixture(), 'activate' => self::VERSION],
            ['region' => 'mini'],
            ['region' => 'mini', 'activate' => true],
            ['region' => 'mini', 'list' => true, 'prune' => true],
            ['region' => 'mini', 'list' => true, 'activate' => self::VERSION],
            ['region' => 'mini', 'list' => true, 'dry-run' => true],
            ['region' => 'Mini', 'list' => true],
            ['region' => 'mini; DROP', 'prune' => true],
        ];
        foreach ($cases as $i => $opts) {
            self::assertSame(1, $this->runLoader($opts), 'case ' . $i);
            self::assertStringStartsWith('load-region: ', $this->errors(), 'case ' . $i);
            self::assertSame([], $this->tables->log, 'case ' . $i);
        }
    }

    public function testTheLoaderKeepsNothingOfOneRunForTheNext(): void
    {
        $loader = $this->loader();
        self::assertSame(0, $this->runLoader(['build' => self::fixture(), 'dry-run' => true], $loader));
        $first = $this->printed();
        self::assertSame(0, $this->runLoader(['build' => self::fixture(), 'dry-run' => true], $loader));
        $strip = static fn (string $text): string => (string) preg_replace('/^(timings|wall clock).*$/m', '', $text);
        self::assertSame($strip($first), $strip($this->printed()));
        foreach ((new \ReflectionObject($loader))->getProperties() as $property) {
            $value = $property->getValue($loader);
            if (is_array($value) && !in_array($property->getName(), ['A', 'laps', 'gates'], true)) {
                self::assertSame([], $value, $property->getName() . ' is released after a run');
            }
        }
    }
}

// =========================================================================================================
// The tables as arrays, and the repositories over them
// =========================================================================================================

/**
 * tp_regions, tp_region_packs, tp_points and tp_places of a test, and a log of what was written.
 */
final class MemoryTables
{
    /** @var array<string, array<string, mixed>> region_id => row */
    public array $regions = [];
    /** @var array<string, array<string, mixed>> "region|version" => ledger row */
    public array $packs = [];
    /** @var array<string, array<string, array<string, mixed>>> "region|version" => point_id => row */
    public array $points = [];
    /** @var array<string, array<string, array<string, mixed>>> "region|version" => place_key => row */
    public array $places = [];
    /** @var list<string> every write, in order */
    public array $log = [];

    /**
     * The log with runs of the same host-vector write folded into one entry.
     *
     * @return list<string>
     */
    public function summary(): array
    {
        $out = [];
        $vectors = 0;
        foreach ($this->log as $entry) {
            if ($entry === 'setHostVec') {
                $vectors++;
                continue;
            }
            if ($vectors > 0) {
                $out[] = 'setHostVec x' . $vectors;
                $vectors = 0;
            }
            $out[] = $entry;
        }
        if ($vectors > 0) {
            $out[] = 'setHostVec x' . $vectors;
        }
        return $out;
    }
}

class MemoryLoadRepository extends RegionLoadRepository
{
    public function __construct(protected MemoryTables $tables)
    {
        parent::__construct(new RecordingDatabase());
    }

    public function upsertRegion(array $region, array $bounds, ?string $configJson = null): void
    {
        $id = (string) $region['id'];
        $existing = $this->tables->regions[$id] ?? [];
        $this->tables->regions[$id] = [
            'region_id' => $id, 'name' => (string) $region['name'], 'cbsa' => $region['cbsa'] ?? null,
            'timezone' => (string) $region['timezone'], 'h3_res' => (int) $region['h3_res'],
            'bbox_lat_min' => (float) $bounds['lat_min'], 'bbox_lng_min' => (float) $bounds['lng_min'],
            'bbox_lat_max' => (float) $bounds['lat_max'], 'bbox_lng_max' => (float) $bounds['lng_max'],
            'center_lat' => (float) $region['map_center']['lat'], 'center_lng' => (float) $region['map_center']['lng'],
            'active_version' => $existing['active_version'] ?? null, 'previous_version' => $existing['previous_version'] ?? null,
            'config' => json_decode($configJson ?? (string) json_encode($region), true),
            'created_at' => '2026-10-05 04:00:00', 'updated_at' => '2026-10-05 04:00:00',
        ];
        $this->tables->log[] = 'upsertRegion ' . $id;
    }

    public function packRowState(string $regionId, string $version): ?string
    {
        return $this->tables->packs[$regionId . '|' . $version]['load_state'] ?? null;
    }

    public function insertPackRow(string $regionId, string $version, array $meta): void
    {
        if (isset($this->tables->packs[$regionId . '|' . $version])) {
            throw new \PDOException('Duplicate entry for key tp_region_packs.PRIMARY');
        }
        $this->tables->packs[$regionId . '|' . $version] = [
            'region_id' => $regionId, 'dataset_version' => $version, 'load_state' => 'loading',
            'model_version' => $meta['model_version'], 'pipeline_version' => $meta['pipeline_version'], 'osm_snapshot' => $meta['osm_snapshot'] ?? null,
            'point_count' => 0, 'place_count' => 0, 'cell_count' => 0, 'pack_format' => 1, 'pack_len' => 0, 'pack_gz_len' => 0,
            'pack_sha256' => null, 'pack_gz' => null, 'kernel' => null, 'manifest' => null,
            'loaded_at' => '2026-10-05 04:00:00', 'activated_at' => null,
        ];
        $this->tables->log[] = 'insertPackRow ' . $version;
    }

    public function deleteVersionBatch(string $table, string $regionId, string $version, int $limit = 5000): int
    {
        $key = $regionId . '|' . $version;
        $deleted = 0;
        if ($table === 'tp_region_packs') {
            $deleted = isset($this->tables->packs[$key]) ? 1 : 0;
            unset($this->tables->packs[$key]);
        } elseif ($table === 'tp_points') {
            $rows = $this->tables->points[$key] ?? [];
            $deleted = min($limit, count($rows));
            $this->tables->points[$key] = array_slice($rows, $deleted, null, true);
            if ($this->tables->points[$key] === []) {
                unset($this->tables->points[$key]);
            }
        } else {
            $rows = $this->tables->places[$key] ?? [];
            $deleted = min($limit, count($rows));
            $this->tables->places[$key] = array_slice($rows, $deleted, null, true);
            if ($this->tables->places[$key] === []) {
                unset($this->tables->places[$key]);
            }
        }
        if ($deleted > 0) {
            $this->tables->log[] = 'delete ' . $table . ' ' . $deleted;
        }
        return $deleted;
    }

    public function insertPlaces(string $regionId, string $version, array $rows): void
    {
        foreach ($rows as $row) {
            if (isset($this->tables->places[$regionId . '|' . $version][$row['place_key']])) {
                throw new \PDOException('Duplicate entry for key tp_places.PRIMARY');
            }
            $this->tables->places[$regionId . '|' . $version][$row['place_key']] = $row;
        }
        $this->tables->log[] = 'insertPlaces ' . count($rows);
    }

    public function insertPoints(string $regionId, string $version, array $rows): void
    {
        foreach ($rows as $row) {
            if (isset($this->tables->points[$regionId . '|' . $version][$row['point_id']])) {
                throw new \PDOException('Duplicate entry for key tp_points.PRIMARY');
            }
            // what MySQL does with the bound texts: DOUBLE and TINYINT columns
            $this->tables->points[$regionId . '|' . $version][$row['point_id']] = [
                'point_id' => $row['point_id'], 'src_kind' => $row['src_kind'], 'src_ref' => $row['src_ref'],
                'in_region' => (int) $row['in_region'], 'job_adj' => (int) $row['job_adj'],
                'lat' => (float) $row['lat'], 'lng' => (float) $row['lng'], 'base' => array_map('floatval', $row['base']),
                'rivals_day' => (float) $row['rivals_day'], 'rivals_eve' => (float) $row['rivals_eve'],
            ];
        }
        $this->tables->log[] = 'insertPoints ' . count($rows);
    }

    public function setHostVec(string $regionId, string $version, string $placeKey, string $bytes): void
    {
        if (!isset($this->tables->places[$regionId . '|' . $version][$placeKey]) || strlen($bytes) !== 400) {
            throw new \LogicException('setHostVec: no such place, or not 400 bytes');
        }
        $this->tables->places[$regionId . '|' . $version][$placeKey]['host_vec'] = $bytes;
        $this->tables->log[] = 'setHostVec';
    }

    public function transaction(callable $work): void
    {
        $this->tables->log[] = 'begin';
        $work();
        $this->tables->log[] = 'commit';
    }

    public function finishPack(string $regionId, string $version, array $counts, string $packGz, array $kernel, string $manifestJson): void
    {
        $row = &$this->tables->packs[$regionId . '|' . $version];
        $row['point_count'] = $counts['points'];
        $row['place_count'] = $counts['places'];
        $row['cell_count'] = $counts['cells'];
        $row['pack_format'] = $counts['pack_format'];
        $row['pack_len'] = $counts['pack_len'];
        $row['pack_gz_len'] = strlen($packGz);
        $row['pack_sha256'] = $counts['pack_sha256'];
        $row['pack_gz'] = $packGz;
        $row['kernel'] = $kernel;
        $row['manifest'] = $manifestJson;
        $row['load_state'] = 'ready';
        $this->tables->log[] = 'finishPack ' . $version;
    }

    public function setState(string $regionId, string $version, string $state): void
    {
        $this->tables->packs[$regionId . '|' . $version]['load_state'] = $state;
        $this->tables->log[] = 'setState ' . $state;
    }

    public function activate(string $regionId, string $version): void
    {
        $state = $this->packRowState($regionId, $version);
        if (!isset($this->tables->regions[$regionId]) || $state !== 'ready') {
            throw new \DomainException('dataset version ' . $version . ' is ' . ($state ?? 'unknown') . ', not ready');
        }
        if ($this->tables->regions[$regionId]['active_version'] !== $version) {
            $this->tables->regions[$regionId]['previous_version'] = $this->tables->regions[$regionId]['active_version'];
            $this->tables->regions[$regionId]['active_version'] = $version;
            $this->tables->packs[$regionId . '|' . $version]['activated_at'] = '2026-10-05 04:30:00';
        }
    }

    public function versions(string $regionId): array
    {
        $out = [];
        foreach ($this->tables->packs as $row) {
            if ($row['region_id'] === $regionId) {
                $out[] = array_intersect_key($row, array_flip(['dataset_version', 'load_state', 'model_version', 'pipeline_version', 'osm_snapshot',
                    'point_count', 'place_count', 'cell_count', 'pack_len', 'pack_gz_len', 'loaded_at', 'activated_at']));
            }
        }
        return $out;
    }
}

class MemoryRegionRepository extends RegionRepository
{
    public function __construct(private MemoryTables $tables)
    {
        parent::__construct(new RecordingDatabase());
    }

    public function all(): array
    {
        return array_values($this->tables->regions);
    }

    public function find(string $regionId): ?array
    {
        return $this->tables->regions[$regionId] ?? null;
    }

    public function packMeta(string $regionId, string $version): ?array
    {
        $row = $this->tables->packs[$regionId . '|' . $version] ?? null;
        if ($row === null) {
            return null;
        }
        $manifest = is_string($row['manifest']) ? json_decode($row['manifest'], true) : null;
        $meta = array_diff_key($row, ['pack_gz' => 1, 'manifest' => 1, 'kernel' => 1]);
        $meta['kernel'] = $row['kernel']['kernel'] ?? null;
        $meta['kernel_seeds_revision'] = $row['kernel']['seeds_revision'] ?? null;
        $meta['vintages'] = $manifest['vintages'] ?? null;
        return $meta;
    }

    public function packBlob(string $regionId, string $version): ?string
    {
        return $this->tables->packs[$regionId . '|' . $version]['pack_gz'] ?? null;
    }

    public function manifest(string $regionId, string $version): ?array
    {
        $text = $this->tables->packs[$regionId . '|' . $version]['manifest'] ?? null;
        return is_string($text) ? json_decode($text, true) : null;
    }
}

/**
 * Q1 and Q4 over the array: the rows of the version in the box, in byte order of their ids.
 */
class MemoryPointRepository extends PointRepository
{
    public function __construct(private MemoryTables $tables)
    {
        parent::__construct(new RecordingDatabase());
    }

    public function near(string $regionId, string $version, float $lat, float $lng, float $radiusM): array
    {
        $out = [];
        foreach ($this->inBox($regionId, $version, self::box($lat, $lng, $radiusM)) as $row) {
            $out[] = array_merge(
                [$row['point_id'], $row['src_kind'], $row['src_ref'], $row['lat'], $row['lng'], $row['rivals_day'], $row['rivals_eve']],
                $row['base']
            );
        }
        return $out;
    }

    public function nearestBlock(string $regionId, string $version, float $lat, float $lng): array
    {
        $out = [];
        foreach ($this->inBox($regionId, $version, self::box($lat, $lng, 2400.0)) as $row) {
            if ($row['src_kind'] === 'block') {
                $out[] = [$row['point_id'], $row['src_ref'], $row['in_region'], $row['lat'], $row['lng']];
            }
        }
        return $out;
    }

    /**
     * @param array{lat_min: float, lat_max: float, lng_min: float, lng_max: float} $box
     * @return list<array<string, mixed>>
     */
    private function inBox(string $regionId, string $version, array $box): array
    {
        $rows = [];
        foreach ($this->tables->points[$regionId . '|' . $version] ?? [] as $row) {
            if ($row['lat'] >= $box['lat_min'] && $row['lat'] <= $box['lat_max'] && $row['lng'] >= $box['lng_min'] && $row['lng'] <= $box['lng_max']) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['point_id'], $b['point_id']));
        return $rows;
    }
}

/**
 * Q2 and Q3 over the array.
 */
class MemoryPlaceRepository extends PlaceRepository
{
    public function __construct(private MemoryTables $tables)
    {
        parent::__construct(new RecordingDatabase());
    }

    public function rivalsNear(string $regionId, string $version, float $lat, float $lng, float $radiusM): array
    {
        $out = [];
        foreach ($this->inBox($regionId, $version, PointRepository::box($lat, $lng, $radiusM)) as $row) {
            if ($row['rival_kind'] !== null) {
                $out[] = [$row['place_key'], $row['place_type'], $row['rival_kind'], (float) $row['lat'], (float) $row['lng'], $row['name'], $row['kitchen'], $row['hours_mask']];
            }
        }
        return $out;
    }

    public function hostsNear(string $regionId, string $version, float $lat, float $lng, float $radiusM): array
    {
        $out = [];
        foreach ($this->inBox($regionId, $version, PointRepository::box($lat, $lng, $radiusM)) as $row) {
            if ($row['in_region'] === 1 && $row['host_fit'] > 0) {
                $out[] = [$row['place_key'], $row['place_type'], $row['name'], $row['brand'], (float) $row['lat'], (float) $row['lng'], $row['county_fips'],
                    (float) $row['host_fit'], $row['kitchen'], (float) $row['size_default'], $row['visitor_segment'], $row['phone'], $row['website'],
                    $row['addr_line'], $row['city'], $row['state_code'], $row['postcode'], $row['opening_hours_raw'], $row['hours_mask'], $row['host_vec'] ?? null];
            }
        }
        return array_slice($out, 0, self::pageRows());
    }

    /**
     * @param array{lat_min: float, lat_max: float, lng_min: float, lng_max: float} $box
     * @return list<array<string, mixed>>
     */
    private function inBox(string $regionId, string $version, array $box): array
    {
        $rows = [];
        foreach ($this->tables->places[$regionId . '|' . $version] ?? [] as $row) {
            if ($row['lat'] >= $box['lat_min'] && $row['lat'] <= $box['lat_max'] && $row['lng'] >= $box['lng_min'] && $row['lng'] <= $box['lng_max']) {
                $rows[] = $row;
            }
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($a['place_key'], $b['place_key']));
        return $rows;
    }
}
