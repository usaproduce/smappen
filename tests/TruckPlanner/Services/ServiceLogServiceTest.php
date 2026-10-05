<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Support\TpNotFound;
use PHPUnit\Framework\TestCase;

// TruckWorld and its in-memory tables live with the planning service's test.
require_once __DIR__ . '/PlanningServiceTest.php';

/**
 * ServiceLogService over the real repositories and in-memory tables, on the fixture region: what is
 * stored with a logged service, which prediction it is judged against, and what a change rebuilds.
 *
 * Today is Monday 2026-10-05 where the truck is. The office area is anchor A1 of 02_MODEL.md (60.49 orders
 * on a Thursday from 11:00 to 14:00), the taproom anchor A2 (39.38 on a Thursday evening).
 */
final class ServiceLogServiceTest extends TestCase
{
    private const ORG = TruckWorld::ORG;
    private const THURSDAY = '2026-10-01';

    private TruckWorld $world;

    /** @var array<string, mixed> */
    private array $office;

    /** @var array<string, mixed> */
    private array $taproom;

    protected function setUp(): void
    {
        $this->world = new TruckWorld();
        $this->office = $this->world->office();
        $this->taproom = $this->world->taproom();
    }

    protected function tearDown(): void
    {
        Registry::reset();
        TpConfig::replace(null);
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * @param array<string, mixed> $body
     * @return array{service: array<string, mixed>, calibration: array<string, mixed>}
     */
    private function log(array $body, ?array $A = null): array
    {
        return $this->world->logging->create(self::ORG, TruckWorld::truck(), $A ?? TruckWorld::A(), TruckWorld::USER, $body);
    }

    /**
     * @param array<string, mixed> $changes
     * @return array<string, mixed> the body of a lunch service at the office area
     */
    private function lunch(array $changes = []): array
    {
        return $changes + ['spot_id' => $this->office['id'], 'date' => self::THURSDAY, 'open_minute' => 660, 'close_minute' => 840, 'actual' => 52];
    }

    /**
     * The model's window for a spot, straight from the Estimator.
     *
     * @param array<string, mixed> $spot Spot
     * @param array<string, mixed>|null $cal
     * @param list<mixed>|null $forecast
     * @return array<string, mixed> WindowResult
     */
    private static function window(array $spot, string $date, int $open, int $close, ?array $cal = null, ?array $forecast = null, ?string $treatAs = null, ?array $A = null): array
    {
        $A ??= TruckWorld::A();
        return Estimator::windowOrders(
            $A,
            TruckWorld::truck()['profile'],
            $spot['terms'],
            $spot['vectors'][$spot['terms']['visibility']],
            $cal,
            Estimator::dayContext($A, $date, $treatAs, $forecast, null, null),
            $close > 1440 ? Estimator::dayContext($A, Estimator::addDays($date, 1), null, null, null, null) : null,
            $open,
            $close
        );
    }

    /**
     * A plan of the blueprint's two stops for a past Thursday, with the drive legs of its day sheet.
     *
     * @return array<string, mixed> Plan
     */
    private function plan(string $date = self::THURSDAY, ?string $treatAs = null): array
    {
        $base = TruckWorld::BASE;
        $this->world->legs->fixed($base, $this->office['point'], 11, 4.85);
        $this->world->legs->fixed($this->office['point'], $this->taproom['point'], 10, 4.85);
        $this->world->legs->fixed($this->taproom['point'], $base, 1, 0.2);
        $this->world->legs->fixed($this->office['point'], $base, 11, 4.85);
        $this->world->legs->fixed($base, $this->taproom['point'], 1, 0.2);
        return $this->world->planning->create(self::ORG, TruckWorld::truck(), TruckWorld::A(), TruckWorld::USER, [
            'date' => $date,
            'treat_as' => $treatAs,
            'stops' => [
                ['kind' => 'spot', 'spot_id' => $this->office['id'], 'open_minute' => 660, 'close_minute' => 840],
                ['kind' => 'spot', 'spot_id' => $this->taproom['id'], 'open_minute' => 1020, 'close_minute' => 1200],
            ],
        ]);
    }

    private static function assertInvalid(string $message, ?string $field, ?string $code, callable $fn): void
    {
        try {
            $fn();
            self::fail('no validation error, expected: ' . $message);
        } catch (TpInvalid $e) {
            self::assertSame($message, $e->getMessage());
            self::assertSame($field, $e->field());
            self::assertSame($code, $e->rule());
        }
    }

    // ------------------------------------------------------------------------------------ what a log stores

    public function testALogStoresThePredictionItIsJudgedAgainst(): void
    {
        $A = TruckWorld::A();
        $answer = $this->log($this->lunch(['sales' => 801.505, 'notes' => ' Rainy start ']));
        $service = $answer['service'];

        self::assertSame(['service', 'calibration'], array_keys($answer));
        self::assertSame(
            ['id', 'kind', 'spot_id', 'plan_id', 'plan_stop_id', 'date', 'open_minute', 'close_minute', 'actual', 'sales', 'sold_out', 'notes',
                'source', 'external_key', 'treat_as', 'prediction', 'created_at', 'updated_at'],
            array_keys($service)
        );
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $service['id']);
        self::assertSame(
            ['kind' => 'spot', 'spot_id' => $this->office['id'], 'plan_id' => null, 'plan_stop_id' => null, 'date' => self::THURSDAY,
                'open_minute' => 660, 'close_minute' => 840, 'actual' => 52, 'sales' => 801.51, 'sold_out' => false, 'notes' => 'Rainy start',
                'source' => 'manual', 'external_key' => null, 'treat_as' => null],
            array_slice($service, 1, 14, true)
        );

        // The four numbers, the label and where they came from.
        $window = self::window($this->office, self::THURSDAY, 660, 840);
        $prediction = $service['prediction'];
        self::assertSame(
            ['predicted_raw', 'predicted', 'low', 'high', 'confidence', 'basis', 'model_version', 'seeds_revision', 'dataset_version', 'detail'],
            array_keys($prediction)
        );
        self::assertSame($window['orders']['value'], $prediction['predicted_raw']);
        self::assertSame($window['orders']['value'], $prediction['predicted'], 'no service was logged before this one: nothing to calibrate with');
        self::assertSame($window['orders']['low'], $prediction['low']);
        self::assertSame($window['orders']['high'], $prediction['high']);
        self::assertSame('rough', $prediction['confidence']);
        self::assertSame('log', $prediction['basis']);
        self::assertEqualsWithDelta(60.49, $prediction['predicted_raw'], 0.005);
        self::assertEqualsWithDelta(33.01, $prediction['low'], 0.005);
        self::assertEqualsWithDelta(93.19, $prediction['high'], 0.005);
        self::assertSame('tps-0.1.0', $prediction['model_version']);
        self::assertSame(Seeds::revision(), $prediction['seeds_revision']);
        self::assertSame(FixtureRegion::VERSION, $prediction['dataset_version']);
        self::assertSame(
            [
                'basis' => 'log',
                'plan_id' => null,
                'ctx_date_types' => Estimator::dayContext($A, self::THURSDAY, null, null, null, null)['day_type'],
                'holiday' => null,
                'terms' => $this->office['terms'],
                'spread' => $window['spread'],
                'evidence' => $window['evidence'],
            ],
            $prediction['detail']
        );

        // The row.
        $row = $this->world->db->logs[$service['id']];
        self::assertSame(self::ORG, $row['organization_id']);
        self::assertSame(TruckWorld::TRUCK, $row['truck_id']);
        self::assertSame(TruckWorld::USER, $row['created_by']);
        self::assertSame(80151, $row['sales_cents']);
        self::assertSame(52, $row['actual_orders']);
        self::assertSame(0, $row['sold_out']);
        self::assertSame('manual', $row['src']);
        self::assertNull($row['external_key']);
        self::assertNull($row['weather_json'], 'a past date has no forecast');
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $row['pred_raw_basis']);
        self::assertSame((string) json_encode($window['orders']['value']), $row['predicted_raw'], 'a float is bound as its shortest exact text');
        self::assertSame($prediction['detail'], json_decode($row['prediction_json'], true));

        // The answer carries the calibration this service leads to, as of today.
        $entry = [
            'service_id' => $service['id'], 'kind' => 'spot', 'spot_id' => $this->office['id'], 'date' => self::THURSDAY, 'open_minute' => 660,
            'close_minute' => 840, 'actual' => 52.0, 'sold_out' => false, 'predicted_raw' => $prediction['predicted_raw'],
            'predicted' => $prediction['predicted'], 'low' => $prediction['low'], 'high' => $prediction['high'],
        ];
        self::assertSame(Estimator::calibrate($A, [$entry], '2026-10-05'), $answer['calibration']);
        self::assertSame(1, $answer['calibration']['truck_n']);
        self::assertLessThan(1.0, $answer['calibration']['truck_factor']);
        self::assertSame([$this->office['id']], array_keys($answer['calibration']['spots']));

        self::assertSame($service, $this->world->logging->get(self::ORG, $service['id']));
        self::assertSame([[self::THURSDAY, 1, [self::THURSDAY => null]]], $this->world->contexts->asked);
    }

    public function testAServiceIsCalibratedOnlyWithTheServicesBeforeIt(): void
    {
        $A = TruckWorld::A();
        $first = $this->log($this->lunch(['actual' => 40]))['service'];
        // Logged later, dated earlier: nothing was before it.
        $earlier = $this->log($this->lunch(['date' => '2026-09-24', 'actual' => 45]))['service'];
        self::assertSame($earlier['prediction']['predicted_raw'], $earlier['prediction']['predicted']);
        // The same day at the taproom: the lunch of that day does not count for it either.
        $sameDay = $this->log(['spot_id' => $this->taproom['id'], 'date' => self::THURSDAY, 'open_minute' => 1020, 'close_minute' => 1200, 'actual' => 30])['service'];
        self::assertNotSame($sameDay['prediction']['predicted_raw'], $sameDay['prediction']['predicted'], 'the service of 09-24 is before it');

        // A service after both lunches is calibrated with exactly those two, as of its own date.
        $next = $this->log($this->lunch(['date' => '2026-10-02', 'actual' => 50]))['service'];
        $before = [];
        foreach ([$earlier, $first, $sameDay] as $service) {
            $before[] = [
                'service_id' => $service['id'], 'kind' => 'spot', 'spot_id' => $service['spot_id'], 'date' => $service['date'],
                'open_minute' => $service['open_minute'], 'close_minute' => $service['close_minute'], 'actual' => (float) $service['actual'],
                'sold_out' => false, 'predicted_raw' => $service['prediction']['predicted_raw'], 'predicted' => $service['prediction']['predicted'],
                'low' => $service['prediction']['low'], 'high' => $service['prediction']['high'],
            ];
        }
        $cal = Estimator::calibrate($A, $before, '2026-10-02');
        $window = self::window($this->office, '2026-10-02', 660, 840, $cal);
        self::assertSame($window['orders']['value'], $next['prediction']['predicted']);
        self::assertSame($window['orders']['low'], $next['prediction']['low']);
        self::assertSame($window['orders']['high'], $next['prediction']['high']);
        self::assertSame($window['evidence'], $next['prediction']['detail']['evidence']);
        self::assertSame(self::window($this->office, '2026-10-02', 660, 840)['orders']['value'], $next['prediction']['predicted_raw']);
        self::assertLessThan($next['prediction']['predicted_raw'], $next['prediction']['predicted'], 'the truck sold less than estimated so far');
        self::assertGreaterThan(0.0, $next['prediction']['detail']['evidence']['spot_weight']);
    }

    public function testTodayIsTheTrucksDayNotTheServers(): void
    {
        // 03:30 UTC on Tuesday is still Monday evening in New York.
        $this->world->clock->set('2026-10-06 03:30:00');
        self::assertInvalid('date must not be in the future', 'date', null, fn () => $this->log($this->lunch(['date' => '2026-10-06'])));
        $today = $this->log($this->lunch(['date' => '2026-10-05']));
        self::assertSame('2026-10-05', $today['calibration']['as_of']);
        self::assertSame([], $this->world->db->writes('UPDATE tp_service_logs'));
    }

    public function testAWindowPastMidnightReadsTheNextDateToo(): void
    {
        $this->world->contexts->forecast = ['2026-10-02' => FixtureContexts::day(), '2026-10-03' => FixtureContexts::day([0 => ['temp_f' => 20.0]])];
        $service = $this->log(['spot_id' => $this->taproom['id'], 'date' => '2026-10-02', 'open_minute' => 1260, 'close_minute' => 1500, 'actual' => 20])['service'];
        self::assertSame([['2026-10-02', 2, ['2026-10-02' => null]]], $this->world->contexts->asked);
        $stored = json_decode((string) $this->world->db->logs[$service['id']]['weather_json'], true);
        self::assertSame(['ctx', 'ctx_next'], array_keys($stored));
        self::assertSame(FixtureContexts::day(), $stored['ctx']);
        self::assertSame(20.0, $stored['ctx_next'][0]['temp_f']);
        self::assertGreaterThan(0.0, $service['prediction']['predicted_raw']);
        self::assertSame(240, $service['close_minute'] - $service['open_minute']);
    }

    public function testASpotWithoutVectorsGetsNoPrediction(): void
    {
        $this->world->db->spots->rows[$this->office['id']]['vectors_bin'] = null;
        $answer = $this->log($this->lunch());
        self::assertNull($answer['service']['prediction']);
        self::assertSame(52, $answer['service']['actual']);
        self::assertSame(0, $answer['calibration']['truck_n'], 'a log without a prediction is not judged');
        self::assertNull($this->world->db->logs[$answer['service']['id']]['predicted_raw']);
    }

    // ------------------------------------------------------------------------------------ the plan-linked basis

    public function testALinkedLogTakesThePlansFiguresWhenTheWindowIsThePlannedOne(): void
    {
        // The day was planned with a wet forecast for lunch. By the time the service is logged the forecast is gone.
        $wet = FixtureContexts::day([
            11 => ['precip_prob' => 70.0, 'short_forecast' => 'Rain Showers Likely'],
            12 => ['precip_prob' => 80.0, 'short_forecast' => 'Rain Showers'],
            13 => ['precip_prob' => 40.0, 'short_forecast' => 'Chance Rain Showers'],
        ]);
        $this->world->contexts->forecast = [self::THURSDAY => $wet];
        $plan = $this->plan(self::THURSDAY, 'fri');
        $this->world->contexts->forecast = [];
        $this->world->contexts->asked = [];
        [$lunch, $evening] = $plan['stops'];
        $planned = $plan['result']['stops'][0];
        self::assertSame(660, $plan['result']['timeline']['stops'][0]['effective_open']);

        $service = $this->log($this->lunch(['plan_stop_id' => $lunch['id']]))['service'];
        self::assertSame($plan['id'], $service['plan_id']);
        self::assertSame($lunch['id'], $service['plan_stop_id']);
        self::assertSame('fri', $service['treat_as'], 'the day type of the plan');
        $prediction = $service['prediction'];
        self::assertSame('plan', $prediction['basis']);
        self::assertSame($planned['orders']['value'], $prediction['predicted']);
        self::assertSame($planned['orders']['low'], $prediction['low']);
        self::assertSame($planned['orders']['high'], $prediction['high']);
        self::assertSame($planned['orders']['confidence'], $prediction['confidence']);
        self::assertSame(
            ['basis' => 'plan', 'plan_id' => $plan['id'], 'ctx_date_types' => $plan['context']['ctx']['day_type'], 'holiday' => null,
                'terms' => $this->office['terms'], 'spread' => $planned['window']['spread'], 'evidence' => $planned['window']['evidence']],
            $prediction['detail']
        );
        self::assertSame([], $this->world->contexts->asked, 'the contexts are the snapshot\'s: none is asked for');

        // The weather the owner saw is kept with the log, and the raw prediction is computed with it.
        $row = $this->world->db->logs[$service['id']];
        self::assertSame(['ctx' => $wet, 'ctx_next' => null], json_decode((string) $row['weather_json'], true));
        $wetWindow = self::window($this->office, self::THURSDAY, 660, 840, null, $wet, 'fri');
        self::assertSame($wetWindow['orders']['value'], $prediction['predicted_raw']);
        self::assertSame($planned['orders']['value'], $prediction['predicted_raw'], 'no service before it: the plan had nothing to calibrate with');
        self::assertLessThan(self::window($this->office, self::THURSDAY, 660, 840, null, null, 'fri')['orders']['value'], $prediction['predicted_raw'], 'rain costs orders');

        // The evening stop, logged with the hours that were really served: not the planned window, so the
        // estimate is the log's own, with what is known today (no forecast any more).
        $late = $this->log([
            'spot_id' => $this->taproom['id'], 'date' => self::THURSDAY, 'open_minute' => 1050, 'close_minute' => 1200, 'actual' => 31, 'plan_stop_id' => $evening['id'],
        ])['service'];
        self::assertSame('log', $late['prediction']['basis']);
        self::assertSame($plan['id'], $late['plan_id'], 'the log is still the planned stop\'s');
        self::assertNull($late['prediction']['detail']['plan_id']);
        self::assertSame('fri', $late['treat_as']);
        self::assertNull($this->world->db->logs[$late['id']]['weather_json']);
        self::assertSame([[self::THURSDAY, 1, [self::THURSDAY => 'fri']]], $this->world->contexts->asked);
        self::assertSame(self::window($this->taproom, self::THURSDAY, 1050, 1200, null, null, 'fri')['orders']['value'], $late['prediction']['predicted_raw']);
    }

    public function testThePlansFiguresAreNotTakenWhenTheLogIsOfSomethingElse(): void
    {
        $plan = $this->plan();
        [$lunch, $evening] = $plan['stops'];
        $basis = fn (array $body): string => $this->log($body)['service']['prediction']['basis'];

        // Another spot than the stop's.
        self::assertSame('log', $basis(['spot_id' => $this->taproom['id'], 'date' => self::THURSDAY, 'open_minute' => 660, 'close_minute' => 840, 'actual' => 5, 'plan_stop_id' => $lunch['id']]));
        // Another date than the plan's.
        self::assertSame('log', $basis($this->lunch(['date' => '2026-09-24', 'plan_stop_id' => $lunch['id']])));
        // Another window than the planned one, by a minute.
        self::assertSame('log', $basis($this->lunch(['date' => self::THURSDAY, 'open_minute' => 660, 'close_minute' => 841, 'plan_stop_id' => $lunch['id']])));
        // Another day type than the plan was evaluated with.
        $typed = $this->log(['spot_id' => $this->taproom['id'], 'date' => self::THURSDAY, 'open_minute' => 1020, 'close_minute' => 1200, 'actual' => 30, 'plan_stop_id' => $evening['id'], 'treat_as' => 'sat'])['service'];
        self::assertSame('log', $typed['prediction']['basis']);
        self::assertSame('sat', $typed['treat_as']);
        // The planned stop itself.
        self::assertSame('plan', $basis($this->lunch(['plan_stop_id' => $lunch['id'], 'treat_as' => null])));
    }

    public function testAStaleSnapshotStillCountsAndAnExpiredOrMissingOneDoesNot(): void
    {
        $truck = TruckWorld::truck();
        $plan = $this->plan();
        $lunch = $plan['stops'][0];
        $other = $this->plan('2026-09-24');
        $otherLunch = $other['stops'][0];
        $third = $this->plan('2026-09-17');

        // Stale: the taproom's terms changed after the day was saved. It is what the owner was shown.
        $this->world->db->advance(600);
        $this->world->spotService->update(self::ORG, $truck, $this->taproom['id'], ['terms' => ['fee_pct' => 0.1]]);
        self::assertSame('stale', $this->world->planning->get(self::ORG, $truck, TruckWorld::A(), $plan['id'])['result_state']);
        $onStale = $this->log($this->lunch(['plan_stop_id' => $lunch['id']]))['service'];
        self::assertSame('plan', $onStale['prediction']['basis']);
        self::assertSame($plan['result']['stops'][0]['orders']['value'], $onStale['prediction']['predicted']);

        // Expired: the snapshot holds Google legs and is 31 days old. The log falls back to its own estimate.
        $this->world->clock->set('2026-11-05 17:00:00');
        $this->world->db->advanceDays(31);
        self::assertSame('expired', $this->world->planning->get(self::ORG, $truck, TruckWorld::A(), $other['id'])['result_state']);
        $onExpired = $this->log($this->lunch(['date' => '2026-09-24', 'plan_stop_id' => $otherLunch['id']]))['service'];
        self::assertSame('log', $onExpired['prediction']['basis']);
        self::assertSame($other['id'], $onExpired['plan_id']);

        // Emptied by a listing: the same.
        $this->world->planning->list(self::ORG, $truck, TruckWorld::A(), ['from' => '2026-09-17', 'to' => '2026-09-17']);
        self::assertNull($this->world->db->plans[$third['id']]['result_json']);
        $onNone = $this->log($this->lunch(['date' => '2026-09-17', 'plan_stop_id' => $third['stops'][0]['id']]))['service'];
        self::assertSame('log', $onNone['prediction']['basis']);
    }

    // ------------------------------------------------------------------------------------ events and catering

    public function testEventAndCateringLogsAreKeptButNeverJudged(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $plan = $this->world->planning->create(self::ORG, $truck, $A, null, ['date' => self::THURSDAY, 'stops' => [
            ['kind' => 'event', 'point' => FixtureRegion::OFFICE, 'open_minute' => 660, 'close_minute' => 900, 'event' => ['attendance' => 2000, 'vendors' => 6, 'event_type' => 'general']],
            ['kind' => 'catering', 'point' => FixtureRegion::TAPROOM, 'open_minute' => 1020, 'close_minute' => 1140, 'catering' => ['headcount' => 80, 'price_per_head' => 14]],
        ]]);
        [$event, $catering] = $plan['stops'];

        // Not linked: nothing to show.
        $loose = $this->log(['kind' => 'event', 'date' => self::THURSDAY, 'open_minute' => 660, 'close_minute' => 900, 'actual' => 75, 'spot_id' => $this->office['id']])['service'];
        self::assertSame('event', $loose['kind']);
        self::assertNull($loose['spot_id'], 'an event log is of no spot');
        self::assertNull($loose['prediction']);

        // Linked: the plan's figures, for display. There is no raw prediction.
        $linked = $this->log(['kind' => 'event', 'date' => self::THURSDAY, 'open_minute' => 690, 'close_minute' => 900, 'actual' => 64, 'plan_stop_id' => $event['id']]);
        $prediction = $linked['service']['prediction'];
        self::assertNull($prediction['predicted_raw']);
        self::assertSame($plan['result']['stops'][0]['orders']['value'], $prediction['predicted']);
        self::assertSame($plan['result']['stops'][0]['orders']['low'], $prediction['low']);
        self::assertSame($plan['result']['stops'][0]['orders']['high'], $prediction['high']);
        self::assertSame('very_rough', $prediction['confidence']);
        self::assertSame('plan', $prediction['basis']);
        self::assertSame($plan['id'], $prediction['detail']['plan_id']);
        self::assertNull($prediction['detail']['terms']);
        self::assertSame($plan['result']['stops'][0]['event']['spread'], $prediction['detail']['spread']);
        self::assertNull($this->world->db->logs[$linked['service']['id']]['pred_raw_basis']);

        $contract = $this->log(['kind' => 'catering', 'date' => self::THURSDAY, 'open_minute' => 1020, 'close_minute' => 1140, 'actual' => 80, 'plan_stop_id' => $catering['id']])['service'];
        self::assertSame(80.0, $contract['prediction']['predicted']);
        self::assertSame('fixed', $contract['prediction']['confidence']);
        // A link to a stop of another kind shows nothing.
        self::assertNull($this->log(['kind' => 'catering', 'date' => self::THURSDAY, 'open_minute' => 600, 'close_minute' => 630, 'actual' => 10, 'plan_stop_id' => $event['id']])['service']['prediction']);

        // None of the four enters calibration or the accuracy report, and two events at one time are no duplicate.
        self::assertSame(0, $linked['calibration']['truck_n']);
        $this->log(['kind' => 'event', 'date' => self::THURSDAY, 'open_minute' => 660, 'close_minute' => 900, 'actual' => 75]);
        $summary = $this->world->calibration->summary(self::ORG, $truck, $A);
        self::assertSame(5, $summary['log_count']);
        self::assertSame(0, $summary['eligible_count']);
        $accuracy = $this->world->calibration->accuracy(self::ORG, $truck, $A, null, null);
        self::assertSame(0, $accuracy['accuracy']['n_total']);
        self::assertSame(5, $accuracy['unscored_without_prediction']);
    }

    // ------------------------------------------------------------------------------------ duplicates

    public function testASecondLogForOneSpotAndTimeIsAConflict(): void
    {
        $first = $this->log($this->lunch())['service'];
        try {
            $this->log($this->lunch(['actual' => 60]));
            self::fail('the same service was logged twice');
        } catch (TpConflict $e) {
            self::assertSame('A service is already logged for this spot and time', $e->getMessage());
        }
        self::assertCount(1, $this->world->db->logs);

        // Another window, another date and another spot are other services.
        $later = $this->log($this->lunch(['open_minute' => 661]))['service'];
        $this->log($this->lunch(['date' => '2026-09-30']));
        $this->log($this->lunch(['spot_id' => $this->taproom['id']]));
        self::assertCount(4, $this->world->db->logs);

        // A change may not create the duplicate either; a change that keeps the time is fine.
        try {
            $this->world->logging->update(self::ORG, TruckWorld::truck(), TruckWorld::A(), $later['id'], ['open_minute' => 660]);
            self::fail('a change made a duplicate');
        } catch (TpConflict $e) {
            self::assertSame('A service is already logged for this spot and time', $e->getMessage());
        }
        self::assertSame(661, $this->world->db->logs[$later['id']]['open_minute']);
        $same = $this->world->logging->update(self::ORG, TruckWorld::truck(), TruckWorld::A(), $first['id'], ['open_minute' => 660, 'actual' => 55])['service'];
        self::assertSame(55, $same['actual']);

        // The other tenant's services are its own.
        $theirTruck = TruckWorld::truck(['id' => TruckWorld::OTHER_TRUCK]);
        $theirSpot = $this->world->spot(['name' => 'Theirs', 'point' => FixtureRegion::OFFICE], TruckWorld::OTHER_ORG, $theirTruck);
        $theirs = $this->world->logging->create(TruckWorld::OTHER_ORG, $theirTruck, TruckWorld::A(), null, ['spot_id' => $theirSpot['id']] + $this->lunch());
        self::assertSame(1, $theirs['calibration']['truck_n'], 'only its own service counts for it');
    }

    // ------------------------------------------------------------------------------------ changes

    public function testAChangeRebuildsThePredictionOnlyWhenItsInputsChange(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $this->log($this->lunch(['date' => '2026-09-24', 'actual' => 40]));
        $service = $this->log($this->lunch(['notes' => 'first']))['service'];
        $row = $this->world->db->logs[$service['id']];
        $this->world->db->advance(60);

        // The count, the sales, the flag and the notes: the prediction is history and stays.
        $answer = $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['actual' => 61, 'sales' => 915, 'sold_out' => true, 'notes' => null]);
        self::assertSame(['service', 'calibration'], array_keys($answer));
        $changed = $answer['service'];
        self::assertSame(61, $changed['actual']);
        self::assertSame(915.0, $changed['sales']);
        self::assertTrue($changed['sold_out']);
        self::assertNull($changed['notes']);
        self::assertSame($service['prediction'], $changed['prediction']);
        self::assertSame($row['pred_raw_basis'], $this->world->db->logs[$service['id']]['pred_raw_basis']);
        self::assertNotSame($service['updated_at'], $changed['updated_at']);
        self::assertSame($service['created_at'], $changed['created_at']);
        self::assertSame(2, $answer['calibration']['truck_n']);

        // A body that repeats what is stored changes nothing, not even the time stamp.
        $this->world->db->advance(60);
        $same = $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['actual' => 61, 'date' => self::THURSDAY, 'spot_id' => $this->office['id']])['service'];
        self::assertSame($changed, $same);

        // The window: another service to estimate.
        $shorter = $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['close_minute' => 780])['service'];
        self::assertSame(780, $shorter['close_minute']);
        self::assertSame(self::window($this->office, self::THURSDAY, 660, 780)['orders']['value'], $shorter['prediction']['predicted_raw']);
        self::assertLessThan($service['prediction']['predicted'], $shorter['prediction']['predicted']);
        self::assertNotSame($row['pred_raw_basis'], $this->world->db->logs[$service['id']]['pred_raw_basis']);
        self::assertNotSame($shorter['prediction']['predicted_raw'], $shorter['prediction']['predicted'], 'calibrated with the service of 09-24, never with itself');

        // The date, the spot and the day type do the same.
        $moved = $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['date' => '2026-09-22', 'close_minute' => 840])['service'];
        self::assertSame($moved['prediction']['predicted_raw'], $moved['prediction']['predicted'], 'nothing is before 09-22');
        $elsewhere = $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['spot_id' => $this->taproom['id'], 'treat_as' => 'sat'])['service'];
        self::assertSame($this->taproom['id'], $elsewhere['spot_id']);
        self::assertSame('sat', $elsewhere['treat_as']);
        self::assertSame(self::window($this->taproom, '2026-09-22', 660, 840, null, null, 'sat')['orders']['value'], $elsewhere['prediction']['predicted_raw']);
        self::assertSame($this->taproom['terms'], $elsewhere['prediction']['detail']['terms']);

        self::assertInvalid('Nothing to update', null, 'V12', fn () => $this->world->logging->update(self::ORG, $truck, $A, $service['id'], []));
        self::assertInvalid('Nothing to update', null, 'V12', fn () => $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['id' => 'x']));
        self::assertInvalid('close_minute must be after open_minute', 'close_minute', null, fn () => $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['open_minute' => 900]));
        self::assertInvalid('date must not be in the future', 'date', null, fn () => $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['date' => '2026-10-06']));
        self::assertInvalid('actual is required', 'actual', 'V1', fn () => $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['actual' => null]));
    }

    public function testAChangeOfTheLinkRebuildsThePrediction(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $plan = $this->plan();
        $lunch = $plan['stops'][0];
        $this->log($this->lunch(['date' => '2026-09-24', 'actual' => 30]));
        $service = $this->log($this->lunch())['service'];
        self::assertSame('log', $service['prediction']['basis']);
        self::assertNotSame($plan['result']['stops'][0]['orders']['value'], $service['prediction']['predicted'], 'the plan was saved before any service was logged');

        $linked = $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['plan_stop_id' => $lunch['id']])['service'];
        self::assertSame('plan', $linked['prediction']['basis']);
        self::assertSame($plan['id'], $linked['plan_id']);
        self::assertSame($plan['result']['stops'][0]['orders']['value'], $linked['prediction']['predicted']);

        // A change of the count keeps the plan's figures; unlinking goes back to the log's own estimate.
        $counted = $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['actual' => 58])['service'];
        self::assertSame($linked['prediction'], $counted['prediction']);
        $unlinked = $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['plan_stop_id' => null])['service'];
        self::assertNull($unlinked['plan_id']);
        self::assertNull($unlinked['plan_stop_id']);
        self::assertSame('log', $unlinked['prediction']['basis']);
        self::assertSame($service['prediction']['predicted'], $unlinked['prediction']['predicted']);
    }

    public function testASpotLogCanBecomeAnEventLogAndBack(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $service = $this->log($this->lunch())['service'];
        $event = $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['kind' => 'event'])['service'];
        self::assertSame('event', $event['kind']);
        self::assertNull($event['spot_id']);
        self::assertNull($event['prediction']);
        self::assertInvalid('spot_id is required', 'spot_id', 'V1', fn () => $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['kind' => 'spot']));
        $back = $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['kind' => 'spot', 'spot_id' => $this->office['id']])['service'];
        self::assertSame($service['prediction'], $back['prediction']);
    }

    public function testDeleteAnswersTheCalibrationWithoutTheService(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $keep = $this->log($this->lunch(['date' => '2026-09-24', 'actual' => 40]))['service'];
        $gone = $this->log($this->lunch(['actual' => 90]));
        self::assertSame(2, $gone['calibration']['truck_n']);

        $answer = $this->world->logging->delete(self::ORG, $truck, $A, $gone['service']['id']);
        self::assertSame(['id', 'deleted', 'calibration'], array_keys($answer));
        self::assertSame($gone['service']['id'], $answer['id']);
        self::assertTrue($answer['deleted']);
        self::assertSame(1, $answer['calibration']['truck_n']);
        self::assertSame($this->world->calibration->state(self::ORG, $truck, $A, '2026-10-05'), $answer['calibration']);
        self::assertSame([$keep['id']], array_keys($this->world->db->logs));

        $this->expectException(TpNotFound::class);
        $this->expectExceptionMessage('Service not found');
        $this->world->logging->delete(self::ORG, $truck, $A, $gone['service']['id']);
    }

    public function testADeletedServiceStampsTheTruckSoThatPlansComputedWithItAreStale(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $service = $this->log($this->lunch())['service'];
        $this->world->logging->update(self::ORG, $truck, $A, $service['id'], ['actual' => 70]);
        self::assertSame([], $this->world->truckDb->calls, 'a log that is written or changed carries its own time stamp');

        $this->world->logging->delete(self::ORG, $truck, $A, $service['id']);
        $stamp = $this->world->truckDb->only('UPDATE tp_trucks SET updated_at = NOW() WHERE id = ? AND organization_id = ?');
        self::assertSame([TruckWorld::TRUCK, self::ORG], $stamp['params']);

        // a refused deletion stamps nothing
        try {
            $this->world->logging->delete(self::ORG, $truck, $A, $service['id']);
            self::fail('a service was deleted twice');
        } catch (TpNotFound $e) {
            self::assertCount(1, $this->world->truckDb->calls);
        }
    }

    // ------------------------------------------------------------------------------------ the list

    public function testTheListIsNewestFirstWithinARange(): void
    {
        $truck = TruckWorld::truck();
        $a = $this->log($this->lunch(['date' => '2026-09-24']))['service'];
        $b = $this->log($this->lunch())['service'];
        $c = $this->log(['spot_id' => $this->taproom['id'], 'date' => self::THURSDAY, 'open_minute' => 1020, 'close_minute' => 1200, 'actual' => 30])['service'];
        $old = $this->log($this->lunch(['date' => '2026-06-01']))['service'];
        [$first, $second] = strcmp($b['id'], $c['id']) < 0 ? [$b, $c] : [$c, $b];

        // Default: the last 90 days up to today.
        $rows = $this->world->logging->list(self::ORG, $truck, []);
        self::assertSame([$first['id'], $second['id'], $a['id']], array_column($rows, 'id'), 'newest date first, then by id');
        self::assertSame($first, $rows[0]);

        self::assertSame([$a['id']], array_column($this->world->logging->list(self::ORG, $truck, ['from' => '2026-09-24', 'to' => '2026-09-24']), 'id'));
        self::assertSame([$c['id']], array_column($this->world->logging->list(self::ORG, $truck, ['spot_id' => $this->taproom['id']]), 'id'));
        self::assertSame([], $this->world->logging->list(self::ORG, $truck, ['spot_id' => '00000000-0000-4000-8000-000000000000']));
        self::assertCount(4, $this->world->logging->list(self::ORG, $truck, ['from' => '2026-06-01']));
        self::assertSame([$old['id']], array_column($this->world->logging->list(self::ORG, $truck, ['from' => '2024-10-06', 'to' => '2026-06-30']), 'id'), '730 dates, both ends counted');

        self::assertInvalid('The date range must be at most 730 days', null, null, fn () => $this->world->logging->list(self::ORG, $truck, ['from' => '2024-10-05']));
        self::assertInvalid('to must not be before from', 'to', null, fn () => $this->world->logging->list(self::ORG, $truck, ['from' => '2026-10-02', 'to' => '2026-10-01']));
        self::assertInvalid('to must be a date in the form YYYY-MM-DD', 'to', 'V7', fn () => $this->world->logging->list(self::ORG, $truck, ['to' => 'yesterday']));
        self::assertSame([], $this->world->logging->list(TruckWorld::OTHER_ORG, TruckWorld::truck(['id' => TruckWorld::OTHER_TRUCK]), []));
    }

    // ------------------------------------------------------------------------------------ tenants

    public function testAnotherTenantsServiceIsNotFound(): void
    {
        $service = $this->log($this->lunch())['service'];
        $plan = $this->plan('2026-09-24');
        $theirTruck = TruckWorld::truck(['id' => TruckWorld::OTHER_TRUCK]);
        $A = TruckWorld::A();
        $before = $this->world->db->logs;

        foreach ([
            fn () => $this->world->logging->get(TruckWorld::OTHER_ORG, $service['id']),
            fn () => $this->world->logging->update(TruckWorld::OTHER_ORG, $theirTruck, $A, $service['id'], ['actual' => 1]),
            fn () => $this->world->logging->delete(TruckWorld::OTHER_ORG, $theirTruck, $A, $service['id']),
            fn () => $this->world->logging->get(self::ORG, '00000000-0000-4000-8000-000000000000'),
            fn () => $this->world->logging->get(self::ORG, ''),
        ] as $call) {
            try {
                $call();
                self::fail('a service of another organization was reached');
            } catch (TpNotFound $e) {
                self::assertSame('Service not found', $e->getMessage());
            }
        }
        // Its spots and its planned stops are not there for another organization.
        $create = fn (array $body) => fn () => $this->world->logging->create(TruckWorld::OTHER_ORG, $theirTruck, $A, null, $body);
        self::assertInvalid('spot_id was not found', 'spot_id', 'V11', $create($this->lunch()));
        self::assertInvalid(
            'plan_stop_id was not found',
            'plan_stop_id',
            'V11',
            $create(['kind' => 'event', 'date' => self::THURSDAY, 'open_minute' => 600, 'close_minute' => 660, 'actual' => 1, 'plan_stop_id' => $plan['stops'][0]['id']])
        );
        self::assertSame($before, $this->world->db->logs);
    }

    // ------------------------------------------------------------------------------------ validation

    public function testABodyIsValidatedFieldByField(): void
    {
        $create = fn (array $body) => fn () => $this->log($body);
        $without = function (string $key): array {
            $body = $this->lunch();
            unset($body[$key]);
            return $body;
        };

        self::assertInvalid('kind must be one of: spot, event, catering', 'kind', 'V4', $create($this->lunch(['kind' => 'market'])));
        self::assertInvalid('spot_id is required', 'spot_id', 'V1', $create($without('spot_id')));
        self::assertInvalid('spot_id must be text of at most 36 characters', 'spot_id', 'V5', $create($this->lunch(['spot_id' => 17])));
        self::assertInvalid('spot_id was not found', 'spot_id', 'V11', $create($this->lunch(['spot_id' => '00000000-0000-4000-8000-000000000000'])));
        self::assertInvalid('date is required', 'date', 'V1', $create($without('date')));
        self::assertInvalid('date must be a date in the form YYYY-MM-DD', 'date', 'V7', $create($this->lunch(['date' => '2026-9-24'])));
        self::assertInvalid('date must not be in the future', 'date', null, $create($this->lunch(['date' => '2026-10-06'])));
        self::assertInvalid('open_minute is required', 'open_minute', 'V1', $create($without('open_minute')));
        self::assertInvalid('open_minute must be a whole number between 0 and 2880', 'open_minute', 'V3', $create($this->lunch(['open_minute' => -1])));
        self::assertInvalid('close_minute must be a whole number between 0 and 2880', 'close_minute', 'V3', $create($this->lunch(['close_minute' => '840'])));
        self::assertInvalid('close_minute must be after open_minute', 'close_minute', null, $create($this->lunch(['close_minute' => 660])));
        self::assertInvalid('actual is required', 'actual', 'V1', $create($without('actual')));
        self::assertInvalid('actual must be a whole number between 0 and 5000', 'actual', 'V3', $create($this->lunch(['actual' => 5001])));
        self::assertInvalid('sales must be a number between 0 and 1000000', 'sales', 'V2', $create($this->lunch(['sales' => -1])));
        self::assertInvalid('sold_out must be true or false', 'sold_out', 'V6', $create($this->lunch(['sold_out' => 'no'])));
        self::assertInvalid('notes must be text of at most 2000 characters', 'notes', 'V5', $create($this->lunch(['notes' => str_repeat('n', 2001)])));
        self::assertInvalid('plan_stop_id must be text of at most 36 characters', 'plan_stop_id', 'V5', $create($this->lunch(['plan_stop_id' => ['x']])));
        self::assertInvalid('plan_stop_id was not found', 'plan_stop_id', 'V11', $create($this->lunch(['plan_stop_id' => '00000000-0000-4000-8000-000000000000'])));
        self::assertInvalid(
            'treat_as must be one of: normal, holiday, mon, tue, wed, thu, fri, sat, sun',
            'treat_as',
            'V4',
            $create($this->lunch(['treat_as' => 'weekend']))
        );
        self::assertSame([], $this->world->db->logs, 'a refused body writes nothing');

        // What may be left out, and what may be null.
        $plain = $this->log($this->lunch(['sales' => null, 'notes' => '', 'plan_stop_id' => null, 'treat_as' => null, 'sold_out' => null, 'kind' => null]))['service'];
        self::assertSame('spot', $plain['kind']);
        self::assertNull($plain['sales']);
        self::assertNull($plain['notes']);
        self::assertFalse($plain['sold_out']);
        // An archived spot still takes a log, and zero orders is a result.
        $this->world->spotService->archive(self::ORG, $this->taproom['id']);
        $zero = $this->log(['spot_id' => $this->taproom['id'], 'date' => self::THURSDAY, 'open_minute' => 1020, 'close_minute' => 1200, 'actual' => 0, 'sold_out' => true])['service'];
        self::assertSame(0, $zero['actual']);
        self::assertTrue($zero['sold_out']);
        self::assertNotNull($zero['prediction']);
    }
}
