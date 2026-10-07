<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\PointRepository;
use App\TruckPlanner\Data\RegionLoadRepository;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\VectorCodec;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\CaptureProvider;
use App\TruckPlanner\Services\Support\JsonSafe;

/**
 * The region loader: turns a build of the geodata pipeline into the rows and the cell pack the application
 * reads (03_DATA.md section 10, driven by scripts/truck/load-region.php).
 *
 *     --build=<dir> [--activate] [--dry-run]     load a build (steps 1 to 13)
 *     --region=<id> --activate=<version>         make a loaded version the live one (or go back to one)
 *     --region=<id> --list                       the loaded versions of a region
 *     --region=<id> --prune                      delete every version but the live and the previous one
 *
 * A load computes, with the model functions the API uses, the rival pull at every source point, the 50
 * numbers of every map cell and the location vector of every possible host. It then proves its own work:
 *
 *     G19  the build was made with this server's model version and build-scope seeds
 *     G20  the pipeline (JavaScript) and the model (PHP) agree on every cell's distance-weighted people
 *     G21  the request path (the capture service over the rows now in MySQL) reproduces sampled cells and
 *          sampled host vectors
 *     G22  the pack decodes within its quantisation bound and is not too large
 *
 * A failed check ends the load with exit code 2, leaves the version in state `failed` and never activates
 * it. A load only writes rows of its own dataset version; the version that is live is not touched until
 * the switch, which is one statement.
 *
 * Memory: the build files are read into one flat array per column, never into an array of rows. The model
 * is handed small lists built for one call from the 3 by 3 grid buckets around a point.
 */
class RegionLoader
{
    public const EXIT_OK = 0;
    public const EXIT_USAGE = 1;
    public const EXIT_FAILED = 2;

    public const SEEDS_CHANGED = 'build-scope seeds changed: raise seeds_revision and rebuild the region';

    private const DATA_FILES = ['points.tsv', 'places.ndjson', 'cells.tsv', 'job_review.csv'];

    private const POINT_COLUMNS = [
        'point_id', 'src_kind', 'src_ref', 'in_region', 'job_adj', 'lat', 'lng',
        'b_res', 'b_w_office', 'b_w_health', 'b_w_edu', 'b_w_retail', 'b_w_industrial', 'b_w_hospitality', 'b_w_public',
        'b_v_nightlife', 'b_v_shopping', 'b_v_leisure', 'b_v_campus', 'b_v_hospital', 'b_v_transit', 'b_v_events', 'b_v_lodging',
    ];
    private const CELL_COLUMNS = ['h3', 'lat', 'lng', 'nearby_etl'];
    private const PLACE_KEYS = [
        'place_key', 'osm_type', 'osm_id', 'place_type', 'geom_kind', 'in_region', 'county_fips', 'name', 'brand', 'lat', 'lng',
        'rival_kind', 'visitor_segment', 'size_default', 'host_fit', 'kitchen', 'phone', 'website', 'addr_line', 'city',
        'state_code', 'postcode', 'cuisine', 'opening_hours_raw', 'hours_mask', 'tags',
    ];
    /** The fields of a place that hold text or null. */
    private const PLACE_TEXT_KEYS = [
        'county_fips', 'name', 'brand', 'phone', 'website', 'addr_line', 'city', 'state_code', 'postcode', 'cuisine',
        'opening_hours_raw', 'hours_mask',
    ];

    private const SEGMENTS = 16;
    private const VECTOR_COLUMNS = 50;

    /** Rows handed to one insert call: ten statements of 500 rows, one transaction. */
    private const ROWS_PER_TRANSACTION = 5000;
    private const HOST_VECTORS_PER_TRANSACTION = 500;
    private const DELETE_BATCH = 5000;

    private const PACK_MAX_GZ_BYTES = 16777216;

    /** Grid buckets are numbered row * SPAN + column; a column number stays far below half of it. */
    private const BUCKET_SPAN = 1048576;

    /** Lists built for the model are kept while the next points fall into the same buckets. */
    private const MAX_CACHED_SOURCES = 20000;
    private const MAX_CACHED_NEIGHBOURHOODS = 96;

    private const SAMPLE_CELLS = 200;
    private const SAMPLE_TOP_CELLS = 20;
    private const SAMPLE_HOSTS = 50;
    private const HOST_READ_BACK_RADIUS_M = 25.0;

    private const NO_EXCLUSION = ['point_ids' => [], 'segment' => null, 'amount' => 0.0];

    private ?RegionLoadRepository $writes;
    private ?RegionRepository $regions;
    private ?RegionService $regionService;
    private ?CaptureProvider $capture;
    private ?PlaceRepository $places;

    /** @var callable(string): void */
    private $out;
    /** @var callable(string): void */
    private $err;

    // ---- the build in memory: one flat array per column

    /** @var list<string> */
    private array $pointId = [];
    /** @var list<float> */
    private array $pointLat = [];
    /** @var list<float> */
    private array $pointLng = [];
    /** @var list<list<float>> 16 columns */
    private array $pointBase = [];
    /** @var list<float> */
    private array $pointRivalsDay = [];
    /** @var list<float> */
    private array $pointRivalsEve = [];

    /** @var list<string> */
    private array $rivalKey = [];
    /** @var list<float> */
    private array $rivalLat = [];
    /** @var list<float> */
    private array $rivalLng = [];
    /** @var list<string> */
    private array $rivalKind = [];

    /** @var list<string> */
    private array $hostKey = [];
    /** @var list<float> */
    private array $hostLat = [];
    /** @var list<float> */
    private array $hostLng = [];
    /** @var list<string> */
    private array $hostType = [];
    /** @var list<?string> the visitor segment of a host that has a source point of its own, else null */
    private array $hostSegment = [];
    /** @var list<float> */
    private array $hostSize = [];

    /** @var list<string> */
    private array $cellId = [];
    /** @var list<float> */
    private array $cellLat = [];
    /** @var list<float> */
    private array $cellLng = [];
    /** @var list<float> */
    private array $cellNearbyEtl = [];
    /** @var list<list<float>> 50 columns in pack order */
    private array $cellColumns = [];
    /** @var list<float> */
    private array $cellNearbySum = [];

    // ---- the grid

    private float $gridLat = 1.0;
    private float $gridLng = 1.0;
    /** @var array<int, list<int>> bucket => indexes of source points */
    private array $pointBuckets = [];
    /** @var array<int, list<int>> bucket => indexes of rivals */
    private array $rivalBuckets = [];

    /** @var array<int, array<string, mixed>> point index => SourcePoint */
    private array $sourceCache = [];
    /** @var array<int, list<array<string, mixed>>> bucket => SourcePoint list of its neighbourhood */
    private array $sourceLists = [];
    /** @var array<int, list<array<string, mixed>>> bucket => Outlet list of its neighbourhood */
    private array $outletLists = [];

    // ---- the run

    /** @var array<string, mixed> */
    private array $A = [];
    /** @var list<array{id: string, pass: bool, check: string, detail: string}> */
    private array $gates = [];
    /** @var list<array{0: string, 1: float}> */
    private array $laps = [];
    private int $lapStart = 0;
    private int $placeCount = 0;
    private int $rivalCount = 0;
    /** @var array{0: string, 1: string}|null region and version whose ledger row this run opened */
    private ?array $opened = null;

    /**
     * @param callable(string): void|null $out where progress and results go (default: standard output)
     * @param callable(string): void|null $err where problems go (default: the error stream)
     */
    public function __construct(
        ?RegionLoadRepository $writes = null,
        ?RegionRepository $regions = null,
        ?RegionService $regionService = null,
        ?CaptureProvider $capture = null,
        ?PlaceRepository $places = null,
        ?callable $out = null,
        ?callable $err = null
    ) {
        $this->writes = $writes;
        $this->regions = $regions;
        $this->regionService = $regionService;
        $this->capture = $capture;
        $this->places = $places;
        $this->out = $out ?? static function (string $line): void {
            echo $line, "\n";
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(\STDERR, $line . "\n");
        };
    }

    /**
     * Runs one of the four command forms.
     *
     * @param array<string, string|bool> $opts the options as tp_args() returns them: `build` (a directory),
     *        `activate` (true, or a dataset version together with `region`), `dry-run`, `region`, `list`, `prune`
     * @return int 0 success, 1 usage or input/output error, 2 a failed check
     */
    public function run(array $opts): int
    {
        JsonSafe::shortestFloats();
        $this->opened = null;
        try {
            return $this->dispatch($opts);
        } catch (\DomainException $e) {
            $this->markFailed();
            $this->printGates();
            ($this->err)('FAILED: ' . $e->getMessage());
            return self::EXIT_FAILED;
        } catch (\Throwable $e) {
            $this->markFailed();
            ($this->err)('ERROR: ' . get_class($e) . ': ' . $e->getMessage());
            return self::EXIT_USAGE;
        } finally {
            $this->release();
        }
    }

    /**
     * @param array<string, string|bool> $opts
     */
    private function dispatch(array $opts): int
    {
        $build = $opts['build'] ?? null;
        $region = $opts['region'] ?? null;
        $activate = $opts['activate'] ?? null;
        $dryRun = isset($opts['dry-run']);
        $list = isset($opts['list']);
        $prune = isset($opts['prune']);

        if (is_string($build) && $build !== '') {
            if ($region !== null || $list || $prune || is_string($activate)) {
                return $this->usage('--build goes with --activate and --dry-run only');
            }
            return $this->load($build, $activate === true, $dryRun);
        }
        if (!is_string($region) || $region === '' || $dryRun) {
            return $this->usage('give --build=<dir>, or --region=<id> with --activate=<version>, --list or --prune');
        }
        if (preg_match('/^[a-z0-9]{1,24}$/', $region) !== 1) {
            return $this->usage('a region id is 1 to 24 lower-case letters and digits');
        }
        $forms = (int) is_string($activate) + (int) $list + (int) $prune;
        if ($forms !== 1 || $activate === true) {
            return $this->usage('--region goes with exactly one of --activate=<version>, --list and --prune');
        }
        if (is_string($activate)) {
            return $this->activateVersion($region, $activate);
        }
        return $list ? $this->listVersions($region) : $this->pruneRegion($region);
    }

    // =====================================================================================================
    // --build
    // =====================================================================================================

    private function load(string $dir, bool $activate, bool $dryRun): int
    {
        $started = hrtime(true);
        $this->lapStart = $started;
        $this->gates = [];
        $this->laps = [];

        // ---- step 1: the manifest, the files, the pipeline's gates, the model match (G19)
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        [$manifest, $manifestText, $configJson] = $this->readManifest($dir);
        $regionId = (string) $manifest['region_id'];
        $version = (string) $manifest['dataset_version'];
        $region = $manifest['region'];
        $this->say(($dryRun ? 'Dry run of ' : 'Loading ') . $version . ' (region ' . $regionId . ') from ' . $dir);
        $this->verifyFiles($dir, $manifest);
        $this->verifyPipelineGates($manifest);
        $this->verifyModelMatch($manifest);
        $this->A = Seeds::defaults();
        $this->A['region'] = [
            'id' => $regionId,
            'traffic_matrix' => self::trafficMatrix($region),
            'flags' => ['inauguration_day' => (bool) ($region['holidays']['inauguration_day'] ?? false)],
        ];
        $kernel = $this->regionService()->kernelFromSeeds();
        $this->lap('verify');

        if (!$dryRun) {
            // ---- step 2: the region row. The live version is not touched.
            $this->writes()->upsertRegion($region, $manifest['bounds'], $configJson);

            // ---- step 3: a version that was loaded before
            $existing = $this->regions()->packMeta($regionId, $version);
            if ($existing !== null) {
                if ($existing['kernel'] !== null && !$this->regionService()->kernelMatches($existing['kernel'])) {
                    throw new \DomainException(self::SEEDS_CHANGED);
                }
                $row = $this->regions()->find($regionId);
                if ($row !== null && $row['active_version'] === $version) {
                    $this->say($version . ' is the live version of ' . $regionId . ': nothing to load.');
                    return self::EXIT_OK;
                }
                $this->say('Replacing the rows of an earlier load of ' . $version . ' (' . $existing['load_state'] . ').');
                $this->deleteVersion($regionId, $version);
            }
            $this->writes()->insertPackRow($regionId, $version, [
                'model_version' => (string) $manifest['model_version'],
                'pipeline_version' => (string) $manifest['pipeline_version'],
                'osm_snapshot' => $manifest['vintages']['osm_snapshot_date'] ?? null,
            ]);
            $this->opened = [$regionId, $version];
            $this->lap('prepare');
        }

        // ---- step 4: places (inserted as they are read); rivals and possible hosts kept as flat arrays
        $this->readPlaces($dir, $regionId, $version, (string) ($manifest['vintages']['osm_snapshot_date'] ?? ''), $dryRun);
        $this->lap('places');

        // ---- step 5: points and cells as flat arrays
        $this->readPoints($dir);
        $this->readCells($dir);
        $this->buildGrid();
        $this->lap('read points and cells');

        // ---- step 6: the rival pull at every source point
        $this->computeRivalsPerPoint();
        $this->lap('rivals per point');

        // ---- step 7: the point rows
        if (!$dryRun) {
            $this->insertPoints($dir, $regionId, $version);
            $this->lap('insert points');
        }

        // ---- step 8: the 50 numbers of every cell; step 8a: the vector of every possible host
        $this->computeCells();
        $this->lap('cell vectors');
        $this->computeHostVectors($dryRun ? null : $regionId, $version);
        $this->releaseModelInputs();
        $this->lap('host vectors');

        // ---- step 9: the pruning cross-check (G20)
        $this->verifyPruningSums();

        // ---- step 10: the request path reproduces the bulk result (G21)
        if ($dryRun) {
            $this->note('G21', 'request-path self-check: not run in a dry run (it reads the rows back from MySQL)');
        } else {
            $this->verifyRequestPath($regionId, $version, $region);
            $this->lap('self-check');
        }

        // ---- step 11: the pack (G22)
        [$pack, $packGz] = $this->buildPack($manifest, $kernel);
        $this->lap('pack');

        $counts = [
            'points' => count($this->pointId),
            'places' => $this->placeCount,
            'cells' => count($this->cellId),
            'pack_format' => CellPackWriter::FORMAT_VERSION,
            'pack_len' => strlen($pack),
            'pack_sha256' => hash('sha256', $pack),
        ];
        unset($pack);

        // ---- step 12: the ledger row
        if (!$dryRun) {
            $this->writes()->finishPack(
                $regionId,
                $version,
                $counts,
                $packGz,
                ['seeds_revision' => (int) $this->A['seeds_revision'], 'kernel' => $kernel],
                $manifestText
            );
            $this->opened = null;
            $this->lap('store pack');
        }

        // ---- step 13: the switch, then the old versions
        $state = $dryRun ? 'nothing written (dry run)' : 'ready';
        if (!$dryRun && $activate) {
            $this->writes()->activate($regionId, $version);
            $removed = $this->prune($regionId);
            $state = 'ready and live' . ($removed === [] ? '' : ' (removed: ' . implode(', ', $removed) . ')');
            $this->lap('activate');
        }

        $this->say('');
        $this->say('region           ' . $regionId . ' (' . (string) ($region['name'] ?? '') . ')');
        $this->say('dataset_version  ' . $version);
        $this->say('points           ' . $counts['points']);
        $this->say('places           ' . $counts['places'] . ' (rivals ' . $this->rivalCount . ', possible hosts ' . count($this->hostKey) . ')');
        $this->say('cells            ' . $counts['cells']);
        $this->say('pack             ' . $counts['pack_len'] . ' bytes, ' . strlen($packGz) . ' gzipped, sha256 ' . $counts['pack_sha256']);
        $this->printGates();
        $times = [];
        foreach ($this->laps as [$name, $seconds]) {
            $times[] = $name . ' ' . sprintf('%.1f', $seconds) . ' s';
        }
        $this->say('timings          ' . implode(', ', $times));
        $this->say(sprintf(
            'wall clock       %.1f s   peak memory %d MiB',
            (hrtime(true) - $started) / 1e9,
            (int) ceil(memory_get_peak_usage(true) / 1048576)
        ));
        $this->say('state            ' . $state);
        if (!$dryRun && !$activate) {
            $this->say('To make it live: php scripts/truck/load-region.php --region=' . $regionId . ' --activate=' . $version);
        }
        if ($dryRun && $activate) {
            $this->say('--activate was not applied: a dry run writes nothing.');
        }
        return self::EXIT_OK;
    }

    // ----------------------------------------------------------------------------------------- step 1

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: string} the manifest, its text, and the region
     *         definition as JSON text
     */
    private function readManifest(string $dir): array
    {
        $path = $dir . '/manifest.json';
        if (!is_dir($dir) || !is_file($path)) {
            throw new \RuntimeException('no manifest.json in ' . $dir . ': --build names the directory of one dataset version');
        }
        $text = file_get_contents($path);
        $manifest = is_string($text) ? json_decode($text, true) : null;
        $asObjects = is_string($text) ? json_decode($text) : null;
        if (!is_array($manifest) || !is_object($asObjects)) {
            throw new \RuntimeException('manifest.json cannot be read as JSON');
        }
        $version = $manifest['dataset_version'] ?? null;
        $regionId = $manifest['region_id'] ?? null;
        $region = $manifest['region'] ?? null;
        $ok = ($manifest['schema'] ?? null) === 1
            && is_string($version) && preg_match('/^[a-z0-9-]{1,48}$/', $version) === 1
            && is_string($regionId) && preg_match('/^[a-z0-9]{1,24}$/', $regionId) === 1
            && is_array($region) && ($region['id'] ?? null) === $regionId
            && is_string($region['name'] ?? null) && is_string($region['timezone'] ?? null) && is_int($region['h3_res'] ?? null)
            && is_array($region['map_center'] ?? null) && self::isNumber($region['map_center']['lat'] ?? null)
            && self::isNumber($region['map_center']['lng'] ?? null)
            && is_array($manifest['bounds'] ?? null)
            && is_string($manifest['model_version'] ?? null) && is_string($manifest['pipeline_version'] ?? null)
            && is_array($manifest['vintages'] ?? null) && is_array($manifest['outputs'] ?? null);
        foreach (['lat_min', 'lng_min', 'lat_max', 'lng_max'] as $key) {
            $ok = $ok && self::isNumber($manifest['bounds'][$key] ?? null);
        }
        if (!$ok) {
            throw new \DomainException('manifest.json is not a manifest of schema 1 (03_DATA.md 8.5)');
        }
        return [$manifest, (string) $text, (string) json_encode($asObjects->region, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }

    /**
     * The four data files are the ones the pipeline wrote: size, SHA-256 and row count.
     *
     * @param array<string, mixed> $manifest
     */
    private function verifyFiles(string $dir, array $manifest): void
    {
        $recorded = [];
        foreach ($manifest['outputs'] as $output) {
            if (is_array($output) && is_string($output['file'] ?? null)) {
                $recorded[$output['file']] = $output;
            }
        }
        foreach (self::DATA_FILES as $file) {
            $path = $dir . '/' . $file;
            if (!is_file($path)) {
                throw new \RuntimeException($file . ' is missing from ' . $dir);
            }
            $want = $recorded[$file] ?? null;
            if ($want === null) {
                $this->gate('files', false, $file, 'the manifest does not list it under outputs');
            }
            $bytes = filesize($path);
            $sha = hash_file('sha256', $path);
            $rows = self::countRows($path, $file);
            $same = $bytes === ($want['bytes'] ?? null) && $sha === ($want['sha256'] ?? null) && $rows === ($want['rows'] ?? null);
            $this->gate(
                'files',
                $same,
                $same ? $file . ': ' . $rows . ' rows, ' . $bytes . ' bytes, sha256 as recorded' : $file . ' is not the file the manifest records',
                'found ' . $rows . ' rows, ' . $bytes . ' bytes, sha256 ' . $sha
                    . '; the manifest records ' . json_encode($want['rows'] ?? null) . ' rows, ' . json_encode($want['bytes'] ?? null)
                    . ' bytes, sha256 ' . json_encode($want['sha256'] ?? null)
            );
        }
    }

    /** Rows of a build file without its header row. A CSV record may span lines inside quotes. */
    private static function countRows(string $path, string $file): int
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException($file . ' cannot be opened');
        }
        $rows = 0;
        $quotes = 0;
        while (($line = fgets($handle)) !== false) {
            if ($file === 'job_review.csv') {
                $quotes += substr_count($line, '"');
                if ($quotes % 2 === 1) {
                    continue;                     // the record goes on in the next line
                }
            } elseif (rtrim($line, "\r\n") === '') {
                continue;
            }
            $rows++;
        }
        fclose($handle);
        return $file === 'places.ndjson' ? $rows : max(0, $rows - 1);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function verifyPipelineGates(array $manifest): void
    {
        $gates = $manifest['gates'] ?? null;
        $failed = [];
        $warned = [];
        $seen = 0;
        foreach (is_array($gates) ? $gates : [] as $gate) {
            if (!is_array($gate)) {
                continue;
            }
            $seen++;
            $name = (string) ($gate['id'] ?? '?') . (isset($gate['part']) ? '.' . $gate['part'] : '');
            if (($gate['pass'] ?? null) !== true) {
                if (($gate['level'] ?? null) === 'warn') {
                    $warned[] = $name;
                } else {
                    $failed[] = $name;
                }
            }
        }
        $this->gate(
            'pipeline',
            $seen > 0 && $failed === [],
            'every fail-level gate of the pipeline passed (' . $seen . ' checks' . ($warned === [] ? '' : ', warnings: ' . implode(', ', $warned)) . ')',
            $seen === 0 ? 'the manifest records no gates' : 'not passed: ' . implode(', ', $failed)
        );
    }

    /**
     * G19: the build was made for this model. Any difference means: rebuild the region.
     *
     * @param array<string, mixed> $manifest
     */
    private function verifyModelMatch(array $manifest): void
    {
        $A = Seeds::defaults();
        $this->gate(
            'G19.model_version',
            $manifest['model_version'] === $A['model_version'],
            'model version ' . $manifest['model_version'],
            'this server runs ' . $A['model_version'] . ': rebuild the region'
        );

        $parameters = $manifest['parameters'] ?? null;
        $service = $this->regionService();
        $this->gate(
            'G19.parameters',
            is_array($parameters) && $service->buildScopeMatches($service->kernelFromSeeds(), $parameters),
            'every seed value the pipeline used equals this server\'s (seeds revision '
                . json_encode($manifest['inputs']['seeds_revision'] ?? null) . ' at build, ' . (int) $A['seeds_revision'] . ' here)',
            'differs in: ' . implode(', ', $service->parameterDifferences(is_array($parameters) ? $parameters : [])) . ': rebuild the region'
        );

        $matrix = self::trafficMatrix($manifest['region']);
        $traffic = $A['seeds']['traffic'] ?? [];
        $this->gate(
            'G19.traffic_matrix',
            is_array($traffic) && isset($traffic[$matrix], $traffic[$matrix . '_typical']),
            'traffic matrix ' . $matrix . ' and its typical value are in the seeds',
            'the seeds hold no traffic.' . $matrix . ' or no traffic.' . $matrix . '_typical'
        );
    }

    /**
     * The traffic matrix a region uses: the one its definition names, else `us_mean`.
     *
     * @param array<string, mixed> $region
     */
    private static function trafficMatrix(array $region): string
    {
        $named = $region['traffic_matrix'] ?? null;
        return is_string($named) && $named !== '' ? $named : 'us_mean';
    }

    // ----------------------------------------------------------------------------------------- step 3

    private function deleteVersion(string $regionId, string $version): void
    {
        foreach (['tp_points', 'tp_places', 'tp_region_packs'] as $table) {
            do {
                $deleted = $this->writes()->deleteVersionBatch($table, $regionId, $version, self::DELETE_BATCH);
            } while ($deleted > 0);
        }
    }

    // ----------------------------------------------------------------------------------------- step 4

    private function readPlaces(string $dir, string $regionId, string $version, string $snapshotDate, bool $dryRun): void
    {
        $A = $this->A;
        $kinds = array_flip(Estimator::seed($A, 'vocabulary.rival_kinds'));
        $segments = array_flip(Estimator::seed($A, 'vocabulary.segments'));
        if (!$dryRun && preg_match('/^\d{4}-\d{2}-\d{2}$/', $snapshotDate) !== 1) {
            throw new \DomainException('the manifest has no vintages.osm_snapshot_date');
        }

        $handle = self::open($dir . '/places.ndjson');
        $batch = [];
        $line = 0;
        $previous = '';
        while (($text = fgets($handle)) !== false) {
            $text = rtrim($text, "\r\n");
            if ($text === '') {
                continue;
            }
            $line++;
            $place = json_decode($text, true);
            if (!is_array($place)) {
                throw new \DomainException('places.ndjson line ' . $line . ' is not a JSON object');
            }
            foreach (self::PLACE_KEYS as $key) {
                if (!array_key_exists($key, $place)) {
                    throw new \DomainException('places.ndjson line ' . $line . ' has no ' . $key);
                }
            }
            $key = $place['place_key'];
            $rivalKind = $place['rival_kind'];
            $segment = $place['visitor_segment'];
            $valid = is_string($key) && preg_match('/^[nwr][0-9]{1,19}$/', $key) === 1
                && self::isNumber($place['lat']) && self::isNumber($place['lng'])
                && self::isNumber($place['size_default']) && $place['size_default'] >= 0
                && self::isNumber($place['host_fit']) && $place['host_fit'] >= 0
                && ($place['in_region'] === 0 || $place['in_region'] === 1)
                && ($rivalKind === null || (is_string($rivalKind) && isset($kinds[$rivalKind])))
                && ($segment === null || (is_string($segment) && isset($segments[$segment])))
                && in_array($place['kitchen'], ['yes', 'no', 'unknown'], true)
                && is_string($place['place_type']) && is_string($place['osm_type']) && is_string($place['geom_kind'])
                && is_string($place['osm_id']) && ctype_digit($place['osm_id'])
                && ($place['tags'] === null || is_array($place['tags']));
            foreach (self::PLACE_TEXT_KEYS as $text) {
                $valid = $valid && ($place[$text] === null || is_string($place[$text]));
            }
            if (!$valid) {
                throw new \DomainException('places.ndjson line ' . $line . ' does not have the shape of 03_DATA.md 8.2');
            }
            if (strcmp($key, $previous) <= 0) {
                throw new \DomainException('places.ndjson is not sorted by place_key at line ' . $line);
            }
            $previous = $key;

            $lat = (float) $place['lat'];
            $lng = (float) $place['lng'];
            if ($rivalKind !== null) {
                $this->rivalKey[] = $key;
                $this->rivalLat[] = $lat;
                $this->rivalLng[] = $lng;
                $this->rivalKind[] = $rivalKind;
            }
            if ($place['in_region'] === 1 && $place['host_fit'] > 0) {
                $this->hostKey[] = $key;
                $this->hostLat[] = $lat;
                $this->hostLng[] = $lng;
                $this->hostType[] = $place['place_type'];
                $this->hostSegment[] = $segment;
                $this->hostSize[] = (float) $place['size_default'];
            }
            if (!$dryRun) {
                $place['snapshot_date'] = $snapshotDate;
                $batch[] = $place;
                if (count($batch) === self::ROWS_PER_TRANSACTION) {
                    $this->writes()->insertPlaces($regionId, $version, $batch);
                    $batch = [];
                }
            }
        }
        fclose($handle);
        if ($batch !== []) {
            $this->writes()->insertPlaces($regionId, $version, $batch);
        }
        $this->placeCount = $line;
        $this->rivalCount = count($this->rivalKey);
    }

    // ----------------------------------------------------------------------------------------- step 5

    private function readPoints(string $dir): void
    {
        $this->pointBase = array_fill(0, self::SEGMENTS, []);
        $previous = '';
        foreach (self::tsvRows($dir . '/points.tsv', self::POINT_COLUMNS) as $line => $fields) {
            $id = $fields[0];
            if (preg_match('/^[bp][0-9a-z]{1,23}$/', $id) !== 1 || strcmp($id, $previous) <= 0) {
                throw new \DomainException('points.tsv line ' . $line . ': point ids must be valid and in ascending byte order');
            }
            $previous = $id;
            $flags = ($fields[3] === '0' || $fields[3] === '1') && ($fields[4] === '0' || $fields[4] === '1');
            if (!$flags || $fields[1] !== ($id[0] === 'b' ? 'block' : 'place') || $fields[2] !== substr($id, 1)) {
                throw new \DomainException('points.tsv line ' . $line . ' does not have the shape of 03_DATA.md 8.1');
            }
            for ($c = 5; $c < 23; $c++) {
                if (!is_numeric($fields[$c]) || !is_finite((float) $fields[$c])) {
                    throw new \DomainException('points.tsv line ' . $line . ': ' . self::POINT_COLUMNS[$c] . ' is not a number');
                }
            }
            $this->pointId[] = $id;
            $this->pointLat[] = (float) $fields[5];
            $this->pointLng[] = (float) $fields[6];
            for ($s = 0; $s < self::SEGMENTS; $s++) {
                $this->pointBase[$s][] = (float) $fields[7 + $s];
            }
        }
    }

    private function readCells(string $dir): void
    {
        $previous = '';
        foreach (self::tsvRows($dir . '/cells.tsv', self::CELL_COLUMNS) as $line => $fields) {
            $id = $fields[0];
            if (preg_match('/^[0-9a-f]{15}$/', $id) !== 1 || strcmp($id, $previous) <= 0) {
                throw new \DomainException('cells.tsv line ' . $line . ': cell ids must be 15 hexadecimal characters, ascending');
            }
            $previous = $id;
            for ($c = 1; $c < 4; $c++) {
                if (!is_numeric($fields[$c]) || !is_finite((float) $fields[$c])) {
                    throw new \DomainException('cells.tsv line ' . $line . ': ' . self::CELL_COLUMNS[$c] . ' is not a number');
                }
            }
            $this->cellId[] = $id;
            $this->cellLat[] = (float) $fields[1];
            $this->cellLng[] = (float) $fields[2];
            $this->cellNearbyEtl[] = (float) $fields[3];
        }
    }

    /**
     * The rows of a tab-separated build file, keyed by line number, after checking its header row.
     *
     * @param list<string> $columns
     * @return \Generator<int, list<string>>
     */
    private static function tsvRows(string $path, array $columns): \Generator
    {
        $name = basename($path);
        $handle = self::open($path);
        $header = fgets($handle);
        if ($header === false || explode("\t", rtrim($header, "\r\n")) !== $columns) {
            fclose($handle);
            throw new \DomainException($name . ' does not start with the header row of 03_DATA.md section 8');
        }
        $width = count($columns);
        $line = 1;
        while (($text = fgets($handle)) !== false) {
            $line++;
            $text = rtrim($text, "\r\n");
            if ($text === '') {
                continue;
            }
            $fields = explode("\t", $text);
            if (count($fields) !== $width) {
                fclose($handle);
                throw new \DomainException($name . ' line ' . $line . ' has ' . count($fields) . ' fields, not ' . $width);
            }
            yield $line => $fields;
        }
        fclose($handle);
    }

    /**
     * @return resource
     */
    private static function open(string $path)
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(basename($path) . ' cannot be opened');
        }
        return $handle;
    }

    // ----------------------------------------------------------------------------------------- the grid

    /**
     * A fixed grid whose buckets are at least one walking cutoff high and wide everywhere in the build, so
     * that the 3 by 3 buckets around any point hold everything within the cutoff. The width is taken at
     * the largest latitude of the build: taken at the centre it would be too narrow at the northern edge.
     */
    private function buildGrid(): void
    {
        $cutoff = (float) Estimator::seed($this->A, 'kernel.walk_cutoff_m');
        $largest = 0.0;
        foreach ([$this->pointLat, $this->rivalLat, $this->hostLat, $this->cellLat] as $latitudes) {
            foreach ($latitudes as $lat) {
                $absolute = abs($lat);
                if ($absolute > $largest) {
                    $largest = $absolute;
                }
            }
        }
        // The half-widths of the query box of 03_DATA.md 9.3, the second one at the largest latitude.
        $this->gridLat = rad2deg($cutoff / (float) Estimator::seed($this->A, 'constants.earth_radius_m')) * PointRepository::GUARD_BAND;
        $this->gridLng = $this->gridLat / max(0.01, cos(deg2rad($largest)));

        $this->pointBuckets = [];
        foreach ($this->pointLat as $i => $lat) {
            $this->pointBuckets[$this->bucket($lat, $this->pointLng[$i])][] = $i;
        }
        $this->rivalBuckets = [];
        foreach ($this->rivalLat as $i => $lat) {
            $this->rivalBuckets[$this->bucket($lat, $this->rivalLng[$i])][] = $i;
        }
        $this->sourceCache = [];
        $this->sourceLists = [];
        $this->outletLists = [];
    }

    private function bucket(float $lat, float $lng): int
    {
        return (int) floor($lat / $this->gridLat) * self::BUCKET_SPAN + (int) floor($lng / $this->gridLng);
    }

    /**
     * The indexes held by the 3 by 3 buckets around a bucket, ascending. The build files are in ascending
     * byte order of their ids (checked while reading), so ascending index is ascending id.
     *
     * @param array<int, list<int>> $buckets
     * @return list<int>
     */
    private static function around(array $buckets, int $bucket): array
    {
        $found = [];
        foreach ([-self::BUCKET_SPAN, 0, self::BUCKET_SPAN] as $row) {
            for ($column = -1; $column <= 1; $column++) {
                foreach ($buckets[$bucket + $row + $column] ?? [] as $index) {
                    $found[] = $index;
                }
            }
        }
        sort($found);
        return $found;
    }

    /**
     * The rival outlets of the 3 by 3 buckets around a bucket, as the Outlet list the model takes, in id order.
     *
     * @return list<array{id: string, lat: float, lng: float, kind: string}>
     */
    private function outletsAround(int $bucket): array
    {
        if (!isset($this->outletLists[$bucket])) {
            if (count($this->outletLists) >= self::MAX_CACHED_NEIGHBOURHOODS) {
                $this->outletLists = [];
            }
            $outlets = [];
            foreach (self::around($this->rivalBuckets, $bucket) as $i) {
                $outlets[] = [
                    'id' => $this->rivalKey[$i],
                    'lat' => $this->rivalLat[$i],
                    'lng' => $this->rivalLng[$i],
                    'kind' => $this->rivalKind[$i],
                ];
            }
            $this->outletLists[$bucket] = $outlets;
        }
        return $this->outletLists[$bucket];
    }

    /**
     * The source points of the 3 by 3 buckets around a bucket, as the SourcePoint list the model takes, in
     * id order. Needs the rival pulls of step 6.
     *
     * @return list<array{id: string, lat: float, lng: float, base: list<float>, rivals: array{day: float, eve: float}}>
     */
    private function sourcesAround(int $bucket): array
    {
        if (!isset($this->sourceLists[$bucket])) {
            if (count($this->sourceLists) >= self::MAX_CACHED_NEIGHBOURHOODS) {
                $this->sourceLists = [];
            }
            $sources = [];
            foreach (self::around($this->pointBuckets, $bucket) as $i) {
                if (!isset($this->sourceCache[$i])) {
                    if (count($this->sourceCache) >= self::MAX_CACHED_SOURCES) {
                        $this->sourceCache = [];
                    }
                    $base = [];
                    for ($s = 0; $s < self::SEGMENTS; $s++) {
                        $base[] = $this->pointBase[$s][$i];
                    }
                    $this->sourceCache[$i] = [
                        'id' => $this->pointId[$i],
                        'lat' => $this->pointLat[$i],
                        'lng' => $this->pointLng[$i],
                        'base' => $base,
                        'rivals' => ['day' => $this->pointRivalsDay[$i], 'eve' => $this->pointRivalsEve[$i]],
                    ];
                }
                $sources[] = $this->sourceCache[$i];
            }
            $this->sourceLists[$bucket] = $sources;
        }
        return $this->sourceLists[$bucket];
    }

    // ----------------------------------------------------------------------------------------- step 6

    private function computeRivalsPerPoint(): void
    {
        $this->pointRivalsDay = [];
        $this->pointRivalsEve = [];
        foreach ($this->pointLat as $i => $lat) {
            $lng = $this->pointLng[$i];
            $rivals = Estimator::rivalsAtOrigin($this->A, $lat, $lng, $this->outletsAround($this->bucket($lat, $lng)));
            $this->pointRivalsDay[] = $rivals['day'];
            $this->pointRivalsEve[] = $rivals['eve'];
        }
    }

    // ----------------------------------------------------------------------------------------- step 7

    /**
     * The point rows: the numbers of the file as they are written there, the rival pulls as computed.
     */
    private function insertPoints(string $dir, string $regionId, string $version): void
    {
        $batch = [];
        $i = 0;
        foreach (self::tsvRows($dir . '/points.tsv', self::POINT_COLUMNS) as $fields) {
            if (($this->pointId[$i] ?? null) !== $fields[0]) {
                throw new \DomainException('points.tsv changed while it was being loaded');
            }
            $batch[] = [
                'point_id' => $fields[0],
                'src_kind' => $fields[1],
                'src_ref' => $fields[2],
                'in_region' => $fields[3],
                'job_adj' => $fields[4],
                'lat' => $fields[5],
                'lng' => $fields[6],
                'base' => array_slice($fields, 7, self::SEGMENTS),
                'rivals_day' => $this->pointRivalsDay[$i],
                'rivals_eve' => $this->pointRivalsEve[$i],
            ];
            $i++;
            if (count($batch) === self::ROWS_PER_TRANSACTION) {
                $this->writes()->insertPoints($regionId, $version, $batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->writes()->insertPoints($regionId, $version, $batch);
        }
        if ($i !== count($this->pointId)) {
            throw new \DomainException('points.tsv changed while it was being loaded');
        }
    }

    // ----------------------------------------------------------------------------------------- step 8

    private function computeCells(): void
    {
        $this->cellColumns = array_fill(0, self::VECTOR_COLUMNS, []);
        foreach ($this->cellLat as $i => $lat) {
            $lng = $this->cellLng[$i];
            $bucket = $this->bucket($lat, $lng);
            $vectors = Estimator::captureAtPoint(
                $this->A,
                $lat,
                $lng,
                'normal',
                $this->sourcesAround($bucket),
                $this->outletsAround($bucket),
                self::NO_EXCLUSION
            );
            foreach (VectorCodec::flat($vectors) as $j => $value) {
                $this->cellColumns[$j][] = $value;
            }
        }
    }

    /**
     * Step 8a: the location vector of every possible host, with the host's own source point left out.
     * Written in place_key order, 500 to a transaction. `$regionId` null computes without writing.
     */
    private function computeHostVectors(?string $regionId, string $version): void
    {
        // Computed bucket by bucket (neighbouring hosts share their lists), written in place_key order.
        $order = [];
        foreach ($this->hostLat as $i => $lat) {
            $order[$i] = $this->bucket($lat, $this->hostLng[$i]);
        }
        asort($order);
        $bytes = [];
        foreach ($order as $i => $bucket) {
            $exclusion = self::NO_EXCLUSION;
            if ($this->hostSegment[$i] !== null) {
                $exclusion['point_ids'] = ['p' . $this->hostKey[$i]];
            }
            $vectors = Estimator::captureAtPoint(
                $this->A,
                $this->hostLat[$i],
                $this->hostLng[$i],
                'normal',
                $this->sourcesAround($bucket),
                $this->outletsAround($bucket),
                $exclusion
            );
            $bytes[$i] = pack('e50', ...VectorCodec::flat($vectors));
        }
        if ($regionId === null) {
            return;
        }
        $writes = $this->writes();
        $count = count($this->hostKey);
        for ($from = 0; $from < $count; $from += self::HOST_VECTORS_PER_TRANSACTION) {
            $to = min($count, $from + self::HOST_VECTORS_PER_TRANSACTION);
            $writes->transaction(function () use ($writes, $regionId, $version, $bytes, $from, $to): void {
                for ($i = $from; $i < $to; $i++) {
                    $writes->setHostVec($regionId, $version, $this->hostKey[$i], $bytes[$i]);
                }
            });
        }
    }

    // ----------------------------------------------------------------------------------------- step 9

    /** G20: the sum of the model's nearby vector equals the pipeline's `nearby_etl` at every cell. */
    private function verifyPruningSums(): void
    {
        $worst = 0.0;
        $bad = [];
        $this->cellNearbySum = [];
        foreach ($this->cellNearbyEtl as $i => $etl) {
            $sum = 0.0;
            for ($s = 0; $s < self::SEGMENTS; $s++) {
                $sum += $this->cellColumns[32 + $s][$i];
            }
            $this->cellNearbySum[] = $sum;
            $relative = abs($sum - $etl) / max(1.0, $etl);
            if ($relative > $worst) {
                $worst = $relative;
            }
            if ($relative > 1e-9 && count($bad) < 3) {
                $bad[] = $this->cellId[$i] . ' (model ' . JsonSafe::float($sum) . ', pipeline ' . JsonSafe::float($etl) . ')';
            }
        }
        $this->gate(
            'G20',
            $worst <= 1e-9,
            'pruning cross-check on ' . count($this->cellId) . ' cells: largest relative difference ' . sprintf('%.2e', $worst) . ' (limit 1e-9)',
            'the JavaScript and PHP distance or decay code disagree, for example at ' . implode(', ', $bad)
        );
    }

    // ----------------------------------------------------------------------------------------- step 10

    /**
     * G21: the service the simulate endpoint uses, reading the rows of this version from MySQL, gives the
     * bulk result at sampled cell centres and the stored vector at sampled hosts.
     *
     * @param array<string, mixed> $region the region definition
     */
    private function verifyRequestPath(string $regionId, string $version, array $region): void
    {
        $capture = $this->capture();
        if ($capture instanceof CaptureService) {
            $capture->forget();
        }
        $names = CellPackWriter::columnNames();

        // ---- the sample of cells
        $count = count($this->cellId);
        $sample = [];
        $step = max(1, (int) floor($count / self::SAMPLE_CELLS));
        for ($i = 0; $i < $count; $i += $step) {
            $sample[$i] = true;
        }
        $bySum = $this->cellNearbySum;
        arsort($bySum);
        foreach (array_slice(array_keys($bySum), 0, self::SAMPLE_TOP_CELLS) as $i) {
            $sample[$i] = true;
        }
        $anchors = 0;
        foreach ((array) ($region['checks']['anchors'] ?? []) as $anchor) {
            $at = is_array($anchor) ? array_search('b' . ($anchor['geoid'] ?? ''), $this->pointId, true) : false;
            if ($at === false) {
                continue;
            }
            $nearest = null;
            $nearestDistance = 0.0;
            foreach ($this->cellLat as $i => $lat) {
                $d = Estimator::haversineM($this->pointLat[$at], $this->pointLng[$at], $lat, $this->cellLng[$i]);
                if ($nearest === null || $d < $nearestDistance) {
                    $nearest = $i;
                    $nearestDistance = $d;
                }
            }
            if ($nearest !== null) {
                $sample[$nearest] = true;
                $anchors++;
            }
        }
        ksort($sample);

        $worst = 0.0;
        $bad = [];
        foreach (array_keys($sample) as $i) {
            $answer = $capture->capture($regionId, $this->cellLat[$i], $this->cellLng[$i], ['normal'], null, $version);
            foreach (VectorCodec::flat($answer['vectors']['normal']) as $j => $got) {
                $want = $this->cellColumns[$j][$i];
                $relative = self::relativeDifference($got, $want);
                $worst = max($worst, $relative);
                if (!self::within($got, $want) && count($bad) < 3) {
                    $bad[] = 'cell ' . $this->cellId[$i] . ' ' . $names[$j] . ' (request ' . JsonSafe::float($got) . ', bulk ' . JsonSafe::float($want) . ')';
                }
            }
        }
        $this->gate(
            'G21.cells',
            $bad === [],
            'request path equals the bulk result at ' . count($sample) . ' of ' . $count . ' cells (one in ' . $step . ', the '
                . self::SAMPLE_TOP_CELLS . ' with most people nearby, ' . $anchors . ' at anchor blocks): largest relative difference '
                . sprintf('%.2e', $worst) . ' (limit 1e-12)',
            'differs, for example at ' . implode(', ', $bad)
        );

        // ---- the sample of hosts
        $hosts = count($this->hostKey);
        $hostStep = max(1, (int) floor($hosts / self::SAMPLE_HOSTS));
        $worst = 0.0;
        $bad = [];
        $checked = 0;
        for ($i = 0; $i < $hosts; $i += $hostStep) {
            $key = $this->hostKey[$i];
            $lat = $this->hostLat[$i];
            $lng = $this->hostLng[$i];
            $stored = null;
            foreach ($this->places()->hostsNear($regionId, $version, $lat, $lng, self::HOST_READ_BACK_RADIUS_M) as $row) {
                if ($row[PlaceRepository::HOST_KEY] === $key) {
                    $stored = $row[PlaceRepository::HOST_VEC];
                    break;
                }
            }
            $checked++;
            if (!is_string($stored) || strlen($stored) !== VectorCodec::BLOCK_BYTES) {
                if (count($bad) < 3) {
                    $bad[] = 'host ' . $key . ' has no stored vector';
                }
                continue;
            }
            $host = $this->hostSegment[$i] === null ? null : [
                'segment' => $this->hostSegment[$i],
                'size' => $this->hostSize[$i],
                'size_source' => 'default',
                'only_food' => false,
                'point_id' => 'p' . $key,
                'place_type' => $this->hostType[$i],
            ];
            $answer = $capture->capture($regionId, $lat, $lng, ['normal'], $host, $version);
            $want = VectorCodec::fromBytes($stored);
            foreach (VectorCodec::flat($answer['vectors']['normal']) as $j => $got) {
                $worst = max($worst, self::relativeDifference($got, $want[$j]));
                if (!self::within($got, $want[$j]) && count($bad) < 3) {
                    $bad[] = 'host ' . $key . ' ' . $names[$j] . ' (request ' . JsonSafe::float($got) . ', stored ' . JsonSafe::float($want[$j]) . ')';
                }
            }
        }
        $this->gate(
            'G21.hosts',
            $bad === [],
            'request path equals the stored vector of ' . $checked . ' of ' . $hosts . ' possible hosts (one in ' . $hostStep
                . '): largest relative difference ' . sprintf('%.2e', $worst) . ' (limit 1e-12)',
            'differs, for example at ' . implode(', ', $bad)
        );
    }

    /** Equal within 1e-12 relative, with an absolute floor of 1e-12. */
    private static function within(float $a, float $b): bool
    {
        return abs($a - $b) <= max(1e-12, 1e-12 * max(abs($a), abs($b)));
    }

    private static function relativeDifference(float $a, float $b): float
    {
        $scale = max(abs($a), abs($b));
        return $scale <= 1.0 ? abs($a - $b) : abs($a - $b) / $scale;
    }

    // ----------------------------------------------------------------------------------------- step 11

    /**
     * Builds the pack, compresses it, reads it back and checks every value (G22).
     *
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $kernel
     * @return array{0: string, 1: string} the pack and the gzip-compressed pack
     */
    private function buildPack(array $manifest, array $kernel): array
    {
        $vintages = $manifest['vintages'];
        $bounds = ['lat_min' => 0.0, 'lng_min' => 0.0, 'lat_max' => 0.0, 'lng_max' => 0.0];
        if ($this->cellLat !== []) {
            $bounds = [
                'lat_min' => min($this->cellLat),
                'lng_min' => min($this->cellLng),
                'lat_max' => max($this->cellLat),
                'lng_max' => max($this->cellLng),
            ];
        }
        $pack = CellPackWriter::build(
            [
                'region_id' => (string) $manifest['region_id'],
                'dataset_version' => (string) $manifest['dataset_version'],
                'model_version' => (string) $manifest['model_version'],
                'pipeline_version' => (string) $manifest['pipeline_version'],
                'h3_res' => (int) $manifest['region']['h3_res'],
                'bounds' => $bounds,
                'kernel' => $kernel,
                'vintages' => [
                    'census_reference_date' => $vintages['census_reference_date'] ?? null,
                    'lodes_year' => $vintages['lodes_year'] ?? null,
                    'osm_snapshot_date' => $vintages['osm_snapshot_date'] ?? null,
                ],
                'attribution' => [
                    '© OpenStreetMap contributors',
                    'U.S. Census Bureau, 2020 Census',
                    'U.S. Census Bureau, LEHD LODES ' . ($vintages['lodes_format'] ?? '') . ' (' . ($vintages['lodes_year'] ?? '') . ')',
                ],
            ],
            $this->cellId,
            $this->cellColumns
        );
        $gz = gzencode($pack, 9);
        if ($gz === false) {
            throw new \RuntimeException('the pack could not be compressed');
        }

        // Read back what will be served, one column at a time.
        $back = gzdecode($gz);
        $layout = CellPackWriter::layout(is_string($back) ? $back : '');
        $count = count($this->cellId);
        $bad = [];
        $worst = 0.0;
        if ($back !== $pack) {
            $bad[] = 'the compressed pack does not read back as the pack';
        }
        unset($back);
        if ($layout['n'] !== $count || $layout['header']['cell_count'] !== $count || CellPackWriter::ids($pack, $layout) !== $this->cellId) {
            $bad[] = 'cell_count or the cell ids differ from cells.tsv';
        }
        if ($layout['k'] !== self::VECTOR_COLUMNS) {
            $bad[] = 'the pack does not have ' . self::VECTOR_COLUMNS . ' columns';
        }
        if ($bad === []) {
            $names = CellPackWriter::columnNames();
            $levels = CellPackWriter::LEVELS;
            foreach ($this->cellColumns as $j => $column) {
                $scale = (float) $layout['header']['scale'][$j];
                $quarterStep = $scale / (4.0 * $levels * $levels);
                $values = CellPackWriter::column($pack, $j, $layout);
                foreach ($column as $i => $v) {
                    $error = abs($values[$i] - $v);
                    $bound = sqrt($v * $scale) / $levels + $quarterStep;       // CellPackWriter::errorBound()
                    if ($bound > 0.0 && $error / $bound > $worst) {
                        $worst = $error / $bound;
                    }
                    // 1e-9 on the bound: room for the rounding of the comparison itself
                    if ($error > $bound * (1.0 + 1e-9) && count($bad) < 3) {
                        $bad[] = $names[$j] . ' of ' . $this->cellId[$i] . ' (' . JsonSafe::float($v) . ' decodes to ' . JsonSafe::float($values[$i]) . ')';
                    }
                }
            }
        }
        if (strlen($gz) > self::PACK_MAX_GZ_BYTES) {
            $bad[] = 'the compressed pack is ' . strlen($gz) . ' bytes, more than 16 MB';
        }
        $this->gate(
            'G22',
            $bad === [],
            'pack of ' . $count . ' cells: ' . strlen($pack) . ' bytes, ' . strlen($gz) . ' gzipped; every value decodes within the quantisation bound (largest error '
                . sprintf('%.6f', $worst) . ' of its bound)',
            implode('; ', $bad)
        );
        return [$pack, $gz];
    }

    // =====================================================================================================
    // --region
    // =====================================================================================================

    private function activateVersion(string $regionId, string $version): int
    {
        $row = $this->regions()->find($regionId);
        if ($row === null) {
            return $this->usage('unknown region: ' . $regionId);
        }
        $meta = $this->regions()->packMeta($regionId, $version);
        if ($meta === null) {
            return $this->usage($regionId . ' has no dataset version ' . $version . ' (see --list)');
        }
        if ($meta['load_state'] !== RegionLoadRepository::STATE_READY) {
            throw new \DomainException($version . ' is ' . $meta['load_state'] . ', not ready: load it again before activating it');
        }
        if ($row['active_version'] === $version) {
            $this->say($version . ' is already the live version of ' . $regionId . '.');
            return self::EXIT_OK;
        }
        if (!$this->regionService()->kernelMatches($meta['kernel'])) {
            $this->say('WARNING: ' . $version . ' was built with other model constants than this server has. While it is live,');
            $this->say('         requests answer 409 "' . CaptureService::BUILD_MISMATCH . '".');
        }
        $this->writes()->activate($regionId, $version);
        $this->say($version . ' is now the live version of ' . $regionId
            . ($row['active_version'] === null ? '.' : '. To go back: --region=' . $regionId . ' --activate=' . $row['active_version']));
        return self::EXIT_OK;
    }

    private function listVersions(string $regionId): int
    {
        $row = $this->regions()->find($regionId);
        if ($row === null) {
            return $this->usage('unknown region: ' . $regionId);
        }
        $versions = $this->writes()->versions($regionId);
        $this->say('Region ' . $regionId . ' (' . $row['name'] . '): ' . count($versions) . ' dataset version' . (count($versions) === 1 ? '' : 's'));
        foreach ($versions as $v) {
            $role = $v['dataset_version'] === $row['active_version'] ? 'live'
                : ($v['dataset_version'] === $row['previous_version'] ? 'previous' : '-');
            $this->say(sprintf(
                '  %-40s %-8s %-8s points %d, places %d, cells %d, pack %d bytes (%d gzipped), loaded %s%s',
                $v['dataset_version'],
                $role,
                $v['load_state'],
                $v['point_count'],
                $v['place_count'],
                $v['cell_count'],
                $v['pack_len'],
                $v['pack_gz_len'],
                $v['loaded_at'],
                $v['activated_at'] === null ? '' : ', activated ' . $v['activated_at']
            ));
        }
        return self::EXIT_OK;
    }

    private function pruneRegion(string $regionId): int
    {
        if ($this->regions()->find($regionId) === null) {
            return $this->usage('unknown region: ' . $regionId);
        }
        $removed = $this->prune($regionId);
        $this->say($removed === [] ? 'Nothing to prune for ' . $regionId . '.' : 'Removed from ' . $regionId . ': ' . implode(', ', $removed));
        return self::EXIT_OK;
    }

    /**
     * Deletes every dataset version of a region but the live and the previous one.
     *
     * @return list<string> the versions removed
     */
    private function prune(string $regionId): array
    {
        $row = $this->regions()->find($regionId);
        if ($row === null) {
            return [];
        }
        $removed = [];
        foreach ($this->writes()->versions($regionId) as $v) {
            $version = $v['dataset_version'];
            if ($version === $row['active_version'] || $version === $row['previous_version']) {
                continue;
            }
            $this->deleteVersion($regionId, $version);
            $removed[] = $version;
        }
        return $removed;
    }

    // =====================================================================================================
    // reporting
    // =====================================================================================================

    /** Records a check. A check that does not hold ends the run (exit code 2). */
    private function gate(string $id, bool $pass, string $check, string $failure): void
    {
        $this->gates[] = ['id' => $id, 'pass' => $pass, 'check' => $check, 'detail' => $pass ? '' : $failure];
        if (!$pass) {
            throw new \DomainException($id . ': ' . $check . ' - ' . $failure);
        }
    }

    private function note(string $id, string $text): void
    {
        $this->gates[] = ['id' => $id, 'pass' => true, 'check' => $text, 'detail' => 'skipped'];
    }

    private function printGates(): void
    {
        foreach ($this->gates as $gate) {
            $mark = !$gate['pass'] ? 'FAIL' : ($gate['detail'] === 'skipped' ? 'skip' : 'ok');
            $this->say(sprintf('%-4s %-18s %s', $mark, $gate['id'], $gate['check']));
        }
        $this->gates = [];
    }

    private function lap(string $name): void
    {
        $now = hrtime(true);
        $this->laps[] = [$name, ($now - $this->lapStart) / 1e9];
        $this->lapStart = $now;
    }

    private function say(string $line): void
    {
        ($this->out)($line);
    }

    private function usage(string $problem): int
    {
        ($this->err)('load-region: ' . $problem);
        return self::EXIT_USAGE;
    }

    /** A version whose load did not finish stays behind as `failed` and is never activated. */
    private function markFailed(): void
    {
        if ($this->opened === null) {
            return;
        }
        [$regionId, $version] = $this->opened;
        $this->opened = null;
        try {
            $this->writes()->setState($regionId, $version, RegionLoadRepository::STATE_FAILED);
        } catch (\Throwable $e) {
            ($this->err)('The version could not be marked failed: ' . get_class($e));
        }
    }

    /** What only the model calls needed: the bases, the rival pulls, the grid and the lists built from them. */
    private function releaseModelInputs(): void
    {
        $this->pointBase = $this->pointRivalsDay = $this->pointRivalsEve = [];
        $this->rivalKey = $this->rivalLat = $this->rivalLng = $this->rivalKind = [];
        $this->pointBuckets = $this->rivalBuckets = $this->sourceCache = $this->sourceLists = $this->outletLists = [];
    }

    private function release(): void
    {
        $this->pointId = $this->pointLat = $this->pointLng = $this->pointBase = [];
        $this->pointRivalsDay = $this->pointRivalsEve = [];
        $this->rivalKey = $this->rivalLat = $this->rivalLng = $this->rivalKind = [];
        $this->hostKey = $this->hostLat = $this->hostLng = $this->hostType = $this->hostSegment = $this->hostSize = [];
        $this->cellId = $this->cellLat = $this->cellLng = $this->cellNearbyEtl = $this->cellColumns = $this->cellNearbySum = [];
        $this->pointBuckets = $this->rivalBuckets = $this->sourceCache = $this->sourceLists = $this->outletLists = [];
        $this->placeCount = $this->rivalCount = 0;
    }

    private static function isNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private function writes(): RegionLoadRepository
    {
        return $this->writes ??= new RegionLoadRepository();
    }

    private function regions(): RegionRepository
    {
        return $this->regions ??= new RegionRepository();
    }

    private function regionService(): RegionService
    {
        return $this->regionService ??= new RegionService($this->regions());
    }

    private function capture(): CaptureProvider
    {
        return $this->capture ??= new CaptureService();
    }

    private function places(): PlaceRepository
    {
        return $this->places ??= new PlaceRepository();
    }
}
