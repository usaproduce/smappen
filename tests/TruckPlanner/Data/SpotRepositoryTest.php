<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use PHPUnit\Framework\TestCase;

final class SpotRepositoryTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const USER = '22222222-2222-4222-8222-222222222222';
    private const TRUCK = '33333333-3333-4333-8333-333333333333';
    private const SPOT = '44444444-4444-4444-8444-444444444444';
    private const VERSION = 'dc-20261003-3fa9c2d1';

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * Three blocks of vectors whose 150 numbers are all different and awkward: long fractions, a
     * denormal, the largest double, a negative zero.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function vectors(): array
    {
        $out = [];
        foreach (SpotRepository::BLOCKS as $b => $level) {
            $series = static function (int $offset) use ($b): array {
                $list = [];
                for ($i = 0; $i < 16; $i++) {
                    $list[] = ($b + 1) * 1000.0 / 7.0 + $offset * 0.1 + $i / 3.0;
                }
                return $list;
            };
            $out[$level] = [
                'capture' => ['day' => $series(1), 'eve' => $series(2)],
                'nearby' => $series(3),
                'rivals' => ['day' => 0.1 + 0.2 + $b, 'eve' => 0.8339850000000001 * ($b + 1)],
                // labels a LocationVectors carries and the codec does not store
                'within' => $series(4),
                'visibility' => $level,
            ];
        }
        $out['hidden']['capture']['day'][0] = 4.9e-324;
        $out['hidden']['capture']['day'][1] = PHP_FLOAT_MAX;
        $out['normal']['nearby'][15] = -0.0;
        $out['prominent']['capture']['eve'][7] = 417.13452041987807;
        return $out;
    }

    /**
     * @return array<string, mixed> the seven keys of a vector write
     */
    private static function vectorWrite(): array
    {
        return [
            'vectors' => self::vectors(),
            'vec_in_region' => true,
            'vec_points_used' => 8,
            'vec_excluded' => 600.0000000000001,
            'vec_region_id' => 'dc',
            'vec_dataset' => self::VERSION,
            'vec_seeds_rev' => 1,
        ];
    }

    /**
     * A spot as MySQL returns it.
     *
     * @return array<string, mixed>
     */
    private static function databaseRow(?string $vectorsBin = null): array
    {
        return [
            'id' => self::SPOT, 'organization_id' => self::ORG, 'truck_id' => self::TRUCK, 'created_by' => self::USER,
            'name' => 'Herndon office park', 'lat' => 38.96000000000001, 'lng' => -77.36, 'address' => '13800 Example Rd',
            'county_fips' => '51059', 'notes' => 'Loading dock side', 'visibility' => 'prominent',
            'host_segment' => 'w_office', 'host_size' => 600.5, 'host_size_source' => 'owner', 'host_only_food' => 1,
            'host_place_type' => 'office_park', 'host_name' => 'Example Plaza', 'host_contact' => 'Pat',
            'host_phone' => '703-555-0100', 'host_website' => 'https://www.openstreetmap.org/way/1',
            'place_key' => 'w1', 'host_point_id' => null, 'google_place_id' => 'ChIJexample',
            'fee_flat_cents' => 2550, 'fee_pct' => 0.1, 'fee_min_cents' => 7500,
            'allowed_json' => '{"days": [true, true, true, true, true, false, false], "open_minute": 660, "close_minute": 840}',
            'vectors_bin' => $vectorsBin, 'vec_in_region' => 1, 'vec_points_used' => 8, 'vec_excluded' => 600.0000000000001,
            'vec_region_id' => 'dc', 'vec_dataset' => self::VERSION, 'vec_seeds_rev' => 1, 'vec_at' => '2026-10-04 23:50:12',
            'archived_at' => null, 'created_at' => '2026-10-04 23:50:12', 'updated_at' => '2026-10-04 23:58:40',
        ];
    }

    /**
     * The bound values of an INSERT, keyed by column.
     *
     * @param array{sql: string, params: array<int|string, mixed>} $call
     * @return array{0: array<string, mixed>, 1: array<string, string>} [bound values, value expressions]
     */
    private static function inserted(array $call): array
    {
        self::assertSame(1, preg_match('/^INSERT INTO tp_spots \((.+?)\) VALUES \((.+)\)$/', $call['sql'], $m));
        $columns = explode(', ', $m[1]);
        $expressions = explode(', ', $m[2]);
        self::assertSame(count($columns), count($expressions));
        $bound = [];
        $params = array_values($call['params']);
        foreach ($columns as $i => $column) {
            if (str_contains($expressions[$i], '?')) {
                $bound[$column] = array_shift($params);
            }
        }
        self::assertSame([], $params, 'every bound value belongs to a column');
        return [$bound, array_combine($columns, $expressions)];
    }

    /** The bits of a double, so that 0.0 and -0.0 differ and NAN could be compared. */
    private static function bits(float $x): string
    {
        return bin2hex(pack('e', $x));
    }

    // ------------------------------------------------------------------------------------ reads

    public function testTheBlockOrderIsTheVisibilityVocabularyOfTheModel(): void
    {
        self::assertSame(Estimator::seed(Seeds::defaults(), 'vocabulary.visibility_levels'), SpotRepository::BLOCKS);
        self::assertSame('vectors', SpotRepository::VECTOR_KEYS[0]);
        self::assertCount(7, SpotRepository::VECTOR_KEYS);
    }

    public function testListActiveIsScopedOrderedAndLeavesArchivedSpotsOut(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_spots', [self::databaseRow()]);
        $repo = new SpotRepository($db);
        $rows = $repo->listActive(self::ORG, self::TRUCK);
        $repo->listActive(self::ORG, self::TRUCK, true);

        self::assertCount(1, $rows);
        self::assertSame(self::SPOT, $rows[0]['id']);
        [$active, $all] = $db->find('FROM tp_spots');
        self::assertSame('fetchAll', $active['kind']);
        self::assertStringEndsWith(
            'WHERE organization_id = ? AND truck_id = ? AND archived_at IS NULL ORDER BY name, id',
            $active['sql']
        );
        self::assertStringEndsWith('WHERE organization_id = ? AND truck_id = ? ORDER BY name, id', $all['sql']);
        self::assertSame([self::ORG, self::TRUCK], $active['params']);
        self::assertSame([self::ORG, self::TRUCK], $all['params']);
        self::assertStringNotContainsString('*', $active['sql']);
        foreach (['fee_flat_cents', 'allowed_json', 'vectors_bin', 'vec_seeds_rev', 'vec_at', 'archived_at', 'updated_at'] as $column) {
            self::assertStringContainsString($column, $active['sql']);
        }
    }

    public function testFindCarriesTheOrganizationAndHidesArchivedSpotsUnlessAsked(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_spots', self::databaseRow());
        $repo = new SpotRepository($db);
        self::assertIsArray($repo->find(self::SPOT, self::ORG));
        self::assertIsArray($repo->find(self::SPOT, self::ORG, true));

        [$active, $any] = $db->find('FROM tp_spots');
        self::assertSame('fetch', $active['kind']);
        self::assertStringEndsWith('WHERE id = ? AND organization_id = ? AND archived_at IS NULL', $active['sql']);
        self::assertStringEndsWith('WHERE id = ? AND organization_id = ?', $any['sql']);
        self::assertSame([self::SPOT, self::ORG], $active['params']);
        self::assertSame([self::SPOT, self::ORG], $any['params']);
    }

    public function testASpotThatIsNotThereIsNull(): void
    {
        self::assertNull((new SpotRepository(new RecordingDatabase()))->find(self::SPOT, self::ORG));
        self::assertSame([], (new SpotRepository(new RecordingDatabase()))->listActive(self::ORG, self::TRUCK));
    }

    public function testFindManyAsksOnceForDistinctIdsAndKeysTheRowsById(): void
    {
        $other = self::databaseRow();
        $other['id'] = '55555555-5555-4555-8555-555555555555';
        $other['archived_at'] = '2026-10-01 08:00:00';
        $db = (new RecordingDatabase())->when('FROM tp_spots', [self::databaseRow(), $other]);
        $rows = (new SpotRepository($db))->findMany([self::SPOT, $other['id'], self::SPOT, 'not-there'], self::ORG);

        self::assertSame([self::SPOT, $other['id']], array_keys($rows));
        self::assertSame('2026-10-01 08:00:00', $rows[$other['id']]['archived_at'], 'archived spots are found too');
        $call = $db->only('FROM tp_spots');
        self::assertStringEndsWith('WHERE organization_id = ? AND id IN (?, ?, ?)', $call['sql']);
        self::assertStringNotContainsString('archived_at IS NULL', $call['sql']);
        self::assertSame([self::ORG, self::SPOT, $other['id'], 'not-there'], $call['params']);
    }

    public function testFindManyWithNoIdAsksNothingAndLongListsAreSplit(): void
    {
        $db = new RecordingDatabase();
        self::assertSame([], (new SpotRepository($db))->findMany([], self::ORG));
        self::assertSame([], $db->calls);

        $ids = [];
        for ($i = 0; $i < 450; $i++) {
            $ids[] = 'id-' . $i;
        }
        (new SpotRepository($db))->findMany($ids, self::ORG);
        $calls = $db->find('FROM tp_spots');
        self::assertCount(3, $calls);
        self::assertCount(201, $calls[0]['params']);
        self::assertCount(51, $calls[2]['params']);
        foreach ($calls as $call) {
            self::assertSame(self::ORG, $call['params'][0]);
        }
    }

    public function testCountActive(): void
    {
        $db = (new RecordingDatabase())->when('COUNT(*)', ['spot_count' => '7']);
        self::assertSame(7, (new SpotRepository($db))->countActive(self::ORG, self::TRUCK));
        $call = $db->only('COUNT(*)');
        self::assertStringEndsWith('WHERE organization_id = ? AND truck_id = ? AND archived_at IS NULL', $call['sql']);
        self::assertSame([self::ORG, self::TRUCK], $call['params']);
        self::assertSame(0, (new SpotRepository(new RecordingDatabase()))->countActive(self::ORG, self::TRUCK));
    }

    public function testReadsAreNormalised(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_spots', self::databaseRow());
        $row = (new SpotRepository($db))->find(self::SPOT, self::ORG);
        self::assertIsArray($row);

        self::assertSame(self::SPOT, $row['id']);
        self::assertSame(self::ORG, $row['organization_id']);
        self::assertSame(self::TRUCK, $row['truck_id']);
        self::assertSame(self::USER, $row['created_by']);
        self::assertSame('Herndon office park', $row['name']);
        self::assertSame(38.96000000000001, $row['lat']);
        self::assertSame(-77.36, $row['lng']);
        // cents become dollars under the name without the suffix
        self::assertSame(25.5, $row['fee_flat']);
        self::assertSame(75.0, $row['fee_min']);
        self::assertSame(0.1, $row['fee_pct']);
        // the JSON column is decoded, in a fixed key order
        self::assertSame(
            ['days' => [true, true, true, true, true, false, false], 'open_minute' => 660, 'close_minute' => 840],
            $row['allowed']
        );
        // flags are booleans, counts ints, doubles floats
        self::assertTrue($row['host_only_food']);
        self::assertTrue($row['vec_in_region']);
        self::assertSame(8, $row['vec_points_used']);
        self::assertSame(600.0000000000001, $row['vec_excluded']);
        self::assertSame(600.5, $row['host_size']);
        self::assertSame(1, $row['vec_seeds_rev']);
        self::assertSame('dc', $row['vec_region_id']);
        self::assertSame(self::VERSION, $row['vec_dataset']);
        self::assertNull($row['host_point_id']);
        self::assertNull($row['vectors']);
        self::assertNull($row['vectors_sha1']);
        self::assertNull($row['archived_at']);
        self::assertSame('2026-10-04 23:58:40', $row['updated_at']);
        foreach (['fee_flat_cents', 'fee_min_cents', 'allowed_json', 'vectors_bin'] as $stored) {
            self::assertArrayNotHasKey($stored, $row);
        }
    }

    public function testStringsFromTheDriverAreCastTooAndNullsStayNull(): void
    {
        $stored = self::databaseRow();
        foreach ($stored as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $stored[$key] = (string) $value;
            }
        }
        foreach (['created_by', 'county_fips', 'notes', 'host_segment', 'host_size', 'host_size_source', 'host_place_type',
            'host_name', 'place_key', 'google_place_id', 'allowed_json', 'vec_region_id', 'vec_dataset', 'vec_seeds_rev', 'vec_at'] as $column) {
            $stored[$column] = null;
        }
        $stored['host_only_food'] = '0';
        $stored['archived_at'] = '2026-10-05 01:02:03';
        $db = (new RecordingDatabase())->when('FROM tp_spots', $stored);
        $row = (new SpotRepository($db))->find(self::SPOT, self::ORG, true);
        self::assertIsArray($row);

        self::assertSame(25.5, $row['fee_flat']);
        self::assertSame(8, $row['vec_points_used']);
        self::assertFalse($row['host_only_food']);
        self::assertTrue($row['vec_in_region']);
        foreach (['created_by', 'county_fips', 'notes', 'host_segment', 'host_size', 'host_size_source', 'host_place_type',
            'host_name', 'place_key', 'google_place_id', 'allowed', 'vec_region_id', 'vec_dataset', 'vec_seeds_rev', 'vec_at'] as $key) {
            self::assertNull($row[$key], $key);
        }
        self::assertSame('2026-10-05 01:02:03', $row['archived_at']);
    }

    public function testAnAllowedObjectComesBackInOneKeyOrderWhateverOrderMySqlPrints(): void
    {
        $stored = self::databaseRow();
        $stored['allowed_json'] = '{"days": [1, 0, 1, 0, 1, 0, 1], "close_minute": 1500, "open_minute": 1020}';
        $row = (new SpotRepository((new RecordingDatabase())->when('FROM tp_spots', $stored)))->find(self::SPOT, self::ORG);
        self::assertIsArray($row);
        self::assertSame(
            ['days' => [true, false, true, false, true, false, true], 'open_minute' => 1020, 'close_minute' => 1500],
            $row['allowed']
        );

        foreach (['[]', '{"days": [true]}', 'null', 'not json'] as $broken) {
            $stored['allowed_json'] = $broken;
            $row = (new SpotRepository((new RecordingDatabase())->when('FROM tp_spots', $stored)))->find(self::SPOT, self::ORG);
            self::assertIsArray($row);
            self::assertNull($row['allowed'], $broken);
        }
    }

    // ------------------------------------------------------------------------------------ vectors

    public function testTheThreeVectorSetsAreStoredAndReadBackBitForBit(): void
    {
        $write = self::vectorWrite();
        $db = new RecordingDatabase();
        (new SpotRepository($db))->setVectors(self::SPOT, self::ORG, $write);

        $call = $db->only('UPDATE tp_spots');
        self::assertSame(
            'UPDATE tp_spots SET vectors_bin = UNHEX(?), vec_in_region = ?, vec_points_used = ?, vec_excluded = ?, '
            . 'vec_region_id = ?, vec_dataset = ?, vec_seeds_rev = ?, vec_at = NOW() WHERE id = ? AND organization_id = ?',
            $call['sql']
        );
        $hex = $call['params'][0];
        self::assertIsString($hex);
        self::assertSame(2400, strlen($hex), '150 doubles of 16 hexadecimal characters');
        self::assertSame(1, preg_match('/^[0-9a-f]+$/', $hex), 'only ASCII travels through the bind');
        self::assertSame([1, 8, '600.0000000000001', 'dc', self::VERSION, 1, self::SPOT, self::ORG], array_slice($call['params'], 1));

        // What MySQL stores for UNHEX(?) is these bytes; reading them gives the same doubles.
        $bytes = (string) hex2bin($hex);
        self::assertSame(1200, strlen($bytes));
        $read = (new SpotRepository((new RecordingDatabase())->when('FROM tp_spots', self::databaseRow($bytes))))
            ->find(self::SPOT, self::ORG);
        self::assertIsArray($read);
        self::assertSame(sha1($bytes), $read['vectors_sha1']);
        self::assertSame(SpotRepository::BLOCKS, array_keys($read['vectors']));

        $compared = 0;
        foreach (SpotRepository::BLOCKS as $level) {
            $in = $write['vectors'][$level];
            $out = $read['vectors'][$level];
            self::assertSame(['capture', 'nearby', 'rivals'], array_keys($out), 'only the stored part comes back');
            foreach ([[$in['capture']['day'], $out['capture']['day']], [$in['capture']['eve'], $out['capture']['eve']], [$in['nearby'], $out['nearby']]] as [$a, $b]) {
                self::assertCount(16, $b);
                foreach ($a as $i => $x) {
                    self::assertSame(self::bits($x), self::bits($b[$i]));
                    $compared++;
                }
            }
            foreach (['day', 'eve'] as $regime) {
                self::assertSame(self::bits($in['rivals'][$regime]), self::bits($out['rivals'][$regime]));
                $compared++;
            }
        }
        self::assertSame(150, $compared);
        self::assertSame(self::bits(-0.0), self::bits($read['vectors']['normal']['nearby'][15]));
        self::assertSame(4.9e-324, $read['vectors']['hidden']['capture']['day'][0]);
        self::assertSame(PHP_FLOAT_MAX, $read['vectors']['hidden']['capture']['day'][1]);
    }

    public function testVectorsOfTheModelSurviveTheRoundTrip(): void
    {
        // The worked layout of 02_MODEL.md 4.4: eight blocks of 250 office jobs north of the truck.
        $A = Seeds::defaults();
        $sources = [];
        foreach ([75, 125, 175, 225, 275, 325, 375, 425] as $i => $metres) {
            $sources[] = [
                'id' => 'b' . ($i + 1), 'lat' => 38.96 + $metres / 6371008.8 * 180.0 / 3.141592653589793, 'lng' => -77.36,
                'base' => [0.0, 250.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0],
                'rivals' => ['day' => 0.3 + $i / 10.0, 'eve' => 0.2 + $i / 7.0],
            ];
        }
        $vectors = [];
        foreach (SpotRepository::BLOCKS as $level) {
            $vectors[$level] = Estimator::captureAtPoint($A, 38.96, -77.36, $level, $sources, [], Estimator::hostExclusion($A, null));
        }
        $db = new RecordingDatabase();
        (new SpotRepository($db))->setVectors(self::SPOT, self::ORG, ['vectors' => $vectors] + self::vectorWrite());
        $bytes = (string) hex2bin((string) $db->only('UPDATE tp_spots')['params'][0]);
        $read = (new SpotRepository((new RecordingDatabase())->when('FROM tp_spots', self::databaseRow($bytes))))
            ->find(self::SPOT, self::ORG);
        self::assertIsArray($read);
        foreach (SpotRepository::BLOCKS as $level) {
            self::assertSame($vectors[$level]['capture'], $read['vectors'][$level]['capture']);
            self::assertSame($vectors[$level]['nearby'], $read['vectors'][$level]['nearby']);
            self::assertSame($vectors[$level]['rivals'], $read['vectors'][$level]['rivals']);
        }
        self::assertNotSame($vectors['hidden']['capture'], $vectors['normal']['capture'], 'the three levels differ');
        self::assertGreaterThan(400.0, $read['vectors']['normal']['capture']['day'][1]);
    }

    public function testBytesOfAnotherLengthAreNotVectors(): void
    {
        foreach (['', str_repeat("\0", 400), str_repeat("\0", 1201)] as $bytes) {
            $row = (new SpotRepository((new RecordingDatabase())->when('FROM tp_spots', self::databaseRow($bytes))))
                ->find(self::SPOT, self::ORG);
            self::assertIsArray($row);
            self::assertNull($row['vectors']);
            self::assertNull($row['vectors_sha1']);
        }
    }

    public function testAVectorWriteIsWholeOrRefused(): void
    {
        $db = new RecordingDatabase();
        $repo = new SpotRepository($db);
        $partial = self::vectorWrite();
        unset($partial['vec_dataset']);
        $attempts = [
            static fn () => $repo->setVectors(self::SPOT, self::ORG, $partial),
            static fn () => $repo->setVectors(self::SPOT, self::ORG, self::vectorWrite() + ['name' => 'x']),
            static fn () => $repo->setVectors(self::SPOT, self::ORG, ['vectors' => null] + self::vectorWrite()),
            static fn () => $repo->update(self::SPOT, self::ORG, ['vectors' => self::vectors()]),
            static fn () => $repo->update(self::SPOT, self::ORG, ['name' => 'x', 'vec_dataset' => self::VERSION]),
            static fn () => $repo->create(self::ORG, self::TRUCK, null, ['name' => 'x', 'lat' => 1.0, 'lng' => 2.0, 'vec_seeds_rev' => 1]),
        ];
        foreach ($attempts as $i => $attempt) {
            try {
                $attempt();
                self::fail('attempt ' . $i . ' was accepted');
            } catch (\LogicException $e) {
                self::assertSame([], $db->calls);
            }
        }

        $short = self::vectorWrite();
        $short['vectors']['normal']['nearby'] = [1.0, 2.0];
        $this->expectException(\LengthException::class);
        $repo->setVectors(self::SPOT, self::ORG, $short);
    }

    public function testANonFiniteNumberNeverReachesTheDatabase(): void
    {
        $db = new RecordingDatabase();
        $write = self::vectorWrite();
        $write['vectors']['prominent']['rivals']['eve'] = INF;
        try {
            (new SpotRepository($db))->setVectors(self::SPOT, self::ORG, $write);
            self::fail('INF was stored');
        } catch (\DomainException $e) {
            self::assertSame([], $db->calls);
        }
        try {
            (new SpotRepository($db))->update(self::SPOT, self::ORG, ['fee_pct' => NAN]);
            self::fail('NAN was bound');
        } catch (\DomainException $e) {
            self::assertSame([], $db->calls);
        }
    }

    // ------------------------------------------------------------------------------------ writes

    public function testCreateWritesEveryColumnWithTheStorageEncodings(): void
    {
        $db = new RecordingDatabase();
        $id = (new SpotRepository($db))->create(self::ORG, self::TRUCK, self::USER, [
            'name' => 'Herndon office park',
            'lat' => 38.91006831234568,
            'lng' => -77.36,
            'address' => '13800 Example Rd',
            'county_fips' => '51059',
            'visibility' => 'prominent',
            'host_segment' => 'w_office',
            'host_size' => 600.0,
            'host_size_source' => 'owner',
            'host_only_food' => true,
            'fee_flat' => 12.345,
            'fee_pct' => 0.1,
            'fee_min' => 75.0,
            'allowed' => ['days' => [true, true, true, true, true, false, false], 'open_minute' => 660, 'close_minute' => 840],
        ] + self::vectorWrite());

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $call = $db->only('INSERT INTO tp_spots');
        self::assertSame('query', $call['kind']);
        [$bound, $expressions] = self::inserted($call);

        // all 38 columns of the table except archived_at: 34 bound, vec_at and the two timestamps by MySQL
        self::assertCount(37, $expressions);
        self::assertCount(34, $bound);
        self::assertArrayNotHasKey('archived_at', $expressions);
        self::assertSame('NOW()', $expressions['vec_at']);
        self::assertSame('NOW()', $expressions['created_at']);
        self::assertSame('NOW()', $expressions['updated_at']);

        self::assertSame($id, $bound['id']);
        self::assertSame(self::ORG, $bound['organization_id']);
        self::assertSame(self::TRUCK, $bound['truck_id']);
        self::assertSame(self::USER, $bound['created_by']);
        // floats travel as text that round-trips, never as PHP floats
        self::assertSame('38.91006831234568', $bound['lat']);
        self::assertSame('-77.36', $bound['lng']);
        self::assertSame('0.1', $bound['fee_pct']);
        self::assertSame('600', $bound['host_size']);
        self::assertSame('600.0000000000001', $bound['vec_excluded']);
        // dollars become whole cents, half a cent away from zero
        self::assertSame(1235, $bound['fee_flat_cents']);
        self::assertSame(7500, $bound['fee_min_cents']);
        // booleans as 1 and 0, JSON by hand
        self::assertSame(1, $bound['host_only_food']);
        self::assertSame(1, $bound['vec_in_region']);
        self::assertSame('{"days":[true,true,true,true,true,false,false],"open_minute":660,"close_minute":840}', $bound['allowed_json']);
        self::assertSame(8, $bound['vec_points_used']);
        self::assertSame(1, $bound['vec_seeds_rev']);
        foreach ($call['params'] as $value) {
            self::assertFalse(is_float($value), 'a PHP float was bound');
            self::assertFalse(is_bool($value), 'a PHP boolean was bound');
            self::assertFalse(is_array($value), 'an array was bound');
        }
        // the binary column, and only it, goes through UNHEX
        self::assertSame('UNHEX(?)', $expressions['vectors_bin']);
        self::assertSame(1, substr_count($call['sql'], 'UNHEX('));
        self::assertSame(2400, strlen((string) $bound['vectors_bin']));
    }

    public function testCreateFillsTheDefaultsOfASpotWithoutHostFeeOrVectors(): void
    {
        $db = new RecordingDatabase();
        (new SpotRepository($db))->create(self::ORG, self::TRUCK, null, ['name' => 'Lot 4', 'lat' => 38.96, 'lng' => -77.36]);
        [$bound, $expressions] = self::inserted($db->only('INSERT INTO tp_spots'));

        self::assertNull($bound['created_by']);
        self::assertSame('', $bound['address']);
        self::assertSame('normal', $bound['visibility']);
        self::assertSame(0, $bound['host_only_food']);
        self::assertSame(0, $bound['fee_flat_cents']);
        self::assertSame('0', $bound['fee_pct']);
        self::assertSame(0, $bound['fee_min_cents']);
        self::assertSame(0, $bound['vec_in_region']);
        self::assertSame(0, $bound['vec_points_used']);
        self::assertSame('0', $bound['vec_excluded']);
        foreach (['county_fips', 'notes', 'host_segment', 'host_size', 'host_size_source', 'host_place_type', 'host_name',
            'host_contact', 'host_phone', 'host_website', 'place_key', 'host_point_id', 'google_place_id', 'allowed_json',
            'vectors_bin', 'vec_region_id', 'vec_dataset', 'vec_seeds_rev'] as $column) {
            self::assertArrayHasKey($column, $bound);
            self::assertNull($bound[$column], $column);
        }
        self::assertSame('NULL', $expressions['vec_at'], 'no vectors, no stamp');
    }

    public function testCreateRefusesARowWithoutNameOrPoint(): void
    {
        $db = new RecordingDatabase();
        foreach ([['lat' => 1.0, 'lng' => 2.0], ['name' => 'x', 'lng' => 2.0], ['name' => 'x', 'lat' => 1.0]] as $row) {
            try {
                (new SpotRepository($db))->create(self::ORG, self::TRUCK, self::USER, $row);
                self::fail('an incomplete row was accepted');
            } catch (\LogicException $e) {
                self::assertSame([], $db->calls);
            }
        }
    }

    public function testUpdateChangesOnlyTheGivenColumnsOfThatOrganization(): void
    {
        $db = new RecordingDatabase();
        (new SpotRepository($db))->update(self::SPOT, self::ORG, [
            'name' => 'New name',
            'fee_flat' => 2.675,
            'host_only_food' => false,
            'lat' => 38.91006831234568,
            'notes' => null,
            'allowed' => null,
            'host_size' => null,
        ]);
        $call = $db->only('UPDATE tp_spots');
        self::assertSame(
            'UPDATE tp_spots SET name = ?, fee_flat_cents = ?, host_only_food = ?, lat = ?, notes = ?, allowed_json = ?, '
            . 'host_size = ? WHERE id = ? AND organization_id = ?',
            $call['sql']
        );
        self::assertSame(['New name', 268, 0, '38.91006831234568', null, null, null, self::SPOT, self::ORG], $call['params']);
        self::assertStringNotContainsString('vec_at', $call['sql'], 'vectors untouched, stamp untouched');
        self::assertStringNotContainsString('updated_at', $call['sql'], 'left to the ON UPDATE rule of the column');
    }

    public function testUpdateWritesVectorsWithTheOtherColumnsInOneStatement(): void
    {
        $db = new RecordingDatabase();
        (new SpotRepository($db))->update(self::SPOT, self::ORG, ['lat' => 38.97, 'lng' => -77.35, 'county_fips' => '51107'] + self::vectorWrite());
        self::assertCount(1, $db->calls);
        $sql = $db->only('UPDATE tp_spots')['sql'];
        self::assertStringContainsString('lat = ?, lng = ?, county_fips = ?, vectors_bin = UNHEX(?)', $sql);
        self::assertStringContainsString('vec_seeds_rev = ?, vec_at = NOW() WHERE id = ? AND organization_id = ?', $sql);
    }

    public function testUpdateWithNothingDoesNothing(): void
    {
        $db = new RecordingDatabase();
        (new SpotRepository($db))->update(self::SPOT, self::ORG, []);
        self::assertSame([], $db->calls);
    }

    public function testUpdateRefusesAKeyThatIsNotAColumnAndANullWhereTheColumnHasNone(): void
    {
        $db = new RecordingDatabase();
        $repo = new SpotRepository($db);
        foreach (['organization_id', 'id', 'truck_id', 'fee_flat_cents', 'vectors_bin', 'archived_at', 'created_at', 'name = name, id'] as $key) {
            try {
                $repo->update(self::SPOT, self::ORG, [$key => 'x']);
                self::fail('"' . $key . '" was accepted as a column');
            } catch (\LogicException $e) {
                self::assertSame([], $db->calls);
            }
        }
        foreach (['name', 'lat', 'address', 'visibility', 'host_only_food', 'fee_flat', 'fee_pct'] as $key) {
            try {
                $repo->update(self::SPOT, self::ORG, [$key => null]);
                self::fail($key . ' was set to NULL');
            } catch (\LogicException $e) {
                self::assertSame([], $db->calls);
            }
        }
    }

    public function testArchiveStampsTheRowAndKeepsIt(): void
    {
        $db = new RecordingDatabase();
        (new SpotRepository($db))->archive(self::SPOT, self::ORG);
        self::assertCount(1, $db->calls);
        $call = $db->calls[0];
        self::assertSame('UPDATE tp_spots SET archived_at = NOW() WHERE id = ? AND organization_id = ? AND archived_at IS NULL', $call['sql']);
        self::assertSame([self::SPOT, self::ORG], $call['params']);
        self::assertStringNotContainsString('DELETE', $call['sql']);
    }

    public function testStaleIdsComparesRegionDatasetAndSeedsRevisionNullSafely(): void
    {
        $db = (new RecordingDatabase())->when('SELECT id FROM tp_spots', [['id' => 'a'], ['id' => 'b']]);
        $repo = new SpotRepository($db);
        self::assertSame(['a', 'b'], $repo->staleIds(self::ORG, self::TRUCK, 'dc', self::VERSION, 1, 50));
        $repo->staleIds(self::ORG, self::TRUCK, 'none', null, 2, -3);

        [$first, $second] = $db->find('SELECT id FROM tp_spots');
        self::assertSame(
            'SELECT id FROM tp_spots WHERE organization_id = ? AND truck_id = ? AND archived_at IS NULL '
            . 'AND (vectors_bin IS NULL OR NOT (vec_region_id <=> ? AND vec_dataset <=> ? AND vec_seeds_rev <=> ?)) '
            . 'ORDER BY id LIMIT ?',
            $first['sql']
        );
        self::assertSame([self::ORG, self::TRUCK, 'dc', self::VERSION, 1, 50], $first['params']);
        self::assertSame([self::ORG, self::TRUCK, 'none', null, 2, 0], $second['params'], 'no active version is NULL, not text');
    }

    public function testEveryStatementIsScopedToTheOrganization(): void
    {
        $db = (new RecordingDatabase())
            ->when('COUNT(*)', ['spot_count' => 1])
            ->when('SELECT id FROM tp_spots', [])
            ->when('AND id IN', [])
            ->when('ORDER BY name, id', [])
            ->when('WHERE id = ? AND organization_id = ?', self::databaseRow());
        $repo = new SpotRepository($db);
        $repo->listActive(self::ORG, self::TRUCK);
        $repo->find(self::SPOT, self::ORG);
        $repo->findMany([self::SPOT], self::ORG);
        $repo->countActive(self::ORG, self::TRUCK);
        $repo->create(self::ORG, self::TRUCK, self::USER, ['name' => 'x', 'lat' => 1.0, 'lng' => 2.0]);
        $repo->update(self::SPOT, self::ORG, ['name' => 'y']);
        $repo->setVectors(self::SPOT, self::ORG, self::vectorWrite());
        $repo->archive(self::SPOT, self::ORG);
        $repo->staleIds(self::ORG, self::TRUCK, 'dc', self::VERSION, 1, 50);

        self::assertCount(9, $db->calls);
        $unhex = 0;
        foreach ($db->calls as $call) {
            self::assertContains(self::ORG, $call['params']);
            if (!str_starts_with($call['sql'], 'INSERT')) {
                self::assertStringContainsString('organization_id = ?', $call['sql']);
            }
            self::assertDoesNotMatchRegularExpression('/SELECT\s+\*/', $call['sql']);
            self::assertStringNotContainsString('DELETE', $call['sql']);
            $unhex += substr_count($call['sql'], 'UNHEX(');
        }
        self::assertSame(2, $unhex, 'vectors_bin in the INSERT and in the vector write, nothing else');
        self::assertStringContainsString('vectors_bin = UNHEX(?)', $db->only('SET vectors_bin')['sql']);
    }
}
