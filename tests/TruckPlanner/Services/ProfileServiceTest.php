<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Core\Database;
use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Contracts\CaptureProvider;
use App\TruckPlanner\Services\Contracts\FuelPriceProvider;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\ProfileService;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpCache;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use PHPUnit\Framework\TestCase;

/**
 * The profile service over the real repositories, mapper and region service. The database is a double that
 * keeps `tp_trucks` in memory the way MySQL would hold it (cents, 0/1, JSON text), so an answer is read
 * back from what the statements wrote.
 */
final class ProfileServiceTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const OTHER_ORG = '22222222-2222-4222-8222-222222222222';
    private const USER = '99999999-9999-4999-8999-999999999999';

    private const STERLING = ['lat' => 39.003, 'lng' => -77.405];      // inside the box of region `dc`
    private const CHICAGO = ['lat' => 41.88, 'lng' => -87.63];         // inside the box of region `chi`
    private const DENVER = ['lat' => 39.74, 'lng' => -104.99];         // inside no box

    private const FUEL = ['price_per_gal' => 4.195, 'source' => 'seed', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'];

    /** @var object the in-memory database; see database() */
    private object $db;

    /** @var object the capture provider; `$located` is its next answer, `$calls` what it was asked */
    private object $capture;

    /** @var object the fuel provider; `$trucks` are the truck values it was asked about */
    private object $fuel;

    protected function setUp(): void
    {
        TpCache::wire(new FixedClock('2026-10-05 12:00:00'), new MemoryCache());
        RegionService::forgetVerdicts();
        Registry::reset();

        $this->db = self::database();
        $this->capture = new class implements CaptureProvider {
            /** @var array<string, mixed> */
            public array $located = ['in_region' => false, 'region_id' => null, 'county_fips' => null, 'state' => null];
            /** @var list<array{0: string, 1: float, 2: float}> */
            public array $calls = [];

            public function locate(string $regionId, float $lat, float $lng, ?string $version = null): array
            {
                $this->calls[] = [$regionId, $lat, $lng];
                return $this->located;
            }

            public function capture(string $regionId, float $lat, float $lng, array $visibilities, ?array $host, ?string $version = null): array
            {
                throw new \LogicException('saving a profile never computes vectors');
            }

            public function sources(string $regionId, float $lat, float $lng, ?string $version = null): array
            {
                throw new \LogicException('saving a profile never reads source points');
            }

            public function place(string $regionId, string $placeKey, ?string $version = null): ?array
            {
                throw new \LogicException('saving a profile never reads a place');
            }

            public function hostsNear(string $regionId, float $lat, float $lng, float $radiusM, ?string $version = null): array
            {
                throw new \LogicException('saving a profile never reads hosts');
            }
        };
        $this->fuel = new class(self::FUEL) implements FuelPriceProvider {
            /** @var list<array<string, mixed>> */
            public array $trucks = [];

            /** @param array<string, mixed> $answer */
            public function __construct(private array $answer)
            {
            }

            public function resolve(array $truck): array
            {
                $this->trucks[] = $truck;
                return $this->answer;
            }
        };
        Registry::set('capture', $this->capture);
        Registry::set('fuel', $this->fuel);
    }

    protected function tearDown(): void
    {
        Registry::reset();
        TpCache::wire();
        RegionService::forgetVerdicts();
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * `tp_trucks`, `tp_regions` and `tp_region_packs` in memory. It answers the statements the Truck
     * Planner repositories send and fails on any other.
     */
    private static function database(): Database
    {
        return new class extends Database {
            /** @var array<string, array<string, mixed>> tp_trucks rows by organization id, as MySQL holds them */
            public array $trucks = [];
            /** @var array<string, array<string, mixed>> tp_regions rows by region id */
            public array $regions = [];
            /** @var array<string, array<string, mixed>> tp_region_packs rows by "region|version" */
            public array $packs = [];
            /** @var list<array{sql: string, params: array<int|string, mixed>}> INSERT and UPDATE statements, in order */
            public array $writes = [];
            /** @var list<mixed> the first bound value of every read, in order */
            public array $readKeys = [];
            /** When set, the next INSERT fails with it. With `$landsAnyway` the row is there afterwards. */
            public ?\Throwable $insertFails = null;
            public bool $landsAnyway = false;
            /** When true, the row is gone after the next write (the truck was deleted meanwhile). */
            public bool $vanishes = false;
            private int $second = 0;

            public function __construct()
            {
            }

            public function fetch(string $sql, array $params = []): ?array
            {
                $sql = self::squash($sql);
                $this->readKeys[] = $params[0] ?? null;
                if (str_contains($sql, 'FROM tp_trucks WHERE organization_id = ?')) {
                    return $this->trucks[$params[0]] ?? null;
                }
                if (str_contains($sql, 'FROM tp_regions WHERE region_id = ?')) {
                    return $this->regions[$params[0]] ?? null;
                }
                if (str_contains($sql, 'kernel_json') && str_contains($sql, 'FROM tp_region_packs')) {
                    return $this->packs[$params[0] . '|' . $params[1]] ?? null;
                }
                throw new \LogicException('a read this test does not expect: ' . $sql);
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                $sql = self::squash($sql);
                if (str_contains($sql, 'FROM tp_regions ORDER BY region_id')) {
                    $rows = $this->regions;
                    ksort($rows, SORT_STRING);
                    return array_values($rows);
                }
                throw new \LogicException('a read this test does not expect: ' . $sql);
            }

            public function query(string $sql, array $params = []): \PDOStatement
            {
                $sql = self::squash($sql);
                $this->writes[] = ['sql' => $sql, 'params' => $params];
                if (preg_match('/^INSERT INTO tp_trucks \((.+), created_at, updated_at\) VALUES \(.+, NOW\(\), NOW\(\)\)$/', $sql, $m) === 1) {
                    $row = array_combine(explode(', ', $m[1]), $params);
                    $row['created_at'] = $row['updated_at'] = $this->now();
                    $fails = $this->insertFails;
                    $this->insertFails = null;
                    if ($fails === null && isset($this->trucks[$row['organization_id']])) {
                        $fails = new \PDOException('Duplicate entry for key uk_tptr_org');
                    } elseif ($fails === null || $this->landsAnyway) {
                        $this->trucks[$row['organization_id']] = $row;
                    }
                    if ($fails !== null) {
                        throw $fails;
                    }
                } elseif (preg_match('/^UPDATE tp_trucks SET (.+) WHERE id = \? AND organization_id = \?$/', $sql, $m) === 1) {
                    $orgId = array_pop($params);
                    $id = array_pop($params);
                    $row = $this->trucks[$orgId] ?? null;
                    if ($row !== null && $row['id'] === $id) {
                        $before = $row;
                        foreach (explode(', ', $m[1]) as $i => $set) {
                            $row[substr($set, 0, -strlen(' = ?'))] = $params[$i];
                        }
                        if ($row !== $before) {
                            $row['updated_at'] = $this->now();      // ON UPDATE CURRENT_TIMESTAMP
                        }
                        $this->trucks[$orgId] = $row;
                    }
                } else {
                    throw new \LogicException('a statement this test does not expect: ' . $sql);
                }
                if ($this->vanishes) {
                    $this->trucks = [];
                }
                return new \PDOStatement();
            }

            private function now(): string
            {
                return sprintf('2026-10-05 12:00:%02d', $this->second++);
            }

            private static function squash(string $sql): string
            {
                return trim((string) preg_replace('/\s+/', ' ', $sql));
            }
        };
    }

    /**
     * Adds a region. `usable` has an active dataset built with this server's constants, `mismatch` one
     * built with others, `empty` has no dataset yet.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $box lat_min, lng_min, lat_max, lng_max
     * @param list<string> $counties five-digit codes
     */
    private function region(string $id, array $box, string $zone, array $counties, string $state = 'usable'): void
    {
        $version = $state === 'empty' ? null : $id . '-20261003-3fa9c2d1';
        $config = ['id' => $id, 'counties' => []];
        foreach ($counties as $fips) {
            $config['counties'][] = ['fips' => $fips, 'name' => 'County ' . $fips, 'state' => 'XX'];
        }
        $this->db->regions[$id] = [
            'region_id' => $id, 'name' => 'Region ' . $id, 'cbsa' => null, 'timezone' => $zone, 'h3_res' => 9,
            'bbox_lat_min' => $box[0], 'bbox_lng_min' => $box[1], 'bbox_lat_max' => $box[2], 'bbox_lng_max' => $box[3],
            'center_lat' => ($box[0] + $box[2]) / 2, 'center_lng' => ($box[1] + $box[3]) / 2,
            'active_version' => $version, 'previous_version' => null, 'config_json' => json_encode($config),
            'created_at' => '2026-10-04 20:00:00', 'updated_at' => '2026-10-04 21:00:00',
        ];
        if ($version === null) {
            return;
        }
        $kernel = (new RegionService(new RegionRepository($this->db)))->kernelFromSeeds();
        if ($state === 'mismatch') {
            $kernel['a0'] = 9.9;
        }
        $this->db->packs[$id . '|' . $version] = [
            'region_id' => $id, 'dataset_version' => $version, 'load_state' => 'ready', 'model_version' => Seeds::defaults()['model_version'],
            'pipeline_version' => 'tp-etl-1.0.0', 'osm_snapshot' => '2026-10-03', 'point_count' => 10, 'place_count' => 10,
            'cell_count' => 10, 'pack_format' => 1, 'pack_len' => 1000, 'pack_gz_len' => 500, 'pack_sha256' => str_repeat('9c1f', 16),
            'kernel_json' => json_encode(['seeds_revision' => Seeds::revision(), 'kernel' => $kernel]),
            'vintages_json' => '{"lodes_year": 2023, "osm_snapshot_date": "2026-10-03", "census_reference_date": "2020-04-01"}',
            'loaded_at' => '2026-10-04 20:00:00', 'activated_at' => '2026-10-04 21:00:00',
        ];
        // The verdict on the manifest parameters, as RegionService keeps it for a day (04_BACKEND.md 5.2).
        TpCache::put('tp:regionok:' . $id . ':' . $version . ':' . Seeds::revision(), ['ok' => true], 86400);
    }

    private function dc(string $state = 'usable'): void
    {
        $this->region('dc', [37.9907, -78.3947, 39.7201, -76.6625], 'America/New_York', ['11001', '51107', '51059'], $state);
    }

    private function chi(): void
    {
        $this->region('chi', [41.0, -88.8, 42.6, -87.2], 'America/Chicago', ['17031', '17043']);
    }

    private function service(): ProfileService
    {
        // A new service per request, as in production: the region service remembers what it read.
        return new ProfileService(new TruckRepository($this->db), new RegionService(new RegionRepository($this->db)));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function save(array $body, string $orgId = self::ORG): array
    {
        return $this->service()->upsert($orgId, self::USER, $body);
    }

    /**
     * @param array<string, mixed> $more
     * @param array{lat: float, lng: float} $at
     * @return array<string, mixed>
     */
    private function create(array $more = [], array $at = self::STERLING, string $orgId = self::ORG): array
    {
        return $this->save($more + ['name' => 'Smoke & Ember', 'base' => $at + ['address' => 'Sterling, VA'], 'avg_ticket' => 15], $orgId);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertRefused(string $message, ?string $field, ?string $rule, array $body): void
    {
        $writes = count($this->db->writes);
        try {
            $this->save($body);
        } catch (TpInvalid $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame($field, $e->field());
            self::assertSame($rule, $e->rule());
            self::assertCount($writes, $this->db->writes, 'a refused save writes nothing');
            return;
        }
        self::fail('accepted: ' . json_encode($body));
    }

    /** @return array<string, mixed> the stored row of the organization, as MySQL holds it */
    private function stored(string $orgId = self::ORG): array
    {
        return $this->db->trucks[$orgId];
    }

    // ------------------------------------------------------------------------------------ the first save

    public function testTheFirstSaveCreatesTheTruckWithEveryDefault(): void
    {
        $answer = $this->create();

        self::assertSame(['created', 'truck', 'region', 'fuel', 'warnings'], array_keys($answer));
        self::assertTrue($answer['created']);
        self::assertSame(['id', 'timezone', 'base_state', 'base_county_fips', 'profile', 'created_at', 'updated_at'], array_keys($answer['truck']));
        self::assertSame(
            ['name' => 'Smoke & Ember', 'region_id' => 'none', 'base' => self::STERLING + ['address' => 'Sterling, VA']]
                + ['avg_ticket' => 15.0] + (new ProfileMapper())->defaults(),
            $answer['truck']['profile']
        );
        // No region is loaded: the truck has none, its zone is assumed and the answer says so.
        self::assertSame('America/New_York', $answer['truck']['timezone']);
        self::assertSame([ProfileService::TIMEZONE_ASSUMED], $answer['warnings']);
        self::assertNull($answer['region']);
        self::assertNull($answer['truck']['base_state']);
        self::assertNull($answer['truck']['base_county_fips']);
        self::assertSame(self::FUEL, $answer['fuel']);

        self::assertCount(1, $this->db->writes);
        $row = $this->stored();
        self::assertSame($answer['truck']['id'], $row['id']);
        self::assertSame(self::ORG, $row['organization_id']);
        self::assertSame(self::USER, $row['created_by']);
        self::assertSame(1500, $row['avg_ticket_cents']);
        self::assertSame('{}', $row['overrides_json']);
        self::assertSame(Seeds::revision(), $row['overrides_seeds_rev']);
        self::assertSame('[]', $row['licence_counties_json']);
    }

    public function testTheFuelPriceIsResolvedForTheTruckAsItWasSaved(): void
    {
        $this->create(['fuel_type' => 'diesel']);
        self::assertCount(1, $this->fuel->trucks);
        $truck = $this->fuel->trucks[0];
        self::assertSame(self::ORG, $truck['organization_id']);
        self::assertSame('diesel', $truck['profile']['fuel_type']);
        self::assertSame($this->stored()['id'], $truck['id']);
    }

    public function testANewTruckNeedsItsNameItsBaseAndItsAverageTicket(): void
    {
        $base = self::STERLING;
        $this->assertRefused('name is required', 'name', 'V1', ['base' => $base, 'avg_ticket' => 15]);
        $this->assertRefused('base is required', 'base', 'V1', ['name' => 'T', 'avg_ticket' => 15]);
        $this->assertRefused('avg_ticket is required', 'avg_ticket', 'V1', ['name' => 'T', 'base' => $base]);
        $this->assertRefused(
            'base must have lat between -90 and 90 and lng between -180 and 180',
            'base',
            'V10',
            ['name' => 'T', 'base' => ['lat' => 39.0], 'avg_ticket' => 15]
        );
        $this->assertRefused('avg_ticket must be a number between 1 and 200', 'avg_ticket', 'V2', ['name' => 'T', 'base' => $base, 'avg_ticket' => 0.5]);
        self::assertSame([], $this->db->trucks);
    }

    public function testANewTruckKeepsWhatItSendsAndDefaultsTheRest(): void
    {
        $answer = $this->create([
            'paid_crew' => 3, 'tips_include' => true, 'fuel_price_override' => 3.899,
            'daypart_fit' => ['breakfast' => 0.9], 'scout_drive_minutes_limit' => 30,
        ]);
        $profile = $answer['truck']['profile'];
        self::assertSame(3, $profile['paid_crew']);
        self::assertTrue($profile['tips_include']);
        self::assertSame(3.899, $profile['fuel_price_override']);
        self::assertSame(['breakfast' => 0.9, 'lunch' => 1.0, 'dinner' => 1.0, 'late' => 0.8], $profile['daypart_fit']);
        self::assertSame(30, $profile['scout_drive_minutes_limit']);
        self::assertSame(18.0, $profile['wage_per_hour']);
        self::assertSame(3899, $this->stored()['fuel_price_override_milli']);
    }

    // ------------------------------------------------------------------------------------ region, zone, place

    public function testANewTruckJoinsTheRegionWhoseBoxHoldsItsBase(): void
    {
        $this->chi();
        $this->dc();
        $this->capture->located = ['in_region' => true, 'region_id' => 'dc', 'county_fips' => '51107', 'state' => 'VA'];

        $answer = $this->create();

        self::assertSame('dc', $answer['truck']['profile']['region_id']);
        self::assertSame('America/New_York', $answer['truck']['timezone']);
        self::assertSame('VA', $answer['truck']['base_state']);
        self::assertSame('51107', $answer['truck']['base_county_fips']);
        self::assertSame([], $answer['warnings']);
        self::assertSame([['dc', 39.003, -77.405]], $this->capture->calls);
        // The region of the answer is what the region service says about it.
        self::assertSame((new RegionService(new RegionRepository($this->db)))->info('dc'), $answer['region']);
        self::assertTrue($answer['region']['usable']);

        $answer = $this->create([], self::CHICAGO, self::OTHER_ORG);
        self::assertSame('chi', $answer['truck']['profile']['region_id']);
        self::assertSame('America/Chicago', $answer['truck']['timezone']);
    }

    public function testTheDefaultRegionNeedsNoLoadedData(): void
    {
        // The box alone chooses the default. The region's data may arrive later.
        $this->dc('empty');
        $answer = $this->create();
        self::assertSame('dc', $answer['truck']['profile']['region_id']);
        self::assertFalse($answer['region']['usable']);
        self::assertSame('not_loaded', $answer['region']['unusable_reason']);
    }

    public function testABaseTheDataPlacesOutsideTheRegionIsSavedWithAWarning(): void
    {
        $this->dc();
        // Inside the box, but the nearest census block says otherwise (or there is none): the box is no test.
        $answer = $this->create(['base' => self::STERLING + ['state' => 'va']]);
        self::assertSame('dc', $answer['truck']['profile']['region_id']);
        self::assertSame([ProfileService::BASE_OUTSIDE_REGION], $answer['warnings']);
        // The state the body named stands in while the data cannot tell.
        self::assertSame('VA', $answer['truck']['base_state']);
        self::assertNull($answer['truck']['base_county_fips']);
    }

    public function testTheStateOfTheDataWinsOverTheStateOfTheBody(): void
    {
        $this->dc();
        $this->capture->located = ['in_region' => true, 'region_id' => 'dc', 'county_fips' => '51107', 'state' => 'VA'];
        $answer = $this->create(['base' => self::STERLING + ['state' => 'MD']]);
        self::assertSame('VA', $answer['truck']['base_state']);
    }

    public function testATruckOutsideEveryBoxHasNoRegionAndTheZoneItNames(): void
    {
        $this->dc();
        $answer = $this->create(['timezone' => 'America/Denver', 'base' => self::DENVER + ['state' => 'CO']], self::DENVER);
        self::assertSame('none', $answer['truck']['profile']['region_id']);
        self::assertSame('America/Denver', $answer['truck']['timezone']);
        self::assertSame('CO', $answer['truck']['base_state']);
        self::assertSame([], $answer['warnings']);
        self::assertNull($answer['region']);
        self::assertSame([['none', 39.74, -104.99]], $this->capture->calls);
    }

    public function testTheZoneOfTheBodyIsReadOnlyWithoutARegion(): void
    {
        $this->dc();
        $answer = $this->create(['timezone' => 'America/Denver']);
        self::assertSame('dc', $answer['truck']['profile']['region_id']);
        self::assertSame('America/New_York', $answer['truck']['timezone']);
    }

    public function testARegionCanBeChosen(): void
    {
        $this->dc();
        $this->chi();
        // `none`, although the base lies in a box.
        $answer = $this->create(['region_id' => 'none']);
        self::assertSame('none', $answer['truck']['profile']['region_id']);
        self::assertSame([ProfileService::TIMEZONE_ASSUMED], $answer['warnings']);
        // A usable region, although the base lies outside its box.
        $answer = $this->create(['region_id' => 'chi'], self::STERLING, self::OTHER_ORG);
        self::assertSame('chi', $answer['truck']['profile']['region_id']);
        self::assertSame('America/Chicago', $answer['truck']['timezone']);
        self::assertSame([ProfileService::BASE_OUTSIDE_REGION], $answer['warnings']);
    }

    public function testARegionThatCannotBeUsedIsNotFound(): void
    {
        $this->dc('mismatch');
        $this->region('new', [10.0, 10.0, 11.0, 11.0], 'UTC', [], 'empty');
        $body = ['name' => 'T', 'base' => self::STERLING, 'avg_ticket' => 15];
        $this->assertRefused('region_id was not found', 'region_id', 'V11', $body + ['region_id' => 'atlantis']);
        $this->assertRefused('region_id was not found', 'region_id', 'V11', $body + ['region_id' => 'dc']);
        $this->assertRefused('region_id was not found', 'region_id', 'V11', $body + ['region_id' => 'new']);
        $this->assertRefused('region_id was not found', 'region_id', 'V11', $body + ['region_id' => '']);
    }

    public function testTextThatCannotBeARegionIdIsNotLookedUp(): void
    {
        // MySQL refuses to compare text outside ASCII with the id column: such text must not reach it.
        foreach (["caf\u{e9}", "dc\u{1F69A}", 'two words'] as $id) {
            $this->db->readKeys = [];
            $this->assertRefused('region_id was not found', 'region_id', 'V11', ['name' => 'T', 'base' => self::STERLING, 'avg_ticket' => 15, 'region_id' => $id]);
            self::assertNotContains($id, $this->db->readKeys);
        }
    }

    public function testLicenceCountiesBelongToTheRegion(): void
    {
        $this->dc();
        $answer = $this->create(['licence_counties' => ['51107', '11001', '51107']]);
        self::assertSame(['51107', '11001'], $answer['truck']['profile']['licence_counties']);
        self::assertSame('["51107","11001"]', $this->stored()['licence_counties_json']);

        // The index is the item's place in the list as it was sent.
        $this->assertRefused(
            'licence_counties[2] is not a county of this region',
            'licence_counties[2]',
            null,
            ['licence_counties' => ['51107', '51107', '17031']]
        );
        $this->assertRefused('licence_counties[0] must be a 5-digit county code', 'licence_counties[0]', null, ['licence_counties' => ['5110']]);
        self::assertSame(['51107', '11001'], $this->save(['name' => 'Same counties'])['truck']['profile']['licence_counties']);
    }

    public function testATruckWithoutARegionTakesAnyCountyCode(): void
    {
        $answer = $this->create(['licence_counties' => ['17031', '06037']], self::DENVER);
        self::assertSame(['17031', '06037'], $answer['truck']['profile']['licence_counties']);
    }

    // ------------------------------------------------------------------------------------ later saves

    public function testALaterSaveChangesOnlyTheFieldsItCarries(): void
    {
        $this->dc();
        $this->capture->located = ['in_region' => true, 'region_id' => 'dc', 'county_fips' => '51107', 'state' => 'VA'];
        $before = $this->create()['truck'];
        $this->capture->calls = [];

        $answer = $this->save(['avg_ticket' => 12.5]);

        self::assertFalse($answer['created']);
        self::assertSame([], $answer['warnings']);
        self::assertSame(
            ['sql' => 'UPDATE tp_trucks SET avg_ticket_cents = ? WHERE id = ? AND organization_id = ?', 'params' => [1250, $before['id'], self::ORG]],
            $this->db->writes[1]
        );
        $expected = $before;
        $expected['profile']['avg_ticket'] = 12.5;
        $expected['updated_at'] = $answer['truck']['updated_at'];
        self::assertSame($expected, $answer['truck']);
        self::assertNotSame($before['updated_at'], $answer['truck']['updated_at']);
        // Nothing about the place of the base changed, so the region data was not asked.
        self::assertSame([], $this->capture->calls);
        self::assertSame('dc', $answer['region']['region_id']);
        self::assertSame(self::FUEL, $answer['fuel']);
    }

    public function testMoneyIsStoredInCentsAndReadsBackExactly(): void
    {
        $this->create();
        $answer = $this->save([
            'avg_ticket' => 12.34, 'wage_per_hour' => 17.29, 'packaging_per_order' => 0.29, 'card_fee_fixed' => 0.07,
            'fixed_cost_per_service_day' => 1.15, 'fuel_price_override' => 4.195,
        ]);
        $row = $this->stored();
        self::assertSame([1234, 1729, 29, 7, 115, 4195], [
            $row['avg_ticket_cents'], $row['wage_cents'], $row['packaging_cents'], $row['card_fee_fixed_cents'],
            $row['fixed_cost_day_cents'], $row['fuel_price_override_milli'],
        ]);
        $profile = $answer['truck']['profile'];
        self::assertSame(12.34, $profile['avg_ticket']);
        self::assertSame(17.29, $profile['wage_per_hour']);
        self::assertSame(0.29, $profile['packaging_per_order']);
        self::assertSame(0.07, $profile['card_fee_fixed']);
        self::assertSame(1.15, $profile['fixed_cost_per_service_day']);
        self::assertSame(4.195, $profile['fuel_price_override']);

        // Every whole-cent amount in the range of the average ticket survives the round trip.
        for ($cents = 100; $cents <= 20000; $cents += 37) {
            $dollars = $cents / 100.0;
            self::assertSame($dollars, $this->save(['avg_ticket' => $dollars])['truck']['profile']['avg_ticket']);
            self::assertSame($cents, $this->stored()['avg_ticket_cents']);
        }
        // An amount finer than a cent is rounded, and the answer shows what was stored.
        self::assertSame(12.35, $this->save(['avg_ticket' => 12.345])['truck']['profile']['avg_ticket']);
        self::assertNull($this->save(['fuel_price_override' => null])['truck']['profile']['fuel_price_override']);
    }

    public function testANestedObjectChangesOnlyTheKeysItCarries(): void
    {
        $this->create();
        $this->capture->calls = [];

        $answer = $this->save(['daypart_fit' => ['late' => 0.5], 'base' => ['address' => '21000 Atlantic Blvd']]);

        self::assertSame(
            'UPDATE tp_trucks SET base_address = ?, fit_late = ? WHERE id = ? AND organization_id = ?',
            $this->db->writes[1]['sql']
        );
        $profile = $answer['truck']['profile'];
        self::assertSame(['breakfast' => 0.3, 'lunch' => 1.0, 'dinner' => 1.0, 'late' => 0.5], $profile['daypart_fit']);
        self::assertSame(self::STERLING + ['address' => '21000 Atlantic Blvd'], $profile['base']);
        self::assertSame([], $this->capture->calls);
    }

    public function testASaveThatCarriesNothingKnownIsRefused(): void
    {
        $this->create();
        $this->assertRefused('Nothing to update', null, 'V12', ['colour' => 'red']);
        $this->assertRefused('Nothing to update', null, 'V12', []);
    }

    public function testASaveThatChangesNothingSendsNoStatement(): void
    {
        $this->dc();
        $before = $this->create()['truck'];
        // The zone of the body is not read while the truck has a region.
        $answer = $this->save(['timezone' => 'America/Denver']);
        self::assertCount(1, $this->db->writes);
        self::assertFalse($answer['created']);
        self::assertSame($before, $answer['truck']);
    }

    public function testTheRegionFollowsABaseThatIsSaved(): void
    {
        $this->dc();
        $this->chi();
        $created = $this->create(['timezone' => 'America/Denver', 'licence_counties' => ['51107', '17031']], self::DENVER);
        self::assertSame('none', $created['truck']['profile']['region_id']);

        // Into a region: its zone, its counties, and the place the data gives.
        $this->capture->located = ['in_region' => true, 'region_id' => 'dc', 'county_fips' => '51107', 'state' => 'VA'];
        $answer = $this->save(['base' => self::STERLING]);
        self::assertSame('dc', $answer['truck']['profile']['region_id']);
        self::assertSame('America/New_York', $answer['truck']['timezone']);
        self::assertSame('VA', $answer['truck']['base_state']);
        self::assertSame('51107', $answer['truck']['base_county_fips']);
        self::assertSame(['51107'], $answer['truck']['profile']['licence_counties']);
        self::assertSame([], $answer['warnings']);
        self::assertSame('dc', $answer['region']['region_id']);

        // Out of every box again: no region. The zone stays, because nothing better is known, and the
        // place of the old point is forgotten.
        $this->capture->located = ['in_region' => false, 'region_id' => null, 'county_fips' => null, 'state' => null];
        $answer = $this->save(['base' => self::DENVER]);
        self::assertSame('none', $answer['truck']['profile']['region_id']);
        self::assertSame('America/New_York', $answer['truck']['timezone']);
        self::assertNull($answer['truck']['base_state']);
        self::assertNull($answer['truck']['base_county_fips']);
        self::assertSame(['51107'], $answer['truck']['profile']['licence_counties']);
        self::assertSame([], $answer['warnings']);
        self::assertNull($answer['region']);
    }

    public function testAChosenRegionSurvivesAMoveInsideItsBox(): void
    {
        // Falls Church lies in two boxes. By default a truck there joins `dc`, the first in id order.
        $this->dc();
        $this->region('fc', [38.87, -77.2, 38.9, -77.14], 'America/New_York', ['51610']);
        $fallsChurch = ['lat' => 38.885, 'lng' => -77.17];
        self::assertSame('dc', $this->create([], $fallsChurch, self::OTHER_ORG)['truck']['profile']['region_id']);

        // A truck that chose `fc` keeps it while its base stays inside the box of `fc`.
        self::assertSame('fc', $this->create(['region_id' => 'fc'], $fallsChurch)['truck']['profile']['region_id']);
        $answer = $this->save(['base' => ['lat' => 38.89, 'lng' => -77.16]]);
        self::assertSame('fc', $answer['truck']['profile']['region_id']);
        self::assertSame(38.89, $answer['truck']['profile']['base']['lat']);
        // The edge of the box belongs to it.
        self::assertSame('fc', $this->save(['base' => ['lat' => 38.9, 'lng' => -77.14]])['truck']['profile']['region_id']);

        // Outside that box, be it by latitude alone or by longitude alone, the default applies again.
        self::assertSame('dc', $this->save(['base' => ['lat' => 38.95, 'lng' => -77.17]])['truck']['profile']['region_id']);
        self::assertSame('fc', $this->save(['region_id' => 'fc', 'base' => $fallsChurch])['truck']['profile']['region_id']);
        self::assertSame('dc', $this->save(['base' => ['lat' => 38.885, 'lng' => -77.1]])['truck']['profile']['region_id']);
        // And a truck in `dc` that comes back to Falls Church stays in `dc`: its box holds the point too.
        self::assertSame('dc', $this->save(['base' => $fallsChurch])['truck']['profile']['region_id']);
    }

    public function testWithoutABasePointTheRegionStays(): void
    {
        $this->dc();
        $this->create(['region_id' => 'none']);
        $answer = $this->save(['name' => 'Renamed', 'base' => ['address' => 'Somewhere else']]);
        self::assertSame('none', $answer['truck']['profile']['region_id']);
        self::assertSame('Renamed', $answer['truck']['profile']['name']);
    }

    public function testAPointThatDidNotMoveKeepsItsPlaceWhenTheDataCannotTell(): void
    {
        $this->dc();
        $this->capture->located = ['in_region' => true, 'region_id' => 'dc', 'county_fips' => '51107', 'state' => 'VA'];
        $this->create();

        // The same point again while the region data gives no answer: what was known stays.
        $this->capture->located = ['in_region' => false, 'region_id' => null, 'county_fips' => null, 'state' => null];
        $answer = $this->save(['base' => self::STERLING]);
        self::assertSame('VA', $answer['truck']['base_state']);
        self::assertSame('51107', $answer['truck']['base_county_fips']);
        self::assertSame([ProfileService::BASE_OUTSIDE_REGION], $answer['warnings']);

        // Another point: the old place says nothing about it. The state of the body stands in.
        $answer = $this->save(['base' => ['lat' => 38.5, 'lng' => -77.3, 'state' => 'md']]);
        self::assertSame('MD', $answer['truck']['base_state']);
        self::assertNull($answer['truck']['base_county_fips']);
        $answer = $this->save(['base' => ['lat' => 38.6, 'lng' => -77.3]]);
        self::assertNull($answer['truck']['base_state']);
    }

    public function testAStateAloneIsUsedOnlyWhenTheDataCannotTell(): void
    {
        $this->create([], self::DENVER);
        $this->capture->calls = [];
        $answer = $this->save(['base' => ['state' => 'co']]);
        self::assertSame('CO', $answer['truck']['base_state']);
        self::assertSame([['none', 39.74, -104.99]], $this->capture->calls);
        $this->assertRefused('base.state must be a two-letter state code', 'base.state', null, ['base' => ['state' => 'C1']]);
    }

    public function testTheTrucksPresentRegionIsAlwaysAccepted(): void
    {
        $this->dc();
        $this->capture->located = ['in_region' => true, 'region_id' => 'dc', 'county_fips' => '51107', 'state' => 'VA'];
        $this->create();
        // The region data is being rebuilt. A client that sends the whole profile back can still save.
        $this->dc('mismatch');
        RegionService::forgetVerdicts();
        $answer = $this->save(['region_id' => 'dc', 'avg_ticket' => 16]);
        self::assertSame('dc', $answer['truck']['profile']['region_id']);
        self::assertSame(16.0, $answer['truck']['profile']['avg_ticket']);
        self::assertFalse($answer['region']['usable']);
        self::assertSame('build_mismatch', $answer['region']['unusable_reason']);
    }

    public function testATruckWithoutARegionCanChangeItsZone(): void
    {
        $this->create([], self::DENVER);
        $answer = $this->save(['timezone' => 'America/Denver']);
        self::assertSame('America/Denver', $answer['truck']['timezone']);
        self::assertSame([], $answer['warnings']);
        // Without a zone in the body the truck keeps the one it has, and nothing is assumed again.
        $answer = $this->save(['avg_ticket' => 14]);
        self::assertSame('America/Denver', $answer['truck']['timezone']);
        self::assertSame([], $answer['warnings']);
        $this->assertRefused('timezone must be an IANA time zone name', 'timezone', null, ['timezone' => 'Mars/Olympus']);
    }

    public function testAChosenRegionReplacesTheCountiesThatAreNotItsOwn(): void
    {
        $this->dc();
        $this->chi();
        $this->create(['licence_counties' => ['51107', '11001']]);
        // The counties of the body are checked against the region of the same body.
        $answer = $this->save(['region_id' => 'chi', 'licence_counties' => ['17031']]);
        self::assertSame(['17031'], $answer['truck']['profile']['licence_counties']);
        // Without a list, the stored codes that are not counties of the new region are dropped.
        $answer = $this->save(['region_id' => 'dc']);
        self::assertSame([], $answer['truck']['profile']['licence_counties']);
        $this->assertRefused('licence_counties[0] is not a county of this region', 'licence_counties[0]', null, ['region_id' => 'chi', 'licence_counties' => ['51107']]);
    }

    // ------------------------------------------------------------------------------------ tenants and races

    public function testEachOrganizationHasItsOwnTruck(): void
    {
        $first = $this->create()['truck'];
        $second = $this->create(['name' => 'Second truck'], self::STERLING, self::OTHER_ORG)['truck'];
        self::assertNotSame($first['id'], $second['id']);

        $this->save(['name' => 'Renamed by the second'], self::OTHER_ORG);

        self::assertSame('Smoke & Ember', $this->stored(self::ORG)['name']);
        self::assertSame('Renamed by the second', $this->stored(self::OTHER_ORG)['name']);
        // Every statement names the organization of its caller, last.
        self::assertSame(self::OTHER_ORG, $this->db->writes[2]['params'][2]);
        self::assertStringEndsWith('WHERE id = ? AND organization_id = ?', $this->db->writes[2]['sql']);
        self::assertSame($first, $this->save(['name' => 'Smoke & Ember'], self::ORG)['truck']);
    }

    public function testTwoFirstSavesAtOnceBothSucceed(): void
    {
        // The other request's INSERT landed between this one's read and its own INSERT.
        $this->db->insertFails = new \PDOException('Duplicate entry for key uk_tptr_org');
        $this->db->landsAnyway = true;

        $answer = $this->create(['paid_crew' => 4]);

        self::assertFalse($answer['created']);
        self::assertSame(4, $answer['truck']['profile']['paid_crew']);
        self::assertStringStartsWith('INSERT INTO tp_trucks', $this->db->writes[0]['sql']);
        self::assertStringStartsWith('UPDATE tp_trucks SET', $this->db->writes[1]['sql']);
    }

    public function testAnInsertThatFailsForAnotherReasonIsNotSwallowed(): void
    {
        $this->db->insertFails = new \PDOException('server has gone away');
        $this->expectException(\PDOException::class);
        $this->expectExceptionMessage('server has gone away');
        $this->create();
    }

    public function testATruckDeletedDuringTheSaveAnswersLikeNoTruck(): void
    {
        $this->create();
        $this->db->vanishes = true;
        try {
            $this->save(['avg_ticket' => 11]);
            self::fail('answered although the truck is gone');
        } catch (TpConflict $e) {
            self::assertSame('Set up your truck first', $e->getMessage());
        }
    }
}
