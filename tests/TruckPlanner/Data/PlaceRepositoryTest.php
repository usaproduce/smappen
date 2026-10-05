<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\PointRepository;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

/**
 * Queries Q2, Q3 and Q5 of 03_DATA.md (9.3, 6.2), Q6 and Q7 of 04_BACKEND.md 5.1 and the export page: the
 * statements, what they bind, and the rows they hand out.
 */
final class PlaceRepositoryTest extends TestCase
{
    private const VERSION = 'dc-20261003-d0514a63';

    protected function tearDown(): void
    {
        TpConfig::replace(null);
    }

    /**
     * @return list<string> the six values every box statement binds first
     */
    private static function boxParams(float $lat, float $lng, float $r): array
    {
        $box = PointRepository::box($lat, $lng, $r);
        return ['dc', self::VERSION, json_encode($box['lat_min']), json_encode($box['lat_max']), json_encode($box['lng_min']), json_encode($box['lng_max'])];
    }

    /**
     * A possible host as PDO hands it over, in the column order of Q3.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function hostRow(array $changes = []): array
    {
        // array_replace keeps the column order of the statement, as PDO does
        return array_replace([
            'place_key' => 'w264230766', 'place_type' => 'taproom', 'name' => 'Example Brewing', 'brand' => null,
            'lat' => '39.0101', 'lng' => -77.4102, 'county_fips' => '51107', 'host_fit' => '1', 'kitchen' => 'unknown',
            'size_default' => 40, 'visitor_segment' => 'v_nightlife', 'phone' => '+17035550100',
            'website' => 'https://example.test/', 'addr_line' => '1 Example Way', 'city' => 'Sterling', 'state_code' => 'VA',
            'postcode' => '20166', 'opening_hours_raw' => 'Mo-Su 12:00-22:00', 'hours_mask' => str_repeat('0f', 21),
            'host_vec' => pack('e50', ...array_map(static fn (int $i): float => $i + 0.1, range(0, 49))),
        ], $changes);
    }

    public function testRivalsNearIsQueryTwo(): void
    {
        $db = (new RecordingDatabase())->queue([
            ['place_key' => 'n100', 'place_type' => 'fast_food', 'rival_kind' => 'quick', 'lat' => '38.95694', 'lng' => -77.36,
                'name' => 'Example Grill', 'kitchen' => 'yes', 'hours_mask' => null],
            ['place_key' => 'w7', 'place_type' => 'bar', 'rival_kind' => 'bar', 'lat' => 38.957, 'lng' => -77.361,
                'name' => null, 'kitchen' => 'unknown', 'hours_mask' => str_repeat('ff', 21)],
        ]);
        $rows = (new PlaceRepository($db))->rivalsNear('dc', self::VERSION, 38.96, -77.36, 1200.0);

        $call = $db->only('FROM tp_places');
        self::assertSame(
            'SELECT place_key, place_type, rival_kind, lat, lng, name, kitchen, hours_mask '
            . 'FROM tp_places FORCE INDEX (idx_tpl_geo) '
            . 'WHERE region_id = ? AND dataset_version = ? AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? '
            . 'AND rival_kind IS NOT NULL ORDER BY place_key',
            $call['sql']
        );
        self::assertSame(self::boxParams(38.96, -77.36, 1200.0), $call['params']);
        self::assertSame(
            [
                ['n100', 'fast_food', 'quick', 38.95694, -77.36, 'Example Grill', 'yes', null],
                ['w7', 'bar', 'bar', 38.957, -77.361, null, 'unknown', str_repeat('ff', 21)],
            ],
            $rows
        );
        self::assertSame('quick', $rows[0][PlaceRepository::RIVAL_KIND]);
        self::assertSame('Example Grill', $rows[0][PlaceRepository::RIVAL_NAME]);
        self::assertSame('yes', $rows[0][PlaceRepository::RIVAL_KITCHEN]);
        self::assertNull($rows[0][PlaceRepository::RIVAL_HOURS_MASK]);
        self::assertSame('n100', $rows[0][PlaceRepository::RIVAL_KEY]);
        self::assertSame('fast_food', $rows[0][PlaceRepository::RIVAL_TYPE]);
        self::assertSame(38.95694, $rows[0][PlaceRepository::RIVAL_LAT]);
        self::assertSame(-77.36, $rows[0][PlaceRepository::RIVAL_LNG]);
    }

    public function testHostsNearIsTheFirstPageOfQueryThree(): void
    {
        $db = (new RecordingDatabase())->queue([self::hostRow(), self::hostRow(['place_key' => 'w9', 'visitor_segment' => null, 'host_vec' => null, 'name' => 'Office Court'])]);
        $rows = (new PlaceRepository($db))->hostsNear('dc', self::VERSION, 39.01, -77.41, 250.0);

        $call = $db->only('FROM tp_places');
        self::assertSame(
            'SELECT place_key, place_type, name, brand, lat, lng, county_fips, host_fit, kitchen, size_default, visitor_segment, '
            . 'phone, website, addr_line, city, state_code, postcode, opening_hours_raw, hours_mask, host_vec '
            . 'FROM tp_places '
            . 'WHERE region_id = ? AND dataset_version = ? AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? '
            . 'AND in_region = 1 AND host_fit > 0 AND place_key > ? ORDER BY place_key LIMIT 2000',
            $call['sql']
        );
        self::assertSame(array_merge(self::boxParams(39.01, -77.41, 250.0), ['']), $call['params'], 'the first-page key is the empty text');

        self::assertCount(2, $rows);
        self::assertCount(20, $rows[0]);
        self::assertSame(
            ['w264230766', 'taproom', 'Example Brewing', null, 39.0101, -77.4102, '51107', 1.0, 'unknown', 40.0, 'v_nightlife',
                '+17035550100', 'https://example.test/', '1 Example Way', 'Sterling', 'VA', '20166', 'Mo-Su 12:00-22:00', str_repeat('0f', 21)],
            array_slice($rows[0], 0, 19)
        );
        // host_vec as stored: 400 bytes, 50 little-endian doubles
        self::assertSame(400, strlen($rows[0][PlaceRepository::HOST_VEC]));
        self::assertSame(49.1, unpack('e50', $rows[0][PlaceRepository::HOST_VEC])[50]);
        self::assertNull($rows[1][PlaceRepository::HOST_VEC]);
        self::assertNull($rows[1][PlaceRepository::HOST_SEGMENT]);
        self::assertSame('Office Court', $rows[1][PlaceRepository::HOST_NAME]);
        self::assertSame(1.0, $rows[0][PlaceRepository::HOST_FIT]);
        self::assertSame(40.0, $rows[0][PlaceRepository::HOST_SIZE]);
        self::assertSame('unknown', $rows[0][PlaceRepository::HOST_KITCHEN]);
        self::assertSame('51107', $rows[0][PlaceRepository::HOST_COUNTY]);
        self::assertSame('Mo-Su 12:00-22:00', $rows[0][PlaceRepository::HOST_HOURS_RAW]);
        self::assertSame(19, PlaceRepository::HOST_VEC);
    }

    public function testThePageSizeIsTheScoutSetting(): void
    {
        self::assertSame(2000, PlaceRepository::pageRows());
        $config = TpConfig::all();
        $config['scout']['page_rows'] = 750;
        TpConfig::replace($config);
        self::assertSame(750, PlaceRepository::pageRows());
        $db = new RecordingDatabase();
        $repository = new PlaceRepository($db);
        $repository->hostsNear('dc', self::VERSION, 39.01, -77.41, 250.0);
        $repository->hostVectorPage('dc', self::VERSION, PointRepository::box(39.0, -77.4, 30000.0), [], '');
        foreach ($db->calls as $call) {
            self::assertStringEndsWith('ORDER BY place_key LIMIT 750', $call['sql']);
        }
    }

    public function testHostVectorPageIsQuerySix(): void
    {
        $row = ['place_key' => 'w264230766', 'place_type' => 'taproom', 'lat' => 39.0101, 'lng' => '-77.4102', 'county_fips' => '51107',
            'kitchen' => 'no', 'visitor_segment' => 'v_nightlife', 'size_default' => '40', 'host_vec' => str_repeat("\x01", 400)];
        $db = (new RecordingDatabase())->queue([$row]);
        $box = PointRepository::box(39.003, -77.405, 25000.0);
        $rows = (new PlaceRepository($db))->hostVectorPage('dc', self::VERSION, $box, [], '');

        $call = $db->only('FROM tp_places');
        self::assertSame(
            'SELECT place_key, place_type, lat, lng, county_fips, kitchen, visitor_segment, size_default, host_vec '
            . 'FROM tp_places '
            . 'WHERE region_id = ? AND dataset_version = ? AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ? '
            . 'AND in_region = 1 AND host_fit > 0 AND host_vec IS NOT NULL '
            . 'AND place_key > ? ORDER BY place_key LIMIT 2000',
            $call['sql']
        );
        self::assertSame(array_merge(self::boxParams(39.003, -77.405, 25000.0), ['']), $call['params']);
        self::assertSame([['w264230766', 'taproom', 39.0101, -77.4102, '51107', 'no', 'v_nightlife', 40.0, str_repeat("\x01", 400)]], $rows);
        self::assertSame(str_repeat("\x01", 400), $rows[0][PlaceRepository::VEC_BYTES]);
        self::assertSame(40.0, $rows[0][PlaceRepository::VEC_SIZE]);
        self::assertSame('v_nightlife', $rows[0][PlaceRepository::VEC_SEGMENT]);
        self::assertSame('no', $rows[0][PlaceRepository::VEC_KITCHEN]);
        self::assertSame('51107', $rows[0][PlaceRepository::VEC_COUNTY]);
        self::assertSame('taproom', $rows[0][PlaceRepository::VEC_TYPE]);
        self::assertSame(39.0101, $rows[0][PlaceRepository::VEC_LAT]);
        self::assertSame(-77.4102, $rows[0][PlaceRepository::VEC_LNG]);
        self::assertSame('w264230766', $rows[0][PlaceRepository::VEC_KEY]);
    }

    public function testHostVectorPageWithLicenceCountiesAndAKeyToContinueFrom(): void
    {
        $db = new RecordingDatabase();
        $box = PointRepository::box(39.003, -77.405, 25000.0);
        (new PlaceRepository($db))->hostVectorPage('dc', self::VERSION, $box, ['51107', '51059', '11001'], 'w264230766');

        $call = $db->only('FROM tp_places');
        self::assertStringContainsString(
            'AND in_region = 1 AND host_fit > 0 AND host_vec IS NOT NULL AND county_fips IN (?, ?, ?) AND place_key > ? ORDER BY place_key',
            $call['sql']
        );
        self::assertSame(
            array_merge(self::boxParams(39.003, -77.405, 25000.0), ['51107', '51059', '11001', 'w264230766']),
            $call['params'],
            'the county codes are bound, never written into the statement'
        );
        self::assertStringNotContainsString('51107', $call['sql']);
    }

    public function testByKeysIsQuerySevenAndAnswersByKey(): void
    {
        $display = self::hostRow();
        unset($display['host_vec']);
        $db = (new RecordingDatabase())->queue([$display + [], ['place_key' => 'n5'] + $display]);
        $found = (new PlaceRepository($db))->byKeys('dc', self::VERSION, ['w264230766', 'n5', 'r404', 'n5']);

        $call = $db->only('FROM tp_places');
        self::assertSame(
            'SELECT place_key, place_type, name, brand, lat, lng, county_fips, host_fit, kitchen, size_default, visitor_segment, '
            . 'phone, website, addr_line, city, state_code, postcode, opening_hours_raw, hours_mask '
            . 'FROM tp_places WHERE region_id = ? AND dataset_version = ? AND place_key IN (?, ?, ?) ORDER BY place_key',
            $call['sql']
        );
        self::assertStringNotContainsString('host_vec', $call['sql']);
        self::assertSame(['dc', self::VERSION, 'w264230766', 'n5', 'r404'], $call['params'], 'a key given twice is asked for once');

        self::assertSame(['n5', 'w264230766'], array_keys($found), 'in place_key order; a key the dataset does not hold is absent');
        self::assertSame(
            ['place_key', 'place_type', 'name', 'brand', 'lat', 'lng', 'county_fips', 'host_fit', 'kitchen', 'size_default',
                'visitor_segment', 'phone', 'website', 'addr_line', 'city', 'state_code', 'postcode', 'opening_hours_raw', 'hours_mask'],
            array_keys($found['w264230766'])
        );
        self::assertSame(39.0101, $found['w264230766']['lat']);
        self::assertSame(40.0, $found['w264230766']['size_default']);
        self::assertSame(1.0, $found['w264230766']['host_fit']);
        self::assertNull($found['w264230766']['brand']);
        self::assertSame('unknown', $found['w264230766']['kitchen']);
    }

    public function testByKeysReadsAHundredKeysAtATimeAndNothingForNoKeys(): void
    {
        $db = new RecordingDatabase();
        $repository = new PlaceRepository($db);
        self::assertSame([], $repository->byKeys('dc', self::VERSION, []));
        self::assertSame([], $db->calls, 'no keys, no query');

        $keys = [];
        for ($i = 0; $i < 230; $i++) {
            $keys[] = 'n' . $i;
        }
        $repository->byKeys('dc', self::VERSION, $keys);
        $calls = $db->find('FROM tp_places');
        self::assertCount(3, $calls);
        self::assertSame([102, 102, 32], array_map(static fn (array $c): int => count($c['params']), $calls));
        self::assertSame(100, TpConfig::get('scout.display_keys_per_query'));
    }

    public function testFindHostIsQueryFive(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_places', [
            'place_key' => 'w264230766', 'place_type' => 'taproom', 'visitor_segment' => 'v_nightlife', 'lat' => '39.0101', 'lng' => -77.4102,
        ]);
        $row = (new PlaceRepository($db))->findHost('dc', self::VERSION, 'w264230766');

        $call = $db->only('FROM tp_places');
        self::assertSame('fetch', $call['kind']);
        self::assertSame(
            'SELECT place_key, place_type, visitor_segment, lat, lng FROM tp_places WHERE region_id = ? AND dataset_version = ? AND place_key = ?',
            $call['sql']
        );
        self::assertSame(['dc', self::VERSION, 'w264230766'], $call['params']);
        self::assertSame(
            ['place_key' => 'w264230766', 'place_type' => 'taproom', 'visitor_segment' => 'v_nightlife', 'lat' => 39.0101, 'lng' => -77.4102],
            $row
        );

        self::assertNull((new PlaceRepository(new RecordingDatabase()))->findHost('dc', self::VERSION, 'n1'));
        $office = (new RecordingDatabase())->when('FROM tp_places', ['place_key' => 'w9', 'place_type' => 'office_park', 'visitor_segment' => null, 'lat' => 1, 'lng' => 2]);
        self::assertNull((new PlaceRepository($office))->findHost('dc', self::VERSION, 'w9')['visitor_segment']);
    }

    public function testExportPageWalksAVersionByKeyWithoutTheHostVectors(): void
    {
        $stored = [
            'place_key' => 'n3413156622', 'osm_type' => 'node', 'osm_id' => 3413156622, 'snapshot_date' => '2026-10-03', 'place_type' => 'cafe',
            'geom_kind' => 'point', 'in_region' => '1', 'county_fips' => '51107', 'name' => 'Starbucks', 'brand' => 'Starbucks',
            'lat' => 38.9455121, 'lng' => -77.4516722, 'rival_kind' => 'cafe', 'visitor_segment' => null, 'size_default' => 0, 'host_fit' => 0,
            'kitchen' => 'yes', 'phone' => '+13017428261', 'website' => null, 'addr_line' => '44844 Aviation Drive', 'city' => 'Sterling',
            'state_code' => 'VA', 'postcode' => '20166', 'cuisine' => 'coffee_shop', 'opening_hours_raw' => '04:30-21:00',
            'hours_mask' => 'f0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1f', 'tags_json' => '{"takeaway": "yes", "brand:wikidata": "Q37158"}',
        ];
        $db = (new RecordingDatabase())->queue([$stored, ['place_key' => 'n9', 'tags_json' => null] + $stored]);
        $rows = (new PlaceRepository($db))->exportPage('dc', self::VERSION, 'n100', 500);

        $call = $db->only('FROM tp_places');
        self::assertStringContainsString('WHERE region_id = ? AND dataset_version = ? AND place_key > ? ORDER BY place_key LIMIT 500', $call['sql']);
        self::assertStringNotContainsString('host_vec', $call['sql']);
        self::assertStringNotContainsString('*', $call['sql']);
        self::assertSame(['dc', self::VERSION, 'n100'], $call['params']);

        self::assertCount(2, $rows);
        self::assertSame(
            ['place_key', 'osm_type', 'osm_id', 'snapshot_date', 'place_type', 'geom_kind', 'in_region', 'county_fips', 'name', 'brand',
                'lat', 'lng', 'rival_kind', 'visitor_segment', 'size_default', 'host_fit', 'kitchen', 'phone', 'website', 'addr_line',
                'city', 'state_code', 'postcode', 'cuisine', 'opening_hours_raw', 'hours_mask', 'tags'],
            array_keys($rows[0])
        );
        self::assertSame('3413156622', $rows[0]['osm_id'], 'identifiers stay text');
        self::assertSame(1, $rows[0]['in_region']);
        self::assertSame(['takeaway' => 'yes', 'brand:wikidata' => 'Q37158'], $rows[0]['tags']);
        self::assertNull($rows[1]['tags']);
        self::assertNull($rows[0]['website']);
        self::assertSame(0.0, $rows[0]['host_fit']);
    }

    public function testNothingFoundIsAnEmptyListAndNoStatementSelectsEveryColumn(): void
    {
        $db = new RecordingDatabase();
        $repository = new PlaceRepository($db);
        self::assertSame([], $repository->rivalsNear('dc', self::VERSION, 38.9, -77.0, 1200.0));
        self::assertSame([], $repository->hostsNear('dc', self::VERSION, 38.9, -77.0, 250.0));
        self::assertSame([], $repository->hostVectorPage('dc', self::VERSION, PointRepository::box(38.9, -77.0, 9000.0), ['11001'], ''));
        self::assertSame([], $repository->byKeys('dc', self::VERSION, ['n1']));
        self::assertSame([], $repository->exportPage('dc', self::VERSION, ''));
        self::assertCount(5, $db->statements());
        foreach ($db->calls as $call) {
            self::assertStringNotContainsString('*', $call['sql']);
            self::assertStringContainsString('region_id = ? AND dataset_version = ?', $call['sql']);
            self::assertSame(['dc', self::VERSION], array_slice($call['params'], 0, 2));
        }
    }
}
