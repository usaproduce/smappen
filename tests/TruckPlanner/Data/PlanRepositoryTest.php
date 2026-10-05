<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\PlanRepository;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

final class PlanRepositoryTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const USER = '22222222-2222-4222-8222-222222222222';
    private const TRUCK = '33333333-3333-4333-8333-333333333333';
    private const PLAN = '55555555-5555-4555-8555-555555555555';
    private const STOP = '66666666-6666-4666-8666-666666666666';
    private const SPOT = '44444444-4444-4444-8444-444444444444';

    protected function tearDown(): void
    {
        TpConfig::replace(null);
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * A plan as MySQL returns it.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function planRow(array $changes = []): array
    {
        return $changes + [
            'id' => self::PLAN, 'organization_id' => self::ORG, 'truck_id' => self::TRUCK, 'created_by' => self::USER,
            'service_date' => '2026-10-08', 'name' => 'Thursday', 'treat_as' => 'sat', 'notes' => 'Bring the awning', 'plan_state' => 'planned',
            'result_json' => '{"date":"2026-10-08","totals":{"orders":{"value":99.87,"low":53.77,"high":155.39,"confidence":"rough"},'
                . '"take_home":{"value":482.2000000000001,"low":-42.35,"high":1011.86,"confidence":"rough"},"day_hours":11.283333333333333,"drive_minutes":22},'
                . '"warnings":[{"code":"outside_region","level":"warn","stop_index":1,"data":{}}],"whole":66.0}',
            'context_json' => '{"ctx":{"date":"2026-10-08","dow":3,"dow_factor":[1.0,1.08]},"uses_google_legs":true}',
            'result_has_google' => 1, 'evaluated_at' => '2026-10-04 23:50:12', 'model_version' => 'tps-0.1.0', 'seeds_revision' => 1,
            'dataset_version' => 'dc-20261003-d0514a63', 'created_at' => '2026-10-04 23:50:10', 'updated_at' => '2026-10-04 23:50:12',
            'has_snapshot' => 1, 'snapshot_expired' => 0, 'stop_count' => 2, 'spots_changed_at' => '2026-10-03 08:00:00',
        ];
    }

    /**
     * A stop as MySQL returns it.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function stopRow(array $changes = []): array
    {
        return $changes + [
            'id' => self::STOP, 'plan_id' => self::PLAN, 'seq' => 0, 'stop_kind' => 'event', 'spot_id' => null, 'label' => 'Fall fair',
            'lat' => 38.95000000000001, 'lng' => -77.35, 'address' => '1 Fair Way', 'open_minute' => 660, 'close_minute' => 900,
            'gap_before_unpaid' => 1, 'setup_minutes' => 45, 'teardown_minutes' => null, 'fee_flat_cents' => 1001, 'fee_pct' => 0.12,
            'fee_min_cents' => 7500, 'ev_attendance' => 1500.5, 'ev_vendor_count' => 6, 'ev_type' => 'general', 'cat_headcount' => null,
            'cat_price_head_cents' => null, 'cat_guarantee_cents' => null, 'cat_food_cost_cents' => null,
        ];
    }

    /**
     * The bound values of an INSERT, keyed by column.
     *
     * @param array{sql: string, params: array<int|string, mixed>} $call
     * @return array{0: array<string, mixed>, 1: array<string, string>} [bound values, value expressions]
     */
    private static function inserted(array $call, string $table): array
    {
        self::assertSame(1, preg_match('/^INSERT INTO ' . $table . ' \((.+?)\) VALUES \((.+)\)$/', $call['sql'], $m), $call['sql']);
        $columns = explode(', ', $m[1]);
        $expressions = explode(', ', $m[2]);
        self::assertSame(count($columns), count($expressions));
        $bound = [];
        $params = array_values($call['params']);
        foreach ($columns as $i => $column) {
            if ($expressions[$i] === '?') {
                $bound[$column] = array_shift($params);
            }
        }
        self::assertSame([], $params, 'every bound value belongs to a column');
        return [$bound, array_combine($columns, $expressions)];
    }

    // ------------------------------------------------------------------------------------ tenant scope

    public function testEveryStatementCarriesTheOrganization(): void
    {
        $db = new RecordingDatabase();
        $repo = new PlanRepository($db);
        $stop = ['stop_kind' => 'spot', 'spot_id' => self::SPOT, 'open_minute' => 660, 'close_minute' => 840];

        $repo->listRange(self::ORG, self::TRUCK, '2026-10-01', '2026-10-31', true);
        $repo->find(self::PLAN, self::ORG);
        $repo->findByDate(self::ORG, self::TRUCK, '2026-10-08');
        $repo->findStop(self::STOP, self::ORG);
        $repo->create(self::ORG, self::TRUCK, self::USER, ['service_date' => '2026-10-08'], [$stop]);
        $repo->update(self::PLAN, self::ORG, ['name' => 'x']);
        $repo->replaceStops(self::PLAN, self::ORG, [$stop]);
        $repo->saveSnapshot(self::PLAN, self::ORG, ['a' => 1], ['b' => 2], false, ['model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => null]);
        $repo->purgeExpiredSnapshots(self::ORG);
        $repo->delete(self::PLAN, self::ORG);

        $statements = 0;
        foreach ($db->calls as $call) {
            if ($call['sql'] === '') {
                continue;
            }
            $statements++;
            self::assertDoesNotMatchRegularExpression('/SELECT\s+(\w+\.)?\*/i', $call['sql']);
            self::assertDoesNotMatchRegularExpression('/\bJSON_[A-Z_]+\(|->>?/', $call['sql'], 'MySQL never looks inside a snapshot');
            self::assertContains(self::ORG, $call['params'], $call['sql']);
            if (str_starts_with($call['sql'], 'INSERT INTO')) {
                self::assertStringContainsString('organization_id', $call['sql']);
            } else {
                self::assertStringContainsString('organization_id = ?', $call['sql'], $call['sql']);
            }
        }
        self::assertGreaterThanOrEqual(15, $statements);
    }

    // ------------------------------------------------------------------------------------ reads

    public function testFindAnswersANormalisedPlanWithItsStops(): void
    {
        $db = (new RecordingDatabase())
            ->when('FROM tp_plans p', self::planRow())
            ->when('FROM tp_plan_stops WHERE', [self::stopRow(), self::stopRow(['id' => 'stop-2', 'seq' => 1, 'stop_kind' => 'spot', 'spot_id' => self::SPOT, 'label' => '', 'lat' => null, 'lng' => null, 'address' => '', 'gap_before_unpaid' => 0, 'setup_minutes' => null, 'fee_flat_cents' => 0, 'fee_pct' => 0, 'fee_min_cents' => 0, 'ev_attendance' => null, 'ev_vendor_count' => null, 'ev_type' => null])]);
        $plan = (new PlanRepository($db))->find(self::PLAN, self::ORG);

        self::assertIsArray($plan);
        self::assertSame(
            ['id', 'organization_id', 'truck_id', 'created_by', 'service_date', 'name', 'treat_as', 'notes', 'plan_state', 'result', 'context',
                'has_snapshot', 'result_has_google', 'evaluated_at', 'model_version', 'seeds_revision', 'dataset_version', 'created_at', 'updated_at',
                'snapshot_expired', 'stop_count', 'spots_changed_at', 'stops'],
            array_keys($plan)
        );
        self::assertSame('2026-10-08', $plan['service_date']);
        self::assertSame('planned', $plan['plan_state']);
        self::assertTrue($plan['has_snapshot']);
        self::assertTrue($plan['result_has_google']);
        self::assertFalse($plan['snapshot_expired']);
        self::assertSame(1, $plan['seeds_revision']);
        self::assertSame(2, $plan['stop_count']);
        self::assertSame('2026-10-03 08:00:00', $plan['spots_changed_at']);

        // The snapshot is decoded by PHP, number for number and type for type.
        self::assertSame(482.2000000000001, $plan['result']['totals']['take_home']['value']);
        self::assertSame(11.283333333333333, $plan['result']['totals']['day_hours']);
        self::assertSame(22, $plan['result']['totals']['drive_minutes']);
        self::assertSame(66.0, $plan['result']['whole']);
        self::assertSame([], $plan['result']['warnings'][0]['data']);
        self::assertSame([1.0, 1.08], $plan['context']['ctx']['dow_factor']);
        self::assertSame(3, $plan['context']['ctx']['dow']);

        // The stops, in API units.
        self::assertCount(2, $plan['stops']);
        self::assertSame(
            ['id' => self::STOP, 'plan_id' => self::PLAN, 'seq' => 0, 'stop_kind' => 'event', 'spot_id' => null, 'label' => 'Fall fair',
                'lat' => 38.95000000000001, 'lng' => -77.35, 'address' => '1 Fair Way', 'open_minute' => 660, 'close_minute' => 900,
                'gap_before_unpaid' => true, 'setup_minutes' => 45, 'teardown_minutes' => null, 'fee_flat' => 10.01, 'fee_pct' => 0.12,
                'fee_min' => 75.0, 'ev_attendance' => 1500.5, 'ev_vendor_count' => 6, 'ev_type' => 'general', 'cat_headcount' => null,
                'cat_price_head' => null, 'cat_guarantee' => null, 'cat_food_cost' => null],
            $plan['stops'][0]
        );
        self::assertSame([self::SPOT, null, null, false, 0.0], [$plan['stops'][1]['spot_id'], $plan['stops'][1]['lat'], $plan['stops'][1]['setup_minutes'], $plan['stops'][1]['gap_before_unpaid'], $plan['stops'][1]['fee_flat']]);

        // The query: the snapshot lifetime first, then the id and the organization.
        $call = $db->only('FROM tp_plans p');
        self::assertSame([30, self::PLAN, self::ORG], $call['params']);
        self::assertStringContainsString('WHERE p.id = ? AND p.organization_id = ?', $call['sql']);
        self::assertStringContainsString('(p.result_has_google = 1 AND p.evaluated_at < NOW() - INTERVAL ? DAY) AS snapshot_expired', $call['sql']);
        self::assertStringContainsString('p.context_json', $call['sql']);
        self::assertStringContainsString('WHERE s.organization_id = p.organization_id AND s.plan_id = p.id) AS stop_count', $call['sql']);
        self::assertStringContainsString('JOIN tp_spots sp ON sp.organization_id = s.organization_id AND sp.id = s.spot_id', $call['sql']);
        $stops = $db->only('FROM tp_plan_stops WHERE');
        self::assertSame([self::ORG, self::PLAN], $stops['params']);
        self::assertStringEndsWith('WHERE organization_id = ? AND plan_id IN (?) ORDER BY plan_id, seq', $stops['sql']);
    }

    public function testAPlanThatIsNotThereIsNull(): void
    {
        $db = new RecordingDatabase();
        $repo = new PlanRepository($db);
        self::assertNull($repo->find(self::PLAN, self::ORG));
        self::assertNull($repo->findByDate(self::ORG, self::TRUCK, '2026-10-08'));
        self::assertNull($repo->findStop(self::STOP, self::ORG));
        self::assertCount(3, $db->calls, 'no stops are read for a plan that is not there');
        self::assertSame([30, self::ORG, self::TRUCK, '2026-10-08'], $db->calls[1]['params']);
        self::assertStringContainsString('WHERE p.organization_id = ? AND p.truck_id = ? AND p.service_date = ?', $db->calls[1]['sql']);
        self::assertSame([self::STOP, self::ORG], $db->calls[2]['params']);
        self::assertStringEndsWith('FROM tp_plan_stops WHERE id = ? AND organization_id = ?', $db->calls[2]['sql']);
    }

    public function testASnapshotCountsOnlyWhenBothHalvesRead(): void
    {
        foreach ([
            ['result_json' => null, 'context_json' => null, 'evaluated_at' => null, 'has_snapshot' => 0, 'snapshot_expired' => null],
            ['context_json' => null],
            ['result_json' => '{"cut off'],
            ['result_json' => '"text"'],
        ] as $changes) {
            $db = (new RecordingDatabase())->when('FROM tp_plans p', self::planRow($changes));
            $plan = (new PlanRepository($db))->find(self::PLAN, self::ORG);
            self::assertFalse($plan['has_snapshot']);
            self::assertNull($plan['result']);
            self::assertNull($plan['context']);
            self::assertFalse($plan['snapshot_expired']);
            self::assertSame([], $plan['stops']);
        }
        $db = (new RecordingDatabase())->when('FROM tp_plans p', self::planRow(['snapshot_expired' => 1]));
        self::assertTrue((new PlanRepository($db))->find(self::PLAN, self::ORG)['snapshot_expired']);
    }

    public function testListRangeAnswersLightRowsByDate(): void
    {
        $second = self::planRow(['id' => 'plan-2', 'service_date' => '2026-10-09', 'result_json' => null, 'has_snapshot' => 0, 'evaluated_at' => null, 'stop_count' => 0, 'spots_changed_at' => null]);
        $first = self::planRow();
        unset($first['context_json'], $second['context_json']);
        $db = (new RecordingDatabase())->when('FROM tp_plan_stops WHERE', [self::stopRow()])->when('FROM tp_plans p', [$first, $second]);
        $repo = new PlanRepository($db);

        $rows = $repo->listRange(self::ORG, self::TRUCK, '2026-10-01', '2026-10-31');
        self::assertCount(2, $rows);
        self::assertArrayNotHasKey('result', $rows[0]);
        self::assertArrayNotHasKey('context', $rows[0]);
        self::assertArrayNotHasKey('stops', $rows[0]);
        self::assertSame(
            [
                'orders' => ['value' => 99.87, 'low' => 53.77, 'high' => 155.39, 'confidence' => 'rough'],
                'take_home' => ['value' => 482.2000000000001, 'low' => -42.35, 'high' => 1011.86, 'confidence' => 'rough'],
                'day_hours' => 11.283333333333333,
            ],
            $rows[0]['summary']
        );
        self::assertTrue($rows[0]['has_snapshot']);
        self::assertNull($rows[1]['summary']);
        self::assertFalse($rows[1]['has_snapshot']);
        self::assertSame(0, $rows[1]['stop_count']);

        $call = $db->only('FROM tp_plans p');
        self::assertSame([30, self::ORG, self::TRUCK, '2026-10-01', '2026-10-31'], $call['params']);
        self::assertStringContainsString('WHERE p.organization_id = ? AND p.truck_id = ? AND p.service_date BETWEEN ? AND ? ORDER BY p.service_date, p.id', $call['sql']);
        self::assertStringNotContainsString('context_json', $call['sql'], 'a list does not read the contexts');
        self::assertCount(1, $db->calls, 'and no stops unless asked');

        // With stops: one more statement for all the listed plans.
        $rows = $repo->listRange(self::ORG, self::TRUCK, '2026-10-01', '2026-10-31', true);
        self::assertCount(1, $rows[0]['stops']);
        self::assertSame([], $rows[1]['stops']);
        $stops = $db->only('FROM tp_plan_stops WHERE');
        self::assertSame([self::ORG, self::PLAN, 'plan-2'], $stops['params']);
        self::assertStringContainsString('plan_id IN (?, ?) ORDER BY plan_id, seq', $stops['sql']);

        // Nothing listed: nothing more is read.
        $empty = new RecordingDatabase();
        self::assertSame([], (new PlanRepository($empty))->listRange(self::ORG, self::TRUCK, '2026-10-01', '2026-10-31', true));
        self::assertCount(1, $empty->calls);
    }

    public function testTheSnapshotLifetimeIsTheSetting(): void
    {
        $config = TpConfig::all();
        $config['plans']['snapshot_ttl_days'] = 7;
        TpConfig::replace($config);
        $db = new RecordingDatabase();
        $repo = new PlanRepository($db);
        $repo->find(self::PLAN, self::ORG);
        $repo->purgeExpiredSnapshots(self::ORG);
        self::assertSame(7, $db->calls[0]['params'][0]);
        self::assertSame([self::ORG, 7], $db->only('SELECT COUNT(*) AS expired')['params']);
    }

    // ------------------------------------------------------------------------------------ writes

    public function testCreateWritesThePlanAndItsStopsInOneTransaction(): void
    {
        $db = new RecordingDatabase();
        $id = (new PlanRepository($db))->create(self::ORG, self::TRUCK, self::USER, ['service_date' => '2026-10-08', 'treat_as' => 'sat', 'notes' => 'n'], [
            ['id' => self::STOP, 'stop_kind' => 'event', 'label' => 'Fall fair', 'lat' => 38.95000000000001, 'lng' => -77.35, 'address' => '1 Fair Way',
                'open_minute' => 660, 'close_minute' => 900, 'gap_before_unpaid' => true, 'setup_minutes' => 45, 'fee_flat' => 10.005, 'fee_pct' => 0.1 + 0.2,
                'fee_min' => 75, 'ev_attendance' => 1500.5, 'ev_vendor_count' => 6, 'ev_type' => 'general'],
            ['stop_kind' => 'catering', 'lat' => 39, 'lng' => -77.4, 'open_minute' => 1020, 'close_minute' => 1140,
                'cat_headcount' => 80, 'cat_price_head' => 14, 'cat_guarantee' => 1000.5, 'cat_food_cost' => null],
        ]);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        self::assertSame(['begin', 'query', 'query', 'query', 'commit'], $db->kinds());

        [$plan, $expressions] = self::inserted($db->calls[1], 'tp_plans');
        self::assertSame(
            ['id' => $id, 'organization_id' => self::ORG, 'truck_id' => self::TRUCK, 'created_by' => self::USER, 'service_date' => '2026-10-08',
                'name' => '', 'treat_as' => 'sat', 'notes' => 'n', 'plan_state' => 'draft'],
            $plan
        );
        self::assertSame('NOW()', $expressions['created_at']);
        self::assertSame('NOW()', $expressions['updated_at']);

        [$first] = self::inserted($db->calls[2], 'tp_plan_stops');
        self::assertSame(
            ['id' => self::STOP, 'organization_id' => self::ORG, 'plan_id' => $id, 'seq' => 0, 'stop_kind' => 'event', 'spot_id' => null,
                'label' => 'Fall fair', 'lat' => '38.95000000000001', 'lng' => '-77.35', 'address' => '1 Fair Way', 'open_minute' => 660,
                'close_minute' => 900, 'gap_before_unpaid' => 1, 'setup_minutes' => 45, 'teardown_minutes' => null, 'fee_flat_cents' => 1001,
                'fee_pct' => '0.30000000000000004', 'fee_min_cents' => 7500, 'ev_attendance' => '1500.5', 'ev_vendor_count' => 6, 'ev_type' => 'general',
                'cat_headcount' => null, 'cat_price_head_cents' => null, 'cat_guarantee_cents' => null, 'cat_food_cost_cents' => null],
            $first,
            'cents for money, the shortest exact text for a float, 1 for true'
        );
        [$second] = self::inserted($db->calls[3], 'tp_plan_stops');
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $second['id']);
        self::assertNotSame(self::STOP, $second['id']);
        self::assertSame(
            [1, 'catering', '', '39', '-77.4', '', 0, 0, '0', 0, '80', 1400, 100050, null],
            [$second['seq'], $second['stop_kind'], $second['label'], $second['lat'], $second['lng'], $second['address'], $second['gap_before_unpaid'],
                $second['fee_flat_cents'], $second['fee_pct'], $second['fee_min_cents'], $second['cat_headcount'], $second['cat_price_head_cents'],
                $second['cat_guarantee_cents'], $second['cat_food_cost_cents']]
        );
    }

    public function testAFailedStopRollsTheWholePlanBack(): void
    {
        $db = (new RecordingDatabase())->failOn('INSERT INTO tp_plan_stops', new \PDOException('SQLSTATE[23000]: duplicate'));
        $repo = new PlanRepository($db);
        try {
            $repo->create(self::ORG, self::TRUCK, null, ['service_date' => '2026-10-08'], [['stop_kind' => 'spot', 'spot_id' => self::SPOT, 'open_minute' => 660, 'close_minute' => 840]]);
            self::fail('the failure was swallowed');
        } catch (\PDOException $e) {
            self::assertStringContainsString('23000', $e->getMessage());
        }
        self::assertSame(['begin', 'query', 'query', 'rollback'], $db->kinds());
        // The repository is usable afterwards: it opens a new transaction.
        $repo->delete(self::PLAN, self::ORG);
        self::assertSame(['begin', 'query', 'query', 'query', 'commit'], array_slice($db->kinds(), 4));
    }

    public function testWhatCannotBeWrittenIsRefusedBeforeAnyStatement(): void
    {
        $db = new RecordingDatabase();
        $repo = new PlanRepository($db);
        $stop = ['stop_kind' => 'spot', 'spot_id' => self::SPOT, 'open_minute' => 660, 'close_minute' => 840];
        foreach ([
            fn () => $repo->create(self::ORG, self::TRUCK, null, [], []),
            fn () => $repo->create(self::ORG, self::TRUCK, null, ['service_date' => '2026-10-08', 'result' => []], []),
            fn () => $repo->update(self::PLAN, self::ORG, ['organization_id' => 'other']),
            fn () => $repo->update(self::PLAN, self::ORG, ['name' => null]),
        ] as $call) {
            try {
                $call();
                self::fail('a write that cannot be made was accepted');
            } catch (\LogicException $e) {
                self::assertStringStartsWith('tp_plans: ', $e->getMessage());
            }
        }
        self::assertSame([], $db->calls);

        // A stop is checked when it is written, inside the transaction, which is then rolled back.
        foreach ([$stop + ['plan_state' => 'draft'], ['stop_kind' => 'spot', 'open_minute' => null, 'close_minute' => 840]] as $bad) {
            $db = new RecordingDatabase();
            try {
                (new PlanRepository($db))->replaceStops(self::PLAN, self::ORG, [$stop, $bad]);
                self::fail('a stop that cannot be written was accepted');
            } catch (\LogicException $e) {
                self::assertStringStartsWith('tp_plan_stops: ', $e->getMessage());
            }
            self::assertSame('rollback', $db->kinds()[count($db->kinds()) - 1]);
        }
    }

    public function testUpdateChangesTheGivenColumnsOnly(): void
    {
        $db = new RecordingDatabase();
        $repo = new PlanRepository($db);
        $repo->update(self::PLAN, self::ORG, ['service_date' => '2026-10-09', 'treat_as' => null, 'plan_state' => 'done']);
        $call = $db->only('UPDATE tp_plans');
        self::assertSame('UPDATE tp_plans SET service_date = ?, treat_as = ?, plan_state = ? WHERE id = ? AND organization_id = ?', $call['sql']);
        self::assertSame(['2026-10-09', null, 'done', self::PLAN, self::ORG], $call['params']);
        self::assertSame(['query'], $db->kinds(), 'one statement needs no transaction');

        $repo->update(self::PLAN, self::ORG, []);
        self::assertCount(1, $db->calls, 'nothing to change, nothing sent');
    }

    public function testReplaceStopsDetachesTheLogsOfStopsThatGo(): void
    {
        $db = new RecordingDatabase();
        (new PlanRepository($db))->replaceStops(self::PLAN, self::ORG, [
            ['id' => self::STOP, 'stop_kind' => 'spot', 'spot_id' => self::SPOT, 'open_minute' => 660, 'close_minute' => 840, 'seq' => 9, 'plan_id' => 'other plan'],
            ['id' => 'kept-2', 'stop_kind' => 'spot', 'spot_id' => self::SPOT, 'open_minute' => 900, 'close_minute' => 960],
            ['stop_kind' => 'spot', 'spot_id' => self::SPOT, 'open_minute' => 1020, 'close_minute' => 1200],
        ]);
        self::assertSame(['begin', 'query', 'query', 'query', 'query', 'query', 'commit'], $db->kinds());

        $detach = $db->calls[1];
        self::assertSame(
            'UPDATE tp_service_logs SET plan_stop_id = NULL, updated_at = updated_at WHERE organization_id = ? AND plan_id = ? '
            . 'AND plan_stop_id IS NOT NULL AND plan_stop_id NOT IN (?, ?)',
            $detach['sql']
        );
        self::assertSame([self::ORG, self::PLAN, self::STOP, 'kept-2'], $detach['params']);
        self::assertSame('DELETE FROM tp_plan_stops WHERE organization_id = ? AND plan_id = ?', $db->calls[2]['sql']);
        self::assertSame([self::ORG, self::PLAN], $db->calls[2]['params']);

        $ids = [];
        foreach ([3, 4, 5] as $i => $call) {
            [$stop] = self::inserted($db->calls[$call], 'tp_plan_stops');
            self::assertSame($i, $stop['seq'], 'the position in the list, whatever a row says');
            self::assertSame(self::PLAN, $stop['plan_id']);
            $ids[] = $stop['id'];
        }
        self::assertSame([self::STOP, 'kept-2'], array_slice($ids, 0, 2));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $ids[2]);

        // No stop left: every log of the plan loses its stop.
        $db = new RecordingDatabase();
        (new PlanRepository($db))->replaceStops(self::PLAN, self::ORG, []);
        self::assertSame(['begin', 'query', 'query', 'commit'], $db->kinds());
        self::assertStringEndsWith('AND plan_stop_id IS NOT NULL', $db->calls[1]['sql']);
        self::assertSame([self::ORG, self::PLAN], $db->calls[1]['params']);
    }

    public function testSaveSnapshotStoresJsonTextThatReadsBackAsSaved(): void
    {
        $db = new RecordingDatabase();
        $result = [
            'date' => '2026-10-08',
            'totals' => ['take_home' => ['value' => 482.2000000000001, 'low' => -42.35], 'day_hours' => 66.0, 'drive_minutes' => 22],
            'awkward' => [0.20000010000000001, 0.1 + 0.2, 1.0e25, 4.9e-324, -0.0, 1.0],
            'warnings' => [['code' => 'outside_region', 'stop_index' => 1, 'data' => []], ['code' => 'long_gap', 'stop_index' => 1, 'data' => ['gap_before_minutes' => 120]]],
            'stops' => [],
            'text' => "Caf\u{00E9} / \u{1F355}",
            'nothing' => null,
        ];
        $context = ['ctx' => ['dow_factor' => [1.0, 1.08], 'holiday' => null], 'legs' => [], 'uses_google_legs' => true];
        (new PlanRepository($db))->saveSnapshot(self::PLAN, self::ORG, $result, $context, true, ['model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => 'dc-1']);

        $call = $db->only('UPDATE tp_plans');
        self::assertSame(
            'UPDATE tp_plans SET result_json = ?, context_json = ?, result_has_google = ?, evaluated_at = NOW(), model_version = ?, '
            . 'seeds_revision = ?, dataset_version = ? WHERE id = ? AND organization_id = ?',
            $call['sql']
        );
        [$resultText, $contextText, $google, $model, $seeds, $dataset, $id, $org] = $call['params'];
        self::assertSame([1, 'tps-0.1.0', 1, 'dc-1', self::PLAN, self::ORG], [$google, $model, $seeds, $dataset, $id, $org]);
        self::assertIsString($resultText);
        self::assertSame($result, json_decode($resultText, true), 'every number and every type as it was');
        self::assertSame($context, json_decode($contextText, true));
        self::assertStringContainsString('"day_hours":66.0', $resultText, 'a whole float stays a float');
        self::assertStringContainsString('"drive_minutes":22', $resultText);
        self::assertStringContainsString('0.20000010000000001,0.30000000000000004,1.0e+25,', $resultText, 'seventeen digits where a double needs them');
        self::assertStringContainsString('"data":{}', $resultText, 'an empty map is an object');
        self::assertStringContainsString('"stops":[]', $resultText, 'an empty list is a list');
        self::assertStringContainsString("Caf\u{00E9} / \u{1F355}", $resultText);
        self::assertSame($resultText, PlanRepository::snapshotText($result, ['warnings.*.data']));

        // No Google leg, no dataset.
        $db = new RecordingDatabase();
        (new PlanRepository($db))->saveSnapshot(self::PLAN, self::ORG, [], [], false, ['model_version' => 'tps-0.1.0', 'seeds_revision' => 2, 'dataset_version' => null]);
        self::assertSame(['[]', '[]', 0, 'tps-0.1.0', 2, null, self::PLAN, self::ORG], $db->calls[0]['params']);
    }

    public function testANumberThatCannotTravelIsStoredAsNull(): void
    {
        $text = '';
        $lines = LogCapture::during(static function () use (&$text): void {
            $text = PlanRepository::snapshotText(['totals' => ['take_home' => ['value' => INF, 'low' => NAN]], 'name' => "\xC3\x28"]);
        });
        self::assertSame('{"totals":{"take_home":{"value":null,"low":null}},"name":null}', $text);
        self::assertCount(3, $lines);
        self::assertSame('[tp] non-finite at totals.take_home.value', $lines[0]);
    }

    public function testDeleteDetachesLogsThenRemovesStopsThenThePlan(): void
    {
        $db = new RecordingDatabase();
        (new PlanRepository($db))->delete(self::PLAN, self::ORG);
        self::assertSame(['begin', 'query', 'query', 'query', 'commit'], $db->kinds());
        self::assertSame(
            [
                'UPDATE tp_service_logs SET plan_id = NULL, plan_stop_id = NULL, updated_at = updated_at WHERE organization_id = ? AND plan_id = ?',
                'DELETE FROM tp_plan_stops WHERE organization_id = ? AND plan_id = ?',
                'DELETE FROM tp_plans WHERE id = ? AND organization_id = ?',
            ],
            $db->statements()
        );
        self::assertSame([self::ORG, self::PLAN], $db->calls[1]['params']);
        self::assertSame([self::ORG, self::PLAN], $db->calls[2]['params']);
        self::assertSame([self::PLAN, self::ORG], $db->calls[3]['params']);
    }

    public function testPurgeEmptiesExpiredSnapshotsAndCountsThem(): void
    {
        $db = (new RecordingDatabase())->when('SELECT COUNT(*) AS expired', ['expired' => 3]);
        self::assertSame(3, (new PlanRepository($db))->purgeExpiredSnapshots(self::ORG));
        self::assertSame(['begin', 'fetch', 'query', 'commit'], $db->kinds(), 'counted and emptied in one transaction');
        $where = 'WHERE organization_id = ? AND result_has_google = 1 AND evaluated_at < NOW() - INTERVAL ? DAY';
        self::assertSame('SELECT COUNT(*) AS expired FROM tp_plans ' . $where, $db->calls[1]['sql']);
        self::assertSame(
            'UPDATE tp_plans SET result_json = NULL, context_json = NULL, result_has_google = 0, evaluated_at = NULL, model_version = NULL, '
            . 'seeds_revision = NULL, dataset_version = NULL, updated_at = updated_at ' . $where,
            $db->calls[2]['sql']
        );
        self::assertSame([self::ORG, 30], $db->calls[1]['params']);
        self::assertSame([self::ORG, 30], $db->calls[2]['params']);

        // Nothing expired: nothing is written.
        $db = new RecordingDatabase();
        self::assertSame(0, (new PlanRepository($db))->purgeExpiredSnapshots(self::ORG));
        self::assertSame(['begin', 'fetch', 'commit'], $db->kinds());

        // The daily sweep: every organization. The one statement pair without an organization.
        $db = (new RecordingDatabase())->when('SELECT COUNT(*) AS expired', ['expired' => 12]);
        self::assertSame(12, (new PlanRepository($db))->purgeExpiredSnapshots());
        self::assertSame('SELECT COUNT(*) AS expired FROM tp_plans WHERE result_has_google = 1 AND evaluated_at < NOW() - INTERVAL ? DAY', $db->calls[1]['sql']);
        self::assertStringEndsWith('updated_at = updated_at WHERE result_has_google = 1 AND evaluated_at < NOW() - INTERVAL ? DAY', $db->calls[2]['sql']);
        self::assertSame([30], $db->calls[1]['params']);
        self::assertSame([30], $db->calls[2]['params']);
    }

    // ------------------------------------------------------------------------------------ one transaction for a change

    public function testTransactionMakesAChangeOfAPlanOneUnit(): void
    {
        $db = new RecordingDatabase();
        $repo = new PlanRepository($db);
        $stop = ['stop_kind' => 'spot', 'spot_id' => self::SPOT, 'open_minute' => 660, 'close_minute' => 840];
        $repo->transaction(function () use ($repo, $stop): void {
            $repo->update(self::PLAN, self::ORG, ['name' => 'x']);
            $repo->replaceStops(self::PLAN, self::ORG, [$stop]);
            $repo->saveSnapshot(self::PLAN, self::ORG, [], [], false, ['model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => null]);
        });
        self::assertSame(['begin', 'query', 'query', 'query', 'query', 'query', 'commit'], $db->kinds(), 'the stops joined the open transaction');

        // A failure anywhere rolls all of it back, and the next change starts cleanly.
        $db = (new RecordingDatabase())->failOn('UPDATE tp_plans SET result_json');
        $repo = new PlanRepository($db);
        try {
            $repo->transaction(function () use ($repo, $stop): void {
                $id = $repo->create(self::ORG, self::TRUCK, null, ['service_date' => '2026-10-08'], [$stop]);
                $repo->saveSnapshot($id, self::ORG, [], [], false, ['model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => null]);
            });
            self::fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('database failure (test)', $e->getMessage());
        }
        self::assertSame(['begin', 'query', 'query', 'query', 'rollback'], $db->kinds());
        $repo->purgeExpiredSnapshots(self::ORG);
        self::assertSame(['begin', 'fetch', 'commit'], array_slice($db->kinds(), 5));
    }
}
