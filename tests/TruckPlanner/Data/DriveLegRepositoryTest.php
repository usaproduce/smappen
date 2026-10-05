<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\DriveLegRepository;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

/**
 * `tp_drive_legs`, the shared 30-day cache of Google legs: the statements, what is bound, and how a row
 * reads. That the statements do on MySQL 8 what they say is shown by the HTTP smoke test.
 */
final class DriveLegRepositoryTest extends TestCase
{
    private const BASE_TO_SPOT = [390030, -774050, 389600, -773600];
    private const SPOT_TO_BASE = [389600, -773600, 390030, -774050];

    protected function tearDown(): void
    {
        TpConfig::replace(null);
    }

    /**
     * A leg as MySQL returns it (every number as it comes out of PDO).
     *
     * @param array<int, int> $pair
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function databaseRow(array $pair, array $over = []): array
    {
        return $over + [
            'o_lat_e4' => (string) $pair[0], 'o_lng_e4' => (string) $pair[1],
            'd_lat_e4' => (string) $pair[2], 'd_lng_e4' => (string) $pair[3],
            'src' => 'google_routes', 'route_found' => 1, 'duration_s' => 600, 'distance_m' => 7805,
            'toll_state' => 2, 'toll_cents' => 375, 'fetched_on' => '2026-10-04', 'age_days' => '0',
        ];
    }

    public function testAPairKeyHasOneText(): void
    {
        self::assertSame('390030,-774050,389600,-773600', DriveLegRepository::pairId(self::BASE_TO_SPOT));
        self::assertSame('0,0,-1,1', DriveLegRepository::pairId(['a' => 0, 'b' => 0, 'c' => -1, 'd' => 1]));
        $this->expectException(\LogicException::class);
        DriveLegRepository::pairId([390030, -774050]);
    }

    public function testFindFreshAsksForTheRouteKeyThePairsAndTheLastThirtyDays(): void
    {
        $db = (new RecordingDatabase())->queue([
            self::databaseRow(self::BASE_TO_SPOT),
            self::databaseRow(self::SPOT_TO_BASE, [
                'src' => 'google_distance_matrix', 'route_found' => '0', 'duration_s' => '0', 'distance_m' => '0',
                'toll_state' => '0', 'toll_cents' => null, 'fetched_on' => '2026-09-06', 'age_days' => 29,
            ]),
        ]);
        $rows = (new DriveLegRepository($db))->findFresh('dh', [self::BASE_TO_SPOT, self::SPOT_TO_BASE, self::BASE_TO_SPOT]);

        self::assertCount(1, $db->calls);
        $call = $db->calls[0];
        self::assertSame('fetchAll', $call['kind']);
        self::assertSame(
            'SELECT o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4, src, route_found, duration_s, distance_m, toll_state, toll_cents, '
            . 'DATE(fetched_at) AS fetched_on, TIMESTAMPDIFF(DAY, fetched_at, NOW()) AS age_days '
            . 'FROM tp_drive_legs WHERE route_key = ? '
            . 'AND (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4) IN ((?, ?, ?, ?), (?, ?, ?, ?)) '
            . 'AND fetched_at >= NOW() - INTERVAL 30 DAY',
            $call['sql']
        );
        // the route key first, then each pair once (a pair given twice is asked once)
        self::assertSame(['dh', 390030, -774050, 389600, -773600, 389600, -773600, 390030, -774050], $call['params']);

        self::assertSame(['390030,-774050,389600,-773600', '389600,-773600,390030,-774050'], array_keys($rows));
        self::assertSame(
            [
                'pair' => self::BASE_TO_SPOT, 'src' => 'google_routes', 'route_found' => true, 'duration_s' => 600,
                'distance_m' => 7805, 'toll_state' => 2, 'toll' => 3.75, 'fetched_on' => '2026-10-04', 'age_days' => 0,
            ],
            $rows['390030,-774050,389600,-773600']
        );
        self::assertSame(
            [
                'pair' => self::SPOT_TO_BASE, 'src' => 'google_distance_matrix', 'route_found' => false, 'duration_s' => 0,
                'distance_m' => 0, 'toll_state' => 0, 'toll' => null, 'fetched_on' => '2026-09-06', 'age_days' => 29,
            ],
            $rows['389600,-773600,390030,-774050']
        );
    }

    public function testFindFreshWithoutPairsAsksNothing(): void
    {
        $db = new RecordingDatabase();
        self::assertSame([], (new DriveLegRepository($db))->findFresh('d', []));
        self::assertSame([], $db->calls);
    }

    public function testFindFreshReadsTwoHundredPairsPerStatement(): void
    {
        $pairs = [];
        for ($i = 0; $i < 401; $i++) {
            $pairs[] = [390000 + $i, -774050, 389600, -773600];
        }
        $db = new RecordingDatabase();
        (new DriveLegRepository($db))->findFresh('d', $pairs);
        self::assertCount(3, $db->calls);
        self::assertSame([801, 801, 5], array_map(static fn (array $call): int => count($call['params']), $db->calls));
        self::assertSame(200, substr_count($db->calls[0]['sql'], '(?, ?, ?, ?)'));
        self::assertSame(1, substr_count($db->calls[2]['sql'], '(?, ?, ?, ?)'));
        foreach ($db->calls as $call) {
            self::assertStringNotContainsString('SELECT *', $call['sql']);
            self::assertStringContainsString('fetched_at >= NOW() - INTERVAL 30 DAY', $call['sql']);
        }
    }

    public function testTheLifetimeOfALegIsTheConfiguredNumberOfDays(): void
    {
        self::assertSame(30, TpConfig::get('routing.leg_ttl_days'));
        $config = TpConfig::all();
        $config['routing']['leg_ttl_days'] = 7;
        TpConfig::replace($config);
        $db = new RecordingDatabase();
        $repository = new DriveLegRepository($db);
        $repository->findFresh('d', [self::BASE_TO_SPOT]);
        $db->queue(['expired_legs' => 3]);
        $repository->purgeExpired();
        self::assertStringContainsString('fetched_at >= NOW() - INTERVAL 7 DAY', $db->calls[0]['sql']);
        self::assertStringContainsString('WHERE fetched_at < NOW() - INTERVAL 7 DAY', $db->only('DELETE FROM tp_drive_legs')['sql']);
    }

    public function testUpsertWritesGoogleAnswersInKeyOrderAndStampsThemNow(): void
    {
        $db = new RecordingDatabase();
        (new DriveLegRepository($db))->upsertMany([
            [
                'pair' => self::SPOT_TO_BASE, 'route_key' => 'd', 'src' => 'google_routes', 'route_found' => false,
                'duration_s' => 0, 'distance_m' => 0, 'toll_state' => 1, 'toll' => null,
            ],
            [
                'pair' => self::BASE_TO_SPOT, 'route_key' => 'd', 'src' => 'google_routes', 'route_found' => true,
                'duration_s' => 600, 'distance_m' => 7805, 'toll_state' => 2, 'toll' => 3.75,
            ],
        ]);
        $call = $db->only('INSERT INTO tp_drive_legs');
        self::assertSame('query', $call['kind']);
        self::assertSame(
            'INSERT INTO tp_drive_legs (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4, route_key, src, route_found, duration_s, '
            . 'distance_m, toll_state, toll_cents, fetched_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) '
            . 'ON DUPLICATE KEY UPDATE src = VALUES(src), route_found = VALUES(route_found), duration_s = VALUES(duration_s), '
            . 'distance_m = VALUES(distance_m), toll_state = VALUES(toll_state), toll_cents = VALUES(toll_cents), fetched_at = NOW()',
            $call['sql']
        );
        // ascending key: 389600 before 390030. Booleans as 1 and 0, the toll in whole cents, no float bound.
        self::assertSame(
            [
                389600, -773600, 390030, -774050, 'd', 'google_routes', 0, 0, 0, 1, null,
                390030, -774050, 389600, -773600, 'd', 'google_routes', 1, 600, 7805, 2, 375,
            ],
            $call['params']
        );
    }

    public function testUpsertKeepsOneRowPerPairAndRouteKeyAndWritesAHundredPerStatement(): void
    {
        $rows = [];
        for ($i = 0; $i < 201; $i++) {
            $rows[] = [
                'pair' => [390000 + $i, -774050, 389600, -773600], 'route_key' => 'd', 'src' => 'google_distance_matrix',
                'route_found' => true, 'duration_s' => 60 + $i, 'distance_m' => 1000, 'toll_state' => 0, 'toll' => null,
            ];
        }
        // the same pair again with another route key is another row; the same pair and key again replaces
        $rows[] = ['pair' => [390000, -774050, 389600, -773600], 'route_key' => 'dt', 'src' => 'google_routes',
            'route_found' => true, 'duration_s' => 1, 'distance_m' => 1, 'toll_state' => 0, 'toll' => null];
        $rows[] = ['pair' => [390000, -774050, 389600, -773600], 'route_key' => 'd', 'src' => 'google_routes',
            'route_found' => true, 'duration_s' => 999, 'distance_m' => 1, 'toll_state' => 0, 'toll' => null];
        $db = new RecordingDatabase();
        (new DriveLegRepository($db))->upsertMany($rows);

        $calls = $db->find('INSERT INTO tp_drive_legs');
        self::assertCount(3, $calls);
        self::assertSame([1100, 1100, 22], array_map(static fn (array $call): int => count($call['params']), $calls));
        // the first row is the replaced one, then its sibling with the other route key
        self::assertSame([390000, -774050, 389600, -773600, 'd', 'google_routes', 1, 999], array_slice($calls[0]['params'], 0, 8));
        self::assertSame('dt', $calls[0]['params'][15]);
    }

    public function testNothingButAGoogleAnswerCanBeWritten(): void
    {
        $db = new RecordingDatabase();
        $repository = new DriveLegRepository($db);
        foreach (['straight_line', 'same_point', 'fallback', ''] as $source) {
            try {
                $repository->upsertMany([[
                    'pair' => self::BASE_TO_SPOT, 'route_key' => 'd', 'src' => $source, 'route_found' => true,
                    'duration_s' => 526, 'distance_m' => 8013, 'toll_state' => 0, 'toll' => null,
                ]]);
                self::fail('a row with source "' . $source . '" was accepted');
            } catch (\LogicException $e) {
                self::assertStringContainsString('Google answers only', $e->getMessage());
            }
        }
        $repository->upsertMany([]);
        self::assertSame([], $db->calls);
    }

    public function testPurgeCountsAndDeletesInOneTransaction(): void
    {
        $db = (new RecordingDatabase())->queue(['expired_legs' => '731']);
        self::assertSame(500, (new DriveLegRepository($db))->purgeExpired(500));
        self::assertSame(['begin', 'fetch', 'query', 'commit'], $db->kinds());
        self::assertSame(
            'SELECT COUNT(*) AS expired_legs FROM tp_drive_legs WHERE fetched_at < NOW() - INTERVAL 30 DAY',
            $db->calls[1]['sql']
        );
        self::assertSame(
            'DELETE FROM tp_drive_legs WHERE fetched_at < NOW() - INTERVAL 30 DAY LIMIT 500',
            $db->calls[2]['sql']
        );
        self::assertSame([], $db->calls[1]['params']);
        self::assertSame([], $db->calls[2]['params']);
    }

    public function testPurgeWithoutALimitDeletesEveryExpiredLeg(): void
    {
        $db = (new RecordingDatabase())->queue(['expired_legs' => 731]);
        self::assertSame(731, (new DriveLegRepository($db))->purgeExpired());
        self::assertSame('DELETE FROM tp_drive_legs WHERE fetched_at < NOW() - INTERVAL 30 DAY', $db->calls[2]['sql']);

        $fewer = (new RecordingDatabase())->queue(['expired_legs' => 12]);
        self::assertSame(12, (new DriveLegRepository($fewer))->purgeExpired(500));
    }

    public function testPurgeWritesNothingWhenNothingHasExpired(): void
    {
        $db = (new RecordingDatabase())->queue(['expired_legs' => 0]);
        self::assertSame(0, (new DriveLegRepository($db))->purgeExpired(500));
        self::assertSame(['begin', 'fetch', 'commit'], $db->kinds());
        self::assertSame([], $db->find('DELETE'));
    }

    public function testAFailedPurgeIsRolledBack(): void
    {
        $db = (new RecordingDatabase())->queue(['expired_legs' => 4])->failOn('DELETE FROM tp_drive_legs');
        try {
            (new DriveLegRepository($db))->purgeExpired(500);
            self::fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame(['begin', 'fetch', 'query', 'rollback'], $db->kinds());
        }
    }

    public function testTheTableHasNoTenantColumnAndNoStatementNamesOne(): void
    {
        $db = (new RecordingDatabase())->queue([], ['expired_legs' => 1]);
        $repository = new DriveLegRepository($db);
        $repository->findFresh('d', [self::BASE_TO_SPOT]);
        $repository->upsertMany([[
            'pair' => self::BASE_TO_SPOT, 'route_key' => 'd', 'src' => 'google_routes', 'route_found' => true,
            'duration_s' => 600, 'distance_m' => 7805, 'toll_state' => 0, 'toll' => null,
        ]]);
        $repository->purgeExpired(1);
        foreach ($db->statements() as $sql) {
            self::assertStringNotContainsString('organization_id', $sql);
            self::assertStringNotContainsString('truck_id', $sql);
        }
        // and no bound value is a float, a boolean or an array
        foreach ($db->calls as $call) {
            foreach ($call['params'] as $value) {
                self::assertTrue(is_int($value) || is_string($value) || $value === null);
            }
        }
    }
}
