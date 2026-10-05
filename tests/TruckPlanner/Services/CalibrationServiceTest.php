<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Support\LogCapture;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\CalibrationService;
use App\TruckPlanner\Services\Contracts\CalibrationProvider;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpInvalid;
use PHPUnit\Framework\TestCase;

// TruckWorld and its in-memory tables live with the planning service's test.
require_once __DIR__ . '/PlanningServiceTest.php';

/**
 * CalibrationService over the real repositories and in-memory tables: the calibration state and the
 * accuracy report computed from stored rows, and the raw predictions that are computed again when what
 * they depend on changes.
 */
final class CalibrationServiceTest extends TestCase
{
    private const ORG = TruckWorld::ORG;

    /** The service log of 02_MODEL.md 4.13: [spot, date, actual, predicted_raw, sold out]. */
    private const EXAMPLE = [
        's1' => ['A', '2026-06-06', 52, 60.0, false],
        's2' => ['A', '2026-07-11', 70, 62.0, false],
        's3' => ['B', '2026-08-01', 30, 39.4, false],
        's4' => ['B', '2026-08-22', 45, 39.4, true],
        's5' => ['A', '2026-09-12', 66, 60.5, false],
        's6' => ['C', '2026-09-26', 2, 2.1, false],
        's7' => ['B', '2026-10-03', 28, 41.0, false],
    ];

    private TruckWorld $world;

    protected function setUp(): void
    {
        $this->world = new TruckWorld();
    }

    protected function tearDown(): void
    {
        Registry::reset();
        TpConfig::replace(null);
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * Stores the seven services of 02_MODEL.md 4.13 at three saved spots, each with the prediction the
     * example gives it and the basis that prediction has today, so that nothing is computed again.
     *
     * @return array{spots: array<string, string>, entries: list<array<string, mixed>>} the ids of spots A,
     *         B and C, and the services as the model reads them, oldest first
     */
    private function storeExample(?array $truck = null, ?array $A = null): array
    {
        $truck ??= TruckWorld::truck();
        $A ??= TruckWorld::A();
        $spots = [
            'A' => $this->world->office()['id'],
            'B' => $this->world->taproom()['id'],
            'C' => $this->world->spot(['name' => 'Corner', 'point' => FixtureRegion::LONE])['id'],
        ];
        $noEvidence = Estimator::evidenceFrom(null, null);
        $entries = [];
        foreach (self::EXAMPLE as [$spot, $date, $actual, $raw, $soldOut]) {
            $row = $this->world->spots->find($spots[$spot], self::ORG, true);
            [$range] = Estimator::interval($A, $raw, $noEvidence);
            $id = $this->world->logs->create(self::ORG, TruckWorld::TRUCK, null, [
                'spot_id' => $spots[$spot],
                'service_date' => $date,
                'open_minute' => 1020,
                'close_minute' => 1200,
                'actual_orders' => $actual,
                'sold_out' => $soldOut,
                'predicted_raw' => $raw,
                'pred_raw_basis' => $this->world->calibration->rawPrediction($truck, $A, $row, $date, 1020, 1200, null, null)['basis'],
                'predicted' => $raw,
                'pred_low' => $range['low'],
                'pred_high' => $range['high'],
                'pred_confidence' => $range['confidence'],
                'pred_basis' => 'log',
            ]);
            $entries[] = [
                'service_id' => $id, 'kind' => 'spot', 'spot_id' => $spots[$spot], 'date' => $date, 'open_minute' => 1020, 'close_minute' => 1200,
                'actual' => (float) $actual, 'sold_out' => $soldOut, 'predicted_raw' => $raw, 'predicted' => $raw,
                'low' => $range['low'], 'high' => $range['high'],
            ];
        }
        return ['spots' => $spots, 'entries' => $entries];
    }

    /**
     * Logs a service through the service, as the API does.
     *
     * @param array<string, mixed> $spot Spot
     * @return array<string, mixed> ServiceLog
     */
    private function log(array $spot, string $date, int $open, int $close, int $actual, ?array $truck = null, ?array $A = null): array
    {
        return $this->world->logging->create(self::ORG, $truck ?? TruckWorld::truck(), $A ?? TruckWorld::A(), null, [
            'spot_id' => $spot['id'], 'date' => $date, 'open_minute' => $open, 'close_minute' => $close, 'actual' => $actual,
        ])['service'];
    }

    /**
     * The model's raw window of a spot.
     *
     * @param array<string, mixed> $spot Spot
     */
    private static function raw(array $spot, string $date, int $open, int $close, array $truck, array $A): float
    {
        return Estimator::windowOrders(
            $A,
            $truck['profile'],
            $spot['terms'],
            $spot['vectors'][$spot['terms']['visibility']],
            null,
            Estimator::dayContext($A, $date, null, null, null, null),
            null,
            $open,
            $close
        )['orders']['value'];
    }

    // ------------------------------------------------------------------------------------ the example of 4.13

    public function testTheCalibrationExampleIsReproducedFromStoredRows(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $example = $this->storeExample();
        ['A' => $a, 'B' => $b, 'C' => $c] = $example['spots'];
        $writes = count($this->world->db->writes('UPDATE tp_service_logs'));

        $state = $this->world->calibration->state(self::ORG, $truck, $A, '2026-10-04');

        self::assertSame(Estimator::calibrate($A, $example['entries'], '2026-10-04'), $state, 'the stored rows give the model exactly the example\'s services');
        self::assertSame('tps-0.1.0', $state['model_version']);
        self::assertSame('2026-10-04', $state['as_of']);
        self::assertEqualsWithDelta(0.963784, $state['truck_factor'], 5e-7);
        self::assertEqualsWithDelta(-0.045458, $state['truck_log_factor'], 5e-7);
        self::assertEqualsWithDelta(0.008570, $state['bias_log'], 5e-7);
        self::assertSame(6, $state['truck_n']);
        self::assertEqualsWithDelta(4.457955, $state['truck_weight'], 5e-7);
        self::assertEqualsWithDelta(0.180334, $state['resid_sd'], 5e-7);
        self::assertSame(5, $state['resid_n']);
        self::assertEqualsWithDelta(3.677890, $state['resid_weight'], 5e-7);
        $expected = [
            $a => [1.034623, 0.034037, 3, 1.992693],
            $b => [0.937663, -0.064365, 3, 2.465262],
            $c => [0.999196, -0.000804, 1, 0.954842],
        ];
        self::assertEqualsCanonicalizing(array_keys($expected), array_keys($state['spots']));
        foreach ($expected as $spotId => [$factor, $logFactor, $n, $weight]) {
            self::assertEqualsWithDelta($factor, $state['spots'][$spotId]['factor'], 5e-7);
            self::assertEqualsWithDelta($logFactor, $state['spots'][$spotId]['log_factor'], 5e-7);
            self::assertSame($n, $state['spots'][$spotId]['n']);
            self::assertEqualsWithDelta($weight, $state['spots'][$spotId]['weight'], 5e-7);
        }
        // The factor applied at spot B: 0.903705.
        [$truckFactor, $spotFactor] = Estimator::calibrationFactor($state, $b);
        self::assertEqualsWithDelta(0.903705, $truckFactor * $spotFactor, 5e-7);

        self::assertCount($writes, $this->world->db->writes('UPDATE tp_service_logs'), 'every stored basis is the present one: nothing was computed again');

        // The answer of GET /calibration.
        $summary = $this->world->calibration->summary(self::ORG, $truck, $A, '2026-10-04');
        self::assertSame(['calibration', 'as_of', 'log_count', 'eligible_count', 'raw_recomputed'], array_keys($summary));
        self::assertSame($state, $summary['calibration']);
        self::assertSame('2026-10-04', $summary['as_of']);
        self::assertSame(7, $summary['log_count']);
        self::assertSame(7, $summary['eligible_count']);
        self::assertSame(0, $summary['raw_recomputed']);

        // Without a date it is as of today where the truck is: 2026-10-05, a day later, so every weight is a little lower.
        $today = $this->world->calibration->summary(self::ORG, $truck, $A);
        self::assertSame('2026-10-05', $today['as_of']);
        self::assertSame('2026-10-05', $today['calibration']['as_of']);
        self::assertLessThan($state['truck_weight'], $today['calibration']['truck_weight']);
        $this->world->clock->set('2026-10-05 03:59:59');
        self::assertSame('2026-10-04', $this->world->calibration->summary(self::ORG, $truck, $A)['as_of'], 'one second before midnight in New York');
    }

    public function testTheAccuracyReportIsTheModelsOnTheStoredRows(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $example = $this->storeExample();
        ['A' => $a, 'B' => $b, 'C' => $c] = $example['spots'];
        // An event log and a spot log without a prediction: counted apart, never scored.
        $this->world->logs->create(self::ORG, TruckWorld::TRUCK, null, ['log_kind' => 'event', 'service_date' => '2026-09-05', 'open_minute' => 600, 'close_minute' => 900, 'actual_orders' => 120]);
        $this->world->logs->create(self::ORG, TruckWorld::TRUCK, null, ['spot_id' => $a, 'service_date' => '2026-09-06', 'open_minute' => 600, 'close_minute' => 900, 'actual_orders' => 12]);

        $answer = $this->world->calibration->accuracy(self::ORG, $truck, $A, null, null);
        self::assertSame(['accuracy', 'entries', 'unscored_without_prediction'], array_keys($answer));
        self::assertSame($example['entries'], $answer['entries'], 'the services as the model reads them, oldest first');
        self::assertSame(Estimator::accuracyReport($example['entries']), $answer['accuracy']);
        self::assertSame(2, $answer['unscored_without_prediction']);

        $report = $answer['accuracy'];
        self::assertSame(7, $report['n_total']);
        self::assertSame(6, $report['n_scored']);
        self::assertSame(1, $report['n_sold_out']);
        self::assertEqualsWithDelta(0.068548, $report['bias'], 5e-7);
        self::assertEqualsWithDelta(0.196514, $report['mape'], 5e-7);
        self::assertSame(1.0, $report['coverage']);
        self::assertSame($report['bias'], $report['raw_bias']);
        $bySpot = array_column($report['by_spot'], null, 'spot_id');
        self::assertEqualsWithDelta(-0.029255, $bySpot[$a]['bias'], 5e-7);
        self::assertEqualsWithDelta(0.117155, $bySpot[$a]['mape'], 5e-7);
        self::assertSame([3, 2, 1], [$bySpot[$b]['n_total'], $bySpot[$b]['n_scored'], $bySpot[$b]['n_sold_out']]);
        self::assertEqualsWithDelta(0.386207, $bySpot[$b]['bias'], 5e-7);
        self::assertEqualsWithDelta(0.388810, $bySpot[$b]['mape'], 5e-7);
        self::assertEqualsWithDelta(0.05, $bySpot[$c]['bias'], 5e-7);

        // A range, both ends counted: s3, s4 and s5, and the two logs without a prediction left out of it.
        $summer = $this->world->calibration->accuracy(self::ORG, $truck, $A, '2026-08-01', '2026-09-04');
        self::assertSame(array_slice($example['entries'], 2, 2), $summer['entries']);
        self::assertSame(Estimator::accuracyReport(array_slice($example['entries'], 2, 2)), $summer['accuracy']);
        self::assertSame(0, $summer['unscored_without_prediction']);
        self::assertSame(1, $this->world->calibration->accuracy(self::ORG, $truck, $A, '2026-09-05', '2026-09-05')['unscored_without_prediction']);
        self::assertCount(5, $this->world->calibration->accuracy(self::ORG, $truck, $A, null, '2026-09-12')['entries']);
        self::assertCount(3, $this->world->calibration->accuracy(self::ORG, $truck, $A, '2026-09-12', null)['entries']);

        // Nothing in the range: a report of nothing, not an error.
        $empty = $this->world->calibration->accuracy(self::ORG, $truck, $A, '2027-01-01', '2027-01-31');
        self::assertSame([], $empty['entries']);
        self::assertSame(0, $empty['accuracy']['n_total']);
        self::assertNull($empty['accuracy']['bias']);
        self::assertSame([], $empty['accuracy']['by_spot']);

        try {
            $this->world->calibration->accuracy(self::ORG, $truck, $A, '2026-09-12', '2026-09-11');
            self::fail('a range that ends before it starts was accepted');
        } catch (TpInvalid $e) {
            self::assertSame('to must not be before from', $e->getMessage());
            self::assertSame('to', $e->field());
        }
    }

    public function testATruckWithoutLogsIsTheModelItself(): void
    {
        $A = TruckWorld::A();
        $state = $this->world->calibration->state(self::ORG, TruckWorld::truck(), $A, '2026-10-05');
        self::assertSame(Estimator::calibrate($A, [], '2026-10-05'), $state);
        self::assertSame(1.0, $state['truck_factor']);
        self::assertSame([], $state['spots']);
        self::assertSame(
            ['calibration' => $state, 'as_of' => '2026-10-05', 'log_count' => 0, 'eligible_count' => 0, 'raw_recomputed' => 0],
            $this->world->calibration->summary(self::ORG, TruckWorld::truck(), $A)
        );
        // One read for each of the two calls, and no spot is looked up.
        self::assertCount(2, $this->world->db->statements);
        foreach ($this->world->db->statements as $sql) {
            self::assertStringStartsWith('SELECT id, log_kind, spot_id, service_date', $sql);
        }
    }

    public function testOnlyTheOrganizationsOwnLogsCount(): void
    {
        $example = $this->storeExample();
        $theirTruck = TruckWorld::truck(['id' => TruckWorld::OTHER_TRUCK]);
        $A = TruckWorld::A();
        self::assertSame(0, $this->world->calibration->state(TruckWorld::OTHER_ORG, $theirTruck, $A, '2026-10-04')['truck_n']);
        self::assertSame(0, $this->world->calibration->accuracy(TruckWorld::OTHER_ORG, $theirTruck, $A, null, null)['accuracy']['n_total']);
        self::assertSame(0, $this->world->calibration->summary(TruckWorld::OTHER_ORG, $theirTruck, $A)['log_count']);
        self::assertCount(7, $example['entries']);
    }

    // ------------------------------------------------------------------------------------ raw predictions

    public function testRawPredictionsAreComputedAgainAfterAnOverrideChange(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $office = $this->world->office();
        $taproom = $this->world->taproom();
        $lunch = $this->log($office, '2026-09-24', 660, 840, 55);
        $evening = $this->log($taproom, '2026-09-25', 1020, 1200, 48);
        $second = $this->log($taproom, '2026-10-02', 1020, 1200, 60);
        self::assertSame(0, $this->world->calibration->ensureRawPredictions(self::ORG, $truck, $A), 'a new log carries the present basis');
        $before = $this->world->db->logs;
        $stateBefore = $this->world->calibration->state(self::ORG, $truck, $A, '2026-10-05');

        // The owner lowers the share of a taproom's guests who eat from the truck (PUT /assumptions).
        $changed = TruckWorld::A(['host.captive_share' => 0.6]);
        $this->world->db->advance(60);
        $summary = $this->world->calibration->summary(self::ORG, $truck, $changed);
        self::assertSame(3, $summary['raw_recomputed'], 'every log of the truck depends on its assumptions');
        self::assertSame(3, $summary['eligible_count']);

        $rows = $this->world->db->logs;
        // The taproom's raw predictions are the model's under the new assumption: 0.6 / 0.75 of what they were.
        foreach ([$evening, $second] as $service) {
            $raw = self::raw($taproom, $service['date'], 1020, 1200, $truck, $changed);
            self::assertSame((string) json_encode($raw), $rows[$service['id']]['predicted_raw']);
            self::assertEqualsWithDelta($service['prediction']['predicted_raw'] * 0.6 / 0.75, $raw, 1e-9);
            self::assertNotSame($before[$service['id']]['pred_raw_basis'], $rows[$service['id']]['pred_raw_basis']);
            self::assertSame($this->world->db->now, $rows[$service['id']]['updated_at']);
        }
        // The office area has no host: the number is the same, the basis is the new one.
        self::assertSame($before[$lunch['id']]['predicted_raw'], $rows[$lunch['id']]['predicted_raw']);
        self::assertNotSame($before[$lunch['id']]['pred_raw_basis'], $rows[$lunch['id']]['pred_raw_basis']);
        // What the owner was shown is history.
        foreach (array_keys($before) as $id) {
            foreach (['predicted', 'pred_low', 'pred_high', 'pred_confidence', 'pred_basis', 'prediction_json', 'actual_orders', 'weather_json'] as $column) {
                self::assertSame($before[$id][$column], $rows[$id][$column], $column);
            }
        }
        // Calibration now judges the truck against the new raw predictions: the taproom did better than estimated.
        self::assertGreaterThan($stateBefore['truck_factor'], $summary['calibration']['truck_factor']);
        self::assertSame(
            Estimator::calibrate($changed, $this->world->calibration->entries(self::ORG, $truck, $changed), '2026-10-05'),
            $summary['calibration']
        );

        // Once is enough.
        $updates = count($this->world->db->writes('UPDATE tp_service_logs'));
        self::assertSame(0, $this->world->calibration->ensureRawPredictions(self::ORG, $truck, $changed));
        self::assertSame(0, $this->world->calibration->summary(self::ORG, $truck, $changed)['raw_recomputed']);
        self::assertCount($updates, $this->world->db->writes('UPDATE tp_service_logs'));

        // The override is taken back: the numbers of before, bit for bit.
        self::assertSame(3, $this->world->calibration->ensureRawPredictions(self::ORG, $truck, $A));
        foreach ($before as $id => $row) {
            self::assertSame($row['predicted_raw'], $this->world->db->logs[$id]['predicted_raw']);
            self::assertSame($row['pred_raw_basis'], $this->world->db->logs[$id]['pred_raw_basis']);
        }
        self::assertSame($stateBefore, $this->world->calibration->state(self::ORG, $truck, $A, '2026-10-05'));
    }

    public function testTheBasisFollowsExactlyWhatARawPredictionDependsOn(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $office = $this->world->office();
        $taproom = $this->world->taproom();
        $this->log($office, '2026-09-24', 660, 840, 55);
        $this->log($taproom, '2026-09-25', 1020, 1200, 48);
        $again = fn (?array $t = null, ?array $a = null): int => $this->world->calibration->ensureRawPredictions(self::ORG, $t ?? $truck, $a ?? $A);
        self::assertSame(0, $again());

        // What demand is not computed from changes nothing: money, drive and crew settings of the profile, fees.
        $money = TruckWorld::truck(['profile' => ['avg_ticket' => 22.0, 'wage_per_hour' => 30.0, 'mpg' => 5.0, 'prep_minutes' => 90, 'fuel_price_override' => 5.0]]);
        self::assertSame(0, $again($money));
        $this->world->spotService->update(self::ORG, $truck, $taproom['id'], ['terms' => ['fee_pct' => 0.15, 'fee_min' => 50], 'name' => 'Taproom, renamed', 'notes' => 'x']);
        self::assertSame(0, $again());

        // Capacity and daypart fit are what the truck brings to demand.
        $slow = TruckWorld::truck(['profile' => ['capacity_orders_per_hour' => 12.0]]);
        self::assertSame(2, $again($slow));
        self::assertSame(36.0, (float) $this->world->db->logs[array_key_first($this->world->db->logs)]['predicted_raw'], 'three hours at twelve orders an hour');
        self::assertSame(0, $again($slow));
        self::assertSame(2, $again());
        $noLunch = TruckWorld::truck(['profile' => ['daypart_fit' => ['breakfast' => 0.3, 'lunch' => 0.5, 'dinner' => 1.0, 'late' => 0.8]]]);
        self::assertSame(2, $again($noLunch));
        self::assertSame(2, $again());

        // Another seeds revision, another model, the region's holiday flags.
        self::assertSame(2, $again(null, ['seeds_revision' => Seeds::revision() + 1] + $A));
        self::assertSame(2, $again(null, ['model_version' => 'tps-0.1.1'] + $A));
        $flagged = $A;
        $flagged['region']['flags']['inauguration_day'] = true;
        self::assertSame(2, $again(null, $flagged));
        self::assertSame(2, $again());

        // The spot: its visibility, its host, its stored vectors. Only that spot's logs follow.
        $this->world->spotService->update(self::ORG, $truck, $office['id'], ['terms' => ['visibility' => 'prominent']]);
        self::assertSame(1, $again());
        $this->world->spotService->update(self::ORG, $truck, $taproom['id'], ['terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 200, 'only_food' => true]]]);
        self::assertSame(1, $again());
        $this->world->region->switchTo('mini-20270105-bbbb2222', 2.0);
        self::assertSame(0, $again(), 'the stored vectors are still the old ones: nothing is recomputed until a spot is refreshed');
        $this->world->spotService->refresh(self::ORG, $truck, $office['id']);
        self::assertSame(1, $again());
        self::assertSame(0, $again());
    }

    public function testTheStoredWeatherAndDayTypeAreWhatARawPredictionIsComputedWith(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $office = $this->world->office();
        $wet = FixtureContexts::day([11 => ['precip_prob' => 90.0, 'short_forecast' => 'Rain'], 12 => ['precip_prob' => 90.0, 'short_forecast' => 'Rain'], 13 => ['precip_prob' => 90.0, 'short_forecast' => 'Rain']]);
        // Logged today, while today's forecast is known; and a Saturday treated as a weekday.
        $this->world->contexts->forecast = ['2026-10-05' => $wet];
        $today = $this->log($office, '2026-10-05', 660, 840, 30);
        $this->world->contexts->forecast = [];
        $typed = $this->world->logging->create(self::ORG, $truck, $A, null, ['spot_id' => $office['id'], 'date' => '2026-10-03', 'open_minute' => 660, 'close_minute' => 840, 'actual' => 50, 'treat_as' => 'thu'])['service'];
        $dry = self::raw($office, '2026-10-05', 660, 840, $truck, $A);
        self::assertLessThan($dry, $today['prediction']['predicted_raw']);
        self::assertGreaterThan(self::raw($office, '2026-10-03', 660, 840, $truck, $A), $typed['prediction']['predicted_raw'], 'an office area on a weekday against a Saturday');

        // Computed again later, with no forecast to be had any more: the same numbers, from what was stored.
        $slow = TruckWorld::truck(['profile' => ['capacity_orders_per_hour' => 44.0]]);
        self::assertSame(2, $this->world->calibration->ensureRawPredictions(self::ORG, $slow, $A));
        self::assertSame(2, $this->world->calibration->ensureRawPredictions(self::ORG, $truck, $A));
        $entries = array_column($this->world->calibration->entries(self::ORG, $truck, $A), 'predicted_raw', 'service_id');
        self::assertSame($today['prediction']['predicted_raw'], $entries[$today['id']]);
        self::assertSame($typed['prediction']['predicted_raw'], $entries[$typed['id']]);
        self::assertSame([], array_filter($this->world->contexts->asked, static fn (array $call): bool => $call[0] > '2026-10-05'));
    }

    public function testALogTheModelCannotReadDoesNotTakeCalibrationDown(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $office = $this->world->office();
        $good = $this->log($office, '2026-09-24', 660, 840, 55);
        $bad = $this->log($office, '2026-09-25', 660, 840, 50);
        // A row as an import could leave it: a window that ends before it starts.
        $this->world->db->logs[$bad['id']]['close_minute'] = 600;

        $count = null;
        $lines = LogCapture::during(function () use (&$count, $truck, $A): void {
            $count = $this->world->calibration->ensureRawPredictions(self::ORG, TruckWorld::truck(['profile' => ['capacity_orders_per_hour' => 12.0]]), $A);
        });
        self::assertSame(1, $count, 'the log that can be read is computed again');
        self::assertCount(1, $lines);
        self::assertStringStartsWith('[tp] a raw prediction could not be recomputed: ', $lines[0]);
        self::assertNull($this->world->db->logs[$bad['id']]['predicted_raw'], 'the other is left without a raw prediction');
        self::assertSame($bad['prediction']['predicted'], (float) $this->world->db->logs[$bad['id']]['predicted'], 'what the owner was shown stays');
        self::assertSame(36.0, (float) $this->world->db->logs[$good['id']]['predicted_raw']);

        // It is not judged, and it is not tried again while nothing changes: no second log line.
        $slow = TruckWorld::truck(['profile' => ['capacity_orders_per_hour' => 12.0]]);
        $lines = LogCapture::during(function () use ($slow, $A): void {
            self::assertSame(0, $this->world->calibration->ensureRawPredictions(self::ORG, $slow, $A));
            self::assertSame(1, $this->world->calibration->state(self::ORG, $slow, $A, '2026-10-05')['truck_n']);
            self::assertSame(1, $this->world->calibration->accuracy(self::ORG, $slow, $A, null, null)['unscored_without_prediction']);
        });
        self::assertSame([], $lines);

        // Once the owner corrects the window the log is read again. (Before the correction is written the
        // log is met once more under the truck's real capacity, which is one more line.)
        $lines = LogCapture::during(function () use ($truck, $A, $bad): void {
            $this->world->logging->update(self::ORG, $truck, $A, $bad['id'], ['close_minute' => 840]);
        });
        self::assertCount(1, $lines);
        self::assertSame(2, $this->world->calibration->state(self::ORG, $truck, $A, '2026-10-05')['truck_n']);
        self::assertSame($bad['prediction']['predicted_raw'], (float) $this->world->db->logs[$bad['id']]['predicted_raw']);
    }

    public function testALogWhoseSpotIsGoneKeepsItsNumber(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $office = $this->world->office();
        $log = $this->log($office, '2026-09-24', 660, 840, 55);
        unset($this->world->db->spots->rows[$office['id']]);
        self::assertSame(0, $this->world->calibration->ensureRawPredictions(self::ORG, TruckWorld::truck(['profile' => ['capacity_orders_per_hour' => 30.0]]), $A));
        self::assertSame($log['prediction']['predicted_raw'], $this->world->calibration->entries(self::ORG, $truck, $A)[0]['predicted_raw']);
    }

    // ------------------------------------------------------------------------------------ helpers of the other services

    public function testVectorsOfIsTheSpotValueWithoutComputingAnything(): void
    {
        $truck = TruckWorld::truck();
        $taproom = $this->world->taproom();
        $row = $this->world->spots->find($taproom['id'], self::ORG, true);
        $terms = $this->world->spotService->terms($row);
        $captures = $this->world->region->count('capture');

        $vectors = CalibrationService::vectorsOf($row, $terms);
        self::assertSame($this->world->spotService->ensureFresh(self::ORG, $truck, $row)['vectors']['normal'], $vectors);
        self::assertSame($taproom['vectors']['normal'], $vectors);
        self::assertTrue(Estimator::vectorsMatch(Seeds::defaults(), $terms, $vectors));

        // Stale vectors are handed over as they are; a spot without vectors has none.
        $this->world->region->switchTo('mini-20270105-bbbb2222', 2.0);
        self::assertSame($vectors, CalibrationService::vectorsOf($row, $terms));
        self::assertSame($captures, $this->world->region->count('capture'));
        self::assertNull(CalibrationService::vectorsOf(['vectors' => null] + $row, $terms));
        self::assertNull($this->world->calibration->rawPrediction($truck, TruckWorld::A(), ['vectors' => null] + $row, '2026-10-01', 660, 840, null, null));
    }

    public function testTheTrucksZoneFallsBackToTheDefaultWhenTheServerDoesNotKnowIt(): void
    {
        self::assertSame('America/Chicago', CalibrationService::zoneOf(['timezone' => 'America/Chicago']));
        $zone = null;
        $lines = LogCapture::during(function () use (&$zone): void {
            $zone = CalibrationService::zoneOf(['timezone' => 'Mars/Olympus_Mons']);
        });
        self::assertSame('America/New_York', $zone);
        self::assertSame(['[tp] a truck has a time zone this server does not know, the default zone is used'], $lines);
    }

    public function testTheRegistryBuildsItWithoutArguments(): void
    {
        Registry::reset();
        $provider = Registry::calibration();
        self::assertInstanceOf(CalibrationService::class, $provider);
        self::assertInstanceOf(CalibrationProvider::class, $provider);
    }
}
