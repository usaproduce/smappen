<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Data;

use App\Tests\TruckPlanner\Support\LogCapture;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\ApiLedger;
use PHPUnit\Framework\TestCase;

final class ApiLedgerTest extends TestCase
{
    public function testOneRowPerCallWithAnIdOfItsOwn(): void
    {
        $db = new RecordingDatabase();
        $ledger = new ApiLedger($db);
        $ledger->record('tp_routes_matrix_ent', 625, 200, 431, null, 'originIndex,destinationIndex,status,condition,distanceMeters,duration,travelAdvisory.tollInfo');

        $call = $db->only('INSERT INTO api_cost_events');
        self::assertSame('query', $call['kind']);
        self::assertStringContainsString(
            '(id, sku, billable_units, unit_cost_usd, total_cost_usd, field_mask_hash, http_status, latency_ms, error_message)',
            $call['sql']
        );
        self::assertCount(9, $call['params']);
        self::assertSame(9, substr_count($call['sql'], '?'));

        // api_cost_events.id is CHAR(36) without a default: the first bound value is a fresh UUID.
        self::assertIsString($call['params'][0]);
        self::assertSame(36, strlen($call['params'][0]));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $call['params'][0]);

        self::assertSame('tp_routes_matrix_ent', $call['params'][1]);
        self::assertSame(625, $call['params'][2]);
        self::assertSame('0.015', $call['params'][3]);
        self::assertSame(9.375, (float) $call['params'][4]);
        self::assertIsString($call['params'][4]);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $call['params'][5]);
        self::assertSame(200, $call['params'][6]);
        self::assertSame(431, $call['params'][7]);
        self::assertNull($call['params'][8]);
    }

    public function testTwoCallsGetTwoIds(): void
    {
        $db = new RecordingDatabase();
        $ledger = new ApiLedger($db);
        $ledger->record('tp_nws_hourly', 1, 200, 80, null);
        $ledger->record('tp_nws_hourly', 1, 200, 80, null);
        $calls = $db->find('INSERT INTO api_cost_events');
        self::assertCount(2, $calls);
        self::assertNotSame($calls[0]['params'][0], $calls[1]['params'][0]);
    }

    public function testFailedCallCostsNothingAndKeepsOnlyACode(): void
    {
        $db = new RecordingDatabase();
        (new ApiLedger($db))->record('tp_routes_matrix', 0, 403, 120, 'PERMISSION_DENIED');
        $params = $db->only('INSERT INTO api_cost_events')['params'];
        self::assertSame(0, $params[2]);
        self::assertSame('0.005', $params[3]);
        self::assertSame('0', $params[4]);
        self::assertNull($params[5]);
        self::assertSame(403, $params[6]);
        self::assertSame('PERMISSION_DENIED', $params[8]);
    }

    public function testNoAnswerAtAll(): void
    {
        $db = new RecordingDatabase();
        (new ApiLedger($db))->record('tp_eia_weekly', 0, null, 5000, 'timeout');
        $params = $db->only('INSERT INTO api_cost_events')['params'];
        self::assertSame('0', $params[3]);
        self::assertNull($params[6]);
        self::assertSame('timeout', $params[8]);
    }

    public function testAKeyInAnErrorTextIsMasked(): void
    {
        $db = new RecordingDatabase();
        (new ApiLedger($db))->record('tp_distance_matrix', 0, 400, 10, 'bad request key=AIzaSyA-secret-secret-secret&x=1');
        $text = (string) $db->only('INSERT INTO api_cost_events')['params'][8];
        self::assertStringNotContainsString('AIza', $text);
        self::assertStringNotContainsString('secret', $text);
    }

    public function testAnUnknownSkuIsRecordedAtNoCost(): void
    {
        $db = new RecordingDatabase();
        (new ApiLedger($db))->record('tp_something_new', 3, 200, 1, null);
        $params = $db->only('INSERT INTO api_cost_events')['params'];
        self::assertSame('0', $params[3]);
        self::assertSame('0', $params[4]);
    }

    public function testRecordNeverThrows(): void
    {
        $db = (new RecordingDatabase())->failOn('INSERT INTO api_cost_events');
        $ledger = new ApiLedger($db);
        $lines = LogCapture::during(static function () use ($ledger): void {
            $ledger->record('tp_routes_matrix', 10, 200, 5, null);
            $ledger->record('tp_routes_matrix', 10, 200, 5, null);
        });
        self::assertCount(2, $db->find('INSERT INTO api_cost_events'));
        // One line per process at most, and never more than the fixed sentence.
        self::assertLessThanOrEqual(1, count($lines));
        foreach ($lines as $line) {
            self::assertSame('[tp] ledger insert failed', $line);
        }
    }

    public function testMaskHashIgnoresOrderAndSpaces(): void
    {
        $a = ApiLedger::maskHash('places.id,places.displayName, places.websiteUri');
        $b = ApiLedger::maskHash('places.websiteUri,places.id,places.displayName');
        self::assertSame($a, $b);
        self::assertSame(substr(hash('sha256', 'places.displayName,places.id,places.websiteUri'), 0, 16), $a);
        self::assertNotSame($a, ApiLedger::maskHash('places.id'));
        self::assertNull(ApiLedger::maskHash(null));
        self::assertNull(ApiLedger::maskHash(' , '));
    }

    public function testUnitsTodaySumsTheGivenSkusSinceTheStartOfTheDatabaseDay(): void
    {
        $db = (new RecordingDatabase())->when('FROM api_cost_events', ['units' => '1875']);
        $units = (new ApiLedger($db))->unitsToday(['tp_routes_matrix', 'tp_routes_matrix_pro', 'tp_distance_matrix']);
        self::assertSame(1875, $units);
        $call = $db->only('FROM api_cost_events');
        self::assertStringContainsString('SUM(billable_units)', $call['sql']);
        self::assertStringContainsString('sku IN (?, ?, ?)', $call['sql']);
        self::assertStringContainsString('called_at >= CURDATE()', $call['sql']);
        self::assertSame(['tp_routes_matrix', 'tp_routes_matrix_pro', 'tp_distance_matrix'], $call['params']);
    }

    public function testUnitsTodayWithoutSkusAsksNothing(): void
    {
        $db = new RecordingDatabase();
        self::assertSame(0, (new ApiLedger($db))->unitsToday([]));
        self::assertSame([], $db->calls);
    }

    public function testAnUnreadableLedgerLooksLikeASpentBudget(): void
    {
        $db = (new RecordingDatabase())->failOn('FROM api_cost_events');
        $units = null;
        LogCapture::during(static function () use ($db, &$units): void {
            $units = (new ApiLedger($db))->unitsToday(['tp_routes_matrix']);
        });
        self::assertSame(PHP_INT_MAX, $units);
    }

    public function testSpentUsdSumsTheCostOfTheGivenSkusForTheDayAndForTheMonth(): void
    {
        // MySQL hands a DECIMAL sum back as text
        $db = (new RecordingDatabase())->when('FROM api_cost_events', ['day_usd' => '1.250000', 'month_usd' => '12.375000']);
        $spent = (new ApiLedger($db))->spentUsd(['tp_routes_matrix', 'tp_places_text']);
        self::assertSame(['day' => 1.25, 'month' => 12.375], $spent);
        $call = $db->only('FROM api_cost_events');
        self::assertStringContainsString('SUM(CASE WHEN called_at >= CURDATE() THEN total_cost_usd ELSE 0 END)', $call['sql']);
        self::assertStringContainsString('SUM(total_cost_usd)', $call['sql']);
        self::assertStringContainsString('sku IN (?, ?)', $call['sql']);
        // from the first of the database's month
        self::assertStringContainsString('called_at >= DATE_SUB(CURDATE(), INTERVAL DAYOFMONTH(CURDATE()) - 1 DAY)', $call['sql']);
        self::assertSame(['tp_routes_matrix', 'tp_places_text'], $call['params']);
    }

    public function testSpentUsdWithoutSkusAsksNothing(): void
    {
        $db = new RecordingDatabase();
        self::assertSame(['day' => 0.0, 'month' => 0.0], (new ApiLedger($db))->spentUsd([]));
        self::assertSame([], $db->calls);
    }

    public function testAnUnreadableLedgerLooksLikeSpentAllowances(): void
    {
        $db = (new RecordingDatabase())->failOn('FROM api_cost_events');
        $spent = null;
        LogCapture::during(static function () use ($db, &$spent): void {
            $spent = (new ApiLedger($db))->spentUsd(['tp_routes_matrix']);
        });
        self::assertSame(['day' => INF, 'month' => INF], $spent);
    }
}
