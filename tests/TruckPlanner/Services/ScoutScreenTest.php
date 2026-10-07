<?php
declare(strict_types=1);

namespace App\Tests\TruckPlanner\Services;

use App\Tests\TruckPlanner\Guard\SourceScan;
use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\VectorCodec;
use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\ProfileMapper;
use App\TruckPlanner\Services\ScoutScreen;
use PHPUnit\Framework\TestCase;

/**
 * The screen of Scout against the model it stands in for (04_BACKEND.md 5.9 step 4, 8.1).
 *
 * The fixtures are the model's own golden cases of `scout_estimate` (every place type that hosts, places
 * with and without a window, kitchens, a calibrated truck, overrides) and a few places made here with a
 * full location vector. For each of them the screen's week is the model's strip, its best run is the
 * model's orders and its margin times that run is the model's contribution, to the tolerance of the golden
 * cases. The drive is the screen's own: it is checked against the formula of the specification.
 */
final class ScoutScreenTest extends TestCase
{
    private const GOLDEN = __DIR__ . '/../../fixtures/truck-planner/golden_cases.json';
    private const BASE = ['lat' => 39.003, 'lng' => -77.405];
    private const FUEL = 4.195;
    private const SEGMENTS = [
        'res', 'w_office', 'w_health', 'w_edu', 'w_retail', 'w_industrial', 'w_hospitality', 'w_public',
        'v_nightlife', 'v_shopping', 'v_leisure', 'v_campus', 'v_hospital', 'v_transit', 'v_events', 'v_lodging',
    ];

    /** @var list<array<string, mixed>>|null */
    private static ?array $fixtures = null;

    public static function tearDownAfterClass(): void
    {
        self::$fixtures = null;
    }

    // ------------------------------------------------------------------------------------ fixtures

    /**
     * Each fixture: `A`, `profile`, `cal`, `fuel`, `place` (the model's PlaceInput), `legs`, and `row`, the
     * same place as a row of PlaceRepository::hostVectorPage().
     *
     * @return list<array<string, mixed>>
     */
    private static function fixtures(): array
    {
        if (self::$fixtures !== null) {
            return self::$fixtures;
        }
        $golden = json_decode((string) file_get_contents(self::GOLDEN), true);
        self::assertIsArray($golden);
        $out = [];
        foreach ($golden['cases'] as $case) {
            if ($case['function'] !== 'scout_estimate') {
                continue;
            }
            $args = $case['args'];
            $A = Seeds::assumptions($args['A']['overrides'], $args['A']['region']);
            if ((float) Estimator::seed($A, 'place_types.rows.' . $args['place']['place_type'])['host_fit'] <= 0.0) {
                continue;                         // a type that hosts nothing is no candidate
            }
            $out[] = [
                'name' => (string) $case['id'],
                'A' => $A,
                'profile' => $args['profile'],
                'cal' => $args['cal'],
                'fuel' => (float) $args['fuel_price_per_gal'],
                'place' => $args['place'],
                'legs' => $args['legs'],
                'row' => self::rowOf($args['place']),
            ];
        }
        self::assertGreaterThanOrEqual(30, count($out), 'the golden cases of scout_estimate were not found');

        // Places with a full vector: every segment present, different by regime, with rivals.
        $profile = self::profile();
        $dc = Seeds::assumptions([], ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => true]]);
        $overridden = Seeds::assumptions(['host.captive_share' => 0.6, 'kernel.visibility.normal' => 1.1], Seeds::REGION_NONE);
        $calibrated = Estimator::calibrate($dc, [], '2026-10-05');
        $calibrated['truck_factor'] = 0.82;
        $busy = self::vectors(static fn (int $s): float => 3.0 + 1.7 * $s, static fn (int $s): float => 41.0 - 2.3 * $s, 0.61, 1.37);
        $quiet = self::vectors(static fn (int $s): float => 0.011 * ($s + 1), static fn (int $s): float => 0.007 * (16 - $s), 2.5, 0.2);
        foreach ([
            ['made-taproom', 'taproom', 'unknown', 40.0, 'pw1', $busy, $dc, null],
            ['made-taproom-kitchen', 'taproom', 'yes', 40.0, 'pw2', $quiet, $dc, null],
            ['made-bar', 'bar', 'unknown', 45.0, 'pn3', $quiet, $overridden, null],
            ['made-hospital', 'hospital', 'no', 120.0, 'pw4', $busy, $overridden, $calibrated],
            ['made-office', 'office_park', 'no', 0.0, null, $busy, $dc, $calibrated],
            ['made-market', 'farmers_market', 'no', 0.0, null, $quiet, $dc, null],
            ['made-station', 'transit_station', 'no', 300.0, 'pn7', $quiet, Seeds::defaults(), $calibrated],
            ['made-campus-building', 'campus', 'yes', 0.0, null, $quiet, $dc, null],
        ] as $i => [$id, $type, $kitchen, $size, $pointId, $vectors, $A, $cal]) {
            $vectors['exclusion'] = ['point_ids' => $pointId === null ? [] : [$pointId], 'segment' => null, 'amount' => 0.0];
            $place = [
                'place_id' => $id, 'place_type' => $type, 'point' => ['lat' => 38.95 + 0.013 * $i, 'lng' => -77.36 - 0.011 * $i],
                'point_id' => $pointId, 'size_default' => $size, 'kitchen' => $kitchen, 'vectors' => $vectors,
            ];
            $out[] = [
                'name' => $id, 'A' => $A, 'profile' => $profile, 'cal' => $cal, 'fuel' => self::FUEL,
                'place' => $place, 'legs' => [], 'row' => self::rowOf($place),
            ];
        }
        return self::$fixtures = $out;
    }

    /**
     * @return array<string, mixed> TruckProfile: the defaults, based at Sterling
     */
    private static function profile(): array
    {
        return ['name' => 'Smoke & Ember', 'region_id' => 'dc', 'base' => self::BASE + ['address' => 'Sterling, VA']]
            + (new ProfileMapper())->defaults();
    }

    /**
     * A LocationVectors as Scout decodes one from `host_vec`.
     *
     * @return array<string, mixed>
     */
    private static function vectors(callable $day, callable $eve, float $rivalsDay, float $rivalsEve): array
    {
        $capture = ['day' => [], 'eve' => []];
        $nearby = [];
        for ($s = 0; $s < 16; $s++) {
            $capture['day'][] = $day($s);
            $capture['eve'][] = $eve($s);
            $nearby[] = 2.5 * $day($s);
        }
        return [
            'capture' => $capture, 'nearby' => $nearby, 'within' => null, 'rivals' => ['day' => $rivalsDay, 'eve' => $rivalsEve],
            'visibility' => 'normal', 'in_region' => true, 'region_id' => 'dc',
            'exclusion' => ['point_ids' => [], 'segment' => null, 'amount' => 0.0], 'excluded_amount' => 0.0,
            'points_used' => null, 'dataset_version' => 'dc-20261003-3fa9c2d1', 'model_version' => Estimator::MODEL_VERSION,
        ];
    }

    /**
     * A PlaceInput of the model as the Q6 row Scout reads for the same place.
     *
     * @param array<string, mixed> $place
     * @return list<mixed>
     */
    private static function rowOf(array $place): array
    {
        $row = [];
        $row[PlaceRepository::VEC_KEY] = (string) $place['place_id'];
        $row[PlaceRepository::VEC_TYPE] = (string) $place['place_type'];
        $row[PlaceRepository::VEC_LAT] = (float) $place['point']['lat'];
        $row[PlaceRepository::VEC_LNG] = (float) $place['point']['lng'];
        $row[PlaceRepository::VEC_COUNTY] = '51107';
        $row[PlaceRepository::VEC_KITCHEN] = $place['kitchen'] ?? 'unknown';
        $row[PlaceRepository::VEC_SEGMENT] = ($place['point_id'] ?? null) === null ? null : 'v_nightlife';
        $row[PlaceRepository::VEC_SIZE] = (float) $place['size_default'];
        $row[PlaceRepository::VEC_BYTES] = pack('e50', ...VectorCodec::flat($place['vectors']));
        ksort($row);
        return array_values($row);
    }

    /**
     * @param array<string, mixed> $f a fixture
     * @return array<string, mixed>
     */
    private static function detailOf(array $f): array
    {
        return ScoutScreen::detail($f['A'], $f['profile'], $f['cal'], $f['fuel'], $f['profile']['base'], $f['row']);
    }

    /** The tolerance of the golden cases (02_MODEL.md 1.4): 1e-9 relative, with a floor of 1. */
    private static function assertClose(float $expected, float $actual, string $what): void
    {
        self::assertLessThanOrEqual(
            1e-9 * max(1.0, abs($expected), abs($actual)),
            abs($expected - $actual),
            $what . ': ' . $actual . ' is not ' . $expected
        );
    }

    /**
     * A candidate `$metres` north of the base whose only people are office workers.
     *
     * @return list<mixed>
     */
    private static function officeAt(string $key, float $metres, float $capture, string $type = 'office_park'): array
    {
        $vectors = self::vectors(static fn (int $s): float => $s === 1 ? $capture : 0.0, static fn (int $s): float => $s === 1 ? $capture : 0.0, 0.0, 0.0);
        return self::rowOf([
            'place_id' => $key, 'place_type' => $type,
            'point' => ['lat' => self::BASE['lat'] + $metres / 6371008.8 * 180.0 / 3.141592653589793, 'lng' => self::BASE['lng']],
            'point_id' => null, 'size_default' => 0.0, 'kitchen' => 'no', 'vectors' => $vectors,
        ]);
    }

    // ------------------------------------------------------------------------------------ the model

    public function testTheWeekIsTheModelsStripFromRows(): void
    {
        $withHost = 0;
        foreach (self::fixtures() as $f) {
            $detail = self::detailOf($f);
            $terms = [
                'spot_id' => null, 'visibility' => 'normal', 'host' => $detail['host'],
                'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null,
            ];
            $rows = Estimator::mapWeightRows($f['A'], $f['profile'], $f['cal']);
            $strip = Estimator::stripFromRows($f['A'], $f['profile'], $terms, $f['place']['vectors'], $rows);
            self::assertCount(168, $detail['strip'], $f['name']);
            foreach ($strip as $how => $expected) {
                self::assertClose($expected, $detail['strip'][$how], $f['name'] . ' hour ' . $how);
            }
            $withHost += $detail['host'] === null ? 0 : 1;
        }
        self::assertGreaterThan(10, $withHost, 'fixtures with a host term');
    }

    public function testTheBestRunIsTheModelsOrdersAndItsMarginTheModelsContribution(): void
    {
        $withWindow = 0;
        $without = 0;
        foreach (self::fixtures() as $f) {
            $detail = self::detailOf($f);
            $model = Estimator::scoutEstimate($f['A'], $f['profile'], $f['place'], $f['legs'], $f['cal'], $f['fuel']);
            self::assertNotNull($model, $f['name']);

            self::assertClose((float) $model['orders']['value'], $detail['best'], $f['name'] . ' orders');
            self::assertClose((float) $model['contribution']['value'], $detail['margin'] * $detail['best'], $f['name'] . ' contribution');
            self::assertSame((float) $model['host_fit'], $detail['host_fit'], $f['name']);
            self::assertSame($model['kitchen'], $detail['kitchen'], $f['name']);
            self::assertSame((float) $model['host_size'], $detail['host'] === null ? 0.0 : (float) $detail['host']['size'], $f['name']);

            // the window the screen found is the one the model takes
            if ($model['best_window'] === null) {
                self::assertSame(ScoutScreen::NO_WINDOW, $detail['start'], $f['name']);
                self::assertSame(0.0, $detail['best']);
                self::assertSame(0.0, $detail['screen'], $f['name'] . ': a place without a window screens at zero');
                $without++;
            } else {
                $window = $model['best_window'];
                self::assertSame($window['dow'] * 24 + $window['open_minute'] / 60, $detail['start'], $f['name']);
                $withWindow++;
            }
        }
        self::assertGreaterThan(15, $withWindow);
        self::assertGreaterThan(5, $without);
    }

    public function testTheTripIsTheFormulaOfTheSpecificationAndTheScreenIsFitTimesMarginTimesBestLessTheTrip(): void
    {
        foreach (self::fixtures() as $f) {
            $detail = self::detailOf($f);
            $A = $f['A'];
            $profile = $f['profile'];
            $row = $f['row'];

            $fb = Estimator::fallbackLeg($A, $profile['base']['lat'], $profile['base']['lng'], $row[PlaceRepository::VEC_LAT], $row[PlaceRepository::VEC_LNG]);
            $ff = $fb['duration_s'] / 60.0;
            $miles = $fb['distance_m'] / 1609.344;
            $typical = Estimator::seed($A, 'traffic.' . $A['region']['traffic_matrix'] . '_typical');
            $trip = (2.0 * $ff * $typical * $profile['truck_time_factor'] / 60.0)
                * $profile['paid_crew'] * $profile['wage_per_hour'] * (1.0 + $profile['payroll_burden_pct'])
                + 2.0 * $miles / $profile['mpg'] * $f['fuel'];
            self::assertClose($trip, $detail['trip'], $f['name'] . ' trip');

            $zeroFee = ['spot_id' => null, 'visibility' => 'normal', 'host' => null, 'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null];
            self::assertSame(Estimator::unitMargins($profile, $zeroFee)['at_minimum'], $detail['margin'], $f['name']);
            $fit = (float) Estimator::seed($A, 'place_types.rows.' . $f['place']['place_type'])['host_fit'];
            $screen = Estimator::qkey($detail['best']) === 0 ? 0.0 : $fit * $detail['margin'] * $detail['best'] - $trip;
            self::assertClose($screen, $detail['screen'], $f['name'] . ' screen');
        }
    }

    public function testTheDriveDoesNotMoveOrdersOrContribution(): void
    {
        // The same place far away: the same week, another trip.
        $f = self::fixtures()[0];
        $near = self::detailOf($f);
        $far = $f;
        $far['row'][PlaceRepository::VEC_LAT] += 0.3;
        $there = self::detailOf($far);
        self::assertSame($near['strip'], $there['strip']);
        self::assertSame($near['best'], $there['best']);
        self::assertGreaterThan($near['trip'], $there['trip']);
        self::assertLessThan($near['screen'], $there['screen']);
    }

    public function testScoresAnswersEveryCandidateInTheOrderGiven(): void
    {
        $byTruck = [];
        foreach (self::fixtures() as $f) {
            // candidates of one request share the truck, its assumptions and its calibration
            $byTruck[sha1(serialize([$f['A']['overrides'], $f['A']['region'], $f['profile'], $f['cal'], $f['fuel']]))][] = $f;
        }
        self::assertGreaterThan(1, count($byTruck));
        foreach ($byTruck as $group) {
            $first = $group[0];
            $rows = array_column($group, 'row');
            $scores = ScoutScreen::scores($first['A'], $first['profile'], $first['cal'], $first['fuel'], $first['profile']['base'], $rows);
            self::assertSame(['screen', 'start', 'best', 'demand'], array_keys($scores));
            foreach ($scores as $list) {
                self::assertCount(count($group), $list);
            }
            foreach ($group as $i => $f) {
                $detail = self::detailOf($f);
                self::assertSame($detail['screen'], $scores['screen'][$i], $f['name']);
                self::assertSame($detail['start'], $scores['start'][$i], $f['name']);
                self::assertSame($detail['best'], $scores['best'][$i], $f['name']);
                self::assertSame($detail['demand'], $scores['demand'][$i], $f['name']);
            }
        }
        self::assertSame(
            ['screen' => [], 'start' => [], 'best' => [], 'demand' => []],
            ScoutScreen::scores(Seeds::defaults(), self::profile(), null, self::FUEL, self::BASE, [])
        );
    }

    // ------------------------------------------------------------------------------------ places that fill the truck

    public function testDemandIsTheBestRunBeforeCapacityForAPlaceThatFillsTheTruck(): void
    {
        $A = Seeds::assumptions([], ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => false]]);
        $profile = self::profile();
        self::assertSame(45.0, (float) $profile['capacity_orders_per_hour']);
        self::assertSame(135000000, ScoutScreen::capacityKey($A, $profile), 'three hours at 45 an hour, in millionths');
        self::assertSame(60000000, ScoutScreen::capacityKey($A, ['capacity_orders_per_hour' => 20] + $profile));

        // The same truck without a limit to what it can serve: its best run is the demand of the place.
        $unlimited = ['capacity_orders_per_hour' => 1.0e9] + $profile;
        $checked = 0;
        foreach ([[3000.0, true], [2000.0, true], [100.0, false], [0.0, false]] as [$workers, $fills]) {
            $row = self::officeAt('w01', 1500.0, $workers);
            $detail = ScoutScreen::detail($A, $profile, null, self::FUEL, self::BASE, $row);
            $free = ScoutScreen::detail($A, $unlimited, null, self::FUEL, self::BASE, $row);
            self::assertSame($fills, Estimator::qkey($detail['best']) >= ScoutScreen::capacityKey($A, $profile), $workers . ' workers');
            if ($fills) {
                self::assertSame(135.0, $detail['best'], 'every hour of the window is capped');
                self::assertClose($free['best'], $detail['demand'], $workers . ' workers: demand is the best run without the cap');
                self::assertGreaterThan(135.0, $detail['demand']);
                $checked++;
            } else {
                self::assertSame($detail['best'], $detail['demand'], 'below capacity demand is the best run itself');
                self::assertSame($free['best'], $detail['best']);
            }
        }
        self::assertSame(2, $checked);
        // more people, more demand: it is what tells two full trucks apart
        $big = ScoutScreen::detail($A, $profile, null, self::FUEL, self::BASE, self::officeAt('w01', 1500.0, 3000.0));
        $small = ScoutScreen::detail($A, $profile, null, self::FUEL, self::BASE, self::officeAt('w02', 1500.0, 2000.0));
        self::assertSame($big['best'], $small['best']);
        self::assertGreaterThan($small['demand'], $big['demand']);
        // The cap leaves the orders, the window and the model's numbers as they were.
        $model = Estimator::stripFromRows(
            $A,
            $profile,
            ['spot_id' => null, 'visibility' => 'normal', 'host' => null, 'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null],
            self::vectors(static fn (int $s): float => $s === 1 ? 3000.0 : 0.0, static fn (int $s): float => $s === 1 ? 3000.0 : 0.0, 0.0, 0.0),
            Estimator::mapWeightRows($A, $profile, null)
        );
        foreach ($model as $how => $expected) {
            self::assertClose($expected, $big['strip'][$how], 'hour ' . $how);
        }
        self::assertSame(45.0, max($big['strip']));
    }

    public function testNeighboursAtCapacityAreOrderedByDemandAndNothingElseMoves(): void
    {
        // The model's order a, b, c, d, e, f; a and b fill the truck, c does not, d, e and f do.
        $ranked = ['a', 'b', 'c', 'd', 'e', 'f'];
        $full = ['a' => true, 'b' => true, 'd' => true, 'e' => true, 'f' => true];
        self::assertSame(
            ['b', 'a', 'c', 'f', 'd', 'e'],
            ScoutScreen::capacityOrder($ranked, $full, ['a' => 200.0, 'b' => 250.0, 'd' => 400.0, 'e' => 300.0, 'f' => 900.0]),
            'a and b are neighbours, and so are d, e and f; c stays third and nothing passes it, although f has the largest demand of all'
        );
        // Places at capacity that are not neighbours keep the model's order, whatever their demand.
        $five = ['a', 'b', 'c', 'd', 'e'];
        self::assertSame($five, ScoutScreen::capacityOrder($five, ['a' => true, 'c' => true, 'e' => true], ['a' => 200.0, 'c' => 500.0, 'e' => 300.0]));
        // equal demand (to the millionth): the model's order decides
        $pair = ['a' => true, 'b' => true];
        self::assertSame($five, ScoutScreen::capacityOrder($five, $pair, ['a' => 300.0, 'b' => 300.0000001]));
        self::assertSame(['b', 'a', 'c', 'd', 'e'], ScoutScreen::capacityOrder($five, $pair, ['a' => 300.0, 'b' => 300.000001]));
        // nobody, one place, everybody
        self::assertSame($five, ScoutScreen::capacityOrder($five, [], []));
        self::assertSame($five, ScoutScreen::capacityOrder($five, ['c' => true], ['c' => 9000.0]));
        self::assertSame($five, ScoutScreen::capacityOrder($five, ['b' => false, 'd' => false], ['b' => 9000.0, 'd' => 1.0]));
        $all = array_fill_keys($five, true);
        self::assertSame(['e', 'd', 'c', 'b', 'a'], ScoutScreen::capacityOrder($five, $all, ['a' => 1.0, 'b' => 2.0, 'c' => 3.0, 'd' => 4.0, 'e' => 5.0]));
        // a place at capacity whose demand is not given counts as none
        self::assertSame(['b', 'a', 'c'], ScoutScreen::capacityOrder(['a', 'b', 'c'], ['a' => true, 'b' => true], ['b' => 1.0]));
        // indexes work as ids, and the answer is a list
        self::assertSame([3, 5, 7], ScoutScreen::capacityOrder([4 => 5, 9 => 3, 2 => 7], [5 => true, 3 => true], [5 => 1.0, 3 => 2.0]));
        self::assertSame([], ScoutScreen::capacityOrder([], [], []));
    }

    public function testTheModelsOrderHoldsForEveryPairThatIsNotATie(): void
    {
        // Every way of marking six places at capacity, with demand running against the model's order: a
        // place that is not at capacity keeps its position, and so does everything on either side of it.
        $ranked = [10, 11, 12, 13, 14, 15];
        for ($mask = 0; $mask < 64; $mask++) {
            $full = [];
            $demand = [];
            foreach ($ranked as $n => $id) {
                if (($mask >> $n) & 1) {
                    $full[$id] = true;
                    $demand[$id] = 100.0 + $n;
                }
            }
            $list = ScoutScreen::capacityOrder($ranked, $full, $demand);
            $sorted = $list;
            sort($sorted);
            self::assertSame($ranked, $sorted, 'the same places, mask ' . $mask);
            foreach ($ranked as $n => $id) {
                if (isset($full[$id])) {
                    continue;
                }
                self::assertSame($id, $list[$n], 'mask ' . $mask);
                self::assertEqualsCanonicalizing(array_slice($ranked, 0, $n), array_slice($list, 0, $n), 'nothing passes it, mask ' . $mask);
            }
            // inside a row of neighbours the largest demand is first
            foreach ($list as $n => $id) {
                if ($n > 0 && isset($full[$id]) && isset($full[$list[$n - 1]])) {
                    self::assertGreaterThan($demand[$id], $demand[$list[$n - 1]], 'mask ' . $mask);
                }
            }
        }
    }

    public function testEveryKindHasItsOwnOrder(): void
    {
        $A = Seeds::assumptions([], ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => false]]);
        $profile = self::profile();
        $rows = [
            self::officeAt('w01', 1000.0, 100.0),                       // 0  below capacity
            self::officeAt('w02', 1000.0, 2000.0),                      // 1  at capacity, the smaller demand, the nearer
            self::officeAt('w03', 4000.0, 3000.0),                      // 2  at capacity, the larger demand, farther
            self::officeAt('w04', 2000.0, 300.0),                       // 3  below capacity
            self::officeAt('n05', 1000.0, 50.0, 'industrial_site'),     // 4
            self::officeAt('n06', 1000.0, 80.0, 'industrial_site'),     // 5
            self::officeAt('w07', 500.0, 0.0),                          // 6  no window
        ];
        $scores = ScoutScreen::scores($A, $profile, null, self::FUEL, self::BASE, $rows);
        $key = ScoutScreen::capacityKey($A, $profile);
        // what the comments above say
        self::assertGreaterThanOrEqual($key, Estimator::qkey($scores['best'][1]));
        self::assertGreaterThanOrEqual($key, Estimator::qkey($scores['best'][2]));
        self::assertLessThan($key, Estimator::qkey($scores['best'][3]));
        self::assertGreaterThan($scores['screen'][2], $scores['screen'][1], 'the model\'s own order has the nearer of two full trucks first');
        self::assertGreaterThan($scores['demand'][1], $scores['demand'][2]);

        $orders = ScoutScreen::kindOrders($rows, $scores, $key);
        self::assertSame(['office_park', 'industrial_site'], array_keys($orders));
        // the two places at capacity lead, the larger demand first; then the others by screen
        self::assertSame([2, 1, 3, 0, 6], $orders['office_park']);
        self::assertSame([5, 4], $orders['industrial_site']);
        // without the capacity rule the screen alone decides
        self::assertSame([1, 2, 3, 0, 6], ScoutScreen::kindOrders($rows, $scores, 0)['office_park']);
        self::assertSame([], ScoutScreen::kindOrders([], ['screen' => [], 'start' => [], 'best' => [], 'demand' => []], $key));
    }

    public function testEqualScreensAreTakenInPlaceKeyOrder(): void
    {
        $A = Seeds::defaults();
        $profile = self::profile();
        $rows = [self::officeAt('w30', 1500.0, 200.0), self::officeAt('w10', 1500.0, 200.0), self::officeAt('w20', 1500.0, 200.0)];
        $scores = ScoutScreen::scores($A, $profile, null, self::FUEL, self::BASE, $rows);
        self::assertSame($scores['screen'][0], $scores['screen'][1]);
        self::assertSame(['office_park' => [1, 2, 0]], ScoutScreen::kindOrders($rows, $scores, ScoutScreen::capacityKey($A, $profile)));
        // ... also when all three fill the truck with the same demand
        $full = [self::officeAt('w30', 1500.0, 2000.0), self::officeAt('w10', 1500.0, 2000.0), self::officeAt('w20', 1500.0, 2000.0)];
        $scores = ScoutScreen::scores($A, $profile, null, self::FUEL, self::BASE, $full);
        self::assertSame(135.0, $scores['best'][0]);
        self::assertSame(['office_park' => [1, 2, 0]], ScoutScreen::kindOrders($full, $scores, ScoutScreen::capacityKey($A, $profile)));
    }

    public function testTheHostIsTheOneTheTypeDescribesAtTheDefaultSizeOfThePlace(): void
    {
        $A = Seeds::defaults();
        $profile = self::profile();
        $vectors = self::vectors(static fn (int $s): float => 0.0, static fn (int $s): float => 0.0, 0.0, 0.0);
        $place = static fn (string $type, ?string $kitchen, float $size): array => self::rowOf([
            'place_id' => 'w1', 'place_type' => $type, 'point' => ['lat' => 39.0035, 'lng' => -77.4035], 'point_id' => null,
            'size_default' => $size, 'kitchen' => $kitchen, 'vectors' => $vectors,
        ]);
        $detail = static fn (array $row): array => ScoutScreen::detail($A, $profile, null, self::FUEL, self::BASE, $row);

        // a taproom whose kitchen is not known: the type's default, no kitchen, so the truck is the only food
        $taproom = $detail($place('taproom', 'unknown', 40.0));
        self::assertSame('no', $taproom['kitchen']);
        self::assertSame(
            ['segment' => 'v_nightlife', 'size' => 40.0, 'size_source' => 'default', 'only_food' => true, 'point_id' => null, 'place_type' => 'taproom'],
            $taproom['host']
        );
        // 40 people, three quarters of them the truck's: 21.516 orders on Saturday 17:00 to 20:00 (02_MODEL.md 4.16)
        self::assertEqualsWithDelta(21.516, $taproom['best'], 1e-9);
        self::assertSame(5 * 24 + 17, $taproom['start']);

        // the same taproom with a kitchen of its own shares its guests
        $kitchen = $detail($place('taproom', 'yes', 40.0));
        self::assertSame('yes', $kitchen['kitchen']);
        self::assertFalse($kitchen['host']['only_food']);
        self::assertEqualsWithDelta(8.6064, $kitchen['best'], 1e-9);

        // a bar is assumed to have a kitchen
        self::assertSame('yes', $detail($place('bar', 'unknown', 45.0))['kitchen']);

        // no host term without a host segment, and none without a default size
        self::assertNull($detail($place('farmers_market', 'no', 0.0))['host']);
        $office = $detail($place('office_park', 'no', 0.0));
        self::assertNull($office['host']);
        self::assertSame(0.0, $office['best']);
        self::assertSame(ScoutScreen::NO_WINDOW, $office['start']);
        self::assertSame(0.0, $office['screen']);
    }

    public function testAVectorOfTheWrongLengthAndAnUnknownTypeAreRefused(): void
    {
        $row = self::fixtures()[0]['row'];
        $short = $row;
        $short[PlaceRepository::VEC_BYTES] = substr((string) $row[PlaceRepository::VEC_BYTES], 0, 392);
        try {
            ScoutScreen::detail(Seeds::defaults(), self::profile(), null, self::FUEL, self::BASE, $short);
            self::fail('a vector of 49 numbers was screened');
        } catch (\LengthException $e) {
            self::assertStringContainsString('50', $e->getMessage());
        }
        $unknown = $row;
        $unknown[PlaceRepository::VEC_TYPE] = 'lighthouse';
        $this->expectException(\OutOfBoundsException::class);
        ScoutScreen::detail(Seeds::defaults(), self::profile(), null, self::FUEL, self::BASE, $unknown);
    }

    // ------------------------------------------------------------------------------------ the drive limit

    public function testTheEstimatedRoundTripIsTheModelsOnStraightLineLegs(): void
    {
        $checked = 0;
        foreach (self::fixtures() as $f) {
            $detail = self::detailOf($f);
            // No legs are handed to the model: it fills both with its straight-line estimate.
            $model = Estimator::scoutEstimate($f['A'], $f['profile'], $f['place'], [], $f['cal'], $f['fuel']);
            $minutes = ScoutScreen::roundTripMinutes($f['A'], $f['profile'], $f['profile']['base'], $f['row'], $detail['start']);
            self::assertSame($model['round_trip']['minutes'], $minutes, $f['name']);
            $checked += $minutes > 0 ? 1 : 0;
        }
        self::assertGreaterThan(15, $checked);
        self::assertSame(0, ScoutScreen::roundTripMinutes(Seeds::defaults(), self::profile(), self::BASE, self::fixtures()[0]['row'], ScoutScreen::NO_WINDOW));
    }

    public function testReachableTakesThePlacesInsideTheLimitAndThoseNearItInTheOrderOfTheirKind(): void
    {
        $A = Seeds::assumptions([], ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => false]]);
        $profile = self::profile();
        // Office workers only: the more of them, the better the screen. Distances are from the base.
        $rows = [
            self::officeAt('w01', 1000.0, 25.0),
            self::officeAt('w02', 2000.0, 100.0),
            self::officeAt('w03', 6000.0, 75.0),
            self::officeAt('w04', 9000.0, 150.0),
            self::officeAt('w05', 12000.0, 225.0),
            self::officeAt('w06', 20000.0, 300.0),
            self::officeAt('w07', 3000.0, 0.0),
        ];
        $scores = ScoutScreen::scores($A, $profile, null, self::FUEL, self::BASE, $rows);
        $minutes = [];
        foreach ($rows as $i => $row) {
            $minutes[] = ScoutScreen::roundTripMinutes($A, $profile, self::BASE, $row, $scores['start'][$i]);
        }
        // The fixture is what the comments below say it is: farther places take longer, more workers
        // screen better whatever the distance, and no hour of the truck's capacity is reached.
        for ($i = 0; $i < 5; $i++) {
            self::assertLessThan($minutes[$i + 1], $minutes[$i]);
        }
        self::assertSame(0, $minutes[6], 'a place without a window has no trip');
        foreach ($rows as $row) {
            self::assertLessThan(45.0, max(ScoutScreen::detail($A, $profile, null, self::FUEL, self::BASE, $row)['strip']));
        }
        $orders = ScoutScreen::kindOrders($rows, $scores, ScoutScreen::capacityKey($A, $profile));
        self::assertSame(['office_park' => [5, 4, 3, 1, 2, 0, 6]], $orders, 'one kind, best screen first');

        // A limit that holds w01 to w03, with w04 within a quarter over it and w05, w06 beyond.
        $limit = (int) (($minutes[2] + 1) / 2);
        self::assertLessThanOrEqual(2 * $limit, $minutes[2]);
        self::assertGreaterThan(2 * $limit, $minutes[3]);
        $slack = ($minutes[3] + 0.5) / (2 * $limit);
        self::assertGreaterThan(2 * $limit * $slack, $minutes[4]);

        $reach = static fn (int $limitMinutes, float $slackOver, int $max): array
            => ScoutScreen::reachable($A, $profile, self::BASE, $rows, $scores, $orders, $limitMinutes, $slackOver, $max);
        // In the order of the kind: w06 and w05 are passed over, w04 is near the limit, then those inside it
        // (w02, w03, w01, and w07 without orders).
        self::assertSame(['office_park' => [[3, false], [1, true], [2, true], [0, true], [6, true]]], $reach($limit, $slack, 10));
        // the walk ends when enough places inside the limit are found
        self::assertSame(['office_park' => [[3, false], [1, true], [2, true]]], $reach($limit, $slack, 2));
        self::assertSame(['office_park' => []], $reach($limit, $slack, 0));
        // without slack nothing over the limit is asked about
        self::assertSame(['office_park' => [[1, true], [2, true], [0, true], [6, true]]], $reach($limit, 1.0, 10));
        // a wide limit holds them all, best screen first
        self::assertSame(
            ['office_park' => [[5, true], [4, true], [3, true], [1, true], [2, true], [0, true], [6, true]]],
            $reach(60, 1.25, 10)
        );
        // no more places near the limit are taken than places inside it
        $far = [];
        for ($i = 0; $i < 6; $i++) {
            $far[] = self::officeAt('w1' . $i, 9000.0 + $i, 150.0 + $i);
        }
        $farScores = ScoutScreen::scores($A, $profile, null, self::FUEL, self::BASE, $far);
        $farOrders = ScoutScreen::kindOrders($far, $farScores, ScoutScreen::capacityKey($A, $profile));
        $taken = ScoutScreen::reachable($A, $profile, self::BASE, $far, $farScores, $farOrders, $limit, $slack, 4)['office_park'];
        self::assertSame([[5, false], [4, false], [3, false], [2, false]], $taken);
    }

    public function testEveryKindIsWalkedOnItsOwn(): void
    {
        $A = Seeds::assumptions([], ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => false]]);
        $profile = self::profile();
        $rows = [];
        // Twelve offices and three warehouses, all close by.
        for ($i = 0; $i < 12; $i++) {
            $rows[] = self::officeAt(sprintf('w%02d', $i), 800.0 + 10.0 * $i, 200.0 - $i);
        }
        for ($i = 0; $i < 3; $i++) {
            $rows[] = self::officeAt(sprintf('n%02d', $i), 900.0 + 10.0 * $i, 20.0 - $i, 'industrial_site');
        }
        $scores = ScoutScreen::scores($A, $profile, null, self::FUEL, self::BASE, $rows);
        $orders = ScoutScreen::kindOrders($rows, $scores, ScoutScreen::capacityKey($A, $profile));
        $reach = ScoutScreen::reachable($A, $profile, self::BASE, $rows, $scores, $orders, 30, 1.25, 5);
        // The many offices do not crowd the warehouses out: each kind gives its own best.
        self::assertSame([[0, true], [1, true], [2, true], [3, true], [4, true]], $reach['office_park']);
        self::assertSame([[12, true], [13, true], [14, true]], $reach['industrial_site']);
    }

    public function testAPlaceWithoutAWindowIsHeldToTheLimitOnItsStraightLineEstimate(): void
    {
        // The model times no trip for a place where no hour is worth opening, so no routed leg can ever
        // be held against the limit for it: the estimate at typical traffic decides, and there is no "near".
        $A = Seeds::assumptions([], ['id' => 'dc', 'traffic_matrix' => 'dc', 'flags' => ['inauguration_day' => false]]);
        $profile = self::profile();
        $rows = [self::officeAt('w01', 2000.0, 0.0), self::officeAt('w02', 9000.0, 0.0), self::officeAt('w03', 2000.0, 100.0)];
        $scores = ScoutScreen::scores($A, $profile, null, self::FUEL, self::BASE, $rows);
        self::assertSame([ScoutScreen::NO_WINDOW, ScoutScreen::NO_WINDOW], array_slice($scores['start'], 0, 2));
        self::assertSame([0.0, 0.0], array_slice($scores['screen'], 0, 2));

        // there and back at typical traffic, at the truck's pace
        $estimate = static function (array $row) use ($A, $profile): float {
            $leg = Estimator::fallbackLeg($A, self::BASE['lat'], self::BASE['lng'], $row[PlaceRepository::VEC_LAT], $row[PlaceRepository::VEC_LNG]);
            return 2.0 * ($leg['duration_s'] / 60.0) * Estimator::seed($A, 'traffic.dc_typical') * $profile['truck_time_factor'];
        };
        self::assertEqualsWithDelta(10.8, $estimate($rows[0]), 0.1);
        self::assertEqualsWithDelta(32.9, $estimate($rows[1]), 0.1);

        $orders = ScoutScreen::kindOrders($rows, $scores, ScoutScreen::capacityKey($A, $profile));
        self::assertSame(['office_park' => [2, 0, 1]], $orders);
        $reach = static fn (int $limit, float $slack): array
            => ScoutScreen::reachable($A, $profile, self::BASE, $rows, $scores, $orders, $limit, $slack, 10)['office_park'];
        // a limit of 17 minutes holds both; one of 15 holds the near one, and the far one is not "near the limit"
        self::assertSame([[2, true], [0, true], [1, true]], $reach(17, 1.25));
        self::assertSame([[2, true], [0, true]], $reach(15, 1.25));
        self::assertSame([[2, true], [0, true]], $reach(15, 3.0));
        self::assertSame(
            ['office_park' => []],
            ScoutScreen::reachable(
                $A,
                $profile,
                self::BASE,
                [$rows[1]],
                ['screen' => [0.0], 'start' => [ScoutScreen::NO_WINDOW], 'best' => [0.0], 'demand' => [0.0]],
                ['office_park' => [0]],
                5,
                1.25,
                10
            )
        );
    }

    // ------------------------------------------------------------------------------------ one place, one entry

    public function testTheSiteNameDropsWhatTellsThePartsOfASiteApart(): void
    {
        $sites = [
            // names of the loaded region: one employer or complex mapped building by building
            'Freddie Mac - HQ 1' => 'freddie mac',
            'Freddie Mac - HQ 4' => 'freddie mac',
            'Freddie Mac - Westbranch' => 'freddie mac',
            'Capital One: Center 2' => 'capital one',
            'Rotunda Building II' => 'rotunda',
            'Rotunda Building V' => 'rotunda',
            'Parkside Building A' => 'parkside',
            'South Campus Commons 7' => 'south campus commons',
            'Westover Place XIV' => 'westover place',
            'Westover Place' => 'westover place',
            'Sonesta ES Suites Fairfax 2' => 'sonesta es suites fairfax',
            'World Bank (G Building)' => 'world bank',
            'University Research Center (north building)' => 'university research center',
            'Preserve at Westfields Phase II' => 'preserve at westfields',
            'Penderwood Water Tank Lot No. 2' => 'penderwood water tank',
            'The Bethesdan Hotel, Tapestry Collection by Hilton' => 'the bethesdan hotel',
            'Cargo Building 3' => 'cargo',
            'MicroStrategy HQ' => 'microstrategy',
            // a name that is only a part word keeps one word
            'Building 6' => 'building',
            'Tower 1' => 'tower',
            'Courtyard 400' => 'courtyard',
            // what a name starts with stays: a street number tells two buildings apart
            '1600 Tysons Boulevard' => '1600 tysons boulevard',
            '1650 Tysons Boulevard' => '1650 tysons boulevard',
            '880 P' => '880',
            // case, punctuation, the ampersand and a dash
            'Vencore' => 'vencore',
            "Herndon Farmers' Market" => 'herndon farmers market',
            'Herndon Farmers Market' => 'herndon farmers market',
            'Harpers Ferry Adventure center' => 'harpers ferry adventure center',
            'Smith & Sons' => 'smith and sons',
            "M-NCPPC \u{2013} South Germantown" => 'm ncppc',
            'Coca-Cola Consolidated, Inc.' => 'coca cola consolidated',
            "Caf\u{00E9} Zo\u{00EB} III" => "caf\u{00E9} zo\u{00EB}",
            // nothing stands before the separator: the whole name is read
            '(Annex)' => 'annex',
            ': Two' => 'two',
            // a word that only looks like a number is a word
            'Mix' => 'mix',
            'Civic Center' => 'civic center',
            // no letter, no digit: no site name
            '' => '',
            ' - ' => '',
            "\u{2605}\u{2605}" => '',
        ];
        foreach ($sites as $name => $site) {
            self::assertSame($site, ScoutScreen::siteName((string) $name), (string) $name);
        }
        self::assertSame('', ScoutScreen::siteName("\xC3\x28 not text"));
    }

    public function testASiteThatIsMappedAsSeveralPlacesIsOneEntry(): void
    {
        $at = static fn (string $site, float $metresNorth, bool $exempt = false): array => [
            'site' => $site,
            'lat' => self::BASE['lat'] + $metresNorth / 6371008.8 * 180.0 / 3.141592653589793,
            'lng' => self::BASE['lng'],
            'exempt' => $exempt,
        ];
        // Best first: four buildings of one employer in a row 300 m apart, another employer between them,
        // and a place of the first name far away.
        $places = [
            $at('freddie mac', 0.0),        // 0 kept
            $at('vencore', 100.0),          // 1 kept
            $at('freddie mac', 300.0),      // 2 within 400 m of 0
            $at('freddie mac', 600.0),      // 3 600 m from 0, 300 m from 2: one site through the chain
            $at('freddie mac', 5000.0),     // 4 another site of the same name
            $at('vencore', 450.0),          // 5 350 m from 1
            $at('freddie mac', 5399.0),     // 6 within 400 m of 4
            $at('', 0.0),                   // 7 a place without a site name is never merged
            $at('', 0.0),                   // 8
        ];
        self::assertSame(
            [[0, [2, 3]], [1, [5]], [4, [6]], [7, []], [8, []]],
            ScoutScreen::sameSites($places, 400.0)
        );
        // the radius is the setting: at 250 m nothing reaches, at 300 m the chain does (the limit counts)
        self::assertCount(9, ScoutScreen::sameSites($places, 250.0));
        self::assertSame([0, [2, 3]], ScoutScreen::sameSites($places, 300.5)[0]);
        // the first of a site is the one that is kept: the order of the list decides
        self::assertSame([[0, [1]]], ScoutScreen::sameSites([$at('rotunda', 50.0), $at('rotunda', 0.0)], 400.0));
        self::assertSame([], ScoutScreen::sameSites([], 400.0));
    }

    public function testAPlaceThatIsExemptIsKeptInItsOwnRight(): void
    {
        $at = static fn (string $site, float $metresNorth, bool $exempt = false): array => [
            'site' => $site,
            'lat' => self::BASE['lat'] + $metresNorth / 6371008.8 * 180.0 / 3.141592653589793,
            'lng' => self::BASE['lng'],
            'exempt' => $exempt,
        ];
        // The third building is one the owner has a lead for: it is not merged, and nothing is merged into it.
        $places = [$at('freddie mac', 0.0), $at('freddie mac', 100.0), $at('freddie mac', 200.0, true), $at('freddie mac', 300.0)];
        self::assertSame([[0, [1, 3]], [2, []]], ScoutScreen::sameSites($places, 400.0));
        // also when it comes first
        $places = [$at('freddie mac', 0.0, true), $at('freddie mac', 100.0), $at('freddie mac', 200.0)];
        self::assertSame([[0, []], [1, [2]]], ScoutScreen::sameSites($places, 400.0));
        // a chain does not run through it
        $places = [$at('rotunda', 0.0), $at('rotunda', 350.0, true), $at('rotunda', 700.0)];
        self::assertSame([[0, []], [1, []], [2, []]], ScoutScreen::sameSites($places, 400.0));
    }


    // ------------------------------------------------------------------------------------ purity

    public function testTheScreenIsPure(): void
    {
        $file = 'src/TruckPlanner/Services/ScoutScreen.php';
        $used = array_values(array_unique(array_column(SourceScan::classUses($file), 0)));
        sort($used);
        // the model, the vector codec, the positions of a Q6 row and two exceptions for a malformed
        // candidate: no database, cache, clock, registry or client
        self::assertSame(['Estimator', 'LengthException', 'OutOfBoundsException', 'PlaceRepository', 'VectorCodec', 'self'], $used);
        self::assertSame([], SourceScan::methodCalls($file), 'nothing is called on an object');
        foreach ((new \ReflectionClass(ScoutScreen::class))->getMethods() as $method) {
            self::assertTrue($method->isStatic(), $method->getName());
        }
        self::assertSame([], (new \ReflectionClass(ScoutScreen::class))->getProperties());
        // The segment order the screen relies on is the model's.
        self::assertSame(self::SEGMENTS, Estimator::seed(Seeds::defaults(), 'vocabulary.segments'));
    }
}
