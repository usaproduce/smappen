<?php
declare(strict_types=1);

namespace App\TruckPlanner\Services;

use App\TruckPlanner\Data\PlaceRepository;
use App\TruckPlanner\Data\VectorCodec;
use App\TruckPlanner\Model\Estimator;

/**
 * The first stage of Scout: one cheap number per possible host, so that thousands of places can be put in
 * order before the model looks closely at the best of each kind (04_BACKEND.md 5.9 steps 4 to 6).
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
 *     demand      for a place whose best run fills the truck in every hour (a place "at capacity"): the
 *                 best run of the week before the truck's capacity is applied, which is what the place
 *                 would bring a truck that could serve everyone. It tells neighbours in the order apart
 *                 that all fill the truck. For every other place it is `best`
 *     trip        the cost of driving there and back on a straight-line estimate at typical traffic:
 *                 the crew's paid time and the fuel
 *     screen      host_fit * margin * best - trip, and 0.0 where no run of hours reaches a millionth
 *                 of an order
 *
 * `strip` is what Estimator::stripFromRows gives for the place, `best` is the orders value of
 * Estimator::scoutEstimate and `margin * best` its contribution value: the screen orders candidates the
 * way the model does, apart from the drive, which the model takes from real legs afterwards.
 *
 * The list is balanced by kind of place, so everything after the scores works on one kind (place type) at
 * a time:
 *
 *     kindOrders()     the candidates of each kind, best first (capacityOrder() holds the rule for places
 *                      that fill the truck)
 *     reachable()      of those, the ones whose round trip, estimated on straight-line legs with the
 *                      model's own timeline, is within the drive limit or close to it. Without this the
 *                      best screens would mostly belong to places at the far edge of the reach, which
 *                      the model then turns away
 *     sameSites()      one place for a site that is mapped as several (siteName() says what a site is)
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

    /** Words that, at the end of a name, tell one part of a site from another ("Tower", "Building 3"). */
    private const PART_WORDS = ['building', 'bldg', 'tower', 'phase', 'block', 'wing', 'lot', 'no', 'hq'];

    /**
     * The screen of every candidate, where its best window starts, and the two runs behind them.
     *
     * @param array<string, mixed> $A the truck's Assumptions
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed>|null $cal CalibrationState; only its truck factor is read
     * @param array{lat: float|int, lng: float|int} $base the truck's base
     * @param list<list<mixed>> $candidates rows of PlaceRepository::hostVectorPage()
     * @return array{screen: list<float>, start: list<int>, best: list<float>, demand: list<float>} four
     *         lists in the order of the candidates: `screen[i]`; `start[i]`, the hour of the week
     *         (0 = Monday 00:00) at which the best window of candidate i opens, or NO_WINDOW; `best[i]`,
     *         the orders of that window; `demand[i]`, for a candidate at capacity the best window of the
     *         week before the truck's capacity is applied, and `best[i]` for any other
     */
    public static function scores(array $A, array $profile, ?array $cal, float $fuelPrice, array $base, array $candidates): array
    {
        $shared = self::shared($A, $profile, $cal, $fuelPrice, $base);
        $screens = [];
        $starts = [];
        $bests = [];
        $demands = [];
        foreach ($candidates as $candidate) {
            $one = self::one($shared, $candidate, false);
            $screens[] = $one['screen'];
            $starts[] = $one['start'];
            $bests[] = $one['best'];
            $demands[] = $one['demand'];
        }
        return ['screen' => $screens, 'start' => $starts, 'best' => $bests, 'demand' => $demands];
    }

    /**
     * Everything the screen works out for one candidate: scores() returns four values of this.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $cal
     * @param array{lat: float|int, lng: float|int} $base
     * @param list<mixed> $candidate a row of PlaceRepository::hostVectorPage()
     * @return array{strip: list<float>, best: float, start: int, demand: float, margin: float, trip: float,
     *               screen: float, host_fit: float, kitchen: string, host: array<string, mixed>|null}
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

    // ------------------------------------------------------------------------------------ order inside a kind

    /**
     * The orders of a scouting window in which every hour fills the truck, as a ranking key (whole
     * millionths): a place whose best window reaches this key is "at capacity". 0 when the window is
     * shorter than an hour, and then no place is.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     */
    public static function capacityKey(array $A, array $profile): int
    {
        $windowMinutes = (int) Estimator::seed($A, 'scout.window_minutes');
        $hours = (int) (($windowMinutes - $windowMinutes % 60) / 60);
        return $hours < 1 ? 0 : Estimator::qkey($hours * (float) $profile['capacity_orders_per_hour']);
    }

    /**
     * The order inside a kind once places fill the truck.
     *
     * The model ranks a kind on what the best window leaves after the drive. Places that reach the truck's
     * capacity are tied on orders there: only the cost of the drive tells them apart. Where two or more of
     * them are neighbours in the model's order, with no other place between them, that tie is broken by
     * demand: the neighbours stand in the order of their demand (largest first, in whole millionths), equal
     * demand in the model's order.
     *
     * Nothing else moves. A place at capacity never passes a place that is not, so the model's order holds
     * for any two places that are not neighbours of this kind. Ordering every place at capacity of a kind
     * by demand, wherever the model put it, would not be breaking a tie: it would move a full truck nearby
     * behind places the model ranks below it, and one far away ahead of places the model ranks above it.
     *
     * @param list<int|string> $ranked the places of one kind in the model's order (ids of the caller)
     * @param array<int|string, bool> $atCapacity by id; an id that is absent is not at capacity
     * @param array<int|string, float|int> $demand by id, for the places at capacity
     * @return list<int|string> the same ids in the order of the list
     */
    public static function capacityOrder(array $ranked, array $atCapacity, array $demand): array
    {
        $list = [];
        $neighbours = [];
        foreach (array_values($ranked) as $position => $id) {
            if ($atCapacity[$id] ?? false) {
                $neighbours[] = [Estimator::qkey((float) ($demand[$id] ?? 0.0)), $position, $id];
                continue;
            }
            foreach (self::byDemand($neighbours) as $member) {
                $list[] = $member;
            }
            $neighbours = [];
            $list[] = $id;
        }
        foreach (self::byDemand($neighbours) as $member) {
            $list[] = $member;
        }
        return $list;
    }

    /**
     * @param list<array{0: int, 1: int, 2: int|string}> $neighbours [demand key, position in the model's order, id]
     * @return list<int|string> their ids, the largest demand first, equal demand in the model's order
     */
    private static function byDemand(array $neighbours): array
    {
        usort($neighbours, static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: ($a[1] <=> $b[1]));
        return array_column($neighbours, 2);
    }

    /**
     * The candidates of each kind, best first: by screen (whole millionths; equal screens by place_key),
     * with neighbours at capacity in the order of their demand (capacityOrder()).
     *
     * @param list<list<mixed>> $candidates the rows scores() was given
     * @param array{screen: list<float>, start: list<int>, best: list<float>, demand: list<float>} $scores
     * @param int $capacityKey capacityKey() of the truck
     * @return array<string, list<int>> place type => indexes into `$candidates`
     */
    public static function kindOrders(array $candidates, array $scores, int $capacityKey): array
    {
        $byKind = [];
        foreach ($scores['screen'] as $i => $screen) {
            $byKind[(string) $candidates[$i][PlaceRepository::VEC_TYPE]][] = [
                Estimator::qkey((float) $screen),
                (string) $candidates[$i][PlaceRepository::VEC_KEY],
                $i,
            ];
        }
        $orders = [];
        foreach ($byKind as $kind => $rows) {
            usort($rows, static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: strcmp($a[1], $b[1]));
            $ranked = [];
            $atCapacity = [];
            $demand = [];
            foreach ($rows as [, , $i]) {
                $ranked[] = $i;
                if ($capacityKey > 0 && Estimator::qkey((float) $scores['best'][$i]) >= $capacityKey) {
                    $atCapacity[$i] = true;
                    $demand[$i] = (float) $scores['demand'][$i];
                }
            }
            $orders[(string) $kind] = self::capacityOrder($ranked, $atCapacity, $demand);
        }
        return $orders;
    }

    // ------------------------------------------------------------------------------------ the drive limit

    /**
     * Which candidates of each kind the model could be asked about, in the order of their kind.
     *
     * A candidate is **inside** when its estimated round trip (roundTripMinutes) is at most twice the
     * limit, and **near** when it is at most `$slack` times that: a real route can be shorter than a
     * straight-line estimate, so a near place is worth asking about when the list is not full without it.
     * Everything farther is passed over. The walk of a kind ends at `$max` inside places, and takes at
     * most `$max` near ones.
     *
     * A candidate without a window has no trip the model could time, so no routed leg can ever be held
     * against the limit for it. Its straight-line estimate at typical traffic decides alone: inside, or
     * passed over.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array{lat: float|int, lng: float|int} $base
     * @param list<list<mixed>> $candidates the rows scores() was given
     * @param array{screen: list<float>, start: list<int>, best: list<float>, demand: list<float>} $scores
     * @param array<string, list<int>> $orders what kindOrders() answered
     * @param int $limitMinutes the longest drive, one way (`scout_drive_minutes_limit`)
     * @param float $slack how far over the limit an estimate may be for the place to be asked about
     * @param int $max the most inside places, and the most near places, of one kind
     * @return array<string, list<array{0: int, 1: bool}>> place type => [index into `$candidates`, inside?]
     *         in the order of the kind
     */
    public static function reachable(
        array $A,
        array $profile,
        array $base,
        array $candidates,
        array $scores,
        array $orders,
        int $limitMinutes,
        float $slack,
        int $max
    ): array {
        $limit = 2 * $limitMinutes;
        $reach = $limit * $slack;
        $profile = self::based($profile, $base);
        $contexts = [];
        $typical = (float) Estimator::seed($A, 'traffic.' . $A['region']['traffic_matrix'] . '_typical')
            * (float) $profile['truck_time_factor'];
        $out = [];
        foreach ($orders as $kind => $order) {
            $taken = [];
            $inside = 0;
            $near = 0;
            foreach ($max < 1 ? [] : $order as $i) {
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
                    $taken[] = [$i, true];
                    if (++$inside >= $max) {
                        break;
                    }
                } elseif ($minutes <= $reach && $near < $max) {
                    $taken[] = [$i, false];
                    $near++;
                }
            }
            $out[(string) $kind] = $taken;
        }
        return $out;
    }

    // ------------------------------------------------------------------------------------ one place, one entry

    /**
     * The name of the site a place belongs to, for telling whether two places are the same site.
     *
     * A large site is often mapped building by building ("Freddie Mac - HQ 1", "Freddie Mac - HQ 4";
     * "Rotunda Building II", "Rotunda Building V"). The site name drops what tells the parts apart:
     *
     *   1. the name up to its first part separator: a hyphen or dash with a blank on both sides, a colon,
     *      a comma or an opening parenthesis (the whole name when nothing stands before the separator)
     *   2. in lower case, `&` read as "and", every run of characters that are neither letters nor digits
     *      as one blank
     *   3. without the part words at its end, as long as a word is left: a number, a single letter, a
     *      Roman numeral up to XXIX, or one of PART_WORDS
     *
     * "Freddie Mac - HQ 1" is `freddie mac`, "Rotunda Building II" is `rotunda`, "Building 6" is
     * `building`, "1600 Tysons Boulevard" stays `1600 tysons boulevard`. A name without a letter or a digit
     * has no site name ("") and is never taken for another place.
     */
    public static function siteName(string $name): string
    {
        if (!mb_check_encoding($name, 'UTF-8')) {
            return '';
        }
        $parts = preg_split('/\s[-\x{2013}\x{2014}]\s|[:,(]/u', $name, 2);
        $text = self::comparable(is_array($parts) ? (string) $parts[0] : $name);
        if ($text === '') {
            $text = self::comparable($name);
        }
        if ($text === '') {
            return '';
        }
        $words = explode(' ', $text);
        while (count($words) > 1) {
            $last = (string) end($words);
            if (!in_array($last, self::PART_WORDS, true)
                && preg_match('/^(?:\p{N}+|\p{L}|x{0,2}(?:ix|iv|v?i{0,3}))$/u', $last) !== 1) {
                break;
            }
            array_pop($words);
        }
        return implode(' ', $words);
    }

    /**
     * One place for a site that is mapped as several.
     *
     * The places of one kind are taken best first. A place is merged into the first place already kept
     * that has its site name and stands, itself or through a place merged into it before, within
     * `$radiusM` of it. A site that is mapped as a chain of buildings is therefore one entry, however long
     * the chain. A place without a site name is never merged, and neither is one marked `exempt`: it is
     * kept in its own right and nothing is merged into it.
     *
     * @param list<array{site: string, lat: float|int, lng: float|int, exempt: bool}> $places one kind, best first
     * @param float $radiusM how far apart two places of one site may stand
     * @return list<array{0: int, 1: list<int>}> for each place that is kept, in the order given: its index
     *         and the indexes of the places merged into it
     */
    public static function sameSites(array $places, float $radiusM): array
    {
        $kept = [];
        $bySite = [];
        foreach ($places as $i => $place) {
            $site = (string) $place['site'];
            $merges = $site !== '' && !$place['exempt'];
            $into = null;
            foreach ($merges ? ($bySite[$site] ?? []) : [] as $n) {
                foreach (array_merge([$kept[$n][0]], $kept[$n][1]) as $member) {
                    $metres = Estimator::haversineM(
                        (float) $place['lat'],
                        (float) $place['lng'],
                        (float) $places[$member]['lat'],
                        (float) $places[$member]['lng']
                    );
                    if ($metres <= $radiusM) {
                        $into = $n;
                        break 2;
                    }
                }
            }
            if ($into !== null) {
                $kept[$into][1][] = $i;
                continue;
            }
            $kept[] = [$i, []];
            if ($merges) {
                $bySite[$site][] = count($kept) - 1;
            }
        }
        return $kept;
    }

    // ------------------------------------------------------------------------------------ internals

    /** Lower case, `&` as "and", everything that is neither a letter nor a digit as one blank. */
    private static function comparable(string $text): string
    {
        $text = str_replace('&', ' and ', mb_strtolower($text, 'UTF-8'));
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
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
            'capacity_key' => self::capacityKey($A, $profile),
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

        // Demand only tells places apart that fill the truck, so the week before capacity is worked out for
        // those alone (a few places in a hundred): the same sum hour by hour, without the cap. Its best run
        // may lie elsewhere in the week than the window the model takes.
        $demand = $best;
        if ($shared['capacity_key'] > 0 && $bestKey >= $shared['capacity_key']) {
            $raw = [];
            foreach ($shared['half'] as $how => $h) {
                $w = $weights[$how];
                $o = 0.0;
                for ($s = 0; $s < 16; $s++) {
                    $o += $vec[16 * $h + $s] * $w[$s];
                }
                if ($host !== null) {
                    $o += $hostCapture[$h] * $w[$hostIndex];
                }
                $raw[] = $o;
            }
            $circle = $raw;
            for ($k = 0; $k < $length - 1; $k++) {
                $circle[] = $raw[$k];
            }
            foreach ($raw as $start => $first) {
                $total = 0.0 + $first;
                for ($k = 1; $k < $length; $k++) {
                    $total += $circle[$start + $k];
                }
                if ($total > $demand) {
                    $demand = $total;
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
            'demand' => $demand,
            'margin' => $margin,
            'trip' => $trip,
            'screen' => $bestStart === self::NO_WINDOW ? 0.0 : $seed['host_fit'] * $margin * $best - $trip,
            'host_fit' => $seed['host_fit'],
            'kitchen' => (string) $kitchen,
            'host' => $host,
        ];
    }
}
