<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\MemoryCache;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\RegionRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\RegionService;
use App\TruckPlanner\Services\Support\TpCache;
use PHPUnit\Framework\TestCase;

final class RegionServiceTest extends TestCase
{
    private const VERSION = 'dc-20261003-3fa9c2d1';

    private FixedClock $clock;
    private MemoryCache $store;

    protected function setUp(): void
    {
        $this->clock = new FixedClock('2026-10-04 12:00:00');
        $this->store = new MemoryCache();
        TpCache::wire($this->clock, $this->store);
        RegionService::forgetVerdicts();
    }

    protected function tearDown(): void
    {
        TpCache::wire();
        RegionService::forgetVerdicts();
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * The kernel block of 03_DATA.md section 11 at seeds revision 1, written by hand.
     *
     * @return array<string, mixed>
     */
    private static function kernel(): array
    {
        return [
            'earth_radius_m' => 6371008.8, 'walk_decay_m' => 400, 'walk_cutoff_m' => 1200, 'a0' => 1.6, 'visibility' => 1,
            'regime_of_hour' => ['eve', 'eve', 'eve', 'eve', 'eve', 'day', 'day', 'day', 'day', 'day', 'day', 'day',
                'day', 'day', 'day', 'day', 'eve', 'eve', 'eve', 'eve', 'eve', 'eve', 'eve', 'eve'],
            'rival_weights' => [
                'day' => ['quick' => 1, 'full' => 0.6, 'cafe' => 0.5, 'bar' => 0.1, 'convenience' => 0.5],
                'eve' => ['quick' => 1, 'full' => 1, 'cafe' => 0.2, 'bar' => 0.6, 'convenience' => 0.4],
            ],
        ];
    }

    /**
     * `parameters` of a manifest as the pipeline writes it from the seed file (03_DATA.md 8.5).
     *
     * @return array<string, mixed>
     */
    private static function parameters(): array
    {
        $A = Seeds::defaults();
        $sectors = [];
        foreach (['w_office', 'w_health', 'w_edu', 'w_retail', 'w_industrial', 'w_hospitality', 'w_public'] as $segment) {
            $sectors[$segment] = Estimator::seed($A, 'segments.' . $segment . '.lodes_cns');
        }
        $types = [];
        foreach (Estimator::seed($A, 'vocabulary.place_types') as $type) {
            $row = Estimator::seed($A, 'place_types.rows.' . $type);
            $types[$type] = [
                'visitor_segment' => $row['visitor_segment'], 'default_size' => $row['default_size'],
                'rival_kind' => $row['rival_kind'], 'host_fit' => $row['host_fit'], 'kitchen_default' => $row['kitchen_default'],
            ];
        }
        return [
            'walk_decay_m' => 400, 'walk_cutoff_m' => 1200, 'earth_radius_m' => 6371008.8, 'cns04_weight' => 0.3,
            'cell_min_nearby' => 100, 'cell_min_venue' => 15, 'segment_cns' => $sectors, 'place_types' => $types,
            'h3_res' => 9, 'job_review' => ['total_min' => 5000, 'single_sector_min' => 2000, 'single_sector_share' => 0.9],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function regionRow(string $id = 'dc', ?string $active = self::VERSION): array
    {
        $config = [
            'id' => $id, 'traffic_matrix' => 'dc',
            'states' => [['usps' => 'dc', 'fips' => '11'], ['usps' => 'va', 'fips' => '51']],
            'census' => ['reference_date' => '2020-04-01'], 'lodes' => ['year' => 2023],
            'fuel_area_by_state' => ['DC' => 'R1Y', 'MD' => 'R1Y', 'VA' => 'R1Z', 'WV' => 'R1Z'],
            'holidays' => ['inauguration_day' => true],
            'counties' => [
                ['fips' => '11001', 'name' => 'District of Columbia', 'state' => 'DC', 'residents' => 689545, 'blocks' => 6012],
                ['fips' => '51107', 'name' => 'Loudoun County', 'state' => 'VA', 'residents' => 420959, 'blocks' => 5882],
            ],
        ];
        return [
            'region_id' => $id, 'name' => 'Washington, DC region', 'cbsa' => '47900', 'timezone' => 'America/New_York',
            'h3_res' => 9, 'bbox_lat_min' => 37.9907, 'bbox_lng_min' => -78.3947, 'bbox_lat_max' => 39.7201,
            'bbox_lng_max' => -76.6625, 'center_lat' => 38.9072, 'center_lng' => -77.0369,
            'active_version' => $active, 'previous_version' => null, 'config_json' => json_encode($config),
            'created_at' => '2026-10-04 20:00:00', 'updated_at' => '2026-10-04 21:00:00',
        ];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function packRow(array $changes = []): array
    {
        return $changes + [
            'region_id' => 'dc', 'dataset_version' => self::VERSION, 'load_state' => 'ready', 'model_version' => 'tps-0.1.0',
            'pipeline_version' => 'tp-etl-1.0.0', 'osm_snapshot' => '2026-10-03', 'point_count' => 60678,
            'place_count' => 25276, 'cell_count' => 61460, 'pack_format' => 1, 'pack_len' => 6638000,
            'pack_gz_len' => 3410000, 'pack_sha256' => str_repeat('9c1f', 16),
            // MySQL gives a JSON column back with its keys reordered and 400.0 printed as 400
            'kernel_json' => json_encode(['seeds_revision' => 1, 'kernel' => array_reverse(self::kernel(), true)]),
            'vintages_json' => '{"lodes_year": 2023, "lodes_format": "8.4", "osm_snapshot_date": "2026-10-03", "census_reference_date": "2020-04-01"}',
            'loaded_at' => '2026-10-04 20:00:00', 'activated_at' => '2026-10-04 21:00:00',
        ];
    }

    /**
     * A service over a database that holds one region, its pack row and its manifest.
     *
     * @param array<string, mixed>|null $region
     * @param array<string, mixed>|null $pack
     * @param array<string, mixed>|null $parameters
     */
    private function service(?array $region, ?array $pack, ?array $parameters, ?RecordingDatabase &$db = null): RegionService
    {
        $db = new RecordingDatabase();
        $db->when('FROM tp_regions WHERE region_id = ?', $region);
        $db->when('FROM tp_regions ORDER BY region_id', $region === null ? [] : [$region]);
        $db->when('SELECT manifest_json FROM tp_region_packs', $parameters === null ? null : ['manifest_json' => json_encode(['schema' => 1, 'parameters' => $parameters])]);
        $db->when('FROM tp_region_packs', $pack);
        return new RegionService(new RegionRepository($db));
    }

    // ------------------------------------------------------------------------------------ the kernel

    public function testKernelFromSeedsIsTheKernelBlockOfThePackHeader(): void
    {
        $kernel = (new RegionService(new RegionRepository(new RecordingDatabase())))->kernelFromSeeds();
        self::assertSame(
            ['earth_radius_m', 'walk_decay_m', 'walk_cutoff_m', 'a0', 'visibility', 'regime_of_hour', 'rival_weights'],
            array_keys($kernel)
        );
        self::assertSame(['day', 'eve'], array_keys($kernel['rival_weights']));
        self::assertSame(['quick', 'full', 'cafe', 'bar', 'convenience'], array_keys($kernel['rival_weights']['day']));
        // the header of 03_DATA.md section 11, to the letter
        self::assertSame(
            '{"earth_radius_m":6371008.8,"walk_decay_m":400,"walk_cutoff_m":1200,"a0":1.6,"visibility":1,'
            . '"regime_of_hour":["eve","eve","eve","eve","eve","day","day","day","day","day","day","day","day","day","day","day","eve","eve","eve","eve","eve","eve","eve","eve"],'
            . '"rival_weights":{"day":{"quick":1,"full":0.6,"cafe":0.5,"bar":0.1,"convenience":0.5},'
            . '"eve":{"quick":1,"full":1,"cafe":0.2,"bar":0.6,"convenience":0.4}}}',
            json_encode($kernel)
        );
        foreach (['earth_radius_m', 'walk_decay_m', 'walk_cutoff_m', 'a0', 'visibility'] as $key) {
            self::assertIsFloat($kernel[$key]);
        }
    }

    public function testKernelMatchesComparesDecodedStructures(): void
    {
        $service = new RegionService(new RegionRepository(new RecordingDatabase()));
        self::assertTrue($service->kernelMatches(self::kernel()));
        // key order and 400 against 400.0 make no difference
        $reordered = array_reverse(self::kernel(), true);
        $reordered['walk_decay_m'] = 400.0;
        $reordered['rival_weights']['eve'] = array_reverse($reordered['rival_weights']['eve'], true);
        self::assertTrue($service->kernelMatches($reordered));
        self::assertTrue($service->kernelMatches(json_decode((string) json_encode($service->kernelFromSeeds()), true)));
        self::assertFalse($service->kernelMatches(null), 'a pack row without a recorded kernel');
    }

    public function testANumberOneUnitInTheLastPlaceOffIsNotAnotherBuild(): void
    {
        // MySQL 8 hands about one in nine 16- or 17-digit doubles back from a JSON column one unit in the
        // last place off (0.20000010000000001 comes back as 0.2000001). That is not another kernel.
        $service = new RegionService(new RegionRepository(new RecordingDatabase()));
        $kernel = self::kernel();
        $kernel['earth_radius_m'] = 6371008.800000001;                // the next double above 6371008.8
        $kernel['rival_weights']['day']['full'] = 0.6000000000000001; // the next double above 0.6
        self::assertNotSame(6371008.8, $kernel['earth_radius_m']);
        self::assertNotSame(0.6, $kernel['rival_weights']['day']['full']);
        self::assertTrue($service->kernelMatches($kernel));

        $kernel['rival_weights']['day']['full'] = 0.6000000000000005; // five units off: a different value
        self::assertFalse($service->kernelMatches($kernel));
    }

    public function testAnyDifferenceInTheKernelIsAMismatch(): void
    {
        $service = new RegionService(new RegionRepository(new RecordingDatabase()));
        $changes = [
            static function (array &$k): void { $k['a0'] = 1.7; },
            static function (array &$k): void { $k['walk_decay_m'] = 400.0000001; },
            static function (array &$k): void { $k['rival_weights']['day']['full'] = 0.7; },
            static function (array &$k): void { $k['regime_of_hour'][5] = 'eve'; },
            static function (array &$k): void { $k['regime_of_hour'] = array_slice($k['regime_of_hour'], 0, 23); },
            static function (array &$k): void { unset($k['visibility']); },
            static function (array &$k): void { $k['extra'] = 1; },
            static function (array &$k): void { unset($k['rival_weights']['eve']['bar']); },
            static function (array &$k): void { $k['rival_weights']['eve']['street'] = 0.3; },
            static function (array &$k): void { $k['a0'] = '1.6'; },
            static function (array &$k): void { $k['visibility'] = true; },
            static function (array &$k): void { $k = []; },
        ];
        foreach ($changes as $i => $change) {
            $kernel = self::kernel();
            $change($kernel);
            self::assertFalse($service->kernelMatches($kernel), 'change ' . $i . ' went unnoticed');
        }
    }

    public function testBuildScopeMatchesNeedsTheKernelAndEveryRecordedSeedValue(): void
    {
        $service = new RegionService(new RegionRepository(new RecordingDatabase()));
        self::assertTrue($service->buildScopeMatches(self::kernel(), self::parameters()));

        // what is not a seed is not compared
        $other = self::parameters();
        $other['h3_res'] = 8;
        $other['job_review']['total_min'] = 1;
        $other['walk_cutoff_m'] = 1200.0;
        self::assertTrue($service->buildScopeMatches(self::kernel(), $other));

        $changes = [
            static function (array &$p): void { $p['walk_decay_m'] = 450; },
            static function (array &$p): void { $p['cns04_weight'] = 0.25; },
            static function (array &$p): void { $p['cell_min_venue'] = 10; },
            static function (array &$p): void { unset($p['earth_radius_m']); },
            static function (array &$p): void { $p['segment_cns']['w_office'][] = 'CNS04'; },
            static function (array &$p): void { $p['segment_cns']['w_office'] = array_reverse($p['segment_cns']['w_office']); },
            static function (array &$p): void { unset($p['segment_cns']['w_public']); },
            static function (array &$p): void { $p['place_types']['taproom']['default_size'] = 41; },
            static function (array &$p): void { $p['place_types']['bar']['rival_kind'] = null; },
            static function (array &$p): void { $p['place_types']['gym']['kitchen_default'] = 'yes'; },
            static function (array &$p): void { unset($p['place_types']['car_dealership']); },
            static function (array &$p): void { $p['place_types']['food_hall'] = $p['place_types']['cafe']; },
            static function (array &$p): void { unset($p['place_types']); },
        ];
        foreach ($changes as $i => $change) {
            $parameters = self::parameters();
            $change($parameters);
            self::assertFalse($service->buildScopeMatches(self::kernel(), $parameters), 'change ' . $i . ' went unnoticed');
        }

        $kernel = self::kernel();
        $kernel['a0'] = 2.0;
        self::assertFalse($service->buildScopeMatches($kernel, self::parameters()));
    }

    public function testParameterDifferencesNamesWhatDiffersInTheRecordedOrder(): void
    {
        $service = new RegionService(new RegionRepository(new RecordingDatabase()));
        self::assertSame([], $service->parameterDifferences(self::parameters()));

        $changed = self::parameters();
        $changed['place_types']['taproom']['default_size'] = 41;
        $changed['walk_cutoff_m'] = 1000;
        unset($changed['cns04_weight']);
        self::assertSame(['walk_cutoff_m', 'cns04_weight', 'place_types'], $service->parameterDifferences($changed));
        self::assertFalse($service->buildScopeMatches(self::kernel(), $changed));

        // 400 and 400.0 are one number, key order says nothing, and one unit in the last place is the same build
        $same = array_reverse(self::parameters(), true);
        $same['walk_decay_m'] = 400.0;
        $same['earth_radius_m'] = 6371008.800000001;
        self::assertSame([], $service->parameterDifferences($same));

        self::assertSame(
            ['walk_decay_m', 'walk_cutoff_m', 'earth_radius_m', 'cns04_weight', 'cell_min_nearby', 'cell_min_venue', 'segment_cns', 'place_types'],
            $service->parameterDifferences([]),
            'a manifest without parameters differs in every one'
        );
    }

    // ------------------------------------------------------------------------------------ region info

    public function testAUsableRegion(): void
    {
        $info = $this->service(self::regionRow(), self::packRow(), self::parameters())->info('dc');
        self::assertSame(
            [
                'region_id' => 'dc',
                'name' => 'Washington, DC region',
                'timezone' => 'America/New_York',
                'h3_res' => 9,
                'center' => ['lat' => 38.9072, 'lng' => -77.0369],
                'bbox' => ['lat_min' => 37.9907, 'lng_min' => -78.3947, 'lat_max' => 39.7201, 'lng_max' => -76.6625],
                'dataset_version' => self::VERSION,
                'usable' => true,
                'unusable_reason' => null,
                'pack' => [
                    'url' => '/api/truck/regions/dc/pack/' . self::VERSION,
                    'format_version' => 1,
                    'bytes' => 6638000,
                    'gz_bytes' => 3410000,
                    'sha256' => str_repeat('9c1f', 16),
                    'cell_count' => 61460,
                ],
                'vintages' => ['census_reference_date' => '2020-04-01', 'lodes_year' => 2023, 'osm_snapshot_date' => '2026-10-03'],
                'counties' => [
                    ['fips' => '11001', 'name' => 'District of Columbia', 'state' => 'DC'],
                    ['fips' => '51107', 'name' => 'Loudoun County', 'state' => 'VA'],
                ],
            ],
            $info
        );
    }

    public function testARegionWithoutAReadyActiveVersionIsNotLoaded(): void
    {
        $cases = [
            'no active version' => [self::regionRow('dc', null), null],
            'the pack row is gone' => [self::regionRow(), null],
            'still loading' => [self::regionRow(), self::packRow(['load_state' => 'loading', 'kernel_json' => null, 'vintages_json' => null])],
            'the load failed' => [self::regionRow(), self::packRow(['load_state' => 'failed'])],
        ];
        foreach ($cases as $name => [$region, $pack]) {
            RegionService::forgetVerdicts();
            $service = $this->service($region, $pack, self::parameters());
            $info = $service->info('dc');
            self::assertFalse($info['usable'], $name);
            self::assertSame('not_loaded', $info['unusable_reason'], $name);
            self::assertNull($info['pack'], $name);
            self::assertNull($info['vintages'], $name);
            self::assertSame($region['active_version'], $info['dataset_version'], $name);
            self::assertCount(2, $info['counties'], $name);
            // nothing can be read from such a region: readers get null, like region none
            self::assertNull($service->active('dc'), $name);
        }
    }

    public function testAReadyVersionBuiltWithOtherConstantsIsABuildMismatch(): void
    {
        $otherKernel = self::kernel();
        $otherKernel['a0'] = 1.8;
        $otherParameters = self::parameters();
        $otherParameters['cns04_weight'] = 0.5;
        $cases = [
            'another model version' => [self::packRow(['model_version' => 'tps-0.2.0']), self::parameters()],
            'another kernel' => [self::packRow(['kernel_json' => json_encode(['seeds_revision' => 2, 'kernel' => $otherKernel])]), self::parameters()],
            'no kernel recorded' => [self::packRow(['kernel_json' => null]), self::parameters()],
            'other manifest parameters' => [self::packRow(), $otherParameters],
            'no manifest' => [self::packRow(), null],
        ];
        foreach ($cases as $name => [$pack, $parameters]) {
            RegionService::forgetVerdicts();
            $this->store->values = [];
            $service = $this->service(self::regionRow(), $pack, $parameters);
            $info = $service->info('dc');
            self::assertFalse($info['usable'], $name);
            self::assertSame('build_mismatch', $info['unusable_reason'], $name);
            self::assertNull($info['pack'], $name);
            self::assertSame('2026-10-03', $info['vintages']['osm_snapshot_date'], $name);

            // the rows exist: a reader may locate a point, and must not compute vectors
            $active = $service->active('dc');
            self::assertIsArray($active, $name);
            self::assertFalse($active['usable'], $name);
            self::assertSame('build_mismatch', $active['unusable_reason'], $name);
            self::assertSame(self::VERSION, $active['dataset_version'], $name);
        }
    }

    public function testTheSeedsRevisionRecordedWithTheKernelIsInformational(): void
    {
        $pack = self::packRow(['kernel_json' => json_encode(['seeds_revision' => 7, 'kernel' => self::kernel()])]);
        self::assertTrue($this->service(self::regionRow(), $pack, self::parameters())->info('dc')['usable']);
    }

    public function testVintagesFallBackToTheRegionDefinitionAndThePackRow(): void
    {
        $info = $this->service(self::regionRow(), self::packRow(['vintages_json' => null]), self::parameters())->info('dc');
        self::assertSame(['census_reference_date' => '2020-04-01', 'lodes_year' => 2023, 'osm_snapshot_date' => '2026-10-03'], $info['vintages']);
    }

    public function testActiveOfAUsableRegion(): void
    {
        $active = $this->service(self::regionRow(), self::packRow(), self::parameters())->active('dc');
        self::assertIsArray($active);
        self::assertSame(['region_id', 'dataset_version', 'timezone', 'h3_res', 'usable', 'unusable_reason', 'config'], array_keys($active));
        self::assertSame('dc', $active['region_id']);
        self::assertSame(self::VERSION, $active['dataset_version']);
        self::assertSame('America/New_York', $active['timezone']);
        self::assertSame(9, $active['h3_res']);
        self::assertTrue($active['usable']);
        self::assertNull($active['unusable_reason']);
        self::assertSame('va', $active['config']['states'][1]['usps']);
    }

    public function testRegionNoneAndUnknownRegionsHaveNothing(): void
    {
        $service = $this->service(null, null, null, $db);
        self::assertNull($service->info('none'));
        self::assertNull($service->active('none'));
        self::assertNull($service->info(''));
        self::assertSame([], $db->calls, 'region none needs no query');
        self::assertNull($service->info('zz'));
        self::assertNull($service->active('zz'));
        self::assertSame([], $service->countyFips('zz'));
    }

    public function testTextThatIsNotARegionIdIsNeverLookedUp(): void
    {
        // MySQL refuses to compare text outside ASCII with the id column (error 3988, a server error for the
        // caller). A region id is 1 to 24 of a-z and 0-9: anything else is no region, and no query is made.
        $service = $this->service(self::regionRow(), self::packRow(), self::parameters(), $db);
        $texts = ["caf\u{e9}", "\u{6771}\u{4eac}", 'DC', 'dc ', "dc\n", 'd c', 'washington-dc', "dc\0", str_repeat('a', 25)];
        foreach ($texts as $text) {
            $shown = (string) json_encode($text);
            self::assertNull($service->info($text), $shown);
            self::assertNull($service->active($text), $shown);
            self::assertSame([], $service->countyFips($text), $shown);
            self::assertSame('NUS', $service->fuelArea($text, 'VA'), $shown);
        }
        self::assertSame([], $db->calls, 'nothing was asked of the database');

        self::assertIsArray($service->info('dc'), 'a region id is looked up as before');
        self::assertIsArray($this->service(self::regionRow(str_repeat('a', 24)), self::packRow(), self::parameters())->info(str_repeat('a', 24)));
    }

    public function testListReturnsEveryRegion(): void
    {
        $service = $this->service(self::regionRow(), self::packRow(), self::parameters());
        $list = $service->list();
        self::assertCount(1, $list);
        self::assertSame($service->info('dc'), $list[0]);
        self::assertSame([], $this->service(null, null, null)->list());
    }

    // ------------------------------------------------------------------------------------ how often it reads

    public function testOneRequestReadsEachRowOnce(): void
    {
        $service = $this->service(self::regionRow(), self::packRow(), self::parameters(), $db);
        $service->info('dc');
        $service->active('dc');
        $service->fuelArea('dc', 'VA');
        $service->countyFips('dc');
        $service->info('dc');
        self::assertCount(1, $db->find('FROM tp_regions'));
        self::assertCount(1, $db->find('JSON_EXTRACT(manifest_json'));
        self::assertCount(1, $db->find('SELECT manifest_json'));
        self::assertCount(0, $db->find('pack_gz FROM'), 'the blob is never read here');
    }

    public function testTheManifestVerdictIsKeptForADay(): void
    {
        $this->service(self::regionRow(), self::packRow(), self::parameters(), $first)->info('dc');
        self::assertCount(1, $first->find('SELECT manifest_json'));
        self::assertSame(['tp:regionok:dc:' . self::VERSION . ':' . Seeds::defaults()['seeds_revision']], array_keys($this->store->values));
        self::assertSame(86400 + 93600, $this->store->ttls['tp:regionok:dc:' . self::VERSION . ':' . Seeds::defaults()['seeds_revision']]);

        // the next request: the pack row is read, the manifest is not
        $info = $this->service(self::regionRow(), self::packRow(), self::parameters(), $second)->info('dc');
        self::assertTrue($info['usable']);
        self::assertCount(1, $second->find('JSON_EXTRACT(manifest_json'));
        self::assertCount(0, $second->find('SELECT manifest_json'));

        // a day later it is checked again
        $this->clock->advance(86400);
        $this->service(self::regionRow(), self::packRow(), self::parameters(), $third)->info('dc');
        self::assertCount(1, $third->find('SELECT manifest_json'));
    }

    public function testANegativeVerdictIsKeptToo(): void
    {
        $bad = self::parameters();
        $bad['walk_decay_m'] = 500;
        self::assertFalse($this->service(self::regionRow(), self::packRow(), $bad)->info('dc')['usable']);
        // even with a matching manifest now, the stored verdict for this version and seeds revision stands
        $info = $this->service(self::regionRow(), self::packRow(), self::parameters(), $db)->info('dc');
        self::assertFalse($info['usable']);
        self::assertCount(0, $db->find('SELECT manifest_json'));
    }

    // ------------------------------------------------------------------------------------ small helpers

    public function testRegionForPointUsesTheBoxOnlyToChooseADefault(): void
    {
        $db = new RecordingDatabase();
        $west = self::regionRow('aaa');
        $west['bbox_lng_min'] = -80.0;
        $west['bbox_lng_max'] = -77.2;
        $db->when('FROM tp_regions ORDER BY region_id', [$west, self::regionRow('dc')]);
        $service = new RegionService(new RegionRepository($db));

        self::assertSame('aaa', $service->regionForPoint(39.003, -77.405), 'both boxes hold it: the first id wins');
        self::assertSame('dc', $service->regionForPoint(38.9072, -77.0369));
        self::assertSame('dc', $service->regionForPoint(37.9907, -76.6625), 'the edge is inside');
        self::assertSame('none', $service->regionForPoint(40.7128, -74.0060));
        self::assertSame('none', $service->regionForPoint(37.99, -77.0));
        self::assertCount(1, $db->calls);
        self::assertSame('none', (new RegionService(new RegionRepository(new RecordingDatabase())))->regionForPoint(38.9, -77.0));
    }

    public function testFuelArea(): void
    {
        $service = $this->service(self::regionRow(), self::packRow(), self::parameters());
        self::assertSame('R1Z', $service->fuelArea('dc', 'VA'));
        self::assertSame('R1Y', $service->fuelArea('dc', 'DC'));
        self::assertSame('R1Z', $service->fuelArea('dc', 'wv'));
        self::assertSame('NUS', $service->fuelArea('dc', 'PA'));
        self::assertSame('NUS', $service->fuelArea('dc', null));
        self::assertSame('NUS', $service->fuelArea('dc', ''));
        self::assertSame('NUS', $service->fuelArea('none', 'VA'));
        self::assertSame('NUS', $service->fuelArea(null, 'VA'));
        self::assertSame('NUS', $this->service(null, null, null)->fuelArea('zz', 'VA'), 'an unknown region');
    }

    public function testCountyFips(): void
    {
        $service = $this->service(self::regionRow(), self::packRow(), self::parameters());
        self::assertSame(['11001', '51107'], $service->countyFips('dc'));
        self::assertSame([], $service->countyFips('none'));
    }
}
