<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * A whole day as planned: timeline, each stop's orders and money, the day's costs and take-home, what each
 * stop adds, the unpaid-gap alternative and the warnings (02_MODEL.md 4.12).
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Planning
{
    private const TOTAL_LINES = [
        'sales' => 'sales', 'food_cost' => 'food_cost', 'packaging' => 'packaging', 'card_fees' => 'card_fees',
        'spot_fees' => 'spot_fee', 'tips' => 'tips', 'contribution' => 'contribution',
    ];

    private const ESTIMATE_TOTALS = [
        'orders', 'sales', 'food_cost', 'packaging', 'card_fees', 'spot_fees', 'tips', 'contribution',
        'labour', 'fuel', 'tolls', 'fixed_cost', 'take_home', 'take_home_per_hour',
    ];

    /**
     * One whole day as planned. dayPlan calls it for the plan, for the plan without each stop and for the
     * unpaid-gap alternative; suggestDay calls it for every candidate plan.
     *
     * With a memo and stop keys, the orders and money of a stop are computed once per (stop key, effective
     * opening minute, closing minute): within one call they depend on nothing else.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $plan PlanInput
     * @param array<string, mixed> $ctx DayContext (fuel_price_per_gal must be a number)
     * @param array<string, mixed>|null $ctxNext
     * @param array<string, array<string, mixed>> $legs
     * @param array<string, mixed>|null $cal
     * @param list<int|string>|null $stopKeys one memo key per stop of the plan
     * @return array<string, mixed> { timeline, stops, costs, totals, take_home, take_home_per_hour, work_hours }
     */
    public static function evaluate(
        array $A,
        array $profile,
        array $plan,
        array $ctx,
        ?array $ctxNext,
        array $legs,
        ?array $cal,
        ?Memo $memo = null,
        ?array $stopKeys = null
    ): array {
        $stopsIn = array_values($plan['stops']);
        $T = Timeline::buildTimeline($A, $profile, $ctx, $stopsIn, $legs);
        $stops = [];
        foreach ($stopsIn as $i => $s) {
            $effectiveOpen = $T['stops'][$i]['effective_open'];
            $closeMinute = Num::i($s['close_minute']);
            $kind = $s['kind'];
            $window = null;
            $event = null;
            if ($kind === 'spot' || $kind === 'event') {
                $key = ($memo !== null && $stopKeys !== null) ? $stopKeys[$i] . ':' . $effectiveOpen . ':' . $closeMinute : null;
                if ($key !== null && isset($memo->stops[$key])) {
                    [$window, $event, $orders, $money] = $memo->stops[$key];
                } else {
                    if ($kind === 'spot') {
                        $window = Demand::windowOrders($A, $profile, $s['terms'], $s['vectors'], $cal, $ctx, $ctxNext, $effectiveOpen, $closeMinute, $memo);
                        $orders = $window['orders'];
                    } else {
                        $E = Events::eventOrders($A, $profile, $s['event'], $cal, $ctx, $ctxNext, $effectiveOpen, $closeMinute);
                        $orders = $E['orders'];
                        $event = ['buyers' => $E['buyers'], 'demand' => $E['demand'], 'hours' => $E['hours'], 'spread' => $E['spread']];
                    }
                    $money = MoneyLines::stopMoney($profile, $s['terms'], $orders);
                    if ($key !== null) {
                        $memo->stops[$key] = [$window, $event, $orders, $money];
                    }
                }
            } else {                                      // catering
                $money = Events::cateringMoney($profile, $s['catering']);
                $orders = $money['orders'];
            }
            $stops[] = [
                'stop_index' => $i,
                'id' => $s['id'],
                'kind' => $kind,
                'spot_id' => $s['spot_id'] ?? null,
                'window' => $window,
                'event' => $event,
                'orders' => $orders,
                'money' => $money,
            ];
        }

        $C = MoneyLines::dayCosts($profile, $T, $ctx['fuel_price_per_gal']);
        $orderLines = [];
        foreach ($stops as $st) {
            $orderLines[] = $st['orders'];
        }
        $totals = ['orders' => Estimates::sum($orderLines)];
        foreach (self::TOTAL_LINES as $name => $line) {
            $lines = [];
            foreach ($stops as $st) {
                $lines[] = $st['money'][$line];
            }
            $totals[$name] = Estimates::sum($lines);
        }
        $totals['labour'] = Estimates::fixed($C['labour']);
        $totals['fuel'] = Estimates::fixed($C['fuel']);
        $totals['tolls'] = Estimates::fixed($C['tolls']);
        $totals['fixed_cost'] = Estimates::fixed($C['fixed']);
        $contribution = $totals['contribution'];
        $takeHome = [
            'value' => $contribution['value'] - $C['total'],
            'low' => $contribution['low'] - $C['total'],
            'high' => $contribution['high'] - $C['total'],
            'confidence' => $contribution['confidence'],
        ];
        $workHours = ($T['day_minutes'] - $T['unpaid_gap_minutes']) / 60.0;
        $perHour = [
            'value' => $workHours > 0 ? $takeHome['value'] / $workHours : 0.0,
            'low' => $workHours > 0 ? $takeHome['low'] / $workHours : 0.0,
            'high' => $workHours > 0 ? $takeHome['high'] / $workHours : 0.0,
            'confidence' => $takeHome['confidence'],
        ];
        $totals['take_home'] = $takeHome;
        $totals['take_home_per_hour'] = $perHour;
        $totals['day_hours'] = $T['day_minutes'] / 60.0;
        $totals['paid_hours'] = $C['paid_hours'];
        $totals['work_hours'] = $workHours;
        $totals['unpaid_gap_hours'] = $T['unpaid_gap_minutes'] / 60.0;
        $totals['drive_minutes'] = $T['drive_minutes'];
        $totals['miles'] = $T['miles'];
        $totals['drive_gallons'] = $C['drive_gallons'];
        $totals['generator_gallons'] = $C['generator_gallons'];
        return [
            'timeline' => $T,
            'stops' => $stops,
            'costs' => $C,
            'totals' => $totals,
            'take_home' => $takeHome,
            'take_home_per_hour' => $perHour,
            'work_hours' => $workHours,
        ];
    }

    /**
     * Evaluate a day as the owner ordered it.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $plan PlanInput
     * @param array<string, mixed> $ctx DayContext
     * @param array<string, mixed>|null $ctxNext
     * @param array<string, array<string, mixed>> $legs
     * @param array<string, mixed>|null $cal
     * @return array<string, mixed> DayResult
     */
    public static function dayPlan(
        array $A,
        array $profile,
        array $plan,
        array $ctx,
        ?array $ctxNext,
        array $legs,
        ?array $cal
    ): array {
        $stopsIn = array_values($plan['stops']);
        $n = count($stopsIn);
        $seedsRevision = $A['seeds_revision'];

        // A plan with an invalid window or overlapping stops is not evaluated.
        $blocking = [];
        for ($i = 0; $i < $n; $i++) {
            $open = Num::i($stopsIn[$i]['open_minute']);
            $close = Num::i($stopsIn[$i]['close_minute']);
            if ($open < 0 || $close > 2880 || $close <= $open) {
                $blocking[] = self::warning('invalid_window', 'error', $i, ['open_minute' => $open, 'close_minute' => $close]);
            }
        }
        for ($i = 1; $i < $n; $i++) {
            $open = Num::i($stopsIn[$i]['open_minute']);
            $previousClose = Num::i($stopsIn[$i - 1]['close_minute']);
            if ($open < $previousClose) {
                $blocking[] = self::warning('stops_overlap', 'error', $i, ['open_minute' => $open, 'previous_close_minute' => $previousClose]);
            }
        }
        if ($blocking !== []) {
            $totals = [];
            foreach (self::ESTIMATE_TOTALS as $name) {
                $totals[$name] = Estimates::fixed(0.0);
            }
            foreach (['day_hours', 'paid_hours', 'work_hours', 'unpaid_gap_hours'] as $name) {
                $totals[$name] = 0.0;
            }
            $totals['drive_minutes'] = 0;
            $totals['miles'] = 0.0;
            $totals['drive_gallons'] = 0.0;
            $totals['generator_gallons'] = 0.0;
            return [
                'model_version' => Vocab::MODEL_VERSION,
                'seeds_revision' => $seedsRevision,
                'date' => $plan['date'],
                'timeline' => Timeline::emptyTimeline(),
                'stops' => [],
                'totals' => $totals,
                'unpaid_gap_alternative' => null,
                'warnings' => $blocking,
            ];
        }

        $memo = new Memo();
        $keys = $n > 0 ? range(0, $n - 1) : [];
        $R = self::evaluate($A, $profile, ['date' => $plan['date'], 'stops' => $stopsIn], $ctx, $ctxNext, $legs, $cal, $memo, $keys);
        $T = $R['timeline'];

        // What each stop adds: the whole day with the stop minus the whole day without it.
        $dayStops = [];
        for ($i = 0; $i < $n; $i++) {
            $s = $stopsIn[$i];
            $without = [
                'date' => $plan['date'],
                'stops' => array_merge(array_slice($stopsIn, 0, $i), array_slice($stopsIn, $i + 1)),
            ];
            $withoutKeys = array_merge(array_slice($keys, 0, $i), array_slice($keys, $i + 1));
            $Ri = self::evaluate($A, $profile, $without, $ctx, $ctxNext, $legs, $cal, $memo, $withoutKeys);
            $addTakeHome = Estimates::levels(
                $R['take_home']['value'] - $Ri['take_home']['value'],
                $R['take_home']['low'] - $Ri['take_home']['low'],
                $R['take_home']['high'] - $Ri['take_home']['high'],
                $R['stops'][$i]['orders']['confidence']
            );
            $addHours = $R['work_hours'] - $Ri['work_hours'];
            $addPerHour = null;
            if ($addHours > 0) {
                $addPerHour = Estimates::levels(
                    $addTakeHome['value'] / $addHours,
                    $addTakeHome['low'] / $addHours,
                    $addTakeHome['high'] / $addHours,
                    $addTakeHome['confidence']
                );
            }
            $addedCosts = $R['costs']['total'] - $Ri['costs']['total'];
            $breakEven = null;
            if ($s['kind'] === 'spot' || $s['kind'] === 'event') {
                $breakEven = MoneyLines::breakEvenOrders($profile, $s['terms'], $addedCosts);
            }
            $usesFallback = false;
            foreach ($Ri['timeline']['legs'] as $leg) {
                if ($leg['source'] === 'fallback') {
                    $usesFallback = true;
                }
            }
            $st = $R['stops'][$i];
            $st['adds'] = [
                'take_home' => $addTakeHome,
                'hours' => $addHours,
                'per_hour' => $addPerHour,
                'added_costs' => $addedCosts,
                'break_even_orders' => $breakEven,
                'uses_fallback_leg' => $usesFallback,
            ];
            $dayStops[] = $st;
        }

        // What the day would clear if every paid gap were an unpaid break.
        $alternative = null;
        $hasPaidGap = false;
        foreach ($T['stops'] as $ts) {
            if ($ts['stop_index'] >= 1 && $ts['gap_before_minutes'] > 0 && !$ts['gap_unpaid']) {
                $hasPaidGap = true;
            }
        }
        if ($hasPaidGap) {
            $unpaidStops = [];
            foreach ($stopsIn as $s) {
                $s['gap_before_unpaid'] = true;
                $unpaidStops[] = $s;
            }
            $Ru = self::evaluate($A, $profile, ['date' => $plan['date'], 'stops' => $unpaidStops], $ctx, $ctxNext, $legs, $cal, $memo, $keys);
            $alternative = [
                'take_home' => $Ru['take_home'],
                'take_home_per_hour' => $Ru['take_home_per_hour'],
                'work_hours' => $Ru['work_hours'],
                'labour_saved' => $R['costs']['labour'] - $Ru['costs']['labour'],
            ];
        }

        return [
            'model_version' => Vocab::MODEL_VERSION,
            'seeds_revision' => $seedsRevision,
            'date' => $plan['date'],
            'timeline' => $T,
            'stops' => $dayStops,
            'totals' => $R['totals'],
            'unpaid_gap_alternative' => $alternative,
            'warnings' => $n > 0 ? self::warnings($A, $stopsIn, $ctx, $ctxNext, $T, $dayStops) : [],
        ];
    }

    /**
     * The warnings of an evaluated day, in the order of the table in 4.12; stops in index order within a code.
     *
     * @param array<string, mixed> $A
     * @param list<array<string, mixed>> $stopsIn
     * @param array<string, mixed> $ctx
     * @param array<string, mixed>|null $ctxNext
     * @param array<string, mixed> $T Timeline
     * @param list<array<string, mixed>> $dayStops
     * @return list<array<string, mixed>>
     */
    private static function warnings(array $A, array $stopsIn, array $ctx, ?array $ctxNext, array $T, array $dayStops): array
    {
        $n = count($stopsIn);
        $warnings = [];
        for ($i = 0; $i < $n; $i++) {
            $ts = $T['stops'][$i];
            if ($ts['effective_open'] >= $ts['close']) {
                $warnings[] = self::warning('stop_unreachable', 'error', $i, [
                    'arrive' => $ts['arrive'], 'effective_open' => $ts['effective_open'], 'close_minute' => $ts['close'],
                ]);
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $ts = $T['stops'][$i];
            if ($ts['late_minutes'] > 0 && !($ts['effective_open'] >= $ts['close'])) {
                $warnings[] = self::warning('late_arrival', 'warn', $i, [
                    'late_minutes' => $ts['late_minutes'], 'effective_open' => $ts['effective_open'],
                ]);
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $s = $stopsIn[$i];
            if ($s['kind'] === 'spot' && !$s['vectors']['in_region']) {
                $warnings[] = self::warning('outside_region', 'warn', $i, []);
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $s = $stopsIn[$i];
            if ($s['kind'] === 'spot' && !Capture::vectorsMatch($A, $s['terms'], $s['vectors'])) {
                $warnings[] = self::warning('stale_vectors', 'error', $i, []);
            }
        }
        $dow = Num::i($ctx['dow']);
        for ($i = 0; $i < $n; $i++) {
            $s = $stopsIn[$i];
            if ($s['kind'] === 'spot' && ($s['terms']['allowed'] ?? null) !== null) {
                $allowed = $s['terms']['allowed'];
                $open = Num::i($s['open_minute']);
                $close = Num::i($s['close_minute']);
                if (!$allowed['days'][$dow] || $open < Num::i($allowed['open_minute']) || $close > Num::i($allowed['close_minute'])) {
                    $warnings[] = self::warning('outside_allowed_hours', 'warn', $i, [
                        'dow' => $dow, 'open_minute' => $open, 'close_minute' => $close,
                    ]);
                }
            }
        }
        $fallbackKeys = [];
        foreach ($T['legs'] as $leg) {
            if ($leg['source'] === 'fallback') {
                $fallbackKeys[] = $leg['from_id'] . '>' . $leg['to_id'];
            }
        }
        if ($fallbackKeys !== []) {
            $warnings[] = self::warning('fallback_drive_time', 'warn', null, ['legs' => $fallbackKeys]);
        }
        $longGap = Seeds::read($A, 'timeline.long_gap_minutes');
        for ($i = 0; $i < $n; $i++) {
            $ts = $T['stops'][$i];
            if ($ts['gap_before_minutes'] >= $longGap && !$ts['gap_unpaid']) {
                $warnings[] = self::warning('long_gap', 'warn', $i, ['gap_before_minutes' => $ts['gap_before_minutes']]);
            }
        }
        if ($T['day_minutes'] > Seeds::read($A, 'timeline.long_day_minutes')) {
            $warnings[] = self::warning('long_day', 'warn', null, ['day_minutes' => $T['day_minutes']]);
        }
        $feeWarnShare = Num::f(Seeds::read($A, 'money.fee_warn_share'));
        for ($i = 0; $i < $n; $i++) {
            $money = $dayStops[$i]['money'];
            if ($money['sales']['value'] > 0 && $money['spot_fee']['value'] > $feeWarnShare * $money['sales']['value']) {
                $warnings[] = self::warning('fee_high', 'warn', $i, [
                    'spot_fee' => $money['spot_fee']['value'], 'sales' => $money['sales']['value'],
                ]);
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $add = $dayStops[$i]['adds']['take_home'];
            if ($add['value'] < 0) {
                $warnings[] = self::warning('below_break_even', 'warn', $i, ['take_home' => $add['value']]);
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $s = $stopsIn[$i];
            if ($s['kind'] === 'event') {
                $vendors = Num::f($s['event']['vendors']);
                $perVendor = Num::f($s['event']['attendance']) * Num::f(Seeds::read($A, 'events.attendance_haircut'))
                    / ($vendors > 1 ? $vendors : 1.0);
                if ($perVendor < Seeds::read($A, 'events.min_attendees_per_vendor')) {
                    $warnings[] = self::warning('event_thin_crowd', 'warn', $i, ['attendees_per_vendor' => $perVendor]);
                }
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $add = $dayStops[$i]['adds']['take_home'];
            if ($add['value'] >= 0 && $add['low'] < 0) {
                $warnings[] = self::warning('weak_day_loss', 'info', $i, ['take_home_low' => $add['low']]);
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $st = $dayStops[$i];
            $cappedHours = 0;
            if ($st['kind'] === 'spot') {
                $cappedHours = $st['window']['capped_hours'];
            } elseif ($st['kind'] === 'event') {
                foreach ($st['event']['hours'] as $eh) {
                    if ($eh['demand'] > $eh['capacity']) {
                        $cappedHours += 1;
                    }
                }
            }
            if ($cappedHours > 0) {
                $warnings[] = self::warning('capacity_bound', 'info', $i, ['capped_hours' => $cappedHours]);
            }
        }
        if ($T['start_prep'] < Seeds::read($A, 'timeline.early_start_minute')) {
            $warnings[] = self::warning('early_start', 'info', null, ['start_prep' => $T['start_prep']]);
        }
        if ($T['done'] > 1440) {
            $warnings[] = self::warning('ends_after_midnight', 'info', null, ['done' => $T['done']]);
        }
        if (!$ctx['typical']) {
            $missing = 0;
            for ($i = 0; $i < $n; $i++) {
                $s = $stopsIn[$i];
                if ($s['kind'] === 'spot' || $s['kind'] === 'event') {
                    $missing += self::missingForecastHours($A, $ctx, $ctxNext, $T['stops'][$i]['effective_open'], Num::i($s['close_minute']));
                }
            }
            if ($missing > 0) {
                $warnings[] = self::warning('no_forecast', 'info', null, ['hours' => $missing]);
            }
        }
        if ($ctx['holiday_class'] !== null) {
            $warnings[] = self::warning('holiday', 'info', null, [
                'holiday_id' => ($ctx['holiday'] ?? null) !== null ? $ctx['holiday']['id'] : null,
                'holiday_class' => $ctx['holiday_class'],
            ]);
        }
        for ($i = 0; $i < $n; $i++) {
            $st = $dayStops[$i];
            if ($st['kind'] === 'spot' && $st['window']['evidence']['weak_share'] >= 0.5) {
                $warnings[] = self::warning('weak_seed', 'info', $i, ['weak_share' => $st['window']['evidence']['weak_share']]);
            }
        }
        for ($i = 0; $i < $n; $i++) {
            $s = $stopsIn[$i];
            $st = $dayStops[$i];
            if ($s['kind'] === 'spot' && $s['terms']['host'] !== null
                && $s['terms']['host']['size_source'] === 'default' && $st['window']['host_orders'] > 0) {
                $warnings[] = self::warning('default_host_size', 'info', $i, ['size' => Num::f($s['terms']['host']['size'])]);
            }
        }
        return $warnings;
    }

    /**
     * How many clock hours of [open, close) have no usable forecast, each in the context of its own civil
     * date. An hour whose context is typical is not counted.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $ctx
     * @param array<string, mixed>|null $ctxNext
     */
    private static function missingForecastHours(array $A, array $ctx, ?array $ctxNext, int $open, int $close): int
    {
        $count = 0;
        foreach (Demand::clockHours($open, $close) as [$dayIndex, $hour]) {
            $cx = $dayIndex === 0 ? $ctx : $ctxNext;
            if ($cx === null || $cx['typical']) {
                continue;
            }
            $forecast = $cx['forecast'] ?? null;
            $fc = $forecast !== null ? ($forecast[$hour] ?? null) : null;
            if (Weather::classify($A, $fc) === null) {    // what weather_multiplier(...).missing says
                $count += 1;
            }
        }
        return $count;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{code: string, level: string, stop_index: ?int, data: array<string, mixed>}
     */
    private static function warning(string $code, string $level, ?int $stopIndex, array $data): array
    {
        return ['code' => $code, 'level' => $level, 'stop_index' => $stopIndex, 'data' => $data];
    }
}
