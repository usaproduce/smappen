<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\VectorCodec;
use App\TruckPlanner\Model\Estimator;

/**
 * The first stage of Scout: one cheap number per possible host, so that thousands of places can be put in
 * order before the model looks closely at the best of them (04_BACKEND.md 5.9 steps 4 and 5).
 *
 * Pure: no database, no clock, no network. The same arguments always give the same numbers.
 *
 * For one candidate, with the weight rows of the map fast path (02_MODEL.md 4.17):
 *
 *     strip[how]  expected orders in each of the 168 hours of a typical week at the place: its stored
 *                 location vector times the weights, plus its own visitors at the type's default size
 *                 when the type has a host segment, capped at what the truck can serve in an hour
 *     best        the orders of the best run of hours of the scouting window's length (seed
 *                 `scout.window_minutes`), the week taken as a circle; `start` is its first hour
 *     trip        the cost of driving there and back on a straight-line estimate at typical traffic:
 *                 the crew's paid time and the fuel
 *     screen      host_fit * margin * best - trip, and 0.0 where no run of hours reaches a millionth
 *                 of an order
 *
 * `strip` is what Estimator::stripFromRows gives for the place, `best` is the orders value of
 * Estimator::scoutEstimate and `margin * best` its contribution value: the screen orders candidates the
 * way the model does, apart from the drive, which the model takes from real legs afterwards.
 *
 * shortlist() then says which candidates the model should look at, and in which order: best screen first,
 * and only places whose round trip, estimated on straight-line legs with the model's own timeline, is
 * within the drive limit or close to it. Without it the best screens would mostly belong to places at the
 * far edge of the reach, which the model then turns away.
 *
 * A candidate is a row of PlaceRepository::hostVectorPage() (positions PlaceRepository::VEC_*): place_key,
 * place_type, lat, lng, county_fips, kitchen, visitor_segment, size_default and the 400 bytes of host_vec.
 *
 * The screen ranks. Like the model's score it is never shown as money, and it says nothing about whether
 * a place would have the truck.
 */
final class ScoutScreen
{
    /** `start` of a candidate that has no hour worth opening for. */
    public const NO_WINDOW = -1;

    private const HOURS_PER_WEEK = 168;
    private const HOURS_PER_DAY = 24;
    private const RIVALS_DAY = 48;
    private const RIVALS_EVE = 49;
    private const METRES_PER_MILE = 1609.344;
    private const QKEY_SCALE = 1000000.0;

    /**
     * The screen of every candidate, and where its best window starts.
     *
     * @param array<string, mixed> $A the truck's Assumptions
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed>|null $cal CalibrationState; only its truck factor is read
     * @param array{lat: float|int, lng: float|int} $base the truck's base
     * @param list<list<mixed>> $candidates rows of PlaceRepository::hostVectorPage()
     * @return array{screen: list<float>, start: list<int>} two lists in the order of the candidates:
     *         `screen[i]` and `start[i]`, the hour of the week (0 = Monday 00:00) at which the best window
     *         of candidate i opens, or NO_WINDOW
     */
    public static function scores(array $A, array $profile, ?array $cal, float $fuelPrice, array $base, array $candidates): array
    {
        $shared = self::shared($A, $profile, $cal, $fuelPrice, $base);
        $screens = [];
        $starts = [];
        foreach ($candidates as $candidate) {
            $one = self::one($shared, $candidate, false);
            $screens[] = $one['screen'];
            $starts[] = $one['start'];
        }
        return ['screen' => $screens, 'start' => $starts];
    }

    /**
     * Everything the screen works out for one candidate: scores() returns the `screen` and `start` of this.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $cal
     * @param array{lat: float|int, lng: float|int} $base
     * @param list<mixed> $candidate a row of PlaceRepository::hostVectorPage()
     * @return array{strip: list<float>, best: float, start: int, margin: float, trip: float, screen: float,
     *               host_fit: float, kitchen: string, host: array<string, mixed>|null}
     *         `host` is the Host the type's default describes (null for a type without a host segment
     *         and for a place without a default size); `kitchen` is the place's own state when known,
     *         else the default of its type
     */
    public static function detail(array $A, array $profile, ?array $cal, float $fuelPrice, array $base, array $candidate): array
    {
        $shared = self::shared($A, $profile, $cal, $fuelPrice, $base);
        return self::one($shared, $candidate, true);
    }

    /**
     * The drive minutes, there and back, that the model's timeline gives for a candidate's best window when
     * both legs are straight-line estimates: what Estimator::scoutEstimate reports as `round_trip.minutes`
     * while no routed leg is known. 0 for a candidate without a window.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array{lat: float|int, lng: float|int} $base
     * @param list<mixed> $candidate a row of PlaceRepository::hostVectorPage()
     * @param int $start `start` of the candidate, from scores()
     */
    public static function roundTripMinutes(array $A, array $profile, array $base, array $candidate, int $start): int
    {
        if ($start < 0 || $start >= self::HOURS_PER_WEEK) {
            return 0;
        }
        $contexts = [];
        return self::timelineMinutes($A, self::based($profile, $base), $contexts, $candidate, $start);
    }

    /**
     * Which candidates the model should look at, and in which order.
     *
     * Candidates are walked best screen first (whole millionths; equal screens by place_key). One is taken
     * when its estimated round trip (roundTripMinutes) is at most twice the limit; the first `$max` of
     * those lead the answer. Candidates estimated up to `$slack` times that follow them, as long as there
     * is room: a real route can be shorter than a straight-line estimate, so they are worth asking about
     * when the list is not full without them. Everything farther is passed over.
     *
     * A candidate without a window has no trip the model could time, so no routed leg can ever be held
     * against the limit for it. Its straight-line estimate at typical traffic decides alone: inside, or
     * passed over.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array{lat: float|int, lng: float|int} $base
     * @param list<list<mixed>> $candidates the rows scores() was given
     * @param array{screen: list<float>, start: list<int>} $scores what scores() answered for them
     * @param int $limitMinutes the longest drive, one way (`scout_drive_minutes_limit`)
     * @param float $slack how far over the limit an estimate may be for the place to be asked about
     * @param int $max the most candidates to return
     * @return list<int> indexes into `$candidates`
     */
    public static function shortlist(
        array $A,
        array $profile,
        array $base,
        array $candidates,
        array $scores,
        int $limitMinutes,
        float $slack,
        int $max
    ): array {
        if ($max < 1) {
            return [];
        }
        $order = [];
        foreach ($scores['screen'] as $i => $screen) {
            $order[] = [Estimator::qkey((float) $screen), (string) $candidates[$i][PlaceRepository::VEC_KEY], $i];
        }
        usort($order, static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: strcmp($a[1], $b[1]));

        $inside = [];
        $near = [];
        $limit = 2 * $limitMinutes;
        $reach = $limit * $slack;
        $profile = self::based($profile, $base);
        $contexts = [];
        $typical = (float) Estimator::seed($A, 'traffic.' . $A['region']['traffic_matrix'] . '_typical')
            * (float) $profile['truck_time_factor'];
        foreach ($order as [, , $i]) {
            $start = (int) $scores['start'][$i];
            if ($start < 0 || $start >= self::HOURS_PER_WEEK) {
                $leg = Estimator::fallbackLeg(
                    $A,
                    (float) $profile['base']['lat'],
                    (float) $profile['base']['lng'],
                    (float) $candidates[$i][PlaceRepository::VEC_LAT],
                    (float) $candidates[$i][PlaceRepository::VEC_LNG]
                );
                if (2.0 * ((float) $leg['duration_s'] / 60.0) * $typical > $limit) {
                    continue;
                }
                $minutes = 0;
            } else {
                $minutes = self::timelineMinutes($A, $profile, $contexts, $candidates[$i], $start);
            }
            if ($minutes <= $limit) {
                $inside[] = $i;
                if (count($inside) >= $max) {
                    break;
                }
            } elseif ($minutes <= $reach && count($near) < $max) {
                $near[] = $i;
            }
        }
        return array_slice(array_merge($inside, $near), 0, $max);
    }

    /**
     * The profile with the given point as its base: the model's timeline reads the base from the profile.
     *
     * @param array<string, mixed> $profile
     * @param array{lat: float|int, lng: float|int} $base
     * @return array<string, mixed>
     */
    private static function based(array $profile, array $base): array
    {
        $profile['base'] = ['lat' => (float) $base['lat'], 'lng' => (float) $base['lng']] + (array) ($profile['base'] ?? []);
        return $profile;
    }

    /**
     * The model's timeline for one stop at the candidate, open for the scouting window from the hour of
     * the week `$start`, in a typical week. No leg is given, so the model fills both with its straight-line
     * estimate: this is the timeline of Estimator::scoutEstimate while no routed leg is known.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile the profile, its base being the truck's base
     * @param array<int, array<string, mixed>> $contexts typical contexts by day of the week, filled as needed
     * @param list<mixed> $candidate
     */
    private static function timelineMinutes(array $A, array $profile, array &$contexts, array $candidate, int $start): int
    {
        $hour = $start % self::HOURS_PER_DAY;
        $dow = (int) (($start - $hour) / self::HOURS_PER_DAY);
        $open = $hour * 60;
        $stop = [
            'id' => (string) $candidate[PlaceRepository::VEC_KEY],
            'kind' => 'spot',
            'spot_id' => null,
            'point' => ['lat' => (float) $candidate[PlaceRepository::VEC_LAT], 'lng' => (float) $candidate[PlaceRepository::VEC_LNG]],
            'open_minute' => $open,
            'close_minute' => $open + (int) Estimator::seed($A, 'scout.window_minutes'),
            'gap_before_unpaid' => false,
            'setup_minutes' => null,
            'teardown_minutes' => null,
        ];
        $contexts[$dow] ??= Estimator::typicalContext($A, $dow);
        return (int) Estimator::buildTimeline($A, $profile, $contexts[$dow], [$stop], [])['drive_minutes'];
    }

    /**
     * What is the same for every candidate of a request.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $cal
     * @param array{lat: float|int, lng: float|int} $base
     * @return array<string, mixed>
     */
    private static function shared(array $A, array $profile, ?array $cal, float $fuelPrice, array $base): array
    {
        // 0 for an hour of the day regime, 1 for an hour of the evening regime: the half of a vector it reads.
        $regimes = array_values(Estimator::seed($A, 'hours.regime_of_hour'));
        $half = [];
        for ($how = 0; $how < self::HOURS_PER_WEEK; $how++) {
            $half[] = $regimes[$how % self::HOURS_PER_DAY] === 'day' ? 0 : 1;
        }
        $zeroFee = [
            'spot_id' => null, 'visibility' => 'normal', 'host' => null,
            'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null,
        ];
        // Whole hours of the scouting window (floor of minutes over 60; the seed is never negative).
        $windowMinutes = (int) Estimator::seed($A, 'scout.window_minutes');
        $windowHours = (int) (($windowMinutes - $windowMinutes % 60) / 60);
        return [
            'A' => $A,
            'weights' => Estimator::mapWeightRows($A, $profile, $cal)['w_opp'],
            'half' => $half,
            'capacity' => (float) $profile['capacity_orders_per_hour'],
            'margin' => (float) Estimator::unitMargins($profile, $zeroFee)['at_minimum'],
            'window_hours' => $windowHours,
            'typical' => (float) Estimator::seed($A, 'traffic.' . $A['region']['traffic_matrix'] . '_typical'),
            'truck_time_factor' => (float) $profile['truck_time_factor'],
            'paid_crew' => (float) $profile['paid_crew'],
            'wage_per_hour' => (float) $profile['wage_per_hour'],
            'payroll_burden_pct' => (float) $profile['payroll_burden_pct'],
            'mpg' => (float) $profile['mpg'],
            'fuel_price' => $fuelPrice,
            'base_lat' => (float) $base['lat'],
            'base_lng' => (float) $base['lng'],
            'segment_index' => array_flip(array_values(Estimator::seed($A, 'vocabulary.segments'))),
            'types' => [],
        ];
    }

    /**
     * @param array<string, mixed> $shared what shared() built; the seed rows of the place types met so far
     *                                     are kept in it
     * @param list<mixed> $candidate
     * @return array<string, mixed>
     */
    private static function one(array &$shared, array $candidate, bool $withStrip): array
    {
        $A = $shared['A'];
        $type = (string) $candidate[PlaceRepository::VEC_TYPE];
        if (!isset($shared['types'][$type])) {
            $row = Estimator::seed($A, 'place_types.rows.' . $type);
            $segment = $row['host_segment'] ?? null;
            if ($segment !== null && !isset($shared['segment_index'][$segment])) {
                throw new \OutOfBoundsException('unknown host segment: ' . $segment);
            }
            $shared['types'][$type] = [
                'host_fit' => (float) $row['host_fit'],
                'kitchen_default' => (string) $row['kitchen_default'],
                'host_segment' => $segment === null ? null : (string) $segment,
                'segment_index' => $segment === null ? 0 : (int) $shared['segment_index'][$segment],
            ];
        }
        $seed = $shared['types'][$type];

        $vec = VectorCodec::fromBytes((string) $candidate[PlaceRepository::VEC_BYTES]);
        if (count($vec) !== VectorCodec::BLOCK_DOUBLES) {
            throw new \LengthException('a host vector has 50 numbers');
        }
        $kitchen = $candidate[PlaceRepository::VEC_KITCHEN];
        if ($kitchen !== 'yes' && $kitchen !== 'no') {
            $kitchen = $seed['kitchen_default'];
        }
        $size = (float) $candidate[PlaceRepository::VEC_SIZE];

        // The place's own visitors, at the default size of its type.
        $host = null;
        $hostCapture = [0.0, 0.0];
        $hostIndex = $seed['segment_index'];
        if ($seed['host_segment'] !== null && $size > 0.0) {
            $host = [
                'segment' => $seed['host_segment'],
                'size' => $size,
                'size_source' => 'default',
                'only_food' => $kitchen === 'no',
                'point_id' => null,
                'place_type' => $type,
            ];
            $captured = Estimator::hostCapture($A, $host, 'normal', ['day' => $vec[self::RIVALS_DAY], 'eve' => $vec[self::RIVALS_EVE]]);
            $hostCapture = [(float) $captured['day'], (float) $captured['eve']];
        }

        // The week, hour by hour: the half of the vector that belongs to the hour's regime times the hour's
        // weights. This is the inner loop of Scout (168 hours for each of thousands of places), so the sum
        // over the sixteen segments is written out. It adds the products left to right in segment order,
        // which is the order of the model's own loop, so the two give the same doubles.
        [$d0, $d1, $d2, $d3, $d4, $d5, $d6, $d7, $d8, $d9, $d10, $d11, $d12, $d13, $d14, $d15,
            $e0, $e1, $e2, $e3, $e4, $e5, $e6, $e7, $e8, $e9, $e10, $e11, $e12, $e13, $e14, $e15] = $vec;
        $weights = $shared['weights'];
        $capacity = $shared['capacity'];
        $strip = [];
        foreach ($shared['half'] as $how => $h) {
            $w = $weights[$how];
            if ($h === 0) {
                $o = $d0 * $w[0] + $d1 * $w[1] + $d2 * $w[2] + $d3 * $w[3]
                    + $d4 * $w[4] + $d5 * $w[5] + $d6 * $w[6] + $d7 * $w[7]
                    + $d8 * $w[8] + $d9 * $w[9] + $d10 * $w[10] + $d11 * $w[11]
                    + $d12 * $w[12] + $d13 * $w[13] + $d14 * $w[14] + $d15 * $w[15];
            } else {
                $o = $e0 * $w[0] + $e1 * $w[1] + $e2 * $w[2] + $e3 * $w[3]
                    + $e4 * $w[4] + $e5 * $w[5] + $e6 * $w[6] + $e7 * $w[7]
                    + $e8 * $w[8] + $e9 * $w[9] + $e10 * $w[10] + $e11 * $w[11]
                    + $e12 * $w[12] + $e13 * $w[13] + $e14 * $w[14] + $e15 * $w[15];
            }
            if ($host !== null) {
                $o += $hostCapture[$h] * $w[$hostIndex];
            }
            $strip[] = $capacity < $o ? $capacity : $o;
        }

        // The best run of hours, the week taken as a circle. Runs are compared on whole millionths and the
        // earliest of equal runs is kept, which is how the model picks its window. A run that does not
        // beat the total of the best one so far cannot beat its millionths either.
        $length = $shared['window_hours'];
        $best = 0.0;
        $bestKey = 0.0;
        $bestStart = self::NO_WINDOW;
        if ($length >= 1 && $length <= count($strip)) {
            $circle = $strip;
            for ($k = 0; $k < $length - 1; $k++) {
                $circle[] = $strip[$k];
            }
            $scale = self::QKEY_SCALE;
            foreach ($strip as $start => $first) {
                $total = 0.0 + $first;
                for ($k = 1; $k < $length; $k++) {
                    $total += $circle[$start + $k];
                }
                if ($total > $best) {
                    $key = floor($total * $scale + 0.5);
                    if ($key > $bestKey) {
                        $bestKey = $key;
                        $best = $total;
                        $bestStart = $start;
                    }
                }
            }
        }

        // There and back on the straight-line estimate, at typical traffic and the truck's own pace.
        $leg = Estimator::fallbackLeg(
            $A,
            $shared['base_lat'],
            $shared['base_lng'],
            (float) $candidate[PlaceRepository::VEC_LAT],
            (float) $candidate[PlaceRepository::VEC_LNG]
        );
        $ff = (float) $leg['duration_s'] / 60.0;
        $miles = (float) $leg['distance_m'] / self::METRES_PER_MILE;
        $trip = (2.0 * $ff * $shared['typical'] * $shared['truck_time_factor'] / 60.0)
            * $shared['paid_crew'] * $shared['wage_per_hour'] * (1.0 + $shared['payroll_burden_pct'])
            + 2.0 * $miles / $shared['mpg'] * $shared['fuel_price'];

        $margin = $shared['margin'];
        return [
            'strip' => $withStrip ? $strip : [],
            'best' => $best,
            'start' => $bestStart,
            'margin' => $margin,
            'trip' => $trip,
            'screen' => $bestStart === self::NO_WINDOW ? 0.0 : $seed['host_fit'] * $margin * $best - $trip,
            'host_fit' => $seed['host_fit'],
            'kitchen' => (string) $kitchen,
            'host' => $host,
        ];
    }
}
