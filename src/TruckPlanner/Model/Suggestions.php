<?php
declare(strict_types=1);

namespace App\TruckPlanner\Model;

/**
 * The best day and the best week from the owner's saved spots (02_MODEL.md 4.15).
 *
 * Both searches are exhaustive within exact limits and have no early exit: among equal-valued results the
 * first one found wins, so the order of the search is part of the definition.
 *
 * @internal Part of the model package; the rest of the backend calls Estimator.
 */
final class Suggestions
{
    /**
     * The best day plans from the saved spots for one date, ranked by expected take-home: candidate windows
     * per spot, kept per daypart, then every ordered subset of 1..max_stops candidates that can be driven
     * without arriving late.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile TruckProfile
     * @param array<string, mixed> $ctx DayContext (with a fuel price)
     * @param array<string, mixed>|null $ctxNext
     * @param array<int, array<string, mixed>> $spots SpotInput list
     * @param array<string, array<string, mixed>> $legs
     * @param array<string, mixed>|null $cal
     * @param array<string, mixed>|null $options SuggestOptions
     * @return list<array<string, mixed>> Suggestion list
     */
    public static function suggestDay(
        array $A,
        array $profile,
        array $ctx,
        ?array $ctxNext,
        array $spots,
        array $legs,
        ?array $cal,
        ?array $options
    ): array {
        $service = Num::i(self::option($options, 'service_minutes', Seeds::read($A, 'suggest.service_minutes')));     // a multiple of 60
        $maxStops = Num::i(self::option($options, 'max_stops_per_day', Seeds::read($A, 'suggest.max_stops_per_day')));
        $limit = Num::i(self::option($options, 'limit', Seeds::read($A, 'suggest.day_results')));
        $L = Num::floorDiv($service, 60);
        $first = Num::floorDiv(Num::i(Seeds::read($A, 'suggest.earliest_open_minute')), 60);
        $last = Num::floorDiv(Num::i(Seeds::read($A, 'suggest.latest_close_minute')), 60);       // hours [first, last)
        $daypartOfHour = Seeds::read($A, 'hours.daypart_of_hour');
        $windowsPerSpot = Num::i(Seeds::read($A, 'suggest.windows_per_spot'));
        $minStopOrders = Num::f(Seeds::read($A, 'suggest.min_stop_orders'));
        $maxDayMinutes = Seeds::read($A, 'suggest.max_day_minutes');
        $dow = Num::i($ctx['dow']);
        $date = $ctx['date'];
        $memo = new Memo();

        // 1. Candidates: the best windows of each spot.
        $candidates = [];
        foreach (Capture::orderBy($spots, 'spot_id') as $spotKey => $spot) {
            $values = [];
            $allowed = [];
            $rule = $spot['terms']['allowed'] ?? null;
            $ruleOpen = $rule !== null ? Num::i($rule['open_minute']) : 0;
            $ruleClose = $rule !== null ? Num::i($rule['close_minute']) : 0;
            for ($k = 0; $k < $last - $first; $k++) {
                $hour = $first + $k;
                $values[] = Demand::hourlyOrders($A, $profile, $spot['terms'], $spot['vectors'], $cal, $ctx, $hour, $memo)['orders'];
                if ($rule === null) {
                    $allowed[] = true;
                } else {
                    $allowed[] = (bool) $rule['days'][$dow] && $hour * 60 >= $ruleOpen && ($hour + 1) * 60 <= $ruleClose;
                }
            }
            foreach (Demand::bestWindows($values, $L, $windowsPerSpot, false, $allowed) as $b) {
                if ($b['total'] < $minStopOrders) {
                    continue;
                }
                $open = ($first + $b['start']) * 60;
                $cand = [
                    'spot' => $spot,
                    'spot_id' => (string) $spot['spot_id'],
                    'open' => $open,
                    'close' => $open + $service,
                    'key' => $spotKey,
                    'position' => count($candidates),
                ];
                $single = Planning::evaluate($A, $profile, self::planOf($date, [$cand]), $ctx, $ctxNext, $legs, $cal, $memo, [$spotKey]);
                $cand['rank'] = Num::rank($single['take_home']['value']);
                $candidates[] = $cand;
            }
        }
        usort($candidates, static fn (array $a, array $b): int => ($b['rank'] <=> $a['rank'])
            ?: (strcmp($a['spot_id'], $b['spot_id']) ?: (($a['open'] <=> $b['open']) ?: ($a['position'] <=> $b['position']))));
        $count = count($candidates);
        $maxCandidates = Num::i(Seeds::read($A, 'suggest.max_candidates'));
        $perDaypart = Num::floorDiv($maxCandidates, 4);
        $keep = $count > 0 ? array_fill(0, $count, false) : [];
        $kept = 0;
        foreach (Vocab::DAYPARTS as $part) {              // so lunch cannot crowd out the evening
            $got = 0;
            for ($j = 0; $j < $count; $j++) {
                if ($got === $perDaypart) {
                    break;
                }
                if (!$keep[$j] && $daypartOfHour[Num::floorDiv($candidates[$j]['open'], 60)] === $part) {
                    $keep[$j] = true;
                    $got += 1;
                    $kept += 1;
                }
            }
        }
        for ($j = 0; $j < $count; $j++) {
            if ($kept >= $maxCandidates) {
                break;
            }
            if (!$keep[$j]) {
                $keep[$j] = true;
                $kept += 1;
            }
        }
        $pool = [];
        for ($j = 0; $j < $count; $j++) {
            if ($keep[$j]) {
                $cand = $candidates[$j];
                $cand['position'] = count($pool);
                $pool[] = $cand;
            }
        }
        usort($pool, static fn (array $a, array $b): int => ($a['open'] <=> $b['open'])
            ?: (strcmp($a['spot_id'], $b['spot_id']) ?: ($a['position'] <=> $b['position'])));

        // 2. Plans: every subset of 1..max_stops candidates, in (open, spot_id) order.
        $feasible = [];
        $poolSize = count($pool);
        $consider = static function (array $chosen) use (&$feasible, $A, $profile, $ctx, $ctxNext, $legs, $cal, $memo, $date, $maxDayMinutes): void {
            $size = count($chosen);
            for ($a = 0; $a < $size; $a++) {
                for ($b = $a + 1; $b < $size; $b++) {
                    if ($chosen[$a]['spot_id'] === $chosen[$b]['spot_id']) {
                        return;                           // a spot appears at most once per day
                    }
                }
            }
            for ($a = 1; $a < $size; $a++) {
                if ($chosen[$a]['open'] < $chosen[$a - 1]['close']) {
                    return;
                }
            }
            $plan = self::planOf($date, $chosen);
            $keys = [];
            foreach ($chosen as $cand) {
                $keys[] = $cand['key'];
            }
            $R = Planning::evaluate($A, $profile, $plan, $ctx, $ctxNext, $legs, $cal, $memo, $keys);
            foreach ($R['timeline']['stops'] as $ts) {
                if ($ts['late_minutes'] !== 0) {
                    return;
                }
            }
            if ($R['timeline']['day_minutes'] > $maxDayMinutes) {
                return;
            }
            $key = [];
            foreach ($chosen as $cand) {
                $key[] = [$cand['spot_id'], $cand['open']];
            }
            $feasible[] = [
                'plan' => $plan,
                'rank' => Num::rank($R['take_home']['value']),
                'day_minutes' => $R['timeline']['day_minutes'],
                'key' => $key,
                'position' => count($feasible),
            ];
        };
        $extend = static function (int $start, array $chosen) use (&$extend, $consider, $pool, $poolSize, $maxStops): void {
            if ($chosen !== []) {
                $consider($chosen);
            }
            if (count($chosen) === $maxStops) {
                return;
            }
            for ($j = $start; $j < $poolSize; $j++) {
                $next = $chosen;
                $next[] = $pool[$j];
                $extend($j + 1, $next);
            }
        };
        $extend(0, []);

        // 3. Rank.
        usort($feasible, static function (array $a, array $b): int {
            $order = ($b['rank'] <=> $a['rank']) ?: ((count($a['key']) <=> count($b['key'])) ?: ($a['day_minutes'] <=> $b['day_minutes']));
            if ($order !== 0) {
                return $order;
            }
            $shared = count($a['key']);                   // the two lists are equally long here
            for ($k = 0; $k < $shared; $k++) {
                $order = strcmp($a['key'][$k][0], $b['key'][$k][0]) ?: ($a['key'][$k][1] <=> $b['key'][$k][1]);
                if ($order !== 0) {
                    return $order;
                }
            }
            return $a['position'] <=> $b['position'];
        });
        $out = [];
        foreach (array_slice($feasible, 0, $limit) as $item) {
            $result = Planning::dayPlan($A, $profile, $item['plan'], $ctx, $ctxNext, $legs, $cal);
            $stops = [];
            foreach ($item['plan']['stops'] as $s) {
                $stops[] = ['spot_id' => $s['spot_id'], 'open_minute' => $s['open_minute'], 'close_minute' => $s['close_minute']];
            }
            $out[] = [
                'date' => $date,
                'position' => count($out) + 1,
                'stops' => $stops,
                'take_home' => $result['totals']['take_home'],
                'orders' => $result['totals']['orders'],
                'day_minutes' => $result['timeline']['day_minutes'],
                'result' => $result,
            ];
        }
        return $out;
    }

    /**
     * The best week from the day suggestions: which days to work and which plan on each, under a limit on
     * working days and on visits per spot. Exhaustive (at most 6^7 leaves). Among equal totals the first
     * one found wins: earlier days prefer higher-ranked plans and working over resting.
     *
     * @param array<string, mixed> $A
     * @param array<string, mixed> $profile
     * @param array<int, array<string, mixed>> $contexts eight DayContexts, Monday to the Monday after
     * @param array<int, array<string, mixed>> $spots
     * @param array<string, array<string, mixed>> $legs
     * @param array<string, mixed>|null $cal
     * @param array<string, mixed>|null $options
     * @return array<string, mixed> WeekSuggestion
     */
    public static function suggestWeek(
        array $A,
        array $profile,
        mixed $weekStart,
        array $contexts,
        array $spots,
        array $legs,
        ?array $cal,
        ?array $options
    ): array {
        $contexts = array_values($contexts);
        $dayOptions = [
            'service_minutes' => self::option($options, 'service_minutes', null),
            'max_stops_per_day' => self::option($options, 'max_stops_per_day', null),
            'limit' => Seeds::read($A, 'suggest.week_day_options'),
        ];
        $minDayTakeHome = Num::f(Seeds::read($A, 'suggest.min_day_take_home'));
        $opts = [];
        $values = [];                                     // take-home value of each option
        $visited = [];                                    // spot ids of each option
        for ($d = 0; $d < 7; $d++) {
            $opts[$d] = [];
            $values[$d] = [];
            $visited[$d] = [];
            foreach (self::suggestDay($A, $profile, $contexts[$d], $contexts[$d + 1], $spots, $legs, $cal, $dayOptions) as $p) {
                if ($p['take_home']['value'] > $minDayTakeHome) {
                    $opts[$d][] = $p;
                    $values[$d][] = $p['take_home']['value'];
                    $ids = [];
                    foreach ($p['stops'] as $stop) {
                        $ids[] = (string) $stop['spot_id'];
                    }
                    $visited[$d][] = $ids;
                }
            }
        }
        $maxDays = Num::i(self::option($options, 'max_days_per_week', Seeds::read($A, 'suggest.max_days_per_week')));
        $maxVisits = Num::i(self::option($options, 'max_visits_per_spot_per_week', Seeds::read($A, 'suggest.max_visits_per_spot_per_week')));

        $bestRank = null;
        $bestPicks = [];
        $leaves = 0;
        $visits = [];
        $picks = [];
        $search = static function (int $d, float $total, int $daysUsed) use (
            &$search, &$bestRank, &$bestPicks, &$leaves, &$visits, &$picks, $values, $visited, $maxDays, $maxVisits
        ): void {
            if ($d === 7) {
                $leaves += 1;
                $rank = Num::rank($total);
                if ($bestRank === null || $rank > $bestRank) {
                    $bestRank = $rank;
                    $bestPicks = $picks;
                }
                return;
            }
            foreach ($values[$d] as $idx => $value) {     // work options first, best first
                $ok = $daysUsed < $maxDays;
                foreach ($visited[$d][$idx] as $spotId) {
                    if (($visits[$spotId] ?? 0) + 1 > $maxVisits) {
                        $ok = false;
                    }
                }
                if (!$ok) {
                    continue;
                }
                foreach ($visited[$d][$idx] as $spotId) {
                    $visits[$spotId] = ($visits[$spotId] ?? 0) + 1;
                }
                $picks[$d] = $idx;
                $search($d + 1, $total + $value, $daysUsed + 1);
                foreach ($visited[$d][$idx] as $spotId) {
                    $visits[$spotId] -= 1;
                }
            }
            $picks[$d] = null;
            $search($d + 1, $total, $daysUsed);           // then the day off
        };
        $search(0, 0.0, 0);

        $days = [];
        $chosen = [];
        $counts = [];
        for ($d = 0; $d < 7; $d++) {
            $idx = $bestPicks[$d];
            $suggestion = $idx === null ? null : $opts[$d][$idx];
            if ($suggestion !== null) {
                $chosen[] = $suggestion['take_home'];
                foreach ($suggestion['stops'] as $stop) {
                    $counts[$stop['spot_id']] = ($counts[$stop['spot_id']] ?? 0) + 1;
                }
            }
            $days[] = ['date' => Dates::addDays($weekStart, $d), 'suggestion' => $suggestion];
        }
        return [
            'week_start' => $weekStart,
            'days' => $days,
            'total_take_home' => Estimates::sum($chosen),
            'visits' => $counts,
            'leaves_visited' => $leaves,
        ];
    }

    /**
     * A plan of spot stops from candidates: the stop id is the spot id, no gap is unpaid, no setup or
     * teardown override.
     *
     * @param list<array<string, mixed>> $cands
     * @return array{date: mixed, stops: list<array<string, mixed>>}
     */
    private static function planOf(mixed $date, array $cands): array
    {
        $stops = [];
        foreach ($cands as $cand) {
            $spot = $cand['spot'];
            $stops[] = [
                'id' => $spot['spot_id'],
                'kind' => 'spot',
                'spot_id' => $spot['spot_id'],
                'point' => $spot['point'],
                'open_minute' => $cand['open'],
                'close_minute' => $cand['close'],
                'gap_before_unpaid' => false,
                'setup_minutes' => null,
                'teardown_minutes' => null,
                'terms' => $spot['terms'],
                'vectors' => $spot['vectors'],
                'event' => null,
                'catering' => null,
            ];
        }
        return ['date' => $date, 'stops' => $stops];
    }

    /**
     * @param array<string, mixed>|null $options
     */
    private static function option(?array $options, string $name, mixed $default): mixed
    {
        if ($options !== null && ($options[$name] ?? null) !== null) {
            return $options[$name];
        }
        return $default;
    }
}
