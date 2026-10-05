<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The Truck Planner model (tps-0.1.0): every function of docs/truck-planner/02_MODEL.md under the camelCase
 * of its canonical name, arguments in the documented order, arrays in and arrays out in the document's
 * snake_case shapes.
 *
 * The Python reference (docs/truck-planner/reference/truck_planner_reference.py) is the definition; this
 * class and the classes behind it are a port that reproduces tests/fixtures/truck-planner/golden_cases.json.
 *
 * Pure: no database, no configuration, no network, no clock, no time zone, no randomness, no locale. The
 * same arguments always give the same result. Numbers are PHP floats where a shape says `number` (an int is
 * accepted and widened) and ints where it says `int`.
 *
 * Errors: ModelError (an \InvalidArgumentException whose message is the code) with `invalid_date`,
 * `invalid_window` or `missing_context` as the document says, and `non_finite` when a result would hold INF
 * or NAN: every method checks what it returns. A malformed argument is a defect of the caller and is never
 * read as zero: a missing field, null or a numeric string where a number is required and a float where an
 * int is required surface as a \TypeError, an unknown segment, label or seed path as an
 * \OutOfBoundsException, an hour outside 0..23 or decimals outside 0..9 as an \OutOfRangeException. The
 * bulk arrays of the map fast path (`features`, weight rows) are the exception: they are not inspected
 * element by element.
 */
final class Estimator
{
    public const MODEL_VERSION = Vocab::MODEL_VERSION;

    /** Canonical name of 02_MODEL.md => method of this class. */
    private const CATALOGUE = [
        'round_half_away' => 'roundHalfAway', 'qkey' => 'qkey', 'seed' => 'seed',
        'validate_overrides' => 'validateOverrides', 'est_fixed' => 'estFixed', 'est_levels' => 'estLevels',
        'weakest' => 'weakest', 'est_sum' => 'estSum',
        'days_from_civil' => 'daysFromCivil', 'civil_from_days' => 'civilFromDays', 'parse_date' => 'parseDate',
        'format_date' => 'formatDate', 'day_of_week' => 'dayOfWeek', 'add_days' => 'addDays',
        'nth_weekday' => 'nthWeekday', 'last_weekday' => 'lastWeekday', 'federal_holidays' => 'federalHolidays',
        'holiday_on' => 'holidayOn', 'day_context' => 'dayContext', 'typical_context' => 'typicalContext',
        'make_context' => 'makeContext',
        'hour_weights' => 'hourWeights', 'expand_curves' => 'expandCurves',
        'haversine_m' => 'haversineM', 'walk_weight' => 'walkWeight',
        'rivals_at_origin' => 'rivalsAtOrigin', 'host_exclusion' => 'hostExclusion',
        'host_link_point' => 'hostLinkPoint', 'capture_at_point' => 'captureAtPoint', 'host_capture' => 'hostCapture',
        'weather_multiplier' => 'weatherMultiplier', 'calibration_factor' => 'calibrationFactor',
        'hourly_orders' => 'hourlyOrders', 'vectors_match' => 'vectorsMatch', 'window_orders' => 'windowOrders',
        'week_strip' => 'weekStrip', 'best_windows' => 'bestWindows',
        'evidence_from' => 'evidenceFrom', 'interval' => 'interval', 'interval_capped' => 'intervalCapped',
        'stop_money_at' => 'stopMoneyAt', 'stop_money' => 'stopMoney', 'unit_margins' => 'unitMargins',
        'break_even_orders' => 'breakEvenOrders', 'day_costs' => 'dayCosts',
        'fallback_leg' => 'fallbackLeg', 'traffic_factor' => 'trafficFactor', 'leg_minutes' => 'legMinutes',
        'required_leg_keys' => 'requiredLegKeys', 'build_timeline' => 'buildTimeline', 'evaluate' => 'evaluate',
        'day_plan' => 'dayPlan',
        'calibrate' => 'calibrate', 'accuracy_report' => 'accuracyReport', 'event_orders' => 'eventOrders',
        'catering_money' => 'cateringMoney', 'suggest_day' => 'suggestDay', 'suggest_week' => 'suggestWeek',
        'scout_estimate' => 'scoutEstimate', 'strip_from_rows' => 'stripFromRows', 'scout_rank' => 'scoutRank',
        'map_weight_rows' => 'mapWeightRows', 'cell_scores' => 'cellScores', 'score_byte' => 'scoreByte',
    ];

    /**
     * Run a function by its canonical name with named arguments, the way a golden case states them
     * (02_MODEL.md 8.1): the keys of $args are the parameter names of the document. `A` may arrive as
     * { overrides, region } and gets the seed file; validate_overrides gets the seed file as `seeds`. A
     * tuple comes back as a list in the written order. A call that must fail raises ModelError.
     *
     * @param array<string, mixed> $args
     */
    public static function dispatch(string $function, array $args): mixed
    {
        if (!isset(self::CATALOGUE[$function])) {
            throw new \BadFunctionCallException('unknown model function: ' . $function);
        }
        if (isset($args['A']) && is_array($args['A']) && !array_key_exists('seeds', $args['A'])) {
            $args['A'] = Seeds::assumptions($args['A']['overrides'], $args['A']['region']);
        }
        if ($function === 'validate_overrides' && !array_key_exists('seeds', $args)) {
            $args['seeds'] = Seeds::data();
        }
        $named = [];
        foreach ($args as $name => $value) {
            $named[self::parameterName((string) $name)] = $value;
        }
        $method = self::CATALOGUE[$function];
        return self::$method(...$named);
    }

    /**
     * The canonical names dispatch() accepts.
     *
     * @return list<string>
     */
    public static function functions(): array
    {
        return array_keys(self::CATALOGUE);
    }

    // ---------------------------------------------------------------------------------------------------
    // 1.4 rounding and ranking keys, 2 seeds, 3 estimates
    // ---------------------------------------------------------------------------------------------------

    /** 1.4: the only rounding helper, half away from zero. decimals 0..9. */
    public static function roundHalfAway(float $x, int $decimals): float
    {
        return Finite::check(Num::roundHalfAway($x, $decimals), __FUNCTION__);
    }

    /** 1.4: ranking key in whole millionths. Every comparison that decides an order or a tie uses it. */
    public static function qkey(float $x): int
    {
        return Num::qkey($x);
    }

    /**
     * 2.1: read a seed by its dot-separated path; an override under exactly that path wins.
     *
     * @param array<string, mixed> $A Assumptions
     */
    public static function seed(array $A, string $path): mixed
    {
        return Finite::check(Seeds::read($A, $path), __FUNCTION__);
    }

    /**
     * 2.2: the problems of a sparse override map, [ { path, error } ] in ascending path order. Empty = valid.
     *
     * @param array<string, mixed> $seeds the seed file as an array (Seeds::data())
     * @param array<int|string, mixed> $overrides
     * @return list<array{path: string, error: string}>
     */
    public static function validateOverrides(array $seeds, array $overrides): array
    {
        return Seeds::validate($seeds, $overrides);
    }

    /** @return array{value: float, low: float, high: float, confidence: string} */
    public static function estFixed(float $x): array
    {
        return Finite::check(Estimates::fixed($x), __FUNCTION__);
    }

    /** @return array{value: float, low: float, high: float, confidence: string} */
    public static function estLevels(float $v, float $l, float $h, string $c): array
    {
        return Finite::check(Estimates::levels($v, $l, $h, $c), __FUNCTION__);
    }

    /**
     * @param array<int, string> $labels
     */
    public static function weakest(array $labels): string
    {
        return Estimates::weakest($labels);
    }

    /**
     * @param array<int, array<string, mixed>> $estimates
     * @return array{value: float, low: float, high: float, confidence: string}
     */
    public static function estSum(array $estimates): array
    {
        return Finite::check(Estimates::sum($estimates), __FUNCTION__);
    }

    // ---------------------------------------------------------------------------------------------------
    // 4.1 dates, holidays, day context
    // ---------------------------------------------------------------------------------------------------

    public static function daysFromCivil(int $y, int $m, int $d): int
    {
        return Dates::daysFromCivil($y, $m, $d);
    }

    /** @return array{0: int, 1: int, 2: int} [y, m, d] */
    public static function civilFromDays(int $z): array
    {
        return Dates::civilFromDays($z);
    }

    /**
     * Exactly "YYYY-MM-DD", a real date, 1970..2199; otherwise ModelError invalid_date.
     *
     * @return array{0: int, 1: int, 2: int} [y, m, d]
     */
    public static function parseDate(string $s): array
    {
        return Dates::parseDate($s);
    }

    public static function formatDate(int $y, int $m, int $d): string
    {
        return Dates::formatDate($y, $m, $d);
    }

    /** 0 = Monday ... 6 = Sunday. */
    public static function dayOfWeek(string $date): int
    {
        return Dates::dayOfWeek($date);
    }

    public static function addDays(string $date, int $n): string
    {
        return Dates::addDays($date, $n);
    }

    public static function nthWeekday(int $year, int $month, int $dow, int $n): string
    {
        return Dates::nthWeekday($year, $month, $dow, $n);
    }

    public static function lastWeekday(int $year, int $month, int $dow): string
    {
        return Dates::lastWeekday($year, $month, $dow);
    }

    /**
     * @param array<string, mixed> $flags { inauguration_day: bool }
     * @return list<array<string, mixed>> Holiday list, by (date, rule order)
     */
    public static function federalHolidays(int $year, array $flags): array
    {
        return Dates::federalHolidays($year, $flags);
    }

    /**
     * @param array<string, mixed> $flags
     * @return array<string, mixed>|null Holiday observed on the date
     */
    public static function holidayOn(string $date, array $flags): ?array
    {
        return Dates::holidayOn($date, $flags);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<int, mixed>|null $forecast [HourForecast? x24], stored as given
     * @return array<string, mixed> DayContext
     */
    public static function dayContext(
        array $A,
        string $date,
        ?string $treatAs,
        ?array $forecast,
        ?float $fuelPricePerGal,
        ?string $fuelPriceSource
    ): array {
        return Finite::check(
            Contexts::dayContext($A, $date, $treatAs, $forecast, $fuelPricePerGal, $fuelPriceSource),
            __FUNCTION__
        );
    }

    /**
     * A typical week: no date, no holiday, no weather, no fuel price.
     *
     * @param array<string, mixed> $A
     * @return array<string, mixed> DayContext
     */
    public static function typicalContext(array $A, int $dow): array
    {
        return Finite::check(Contexts::typicalContext($A, $dow), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed>|null $hol
     * @param array<int, mixed>|null $forecast
     * @return array<string, mixed> DayContext
     */
    public static function makeContext(
        array $A,
        ?string $date,
        int $dow,
        int $effDow,
        ?string $cls,
        ?array $hol,
        ?string $treatAs,
        ?array $forecast,
        ?float $fuelPricePerGal,
        ?string $fuelPriceSource,
        bool $typical
    ): array {
        return Finite::check(
            Contexts::makeContext($A, $date, $dow, $effDow, $cls, $hol, $treatAs, $forecast, $fuelPricePerGal, $fuelPriceSource, $typical),
            __FUNCTION__
        );
    }

    // ---------------------------------------------------------------------------------------------------
    // 4.2 curves, 4.3 geometry
    // ---------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $ctx DayContext
     * @return array{presence: list<list<float>>, intent: list<list<float>>} [16][24] each
     */
    public static function hourWeights(array $A, array $ctx): array
    {
        return Finite::check(Curves::hourWeights($A, $ctx), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @return array{presence: list<list<float>>, intent: list<list<float>>} [16][168] each
     */
    public static function expandCurves(array $A): array
    {
        return Finite::check(Curves::expandCurves($A), __FUNCTION__);
    }

    /** Metres on a sphere of radius 6371008.8: the only distance function of the model. */
    public static function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return Finite::check(Geometry::haversineM($lat1, $lng1, $lat2, $lng2), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     */
    public static function walkWeight(array $A, float $d): float
    {
        return Finite::check(Geometry::walkWeight($A, $d), __FUNCTION__);
    }

    // ---------------------------------------------------------------------------------------------------
    // 4.4 capture, 4.5 host term
    // ---------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $A
     * @param array<int, array<string, mixed>> $outlets Outlet list
     * @return array{day: float, eve: float}
     */
    public static function rivalsAtOrigin(array $A, float $lat, float $lng, array $outlets): array
    {
        return Finite::check(Capture::rivalsAtOrigin($A, $lat, $lng, $outlets), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed>|null $host
     * @return array{point_ids: list<string>, segment: ?string, amount: float} Exclusion
     */
    public static function hostExclusion(array $A, ?array $host): array
    {
        return Finite::check(Capture::hostExclusion($A, $host), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $host
     * @param array<int, array<string, mixed>> $sources SourcePoint list
     */
    public static function hostLinkPoint(array $A, float $lat, float $lng, array $host, array $sources): ?string
    {
        return Capture::hostLinkPoint($A, $lat, $lng, $host, $sources);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<int, array<string, mixed>> $sources SourcePoint list
     * @param array<int, array<string, mixed>> $outlets Outlet list
     * @param array<string, mixed> $exclusion Exclusion
     * @return array<string, mixed> LocationVectors (in_region true, region_id and dataset_version null)
     */
    public static function captureAtPoint(
        array $A,
        float $lat,
        float $lng,
        string $visibility,
        array $sources,
        array $outlets,
        array $exclusion
    ): array {
        return Finite::check(Capture::captureAtPoint($A, $lat, $lng, $visibility, $sources, $outlets, $exclusion), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed>|null $host
     * @param array<string, mixed> $rivalsHere LocationVectors.rivals
     * @return array{day: float, eve: float, share: array{day: float, eve: float}, mode: ?string}
     */
    public static function hostCapture(array $A, ?array $host, string $visibility, array $rivalsHere): array
    {
        return Finite::check(Capture::hostCapture($A, $host, $visibility, $rivalsHere), __FUNCTION__);
    }

    // ---------------------------------------------------------------------------------------------------
    // 4.6 weather, 4.7 demand and orders, 4.8 ranges
    // ---------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed>|null $fc HourForecast
     * @return array<string, mixed> WeatherDetail
     */
    public static function weatherMultiplier(array $A, ?array $fc, string $setting): array
    {
        return Finite::check(Weather::multiplier($A, $fc, $setting), __FUNCTION__);
    }

    /**
     * @param array<string, mixed>|null $cal CalibrationState
     * @return array{0: float, 1: float} [truck_factor, spot_factor]
     */
    public static function calibrationFactor(?array $cal, ?string $spotId): array
    {
        return Finite::check(Demand::calibrationFactor($cal, $spotId), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $terms SpotTerms
     * @param array<string, mixed> $vectors LocationVectors
     * @param array<string, mixed>|null $cal CalibrationState
     * @param array<string, mixed> $ctx DayContext
     * @return array<string, mixed> HourResult
     */
    public static function hourlyOrders(array $A, array $profile, array $terms, array $vectors, ?array $cal, array $ctx, int $hour): array
    {
        return Finite::check(Demand::hourlyOrders($A, $profile, $terms, $vectors, $cal, $ctx, $hour), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $terms
     * @param array<string, mixed> $vectors
     */
    public static function vectorsMatch(array $A, array $terms, array $vectors): bool
    {
        return Capture::vectorsMatch($A, $terms, $vectors);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     * @param array<string, mixed> $vectors
     * @param array<string, mixed>|null $cal
     * @param array<string, mixed> $ctx
     * @param array<string, mixed>|null $ctxNext context of the next civil date; null only when close <= 1440
     * @return array<string, mixed> WindowResult
     */
    public static function windowOrders(
        array $A,
        array $profile,
        array $terms,
        array $vectors,
        ?array $cal,
        array $ctx,
        ?array $ctxNext,
        int $open,
        int $close
    ): array {
        return Finite::check(
            Demand::windowOrders($A, $profile, $terms, $vectors, $cal, $ctx, $ctxNext, $open, $close),
            __FUNCTION__
        );
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     * @param array<string, mixed> $vectors
     * @param array<string, mixed>|null $cal
     * @return list<float> 168 values, how = dow * 24 + hour
     */
    public static function weekStrip(array $A, array $profile, array $terms, array $vectors, ?array $cal): array
    {
        return Finite::check(Demand::weekStrip($A, $profile, $terms, $vectors, $cal), __FUNCTION__);
    }

    /**
     * @param array<int, float|int> $values
     * @param array<int, mixed>|null $allowed
     * @return list<array{start: int, length: int, total: float}>
     */
    public static function bestWindows(array $values, int $length, int $topN, bool $circular, ?array $allowed = null): array
    {
        return Finite::check(Demand::bestWindows($values, $length, $topN, $circular, $allowed), __FUNCTION__);
    }

    /**
     * @param array<string, mixed>|null $cal
     * @return array<string, mixed> Evidence
     */
    public static function evidenceFrom(?array $cal, ?string $spotId): array
    {
        return Finite::check(Ranges::evidenceFrom($cal, $spotId), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $ev Evidence
     * @return array{0: array<string, mixed>, 1: array<string, float>} [Estimate, spread]
     */
    public static function interval(array $A, float $mean, array $ev): array
    {
        return Finite::check(Ranges::interval($A, $mean, $ev), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<int, float|int> $d demand of each loop hour, times its fraction
     * @param array<int, float|int> $c capacity of each loop hour, times its fraction
     * @param array<string, mixed> $ev Evidence
     * @return array{0: array<string, mixed>, 1: array<string, float>} [Estimate, spread]
     */
    public static function intervalCapped(array $A, array $d, array $c, array $ev): array
    {
        return Finite::check(Ranges::intervalCapped($A, $d, $c, $ev), __FUNCTION__);
    }

    // ---------------------------------------------------------------------------------------------------
    // 4.9 money, 4.10 driving, 4.11 timeline, 4.12 day plan
    // ---------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     * @return array<string, float> the eight money lines at one number of orders
     */
    public static function stopMoneyAt(array $profile, array $terms, float $orders): array
    {
        return Finite::check(MoneyLines::stopMoneyAt($profile, $terms, $orders), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     * @param array<string, mixed> $orders Estimate
     * @return array<string, mixed> StopMoney
     */
    public static function stopMoney(array $profile, array $terms, array $orders): array
    {
        return Finite::check(MoneyLines::stopMoney($profile, $terms, $orders), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     * @return array{at_minimum: float, at_percentage: float}
     */
    public static function unitMargins(array $profile, array $terms): array
    {
        return Finite::check(MoneyLines::unitMargins($profile, $terms), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     */
    public static function breakEvenOrders(array $profile, array $terms, float $fixedCosts): ?float
    {
        return Finite::check(MoneyLines::breakEvenOrders($profile, $terms, $fixedCosts), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $timeline Timeline
     * @return array<string, float> { labour, fuel, tolls, fixed, total, paid_hours, drive_gallons, generator_gallons }
     */
    public static function dayCosts(array $profile, array $timeline, float $fuelPricePerGal): array
    {
        return Finite::check(MoneyLines::dayCosts($profile, $timeline, $fuelPricePerGal), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @return array<string, mixed> LegInput with source "fallback"
     */
    public static function fallbackLeg(array $A, float $lat1, float $lng1, float $lat2, float $lng2): array
    {
        return Finite::check(Driving::fallbackLeg($A, $lat1, $lng1, $lat2, $lng2), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $ctx
     * @return array{0: float, 1: int, 2: int} [factor, dow, hour]
     */
    public static function trafficFactor(array $A, array $ctx, int $minute): array
    {
        return Finite::check(Driving::trafficFactor($A, $ctx, $minute), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $leg LegInput
     * @param array<string, mixed> $ctx
     * @return array<string, mixed> Leg (from_id, to_id and depart_minute null)
     */
    public static function legMinutes(array $A, array $profile, array $leg, array $ctx, int $lookupMinute): array
    {
        return Finite::check(Driving::legMinutes($A, $profile, $leg, $ctx, $lookupMinute), __FUNCTION__);
    }

    /**
     * @param array<int, array<string, mixed>> $stops StopInput list
     * @return list<string> "<from_id>><to_id>" keys
     */
    public static function requiredLegKeys(array $stops): array
    {
        return Timeline::requiredLegKeys($stops);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $ctx
     * @param array<int, array<string, mixed>> $stops StopInput list, in the owner's order
     * @param array<string, array<string, mixed>> $legs LegInput by "<from_id>><to_id>"; a missing key is a fallback leg
     * @return array<string, mixed> Timeline
     */
    public static function buildTimeline(array $A, array $profile, array $ctx, array $stops, array $legs): array
    {
        return Finite::check(Timeline::buildTimeline($A, $profile, $ctx, $stops, $legs), __FUNCTION__);
    }

    /**
     * The internal result of one whole day as planned (no warnings, no "adds").
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $plan PlanInput
     * @param array<string, mixed> $ctx DayContext with a fuel price
     * @param array<string, mixed>|null $ctxNext
     * @param array<string, array<string, mixed>> $legs
     * @param array<string, mixed>|null $cal
     * @return array<string, mixed> { timeline, stops, costs, totals, take_home, take_home_per_hour, work_hours }
     */
    public static function evaluate(array $A, array $profile, array $plan, array $ctx, ?array $ctxNext, array $legs, ?array $cal): array
    {
        return Finite::check(Planning::evaluate($A, $profile, $plan, $ctx, $ctxNext, $legs, $cal), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $plan PlanInput
     * @param array<string, mixed> $ctx DayContext with a fuel price
     * @param array<string, mixed>|null $ctxNext
     * @param array<string, array<string, mixed>> $legs resolve requiredLegKeys() first
     * @param array<string, mixed>|null $cal
     * @return array<string, mixed> DayResult
     */
    public static function dayPlan(array $A, array $profile, array $plan, array $ctx, ?array $ctxNext, array $legs, ?array $cal): array
    {
        return Finite::check(Planning::dayPlan($A, $profile, $plan, $ctx, $ctxNext, $legs, $cal), __FUNCTION__);
    }

    // ---------------------------------------------------------------------------------------------------
    // 4.13 calibration and accuracy, 4.14 events and catering
    // ---------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $A
     * @param array<int, array<string, mixed>> $services ServiceLogEntry list
     * @return array<string, mixed> CalibrationState
     */
    public static function calibrate(array $A, array $services, string $asOf): array
    {
        return Finite::check(Calibration::calibrate($A, $services, $asOf), __FUNCTION__);
    }

    /**
     * @param array<int, array<string, mixed>> $entries ServiceLogEntry list
     * @return array<string, mixed> AccuracyReport
     */
    public static function accuracyReport(array $entries): array
    {
        return Finite::check(Calibration::accuracyReport($entries), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $ev EventTerms
     * @param array<string, mixed>|null $cal
     * @param array<string, mixed> $ctx
     * @param array<string, mixed>|null $ctxNext
     * @return array<string, mixed> EventResult
     */
    public static function eventOrders(array $A, array $profile, array $ev, ?array $cal, array $ctx, ?array $ctxNext, int $open, int $close): array
    {
        return Finite::check(Events::eventOrders($A, $profile, $ev, $cal, $ctx, $ctxNext, $open, $close), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $ct CateringTerms
     * @return array<string, mixed> StopMoney, every line fixed
     */
    public static function cateringMoney(array $profile, array $ct): array
    {
        return Finite::check(Events::cateringMoney($profile, $ct), __FUNCTION__);
    }

    // ---------------------------------------------------------------------------------------------------
    // 4.15 suggestions, 4.16 scouting, 4.17 map fast path
    // ---------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $ctx DayContext with a fuel price
     * @param array<string, mixed>|null $ctxNext
     * @param array<int, array<string, mixed>> $spots SpotInput list
     * @param array<string, array<string, mixed>> $legs every ordered pair among "base" and the spot ids
     * @param array<string, mixed>|null $cal
     * @param array<string, mixed>|null $options SuggestOptions; null or a null option takes the default
     * @return list<array<string, mixed>> Suggestion list, best first
     */
    public static function suggestDay(array $A, array $profile, array $ctx, ?array $ctxNext, array $spots, array $legs, ?array $cal, ?array $options = null): array
    {
        return Finite::check(Suggestions::suggestDay($A, $profile, $ctx, $ctxNext, $spots, $legs, $cal, $options), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<int, array<string, mixed>> $contexts eight DayContexts from week_start (a Monday)
     * @param array<int, array<string, mixed>> $spots
     * @param array<string, array<string, mixed>> $legs
     * @param array<string, mixed>|null $cal
     * @param array<string, mixed>|null $options
     * @return array<string, mixed> WeekSuggestion
     */
    public static function suggestWeek(array $A, array $profile, string $weekStart, array $contexts, array $spots, array $legs, ?array $cal, ?array $options = null): array
    {
        return Finite::check(Suggestions::suggestWeek($A, $profile, $weekStart, $contexts, $spots, $legs, $cal, $options), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $place PlaceInput
     * @param array<string, array<string, mixed>> $legs
     * @param array<string, mixed>|null $cal
     * @return array<string, mixed>|null ScoutResult with position 0, or null for a type that does not host
     */
    public static function scoutEstimate(array $A, array $profile, array $place, array $legs, ?array $cal, float $fuelPricePerGal): ?array
    {
        return Finite::check(Scouting::scoutEstimate($A, $profile, $place, $legs, $cal, $fuelPricePerGal), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $terms
     * @param array<string, mixed> $vectors
     * @param array<string, mixed> $rows the result of mapWeightRows
     * @return list<float> 168 values
     */
    public static function stripFromRows(array $A, array $profile, array $terms, array $vectors, array $rows): array
    {
        return Finite::check(Scouting::stripFromRows($A, $profile, $terms, $vectors, $rows), __FUNCTION__);
    }

    /**
     * @param array<int, array<string, mixed>> $results ScoutResult list (no nulls)
     * @return list<array<string, mixed>> best first, numbered from 1, at most scout.max_results
     */
    public static function scoutRank(array $results): array
    {
        return Finite::check(Scouting::scoutRank($results), __FUNCTION__);
    }

    /**
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<string, mixed>|null $cal
     * @return array{w_opp: list<list<float>>, w_people: list<list<float>>} [168][16] each
     */
    public static function mapWeightRows(array $A, array $profile, ?array $cal): array
    {
        return Finite::check(FastPath::mapWeightRows($A, $profile, $cal), __FUNCTION__);
    }

    /**
     * @param array<int, float|int> $features 50 numbers per cell
     * @param array<int, float|int> $wOppRow
     * @param array<int, float|int> $wPeopleRow
     * @return array{opportunity: list<float>, people: list<float>, competition: list<float>}
     */
    public static function cellScores(array $features, int $n, array $wOppRow, array $wPeopleRow, string $regime, float $capacity): array
    {
        return Finite::check(FastPath::cellScores($features, $n, $wOppRow, $wPeopleRow, $regime, $capacity), __FUNCTION__);
    }

    /** Map colour byte 0..255. */
    public static function scoreByte(float $x, float $hi): int
    {
        return FastPath::scoreByte($x, $hi);
    }

    /** A canonical argument name as the parameter name of this class: fuel_price_per_gal => fuelPricePerGal. */
    private static function parameterName(string $name): string
    {
        $parts = explode('_', $name);
        $out = $parts[0];
        for ($i = 1; $i < count($parts); $i++) {
            $part = $parts[$i];
            if ($part === '') {
                continue;
            }
            $out .= strtr($part[0], 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') . substr($part, 1);
        }
        return $out;
    }
}
