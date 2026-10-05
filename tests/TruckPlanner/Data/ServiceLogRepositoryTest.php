<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\PlanRepository;
use App\TruckPlanner\Data\ServiceLogRepository;
use PHPUnit\Framework\TestCase;

final class ServiceLogRepositoryTest extends TestCase
{
    private const ORG = '11111111-1111-4111-8111-111111111111';
    private const USER = '22222222-2222-4222-8222-222222222222';
    private const TRUCK = '33333333-3333-4333-8333-333333333333';
    private const SPOT = '44444444-4444-4444-8444-444444444444';
    private const PLAN = '55555555-5555-4555-8555-555555555555';
    private const STOP = '66666666-6666-4666-8666-666666666666';
    private const LOG = '77777777-7777-4777-8777-777777777777';

    private const WEATHER = [
        'ctx' => [null, ['hour' => 1, 'temp_f' => 58.0, 'precip_prob' => null, 'short_forecast' => "Patchy Fog \u{2014} caf\u{00E9}", 'wind_mph' => 5.0]],
        'ctx_next' => null,
    ];

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * A log as MySQL returns it (without the weather, which the row reads leave out).
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private static function databaseRow(array $changes = []): array
    {
        return $changes + [
            'id' => self::LOG, 'organization_id' => self::ORG, 'truck_id' => self::TRUCK, 'created_by' => self::USER, 'log_kind' => 'spot',
            'spot_id' => self::SPOT, 'plan_id' => self::PLAN, 'plan_stop_id' => self::STOP, 'service_date' => '2026-10-01', 'open_minute' => 660,
            'close_minute' => 840, 'actual_orders' => 52, 'sales_cents' => 80151, 'sold_out' => 1, 'notes' => 'Rainy start', 'src' => 'manual',
            'external_key' => null, 'treat_as' => 'sat', 'predicted_raw' => 60.49384918082338, 'pred_raw_basis' => str_repeat('a', 40),
            'predicted' => 58.1, 'pred_low' => 33.014, 'pred_high' => 93.19, 'pred_confidence' => 'rough', 'pred_basis' => 'plan',
            'prediction_json' => '{"basis":"plan","plan_id":"' . self::PLAN . '","spread":{"sigma":0.5,"v_truck":0.0},"evidence":{"truck_weight":2.0}}',
            'pred_model_version' => 'tps-0.1.0', 'pred_seeds_rev' => 1, 'pred_dataset' => 'dc-20261003-d0514a63',
            'created_at' => '2026-10-02 08:00:00', 'updated_at' => '2026-10-02 08:05:00',
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
        self::assertSame(1, preg_match('/^INSERT INTO tp_service_logs \((.+?)\) VALUES \((.+)\)$/', $call['sql'], $m), $call['sql']);
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
        $repo = new ServiceLogRepository($db);
        $repo->listRange(self::ORG, self::TRUCK, '2026-07-01', '2026-10-05', self::SPOT);
        $repo->find(self::LOG, self::ORG);
        $repo->allForCalibration(self::ORG, self::TRUCK);
        $repo->weatherOf([self::LOG], self::ORG);
        $repo->existsSame(self::ORG, self::SPOT, '2026-10-01', 660, 840, self::LOG);
        $repo->lastChangeAt(self::ORG, self::TRUCK);
        $repo->create(self::ORG, self::TRUCK, self::USER, ['service_date' => '2026-10-01', 'open_minute' => 660, 'close_minute' => 840, 'actual_orders' => 52]);
        $repo->update(self::LOG, self::ORG, ['actual_orders' => 53]);
        $repo->setRawPrediction(self::LOG, self::ORG, 60.5, str_repeat('b', 40), ['model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => null]);
        $repo->delete(self::LOG, self::ORG);

        self::assertCount(10, $db->calls);
        foreach ($db->calls as $call) {
            self::assertDoesNotMatchRegularExpression('/SELECT\s+(\w+\.)?\*/i', $call['sql']);
            self::assertDoesNotMatchRegularExpression('/\bJSON_[A-Z_]+\(|->>?/', $call['sql'], 'MySQL never looks inside a snapshot');
            self::assertContains(self::ORG, $call['params'], $call['sql']);
            if (str_starts_with($call['sql'], 'INSERT INTO')) {
                self::assertStringContainsString('organization_id', $call['sql']);
            } else {
                self::assertStringContainsString('organization_id = ?', $call['sql'], $call['sql']);
            }
        }
        self::assertNotContains('begin', $db->kinds(), 'no transaction: every write is one statement');
    }

    // ------------------------------------------------------------------------------------ reads

    public function testFindAnswersANormalisedRow(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_service_logs', self::databaseRow());
        $row = (new ServiceLogRepository($db))->find(self::LOG, self::ORG);

        self::assertSame(
            [
                'id' => self::LOG, 'organization_id' => self::ORG, 'truck_id' => self::TRUCK, 'created_by' => self::USER, 'log_kind' => 'spot',
                'spot_id' => self::SPOT, 'plan_id' => self::PLAN, 'plan_stop_id' => self::STOP, 'service_date' => '2026-10-01', 'open_minute' => 660,
                'close_minute' => 840, 'actual_orders' => 52, 'sales' => 801.51, 'sold_out' => true, 'notes' => 'Rainy start', 'src' => 'manual',
                'external_key' => null, 'treat_as' => 'sat', 'predicted_raw' => 60.49384918082338, 'pred_raw_basis' => str_repeat('a', 40),
                'predicted' => 58.1, 'pred_low' => 33.014, 'pred_high' => 93.19, 'pred_confidence' => 'rough', 'pred_basis' => 'plan',
                'prediction' => ['basis' => 'plan', 'plan_id' => self::PLAN, 'spread' => ['sigma' => 0.5, 'v_truck' => 0.0], 'evidence' => ['truck_weight' => 2.0]],
                'pred_model_version' => 'tps-0.1.0', 'pred_seeds_rev' => 1, 'pred_dataset' => 'dc-20261003-d0514a63',
                'created_at' => '2026-10-02 08:00:00', 'updated_at' => '2026-10-02 08:05:00',
            ],
            $row
        );
        $call = $db->only('FROM tp_service_logs');
        self::assertSame([self::LOG, self::ORG], $call['params']);
        self::assertStringEndsWith('FROM tp_service_logs WHERE id = ? AND organization_id = ?', $call['sql']);
        self::assertStringNotContainsString('weather_json', $call['sql'], 'the stored weather is read only where it is needed');

        // A log without a prediction, as an event log has none.
        $db = (new RecordingDatabase())->when('FROM tp_service_logs', self::databaseRow([
            'log_kind' => 'event', 'spot_id' => null, 'plan_id' => null, 'plan_stop_id' => null, 'sales_cents' => null, 'sold_out' => 0, 'notes' => null,
            'treat_as' => null, 'predicted_raw' => null, 'pred_raw_basis' => null, 'predicted' => null, 'pred_low' => null, 'pred_high' => null,
            'pred_confidence' => null, 'pred_basis' => null, 'prediction_json' => null, 'pred_model_version' => null, 'pred_seeds_rev' => null, 'pred_dataset' => null,
        ]));
        $event = (new ServiceLogRepository($db))->find(self::LOG, self::ORG);
        self::assertSame('event', $event['log_kind']);
        foreach (['spot_id', 'sales', 'notes', 'predicted_raw', 'predicted', 'pred_low', 'pred_high', 'prediction', 'pred_seeds_rev'] as $key) {
            self::assertNull($event[$key], $key);
        }
        self::assertFalse($event['sold_out']);
        self::assertNull((new ServiceLogRepository(new RecordingDatabase()))->find(self::LOG, self::ORG));
    }

    public function testListRangeIsNewestFirstAndCanBeNarrowedToASpot(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_service_logs', [self::databaseRow(), self::databaseRow(['id' => 'log-2', 'service_date' => '2026-09-24'])]);
        $repo = new ServiceLogRepository($db);
        $rows = $repo->listRange(self::ORG, self::TRUCK, '2026-07-07', '2026-10-05');
        self::assertSame([self::LOG, 'log-2'], array_column($rows, 'id'));
        self::assertSame(801.51, $rows[0]['sales']);
        $repo->listRange(self::ORG, self::TRUCK, '2026-07-07', '2026-10-05', self::SPOT);

        [$all, $one] = $db->calls;
        self::assertStringEndsWith(
            'FROM tp_service_logs WHERE organization_id = ? AND truck_id = ? AND service_date BETWEEN ? AND ? ORDER BY service_date DESC, id ASC',
            $all['sql']
        );
        self::assertSame([self::ORG, self::TRUCK, '2026-07-07', '2026-10-05'], $all['params']);
        self::assertStringContainsString('AND service_date BETWEEN ? AND ? AND spot_id = ? ORDER BY service_date DESC, id ASC', $one['sql']);
        self::assertSame([self::ORG, self::TRUCK, '2026-07-07', '2026-10-05', self::SPOT], $one['params']);
    }

    public function testAllForCalibrationReadsWhatCalibrationNeedsAndNoText(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_service_logs', [
            ['id' => 'a', 'log_kind' => 'spot', 'spot_id' => self::SPOT, 'service_date' => '2026-09-24', 'open_minute' => 660, 'close_minute' => 840,
                'actual_orders' => 52, 'sold_out' => 1, 'treat_as' => null, 'predicted_raw' => 60.49384918082338, 'pred_raw_basis' => str_repeat('a', 40),
                'predicted' => 58.1, 'pred_low' => 33.014, 'pred_high' => 93.19, 'weather_sha1' => '031586067C32C26154BDC07F2196686AC46D0B61'],
            ['id' => 'b', 'log_kind' => 'event', 'spot_id' => null, 'service_date' => '2026-10-01', 'open_minute' => 600, 'close_minute' => 900,
                'actual_orders' => 120, 'sold_out' => 0, 'treat_as' => 'sat', 'predicted_raw' => null, 'pred_raw_basis' => null,
                'predicted' => null, 'pred_low' => null, 'pred_high' => null, 'weather_sha1' => null],
        ]);
        $rows = (new ServiceLogRepository($db))->allForCalibration(self::ORG, self::TRUCK);

        self::assertSame(
            ['id' => 'a', 'log_kind' => 'spot', 'spot_id' => self::SPOT, 'service_date' => '2026-09-24', 'open_minute' => 660, 'close_minute' => 840,
                'actual_orders' => 52, 'sold_out' => true, 'treat_as' => null, 'predicted_raw' => 60.49384918082338, 'pred_raw_basis' => str_repeat('a', 40),
                'predicted' => 58.1, 'pred_low' => 33.014, 'pred_high' => 93.19, 'weather_sha1' => '031586067c32c26154bdc07f2196686ac46d0b61'],
            $rows[0]
        );
        self::assertSame([null, null, null, false, 'sat'], [$rows[1]['spot_id'], $rows[1]['predicted_raw'], $rows[1]['weather_sha1'], $rows[1]['sold_out'], $rows[1]['treat_as']]);

        $call = $db->only('FROM tp_service_logs');
        self::assertSame(
            'SELECT id, log_kind, spot_id, service_date, open_minute, close_minute, actual_orders, sold_out, treat_as, predicted_raw, pred_raw_basis, '
            . 'predicted, pred_low, pred_high, SHA1(weather_json) AS weather_sha1 FROM tp_service_logs WHERE organization_id = ? AND truck_id = ? '
            . 'ORDER BY service_date, id',
            $call['sql']
        );
        self::assertSame([self::ORG, self::TRUCK], $call['params']);
    }

    public function testTheWeatherHashIsOfTheTextThatIsStored(): void
    {
        $db = new RecordingDatabase();
        (new ServiceLogRepository($db))->create(self::ORG, self::TRUCK, null, [
            'service_date' => '2026-10-01', 'open_minute' => 660, 'close_minute' => 840, 'actual_orders' => 52, 'weather' => self::WEATHER,
        ]);
        [$bound] = self::inserted($db->calls[0]);
        self::assertSame(PlanRepository::snapshotText(self::WEATHER), $bound['weather_json']);
        self::assertSame(sha1($bound['weather_json']), ServiceLogRepository::weatherSha1(self::WEATHER), 'what MySQL answers as SHA1(weather_json)');
        self::assertSame('031586067c32c26154bdc07f2196686ac46d0b61', ServiceLogRepository::weatherSha1(self::WEATHER), 'as measured on MySQL 8.0.45');
        self::assertNull(ServiceLogRepository::weatherSha1(null));
        self::assertSame(self::WEATHER, json_decode($bound['weather_json'], true));
        self::assertStringContainsString('"temp_f":58.0', $bound['weather_json']);
    }

    public function testWeatherOfReadsTheStoredForecastsOfSomeLogs(): void
    {
        $db = (new RecordingDatabase())->when('FROM tp_service_logs', [
            ['id' => 'a', 'weather_json' => PlanRepository::snapshotText(self::WEATHER)],
            ['id' => 'b', 'weather_json' => null],
        ]);
        $weather = (new ServiceLogRepository($db))->weatherOf(['a', 'b', 'a'], self::ORG);
        self::assertSame(['a' => self::WEATHER, 'b' => null], $weather);
        $call = $db->only('FROM tp_service_logs');
        self::assertSame('SELECT id, weather_json FROM tp_service_logs WHERE organization_id = ? AND id IN (?, ?)', $call['sql']);
        self::assertSame([self::ORG, 'a', 'b'], $call['params']);

        // 450 logs are read 200 at a time; none asked, none read.
        $db = new RecordingDatabase();
        $repo = new ServiceLogRepository($db);
        $repo->weatherOf(array_map(static fn (int $i): string => 'log-' . $i, range(1, 450)), self::ORG);
        self::assertSame([201, 201, 51], array_map(static fn (array $call): int => count($call['params']), $db->calls));
        self::assertSame([], $repo->weatherOf([], self::ORG));
        self::assertCount(3, $db->calls);
    }

    public function testExistsSameLooksForTheSpotDateAndWindow(): void
    {
        $db = (new RecordingDatabase())->queue(['id' => self::LOG], null);
        $repo = new ServiceLogRepository($db);
        self::assertTrue($repo->existsSame(self::ORG, self::SPOT, '2026-10-01', 660, 840, null));
        self::assertFalse($repo->existsSame(self::ORG, self::SPOT, '2026-10-01', 660, 840, self::LOG));

        self::assertSame(
            'SELECT id FROM tp_service_logs WHERE organization_id = ? AND spot_id = ? AND service_date = ? AND open_minute = ? AND close_minute = ? LIMIT 1',
            $db->calls[0]['sql']
        );
        self::assertSame([self::ORG, self::SPOT, '2026-10-01', 660, 840], $db->calls[0]['params']);
        self::assertStringEndsWith('AND close_minute = ? AND id <> ? LIMIT 1', $db->calls[1]['sql']);
        self::assertSame([self::ORG, self::SPOT, '2026-10-01', 660, 840, self::LOG], $db->calls[1]['params']);
    }

    public function testLastChangeAtIsTheNewestTimeStampOfTheTrucksLogs(): void
    {
        $db = (new RecordingDatabase())->queue(['changed_at' => '2026-10-02 08:05:00'], ['changed_at' => null]);
        $repo = new ServiceLogRepository($db);
        self::assertSame('2026-10-02 08:05:00', $repo->lastChangeAt(self::ORG, self::TRUCK));
        self::assertNull($repo->lastChangeAt(self::ORG, self::TRUCK), 'a truck without logs');
        self::assertSame('SELECT MAX(updated_at) AS changed_at FROM tp_service_logs WHERE organization_id = ? AND truck_id = ?', $db->calls[0]['sql']);
        self::assertSame([self::ORG, self::TRUCK], $db->calls[0]['params']);
    }

    // ------------------------------------------------------------------------------------ writes

    public function testCreateWritesEveryColumn(): void
    {
        $db = new RecordingDatabase();
        $detail = ['basis' => 'log', 'plan_id' => null, 'ctx_date_types' => ['weekday', 'weekday'], 'holiday' => null, 'spread' => ['sigma' => 0.1 + 0.2, 'v_truck' => 0.0]];
        $id = (new ServiceLogRepository($db))->create(self::ORG, self::TRUCK, self::USER, [
            'spot_id' => self::SPOT, 'plan_id' => self::PLAN, 'plan_stop_id' => self::STOP, 'service_date' => '2026-10-01', 'open_minute' => 660,
            'close_minute' => 840, 'actual_orders' => 52, 'sales' => 801.505, 'sold_out' => true, 'notes' => 'Rainy start', 'treat_as' => 'sat',
            'weather' => self::WEATHER, 'predicted_raw' => 60.49384918082338, 'pred_raw_basis' => str_repeat('a', 40), 'predicted' => 58.1,
            'pred_low' => 33.0, 'pred_high' => 0.1 + 0.2, 'pred_confidence' => 'rough', 'pred_basis' => 'log', 'prediction' => $detail,
            'pred_model_version' => 'tps-0.1.0', 'pred_seeds_rev' => 1, 'pred_dataset' => 'dc-1',
        ]);

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        [$bound, $expressions] = self::inserted($db->only('INSERT INTO tp_service_logs'));
        self::assertSame(
            [
                'id' => $id, 'organization_id' => self::ORG, 'truck_id' => self::TRUCK, 'created_by' => self::USER, 'log_kind' => 'spot',
                'spot_id' => self::SPOT, 'plan_id' => self::PLAN, 'plan_stop_id' => self::STOP, 'service_date' => '2026-10-01', 'open_minute' => 660,
                'close_minute' => 840, 'actual_orders' => 52, 'sales_cents' => 80151, 'sold_out' => 1, 'notes' => 'Rainy start', 'src' => 'manual',
                'external_key' => null, 'treat_as' => 'sat', 'weather_json' => PlanRepository::snapshotText(self::WEATHER),
                'predicted_raw' => '60.49384918082338', 'pred_raw_basis' => str_repeat('a', 40), 'predicted' => '58.1', 'pred_low' => '33',
                'pred_high' => '0.30000000000000004', 'pred_confidence' => 'rough', 'pred_basis' => 'log',
                'prediction_json' => '{"basis":"log","plan_id":null,"ctx_date_types":["weekday","weekday"],"holiday":null,"spread":{"sigma":0.30000000000000004,"v_truck":0.0}}',
                'pred_model_version' => 'tps-0.1.0', 'pred_seeds_rev' => 1, 'pred_dataset' => 'dc-1',
            ],
            $bound,
            'cents for money, 1 for true, the shortest exact text for a float, JSON text for the two snapshots'
        );
        self::assertSame('NOW()', $expressions['created_at']);
        self::assertSame('NOW()', $expressions['updated_at']);
        self::assertSame($detail, json_decode($bound['prediction_json'], true));

        // The least a log is: a date, a window and a count. Everything else is NULL or its default.
        $db = new RecordingDatabase();
        (new ServiceLogRepository($db))->create(self::ORG, self::TRUCK, null, ['log_kind' => 'event', 'service_date' => '2026-10-01', 'open_minute' => 0, 'close_minute' => 60, 'actual_orders' => 0]);
        [$bound] = self::inserted($db->calls[0]);
        self::assertSame(['event', null, 0, 0, null, 0, 'manual', null, null], [
            $bound['log_kind'], $bound['spot_id'], $bound['open_minute'], $bound['actual_orders'], $bound['sales_cents'], $bound['sold_out'],
            $bound['src'], $bound['weather_json'], $bound['predicted_raw'],
        ]);
        self::assertNull($bound['created_by']);
    }

    public function testWhatCannotBeWrittenIsRefusedBeforeAnyStatement(): void
    {
        $db = new RecordingDatabase();
        $repo = new ServiceLogRepository($db);
        $least = ['service_date' => '2026-10-01', 'open_minute' => 660, 'close_minute' => 840, 'actual_orders' => 52];
        foreach ([
            fn () => $repo->create(self::ORG, self::TRUCK, null, array_diff_key($least, ['actual_orders' => true])),
            fn () => $repo->create(self::ORG, self::TRUCK, null, $least + ['organization_id' => 'other']),
            fn () => $repo->create(self::ORG, self::TRUCK, null, $least + ['weather_json' => '{}']),
            fn () => $repo->update(self::LOG, self::ORG, ['id' => 'other']),
            fn () => $repo->update(self::LOG, self::ORG, ['actual_orders' => null]),
            fn () => $repo->update(self::LOG, self::ORG, ['sold_out' => null]),
        ] as $call) {
            try {
                $call();
                self::fail('a write that cannot be made was accepted');
            } catch (\LogicException $e) {
                self::assertStringStartsWith('tp_service_logs: ', $e->getMessage());
            }
        }
        try {
            $repo->update(self::LOG, self::ORG, ['predicted' => INF]);
            self::fail('a number that is not finite reached SQL');
        } catch (\DomainException $e) {
            self::assertSame('a non-finite number cannot be written', $e->getMessage());
        }
        self::assertSame([], $db->calls);
    }

    public function testUpdateChangesTheGivenColumnsInOneStatement(): void
    {
        $db = new RecordingDatabase();
        $repo = new ServiceLogRepository($db);
        $repo->update(self::LOG, self::ORG, ['actual_orders' => 61, 'sales' => null, 'sold_out' => false, 'notes' => null, 'plan_id' => null, 'weather' => null, 'predicted' => 12.5]);
        $call = $db->only('UPDATE tp_service_logs');
        self::assertSame(
            'UPDATE tp_service_logs SET actual_orders = ?, sales_cents = ?, sold_out = ?, notes = ?, plan_id = ?, weather_json = ?, predicted = ? '
            . 'WHERE id = ? AND organization_id = ?',
            $call['sql']
        );
        self::assertSame([61, null, 0, null, null, null, '12.5', self::LOG, self::ORG], $call['params']);
        $repo->update(self::LOG, self::ORG, []);
        self::assertCount(1, $db->calls, 'nothing to change, nothing sent');
    }

    public function testSetRawPredictionLeavesWhatTheOwnerWasShown(): void
    {
        $db = new RecordingDatabase();
        $repo = new ServiceLogRepository($db);
        $repo->setRawPrediction(self::LOG, self::ORG, 0.1 + 0.2, str_repeat('b', 40), ['model_version' => 'tps-0.1.0', 'seeds_revision' => 2, 'dataset_version' => 'dc-2']);
        self::assertSame(
            'UPDATE tp_service_logs SET predicted_raw = ?, pred_raw_basis = ?, pred_model_version = ?, pred_seeds_rev = ?, pred_dataset = ? '
            . 'WHERE id = ? AND organization_id = ?',
            $db->calls[0]['sql']
        );
        self::assertSame(['0.30000000000000004', str_repeat('b', 40), 'tps-0.1.0', 2, 'dc-2', self::LOG, self::ORG], $db->calls[0]['params']);

        // A log the model could not read: no number, but the basis it was tried under.
        $repo->setRawPrediction(self::LOG, self::ORG, null, str_repeat('c', 40), ['model_version' => 'tps-0.1.0', 'seeds_revision' => 2, 'dataset_version' => null]);
        self::assertSame([null, str_repeat('c', 40), 'tps-0.1.0', 2, null, self::LOG, self::ORG], $db->calls[1]['params']);
    }

    public function testDeleteRemovesOneLogOfTheOrganization(): void
    {
        $db = new RecordingDatabase();
        (new ServiceLogRepository($db))->delete(self::LOG, self::ORG);
        self::assertSame('DELETE FROM tp_service_logs WHERE id = ? AND organization_id = ?', $db->calls[0]['sql']);
        self::assertSame([self::LOG, self::ORG], $db->calls[0]['params']);
    }
}
