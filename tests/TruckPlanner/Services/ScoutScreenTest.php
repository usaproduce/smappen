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
            self::assertSame(['screen', 'start'], array_keys($scores));
            self::assertCount(count($group), $scores['screen']);
            self::assertCount(count($group), $scores['start']);
            foreach ($group as $i => $f) {
                $detail = self::detailOf($f);
                self::assertSame($detail['screen'], $scores['screen'][$i], $f['name']);
                self::assertSame($detail['start'], $scores['start'][$i], $f['name']);
            }
        }
        self::assertSame(['screen' => [], 'start' => []], ScoutScreen::scores(Seeds::defaults(), self::profile(), null, self::FUEL, self::BASE, []));
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

    public function testTheShortlistTakesTheBestScreensInsideTheLimitThenThoseNearIt(): void
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
        $byScreen = array_keys($scores['screen']);
        usort($byScreen, static fn (int $a, int $b): int => $scores['screen'][$b] <=> $scores['screen'][$a]);
        self::assertSame([5, 4, 3, 1, 2, 0, 6], $byScreen);
        foreach ($rows as $row) {
            self::assertLessThan(45.0, max(ScoutScreen::detail($A, $profile, null, self::FUEL, self::BASE, $row)['strip']));
        }

        // A limit that holds w01 to w03, with w04 within a quarter over it and w05, w06 beyond.
        $limit = (int) (($minutes[2] + 1) / 2);
        self::assertLessThanOrEqual(2 * $limit, $minutes[2]);
        self::assertGreaterThan(2 * $limit, $minutes[3]);
        $slack = ($minutes[3] + 0.5) / (2 * $limit);
        self::assertGreaterThan(2 * $limit * $slack, $minutes[4]);

        $picked = ScoutScreen::shortlist($A, $profile, self::BASE, $rows, $scores, $limit, $slack, 10);
        // inside the limit by screen (w02, w03, w01, then w07 without orders), then the one near it
        self::assertSame([1, 2, 0, 6, 3], $picked);

        // the cut takes the best of those inside first
        self::assertSame([1, 2], ScoutScreen::shortlist($A, $profile, self::BASE, $rows, $scores, $limit, $slack, 2));
        self::assertSame([], ScoutScreen::shortlist($A, $profile, self::BASE, $rows, $scores, $limit, $slack, 0));
        // without slack nothing over the limit is asked about
        self::assertSame([1, 2, 0, 6], ScoutScreen::shortlist($A, $profile, self::BASE, $rows, $scores, $limit, 1.0, 10));
        // a wide limit holds them all, best screen first
        self::assertSame([5, 4, 3, 1, 2, 0, 6], ScoutScreen::shortlist($A, $profile, self::BASE, $rows, $scores, 60, 1.25, 10));
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

        // a limit of 15 minutes holds both; one of 10 holds the near one, and the far one is not "near the limit"
        self::assertSame([2, 0, 1], ScoutScreen::shortlist($A, $profile, self::BASE, $rows, $scores, 17, 1.25, 10));
        self::assertSame([2, 0], ScoutScreen::shortlist($A, $profile, self::BASE, $rows, $scores, 15, 1.25, 10));
        self::assertSame([2, 0], ScoutScreen::shortlist($A, $profile, self::BASE, $rows, $scores, 15, 3.0, 10));
        self::assertSame([], ScoutScreen::shortlist($A, $profile, self::BASE, [$rows[1]], ['screen' => [0.0], 'start' => [ScoutScreen::NO_WINDOW]], 5, 1.25, 10));
    }

    public function testEqualScreensAreTakenInPlaceKeyOrder(): void
    {
        $A = Seeds::defaults();
        $profile = self::profile();
        $rows = [self::officeAt('w30', 1500.0, 200.0), self::officeAt('w10', 1500.0, 200.0), self::officeAt('w20', 1500.0, 200.0)];
        $scores = ScoutScreen::scores($A, $profile, null, self::FUEL, self::BASE, $rows);
        self::assertSame($scores['screen'][0], $scores['screen'][1]);
        self::assertSame([1, 2, 0], ScoutScreen::shortlist($A, $profile, self::BASE, $rows, $scores, 30, 1.25, 10));
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
