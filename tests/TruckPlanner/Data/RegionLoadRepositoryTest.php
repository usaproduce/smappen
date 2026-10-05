<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Core\Database;
use App\TruckPlanner\Data\RegionLoadRepository;
use PHPUnit\Framework\TestCase;

/**
 * What the region loader writes (03_DATA.md 9.2 and section 10): the statements, the batches, the binds.
 *
 * The repository is the one that uses the PDO handle itself, for the two binary values and for the row
 * count of a batched delete, so the double below records both doors: the house query methods and PDO.
 */
final class RegionLoadRepositoryTest extends TestCase
{
    private const VERSION = 'dc-20261003-d0514a63';

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function place(array $changes = []): array
    {
        return $changes + [
            'place_key' => 'n3413156622', 'osm_type' => 'node', 'osm_id' => '3413156622', 'place_type' => 'cafe', 'geom_kind' => 'point',
            'in_region' => 1, 'county_fips' => '51107', 'name' => 'Starbucks', 'brand' => 'Starbucks', 'lat' => 38.9455121,
            'lng' => -77.4516722, 'rival_kind' => 'cafe', 'visitor_segment' => null, 'size_default' => 0, 'host_fit' => 0,
            'kitchen' => 'yes', 'phone' => '+13017428261', 'website' => 'https://www.example.test/', 'addr_line' => '44844 Aviation Drive',
            'city' => 'Sterling', 'state_code' => 'VA', 'postcode' => '20166', 'cuisine' => 'coffee_shop',
            'opening_hours_raw' => '04:30-21:00', 'hours_mask' => 'f0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1f',
            'tags' => ['brand:wikidata' => 'Q37158', 'takeaway' => 'yes'], 'snapshot_date' => '2026-10-03',
        ];
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function point(array $changes = []): array
    {
        return $changes + [
            'point_id' => 'b110010001011001', 'src_kind' => 'block', 'src_ref' => '110010001011001', 'in_region' => '1', 'job_adj' => '0',
            'lat' => '38.9099975', 'lng' => '-77.056197',
            'base' => ['282', '6.229805175574284', '0.01487631814098686', '0', '0', '1e-7', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0'],
            'rivals_day' => 0.8339850000000001, 'rivals_eve' => 1.0e-7,
        ];
    }

    // ------------------------------------------------------------------------------------ the region row

    public function testUpsertRegionInsertsARegionThatIsNew(): void
    {
        $db = new LoadDatabase();
        $region = ['id' => 'dc', 'name' => 'Washington, DC region', 'cbsa' => '47900', 'timezone' => 'America/New_York', 'h3_res' => 9,
            'map_center' => ['lat' => 38.9072, 'lng' => -77.0369], 'traffic_matrix' => 'dc'];
        $bounds = ['lat_min' => 37.99069, 'lng_min' => -78.3947, 'lat_max' => 39.72005, 'lng_max' => -76.66251];
        (new RegionLoadRepository($db))->upsertRegion($region, $bounds, '{"id":"dc","verbatim":{}}');

        self::assertSame('SELECT region_id FROM tp_regions WHERE region_id = ?', $db->calls[0]['sql']);
        $insert = $db->only('INSERT INTO tp_regions');
        self::assertSame(
            'INSERT INTO tp_regions (region_id, name, cbsa, timezone, h3_res, bbox_lat_min, bbox_lng_min, bbox_lat_max, bbox_lng_max, '
            . 'center_lat, center_lng, config_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $insert['sql']
        );
        self::assertSame(
            ['dc', 'Washington, DC region', '47900', 'America/New_York', 9, '37.99069', '-78.3947', '39.72005', '-76.66251',
                '38.9072', '-77.0369', '{"id":"dc","verbatim":{}}'],
            $insert['params']
        );
        self::assertStringNotContainsString('active_version', $insert['sql'], 'the live version is not touched');
        self::assertSame([], $db->find('UPDATE tp_regions'));
    }

    public function testUpsertRegionUpdatesARegionThatExistsAndLeavesItsLiveVersionAlone(): void
    {
        $db = new LoadDatabase();
        $db->when('SELECT region_id FROM tp_regions', ['region_id' => 'mini']);
        $region = ['id' => 'mini', 'name' => 'Falls Church test region', 'timezone' => 'America/New_York', 'h3_res' => 9,
            'map_center' => ['lat' => 38.8847, 'lng' => -77.1756], 'holidays' => []];
        (new RegionLoadRepository($db))->upsertRegion($region, ['lat_min' => 38.87247, 'lng_min' => -77.195, 'lat_max' => 38.89989, 'lng_max' => -77.1497]);

        $update = $db->only('UPDATE tp_regions');
        self::assertSame(
            'UPDATE tp_regions SET name = ?, cbsa = ?, timezone = ?, h3_res = ?, bbox_lat_min = ?, bbox_lng_min = ?, bbox_lat_max = ?, '
            . 'bbox_lng_max = ?, center_lat = ?, center_lng = ?, config_json = ? WHERE region_id = ?',
            $update['sql']
        );
        self::assertNull($update['params'][1], 'a region without a CBSA');
        self::assertSame('mini', $update['params'][11]);
        // without a text of its own the definition is encoded here, as a JSON object
        self::assertSame(
            '{"id":"mini","name":"Falls Church test region","timezone":"America/New_York","h3_res":9,"map_center":{"lat":38.8847,"lng":-77.1756},"holidays":[]}',
            $update['params'][10]
        );
        self::assertStringNotContainsString('active_version', $update['sql']);
        self::assertStringNotContainsString('previous_version', $update['sql']);
        self::assertSame([], $db->find('INSERT INTO'));
    }

    // ------------------------------------------------------------------------------------ the ledger row

    public function testPackRowStateAndTheOpeningOfALoad(): void
    {
        $db = new LoadDatabase();
        $repository = new RegionLoadRepository($db);
        self::assertNull($repository->packRowState('dc', self::VERSION));
        self::assertSame(
            'SELECT load_state FROM tp_region_packs WHERE region_id = ? AND dataset_version = ?',
            $db->calls[0]['sql']
        );
        self::assertStringNotContainsString('pack_gz', $db->calls[0]['sql']);

        $db->when('SELECT load_state', ['load_state' => 'failed']);
        self::assertSame('failed', $repository->packRowState('dc', self::VERSION));

        $repository->insertPackRow('dc', self::VERSION, ['model_version' => 'tps-0.1.0', 'pipeline_version' => 'tp-etl-1.0.0', 'osm_snapshot' => '2026-10-03']);
        $insert = $db->only('INSERT INTO tp_region_packs');
        self::assertSame(
            'INSERT INTO tp_region_packs (region_id, dataset_version, load_state, model_version, pipeline_version, osm_snapshot) VALUES (?, ?, ?, ?, ?, ?)',
            $insert['sql']
        );
        self::assertSame(['dc', self::VERSION, 'loading', 'tps-0.1.0', 'tp-etl-1.0.0', '2026-10-03'], $insert['params']);
    }

    public function testSetState(): void
    {
        $db = new LoadDatabase();
        $repository = new RegionLoadRepository($db);
        $repository->setState('dc', self::VERSION, 'failed');
        $call = $db->only('UPDATE tp_region_packs');
        self::assertSame('UPDATE tp_region_packs SET load_state = ? WHERE region_id = ? AND dataset_version = ?', $call['sql']);
        self::assertSame(['failed', 'dc', self::VERSION], $call['params']);
        $this->expectException(\LogicException::class);
        $repository->setState('dc', self::VERSION, 'live');
    }

    // ------------------------------------------------------------------------------------ deleting a version

    public function testDeleteVersionBatchSaysHowManyRowsWent(): void
    {
        $db = new LoadDatabase();
        $db->pdo->rowCounts = [5000, 1234, 0];
        $repository = new RegionLoadRepository($db);
        self::assertSame(5000, $repository->deleteVersionBatch('tp_points', 'dc', self::VERSION));
        self::assertSame(1234, $repository->deleteVersionBatch('tp_points', 'dc', self::VERSION));
        self::assertSame(0, $repository->deleteVersionBatch('tp_places', 'dc', self::VERSION, 100));

        self::assertSame('DELETE FROM tp_points WHERE region_id = ? AND dataset_version = ? LIMIT 5000', $db->pdo->statements[0]->sql);
        self::assertSame([['dc', self::VERSION]], $db->pdo->statements[0]->executions);
        self::assertSame('DELETE FROM tp_places WHERE region_id = ? AND dataset_version = ? LIMIT 100', $db->pdo->statements[2]->sql);
        $repository->deleteVersionBatch('tp_region_packs', 'dc', self::VERSION);
        self::assertSame('DELETE FROM tp_region_packs WHERE region_id = ? AND dataset_version = ? LIMIT 5000', $db->pdo->statements[3]->sql);
    }

    public function testOnlyTheTablesOfDatasetVersionsCanBeEmptied(): void
    {
        $repository = new RegionLoadRepository(new LoadDatabase());
        foreach (['tp_regions', 'tp_spots', 'users', 'tp_points; DROP TABLE users', ''] as $table) {
            try {
                $repository->deleteVersionBatch($table, 'dc', self::VERSION);
                self::fail($table . ' was accepted');
            } catch (\LogicException $e) {
                self::assertStringContainsString('not a table of dataset versions', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------------------------ places

    public function testInsertPlacesWritesStatementsOfFiveHundredRowsInOneTransaction(): void
    {
        $db = new LoadDatabase();
        $rows = [];
        for ($i = 0; $i < 1200; $i++) {
            $rows[] = self::place(['place_key' => 'n' . (1000 + $i)]);
        }
        (new RegionLoadRepository($db))->insertPlaces('dc', self::VERSION, $rows);

        self::assertSame(['begin', 'query', 'query', 'query', 'commit'], $db->kinds());
        $statements = $db->find('INSERT INTO tp_places');
        self::assertSame([500 * 29, 500 * 29, 200 * 29], array_map(static fn (array $c): int => count($c['params']), $statements));
        self::assertSame(14500, substr_count($statements[0]['sql'], '?'), '29 columns, 14,500 placeholders');
        self::assertStringStartsWith(
            'INSERT INTO tp_places (region_id, dataset_version, place_key, osm_type, osm_id, snapshot_date, place_type, geom_kind, '
            . 'in_region, county_fips, name, brand, lat, lng, rival_kind, visitor_segment, size_default, host_fit, kitchen, phone, website, '
            . 'addr_line, city, state_code, postcode, cuisine, opening_hours_raw, hours_mask, tags_json) VALUES (?, ?, ',
            $statements[0]['sql']
        );
        self::assertStringNotContainsString('host_vec', $statements[0]['sql'], 'host_vec stays NULL until the host vectors are computed');
        self::assertSame(
            ['dc', self::VERSION, 'n1000', 'node', '3413156622', '2026-10-03', 'cafe', 'point', 1, '51107', 'Starbucks', 'Starbucks',
                '38.9455121', '-77.4516722', 'cafe', null, '0', '0', 'yes', '+13017428261', 'https://www.example.test/',
                '44844 Aviation Drive', 'Sterling', 'VA', '20166', 'coffee_shop', '04:30-21:00',
                'f0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1f', '{"brand:wikidata":"Q37158","takeaway":"yes"}'],
            array_slice($statements[0]['params'], 0, 29)
        );
        self::assertSame('n2199', $statements[2]['params'][199 * 29 + 2]);
    }

    public function testInsertPlacesBindsNullsFlagsAndAnEmptyTagObject(): void
    {
        $db = new LoadDatabase();
        (new RegionLoadRepository($db))->insertPlaces('dc', self::VERSION, [
            self::place(['in_region' => 0, 'county_fips' => null, 'name' => null, 'brand' => null, 'rival_kind' => null,
                'visitor_segment' => 'v_nightlife', 'size_default' => 40.0, 'host_fit' => 0.3, 'phone' => null, 'tags' => null,
                'lat' => 39, 'lng' => -77]),
            self::place(['place_key' => 'w2', 'tags' => []]),
        ]);
        $params = $db->only('INSERT INTO tp_places')['params'];
        self::assertSame(0, $params[8]);
        self::assertNull($params[9]);
        self::assertNull($params[10]);
        self::assertSame('39', $params[12], 'a whole number is bound as text too, never as a PHP float');
        self::assertSame('-77', $params[13]);
        self::assertNull($params[14]);
        self::assertSame('v_nightlife', $params[15]);
        self::assertSame('40', $params[16]);
        self::assertSame('0.3', $params[17]);
        self::assertNull($params[19]);
        self::assertNull($params[28]);
        self::assertSame('{}', $params[29 + 28], 'an empty tag object is an object');
    }

    public function testNothingIsWrittenForNoRows(): void
    {
        $db = new LoadDatabase();
        $repository = new RegionLoadRepository($db);
        $repository->insertPlaces('dc', self::VERSION, []);
        $repository->insertPoints('dc', self::VERSION, []);
        self::assertSame([], $db->calls);
    }

    // ------------------------------------------------------------------------------------ points

    public function testInsertPointsBindsFileNumbersAsTheyAreAndComputedOnesWithEveryDigit(): void
    {
        $db = new LoadDatabase();
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = self::point(['point_id' => 'b' . (110010001011001 + $i)]);
        }
        (new RegionLoadRepository($db))->insertPoints('dc', self::VERSION, $rows);

        self::assertSame(['begin', 'query', 'query', 'commit'], $db->kinds());
        $statements = $db->find('INSERT INTO tp_points');
        self::assertSame(13500, substr_count($statements[0]['sql'], '?'), '27 columns, 13,500 placeholders');
        self::assertSame(27, count($statements[1]['params']));
        self::assertStringStartsWith(
            'INSERT INTO tp_points (region_id, dataset_version, point_id, src_kind, src_ref, in_region, job_adj, lat, lng, '
            . 'b_res, b_w_office, b_w_health, b_w_edu, b_w_retail, b_w_industrial, b_w_hospitality, b_w_public, '
            . 'b_v_nightlife, b_v_shopping, b_v_leisure, b_v_campus, b_v_hospital, b_v_transit, b_v_events, b_v_lodging, '
            . 'rivals_day, rivals_eve) VALUES (?, ',
            $statements[0]['sql']
        );
        self::assertSame(
            ['dc', self::VERSION, 'b110010001011001', 'block', '110010001011001', 1, 0, '38.9099975', '-77.056197',
                '282', '6.229805175574284', '0.01487631814098686', '0', '0', '1e-7', '0', '0', '0', '0', '0', '0', '0', '0', '0', '0',
                '0.8339850000000001', '1.0e-7'],
            array_slice($statements[0]['params'], 0, 27)
        );
        // every bound number reads back as the double it came from
        self::assertSame(0.8339850000000001, (float) $statements[0]['params'][25]);
        self::assertSame(1.0e-7, (float) $statements[0]['params'][26]);
        self::assertSame(6.229805175574284, (float) $statements[0]['params'][10]);
    }

    public function testAPointNeedsSixteenBasesAndNumbers(): void
    {
        $repository = new RegionLoadRepository(new LoadDatabase());
        try {
            $repository->insertPoints('dc', self::VERSION, [self::point(['base' => ['1', '2']])]);
            self::fail('a point with two bases was accepted');
        } catch (\LengthException $e) {
            self::assertStringContainsString('16 bases', $e->getMessage());
        }
        foreach (['abc', '', 'NaN', null, INF] as $bad) {
            $db = new LoadDatabase();
            try {
                (new RegionLoadRepository($db))->insertPoints('dc', self::VERSION, [self::point(['lat' => $bad])]);
                self::fail(var_export($bad, true) . ' was bound as a number');
            } catch (\DomainException $e) {
                self::assertSame(['begin', 'rollback'], $db->kinds(), 'nothing of the batch stays');
            }
        }
    }

    public function testAFailedStatementRollsTheBatchBack(): void
    {
        $db = new LoadDatabase();
        $db->failOn = 'INSERT INTO tp_places';
        try {
            (new RegionLoadRepository($db))->insertPlaces('dc', self::VERSION, [self::place()]);
            self::fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame(['begin', 'query', 'rollback'], $db->kinds());
        }
    }

    // ------------------------------------------------------------------------------------ host vectors

    public function testSetHostVecBindsItsFourHundredBytesAsALob(): void
    {
        $db = new LoadDatabase();
        $repository = new RegionLoadRepository($db);
        $first = pack('e50', ...array_map(static fn (int $i): float => $i / 7.0, range(0, 49)));
        $second = pack('e50', ...array_fill(0, 50, 0.0));
        $repository->setHostVec('dc', self::VERSION, 'w264230766', $first);
        $repository->setHostVec('dc', self::VERSION, 'w9', $second);

        self::assertCount(1, $db->pdo->statements, 'one prepared statement serves every host');
        $statement = $db->pdo->statements[0];
        self::assertSame('UPDATE tp_places SET host_vec = ? WHERE region_id = ? AND dataset_version = ? AND place_key = ?', $statement->sql);
        self::assertCount(2, $statement->executions);
        self::assertSame([1 => $first, 2 => 'dc', 3 => self::VERSION, 4 => 'w264230766'], $statement->executions[0]);
        self::assertSame([1 => $second, 2 => 'dc', 3 => self::VERSION, 4 => 'w9'], $statement->executions[1]);
        self::assertSame(\PDO::PARAM_LOB, $statement->types[1]);
        self::assertSame(\PDO::PARAM_STR, $statement->types[4]);
        self::assertSame(400, strlen($statement->executions[0][1]));
        self::assertStringNotContainsString('UNHEX', $statement->sql);
        self::assertSame([], $db->calls, 'the bytes never travel through a text bind');
    }

    public function testAHostVectorIsExactlyFiftyDoubles(): void
    {
        $repository = new RegionLoadRepository(new LoadDatabase());
        foreach ([0, 392, 408, 1200] as $length) {
            try {
                $repository->setHostVec('dc', self::VERSION, 'w1', str_repeat("\0", $length));
                self::fail($length . ' bytes were accepted');
            } catch (\LengthException $e) {
                self::assertStringContainsString('400 bytes', $e->getMessage());
            }
        }
    }

    public function testTransactionGroupsTheWritesMadeInsideIt(): void
    {
        $db = new LoadDatabase();
        $repository = new RegionLoadRepository($db);
        $repository->transaction(function () use ($repository): void {
            $repository->setHostVec('dc', self::VERSION, 'w1', str_repeat("\0", 400));
            $repository->setHostVec('dc', self::VERSION, 'w2', str_repeat("\0", 400));
        });
        self::assertSame(['begin', 'commit'], $db->kinds());
        self::assertCount(2, $db->pdo->statements[0]->executions);

        $db = new LoadDatabase();
        try {
            (new RegionLoadRepository($db))->transaction(static function (): void {
                throw new \RuntimeException('a write failed');
            });
            self::fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('a write failed', $e->getMessage());
            self::assertSame(['begin', 'rollback'], $db->kinds());
        }
    }

    // ------------------------------------------------------------------------------------ closing a load

    public function testFinishPackStoresTheBlobAsALobAndMarksTheVersionReady(): void
    {
        $db = new LoadDatabase();
        $pack = str_repeat('pack bytes ', 100);
        $gz = (string) gzencode($pack, 9);
        $kernel = ['seeds_revision' => 1, 'kernel' => ['earth_radius_m' => 6371008.8, 'walk_decay_m' => 400.0, 'a0' => 1.6]];
        (new RegionLoadRepository($db))->finishPack(
            'dc',
            self::VERSION,
            ['points' => 60678, 'places' => 25276, 'cells' => 61460, 'pack_format' => 1, 'pack_len' => strlen($pack), 'pack_sha256' => hash('sha256', $pack)],
            $gz,
            $kernel,
            '{"schema": 1, "gates": []}'
        );

        $statement = $db->pdo->statements[0];
        self::assertSame(
            'UPDATE tp_region_packs SET point_count = ?, place_count = ?, cell_count = ?, pack_format = ?, pack_len = ?, pack_gz_len = ?, '
            . 'pack_sha256 = ?, pack_gz = ?, kernel_json = ?, manifest_json = ?, load_state = ?, loaded_at = NOW() '
            . 'WHERE region_id = ? AND dataset_version = ?',
            $statement->sql
        );
        self::assertSame(
            [1 => 60678, 2 => 25276, 3 => 61460, 4 => 1, 5 => strlen($pack), 6 => strlen($gz), 7 => hash('sha256', $pack), 8 => $gz,
                9 => '{"seeds_revision":1,"kernel":{"earth_radius_m":6371008.8,"walk_decay_m":400,"a0":1.6}}',
                10 => '{"schema": 1, "gates": []}', 11 => 'ready', 12 => 'dc', 13 => self::VERSION],
            $statement->executions[0]
        );
        self::assertSame(\PDO::PARAM_LOB, $statement->types[8], 'the pack is bound as a LOB');
        self::assertSame(\PDO::PARAM_INT, $statement->types[6]);
        self::assertSame(\PDO::PARAM_STR, $statement->types[10], 'the manifest is stored as its own text');
    }

    // ------------------------------------------------------------------------------------ the switch

    public function testActivateIsOneTransactionOfTwoUpdates(): void
    {
        $db = new LoadDatabase();
        $db->when('SELECT active_version FROM tp_regions', ['active_version' => 'dc-20260701-aaaaaaaa']);
        $db->when('SELECT load_state', ['load_state' => 'ready']);
        (new RegionLoadRepository($db))->activate('dc', self::VERSION);

        self::assertSame(['begin', 'fetch', 'fetch', 'query', 'query', 'commit'], $db->kinds());
        $switch = $db->only('UPDATE tp_regions');
        self::assertSame(
            'UPDATE tp_regions SET previous_version = active_version, active_version = ?, updated_at = NOW() WHERE region_id = ?',
            $switch['sql']
        );
        self::assertSame([self::VERSION, 'dc'], $switch['params']);
        $stamp = $db->only('UPDATE tp_region_packs');
        self::assertSame('UPDATE tp_region_packs SET activated_at = NOW() WHERE region_id = ? AND dataset_version = ?', $stamp['sql']);
        self::assertSame(['dc', self::VERSION], $stamp['params']);
    }

    public function testActivatingTheLiveVersionChangesNothing(): void
    {
        $db = new LoadDatabase();
        $db->when('SELECT active_version FROM tp_regions', ['active_version' => self::VERSION]);
        $db->when('SELECT load_state', ['load_state' => 'ready']);
        (new RegionLoadRepository($db))->activate('dc', self::VERSION);
        self::assertSame(['begin', 'fetch', 'fetch', 'commit'], $db->kinds(), 'the previous version must not be overwritten with the live one');
    }

    public function testOnlyAReadyVersionOfAKnownRegionIsActivated(): void
    {
        $cases = [
            'unknown region' => [null, ['load_state' => 'ready']],
            'unknown dataset version' => [['active_version' => null], null],
            'is loading, not ready' => [['active_version' => null], ['load_state' => 'loading']],
            'is failed, not ready' => [['active_version' => null], ['load_state' => 'failed']],
        ];
        foreach ($cases as $message => [$region, $pack]) {
            $db = new LoadDatabase();
            $db->when('SELECT active_version FROM tp_regions', $region);
            $db->when('SELECT load_state', $pack);
            try {
                (new RegionLoadRepository($db))->activate('dc', self::VERSION);
                self::fail($message . ': activated');
            } catch (\DomainException $e) {
                self::assertStringContainsString($message, $e->getMessage());
                self::assertSame([], $db->find('UPDATE'), $message);
                self::assertSame('rollback', $db->kinds()[count($db->kinds()) - 1], $message);
            }
        }
    }

    // ------------------------------------------------------------------------------------ the ledger

    public function testVersionsListsTheLedgerWithoutThePack(): void
    {
        $db = new LoadDatabase();
        $db->when('FROM tp_region_packs WHERE region_id = ? ORDER BY loaded_at, dataset_version', [
            ['dataset_version' => 'dc-20260701-aaaaaaaa', 'load_state' => 'ready', 'model_version' => 'tps-0.1.0', 'pipeline_version' => 'tp-etl-1.0.0',
                'osm_snapshot' => '2026-07-01', 'point_count' => '60000', 'place_count' => 25000, 'cell_count' => 61000, 'pack_len' => 6600000,
                'pack_gz_len' => '3400000', 'loaded_at' => '2026-07-02 10:00:00', 'activated_at' => '2026-07-02 10:05:00'],
            ['dataset_version' => self::VERSION, 'load_state' => 'failed', 'model_version' => 'tps-0.1.0', 'pipeline_version' => 'tp-etl-1.0.0',
                'osm_snapshot' => null, 'point_count' => 0, 'place_count' => 0, 'cell_count' => 0, 'pack_len' => 0, 'pack_gz_len' => 0,
                'loaded_at' => '2026-10-05 04:00:00', 'activated_at' => null],
        ]);
        $versions = (new RegionLoadRepository($db))->versions('dc');

        $call = $db->only('FROM tp_region_packs');
        self::assertSame(['dc'], $call['params']);
        self::assertStringNotContainsString('pack_gz,', $call['sql']);
        self::assertStringNotContainsString('pack_gz ', $call['sql']);
        self::assertStringNotContainsString('manifest_json', $call['sql']);
        self::assertStringNotContainsString('*', $call['sql']);
        self::assertCount(2, $versions);
        self::assertSame(60000, $versions[0]['point_count']);
        self::assertSame(3400000, $versions[0]['pack_gz_len']);
        self::assertSame('2026-07-02 10:05:00', $versions[0]['activated_at']);
        self::assertSame('failed', $versions[1]['load_state']);
        self::assertNull($versions[1]['osm_snapshot']);
        self::assertNull($versions[1]['activated_at']);
    }
}

/**
 * A Database that connects to nothing and records both doors of RegionLoadRepository: the house methods
 * (query, fetch, fetchAll, the transaction calls) in `$calls`, and what goes through the PDO handle in `$pdo`.
 */
final class LoadDatabase extends Database
{
    /** @var list<array{kind: string, sql: string, params: array<int|string, mixed>}> */
    public array $calls = [];
    public LoadPdo $pdo;
    public ?string $failOn = null;

    /** @var list<array{0: string, 1: mixed}> */
    private array $stubs = [];

    public function __construct()
    {
        $this->pdo = new LoadPdo();
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /** The answer of every read whose SQL contains `$needle`. */
    public function when(string $needle, mixed $result): void
    {
        $this->stubs[] = [self::squash($needle), $result];
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $this->calls[] = ['kind' => 'query', 'sql' => self::squash($sql), 'params' => $params];
        if ($this->failOn !== null && str_contains(self::squash($sql), $this->failOn)) {
            throw new \RuntimeException('database failure (test)');
        }
        return new \PDOStatement();
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $this->calls[] = ['kind' => 'fetch', 'sql' => self::squash($sql), 'params' => $params];
        $answer = $this->answer($sql);
        return is_array($answer) ? $answer : null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->calls[] = ['kind' => 'fetchAll', 'sql' => self::squash($sql), 'params' => $params];
        $answer = $this->answer($sql);
        return is_array($answer) ? $answer : [];
    }

    public function beginTransaction(): void
    {
        $this->calls[] = ['kind' => 'begin', 'sql' => '', 'params' => []];
    }

    public function commit(): void
    {
        $this->calls[] = ['kind' => 'commit', 'sql' => '', 'params' => []];
    }

    public function rollback(): void
    {
        $this->calls[] = ['kind' => 'rollback', 'sql' => '', 'params' => []];
    }

    /**
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_column($this->calls, 'kind');
    }

    /**
     * @return list<array{kind: string, sql: string, params: array<int|string, mixed>}>
     */
    public function find(string $needle): array
    {
        $found = [];
        foreach ($this->calls as $call) {
            if ($call['sql'] !== '' && str_contains($call['sql'], self::squash($needle))) {
                $found[] = $call;
            }
        }
        return $found;
    }

    /**
     * @return array{kind: string, sql: string, params: array<int|string, mixed>}
     */
    public function only(string $needle): array
    {
        $found = $this->find($needle);
        if (count($found) !== 1) {
            throw new \LogicException(count($found) . ' statements contain "' . $needle . '", expected 1');
        }
        return $found[0];
    }

    private function answer(string $sql): mixed
    {
        $squashed = self::squash($sql);
        $answer = null;
        foreach ($this->stubs as [$needle, $result]) {
            if (str_contains($squashed, $needle)) {
                $answer = $result;                // the stub given last wins
            }
        }
        return $answer;
    }

    private static function squash(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}

/**
 * A PDO that prepares recording statements and never connects.
 */
final class LoadPdo extends \PDO
{
    /** @var list<LoadStatement> */
    public array $statements = [];

    /** @var list<int> what rowCount() answers after each execution, in order */
    public array $rowCounts = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        $statement = new LoadStatement(trim((string) preg_replace('/\s+/', ' ', $query)), $this);
        $this->statements[] = $statement;
        return $statement;
    }
}

final class LoadStatement extends \PDOStatement
{
    /** @var list<array<int|string, mixed>> the values of each execution */
    public array $executions = [];

    /** @var array<int|string, int> the PDO type each value was last bound with */
    public array $types = [];

    /** @var array<int|string, mixed> */
    private array $bound = [];
    private int $rows = 0;

    public function __construct(public string $sql, private LoadPdo $owner)
    {
    }

    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        $this->bound[$param] = $value;
        $this->types[$param] = $type;
        return true;
    }

    public function execute(?array $params = null): bool
    {
        $this->executions[] = $params ?? $this->bound;
        $this->rows = $this->owner->rowCounts === [] ? 0 : array_shift($this->owner->rowCounts);
        return true;
    }

    public function rowCount(): int
    {
        return $this->rows;
    }
}
