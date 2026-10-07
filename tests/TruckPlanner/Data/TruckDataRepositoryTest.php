<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Core\Database;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\TruckDataRepository;
use App\TruckPlanner\Services\Support\TpConfig;
use PHPUnit\Framework\TestCase;

/**
 * TruckDataRepository: the statements it writes (RecordingDatabase), and what they do to rows
 * (TruckTables, an in-memory copy of the owner tables that runs exactly those statements).
 *
 * The two fixture classes at the end of this file, TruckTables and TruckDataFixtures, are shared with the
 * tests of the export and of the purge service.
 */
final class TruckDataRepositoryTest extends TestCase
{
    private const ORG = TruckDataFixtures::ORG;
    private const OTHER_ORG = TruckDataFixtures::OTHER_ORG;
    private const TRUCK = TruckDataFixtures::TRUCK;

    /**
     * What a page statement must never name: Google content, and what the export does not carry. The first
     * five are the lead columns that held looked-up contact details before only the place id was kept: they
     * are gone from the table, and no statement may bring them back.
     */
    private const NEVER_SELECTED = [
        'g_name', 'g_address', 'g_phone', 'g_website', 'g_maps_uri', 'g_lookup_state', 'g_fetched_at',
        'context_json', 'weather_json', 'vectors_bin', 'pred_raw_basis',
    ];

    protected function tearDown(): void
    {
        TpConfig::replace(null);
    }

    // ------------------------------------------------------------------------------------ page(): statements

    public function testAPageIsReadByAscendingIdForOneOrganization(): void
    {
        foreach (TruckDataRepository::PAGE_TABLES as $table) {
            $db = new RecordingDatabase();
            (new TruckDataRepository($db))->page($table, self::ORG, 'after-this-id', 200);

            $call = $db->only('FROM ' . $table);
            self::assertSame('fetchAll', $call['kind']);
            self::assertStringEndsWith('FROM ' . $table . ' WHERE organization_id = ? AND id > ? ORDER BY id LIMIT ?', $call['sql'], $table);
            // The plan page also binds the lifetime of a result that holds Google legs, in its select list.
            $expected = $table === 'tp_plans' ? [30, self::ORG, 'after-this-id', 200] : [self::ORG, 'after-this-id', 200];
            self::assertSame($expected, $call['params'], $table);
            self::assertStringNotContainsString('*', $call['sql'], $table . ' names every column');
            self::assertCount(1, $db->calls, $table);
        }
    }

    public function testNoPageNamesGoogleContentOrDerivedData(): void
    {
        foreach (TruckDataRepository::PAGE_TABLES as $table) {
            $db = new RecordingDatabase();
            (new TruckDataRepository($db))->page($table, self::ORG, '', 50);
            $sql = $db->only('FROM ' . $table)['sql'];
            foreach (self::NEVER_SELECTED as $column) {
                self::assertStringNotContainsString($column, $sql, $table . ' must not read ' . $column);
            }
        }
        // A plan's result is only tested for presence on the page: its text is read by planResult() alone.
        $db = new RecordingDatabase();
        (new TruckDataRepository($db))->page('tp_plans', self::ORG, '', 50);
        $sql = $db->only('FROM tp_plans')['sql'];
        self::assertStringContainsString('(result_json IS NOT NULL) AS has_result', $sql);
        self::assertSame(1, substr_count($sql, 'result_json'));
        self::assertStringContainsString('(result_has_google = 1 AND evaluated_at < NOW() - INTERVAL ? DAY) AS snapshot_expired', $sql);
    }

    public function testOnlyTheFiveListedTablesCanBePaged(): void
    {
        self::assertSame(['tp_spots', 'tp_plans', 'tp_service_logs', 'tp_drive_overrides', 'tp_scout_leads'], TruckDataRepository::PAGE_TABLES);
        foreach (['tp_trucks', 'tp_drive_legs', 'tp_plan_stops', 'users', 'tp_spots WHERE 1 = 1 --', ''] as $table) {
            $db = new RecordingDatabase();
            try {
                (new TruckDataRepository($db))->page($table, self::ORG, '', 10);
                self::fail('a page of ' . $table . ' was read');
            } catch (\LogicException $e) {
                self::assertSame([], $db->calls, 'nothing reaches the database');
            }
        }
    }

    public function testTheSnapshotLifetimeComesFromTheSettings(): void
    {
        $config = TpConfig::all();
        $config['plans']['snapshot_ttl_days'] = 7;
        TpConfig::replace($config);
        $db = new RecordingDatabase();
        (new TruckDataRepository($db))->page('tp_plans', self::ORG, '', 5);
        self::assertSame([7, self::ORG, '', 5], $db->only('FROM tp_plans')['params']);
    }

    // ------------------------------------------------------------------------------------ page(): rows

    public function testASpotRowIsNormalisedWithoutItsVectors(): void
    {
        $tables = new TruckTables();
        $tables->add('tp_spots', TruckDataFixtures::spot('s1', [
            'name' => 'Sterling taproom', 'lat' => 39.01, 'lng' => -77.41, 'address' => '1 Example Rd', 'county_fips' => '51107',
            'notes' => 'Ask for Dana', 'visibility' => 'prominent', 'host_segment' => 'v_nightlife', 'host_size' => 120.0,
            'host_size_source' => 'owner', 'host_only_food' => 1, 'host_place_type' => 'taproom', 'host_name' => 'Example Brewing',
            'host_contact' => 'Dana', 'host_phone' => '(703) 555-0100', 'host_website' => 'https://example.com/',
            'place_key' => 'w264230766', 'host_point_id' => 'pw264230766', 'google_place_id' => 'ChIJexample',
            'fee_flat_cents' => 2550, 'fee_pct' => 0.1, 'fee_min_cents' => 7500,
            'allowed_json' => '{"days": [true, true, true, true, true, false, false], "open_minute": 660, "close_minute": 1320}',
            'archived_at' => '2026-10-04 09:00:00',
        ]));

        $rows = (new TruckDataRepository($tables))->page('tp_spots', self::ORG, '', 10);

        self::assertSame([[
            'id' => 's1',
            'truck_id' => self::TRUCK,
            'name' => 'Sterling taproom',
            'lat' => 39.01,
            'lng' => -77.41,
            'address' => '1 Example Rd',
            'county_fips' => '51107',
            'notes' => 'Ask for Dana',
            'visibility' => 'prominent',
            'host_segment' => 'v_nightlife',
            'host_size' => 120.0,
            'host_size_source' => 'owner',
            'host_only_food' => true,
            'host_place_type' => 'taproom',
            'host_name' => 'Example Brewing',
            'host_contact' => 'Dana',
            'host_phone' => '(703) 555-0100',
            'host_website' => 'https://example.com/',
            'place_key' => 'w264230766',
            'host_point_id' => 'pw264230766',
            'google_place_id' => 'ChIJexample',
            'fee_flat' => 25.5,
            'fee_pct' => 0.1,
            'fee_min' => 75.0,
            'allowed' => ['days' => [true, true, true, true, true, false, false], 'open_minute' => 660, 'close_minute' => 1320],
            'archived_at' => '2026-10-04 09:00:00',
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-01 08:00:00',
        ]], $rows);

        // A spot with nothing optional: nulls stay null, and a broken `allowed` is no rule at all.
        $tables = new TruckTables();
        $tables->add('tp_spots', TruckDataFixtures::spot('s2', ['allowed_json' => '{"days": [true]}']));
        $plain = (new TruckDataRepository($tables))->page('tp_spots', self::ORG, '', 10)[0];
        self::assertNull($plain['host_segment']);
        self::assertNull($plain['host_size']);
        self::assertFalse($plain['host_only_food']);
        self::assertNull($plain['notes']);
        self::assertNull($plain['allowed']);
        self::assertNull($plain['archived_at']);
        self::assertSame(0.0, $plain['fee_flat']);
    }

    public function testAPlanRowSaysWhetherItHasAResultAndWhetherThatResultHasExpired(): void
    {
        $tables = new TruckTables();
        $tables->now = '2026-10-05 12:00:00';
        $tables->add('tp_plans', TruckDataFixtures::plan('p1', '2026-10-08', [
            'name' => 'Thursday', 'treat_as' => 'sat', 'notes' => 'Bring the awning', 'plan_state' => 'planned',
            'result_json' => '{"totals": {}}', 'result_has_google' => 1, 'evaluated_at' => '2026-10-04 23:50:12',
            'model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => 'dc-20261003-d0514a63',
        ]));
        $tables->add('tp_plans', TruckDataFixtures::plan('p2', '2026-08-06', [
            'result_json' => '{"totals": {}}', 'result_has_google' => 1, 'evaluated_at' => '2026-09-05 11:59:59',
        ]));
        $tables->add('tp_plans', TruckDataFixtures::plan('p3', '2026-08-07', [
            'result_json' => '{"totals": {}}', 'result_has_google' => 0, 'evaluated_at' => '2026-08-01 00:00:00',
        ]));
        $tables->add('tp_plans', TruckDataFixtures::plan('p4', '2026-10-09'));

        $rows = (new TruckDataRepository($tables))->page('tp_plans', self::ORG, '', 10);

        self::assertSame([
            'id' => 'p1',
            'truck_id' => self::TRUCK,
            'service_date' => '2026-10-08',
            'name' => 'Thursday',
            'treat_as' => 'sat',
            'notes' => 'Bring the awning',
            'plan_state' => 'planned',
            'result_has_google' => true,
            'evaluated_at' => '2026-10-04 23:50:12',
            'model_version' => 'tps-0.1.0',
            'seeds_revision' => 1,
            'dataset_version' => 'dc-20261003-d0514a63',
            'has_result' => true,
            'snapshot_expired' => false,
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-01 08:00:00',
        ], $rows[0]);
        // Thirty days and a second after a result with Google legs was computed, it has expired.
        self::assertSame([true, true], [$rows[1]['has_result'], $rows[1]['snapshot_expired']]);
        // A result without Google legs has no lifetime.
        self::assertSame([true, false], [$rows[2]['has_result'], $rows[2]['snapshot_expired']]);
        self::assertSame([false, false], [$rows[3]['has_result'], $rows[3]['snapshot_expired']]);
        self::assertNull($rows[3]['evaluated_at']);
        self::assertNull($rows[3]['seeds_revision']);
        self::assertNull($rows[3]['treat_as']);
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('result_json', $row);
            self::assertArrayNotHasKey('context_json', $row);
        }
    }

    public function testALogRowCarriesItsPredictionAndNoWeather(): void
    {
        $tables = new TruckTables();
        $tables->add('tp_service_logs', TruckDataFixtures::log('l1', '2026-09-24', [
            'spot_id' => 's1', 'plan_id' => 'p1', 'plan_stop_id' => 'st1', 'actual_orders' => 52, 'sales_cents' => 78050, 'sold_out' => 1,
            'notes' => 'Rain at noon', 'src' => 'manual', 'external_key' => 'k-1', 'treat_as' => 'normal',
            'predicted_raw' => 60.4938, 'predicted' => 58.2, 'pred_low' => 31.7, 'pred_high' => 89.6, 'pred_confidence' => 'rough',
            'pred_basis' => 'plan', 'prediction_json' => '{"basis": "plan", "spread": {"sigma": 0.41}}',
            'pred_model_version' => 'tps-0.1.0', 'pred_seeds_rev' => 1, 'pred_dataset' => 'dc-20261003-d0514a63',
        ]));
        $tables->add('tp_service_logs', TruckDataFixtures::log('l2', '2026-09-25', ['log_kind' => 'catering', 'actual_orders' => 80]));

        $rows = (new TruckDataRepository($tables))->page('tp_service_logs', self::ORG, '', 10);

        self::assertSame([
            'id' => 'l1',
            'truck_id' => self::TRUCK,
            'log_kind' => 'spot',
            'spot_id' => 's1',
            'plan_id' => 'p1',
            'plan_stop_id' => 'st1',
            'service_date' => '2026-09-24',
            'open_minute' => 660,
            'close_minute' => 840,
            'actual_orders' => 52,
            'sales' => 780.5,
            'sold_out' => true,
            'notes' => 'Rain at noon',
            'src' => 'manual',
            'external_key' => 'k-1',
            'treat_as' => 'normal',
            'predicted_raw' => 60.4938,
            'predicted' => 58.2,
            'pred_low' => 31.7,
            'pred_high' => 89.6,
            'pred_confidence' => 'rough',
            'pred_basis' => 'plan',
            'prediction' => ['basis' => 'plan', 'spread' => ['sigma' => 0.41]],
            'pred_model_version' => 'tps-0.1.0',
            'pred_seeds_rev' => 1,
            'pred_dataset' => 'dc-20261003-d0514a63',
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-01 08:00:00',
        ], $rows[0]);
        self::assertSame('catering', $rows[1]['log_kind']);
        self::assertNull($rows[1]['sales']);
        self::assertNull($rows[1]['predicted']);
        self::assertNull($rows[1]['prediction']);
        self::assertFalse($rows[1]['sold_out']);
        self::assertArrayNotHasKey('weather', $rows[0]);
    }

    public function testACorrectionAndALeadAreNormalised(): void
    {
        $tables = new TruckTables();
        $tables->add('tp_drive_overrides', TruckDataFixtures::correction('c1', ['override_minutes' => 14, 'toll_cents' => 375, 'note' => 'School zone']));
        $tables->add('tp_drive_overrides', TruckDataFixtures::correction('c2', ['override_minutes' => null, 'toll_cents' => null]));
        $tables->add('tp_scout_leads', TruckDataFixtures::lead('d1', 'w264230766', ['lead_state' => 'contacted', 'notes' => 'Called Tuesday', 'spot_id' => 's1']));
        $repository = new TruckDataRepository($tables);

        $corrections = $repository->page('tp_drive_overrides', self::ORG, '', 10);
        self::assertSame([
            'id' => 'c1',
            'truck_id' => self::TRUCK,
            'o_lat_e4' => 389600,
            'o_lng_e4' => -773600,
            'd_lat_e4' => 390030,
            'd_lng_e4' => -774050,
            'override_minutes' => 14,
            'toll' => 3.75,
            'note' => 'School zone',
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-01 08:00:00',
        ], $corrections[0]);
        self::assertSame([null, null], [$corrections[1]['override_minutes'], $corrections[1]['toll']]);

        $leads = $repository->page('tp_scout_leads', self::ORG, '', 10);
        self::assertSame([[
            'id' => 'd1',
            'truck_id' => self::TRUCK,
            'region_id' => 'dc',
            'place_key' => 'w264230766',
            'place_name' => 'Example Brewing',
            'place_type' => 'taproom',
            'lat' => 39.01,
            'lng' => -77.41,
            'lead_state' => 'contacted',
            'notes' => 'Called Tuesday',
            'spot_id' => 's1',
            'google_place_id' => 'ChIJexample',
            'created_at' => '2026-10-01 08:00:00',
            'updated_at' => '2026-10-01 08:00:00',
        ]], $leads, 'the looked-up contact fields of the lead stay behind');
    }

    public function testPagesWalkATableOnceAndStayInsideTheOrganization(): void
    {
        $tables = new TruckTables();
        foreach (['s5', 's1', 's4', 's2', 's3'] as $id) {
            $tables->add('tp_spots', TruckDataFixtures::spot($id));
        }
        $tables->add('tp_spots', TruckDataFixtures::spot('s0-theirs', [], self::OTHER_ORG));
        $tables->add('tp_spots', TruckDataFixtures::spot('s6-theirs', [], self::OTHER_ORG));
        $repository = new TruckDataRepository($tables);

        $seen = [];
        $after = '';
        $pages = 0;
        do {
            $rows = $repository->page('tp_spots', self::ORG, $after, 2);
            foreach ($rows as $row) {
                $seen[] = $row['id'];
                $after = $row['id'];
            }
            $pages++;
        } while (count($rows) === 2);

        self::assertSame(['s1', 's2', 's3', 's4', 's5'], $seen);
        self::assertSame(3, $pages);
        self::assertSame(['s0-theirs', 's6-theirs'], array_column($repository->page('tp_spots', self::OTHER_ORG, '', 10), 'id'));
        self::assertSame([], $repository->page('tp_spots', 'nobody', '', 10));
        // A limit below one still reads a row at a time rather than everything or nothing.
        self::assertCount(1, $repository->page('tp_spots', self::ORG, '', 0));
    }

    // ------------------------------------------------------------------------------------ plans: stops and result

    public function testThePlanStopsAreReadForManyPlansAtOnceInVisitingOrder(): void
    {
        $tables = new TruckTables();
        $tables->add('tp_plan_stops', TruckDataFixtures::stop('st2', 'p1', 1, [
            'stop_kind' => 'event', 'spot_id' => null, 'label' => 'Fall fair', 'lat' => 38.95, 'lng' => -77.35, 'address' => 'Fairgrounds',
            'open_minute' => 1020, 'close_minute' => 1200, 'gap_before_unpaid' => 1, 'setup_minutes' => 45, 'teardown_minutes' => 25,
            'fee_flat_cents' => 10000, 'fee_pct' => 0.1, 'fee_min_cents' => 15000,
            'ev_attendance' => 4000.0, 'ev_vendor_count' => 12, 'ev_type' => 'general',
        ]));
        $tables->add('tp_plan_stops', TruckDataFixtures::stop('st1', 'p1', 0, ['spot_id' => 's1']));
        $tables->add('tp_plan_stops', TruckDataFixtures::stop('st3', 'p2', 0, [
            'stop_kind' => 'catering', 'spot_id' => null, 'label' => 'Office lunch', 'lat' => 38.9, 'lng' => -77.3,
            'cat_headcount' => 80.0, 'cat_price_head_cents' => 1400, 'cat_guarantee_cents' => 100000, 'cat_food_cost_cents' => null,
        ]));
        $tables->add('tp_plan_stops', TruckDataFixtures::stop('st9', 'p1', 0, ['spot_id' => 'theirs'], self::OTHER_ORG));
        $repository = new TruckDataRepository($tables);

        $stops = $repository->planStops(self::ORG, ['p1', 'p2', 'p3', 'p1']);

        self::assertSame(['p1', 'p2'], array_keys($stops), 'a plan without stops is absent');
        self::assertSame(['st1', 'st2'], array_column($stops['p1'], 'id'));
        self::assertSame([
            'id' => 'st2',
            'plan_id' => 'p1',
            'seq' => 1,
            'stop_kind' => 'event',
            'spot_id' => null,
            'label' => 'Fall fair',
            'lat' => 38.95,
            'lng' => -77.35,
            'address' => 'Fairgrounds',
            'open_minute' => 1020,
            'close_minute' => 1200,
            'gap_before_unpaid' => true,
            'setup_minutes' => 45,
            'teardown_minutes' => 25,
            'fee_flat' => 100.0,
            'fee_pct' => 0.1,
            'fee_min' => 150.0,
            'ev_attendance' => 4000.0,
            'ev_vendor_count' => 12,
            'ev_type' => 'general',
            'cat_headcount' => null,
            'cat_price_head' => null,
            'cat_guarantee' => null,
            'cat_food_cost' => null,
        ], $stops['p1'][1]);
        self::assertSame([null, null, null, null], [$stops['p1'][0]['lat'], $stops['p1'][0]['lng'], $stops['p1'][0]['setup_minutes'], $stops['p1'][0]['ev_type']]);
        self::assertSame([80.0, 14.0, 1000.0, null], [$stops['p2'][0]['cat_headcount'], $stops['p2'][0]['cat_price_head'], $stops['p2'][0]['cat_guarantee'], $stops['p2'][0]['cat_food_cost']]);

        // The statement: one organization, each plan once, the stops in plan and visiting order.
        $db = new RecordingDatabase();
        (new TruckDataRepository($db))->planStops(self::ORG, ['p1', 'p2', 'p1']);
        $call = $db->only('FROM tp_plan_stops');
        self::assertStringEndsWith('FROM tp_plan_stops WHERE organization_id = ? AND plan_id IN (?, ?) ORDER BY plan_id, seq', $call['sql']);
        self::assertSame([self::ORG, 'p1', 'p2'], $call['params']);
        self::assertStringNotContainsString('*', $call['sql']);

        // No plan, no statement. Many plans, 200 to a statement.
        $db = new RecordingDatabase();
        self::assertSame([], (new TruckDataRepository($db))->planStops(self::ORG, []));
        self::assertSame([], $db->calls);
        $ids = [];
        for ($i = 0; $i < 450; $i++) {
            $ids[] = 'p' . $i;
        }
        (new TruckDataRepository($db))->planStops(self::ORG, $ids);
        self::assertSame([201, 201, 51], array_map(static fn (array $c): int => count($c['params']), $db->find('FROM tp_plan_stops')));
    }

    public function testThePlanResultIsReadForOnePlanOfTheOrganization(): void
    {
        $tables = new TruckTables();
        $tables->add('tp_plans', TruckDataFixtures::plan('p1', '2026-10-08', ['result_json' => '{"totals": {"day_hours": 11.283333333333333}}']));
        $tables->add('tp_plans', TruckDataFixtures::plan('p2', '2026-10-09'));
        $tables->add('tp_plans', TruckDataFixtures::plan('p3', '2026-10-10', ['result_json' => 'not json']));
        $repository = new TruckDataRepository($tables);

        self::assertSame(['totals' => ['day_hours' => 11.283333333333333]], $repository->planResult('p1', self::ORG));
        self::assertNull($repository->planResult('p1', self::OTHER_ORG), 'another organization finds nothing');
        self::assertNull($repository->planResult('p2', self::ORG));
        self::assertNull($repository->planResult('p3', self::ORG));
        self::assertNull($repository->planResult('nowhere', self::ORG));

        $db = new RecordingDatabase();
        (new TruckDataRepository($db))->planResult('p1', self::ORG);
        $call = $db->only('FROM tp_plans');
        self::assertSame('SELECT result_json FROM tp_plans WHERE id = ? AND organization_id = ?', $call['sql']);
        self::assertSame(['p1', self::ORG], $call['params']);
    }

    public function testTheLastChangeOfALogIsTheNewestUpdate(): void
    {
        $tables = new TruckTables();
        $repository = new TruckDataRepository($tables);
        self::assertNull($repository->lastLogChange(self::ORG, self::TRUCK));

        $tables->add('tp_service_logs', TruckDataFixtures::log('l1', '2026-09-24', ['updated_at' => '2026-10-02 10:00:00']));
        $tables->add('tp_service_logs', TruckDataFixtures::log('l2', '2026-09-25', ['updated_at' => '2026-10-04 18:30:00']));
        $tables->add('tp_service_logs', TruckDataFixtures::log('l3', '2026-09-26', ['updated_at' => '2026-10-05 09:00:00'], self::OTHER_ORG));
        self::assertSame('2026-10-04 18:30:00', $repository->lastLogChange(self::ORG, self::TRUCK));

        $db = new RecordingDatabase();
        (new TruckDataRepository($db))->lastLogChange(self::ORG, self::TRUCK);
        $call = $db->only('FROM tp_service_logs');
        self::assertSame('SELECT MAX(updated_at) AS last_change FROM tp_service_logs WHERE organization_id = ? AND truck_id = ?', $call['sql']);
        self::assertSame([self::ORG, self::TRUCK], $call['params']);
    }

    // ------------------------------------------------------------------------------------ deleteAll()

    public function testDeleteAllCountsAndDeletesTheSevenTablesInOrderInOneTransaction(): void
    {
        $db = new RecordingDatabase();
        foreach ([12, 2, 1, 3, 1, 5, 1] as $count) {
            $db->queue(['row_count' => $count]);
        }

        $deleted = (new TruckDataRepository($db))->deleteAll(self::ORG);

        self::assertSame(
            ['services' => 12, 'plan_stops' => 2, 'plans' => 1, 'leads' => 3, 'drive_overrides' => 1, 'spots' => 5, 'trucks' => 1],
            $deleted
        );
        $expected = ['begin'];
        foreach (['tp_service_logs', 'tp_plan_stops', 'tp_plans', 'tp_scout_leads', 'tp_drive_overrides', 'tp_spots', 'tp_trucks'] as $table) {
            $expected[] = 'SELECT COUNT(*) AS row_count FROM ' . $table . ' WHERE organization_id = ?';
            $expected[] = 'DELETE FROM ' . $table . ' WHERE organization_id = ?';
        }
        $expected[] = 'commit';
        $actual = [];
        foreach ($db->calls as $call) {
            $actual[] = $call['sql'] === '' ? $call['kind'] : $call['sql'];
            if ($call['sql'] !== '') {
                self::assertSame([self::ORG], $call['params'], 'every statement binds the organization and nothing else');
            }
        }
        self::assertSame($expected, $actual);
        self::assertSame(array_keys($deleted), array_values(TruckDataRepository::DELETE_ORDER));
    }

    public function testDeleteAllRemovesExactlyTheRowsOfOneOrganization(): void
    {
        $tables = TruckDataFixtures::tables();
        $before = $tables->rows;
        $counts = [];
        foreach (TruckTables::TABLES as $table) {
            $counts[$table] = [$tables->count($table, self::ORG), $tables->count($table, self::OTHER_ORG)];
        }
        self::assertGreaterThan(0, min(array_map(static fn (array $c): int => min($c[0], $c[1]), array_diff_key($counts, ['tp_drive_legs' => true]))),
            'both organizations hold rows in every owner table');

        $deleted = (new TruckDataRepository($tables))->deleteAll(self::ORG);

        self::assertSame([
            'services' => $counts['tp_service_logs'][0],
            'plan_stops' => $counts['tp_plan_stops'][0],
            'plans' => $counts['tp_plans'][0],
            'leads' => $counts['tp_scout_leads'][0],
            'drive_overrides' => $counts['tp_drive_overrides'][0],
            'spots' => $counts['tp_spots'][0],
            'trucks' => 1,
        ], $deleted);
        foreach (array_keys(TruckDataRepository::DELETE_ORDER) as $table) {
            self::assertSame(0, $tables->count($table, self::ORG), $table . ' of the organization');
            self::assertSame($counts[$table][1], $tables->count($table, self::OTHER_ORG), $table . ' of the other organization');
            // The other organization's rows are the very rows they were.
            $theirs = array_values(array_filter($before[$table], static fn (array $row): bool => $row['organization_id'] === self::OTHER_ORG));
            self::assertSame($theirs, $tables->rows[$table], $table);
        }
        // The shared cache of Google legs has no tenant and is not part of anybody's data.
        self::assertSame($before['tp_drive_legs'], $tables->rows['tp_drive_legs']);
        self::assertSame(['begin', 'commit'], array_values(array_filter($tables->events, static fn (string $e): bool => in_array($e, ['begin', 'commit', 'rollback'], true))));

        // Again: nothing is left to delete, and that is an answer, not an error.
        self::assertSame(
            ['services' => 0, 'plan_stops' => 0, 'plans' => 0, 'leads' => 0, 'drive_overrides' => 0, 'spots' => 0, 'trucks' => 0],
            (new TruckDataRepository($tables))->deleteAll(self::ORG)
        );
    }

    public function testAFailureHalfWayRollsEverythingBack(): void
    {
        $tables = TruckDataFixtures::tables();
        $before = $tables->rows;
        $tables->failOn('DELETE FROM tp_drive_overrides');

        try {
            (new TruckDataRepository($tables))->deleteAll(self::ORG);
            self::fail('the failure was swallowed');
        } catch (\RuntimeException $e) {
            self::assertSame('database failure (test)', $e->getMessage());
        }

        self::assertSame($before, $tables->rows, 'not one row is gone');
        self::assertSame(['begin', 'rollback'], array_values(array_filter($tables->events, static fn (string $e): bool => in_array($e, ['begin', 'commit', 'rollback'], true))));
    }

    // ------------------------------------------------------------------------------------ operator reads

    public function testExpiredGoogleContentIsCountedWithItsTwoLifetimes(): void
    {
        $tables = TruckDataFixtures::tables();
        $tables->now = '2026-10-05 12:00:00';
        $repository = new TruckDataRepository($tables);

        // The fixture holds: one leg of 31 days and one of 29; a plan result with Google legs of 35 days, one
        // of 2 days, one old without Google legs. It also holds a lead whose place was matched 40 days ago:
        // a lead keeps Google's id of the place and nothing that expires, so the leads are not counted.
        self::assertSame(['drive_legs' => 1, 'plan_snapshots' => 1], $repository->expiredGoogleCounts());

        $db = new RecordingDatabase();
        $db->queue(['drive_legs' => '4', 'plan_snapshots' => '7']);
        self::assertSame(['drive_legs' => 4, 'plan_snapshots' => 7], (new TruckDataRepository($db))->expiredGoogleCounts());
        $call = $db->calls[0];
        self::assertSame(
            'SELECT (SELECT COUNT(*) FROM tp_drive_legs WHERE fetched_at < NOW() - INTERVAL ? DAY) AS drive_legs, '
            . '(SELECT COUNT(*) FROM tp_plans WHERE result_has_google = 1 AND evaluated_at < NOW() - INTERVAL ? DAY) AS plan_snapshots',
            $call['sql']
        );
        self::assertSame([30, 30], $call['params']);
        self::assertStringNotContainsString('tp_scout_leads', $call['sql']);

        // Each lifetime is its own setting.
        $config = TpConfig::all();
        $config['routing']['leg_ttl_days'] = 10;
        $config['plans']['snapshot_ttl_days'] = 40;
        TpConfig::replace($config);
        $db = new RecordingDatabase();
        (new TruckDataRepository($db))->expiredGoogleCounts();
        self::assertSame([10, 40], $db->calls[0]['params']);
        self::assertSame(['drive_legs' => 2, 'plan_snapshots' => 0], $repository->expiredGoogleCounts());
    }

    public function testTheOrganizationsWithATruckAreListedByPage(): void
    {
        $tables = new TruckTables();
        foreach (['org-c' => 'dc', 'org-a' => 'dc', 'org-d' => 'mini', 'org-b' => 'none'] as $org => $region) {
            $tables->add('tp_trucks', TruckDataFixtures::truck($org, 'truck-of-' . $org, ['region_id' => $region]));
        }
        $repository = new TruckDataRepository($tables);

        self::assertSame(['org-a', 'org-b'], $repository->truckOrganizations(null, '', 2));
        self::assertSame(['org-c', 'org-d'], $repository->truckOrganizations(null, 'org-b', 2));
        self::assertSame([], $repository->truckOrganizations(null, 'org-d', 2));
        self::assertSame(['org-a', 'org-c'], $repository->truckOrganizations('dc', '', 10));
        self::assertSame(['org-c'], $repository->truckOrganizations('dc', 'org-a', 10));
        self::assertSame([], $repository->truckOrganizations('nowhere', '', 10));

        $db = new RecordingDatabase();
        (new TruckDataRepository($db))->truckOrganizations(null, 'org-b', 100);
        (new TruckDataRepository($db))->truckOrganizations('dc', '', 100);
        self::assertSame('SELECT organization_id FROM tp_trucks WHERE organization_id > ? ORDER BY organization_id LIMIT ?', $db->calls[0]['sql']);
        self::assertSame(['org-b', 100], $db->calls[0]['params']);
        self::assertSame('SELECT organization_id FROM tp_trucks WHERE organization_id > ? AND region_id = ? ORDER BY organization_id LIMIT ?', $db->calls[1]['sql']);
        self::assertSame(['', 'dc', 100], $db->calls[1]['params']);
    }

    // ------------------------------------------------------------------------------------ the rules of every repository

    public function testEveryStatementOnAnOwnerTableCarriesTheOrganization(): void
    {
        $db = new RecordingDatabase();
        $repository = new TruckDataRepository($db);
        foreach (TruckDataRepository::PAGE_TABLES as $table) {
            $repository->page($table, self::ORG, '', 10);
        }
        $repository->planStops(self::ORG, ['p1']);
        $repository->planResult('p1', self::ORG);
        $repository->lastLogChange(self::ORG, self::TRUCK);
        $repository->deleteAll(self::ORG);

        $statements = 0;
        foreach ($db->calls as $call) {
            if ($call['sql'] === '') {
                continue;
            }
            $statements++;
            self::assertStringContainsString('organization_id = ?', $call['sql']);
            self::assertContains(self::ORG, $call['params'], $call['sql']);
            self::assertDoesNotMatchRegularExpression('/\bSELECT\s+(\w+\.)?\*/i', $call['sql']);
            foreach ($call['params'] as $param) {
                self::assertFalse(is_float($param) || is_bool($param) || is_array($param), 'a float, a boolean or an array was bound');
            }
        }
        self::assertSame(5 + 3 + 14, $statements);
    }
}

/**
 * The owner tables of Truck Planner in memory, behind the Database interface. It runs the statements that
 * TruckDataRepository writes (and the two reads the export borrows from TruckRepository and
 * CountsRepository) on rows held as MySQL would return them, and answers a select with exactly the columns
 * the statement names: a column that is not selected does not come back.
 */
final class TruckTables extends Database
{
    public const TABLES = [
        'tp_trucks', 'tp_spots', 'tp_plans', 'tp_plan_stops', 'tp_service_logs', 'tp_drive_legs', 'tp_drive_overrides', 'tp_scout_leads',
    ];

    /** @var array<string, list<array<string, mixed>>> table => rows */
    public array $rows = [
        'tp_trucks' => [], 'tp_spots' => [], 'tp_plans' => [], 'tp_plan_stops' => [], 'tp_service_logs' => [],
        'tp_drive_legs' => [], 'tp_drive_overrides' => [], 'tp_scout_leads' => [],
    ];

    /** What NOW() is. */
    public string $now = '2026-10-05 12:00:00';

    /** @var list<string> "begin", "commit", "rollback" and every statement between them, in order */
    public array $events = [];

    /** @var list<array{0: string, 1: int}> [text a statement contains, how many such statements pass first] */
    private array $failures = [];

    /** @var array<string, list<array<string, mixed>>>|null the rows when the open transaction began */
    private ?array $saved = null;

    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function add(string $table, array $row): void
    {
        $this->rows[$table][] = $row;
    }

    public function count(string $table, ?string $org = null): int
    {
        if ($org === null || $table === 'tp_drive_legs') {
            return count($this->rows[$table]);
        }
        return count(array_filter($this->rows[$table], static fn (array $row): bool => $row['organization_id'] === $org));
    }

    /** The statement that contains `$needle` throws, after `$skip` such statements have passed. */
    public function failOn(string $needle, int $skip = 0): void
    {
        $this->failures[] = [$needle, $skip];
    }

    /**
     * The statements that contain `$needle`, in order.
     *
     * @return list<string>
     */
    public function statements(string $needle = ''): array
    {
        return array_values(array_filter(
            $this->events,
            static fn (string $event): bool => !in_array($event, ['begin', 'commit', 'rollback'], true) && ($needle === '' || str_contains($event, $needle))
        ));
    }

    public function beginTransaction(): void
    {
        if ($this->saved !== null) {
            throw new \LogicException('TruckTables: there is already an active transaction');
        }
        $this->saved = $this->rows;
        $this->events[] = 'begin';
    }

    public function commit(): void
    {
        $this->saved = null;
        $this->events[] = 'commit';
    }

    public function rollback(): void
    {
        if ($this->saved !== null) {
            $this->rows = $this->saved;
        }
        $this->saved = null;
        $this->events[] = 'rollback';
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $sql = $this->record($sql);
        if (preg_match('~^DELETE FROM (tp_[a-z_]+) WHERE organization_id = \?$~', $sql, $m) === 1 && isset($this->rows[$m[1]])) {
            [$org] = self::bound($params, 1);
            $this->rows[$m[1]] = array_values(array_filter($this->rows[$m[1]], static fn (array $row): bool => $row['organization_id'] !== $org));
            return new \PDOStatement();
        }
        throw new \LogicException('TruckTables: unexpected statement: ' . $sql);
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        $sql = $this->record($sql);
        if (preg_match('~^SELECT COUNT\(\*\) AS row_count FROM (tp_[a-z_]+) WHERE organization_id = \?$~', $sql, $m) === 1 && isset($this->rows[$m[1]])) {
            [$org] = self::bound($params, 1);
            return ['row_count' => $this->count($m[1], (string) $org)];
        }
        if ($sql === 'SELECT result_json FROM tp_plans WHERE id = ? AND organization_id = ?') {
            [$id, $org] = self::bound($params, 2);
            foreach ($this->rows['tp_plans'] as $row) {
                if ($row['id'] === $id && $row['organization_id'] === $org) {
                    return ['result_json' => $row['result_json']];
                }
            }
            return null;
        }
        if ($sql === 'SELECT MAX(updated_at) AS last_change FROM tp_service_logs WHERE organization_id = ? AND truck_id = ?') {
            [$org, $truck] = self::bound($params, 2);
            $last = null;
            foreach ($this->rows['tp_service_logs'] as $row) {
                if ($row['organization_id'] === $org && $row['truck_id'] === $truck && ($last === null || $row['updated_at'] > $last)) {
                    $last = $row['updated_at'];
                }
            }
            return ['last_change' => $last];
        }
        if (str_contains($sql, ') AS drive_legs, ')) {
            [$legDays, $planDays] = self::bound($params, 2);
            $legs = array_filter($this->rows['tp_drive_legs'], fn (array $r): bool => $r['fetched_at'] < $this->daysAgo((int) $legDays));
            $plans = array_filter($this->rows['tp_plans'], fn (array $r): bool => $this->expired($r, (int) $planDays));
            return ['drive_legs' => count($legs), 'plan_snapshots' => count($plans)];
        }
        if (preg_match('~^SELECT (.+) FROM tp_trucks WHERE organization_id = \?$~s', $sql, $m) === 1) {
            [$org] = self::bound($params, 1);
            foreach ($this->rows['tp_trucks'] as $row) {
                if ($row['organization_id'] === $org) {
                    return $this->project($row, $m[1], null);
                }
            }
            return null;
        }
        throw new \LogicException('TruckTables: unexpected statement: ' . $sql);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $sql = $this->record($sql);
        if (preg_match('~^SELECT (.+) FROM (tp_[a-z_]+) WHERE organization_id = \? AND id > \? ORDER BY id LIMIT \?$~s', $sql, $m) === 1
            && isset($this->rows[$m[2]])) {
            // A placeholder inside the select list (the lifetime of a plan result) is bound first.
            $inList = substr_count($m[1], '?');
            $values = self::bound($params, $inList + 3);
            $days = $inList === 1 ? (int) $values[0] : null;
            [$org, $after, $limit] = array_slice($values, $inList);
            if (!is_int($limit)) {
                throw new \LogicException('TruckTables: LIMIT takes a whole number');
            }
            $found = array_values(array_filter(
                $this->rows[$m[2]],
                static fn (array $row): bool => $row['organization_id'] === $org && strcmp((string) $row['id'], (string) $after) > 0
            ));
            usort($found, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
            return array_map(fn (array $row): array => $this->project($row, $m[1], $days), array_slice($found, 0, $limit));
        }
        if (preg_match('~^SELECT (.+) FROM tp_plan_stops WHERE organization_id = \? AND plan_id IN \(([?, ]+)\) ORDER BY plan_id, seq$~s', $sql, $m) === 1) {
            $values = self::bound($params, 1 + substr_count($m[2], '?'));
            $org = array_shift($values);
            $found = array_values(array_filter(
                $this->rows['tp_plan_stops'],
                static fn (array $row): bool => $row['organization_id'] === $org && in_array($row['plan_id'], $values, true)
            ));
            usort($found, static fn (array $a, array $b): int => strcmp((string) $a['plan_id'], (string) $b['plan_id']) ?: ($a['seq'] <=> $b['seq']));
            return array_map(fn (array $row): array => $this->project($row, $m[1], null), $found);
        }
        if (str_contains($sql, 'COUNT(*) AS log_count') && str_contains($sql, 'GROUP BY spot_id')) {
            [$org, $truck] = self::bound($params, 2);
            $bySpot = [];
            foreach ($this->rows['tp_service_logs'] as $row) {
                if ($row['organization_id'] !== $org || $row['truck_id'] !== $truck || $row['spot_id'] === null) {
                    continue;
                }
                $spot = (string) $row['spot_id'];
                $bySpot[$spot] ??= ['spot_id' => $spot, 'log_count' => 0, 'last_date' => $row['service_date']];
                $bySpot[$spot]['log_count']++;
                $bySpot[$spot]['last_date'] = max($bySpot[$spot]['last_date'], $row['service_date']);
            }
            return array_values($bySpot);
        }
        if (preg_match('~^SELECT organization_id FROM tp_trucks WHERE organization_id > \?( AND region_id = \?)? ORDER BY organization_id LIMIT \?$~', $sql, $m) === 1) {
            $region = null;
            if (($m[1] ?? '') !== '') {
                [$after, $region, $limit] = self::bound($params, 3);
            } else {
                [$after, $limit] = self::bound($params, 2);
            }
            $ids = [];
            foreach ($this->rows['tp_trucks'] as $row) {
                if (strcmp((string) $row['organization_id'], (string) $after) > 0 && ($region === null || $row['region_id'] === $region)) {
                    $ids[] = (string) $row['organization_id'];
                }
            }
            sort($ids, SORT_STRING);
            return array_map(static fn (string $id): array => ['organization_id' => $id], array_slice($ids, 0, (int) $limit));
        }
        throw new \LogicException('TruckTables: unexpected statement: ' . $sql);
    }

    private function record(string $sql): string
    {
        $squashed = trim((string) preg_replace('/\s+/', ' ', $sql));
        $this->events[] = $squashed;
        foreach ($this->failures as $i => [$needle, $skip]) {
            if (!str_contains($squashed, $needle)) {
                continue;
            }
            if ($skip > 0) {
                $this->failures[$i][1] = $skip - 1;
                continue;
            }
            throw new \RuntimeException('database failure (test)');
        }
        return $squashed;
    }

    /**
     * The bound values of a statement: exactly `$count` of them, none of a kind PDO cannot bind as it is.
     *
     * @param array<int|string, mixed> $params
     * @return list<mixed>
     */
    private static function bound(array $params, int $count): array
    {
        $values = array_values($params);
        if (count($values) !== $count) {
            throw new \LogicException('TruckTables: ' . count($values) . ' values bound, the statement has ' . $count . ' placeholders');
        }
        foreach ($values as $value) {
            if (is_float($value) || is_bool($value) || is_array($value)) {
                throw new \LogicException('TruckTables: a float, a boolean or an array was bound');
            }
        }
        return $values;
    }

    /**
     * The columns a select list names, of one row. A computed column is known by its alias.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function project(array $row, string $list, ?int $days): array
    {
        $out = [];
        foreach (self::columns($list) as $column) {
            if (preg_match('~^\(.+\) AS ([a-z_]+)$~s', $column, $m) === 1) {
                switch ($m[1]) {
                    case 'has_result':
                        $out[$m[1]] = $row['result_json'] !== null ? 1 : 0;
                        break;
                    case 'snapshot_expired':
                        $out[$m[1]] = $this->expired($row, (int) $days) ? 1 : 0;
                        break;
                    default:
                        throw new \LogicException('TruckTables: unknown computed column ' . $m[1]);
                }
                continue;
            }
            if (!array_key_exists($column, $row)) {
                throw new \LogicException('TruckTables: the table has no column ' . $column);
            }
            $out[$column] = $row[$column];
        }
        return $out;
    }

    /**
     * A select list split at the commas that stand outside parentheses.
     *
     * @return list<string>
     */
    private static function columns(string $list): array
    {
        $columns = [];
        $depth = 0;
        $current = '';
        foreach (str_split($list) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            }
            if ($char === ',' && $depth === 0) {
                $columns[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $columns[] = trim($current);
        return $columns;
    }

    /**
     * result_has_google = 1 AND evaluated_at < NOW() - INTERVAL days DAY
     *
     * @param array<string, mixed> $plan
     */
    private function expired(array $plan, int $days): bool
    {
        return (int) $plan['result_has_google'] === 1 && $plan['evaluated_at'] !== null && $plan['evaluated_at'] < $this->daysAgo($days);
    }

    /** NOW() - INTERVAL days DAY, as MySQL prints it. */
    private function daysAgo(int $days): string
    {
        $utc = new \DateTimeZone('UTC');
        $now = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $this->now, $utc);
        if ($now === false) {
            throw new \LogicException('TruckTables: $now is not "YYYY-MM-DD HH:MM:SS"');
        }
        return $now->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
    }
}

/**
 * Rows of the owner tables as MySQL returns them, and a populated set of tables with two organizations.
 *
 * Wherever a row stores Google content (the looked-up contact fields of a lead, the legs inside a plan's
 * context and result, the leg cache) the fixture plants SENTINEL, so that a test can prove none of it
 * leaves through the export.
 */
final class TruckDataFixtures
{
    public const ORG = '11111111-1111-4111-8111-111111111111';
    public const OTHER_ORG = '99999999-9999-4999-8999-999999999999';
    public const TRUCK = '33333333-3333-4333-8333-333333333333';
    public const OTHER_TRUCK = '44444444-4444-4444-8444-444444444444';
    public const SENTINEL = 'GOOGLE-CONTENT-7805';
    public const DATASET = 'dc-20261003-d0514a63';

    private const STAMP = '2026-10-01 08:00:00';

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed> a row of tp_trucks
     */
    public static function truck(string $org = self::ORG, string $id = self::TRUCK, array $over = []): array
    {
        return $over + [
            'id' => $id, 'organization_id' => $org, 'created_by' => null,
            'name' => 'Smoke & Ember', 'region_id' => 'dc', 'timezone' => 'America/New_York',
            'base_lat' => 39.003, 'base_lng' => -77.405, 'base_address' => 'Sterling, VA',
            'base_state' => 'VA', 'base_county_fips' => '51107',
            'avg_ticket_cents' => 1500, 'capacity_orders_per_hour' => 45.0, 'paid_crew' => 2, 'wage_cents' => 1800,
            'payroll_burden_pct' => 0.1, 'food_cost_pct' => 0.3, 'packaging_cents' => 50, 'card_fee_pct' => 0.026,
            'card_fee_fixed_cents' => 15, 'card_share' => 0.85, 'tips_include' => 0, 'tips_pct' => 0.1, 'mpg' => 9.0,
            'fuel_type' => 'gasoline', 'fuel_price_override_milli' => null, 'generator_gal_per_hour' => 0.6,
            'prep_minutes' => 45, 'setup_minutes' => 30, 'teardown_minutes' => 20, 'closeout_minutes' => 30,
            'fixed_cost_day_cents' => 0, 'fit_breakfast' => 0.3, 'fit_lunch' => 1.0, 'fit_dinner' => 1.0, 'fit_late' => 0.8,
            'avoid_tolls' => 0, 'avoid_highways' => 0, 'truck_time_factor' => 1.1,
            'licence_counties_json' => '["51107", "51059"]', 'scout_drive_minutes_limit' => 45,
            'overrides_json' => '{}', 'overrides_seeds_rev' => 1,
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed> a row of tp_spots
     */
    public static function spot(string $id, array $over = [], string $org = self::ORG): array
    {
        return $over + [
            'id' => $id, 'organization_id' => $org, 'truck_id' => $org === self::ORG ? self::TRUCK : self::OTHER_TRUCK, 'created_by' => null,
            'name' => 'Spot ' . $id, 'lat' => 38.96, 'lng' => -77.36, 'address' => '', 'county_fips' => null, 'notes' => null,
            'visibility' => 'normal', 'host_segment' => null, 'host_size' => null, 'host_size_source' => null, 'host_only_food' => 0,
            'host_place_type' => null, 'host_name' => null, 'host_contact' => null, 'host_phone' => null, 'host_website' => null,
            'place_key' => null, 'host_point_id' => null, 'google_place_id' => null,
            'fee_flat_cents' => 0, 'fee_pct' => 0.0, 'fee_min_cents' => 0, 'allowed_json' => null,
            'vectors_bin' => str_repeat("\x00", 1200), 'vec_in_region' => 1, 'vec_points_used' => 8, 'vec_excluded' => 0.0,
            'vec_region_id' => 'dc', 'vec_dataset' => self::DATASET, 'vec_seeds_rev' => 1, 'vec_at' => self::STAMP,
            'archived_at' => null, 'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed> a row of tp_plans
     */
    public static function plan(string $id, string $date, array $over = [], string $org = self::ORG): array
    {
        return $over + [
            'id' => $id, 'organization_id' => $org, 'truck_id' => $org === self::ORG ? self::TRUCK : self::OTHER_TRUCK, 'created_by' => null,
            'service_date' => $date, 'name' => '', 'treat_as' => null, 'notes' => null, 'plan_state' => 'draft',
            'result_json' => null, 'context_json' => null, 'result_has_google' => 0, 'evaluated_at' => null,
            'model_version' => null, 'seeds_revision' => null, 'dataset_version' => null,
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed> a row of tp_plan_stops
     */
    public static function stop(string $id, string $planId, int $seq, array $over = [], string $org = self::ORG): array
    {
        return $over + [
            'id' => $id, 'organization_id' => $org, 'plan_id' => $planId, 'seq' => $seq, 'stop_kind' => 'spot', 'spot_id' => null,
            'label' => '', 'lat' => null, 'lng' => null, 'address' => '', 'open_minute' => 660, 'close_minute' => 840,
            'gap_before_unpaid' => 0, 'setup_minutes' => null, 'teardown_minutes' => null,
            'fee_flat_cents' => 0, 'fee_pct' => 0.0, 'fee_min_cents' => 0,
            'ev_attendance' => null, 'ev_vendor_count' => null, 'ev_type' => null,
            'cat_headcount' => null, 'cat_price_head_cents' => null, 'cat_guarantee_cents' => null, 'cat_food_cost_cents' => null,
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed> a row of tp_service_logs
     */
    public static function log(string $id, string $date, array $over = [], string $org = self::ORG): array
    {
        return $over + [
            'id' => $id, 'organization_id' => $org, 'truck_id' => $org === self::ORG ? self::TRUCK : self::OTHER_TRUCK, 'created_by' => null,
            'log_kind' => 'spot', 'spot_id' => null, 'plan_id' => null, 'plan_stop_id' => null, 'service_date' => $date,
            'open_minute' => 660, 'close_minute' => 840, 'actual_orders' => 50, 'sales_cents' => null, 'sold_out' => 0, 'notes' => null,
            'src' => 'manual', 'external_key' => null, 'treat_as' => null,
            'weather_json' => '{"ctx": [null, {"hour": 1, "temp_f": 58, "short_forecast": "WEATHER-OF-THE-DAY"}], "ctx_next": null}',
            'predicted_raw' => null, 'pred_raw_basis' => null, 'predicted' => null, 'pred_low' => null, 'pred_high' => null,
            'pred_confidence' => null, 'pred_basis' => null, 'prediction_json' => null,
            'pred_model_version' => null, 'pred_seeds_rev' => null, 'pred_dataset' => null,
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed> a row of tp_drive_overrides: Herndon office park to the base in Sterling
     */
    public static function correction(string $id, array $over = [], string $org = self::ORG): array
    {
        return $over + [
            'id' => $id, 'organization_id' => $org, 'truck_id' => $org === self::ORG ? self::TRUCK : self::OTHER_TRUCK,
            'o_lat_e4' => 389600, 'o_lng_e4' => -773600, 'd_lat_e4' => 390030, 'd_lng_e4' => -774050,
            'override_minutes' => 14, 'toll_cents' => null, 'note' => '',
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ];
    }

    /**
     * @param array<string, mixed> $over
     * @return array<string, mixed> a row of tp_scout_leads after a lookup that matched: Google's id of the
     *         place, the outcome and the time. The table has no column for anything else of a lookup
     */
    public static function lead(string $id, string $placeKey, array $over = [], string $org = self::ORG): array
    {
        return $over + [
            'id' => $id, 'organization_id' => $org, 'truck_id' => $org === self::ORG ? self::TRUCK : self::OTHER_TRUCK, 'region_id' => 'dc',
            'place_key' => $placeKey, 'place_name' => 'Example Brewing', 'place_type' => 'taproom', 'lat' => 39.01, 'lng' => -77.41,
            'lead_state' => 'new', 'notes' => null, 'spot_id' => null, 'google_place_id' => 'ChIJexample',
            'g_lookup_state' => 'found', 'g_fetched_at' => '2026-10-02 08:00:00',
            'created_at' => self::STAMP, 'updated_at' => self::STAMP,
        ];
    }

    /**
     * @return array<string, mixed> a row of tp_drive_legs
     */
    public static function leg(int $originLatE4, string $fetchedAt): array
    {
        return [
            'o_lat_e4' => $originLatE4, 'o_lng_e4' => -773600, 'd_lat_e4' => 390030, 'd_lng_e4' => -774050, 'route_key' => 'd',
            'src' => 'google_routes', 'route_found' => 1, 'duration_s' => 600, 'distance_m' => 7805, 'toll_state' => 1, 'toll_cents' => null,
            'fetched_at' => $fetchedAt,
        ];
    }

    /**
     * The text of a stored plan result: the totals a summary reads, and a timeline with a Google leg.
     *
     * @param float $takeHome expected take-home in dollars
     */
    public static function resultJson(float $takeHome = 482.2): string
    {
        return (string) json_encode([
            'model_version' => 'tps-0.1.0',
            'seeds_revision' => 1,
            'date' => '2026-10-08',
            'timeline' => ['legs' => [['from_id' => 'base', 'to_id' => 's1', 'source' => 'google', 'distance_m' => 7805, 'note' => self::SENTINEL]]],
            'stops' => [],
            'totals' => [
                'orders' => ['value' => 99.87, 'low' => 53.77, 'high' => 155.39, 'confidence' => 'rough'],
                'take_home' => ['value' => $takeHome, 'low' => 42.35, 'high' => 1011.86, 'confidence' => 'rough'],
                'day_hours' => 11.283333333333333,
            ],
            'warnings' => [],
        ]);
    }

    /** The text of a stored plan context: the drive legs Google answered. */
    public static function contextJson(): string
    {
        return (string) json_encode([
            'legs' => [['from_id' => 'base', 'to_id' => 's1', 'source' => 'google_routes', 'distance_m' => 7805, 'duration_s' => 600, 'note' => self::SENTINEL]],
            'uses_google_legs' => true,
        ]);
    }

    /**
     * Two organizations with rows in every owner table, and a leg cache.
     *
     * The first one, as of NOW() = 2026-10-05 12:00:00:
     *
     *   spots     s1 a taproom with a host and a linked place, s2 plain, s3 archived
     *   plans     p-fresh    Thursday 2026-10-08, three stops, a current result (evaluated 2026-10-04)
     *             p-stale    its spot s2 changed after the result was computed
     *             p-expired  a result with Google legs, 35 days old
     *             p-old      a result without Google legs, 60 days old and computed by another seeds revision
     *             p-none     never evaluated, no stops
     *   services  l1 at s1 with a prediction, l2 a catering job without one
     *   corrections c1;   leads d1 looked up 3 days ago, d2 looked up 40 days ago, d3 never
     */
    public static function tables(): TruckTables
    {
        $t = new TruckTables();
        $t->now = '2026-10-05 12:00:00';

        $t->add('tp_trucks', self::truck(self::ORG, self::TRUCK, ['overrides_json' => '{"host.captive_share": 0.6, "weather.floor": 0.20000010000000001}']));
        $t->add('tp_spots', self::spot('s1', [
            'name' => 'Sterling taproom', 'lat' => 39.01, 'lng' => -77.41, 'address' => '1 Example Rd', 'county_fips' => '51107',
            'host_segment' => 'v_nightlife', 'host_size' => 120.0, 'host_size_source' => 'owner', 'host_only_food' => 1,
            'host_place_type' => 'taproom', 'host_name' => 'Example Brewing', 'host_phone' => '(703) 555-0100',
            'place_key' => 'w264230766', 'host_point_id' => 'pw264230766', 'google_place_id' => 'ChIJexample',
            'fee_pct' => 0.1, 'fee_min_cents' => 7500,
        ]));
        $t->add('tp_spots', self::spot('s2', ['name' => 'Herndon office park', 'updated_at' => '2026-10-04 08:00:00']));
        $t->add('tp_spots', self::spot('s3', ['name' => 'Old lot', 'archived_at' => '2026-09-20 10:00:00']));

        $current = ['model_version' => 'tps-0.1.0', 'seeds_revision' => 1, 'dataset_version' => self::DATASET];
        $t->add('tp_plans', self::plan('p-fresh', '2026-10-08', $current + [
            'name' => 'Thursday', 'plan_state' => 'planned', 'notes' => 'Bring the awning',
            'result_json' => self::resultJson(), 'context_json' => self::contextJson(), 'result_has_google' => 1,
            'evaluated_at' => '2026-10-04 23:50:12',
        ]));
        $t->add('tp_plan_stops', self::stop('st1', 'p-fresh', 0, ['spot_id' => 's1', 'open_minute' => 1020, 'close_minute' => 1200]));
        $t->add('tp_plan_stops', self::stop('st2', 'p-fresh', 1, [
            'stop_kind' => 'event', 'label' => 'Fall fair', 'lat' => 38.95, 'lng' => -77.35, 'address' => 'Fairgrounds',
            'open_minute' => 1230, 'close_minute' => 1350, 'fee_flat_cents' => 10000, 'fee_pct' => 0.1, 'fee_min_cents' => 15000,
            'ev_attendance' => 4000.0, 'ev_vendor_count' => 12, 'ev_type' => 'general',
        ]));
        $t->add('tp_plan_stops', self::stop('st3', 'p-fresh', 2, [
            'stop_kind' => 'catering', 'label' => 'Late shift', 'lat' => 38.9, 'lng' => -77.3, 'open_minute' => 1380, 'close_minute' => 1440,
            'gap_before_unpaid' => 1, 'cat_headcount' => 80.0, 'cat_price_head_cents' => 1400, 'cat_guarantee_cents' => 100000,
        ]));
        $t->add('tp_plans', self::plan('p-stale', '2026-10-09', $current + [
            'result_json' => self::resultJson(111.0), 'context_json' => self::contextJson(), 'result_has_google' => 1,
            'evaluated_at' => '2026-10-03 12:00:00',
        ]));
        $t->add('tp_plan_stops', self::stop('st4', 'p-stale', 0, ['spot_id' => 's2']));
        $t->add('tp_plans', self::plan('p-expired', '2026-09-03', $current + [
            'result_json' => self::resultJson(222.0), 'context_json' => self::contextJson(), 'result_has_google' => 1,
            'evaluated_at' => '2026-08-31 12:00:00',
        ]));
        $t->add('tp_plan_stops', self::stop('st5', 'p-expired', 0, ['spot_id' => 's3']));
        $t->add('tp_plans', self::plan('p-old', '2026-08-06', [
            'model_version' => 'tps-0.1.0', 'seeds_revision' => 0, 'dataset_version' => self::DATASET,
            'result_json' => self::resultJson(333.0), 'context_json' => '{"legs": [], "uses_google_legs": false}', 'result_has_google' => 0,
            'evaluated_at' => '2026-08-06 12:00:00',
        ]));
        $t->add('tp_plans', self::plan('p-none', '2026-10-12'));

        $t->add('tp_service_logs', self::log('l1', '2026-09-24', [
            'spot_id' => 's1', 'actual_orders' => 52, 'sales_cents' => 78050, 'sold_out' => 1, 'notes' => 'Rain at noon',
            'predicted_raw' => 39.384, 'pred_raw_basis' => str_repeat('a', 40), 'predicted' => 38.1, 'pred_low' => 20.1, 'pred_high' => 60.2,
            'pred_confidence' => 'rough', 'pred_basis' => 'log',
            'prediction_json' => '{"basis": "log", "plan_id": null, "holiday": null, "spread": {"sigma": 0.41}, "evidence": {"truck_weight": 0}}',
            'pred_model_version' => 'tps-0.1.0', 'pred_seeds_rev' => 1, 'pred_dataset' => self::DATASET,
            'updated_at' => '2026-09-24 21:00:00',
        ]));
        $t->add('tp_service_logs', self::log('l2', '2026-09-25', ['log_kind' => 'catering', 'actual_orders' => 80, 'updated_at' => '2026-09-25 21:00:00']));

        $t->add('tp_drive_overrides', self::correction('c1', ['toll_cents' => 375, 'note' => 'School zone']));
        $t->add('tp_scout_leads', self::lead('d1', 'w264230766', ['lead_state' => 'contacted', 'notes' => 'Called Tuesday', 'spot_id' => 's1', 'g_fetched_at' => '2026-10-02 08:00:00']));
        $t->add('tp_scout_leads', self::lead('d2', 'n4100', ['place_name' => 'Example Bar', 'place_type' => 'bar', 'lead_state' => 'hidden', 'g_fetched_at' => '2026-08-26 08:00:00']));
        $t->add('tp_scout_leads', self::lead('d3', 'w5200', [
            'place_name' => null, 'google_place_id' => null, 'g_lookup_state' => null, 'g_fetched_at' => null,
        ]));

        // The other organization: a row in every owner table, all of it marked.
        $t->add('tp_trucks', self::truck(self::OTHER_ORG, self::OTHER_TRUCK, ['name' => 'THEIR TRUCK']));
        $t->add('tp_spots', self::spot('t-s1', ['name' => 'THEIR SPOT'], self::OTHER_ORG));
        $t->add('tp_plans', self::plan('t-p1', '2026-10-08', ['name' => 'THEIR PLAN', 'result_json' => self::resultJson(999.0), 'evaluated_at' => '2026-10-04 23:50:12'], self::OTHER_ORG));
        $t->add('tp_plan_stops', self::stop('t-st1', 't-p1', 0, ['spot_id' => 't-s1', 'label' => 'THEIR STOP'], self::OTHER_ORG));
        $t->add('tp_plan_stops', self::stop('t-st2', 'p-fresh', 3, ['spot_id' => 't-s1', 'label' => 'THEIR STOP'], self::OTHER_ORG));
        $t->add('tp_service_logs', self::log('t-l1', '2026-09-24', ['spot_id' => 't-s1', 'notes' => 'THEIR LOG', 'updated_at' => '2026-10-05 11:00:00'], self::OTHER_ORG));
        $t->add('tp_drive_overrides', self::correction('t-c1', ['note' => 'THEIR CORRECTION'], self::OTHER_ORG));
        $t->add('tp_scout_leads', self::lead('t-d1', 'w264230766', ['notes' => 'THEIR LEAD', 'g_fetched_at' => '2026-10-03 08:00:00'], self::OTHER_ORG));

        // The shared leg cache: one leg past its 30 days, one inside them.
        $t->add('tp_drive_legs', self::leg(389600, '2026-09-04 11:00:00'));
        $t->add('tp_drive_legs', self::leg(389700, '2026-09-06 13:00:00'));
        return $t;
    }
}
