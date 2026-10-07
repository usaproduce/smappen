<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Core\Database;
use App\Tests\TruckPlanner\Support\FixedClock;
use App\Tests\TruckPlanner\Support\RecordingDatabase;
use App\TruckPlanner\Data\CountsRepository;
use App\TruckPlanner\Data\LegKey;
use App\TruckPlanner\Data\PlanRepository;
use App\TruckPlanner\Data\ServiceLogRepository;
use App\TruckPlanner\Data\SpotRepository;
use App\TruckPlanner\Data\TruckRepository;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\CalibrationService;
use App\TruckPlanner\Services\Contracts\DayContextProvider;
use App\TruckPlanner\Services\Contracts\LegProvider;
use App\TruckPlanner\Services\Fallback\SeedFuelPrice;
use App\TruckPlanner\Services\Fallback\StraightLineLegs;
use App\TruckPlanner\Services\PlanningService;
use App\TruckPlanner\Services\ServiceLogService;
use App\TruckPlanner\Services\SpotService;
use App\TruckPlanner\Services\Support\Registry;
use App\TruckPlanner\Services\Support\TpConfig;
use App\TruckPlanner\Services\Support\TpConflict;
use App\TruckPlanner\Services\Support\TpInvalid;
use App\TruckPlanner\Services\Support\TpNotFound;
use PHPUnit\Framework\TestCase;

// The fixture region and the in-memory `tp_spots` live with the spot service's test.
require_once __DIR__ . '/SpotServiceTest.php';

/**
 * PlanningService over the real repositories and an in-memory copy of the four owner tables it touches
 * (PlanTables), on the fixture region of SpotServiceTest. Drive legs and day contexts are answered by
 * doubles behind the contracts, so every number below can be recomputed from 02_MODEL.md.
 *
 * The fixture classes at the end of this file (TruckWorld, PlanTables, FixtureLegs, FixtureContexts) are
 * shared with ServiceLogServiceTest, CalibrationServiceTest and DemoTruckSeederTest.
 */
final class PlanningServiceTest extends TestCase
{
    private const ORG = TruckWorld::ORG;
    private const THURSDAY = '2026-10-08';

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
     * The blueprint's Thursday: the office area 11:00 to 14:00, then the taproom 17:00 to 20:00, with the
     * drive legs of its day sheet (11, 10 and 1 minutes) as the owner's corrections.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [office spot, taproom spot]
     */
    private function blueprintSpots(): array
    {
        $office = $this->world->office();
        $taproom = $this->world->taproom();
        $base = TruckWorld::BASE;
        $this->world->legs->fixed($base, $office['point'], 11, 4.85);
        $this->world->legs->fixed($office['point'], $taproom['point'], 10, 4.85);
        $this->world->legs->fixed($taproom['point'], $base, 1, 0.2);
        $this->world->legs->fixed($office['point'], $base, 11, 4.85);
        $this->world->legs->fixed($base, $taproom['point'], 1, 0.2);
        return [$office, $taproom];
    }

    /**
     * @param array<string, mixed> $office
     * @param array<string, mixed> $taproom
     * @return array<string, mixed> the body of route 23 for the blueprint's Thursday
     */
    private static function blueprintBody(array $office, array $taproom, string $date = self::THURSDAY): array
    {
        return [
            'date' => $date,
            'stops' => [
                ['kind' => 'spot', 'spot_id' => $office['id'], 'open_minute' => 660, 'close_minute' => 840],
                ['kind' => 'spot', 'spot_id' => $taproom['id'], 'open_minute' => 1020, 'close_minute' => 1200],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed> Plan
     */
    private function create(array $body, ?array $truck = null, ?array $A = null): array
    {
        return $this->world->planning->create(self::ORG, $truck ?? TruckWorld::truck(), $A ?? TruckWorld::A(), TruckWorld::USER, $body);
    }

    /**
     * @return array<string, mixed> Plan
     */
    private function get(string $planId, ?array $truck = null, ?array $A = null): array
    {
        return $this->world->planning->get(self::ORG, $truck ?? TruckWorld::truck(), $A ?? TruckWorld::A(), $planId);
    }

    /**
     * The model's StopInput of a spot stop, built by hand from the API's Spot.
     *
     * @param array<string, mixed> $spot
     * @return array<string, mixed>
     */
    private static function spotStop(string $id, array $spot, int $open, int $close): array
    {
        return [
            'id' => $id, 'kind' => 'spot', 'spot_id' => $spot['id'], 'point' => $spot['point'],
            'open_minute' => $open, 'close_minute' => $close, 'gap_before_unpaid' => false,
            'setup_minutes' => null, 'teardown_minutes' => null,
            'terms' => $spot['terms'], 'vectors' => $spot['vectors'][$spot['terms']['visibility']],
            'event' => null, 'catering' => null,
        ];
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

    // ------------------------------------------------------------------------------------ the stored result

    public function testAStoredPlanIsTheModelsDayPlanOnTheSameInputs(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();

        $plan = $this->create(self::blueprintBody($office, $taproom) + ['status' => 'planned', 'name' => ' Thursday ', 'notes' => 'Bring the awning']);

        self::assertSame(
            ['id', 'date', 'name', 'treat_as', 'notes', 'status', 'stops', 'result', 'context', 'result_state', 'evaluated_at',
                'maps_route_url', 'created_at', 'updated_at'],
            array_keys($plan)
        );
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $plan['id']);
        self::assertSame(self::THURSDAY, $plan['date']);
        self::assertSame('Thursday', $plan['name']);
        self::assertNull($plan['treat_as']);
        self::assertSame('Bring the awning', $plan['notes']);
        self::assertSame('planned', $plan['status']);
        self::assertSame('fresh', $plan['result_state']);
        self::assertSame($this->world->db->now, $plan['evaluated_at']);
        self::assertCount(2, $plan['stops']);
        [$first, $second] = $plan['stops'];
        self::assertSame(
            ['id', 'kind', 'spot_id', 'label', 'point', 'address', 'open_minute', 'close_minute', 'gap_before_unpaid',
                'setup_minutes', 'teardown_minutes', 'fee_flat', 'fee_pct', 'fee_min', 'event', 'catering'],
            array_keys($first)
        );
        self::assertSame(
            ['kind' => 'spot', 'spot_id' => $office['id'], 'label' => '', 'point' => null, 'address' => '', 'open_minute' => 660,
                'close_minute' => 840, 'gap_before_unpaid' => false, 'setup_minutes' => null, 'teardown_minutes' => null,
                'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'event' => null, 'catering' => null],
            array_diff_key($first, ['id' => true])
        );
        self::assertSame($taproom['id'], $second['spot_id']);
        self::assertNotSame($first['id'], $second['id']);

        // The same inputs, handed to the model directly.
        $today = '2026-10-05';
        $ctx = Estimator::dayContext($A, self::THURSDAY, null, null, 4.195, 'seed');
        $next = Estimator::dayContext($A, '2026-10-09', null, null, 4.195, 'seed');
        $fixed = static fn (int $minutes, float $miles): array => [
            'source' => 'google', 'distance_m' => $miles * 1609.344, 'duration_s' => 0.0, 'override_minutes' => $minutes, 'toll' => 0.0,
        ];
        $a = $first['id'];
        $b = $second['id'];
        $legs = [
            'base>' . $a => $fixed(11, 4.85), $a . '>' . $b => $fixed(10, 4.85), $b . '>base' => $fixed(1, 0.2),
            'base>' . $b => $fixed(1, 0.2), $a . '>base' => $fixed(11, 4.85),
        ];
        $stops = [self::spotStop($a, $office, 660, 840), self::spotStop($b, $taproom, 1020, 1200)];
        $expected = Estimator::dayPlan($A, $truck['profile'], ['date' => self::THURSDAY, 'stops' => $stops], $ctx, $next, $legs, Estimator::calibrate($A, [], $today));

        self::assertSame($expected, $plan['result'], 'what is stored and read back is the model\'s result, number for number');
        self::assertSame($expected, json_decode((string) $this->world->db->plans[$plan['id']]['result_json'], true));

        // The blueprint's day sheet, to the minute: start prep 9:34 ... done 20:51.
        $sheet = [];
        foreach ($plan['result']['timeline']['events'] as $event) {
            $sheet[] = [$event['kind'], $event['minute'], $event['stop_index']];
        }
        self::assertSame(
            [
                ['start_prep', 574, null], ['leave_base', 619, null], ['arrive', 630, 0], ['setup_start', 630, 0], ['open', 660, 0],
                ['close', 840, 0], ['leave', 860, 0], ['arrive', 870, 1], ['setup_start', 990, 1], ['open', 1020, 1],
                ['close', 1200, 1], ['leave', 1220, 1], ['back_at_base', 1221, null], ['done', 1251, null],
            ],
            $sheet
        );
        self::assertSame(574, $plan['result']['timeline']['start_prep']);
        self::assertSame(1251, $plan['result']['timeline']['done']);
        self::assertSame(677, $plan['result']['timeline']['day_minutes']);
        self::assertSame(22, $plan['result']['timeline']['drive_minutes']);

        // The worked day of 02_MODEL.md 4.12.
        $r = $plan['result'];
        self::assertEqualsWithDelta(60.49, $r['stops'][0]['orders']['value'], 0.005);
        self::assertEqualsWithDelta(33.01, $r['stops'][0]['orders']['low'], 0.005);
        self::assertEqualsWithDelta(93.19, $r['stops'][0]['orders']['high'], 0.005);
        self::assertEqualsWithDelta(39.38, $r['stops'][1]['orders']['value'], 0.005);
        self::assertSame('rough', $r['stops'][1]['orders']['confidence']);
        self::assertEqualsWithDelta(1498.17, $r['totals']['sales']['value'], 0.005);
        self::assertEqualsWithDelta(482.20, $r['totals']['take_home']['value'], 0.005);
        self::assertEqualsWithDelta(42.35, $r['totals']['take_home']['low'], 0.005);
        self::assertEqualsWithDelta(1011.86, $r['totals']['take_home']['high'], 0.005);
        self::assertEqualsWithDelta(135.02, $r['stops'][1]['adds']['take_home']['value'], 0.005);
        self::assertEqualsWithDelta(561.40, $r['unpaid_gap_alternative']['take_home']['value'], 0.005);
        // The fixture's taproom has no census block around it, which the model says; the rest is the worked day's.
        self::assertSame(['outside_region', 'long_gap', 'weak_day_loss', 'no_forecast'], array_column($r['warnings'], 'code'));
        self::assertSame([], $r['warnings'][0]['data']);
        self::assertSame(['gap_before_minutes' => 120], $r['warnings'][1]['data']);
        self::assertSame([$a, $b], array_column($r['stops'], 'id'));

        // The context the result was computed in.
        $context = $plan['context'];
        self::assertSame(
            ['ctx', 'ctx_next', 'legs', 'calibration', 'fuel', 'model_version', 'seeds_revision', 'dataset_version', 'uses_google_legs'],
            array_keys($context)
        );
        self::assertSame($ctx, $context['ctx']);
        self::assertSame($next, $context['ctx_next']);
        self::assertSame(['as_of' => $today, 'truck_factor' => 1.0, 'truck_n' => 0], $context['calibration']);
        self::assertSame(FixtureContexts::FUEL, $context['fuel']);
        self::assertSame('tps-0.1.0', $context['model_version']);
        self::assertSame(Seeds::revision(), $context['seeds_revision']);
        self::assertSame(FixtureRegion::VERSION, $context['dataset_version']);
        self::assertTrue($context['uses_google_legs']);
        self::assertSame(
            [['base', $a], [$a, $b], [$b, 'base'], ['base', $b], [$a, 'base']],
            array_map(static fn (array $leg): array => [$leg['from_id'], $leg['to_id']], $context['legs']),
            'the day as ordered, then the legs that appear when one stop is left out'
        );
        self::assertSame(11, $context['legs'][0]['override']['minutes']);

        // What the two providers were asked.
        self::assertCount(1, $this->world->legs->calls);
        [$orgId, $points, $pairs, $options] = $this->world->legs->calls[0];
        self::assertSame(self::ORG, $orgId);
        self::assertSame(
            [['id' => 'base'] + TruckWorld::BASE, ['id' => $a] + $office['point'], ['id' => $b] + $taproom['point']],
            $points
        );
        self::assertSame([['base', $a], [$a, $b], [$b, 'base'], ['base', $b], [$a, 'base']], $pairs);
        self::assertSame(['tolls' => true], $options);
        self::assertSame([[self::THURSDAY, 2, [self::THURSDAY => null]]], $this->world->contexts->asked);

        // The row: versions, the Google flag, and a route link through both stops.
        $row = $this->world->db->plans[$plan['id']];
        self::assertSame(self::ORG, $row['organization_id']);
        self::assertSame(TruckWorld::TRUCK, $row['truck_id']);
        self::assertSame(TruckWorld::USER, $row['created_by']);
        self::assertSame(1, $row['result_has_google']);
        self::assertSame('tps-0.1.0', $row['model_version']);
        self::assertSame(FixtureRegion::VERSION, $row['dataset_version']);
        self::assertSame(
            'https://www.google.com/maps/dir/?api=1&origin=39.003000%2C-77.405000&destination=39.003000%2C-77.405000'
            . '&waypoints=38.960000%2C-77.360000%7C39.010000%2C-77.410000&travelmode=driving',
            $plan['maps_route_url']
        );
        self::assertSame($plan, $this->get($plan['id']));
    }

    public function testTheDayIsEvaluatedAsOfTodayInTheTrucksZoneAndWithItsDayType(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        // 03:30 UTC on Tuesday is still Monday evening in New York.
        $this->world->clock->set('2026-10-06 03:30:00');
        $plan = $this->create(self::blueprintBody($office, $taproom) + ['treat_as' => 'sat']);

        self::assertSame('2026-10-05', $plan['context']['calibration']['as_of']);
        self::assertSame('sat', $plan['treat_as']);
        self::assertSame('sat', $plan['context']['ctx']['treat_as']);
        self::assertSame(5, $plan['context']['ctx']['eff_dow']);
        self::assertNull($plan['context']['ctx_next']['treat_as'], 'the override belongs to one civil date');
        self::assertSame([[self::THURSDAY, 2, [self::THURSDAY => 'sat']]], $this->world->contexts->asked);
        // A Saturday at the taproom is worth more than a Thursday: 64.548 orders (02_MODEL.md anchor A2).
        self::assertEqualsWithDelta(64.548, $plan['result']['stops'][1]['orders']['value'], 0.0005);
    }

    public function testTheLogsCalibrateThePlan(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        // A calibration state with a truck factor: the plan must be evaluated with it.
        $state = Estimator::calibrate(TruckWorld::A(), [], '2026-10-05');
        $state['truck_factor'] = 0.9;
        $state['truck_n'] = 6;
        $state['truck_weight'] = 4.0;
        Registry::set('calibration', new class($state) implements \App\TruckPlanner\Services\Contracts\CalibrationProvider {
            /** @param array<string, mixed> $state */
            public function __construct(private array $state)
            {
            }

            public function state(string $orgId, array $truck, array $A, string $asOf): array
            {
                return ['as_of' => $asOf] + $this->state;
            }
        });
        $plan = $this->create(self::blueprintBody($office, $taproom));
        self::assertSame(['as_of' => '2026-10-05', 'truck_factor' => 0.9, 'truck_n' => 6], $plan['context']['calibration']);
        self::assertEqualsWithDelta(0.9, $plan['result']['stops'][1]['window']['hours'][0]['result']['factors']['truck_factor'], 1e-12);
        self::assertEqualsWithDelta(39.384 * 0.9, $plan['result']['stops'][1]['orders']['value'], 0.001);
    }

    public function testStraightLineLegsAreLabelledAndKeepTheSnapshotForGood(): void
    {
        $office = $this->world->office();
        $plan = $this->create(['date' => self::THURSDAY, 'stops' => [['kind' => 'spot', 'spot_id' => $office['id'], 'open_minute' => 660, 'close_minute' => 840]]]);

        self::assertFalse($plan['context']['uses_google_legs']);
        self::assertSame(['straight_line', 'straight_line'], array_column($plan['context']['legs'], 'source'));
        self::assertSame(['no_key', 'no_key'], array_column($plan['context']['legs'], 'fallback_reason'));
        self::assertSame(0, $this->world->db->plans[$plan['id']]['result_has_google']);
        $warning = array_values(array_filter($plan['result']['warnings'], static fn (array $w): bool => $w['code'] === 'fallback_drive_time'));
        self::assertCount(1, $warning, 'a leg Google did not supply is said to be an estimate');
        $id = $plan['stops'][0]['id'];
        self::assertSame(['legs' => ['base>' . $id, $id . '>base']], $warning[0]['data']);

        // Such a snapshot holds nothing of Google's: it never expires.
        $this->world->db->advanceDays(400);
        self::assertSame('fresh', $this->get($plan['id'])['result_state']);
        self::assertSame([], $this->world->planning->list(self::ORG, TruckWorld::truck(), TruckWorld::A(), ['from' => '2026-11-01', 'to' => '2026-11-02']));
        self::assertNotNull($this->world->db->plans[$plan['id']]['result_json']);
    }

    public function testAPlanWithoutStopsIsAnEmptyDay(): void
    {
        $plan = $this->create(['date' => self::THURSDAY]);
        self::assertSame([], $plan['stops']);
        self::assertSame('draft', $plan['status']);
        self::assertSame('', $plan['name']);
        self::assertNull($plan['notes']);
        self::assertNull($plan['maps_route_url']);
        self::assertSame('fresh', $plan['result_state']);
        self::assertSame([], $plan['result']['stops']);
        self::assertSame([], $plan['result']['warnings']);
        self::assertNull($plan['result']['timeline']['start_prep']);
        self::assertSame(0.0, $plan['result']['totals']['take_home']['value']);
        self::assertSame('fixed', $plan['result']['totals']['take_home']['confidence']);
        self::assertSame([], $plan['context']['legs']);
        self::assertSame([], $this->world->legs->calls, 'no leg is asked for a day without stops');
    }

    // ------------------------------------------------------------------------------------ events and catering

    public function testEventAndCateringStopsCarryTheirOwnTerms(): void
    {
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $fair = ['lat' => 38.95, 'lng' => -77.35];
        $hall = ['lat' => 38.99, 'lng' => -77.39];
        $plan = $this->create([
            'date' => '2026-10-10',
            'stops' => [
                [
                    'kind' => 'event', 'point' => $fair, 'label' => ' Fall fair ', 'address' => '1 Fair Way', 'open_minute' => 660, 'close_minute' => 900,
                    'fee_flat' => 10.005, 'fee_pct' => 0.12, 'fee_min' => 75, 'setup_minutes' => 45, 'teardown_minutes' => 0,
                    'event' => ['attendance' => 1500, 'vendors' => 6, 'event_type' => 'general'],
                    'spot_id' => 'ignored for an event',
                ],
                [
                    'kind' => 'catering', 'point' => $hall, 'label' => 'Office party', 'open_minute' => 1020, 'close_minute' => 1140, 'gap_before_unpaid' => true,
                    'catering' => ['headcount' => 80, 'price_per_head' => 14, 'guarantee' => 1000, 'food_cost' => null],
                    'fee_pct' => 0.5,
                ],
            ],
        ]);

        [$event, $catering] = $plan['stops'];
        self::assertSame(
            ['kind' => 'event', 'spot_id' => null, 'label' => 'Fall fair', 'point' => $fair, 'address' => '1 Fair Way', 'open_minute' => 660,
                'close_minute' => 900, 'gap_before_unpaid' => false, 'setup_minutes' => 45, 'teardown_minutes' => 0,
                'fee_flat' => 10.01, 'fee_pct' => 0.12, 'fee_min' => 75.0,
                'event' => ['attendance' => 1500.0, 'vendors' => 6, 'event_type' => 'general'], 'catering' => null],
            array_diff_key($event, ['id' => true])
        );
        self::assertSame(
            ['kind' => 'catering', 'spot_id' => null, 'label' => 'Office party', 'point' => $hall, 'address' => '', 'open_minute' => 1020,
                'close_minute' => 1140, 'gap_before_unpaid' => true, 'setup_minutes' => null, 'teardown_minutes' => null,
                'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'event' => null,
                'catering' => ['headcount' => 80.0, 'price_per_head' => 14.0, 'guarantee' => 1000.0, 'food_cost' => null]],
            array_diff_key($catering, ['id' => true])
        );
        $stored = $this->world->db->stops[$event['id']];
        self::assertSame(1001, $stored['fee_flat_cents']);
        self::assertSame(7500, $stored['fee_min_cents']);
        self::assertSame('0.12', $stored['fee_pct']);
        self::assertSame(1400, $this->world->db->stops[$catering['id']]['cat_price_head_cents']);
        self::assertNull($this->world->db->stops[$catering['id']]['cat_food_cost_cents']);

        // The model was given exactly these two stops.
        $inputs = [
            [
                'id' => $event['id'], 'kind' => 'event', 'spot_id' => null, 'point' => $fair, 'open_minute' => 660, 'close_minute' => 900,
                'gap_before_unpaid' => false, 'setup_minutes' => 45, 'teardown_minutes' => 0,
                'terms' => ['spot_id' => null, 'visibility' => 'normal', 'host' => null, 'fee_flat' => 10.01, 'fee_pct' => 0.12, 'fee_min' => 75.0, 'allowed' => null],
                'vectors' => null, 'event' => ['attendance' => 1500.0, 'vendors' => 6, 'event_type' => 'general'], 'catering' => null,
            ],
            [
                'id' => $catering['id'], 'kind' => 'catering', 'spot_id' => null, 'point' => $hall, 'open_minute' => 1020, 'close_minute' => 1140,
                'gap_before_unpaid' => true, 'setup_minutes' => null, 'teardown_minutes' => null, 'terms' => null, 'vectors' => null, 'event' => null,
                'catering' => ['headcount' => 80.0, 'price_per_head' => 14.0, 'guarantee' => 1000.0, 'food_cost' => null],
            ],
        ];
        self::assertSame($inputs, $this->world->planning->stopInputs(self::ORG, $truck, $this->world->plans->find($plan['id'], self::ORG)['stops']));

        $legs = [];
        foreach ($plan['context']['legs'] as $leg) {
            $legs[$leg['from_id'] . '>' . $leg['to_id']] = $leg['leg_input'];
        }
        $expected = Estimator::dayPlan(
            $A,
            $truck['profile'],
            ['date' => '2026-10-10', 'stops' => $inputs],
            Estimator::dayContext($A, '2026-10-10', null, null, 4.195, 'seed'),
            Estimator::dayContext($A, '2026-10-11', null, null, 4.195, 'seed'),
            $legs,
            Estimator::calibrate($A, [], '2026-10-05')
        );
        self::assertSame($expected, $plan['result']);
        self::assertSame(['event', 'catering'], array_column($plan['result']['stops'], 'kind'));
        self::assertSame('very_rough', $plan['result']['stops'][0]['orders']['confidence']);
        self::assertSame('fixed', $plan['result']['stops'][1]['orders']['confidence']);
        self::assertSame(1120.0, $plan['result']['stops'][1]['money']['sales']['value']);
        self::assertContains('event_thin_crowd', array_column($plan['result']['warnings'], 'code'));
        self::assertStringContainsString('waypoints=38.950000%2C-77.350000%7C38.990000%2C-77.390000', (string) $plan['maps_route_url']);
    }

    // ------------------------------------------------------------------------------------ one plan per date

    public function testASecondPlanOnOneDateIsAConflict(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $first = $this->create(self::blueprintBody($office, $taproom));
        try {
            $this->create(['date' => self::THURSDAY]);
            self::fail('a second plan was saved for one date');
        } catch (TpConflict $e) {
            self::assertSame('A plan already exists for this date', $e->getMessage());
        }
        self::assertCount(1, $this->world->db->plans);

        // Another date is free, and moving a plan onto a taken date is the same conflict.
        $friday = $this->create(['date' => '2026-10-09']);
        try {
            $this->world->planning->update(self::ORG, TruckWorld::truck(), TruckWorld::A(), $friday['id'], ['date' => self::THURSDAY, 'name' => 'Moved']);
            self::fail('a plan was moved onto a date that has one');
        } catch (TpConflict $e) {
            self::assertSame('A plan already exists for this date', $e->getMessage());
        }
        self::assertSame('2026-10-09', $this->world->db->plans[$friday['id']]['service_date']);
        self::assertSame('', $this->world->db->plans[$friday['id']]['name'], 'nothing of the refused change was written');

        // Onto a free date it moves, and saving a plan onto its own date is no conflict.
        $moved = $this->world->planning->update(self::ORG, TruckWorld::truck(), TruckWorld::A(), $friday['id'], ['date' => '2026-10-12']);
        self::assertSame('2026-10-12', $moved['date']);
        self::assertSame('2026-10-12', $moved['result']['date']);
        self::assertSame(self::THURSDAY, $this->world->planning->update(self::ORG, TruckWorld::truck(), TruckWorld::A(), $first['id'], ['date' => self::THURSDAY])['date']);

        // The other tenant has its own calendar.
        $other = $this->world->planning->create(TruckWorld::OTHER_ORG, TruckWorld::truck(['id' => TruckWorld::OTHER_TRUCK]), TruckWorld::A(), null, ['date' => self::THURSDAY]);
        self::assertSame(self::THURSDAY, $other['date']);
    }

    public function testTwoSavesAtTheSameMomentLeaveOnePlan(): void
    {
        // The check finds the date free, and the unique key then refuses the insert: the other save got in between.
        $racing = new class($this->world->db) extends PlanRepository {
            public int $checks = 0;

            public function findByDate(string $orgId, string $truckId, string $date): ?array
            {
                return $this->checks++ === 0 ? null : parent::findByDate($orgId, $truckId, $date);
            }
        };
        $planning = new PlanningService($racing, $this->world->spots, $this->world->spotService, $this->world->logs, $this->world->regions, $this->world->clock);
        $first = $this->create(['date' => self::THURSDAY]);
        try {
            $planning->create(self::ORG, TruckWorld::truck(), TruckWorld::A(), TruckWorld::USER, ['date' => self::THURSDAY, 'name' => 'Second']);
            self::fail('the unique key was not met');
        } catch (TpConflict $e) {
            self::assertSame('A plan already exists for this date', $e->getMessage());
        }
        self::assertSame([$first['id']], array_keys($this->world->db->plans));
        self::assertFalse($this->world->db->inTransaction(), 'the refused insert was rolled back');
    }

    // ------------------------------------------------------------------------------------ changing a plan

    public function testStopsAreReplacedAsASet(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $plan = $this->create(self::blueprintBody($office, $taproom));
        [$a, $b] = array_column($plan['stops'], 'id');
        $other = $this->create(['date' => '2026-10-09', 'stops' => [['kind' => 'spot', 'spot_id' => $office['id'], 'open_minute' => 660, 'close_minute' => 840]]]);
        $foreign = $other['stops'][0]['id'];

        // The taproom first with the id it had, then three new stops: ids that are not this plan's are not kept.
        $this->world->db->advance(60);
        $changed = $this->world->planning->update(self::ORG, $truck, $A, $plan['id'], ['stops' => [
            ['id' => $b, 'kind' => 'spot', 'spot_id' => $taproom['id'], 'open_minute' => 600, 'close_minute' => 720],
            ['id' => $foreign, 'kind' => 'spot', 'spot_id' => $office['id'], 'open_minute' => 780, 'close_minute' => 900],
            ['id' => $b, 'kind' => 'spot', 'spot_id' => $office['id'], 'open_minute' => 960, 'close_minute' => 1020],
            ['kind' => 'spot', 'spot_id' => $taproom['id'], 'open_minute' => 1080, 'close_minute' => 1200],
        ]]);

        $ids = array_column($changed['stops'], 'id');
        self::assertCount(4, $ids);
        self::assertSame($b, $ids[0], 'a stop that comes with the id it had keeps it');
        self::assertNotContains($a, $ids, 'the stop that was not sent is gone');
        self::assertNotContains($foreign, $ids, 'an id of another plan is not taken over');
        self::assertCount(4, array_unique($ids), 'an id sent twice is kept once');
        self::assertSame([600, 780, 960, 1080], array_column($changed['stops'], 'open_minute'), 'in the order sent');
        self::assertSame([$taproom['id'], $office['id'], $office['id'], $taproom['id']], array_column($changed['stops'], 'spot_id'));

        // The table holds exactly the new set, numbered in visiting order, and the other plan is untouched.
        $rows = array_values(array_filter($this->world->db->stops, static fn (array $row): bool => $row['plan_id'] === $plan['id']));
        usort($rows, static fn (array $x, array $y): int => $x['seq'] <=> $y['seq']);
        self::assertSame($ids, array_column($rows, 'id'));
        self::assertSame([0, 1, 2, 3], array_column($rows, 'seq'));
        self::assertArrayHasKey($foreign, $this->world->db->stops);
        self::assertSame($other['id'], $this->world->db->stops[$foreign]['plan_id']);

        // The plan was evaluated again as it now stands.
        self::assertSame($ids, array_column($changed['result']['stops'], 'id'));
        self::assertSame('fresh', $changed['result_state']);
        self::assertSame($this->world->db->now, $changed['evaluated_at']);
        self::assertNotSame($plan['evaluated_at'], $changed['evaluated_at']);

        // An empty list empties the day.
        $empty = $this->world->planning->update(self::ORG, $truck, $A, $plan['id'], ['stops' => []]);
        self::assertSame([], $empty['stops']);
        self::assertSame([], $empty['result']['stops']);
        self::assertNull($empty['maps_route_url']);
        self::assertSame([], array_filter($this->world->db->stops, static fn (array $row): bool => $row['plan_id'] === $plan['id']));
    }

    public function testUpdateChangesOnlyTheKeysItCarries(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $plan = $this->create(self::blueprintBody($office, $taproom) + ['name' => 'Thursday', 'notes' => 'First draft', 'treat_as' => 'holiday', 'status' => 'planned']);

        $renamed = $this->world->planning->update(self::ORG, $truck, $A, $plan['id'], ['name' => 'Lunch and taproom', 'unknown' => 1]);
        self::assertSame('Lunch and taproom', $renamed['name']);
        self::assertSame('First draft', $renamed['notes']);
        self::assertSame('holiday', $renamed['treat_as']);
        self::assertSame('planned', $renamed['status']);
        self::assertSame(array_column($plan['stops'], 'id'), array_column($renamed['stops'], 'id'), 'stops that were not sent stay as they are');
        self::assertSame($plan['result']['totals'], $renamed['result']['totals']);

        $cleared = $this->world->planning->update(self::ORG, $truck, $A, $plan['id'], ['notes' => '', 'treat_as' => null, 'status' => 'done', 'name' => null]);
        self::assertNull($cleared['notes']);
        self::assertNull($cleared['treat_as']);
        self::assertSame('done', $cleared['status']);
        self::assertSame('', $cleared['name']);
        self::assertNull($cleared['context']['ctx']['treat_as'], 'the plan was evaluated again without the override');
        self::assertNotSame($plan['result']['totals']['orders'], $cleared['result']['totals']['orders']);

        self::assertInvalid('Nothing to update', null, 'V12', fn () => $this->world->planning->update(self::ORG, $truck, $A, $plan['id'], []));
        self::assertInvalid('Nothing to update', null, 'V12', fn () => $this->world->planning->update(self::ORG, $truck, $A, $plan['id'], ['colour' => 'red']));
        self::assertInvalid('status is required', 'status', 'V1', fn () => $this->world->planning->update(self::ORG, $truck, $A, $plan['id'], ['status' => null]));
        self::assertInvalid('stops is required', 'stops', 'V1', fn () => $this->world->planning->update(self::ORG, $truck, $A, $plan['id'], ['stops' => null]));
        self::assertSame('done', $this->world->db->plans[$plan['id']]['plan_state'], 'a refused change writes nothing');
    }

    public function testEvaluateStoredWritesANewSnapshot(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $truck = TruckWorld::truck();
        $plan = $this->create(self::blueprintBody($office, $taproom));

        // The owner now takes 12 minutes to the offices: the stored result knows nothing of it until asked.
        $this->world->legs->fixed(TruckWorld::BASE, $office['point'], 12, 4.85);
        $this->world->db->advance(120);
        self::assertSame(574, $this->get($plan['id'])['result']['timeline']['start_prep']);

        $again = $this->world->planning->evaluateStored(self::ORG, $truck, TruckWorld::A(), $plan['id']);
        self::assertSame(573, $again['result']['timeline']['start_prep']);
        self::assertSame($this->world->db->now, $again['evaluated_at']);
        self::assertSame('fresh', $again['result_state']);
        self::assertSame(array_column($plan['stops'], 'id'), array_column($again['stops'], 'id'));
    }

    public function testDeleteRemovesThePlanAndItsStopsAndDetachesItsLogs(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $plan = $this->create(self::blueprintBody($office, $taproom, '2026-10-01'));
        $keep = $this->create(['date' => '2026-10-02', 'stops' => [['kind' => 'spot', 'spot_id' => $office['id'], 'open_minute' => 660, 'close_minute' => 840]]]);
        $log = $this->world->logs->create(self::ORG, TruckWorld::TRUCK, null, [
            'spot_id' => $office['id'], 'plan_id' => $plan['id'], 'plan_stop_id' => $plan['stops'][0]['id'],
            'service_date' => '2026-10-01', 'open_minute' => 660, 'close_minute' => 840, 'actual_orders' => 52,
        ]);
        $stamp = $this->world->db->logs[$log]['updated_at'];
        $this->world->db->advance(30);

        self::assertSame(['id' => $plan['id'], 'deleted' => true], $this->world->planning->delete(self::ORG, $plan['id']));
        self::assertSame([$keep['id']], array_keys($this->world->db->plans));
        self::assertSame([$keep['stops'][0]['id']], array_keys($this->world->db->stops));
        $row = $this->world->db->logs[$log];
        self::assertNull($row['plan_id']);
        self::assertNull($row['plan_stop_id']);
        self::assertSame(52, $row['actual_orders'], 'the log keeps its numbers');
        self::assertSame($stamp, $row['updated_at'], 'losing a link is not a change of the log');

        $this->expectException(TpNotFound::class);
        $this->expectExceptionMessage('Plan not found');
        $this->get($plan['id']);
    }

    public function testALogOfAStopThatIsReplacedLosesItsLinkToTheStopOnly(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $plan = $this->create(self::blueprintBody($office, $taproom, '2026-10-01'));
        [$a, $b] = array_column($plan['stops'], 'id');
        $base = ['service_date' => '2026-10-01', 'open_minute' => 660, 'close_minute' => 840, 'actual_orders' => 40, 'plan_id' => $plan['id']];
        $kept = $this->world->logs->create(self::ORG, TruckWorld::TRUCK, null, $base + ['spot_id' => $office['id'], 'plan_stop_id' => $a]);
        $loose = $this->world->logs->create(self::ORG, TruckWorld::TRUCK, null, $base + ['spot_id' => $taproom['id'], 'plan_stop_id' => $b]);

        $this->world->planning->update(self::ORG, TruckWorld::truck(), TruckWorld::A(), $plan['id'], ['stops' => [
            ['id' => $a, 'kind' => 'spot', 'spot_id' => $office['id'], 'open_minute' => 660, 'close_minute' => 840],
        ]]);
        self::assertSame($a, $this->world->db->logs[$kept]['plan_stop_id']);
        self::assertNull($this->world->db->logs[$loose]['plan_stop_id']);
        self::assertSame($plan['id'], $this->world->db->logs[$loose]['plan_id']);
    }

    // ------------------------------------------------------------------------------------ snapshot states

    public function testASnapshotIsFreshUntilSomethingItWasComputedFromChanges(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $plan = $this->create(self::blueprintBody($office, $taproom));
        $evaluatedAt = $plan['evaluated_at'];
        $state = fn (?array $t = null, ?array $a = null): string => $this->get($plan['id'], $t, $a)['result_state'];

        self::assertSame('fresh', $state());
        $this->world->db->advance(3600);
        self::assertSame('fresh', $state(), 'time alone changes nothing');

        // The truck row changed after the evaluation (a profile field, an assumption).
        self::assertSame('stale', $state(['updated_at' => $this->world->db->now] + $truck));
        self::assertSame('fresh', $state(['updated_at' => $evaluatedAt] + $truck), 'changed in the same second it was evaluated: not after it');

        // Another model, seeds or dataset version.
        self::assertSame('stale', $state(null, ['seeds_revision' => Seeds::revision() + 1] + $A));
        self::assertSame('stale', $state(null, ['model_version' => 'tps-9.9.9'] + $A));
        $this->world->region->version = 'mini-20270105-bbbb2222';
        self::assertSame('stale', $state(), 'the region has another dataset now');
        $this->world->region->version = FixtureRegion::VERSION;
        self::assertSame('fresh', $state());

        // A spot the plan refers to changed; a spot it does not refer to is none of its business.
        $elsewhere = $this->world->spot(['name' => 'Elsewhere', 'point' => FixtureRegion::LONE]);
        $this->world->spotService->update(self::ORG, $truck, $elsewhere['id'], ['terms' => ['fee_pct' => 0.2]]);
        self::assertSame('fresh', $state());
        $this->world->spotService->update(self::ORG, $truck, $taproom['id'], ['terms' => ['fee_pct' => 0.1]]);
        self::assertSame('stale', $state());
        $stale = $this->get($plan['id']);
        self::assertNotNull($stale['result'], 'a stale snapshot is still shown');
        self::assertSame($plan['result'], $stale['result']);
        self::assertSame($evaluatedAt, $stale['evaluated_at']);

        // Evaluated again it is fresh, with the fee in it.
        $again = $this->world->planning->evaluateStored(self::ORG, $truck, $A, $plan['id']);
        self::assertSame('fresh', $again['result_state']);
        self::assertGreaterThan(0.0, $again['result']['stops'][1]['money']['spot_fee']['value']);

        // A logged service changed.
        $this->world->db->advance(60);
        self::assertSame('fresh', $state());
        $log = $this->world->logs->create(self::ORG, TruckWorld::TRUCK, null, ['spot_id' => $office['id'], 'service_date' => '2026-10-01', 'open_minute' => 660, 'close_minute' => 840, 'actual_orders' => 52]);
        self::assertSame('stale', $state());
        $this->world->planning->evaluateStored(self::ORG, $truck, $A, $plan['id']);
        self::assertSame('fresh', $state());
        $this->world->db->advance(60);
        $this->world->logs->update($log, self::ORG, ['actual_orders' => 60]);
        self::assertSame('stale', $state());
        self::assertSame('stale', $this->world->planning->resultState($this->world->plans->find($plan['id'], self::ORG), $truck, $A));
        // The other tenant's logs are not this truck's.
        $this->world->planning->evaluateStored(self::ORG, $truck, $A, $plan['id']);
        $this->world->db->advance(60);
        $this->world->logs->create(TruckWorld::OTHER_ORG, TruckWorld::OTHER_TRUCK, null, ['spot_id' => $office['id'], 'service_date' => '2026-10-01', 'open_minute' => 660, 'close_minute' => 840, 'actual_orders' => 1]);
        self::assertSame('fresh', $state());
    }

    public function testASnapshotWithGoogleLegsExpiresAfterThirtyDaysAndTheNextListingEmptiesIt(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $plan = $this->create(self::blueprintBody($office, $taproom) + ['name' => 'Kept', 'notes' => 'Mine']);
        // A day whose legs are straight-line estimates holds nothing of Google's.
        $lone = $this->world->spot(['name' => 'Lone venue', 'point' => FixtureRegion::LONE]);
        $straight = $this->create(['date' => '2026-10-09', 'stops' => [['kind' => 'spot', 'spot_id' => $lone['id'], 'open_minute' => 660, 'close_minute' => 840]]]);
        self::assertTrue($plan['context']['uses_google_legs']);
        self::assertFalse($straight['context']['uses_google_legs']);
        $updatedAt = $this->world->db->plans[$plan['id']]['updated_at'];

        // 30 days and a second less: still shown.
        $this->world->db->advance(30 * 86400 - 1);
        self::assertSame('fresh', $this->get($plan['id'])['result_state']);

        // 31 days after the evaluation: expired, and not shown although it is still stored.
        $this->world->db->advanceDays(1);
        $expired = $this->get($plan['id']);
        self::assertSame('expired', $expired['result_state']);
        self::assertNull($expired['result']);
        self::assertNull($expired['context']);
        self::assertSame($plan['evaluated_at'], $expired['evaluated_at']);
        self::assertSame($plan['stops'], $expired['stops'], 'the plan itself is the owner\'s and stays');
        self::assertNotNull($this->world->db->plans[$plan['id']]['result_json']);

        // The next listing empties it. Anything listed after that has no snapshot.
        $rows = $this->world->planning->list(self::ORG, $truck, $A, ['from' => self::THURSDAY, 'to' => '2026-10-09', 'stops' => '1']);
        self::assertSame(['none', 'fresh'], array_column($rows, 'result_state'));
        self::assertNull($rows[0]['summary']);
        self::assertNull($rows[0]['evaluated_at']);
        self::assertNotNull($rows[1]['summary'], 'a snapshot without Google legs is kept');
        $row = $this->world->db->plans[$plan['id']];
        self::assertNull($row['result_json']);
        self::assertNull($row['context_json']);
        self::assertNull($row['evaluated_at']);
        self::assertSame(0, $row['result_has_google']);
        self::assertSame('Kept', $row['name']);
        self::assertSame('Mine', $row['notes']);
        self::assertSame($updatedAt, $row['updated_at'], 'emptying a snapshot is not a change of the plan');
        self::assertCount(2, array_filter($this->world->db->stops, static fn (array $stop): bool => $stop['plan_id'] === $plan['id']));
        self::assertNotNull($this->world->db->plans[$straight['id']]['result_json']);

        $none = $this->get($plan['id']);
        self::assertSame('none', $none['result_state']);
        self::assertNull($none['result']);
        self::assertNull($none['evaluated_at']);

        // Asked again, it is evaluated again.
        $again = $this->world->planning->evaluateStored(self::ORG, $truck, $A, $plan['id']);
        self::assertSame('fresh', $again['result_state']);
        self::assertSame(574, $again['result']['timeline']['start_prep']);
    }

    public function testTheListingEmptiesOnlyTheOrganizationsOwnSnapshots(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $plan = $this->create(self::blueprintBody($office, $taproom));
        $this->world->db->advanceDays(31);
        self::assertSame([], $this->world->planning->list(TruckWorld::OTHER_ORG, TruckWorld::truck(['id' => TruckWorld::OTHER_TRUCK]), TruckWorld::A(), []));
        self::assertNotNull($this->world->db->plans[$plan['id']]['result_json']);
        self::assertSame('expired', $this->get($plan['id'])['result_state']);
    }

    // ------------------------------------------------------------------------------------ the list

    public function testTheListCarriesStateAndSummaryAndOnRequestTheStops(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $thursday = $this->create(self::blueprintBody($office, $taproom) + ['name' => 'Thursday', 'status' => 'planned', 'notes' => 'n']);
        $monday = $this->create(['date' => '2026-10-05', 'treat_as' => 'holiday']);
        $far = $this->create(['date' => '2026-12-24']);
        $this->world->contexts->asked = [];
        $this->world->legs->calls = [];

        // Default range: a week back and three weeks ahead of today in the truck's zone (2026-10-05).
        $rows = $this->world->planning->list(self::ORG, $truck, $A, []);
        self::assertSame([$monday['id'], $thursday['id']], array_column($rows, 'id'), 'by date');
        self::assertSame(
            ['id', 'date', 'name', 'treat_as', 'status', 'stop_count', 'result_state', 'summary', 'updated_at'],
            array_keys($rows[1])
        );
        self::assertSame(
            ['id' => $thursday['id'], 'date' => self::THURSDAY, 'name' => 'Thursday', 'treat_as' => null, 'status' => 'planned', 'stop_count' => 2, 'result_state' => 'fresh'],
            array_slice($rows[1], 0, 7, true)
        );
        self::assertSame(
            ['orders' => $thursday['result']['totals']['orders'], 'take_home' => $thursday['result']['totals']['take_home'], 'day_hours' => $thursday['result']['totals']['day_hours']],
            $rows[1]['summary']
        );
        self::assertSame(0, $rows[0]['stop_count']);
        self::assertSame('holiday', $rows[0]['treat_as']);
        self::assertSame([], $this->world->contexts->asked, 'listing evaluates nothing');
        self::assertSame([], $this->world->legs->calls);

        // With stops: a Plan without result and context.
        $rows = $this->world->planning->list(self::ORG, $truck, $A, ['from' => self::THURSDAY, 'to' => self::THURSDAY, 'stops' => '1']);
        self::assertCount(1, $rows);
        self::assertSame(
            ['id', 'date', 'name', 'treat_as', 'notes', 'status', 'stops', 'stop_count', 'result_state', 'evaluated_at', 'summary',
                'maps_route_url', 'created_at', 'updated_at'],
            array_keys($rows[0])
        );
        self::assertSame($thursday['stops'], $rows[0]['stops']);
        self::assertSame($thursday['maps_route_url'], $rows[0]['maps_route_url']);
        self::assertSame($thursday['evaluated_at'], $rows[0]['evaluated_at']);
        self::assertSame('n', $rows[0]['notes']);

        // The ends of a range count, and 92 dates are the most.
        self::assertSame([$far['id']], array_column($this->world->planning->list(self::ORG, $truck, $A, ['from' => '2026-12-24', 'to' => '2027-03-25']), 'id'));
        self::assertSame([], $this->world->planning->list(self::ORG, $truck, $A, ['from' => '2026-12-25', 'to' => '2026-12-25', 'stops' => '0']));
        self::assertInvalid('The date range must be at most 92 days', null, null, fn () => $this->world->planning->list(self::ORG, $truck, $A, ['from' => '2026-12-24', 'to' => '2027-03-26']));
        self::assertInvalid('to must not be before from', 'to', null, fn () => $this->world->planning->list(self::ORG, $truck, $A, ['from' => '2026-10-09', 'to' => self::THURSDAY]));
        self::assertInvalid('from must be a date in the form YYYY-MM-DD', 'from', 'V7', fn () => $this->world->planning->list(self::ORG, $truck, $A, ['from' => '10/08/2026']));
        self::assertInvalid('stops must be true or false', 'stops', 'V6', fn () => $this->world->planning->list(self::ORG, $truck, $A, ['stops' => 'yes']));

        // A stale row still has its summary; the other tenant sees nothing.
        self::assertSame('stale', $this->world->planning->list(self::ORG, ['updated_at' => '2027-01-01 00:00:00'] + $truck, $A, [])[1]['result_state']);
        self::assertNotNull($this->world->planning->list(self::ORG, ['updated_at' => '2027-01-01 00:00:00'] + $truck, $A, [])[1]['summary']);
        self::assertSame([], $this->world->planning->list(TruckWorld::OTHER_ORG, TruckWorld::truck(['id' => TruckWorld::OTHER_TRUCK]), $A, []));
    }

    // ------------------------------------------------------------------------------------ preview

    public function testAPreviewStoresNothing(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $body = self::blueprintBody($office, $taproom) + ['name' => 'ignored', 'status' => 'not a status', 'notes' => 17];
        $body['stops'][0]['id'] = 'lunch';
        $body['stops'][1]['id'] = 'lunch';

        $answer = $this->world->planning->preview(self::ORG, $truck, $A, $body);
        self::assertSame(['result', 'context'], array_keys($answer));
        self::assertSame([], $this->world->db->plans);
        self::assertSame([], $this->world->db->stops);
        self::assertSame(['lunch', 'stop:2'], array_column($answer['result']['stops'], 'id'), 'a sent id is kept once; a stop without one is called by its position');
        self::assertSame(574, $answer['result']['timeline']['start_prep']);
        self::assertSame(1251, $answer['result']['timeline']['done']);
        self::assertSame('base', $answer['context']['legs'][0]['from_id']);
        self::assertSame('lunch', $answer['context']['legs'][0]['to_id']);

        // The same body twice gives the same answer; "base" and odd ids are not taken as ids.
        self::assertSame($answer, $this->world->planning->preview(self::ORG, $truck, $A, $body));
        $body['stops'][0]['id'] = 'base';
        $body['stops'][1]['id'] = 'a>b';
        self::assertSame(['stop:1', 'stop:2'], array_column($this->world->planning->preview(self::ORG, $truck, $A, $body)['result']['stops'], 'id'));

        // It is the result a save would store.
        $saved = $this->create(self::blueprintBody($office, $taproom));
        self::assertSame($answer['result']['totals'], $saved['result']['totals']);
        self::assertSame($answer['result']['timeline']['events'], $saved['result']['timeline']['events']);

        self::assertInvalid('date is required', 'date', 'V1', fn () => $this->world->planning->preview(self::ORG, $truck, $A, ['stops' => []]));
    }

    // ------------------------------------------------------------------------------------ spots behind the stops

    public function testAStaleSpotIsComputedAgainAndAnArchivedOneStillServes(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $truck = TruckWorld::truck();
        $this->world->spotService->archive(self::ORG, $office['id']);
        $captures = $this->world->region->count('capture');

        $plan = $this->create(self::blueprintBody($office, $taproom));
        self::assertSame($captures, $this->world->region->count('capture'), 'fresh vectors are used as they are');
        self::assertEqualsWithDelta(60.49, $plan['result']['stops'][0]['orders']['value'], 0.005);

        // Another dataset becomes active, with twice the people: the plan is stale, and evaluating it
        // computes the vectors of its spots again first.
        $this->world->region->switchTo('mini-20270105-bbbb2222', 2.0);
        self::assertSame('stale', $this->get($plan['id'])['result_state']);
        $again = $this->world->planning->evaluateStored(self::ORG, $truck, TruckWorld::A(), $plan['id']);
        self::assertSame($captures + 2, $this->world->region->count('capture'));
        self::assertSame('mini-20270105-bbbb2222', $again['context']['dataset_version']);
        self::assertSame('mini-20270105-bbbb2222', $this->world->db->spots->rows[$office['id']]['vec_dataset']);
        self::assertGreaterThan($plan['result']['stops'][0]['orders']['value'], $again['result']['stops'][0]['orders']['value']);
        self::assertSame('fresh', $again['result_state'], 'the spots were written before the snapshot');
        self::assertNotContains('stale_vectors', array_column($again['result']['warnings'], 'code'));
    }

    public function testWhileTheRegionDataDoesNotFitNothingIsStored(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $plan = $this->create(self::blueprintBody($office, $taproom));

        // Fresh spots need nothing from the region: the plan can still be saved.
        $this->world->region->usable = false;
        self::assertSame('fresh', $this->world->planning->update(self::ORG, $truck, $A, $plan['id'], ['name' => 'Still fine'])['result_state']);

        // A spot that needs new vectors cannot get them: 409, and nothing of the change is written.
        $this->world->db->spots->rows[$taproom['id']]['vec_seeds_rev'] = Seeds::revision() + 1;
        $before = [$this->world->db->plans, $this->world->db->stops];
        foreach ([
            fn () => $this->world->planning->update(self::ORG, $truck, $A, $plan['id'], ['name' => 'Not saved']),
            fn () => $this->world->planning->evaluateStored(self::ORG, $truck, $A, $plan['id']),
            fn () => $this->create(self::blueprintBody($office, $taproom, '2026-10-09')),
            fn () => $this->world->planning->preview(self::ORG, $truck, $A, self::blueprintBody($office, $taproom)),
        ] as $call) {
            try {
                $call();
                self::fail('the plan was evaluated on data that does not fit');
            } catch (TpConflict $e) {
                self::assertSame(FixtureRegion::MISMATCH, $e->getMessage());
            }
        }
        self::assertSame($before, [$this->world->db->plans, $this->world->db->stops]);
    }

    // ------------------------------------------------------------------------------------ tenants

    public function testAnotherTenantsPlanIsNotFound(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $plan = $this->create(self::blueprintBody($office, $taproom));
        $theirTruck = TruckWorld::truck(['id' => TruckWorld::OTHER_TRUCK]);
        $A = TruckWorld::A();
        $before = [$this->world->db->plans, $this->world->db->stops];

        $calls = [
            fn () => $this->world->planning->get(TruckWorld::OTHER_ORG, $theirTruck, $A, $plan['id']),
            fn () => $this->world->planning->update(TruckWorld::OTHER_ORG, $theirTruck, $A, $plan['id'], ['name' => 'Taken over']),
            fn () => $this->world->planning->delete(TruckWorld::OTHER_ORG, $plan['id']),
            fn () => $this->world->planning->evaluateStored(TruckWorld::OTHER_ORG, $theirTruck, $A, $plan['id']),
            fn () => $this->world->planning->get(self::ORG, TruckWorld::truck(), $A, '00000000-0000-4000-8000-000000000000'),
            fn () => $this->world->planning->get(self::ORG, TruckWorld::truck(), $A, ''),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('a plan of another organization was reached');
            } catch (TpNotFound $e) {
                self::assertSame('Plan not found', $e->getMessage());
            }
        }
        self::assertSame($before, [$this->world->db->plans, $this->world->db->stops]);

        // Its spots cannot be planned by another organization either.
        self::assertInvalid(
            'stops[0].spot_id was not found',
            'stops[0].spot_id',
            'V11',
            fn () => $this->world->planning->create(TruckWorld::OTHER_ORG, $theirTruck, $A, null, self::blueprintBody($office, $taproom))
        );
        self::assertInvalid(
            'stops[1].spot_id was not found',
            'stops[1].spot_id',
            'V11',
            fn () => $this->world->planning->preview(TruckWorld::OTHER_ORG, $theirTruck, $A, ['date' => self::THURSDAY, 'stops' => [
                ['kind' => 'catering', 'point' => FixtureRegion::OFFICE, 'open_minute' => 600, 'close_minute' => 660, 'catering' => ['headcount' => 10, 'guarantee' => 100]],
                ['kind' => 'spot', 'spot_id' => $taproom['id'], 'open_minute' => 720, 'close_minute' => 780],
            ]])
        );

        // Every statement the service sent was scoped to the caller's organization.
        foreach ($this->world->db->statements as $sql) {
            if (preg_match('/\b(tp_plans|tp_plan_stops|tp_service_logs|tp_spots)\b/', $sql) === 1) {
                self::assertStringContainsString('organization_id', $sql, $sql);
            }
        }
    }

    // ------------------------------------------------------------------------------------ validation

    public function testABodyIsValidatedFieldByField(): void
    {
        $office = $this->world->office();
        $truck = TruckWorld::truck();
        $A = TruckWorld::A();
        $create = fn (array $body) => fn () => $this->world->planning->create(self::ORG, $truck, $A, null, $body);
        $spot = ['kind' => 'spot', 'spot_id' => $office['id'], 'open_minute' => 660, 'close_minute' => 840];
        $event = ['kind' => 'event', 'point' => FixtureRegion::OFFICE, 'open_minute' => 660, 'close_minute' => 840, 'event' => ['attendance' => 500, 'vendors' => 2, 'event_type' => 'general']];
        $catering = ['kind' => 'catering', 'point' => FixtureRegion::OFFICE, 'open_minute' => 660, 'close_minute' => 840, 'catering' => ['headcount' => 50, 'guarantee' => 500]];
        $with = static fn (array $stop, array $changes): array => ['date' => self::THURSDAY, 'stops' => [$spot, array_replace_recursive($stop, $changes)]];
        $without = static function (array $stop, string $key) use ($spot): array {
            unset($stop[$key]);
            return ['date' => self::THURSDAY, 'stops' => [$spot, $stop]];
        };

        // The plan itself.
        self::assertInvalid('date is required', 'date', 'V1', $create([]));
        self::assertInvalid('date must be a date in the form YYYY-MM-DD', 'date', 'V7', $create(['date' => '2026-02-30']));
        self::assertInvalid('date must be a date in the form YYYY-MM-DD', 'date', 'V7', $create(['date' => '2199-12-31']));
        self::assertInvalid('name must be text of at most 120 characters', 'name', 'V5', $create(['date' => self::THURSDAY, 'name' => str_repeat('n', 121)]));
        self::assertInvalid('notes must be text of at most 4000 characters', 'notes', 'V5', $create(['date' => self::THURSDAY, 'notes' => str_repeat('n', 4001)]));
        self::assertInvalid(
            'treat_as must be one of: normal, holiday, mon, tue, wed, thu, fri, sat, sun',
            'treat_as',
            'V4',
            $create(['date' => self::THURSDAY, 'treat_as' => 'saturday'])
        );
        self::assertInvalid('status must be one of: draft, planned, done, cancelled', 'status', 'V4', $create(['date' => self::THURSDAY, 'status' => 'open']));
        self::assertInvalid('stops must be a list of 0 to 8 items', 'stops', 'V8', $create(['date' => self::THURSDAY, 'stops' => array_fill(0, 9, $spot)]));
        self::assertInvalid('stops must be a list of 0 to 8 items', 'stops', 'V8', $create(['date' => self::THURSDAY, 'stops' => ['a' => $spot]]));

        // Every stop.
        self::assertInvalid('stops[1] must be an object', 'stops[1]', 'V9', $create(['date' => self::THURSDAY, 'stops' => [$spot, 'lunch']]));
        self::assertInvalid('stops[1] is required', 'stops[1]', 'V1', $create(['date' => self::THURSDAY, 'stops' => [$spot, null]]));
        self::assertInvalid('stops[1].id must be text of at most 36 characters', 'stops[1].id', 'V5', $create($with($spot, ['id' => 7])));
        self::assertInvalid('stops[1].kind is required', 'stops[1].kind', 'V1', $create($without($spot, 'kind')));
        self::assertInvalid('stops[1].kind must be one of: spot, event, catering', 'stops[1].kind', 'V4', $create($with($spot, ['kind' => 'market'])));
        self::assertInvalid('stops[1].spot_id is required', 'stops[1].spot_id', 'V1', $create($without($spot, 'spot_id')));
        self::assertInvalid('stops[1].spot_id must be text of at most 36 characters', 'stops[1].spot_id', 'V5', $create($with($spot, ['spot_id' => str_repeat('x', 37)])));
        self::assertInvalid('stops[1].spot_id was not found', 'stops[1].spot_id', 'V11', $create($with($spot, ['spot_id' => '00000000-0000-4000-8000-000000000000'])));
        self::assertInvalid('stops[1].open_minute is required', 'stops[1].open_minute', 'V1', $create($without($spot, 'open_minute')));
        self::assertInvalid('stops[1].open_minute must be a whole number between 0 and 2880', 'stops[1].open_minute', 'V3', $create($with($spot, ['open_minute' => 660.5])));
        self::assertInvalid('stops[1].close_minute must be a whole number between 0 and 2880', 'stops[1].close_minute', 'V3', $create($with($spot, ['close_minute' => 2881])));
        self::assertInvalid('stops[1].close_minute must be after open_minute', 'stops[1].close_minute', null, $create($with($spot, ['close_minute' => 660])));
        self::assertInvalid('stops[1].gap_before_unpaid must be true or false', 'stops[1].gap_before_unpaid', 'V6', $create($with($spot, ['gap_before_unpaid' => 1])));
        self::assertInvalid('stops[1].setup_minutes must be a whole number between 0 and 240', 'stops[1].setup_minutes', 'V3', $create($with($spot, ['setup_minutes' => 241])));
        self::assertInvalid('stops[1].teardown_minutes must be a whole number between 0 and 240', 'stops[1].teardown_minutes', 'V3', $create($with($spot, ['teardown_minutes' => -1])));

        // Events.
        self::assertInvalid('stops[1].point is required', 'stops[1].point', 'V1', $create($without($event, 'point')));
        self::assertInvalid(
            'stops[1].point must have lat between -90 and 90 and lng between -180 and 180',
            'stops[1].point',
            'V10',
            $create($with($event, ['point' => ['lat' => 91, 'lng' => 0]]))
        );
        self::assertInvalid('stops[1].label must be text of at most 120 characters', 'stops[1].label', 'V5', $create($with($event, ['label' => str_repeat('l', 121)])));
        self::assertInvalid('stops[1].address must be text of at most 255 characters', 'stops[1].address', 'V5', $create($with($event, ['address' => str_repeat('a', 256)])));
        self::assertInvalid('stops[1].fee_flat must be a number between 0 and 100000', 'stops[1].fee_flat', 'V2', $create($with($event, ['fee_flat' => -1])));
        self::assertInvalid('stops[1].fee_min must be a number between 0 and 100000', 'stops[1].fee_min', 'V2', $create($with($event, ['fee_min' => '75'])));
        self::assertInvalid('stops[1].fee_pct must be a number between 0 and 1', 'stops[1].fee_pct', 'V2', $create($with($event, ['fee_pct' => 12])));
        self::assertInvalid('stops[1].event is required', 'stops[1].event', 'V1', $create($without($event, 'event')));
        self::assertInvalid('stops[1].event must be an object', 'stops[1].event', 'V9', $create(['date' => self::THURSDAY, 'stops' => [$spot, ['event' => 'fair'] + $event]]));
        self::assertInvalid('stops[1].event.attendance must be a number between 1 and 2000000', 'stops[1].event.attendance', 'V2', $create($with($event, ['event' => ['attendance' => 0]])));
        self::assertInvalid('stops[1].event.vendors must be a whole number between 1 and 500', 'stops[1].event.vendors', 'V3', $create($with($event, ['event' => ['vendors' => 501]])));
        self::assertInvalid(
            'stops[1].event.event_type must be one of: general, food_focused, evening_show, incidental',
            'stops[1].event.event_type',
            'V4',
            $create($with($event, ['event' => ['event_type' => 'fair']]))
        );

        // Catering.
        self::assertInvalid('stops[1].catering is required', 'stops[1].catering', 'V1', $create($without($catering, 'catering')));
        self::assertInvalid('stops[1].catering.headcount must be a number between 1 and 100000', 'stops[1].catering.headcount', 'V2', $create($with($catering, ['catering' => ['headcount' => 0.5]])));
        self::assertInvalid('stops[1].catering.price_per_head must be a number between 0 and 1000', 'stops[1].catering.price_per_head', 'V2', $create($with($catering, ['catering' => ['price_per_head' => 1001]])));
        self::assertInvalid('stops[1].catering.guarantee must be a number between 0 and 1000000', 'stops[1].catering.guarantee', 'V2', $create($with($catering, ['catering' => ['guarantee' => -5]])));
        self::assertInvalid('stops[1].catering.food_cost must be a number between 0 and 1000000', 'stops[1].catering.food_cost', 'V2', $create($with($catering, ['catering' => ['food_cost' => 'low']])));
        self::assertInvalid(
            'stops[1].catering needs price_per_head or guarantee',
            'stops[1].catering',
            null,
            $create(['date' => self::THURSDAY, 'stops' => [$spot, ['catering' => ['headcount' => 50, 'price_per_head' => null]] + $catering]])
        );

        self::assertSame([], $this->world->db->plans, 'a refused body writes nothing');
        self::assertSame([], $this->world->legs->calls, 'and evaluates nothing');
    }

    public function testOverlappingStopsAreAWarningOfTheModelNotARefusal(): void
    {
        [$office, $taproom] = $this->blueprintSpots();
        $body = self::blueprintBody($office, $taproom);
        $body['stops'][1]['open_minute'] = 800;
        $plan = $this->create($body);
        self::assertSame(['stops_overlap'], array_column($plan['result']['warnings'], 'code'));
        self::assertSame(['open_minute' => 800, 'previous_close_minute' => 840], $plan['result']['warnings'][0]['data']);
        self::assertSame([], $plan['result']['stops'], 'the day is not evaluated');
        self::assertCount(2, $plan['stops'], 'and is saved as the owner entered it');
        self::assertSame('fresh', $plan['result_state']);
    }
}

/**
 * The world the plan, log and calibration tests share: one truck of one organization on the fixture
 * region, the real repositories over in-memory tables, the real services, and doubles behind the leg and
 * day-context contracts. Building it wires the Registry; a test resets the Registry when it is done.
 */
final class TruckWorld
{
    public const ORG = SpotServiceTest::ORG;
    public const OTHER_ORG = SpotServiceTest::OTHER_ORG;
    public const USER = SpotServiceTest::USER;
    public const TRUCK = SpotServiceTest::TRUCK;
    public const OTHER_TRUCK = '77777777-7777-4777-8777-777777777777';
    public const BASE = ['lat' => 39.003, 'lng' => -77.405];

    public PlanTables $db;

    /** What was written to `tp_trucks`: the stamp of a change that leaves no time stamp of its own. */
    public RecordingDatabase $truckDb;
    public FixtureRegion $region;
    public FixtureRegions $regions;
    public FixedClock $clock;
    public FixtureLegs $legs;
    public FixtureContexts $contexts;
    public SpotRepository $spots;
    public PlanRepository $plans;
    public ServiceLogRepository $logs;
    public SpotService $spotService;
    public CalibrationService $calibration;
    public PlanningService $planning;
    public ServiceLogService $logging;

    public function __construct()
    {
        $this->db = new PlanTables();
        $this->truckDb = new RecordingDatabase();
        $this->region = FixtureRegion::standard();
        $this->regions = new FixtureRegions($this->region);
        // 16:00 UTC is noon in New York: today is Monday 2026-10-05 where the truck is.
        $this->clock = new FixedClock('2026-10-05 16:00:00');
        $this->legs = new FixtureLegs();
        $this->contexts = new FixtureContexts();
        $this->spots = new SpotRepository($this->db);
        $this->plans = new PlanRepository($this->db);
        $this->logs = new ServiceLogRepository($this->db);
        $this->spotService = new SpotService($this->spots, new CountsRepository(new RecordingDatabase()), $this->regions);
        $this->calibration = new CalibrationService($this->logs, $this->spots, $this->spotService, $this->clock);
        $this->planning = new PlanningService($this->plans, $this->spots, $this->spotService, $this->logs, $this->regions, $this->clock);
        $this->logging = new ServiceLogService($this->logs, $this->spots, $this->plans, $this->calibration, $this->clock, new TruckRepository($this->truckDb));

        Registry::reset();
        Registry::set('capture', $this->region);
        Registry::set('legs', $this->legs);
        Registry::set('dayContexts', $this->contexts);
        Registry::set('calibration', $this->calibration);
        Registry::set('fuel', new SeedFuelPrice($this->regions));
    }

    /**
     * The truck value of TruckBaseController::truck(): the default profile, based in Sterling.
     *
     * @param array<string, mixed> $changes keys of the truck value to replace; `profile` is merged
     * @return array<string, mixed>
     */
    public static function truck(array $changes = []): array
    {
        $truck = SpotServiceTest::truck();
        if (isset($changes['profile'])) {
            $truck['profile'] = array_replace($truck['profile'], $changes['profile']);
            unset($changes['profile']);
        }
        return array_replace($truck, $changes);
    }

    /**
     * The truck's Assumptions.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function A(array $overrides = []): array
    {
        return Seeds::assumptions($overrides, ['id' => FixtureRegion::REGION, 'traffic_matrix' => 'us_mean', 'flags' => ['inauguration_day' => false]]);
    }

    /**
     * Saves a spot of the truck.
     *
     * @param array<string, mixed> $body the body of route 11
     * @return array<string, mixed> Spot
     */
    public function spot(array $body, string $orgId = self::ORG, ?array $truck = null): array
    {
        return $this->spotService->create($orgId, $truck ?? self::truck(), self::USER, $body);
    }

    /**
     * Anchor A1 of 02_MODEL.md: eight blocks of 250 office jobs to the north, two outlets to the south.
     *
     * @return array<string, mixed> Spot
     */
    public function office(): array
    {
        return $this->spot(['name' => 'Office area', 'point' => FixtureRegion::OFFICE]);
    }

    /**
     * Anchor A2: a taproom of 120 people in its busiest hour where the truck is the only food. It stands
     * at the fixture's taproom, so the host link rule takes that place's own point out of the catchment.
     *
     * @return array<string, mixed> Spot
     */
    public function taproom(): array
    {
        return $this->spot([
            'name' => 'Taproom',
            'point' => FixtureRegion::TAPROOM,
            'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120, 'only_food' => true]],
        ]);
    }
}

/**
 * `tp_plans`, `tp_plan_stops` and `tp_service_logs` in memory, behind the Database interface, with the
 * SpotTable of the spot service's test for `tp_spots`. It runs the statements the Truck Planner
 * repositories write and keeps the rows as MySQL would hold them (bound values as they were bound, cents,
 * 0 and 1, JSON text). The repositories under test are the real ones.
 *
 * NOW() is `$now`, which a test moves: every table shares that one clock, as they share MySQL's.
 * `updated_at` follows a change of a row unless the statement holds it. The unique keys that the services
 * rely on are kept: one plan per truck and date, one stop per plan and position.
 */
final class PlanTables extends Database
{
    private const TABLES = ['tp_plans' => 'plans', 'tp_plan_stops' => 'stops', 'tp_service_logs' => 'logs'];

    /** @var array<string, array<string, mixed>> rows by id */
    public array $plans = [];

    /** @var array<string, array<string, mixed>> rows by id */
    public array $stops = [];

    /** @var array<string, array<string, mixed>> rows by id */
    public array $logs = [];

    public SpotTable $spots;

    /** @var list<string> every statement in order, whitespace squashed */
    public array $statements = [];

    /** The database clock: "YYYY-MM-DD HH:MM:SS". */
    public string $now = '2026-10-05 12:00:00';

    /** @var array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>}|null */
    private ?array $saved = null;

    public function __construct()
    {
        $this->spots = new SpotTable();
    }

    public function advance(int $seconds): void
    {
        $this->now = self::shifted($this->now, $seconds);
    }

    public function advanceDays(int $days): void
    {
        $this->advance($days * 86400);
    }

    public function inTransaction(): bool
    {
        return $this->saved !== null;
    }

    /**
     * The statements that start with `$prefix`.
     *
     * @return list<string>
     */
    public function writes(string $prefix): array
    {
        return array_values(array_filter($this->statements, static fn (string $sql): bool => str_starts_with($sql, $prefix)));
    }

    public function beginTransaction(): void
    {
        if ($this->saved !== null) {
            throw new \LogicException('PlanTables: there is already an active transaction');
        }
        $this->saved = [$this->plans, $this->stops, $this->logs, $this->spots->rows];
    }

    public function commit(): void
    {
        if ($this->saved === null) {
            throw new \LogicException('PlanTables: commit without a transaction');
        }
        $this->saved = null;
    }

    public function rollback(): void
    {
        if ($this->saved === null) {
            throw new \LogicException('PlanTables: rollback without a transaction');
        }
        [$this->plans, $this->stops, $this->logs, $this->spots->rows] = $this->saved;
        $this->saved = null;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $sql = $this->record($sql);
        $params = array_values($params);
        foreach ($params as $value) {
            if (is_float($value) || is_bool($value) || is_array($value)) {
                throw new \LogicException('PlanTables: a float, a boolean or an array was bound: ' . $sql);
            }
        }
        if (preg_match('/^(INSERT INTO|UPDATE) tp_spots\b/', $sql) === 1) {
            $this->spotWrite($sql, $params);
            return new \PDOStatement();
        }
        if (preg_match('/^INSERT INTO (tp_plans|tp_plan_stops|tp_service_logs) \((.+?)\) VALUES \((.+)\)$/', $sql, $m) === 1) {
            $columns = explode(', ', $m[2]);
            $expressions = explode(', ', $m[3]);
            if (count($columns) !== count($expressions)) {
                throw new \LogicException('PlanTables: columns and values differ in number');
            }
            $row = [];
            foreach ($columns as $i => $column) {
                $row[$column] = $expressions[$i] === 'NOW()' ? $this->now : $this->value($expressions[$i], $params);
            }
            $this->done($params, $sql);
            $this->addRow($m[1], $row);
            return new \PDOStatement();
        }
        if (preg_match('/^UPDATE (tp_plans|tp_service_logs) SET (.+) WHERE (.+)$/', $sql, $m) === 1) {
            $changes = [];
            foreach (explode(', ', $m[2]) as $assignment) {
                [$column, $expression] = explode(' = ', $assignment, 2);
                $changes[$column] = $expression === $column ? ['keep'] : ($expression === 'NOW()' ? $this->now : $this->value($expression, $params));
            }
            $matches = $this->where($m[3], $params);
            $this->done($params, $sql);
            $table = self::TABLES[$m[1]];
            foreach ($this->{$table} as $id => $row) {
                if (!$matches($row)) {
                    continue;
                }
                $next = $row;
                foreach ($changes as $column => $value) {
                    if ($value !== ['keep']) {
                        $next[$column] = $value;
                    }
                }
                if ($next !== $row && !array_key_exists('updated_at', $changes)) {
                    $next['updated_at'] = $this->now;                 // ON UPDATE CURRENT_TIMESTAMP
                }
                if ($m[1] === 'tp_plans') {
                    $this->refuseSecondPlan($next, (string) $id);
                }
                $this->{$table}[$id] = $next;
            }
            return new \PDOStatement();
        }
        if (preg_match('/^DELETE FROM (tp_plans|tp_plan_stops|tp_service_logs) WHERE (.+)$/', $sql, $m) === 1) {
            $matches = $this->where($m[2], $params);
            $this->done($params, $sql);
            $table = self::TABLES[$m[1]];
            foreach ($this->{$table} as $id => $row) {
                if ($matches($row)) {
                    unset($this->{$table}[$id]);
                }
            }
            return new \PDOStatement();
        }
        throw new \LogicException('PlanTables: unexpected statement: ' . $sql);
    }

    public function fetch(string $sql, array $params = []): ?array
    {
        if ($this->isSpotRead($sql)) {
            $this->statements[] = self::squash($sql);
            return $this->spots->fetch($sql, $params);
        }
        $rows = $this->select($this->record($sql), array_values($params));
        return $rows[0] ?? null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        if ($this->isSpotRead($sql)) {
            $this->statements[] = self::squash($sql);
            return $this->spots->fetchAll($sql, $params);
        }
        return $this->select($this->record($sql), array_values($params));
    }

    // ---- reads

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, array $params): array
    {
        if (preg_match('/^SELECT (.+) FROM tp_plans p WHERE (.+?)(?: ORDER BY p\.service_date, p\.id)?$/', $sql, $m) === 1) {
            $ttl = (int) array_shift($params);
            $matches = $this->where($m[2], $params);
            $this->done($params, $sql);
            $out = [];
            foreach ($this->plans as $row) {
                if (!$matches($row)) {
                    continue;
                }
                if (!str_contains($m[1], 'p.context_json')) {
                    unset($row['context_json']);
                }
                $row['has_snapshot'] = $row['result_json'] === null ? 0 : 1;
                $row['snapshot_expired'] = $row['evaluated_at'] === null
                    ? null
                    : (int) ($row['result_has_google'] === 1 && strcmp($row['evaluated_at'], self::shifted($this->now, -$ttl * 86400)) < 0);
                $row['stop_count'] = 0;
                $row['spots_changed_at'] = null;
                foreach ($this->stops as $stop) {
                    if ($stop['plan_id'] !== $row['id'] || $stop['organization_id'] !== $row['organization_id']) {
                        continue;
                    }
                    $row['stop_count']++;
                    $spot = $stop['spot_id'] === null ? null : ($this->spots->rows[$stop['spot_id']] ?? null);
                    if ($spot !== null && $spot['organization_id'] === $stop['organization_id']
                        && ($row['spots_changed_at'] === null || strcmp($spot['updated_at'], $row['spots_changed_at']) > 0)) {
                        $row['spots_changed_at'] = $spot['updated_at'];
                    }
                }
                $out[] = $row;
            }
            usort($out, static fn (array $a, array $b): int => strcmp($a['service_date'], $b['service_date']) ?: strcmp($a['id'], $b['id']));
            return $out;
        }
        if (preg_match('/^SELECT (.+) FROM (tp_plans|tp_plan_stops|tp_service_logs) WHERE (.+?)(?: ORDER BY (.+?))?( LIMIT 1)?$/', $sql, $m) === 1) {
            $matches = $this->where($m[3], $params);
            $this->done($params, $sql);
            $rows = array_values(array_filter($this->{self::TABLES[$m[2]]}, $matches));
            switch ($m[4] ?? '') {
                case '':
                    break;
                case 'plan_id, seq':
                    usort($rows, static fn (array $a, array $b): int => strcmp($a['plan_id'], $b['plan_id']) ?: ($a['seq'] <=> $b['seq']));
                    break;
                case 'service_date, id':
                    usort($rows, static fn (array $a, array $b): int => strcmp($a['service_date'], $b['service_date']) ?: strcmp($a['id'], $b['id']));
                    break;
                case 'service_date DESC, id ASC':
                    usort($rows, static fn (array $a, array $b): int => strcmp($b['service_date'], $a['service_date']) ?: strcmp($a['id'], $b['id']));
                    break;
                default:
                    throw new \LogicException('PlanTables: unexpected order: ' . $m[4]);
            }
            if ($m[1] === 'COUNT(*) AS expired') {
                return [['expired' => count($rows)]];
            }
            if ($m[1] === 'MAX(updated_at) AS changed_at') {
                return [['changed_at' => $rows === [] ? null : max(array_column($rows, 'updated_at'))]];
            }
            $out = [];
            foreach ($rows as $row) {
                $picked = [];
                foreach (explode(', ', $m[1]) as $column) {
                    if ($column === 'SHA1(weather_json) AS weather_sha1') {
                        $picked['weather_sha1'] = $row['weather_json'] === null ? null : sha1($row['weather_json']);
                    } elseif (array_key_exists($column, $row)) {
                        $picked[$column] = $row[$column];
                    } else {
                        throw new \LogicException('PlanTables: unknown column ' . $column . ' in: ' . $sql);
                    }
                }
                $out[] = $picked;
            }
            return ($m[5] ?? '') === '' ? $out : array_slice($out, 0, 1);
        }
        throw new \LogicException('PlanTables: unexpected statement: ' . $sql);
    }

    private function isSpotRead(string $sql): bool
    {
        return preg_match('/\bFROM\s+tp_spots\b/', $sql) === 1 && preg_match('/\bFROM\s+tp_plans\b/', $sql) !== 1;
    }

    // ---- writes

    /**
     * @param array<string, mixed> $row
     */
    private function addRow(string $table, array $row): void
    {
        $name = self::TABLES[$table];
        $id = (string) $row['id'];
        if (isset($this->{$name}[$id])) {
            throw new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key PRIMARY');
        }
        if ($table === 'tp_plans') {
            $row += [
                'result_json' => null, 'context_json' => null, 'result_has_google' => 0, 'evaluated_at' => null,
                'model_version' => null, 'seeds_revision' => null, 'dataset_version' => null,
            ];
            $this->refuseSecondPlan($row, $id);
        }
        if ($table === 'tp_plan_stops') {
            foreach ($this->stops as $stop) {
                if ($stop['plan_id'] === $row['plan_id'] && $stop['seq'] === $row['seq']) {
                    throw new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key uk_tpps_plan_seq');
                }
            }
        }
        $this->{$name}[$id] = $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function refuseSecondPlan(array $row, string $id): void
    {
        foreach ($this->plans as $otherId => $other) {
            if ((string) $otherId !== $id && $other['truck_id'] === $row['truck_id'] && $other['service_date'] === $row['service_date']) {
                throw new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry for key uk_tppl_truck_date');
            }
        }
    }

    /**
     * A write on `tp_spots`: the spot table runs it, and what it stamped is stamped with this clock.
     *
     * @param list<mixed> $params
     */
    private function spotWrite(string $sql, array $params): void
    {
        $before = $this->spots->rows;
        $this->spots->query($sql, $params);
        foreach ($this->spots->rows as $id => $row) {
            $old = $before[$id] ?? null;
            if ($old === $row) {
                continue;
            }
            $row['updated_at'] = $this->now;
            if ($old === null) {
                $row['created_at'] = $this->now;
            }
            foreach (['vec_at', 'archived_at'] as $stamp) {
                if (($row[$stamp] ?? null) !== null && ($old === null || $row[$stamp] !== ($old[$stamp] ?? null))) {
                    $row[$stamp] = $this->now;
                }
            }
            $this->spots->rows[$id] = $row;
        }
    }

    // ---- the small part of SQL the repositories use

    /**
     * A WHERE clause as a test of a row. The bound values it uses are taken off the front of `$params`.
     *
     * @param list<mixed> $params
     * @return callable(array<string, mixed>): bool
     */
    private function where(string $clause, array &$params): callable
    {
        $clause = (string) preg_replace('/\bp\./', '', $clause);
        $clause = str_replace('BETWEEN ? AND ?', 'BETWEEN ?~?', $clause);
        $tests = [];
        foreach (explode(' AND ', $clause) as $condition) {
            if (preg_match('/^(\w+) (=|<>) \?$/', $condition, $m) === 1) {
                $value = $this->value('?', $params);
                $equal = $m[2] === '=';
                $tests[] = static fn (array $row): bool => $row[$m[1]] !== null && (((string) $row[$m[1]] === (string) $value) === $equal);
            } elseif (preg_match('/^(\w+) = (\d+)$/', $condition, $m) === 1) {
                $tests[] = static fn (array $row): bool => $row[$m[1]] !== null && (int) $row[$m[1]] === (int) $m[2];
            } elseif (preg_match('/^(\w+) IS NOT NULL$/', $condition, $m) === 1) {
                $tests[] = static fn (array $row): bool => $row[$m[1]] !== null;
            } elseif (preg_match('/^(\w+) (NOT )?IN \(([?, ]+)\)$/', $condition, $m) === 1) {
                $values = [];
                for ($i = substr_count($m[3], '?'); $i > 0; $i--) {
                    $values[] = (string) $this->value('?', $params);
                }
                $inside = $m[2] === '';
                $tests[] = static fn (array $row): bool => $row[$m[1]] !== null && (in_array((string) $row[$m[1]], $values, true) === $inside);
            } elseif (preg_match('/^(\w+) BETWEEN \?~\?$/', $condition, $m) === 1) {
                $from = (string) $this->value('?', $params);
                $to = (string) $this->value('?', $params);
                $tests[] = static fn (array $row): bool => strcmp((string) $row[$m[1]], $from) >= 0 && strcmp((string) $row[$m[1]], $to) <= 0;
            } elseif ($condition === 'evaluated_at < NOW() - INTERVAL ? DAY') {
                $limit = self::shifted($this->now, -86400 * (int) $this->value('?', $params));
                $tests[] = static fn (array $row): bool => $row['evaluated_at'] !== null && strcmp($row['evaluated_at'], $limit) < 0;
            } else {
                throw new \LogicException('PlanTables: unexpected condition: ' . $condition);
            }
        }
        return static function (array $row) use ($tests): bool {
            foreach ($tests as $test) {
                if (!$test($row)) {
                    return false;
                }
            }
            return true;
        };
    }

    /**
     * @param list<mixed> $params the values not yet used; a used one is taken off the front
     */
    private function value(string $expression, array &$params): mixed
    {
        if ($expression === 'NULL') {
            return null;
        }
        if (preg_match('/^\d+$/', $expression) === 1) {
            return (int) $expression;
        }
        if ($expression !== '?') {
            throw new \LogicException('PlanTables: unexpected value expression: ' . $expression);
        }
        if ($params === []) {
            throw new \LogicException('PlanTables: more placeholders than values');
        }
        return array_shift($params);
    }

    /**
     * @param list<mixed> $params
     */
    private function done(array $params, string $sql): void
    {
        if ($params !== []) {
            throw new \LogicException('PlanTables: more values than placeholders: ' . $sql);
        }
    }

    private function record(string $sql): string
    {
        $squashed = self::squash($sql);
        $this->statements[] = $squashed;
        return $squashed;
    }

    private static function squash(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private static function shifted(string $stamp, int $seconds): string
    {
        $utc = new \DateTimeZone('UTC');
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $stamp, $utc);
        if ($at === false) {
            throw new \LogicException('PlanTables: not a time stamp: ' . $stamp);
        }
        return $at->setTimestamp($at->getTimestamp() + $seconds)->format('Y-m-d H:i:s');
    }
}

/**
 * The leg provider of the tests. A pair that a test fixed is answered the way the blueprint's day sheet
 * states its drives: minutes the owner set, on a routed leg of the given length. Every other pair is the
 * labelled straight-line estimate, as it is without a Google key.
 */
final class FixtureLegs implements LegProvider
{
    /** @var list<array{0: string, 1: list<array<string, mixed>>, 2: list<array{0: string, 1: string}>, 3: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array{0: int, 1: float, 2: string}> "<from key>><to key>" => [minutes, miles, source] */
    private array $fixed = [];

    /**
     * @param array{lat: float, lng: float} $from
     * @param array{lat: float, lng: float} $to
     * @param string $source the DriveLeg source: a routed leg (`google_routes`) or `straight_line`
     */
    public function fixed(array $from, array $to, int $minutes, float $miles, string $source = 'google_routes'): void
    {
        $this->fixed[self::key($from) . '>' . self::key($to)] = [$minutes, $miles, $source];
    }

    public function legs(string $orgId, array $truck, array $points, array $pairs, array $options = []): array
    {
        $this->calls[] = [$orgId, $points, $pairs, $options];
        $byId = [];
        foreach ($points as $point) {
            $byId[(string) $point['id']] = $point;
        }
        $legs = [];
        foreach ($pairs as [$fromId, $toId]) {
            $from = $byId[$fromId];
            $to = $byId[$toId];
            $fixed = $this->fixed[self::key($from) . '>' . self::key($to)] ?? null;
            if ($fixed === null) {
                $legs[] = self::key($from) === self::key($to)
                    ? StraightLineLegs::samePoint($fromId, $toId)
                    : StraightLineLegs::straightLine($fromId, $toId, $from, $to, StraightLineLegs::REASON);
                continue;
            }
            [$minutes, $miles, $source] = $fixed;
            $routed = $source !== 'straight_line';
            $legs[] = [
                'from_id' => $fromId,
                'to_id' => $toId,
                'source' => $source,
                'fetched_on' => $routed ? '2026-10-05' : null,
                'age_days' => $routed ? 0 : null,
                'distance_m' => $miles * 1609.344,
                'duration_s' => 0.0,
                'toll_state' => 'not_asked',
                'google_toll' => null,
                'toll_source' => 'none',
                'override' => ['id' => 'c0ffee00-0000-4000-8000-00000000' . sprintf('%04d', $minutes), 'minutes' => $minutes, 'toll' => null, 'note' => ''],
                'fallback_reason' => $routed ? null : 'no_key',
                'leg_input' => [
                    'source' => $routed ? 'google' : 'fallback',
                    'distance_m' => $miles * 1609.344,
                    'duration_s' => 0.0,
                    'override_minutes' => $minutes,
                    'toll' => 0.0,
                ],
            ];
        }
        return $legs;
    }

    public function status(): array
    {
        return ['state' => 'no_key'];
    }

    public function movePoint(string $orgId, array $truck, array $oldPoint, array $newPoint): void
    {
    }

    /**
     * @param array<string, mixed> $point
     */
    private static function key(array $point): string
    {
        return implode(',', LegKey::of((float) $point['lat'], (float) $point['lng']));
    }
}

/**
 * The day-context provider of the tests: holidays and a fixed fuel price, and the forecast a test put in
 * for a date. It remembers what it was asked.
 */
final class FixtureContexts implements DayContextProvider
{
    public const FUEL = ['price_per_gal' => 4.195, 'source' => 'seed', 'area' => 'R1Z', 'product' => 'EPMR', 'period' => '2026-09-28'];

    /** @var list<array{0: string, 1: int, 2: array<string, ?string>}> [from, days, treat_as] of every call */
    public array $asked = [];

    /** @var array<string, list<mixed>> date => 24 hourly records (or nulls) */
    public array $forecast = [];

    public function contexts(array $truck, array $A, string $from, int $days, array $treatAs = []): array
    {
        $this->asked[] = [$from, $days, $treatAs];
        $list = [];
        for ($i = 0; $i < $days; $i++) {
            $date = Estimator::addDays($from, $i);
            $context = Estimator::dayContext($A, $date, $treatAs[$date] ?? null, $this->forecast[$date] ?? null, self::FUEL['price_per_gal'], self::FUEL['source']);
            $list[] = ['date' => $date, 'holiday' => $context['holiday'], 'context' => $context];
        }
        return [
            'days' => $list,
            'forecast' => [
                'state' => $this->forecast === [] ? 'unavailable' : 'fresh',
                'generated_at' => $this->forecast === [] ? null : '2026-10-05T15:41:07+00:00',
                'point' => TruckWorld::BASE,
                'source' => 'National Weather Service (weather.gov)',
            ],
            'fuel' => self::FUEL,
        ];
    }

    /**
     * A forecast of 24 hours for a date: mild and dry, except the hours given.
     *
     * @param array<int, array{temp_f?: float, precip_prob?: ?float, short_forecast?: string, wind_mph?: float}> $hours
     * @return list<array<string, mixed>>
     */
    public static function day(array $hours = []): array
    {
        $out = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $out[] = array_replace(
                ['hour' => $hour, 'temp_f' => 68.0, 'precip_prob' => 5.0, 'short_forecast' => 'Mostly Sunny', 'wind_mph' => 6.0],
                $hours[$hour] ?? []
            );
        }
        return $out;
    }
}
