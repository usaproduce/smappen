<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\DriveOverrideRepository;
use PHPUnit\Framework\TestCase;

/**
 * `tp_drive_overrides`, the owner's corrections of a drive: every statement is scoped to the
 * organization, values are bound in storage units, and a row reads in API units.
 */
final class DriveOverrideRepositoryTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const TRUCK = '33333333-3333-4333-8333-333333333333';
    private const ID = '9d1e0000-0000-4000-8000-000000000001';
    private const OTHER_ID = '9d1e0000-0000-4000-8000-000000000002';

    private const BASE = [390030, -774050];
    private const SPOT = [389600, -773600];
    private const MOVED = [389604, -773597];

    /**
     * A correction as MySQL returns it.
     *
     * @param array<int, int> $from
     * @param array<int, int> $to
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    private static function databaseRow(string $id, array $from, array $to, array $over = []): array
    {
        return $over + [
            'id' => $id, 'organization_id' => self::ORG, 'truck_id' => self::TRUCK,
            'o_lat_e4' => (string) $from[0], 'o_lng_e4' => (string) $from[1],
            'd_lat_e4' => (string) $to[0], 'd_lng_e4' => (string) $to[1],
            'override_minutes' => '14', 'toll_cents' => '375', 'note' => 'school run',
            'created_at' => '2026-10-04 23:50:12', 'updated_at' => '2026-10-04 23:58:40',
        ];
    }

    public function testAListReadsInApiUnitsInIdOrder(): void
    {
        $db = (new RecordingDatabase())->queue([
            self::databaseRow(self::ID, self::SPOT, self::BASE),
            self::databaseRow(self::OTHER_ID, self::BASE, self::SPOT, ['override_minutes' => null, 'toll_cents' => 0, 'note' => '']),
        ]);
        $rows = (new DriveOverrideRepository($db))->listForTruck(self::ORG, self::TRUCK);

        $call = $db->calls[0];
        self::assertSame('fetchAll', $call['kind']);
        self::assertStringContainsString('FROM tp_drive_overrides WHERE organization_id = ? AND truck_id = ?', $call['sql']);
        self::assertStringEndsWith('ORDER BY id', $call['sql']);
        self::assertStringNotContainsString('SELECT *', $call['sql']);
        self::assertSame([self::ORG, self::TRUCK], $call['params']);

        self::assertSame(
            [
                'id' => self::ID, 'organization_id' => self::ORG, 'truck_id' => self::TRUCK,
                'from_key' => self::SPOT, 'to_key' => self::BASE, 'minutes' => 14, 'toll' => 3.75, 'note' => 'school run',
                'created_at' => '2026-10-04 23:50:12', 'updated_at' => '2026-10-04 23:58:40',
            ],
            $rows[0]
        );
        // "no minutes" stays null, and a toll of nothing is 0.0, not null
        self::assertNull($rows[1]['minutes']);
        self::assertSame(0.0, $rows[1]['toll']);
        self::assertSame('', $rows[1]['note']);
    }

    public function testFindIsScopedToTheOrganization(): void
    {
        $db = (new RecordingDatabase())->queue(self::databaseRow(self::ID, self::SPOT, self::BASE));
        $row = (new DriveOverrideRepository($db))->find(self::ID, self::ORG);
        self::assertSame(self::ID, $row['id']);
        self::assertStringEndsWith('FROM tp_drive_overrides WHERE id = ? AND organization_id = ?', $db->calls[0]['sql']);
        self::assertSame([self::ID, self::ORG], $db->calls[0]['params']);

        // another organization's id: the statement finds nothing, and the answer is null
        self::assertNull((new DriveOverrideRepository(new RecordingDatabase()))->find(self::ID, '99999999-9999-4999-8999-999999999999'));
    }

    public function testFindForPairsAnswersByPair(): void
    {
        $db = (new RecordingDatabase())->queue([self::databaseRow(self::ID, self::SPOT, self::BASE)]);
        $found = (new DriveOverrideRepository($db))->findForPairs(self::ORG, self::TRUCK, [
            [389600, -773600, 390030, -774050],
            [390030, -774050, 389600, -773600],
            [389600, -773600, 390030, -774050],
        ]);
        $call = $db->calls[0];
        self::assertStringContainsString(
            'WHERE organization_id = ? AND truck_id = ? AND (o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4) IN ((?, ?, ?, ?), (?, ?, ?, ?))',
            $call['sql']
        );
        self::assertSame([self::ORG, self::TRUCK, 389600, -773600, 390030, -774050, 390030, -774050, 389600, -773600], $call['params']);
        self::assertSame(['389600,-773600,390030,-774050'], array_keys($found));
        self::assertSame(14, $found['389600,-773600,390030,-774050']['minutes']);

        $none = new RecordingDatabase();
        self::assertSame([], (new DriveOverrideRepository($none))->findForPairs(self::ORG, self::TRUCK, []));
        self::assertSame([], $none->calls);
    }

    public function testTheFirstSaveOfAPairInsertsARowWithANewId(): void
    {
        $db = new RecordingDatabase();
        $id = (new DriveOverrideRepository($db))->upsert(self::ORG, self::TRUCK, self::SPOT, self::BASE, 14, null, 'school run');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        self::assertSame(['fetch', 'query'], $db->kinds());
        // existence is read first, with the organization
        self::assertSame(
            'SELECT id FROM tp_drive_overrides WHERE organization_id = ? AND truck_id = ? '
            . 'AND o_lat_e4 = ? AND o_lng_e4 = ? AND d_lat_e4 = ? AND d_lng_e4 = ?',
            $db->calls[0]['sql']
        );
        self::assertSame([self::ORG, self::TRUCK, 389600, -773600, 390030, -774050], $db->calls[0]['params']);
        $insert = $db->only('INSERT INTO tp_drive_overrides');
        self::assertStringContainsString(
            '(id, organization_id, truck_id, o_lat_e4, o_lng_e4, d_lat_e4, d_lng_e4, override_minutes, toll_cents, note, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            $insert['sql']
        );
        self::assertSame([$id, self::ORG, self::TRUCK, 389600, -773600, 390030, -774050, 14, null, 'school run'], $insert['params']);
    }

    public function testALaterSaveChangesTheRowOfThePair(): void
    {
        $db = (new RecordingDatabase())->queue(['id' => self::ID]);
        $id = (new DriveOverrideRepository($db))->upsert(self::ORG, self::TRUCK, self::SPOT, self::BASE, null, 375, '');
        self::assertSame(self::ID, $id);
        self::assertSame([], $db->find('INSERT INTO'));
        $update = $db->only('UPDATE tp_drive_overrides');
        self::assertSame(
            'UPDATE tp_drive_overrides SET override_minutes = ?, toll_cents = ?, note = ? WHERE id = ? AND organization_id = ?',
            $update['sql']
        );
        // null clears the minutes; the toll travels in whole cents
        self::assertSame([null, 375, '', self::ID, self::ORG], $update['params']);
    }

    public function testTwoFirstSavesThatMeetEndAsOneRow(): void
    {
        // The read finds nothing, the insert meets the unique key, the second read finds the other save's row.
        $db = (new RecordingDatabase())
            ->queue(null, ['id' => self::ID])
            ->failOn('INSERT INTO tp_drive_overrides', new \PDOException('Duplicate entry', 23000));
        $id = (new DriveOverrideRepository($db))->upsert(self::ORG, self::TRUCK, self::SPOT, self::BASE, 9, 100, 'n');
        self::assertSame(self::ID, $id);
        self::assertSame([9, 100, 'n', self::ID, self::ORG], $db->only('UPDATE tp_drive_overrides')['params']);
    }

    public function testAnyOtherFailureOfTheInsertIsNotSwallowed(): void
    {
        $db = (new RecordingDatabase())->failOn('INSERT INTO tp_drive_overrides', new \PDOException('Data too long', 22001));
        $this->expectException(\PDOException::class);
        (new DriveOverrideRepository($db))->upsert(self::ORG, self::TRUCK, self::SPOT, self::BASE, 9, null, 'n');
    }

    public function testDeleteIsScopedToTheOrganization(): void
    {
        $db = new RecordingDatabase();
        (new DriveOverrideRepository($db))->delete(self::ID, self::ORG);
        self::assertSame('DELETE FROM tp_drive_overrides WHERE id = ? AND organization_id = ?', $db->calls[0]['sql']);
        self::assertSame([self::ID, self::ORG], $db->calls[0]['params']);
    }

    public function testAMovedPointTakesItsCorrectionsAlong(): void
    {
        // one correction starts at the spot, one ends there
        $db = (new RecordingDatabase())->queue([
            ['id' => self::ID, 'o_lat_e4' => 389600, 'o_lng_e4' => -773600, 'd_lat_e4' => 390030, 'd_lng_e4' => -774050],
            ['id' => self::OTHER_ID, 'o_lat_e4' => '390030', 'o_lng_e4' => '-774050', 'd_lat_e4' => '389600', 'd_lng_e4' => '-773600'],
        ]);
        (new DriveOverrideRepository($db))->movePoint(self::ORG, self::TRUCK, self::SPOT, self::MOVED);

        self::assertSame(['begin', 'fetchAll', 'query', 'query', 'commit'], $db->kinds());
        $read = $db->calls[1];
        self::assertStringContainsString('WHERE organization_id = ? AND truck_id = ?', $read['sql']);
        self::assertSame(
            [self::ORG, self::TRUCK, 389600, -773600, 389600, -773600, 389604, -773597, 389604, -773597],
            $read['params']
        );
        $updates = $db->find('UPDATE tp_drive_overrides');
        self::assertSame(
            'UPDATE tp_drive_overrides SET o_lat_e4 = ?, o_lng_e4 = ?, d_lat_e4 = ?, d_lng_e4 = ? WHERE id = ? AND organization_id = ?',
            $updates[0]['sql']
        );
        self::assertSame([389604, -773597, 390030, -774050, self::ID, self::ORG], $updates[0]['params']);
        self::assertSame([390030, -774050, 389604, -773597, self::OTHER_ID, self::ORG], $updates[1]['params']);
    }

    public function testACorrectionStaysBehindWhenTheNewPairIsTakenOrWouldBeOnePlace(): void
    {
        $db = (new RecordingDatabase())->queue([
            // spot -> base would become moved -> base, which has a correction of its own
            ['id' => 'a', 'o_lat_e4' => 389600, 'o_lng_e4' => -773600, 'd_lat_e4' => 390030, 'd_lng_e4' => -774050],
            ['id' => 'b', 'o_lat_e4' => 389604, 'o_lng_e4' => -773597, 'd_lat_e4' => 390030, 'd_lng_e4' => -774050],
            // spot -> moved would become moved -> moved
            ['id' => 'c', 'o_lat_e4' => 389600, 'o_lng_e4' => -773600, 'd_lat_e4' => 389604, 'd_lng_e4' => -773597],
            // base -> spot is free to follow
            ['id' => 'd', 'o_lat_e4' => 390030, 'o_lng_e4' => -774050, 'd_lat_e4' => 389600, 'd_lng_e4' => -773600],
        ]);
        (new DriveOverrideRepository($db))->movePoint(self::ORG, self::TRUCK, self::SPOT, self::MOVED);
        $updates = $db->find('UPDATE tp_drive_overrides');
        self::assertCount(1, $updates);
        self::assertSame([390030, -774050, 389604, -773597, 'd', self::ORG], $updates[0]['params']);
        self::assertSame([], $db->find('DELETE'));
    }

    public function testAMoveInsideOneKeyAsksNothing(): void
    {
        $db = new RecordingDatabase();
        (new DriveOverrideRepository($db))->movePoint(self::ORG, self::TRUCK, self::SPOT, self::SPOT);
        self::assertSame([], $db->calls);
    }

    public function testAFailedMoveIsRolledBack(): void
    {
        $db = (new RecordingDatabase())
            ->queue([['id' => self::ID, 'o_lat_e4' => 389600, 'o_lng_e4' => -773600, 'd_lat_e4' => 390030, 'd_lng_e4' => -774050]])
            ->failOn('UPDATE tp_drive_overrides');
        try {
            (new DriveOverrideRepository($db))->movePoint(self::ORG, self::TRUCK, self::SPOT, self::MOVED);
            self::fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame(['begin', 'fetchAll', 'query', 'rollback'], $db->kinds());
        }
    }

    public function testEveryStatementCarriesTheOrganization(): void
    {
        $db = (new RecordingDatabase())
            ->when('SELECT id, o_lat_e4', [['id' => self::ID, 'o_lat_e4' => 389600, 'o_lng_e4' => -773600, 'd_lat_e4' => 390030, 'd_lng_e4' => -774050]]);
        $repository = new DriveOverrideRepository($db);
        $repository->listForTruck(self::ORG, self::TRUCK);
        $repository->find(self::ID, self::ORG);
        $repository->findForPairs(self::ORG, self::TRUCK, [[389600, -773600, 390030, -774050]]);
        $repository->upsert(self::ORG, self::TRUCK, self::SPOT, self::BASE, 14, 375, '');
        $repository->delete(self::ID, self::ORG);
        $repository->movePoint(self::ORG, self::TRUCK, self::SPOT, self::MOVED);

        $statements = 0;
        foreach ($db->calls as $call) {
            if ($call['sql'] === '') {
                continue;
            }
            $statements++;
            if (str_starts_with($call['sql'], 'INSERT INTO tp_drive_overrides')) {
                self::assertSame(self::ORG, $call['params'][1], 'an insert binds the organization as its second value');
            } else {
                self::assertStringContainsString('organization_id = ?', $call['sql']);
                self::assertContains(self::ORG, $call['params']);
            }
            self::assertStringNotContainsString('SELECT *', $call['sql']);
            foreach ($call['params'] as $value) {
                self::assertTrue(is_int($value) || is_string($value) || $value === null, 'no float, boolean or array is bound');
            }
        }
        self::assertSame(8, $statements);
    }
}
