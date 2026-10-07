<?php
declare(strict_types=1);

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;

/**
 * Smoke step 70: suggested days and weeks (routes 29 and 30).
 *
 *   1. A body that fails names its field: one refusal for each of the messages V1, V3, V4, V7, V8, V9 and
 *      V11, and for the three sentences of these routes (a week starts on a Monday, a service lasts whole
 *      hours, a spot that is archived or another organization's is not found).
 *   2. A day: the suggestions come ranked by expected take-home, each made of the user's own active
 *      spots, each figure a range with a confidence label, the hours of every window left out, and the
 *      same question asked twice gets the same answer.
 *   3. When the plan routes are built, a suggestion evaluated as a plan (route 24) gives the same day.
 *   4. A week: seven days, the total is the sum of the chosen days, and the limits on working days and on
 *      visits to a spot hold.
 *   5. A user without a saved spot gets an empty list and a week of seven days off, never an error.
 *
 * It starts from the trucks of step 20 and the spots of step 30 and changes nothing. Both routes are
 * limited to 30 requests an hour for a user, refusals included: this step stays well below that.
 */
return function (SmokeClient $c, array &$state): void {
    $spots = $state['spots'] ?? null;
    if (!is_array($spots) || !isset($spots['first'], $spots['second'], $spots['archived'])) {
        throw new SmokeFailure('step 70 starts from the spots that step 30 leaves in $state[\'spots\']');
    }
    $dayPath = '/api/truck/suggest/day';
    $weekPath = '/api/truck/suggest/week';
    $labels = ['very_rough', 'rough', 'fair', 'good', 'fixed'];
    $treatAs = 'treat_as must be one of: normal, holiday, mon, tue, wed, thu, fri, sat, sun';
    // The limits of a suggestion are fixed seeds of the model: the step reads them where the server does.
    $seeds = Seeds::defaults();
    $limit = static fn (string $name): int => (int) Estimator::seed($seeds, 'suggest.' . $name);
    $service = $limit('service_minutes');
    $earliest = $limit('earliest_open_minute');
    $latest = $limit('latest_close_minute');
    $longestDay = $limit('max_day_minutes');

    // A 422 with its exact sentence, and the field and message id it names in `details`.
    $refused = static function (SmokeClient $c, string $error, ?string $field = null, ?string $code = null): SmokeClient {
        $c->status(422)->path('success', false)->path('error', $error);
        if ($field !== null) {
            $c->path('details.field', $field);
        }
        if ($code !== null) {
            $c->path('details.code', $code);
        }
        return $c;
    };
    // Two numbers that agree to the tolerance of the model's golden cases.
    $close = static function (mixed $a, mixed $b): bool {
        if (!(is_int($a) || is_float($a)) || !(is_int($b) || is_float($b))) {
            return false;
        }
        return abs($a - $b) <= 1e-9 * max(1.0, abs($a), abs($b));
    };
    // An Estimate: a value inside its range, with a confidence label.
    $checkEstimate = static function (SmokeClient $c, mixed $estimate, string $what) use ($labels): void {
        $c->check(is_array($estimate) && array_keys($estimate) === ['value', 'low', 'high', 'confidence'], $what . ' is an estimate with its range and label');
        foreach (['value', 'low', 'high'] as $key) {
            $c->check(is_int($estimate[$key]) || is_float($estimate[$key]), $what . '.' . $key . ' is a number');
        }
        $c->check($estimate['low'] <= $estimate['value'] && $estimate['value'] <= $estimate['high'], $what . ': low <= value <= high');
        $c->check(in_array($estimate['confidence'], $labels, true), $what . ' carries a confidence label');
    };
    // A Suggestion as the API sends it, made of these spots.
    $checkSuggestion = static function (SmokeClient $c, mixed $s, string $date, array $spotIds, int $serviceMinutes, string $what) use ($checkEstimate, $earliest, $latest, $longestDay): void {
        $c->check(is_array($s) && array_keys($s) === ['date', 'position', 'stops', 'take_home', 'orders', 'day_minutes', 'result'], $what . ' has the fields of a suggestion');
        $c->check($s['date'] === $date, $what . ' is for the date asked');
        $c->check(is_int($s['position']) && $s['position'] >= 1, $what . ' has its position in the ranking');
        $c->check(is_array($s['stops']) && count($s['stops']) >= 1 && count($s['stops']) <= 3, $what . ' has one to three stops');
        $seen = [];
        $lastClose = 0;
        foreach ($s['stops'] as $stop) {
            $c->check(array_keys($stop) === ['spot_id', 'open_minute', 'close_minute'], $what . ': a stop is a spot and a window');
            $c->check(in_array($stop['spot_id'], $spotIds, true), $what . ' is made of the user\'s own active spots');
            $c->check(!in_array($stop['spot_id'], $seen, true), $what . ' visits a spot once');
            $seen[] = $stop['spot_id'];
            $c->check($stop['close_minute'] - $stop['open_minute'] === $serviceMinutes, $what . ': a window is as long as the service');
            $c->check($stop['open_minute'] % 60 === 0 && $stop['open_minute'] >= $earliest && $stop['close_minute'] <= $latest, $what . ': a window starts on the hour, inside the hours a suggestion may use');
            $c->check($stop['open_minute'] >= $lastClose, $what . ': the stops follow one another');
            $lastClose = $stop['close_minute'];
        }
        $checkEstimate($c, $s['take_home'], $what . '.take_home');
        $checkEstimate($c, $s['orders'], $what . '.orders');
        $result = $s['result'];
        $c->check(is_array($result) && ($result['date'] ?? null) === $date, $what . ' carries the result of its day');
        $c->check($s['take_home'] === $result['totals']['take_home'] && $s['orders'] === $result['totals']['orders'], $what . ': the headline figures are the totals of the result');
        $c->check($s['day_minutes'] === $result['timeline']['day_minutes'] && is_int($s['day_minutes']) && $s['day_minutes'] <= $longestDay, $what . ': the day is no longer than a suggested day may be');
        $c->check(count($result['stops']) === count($s['stops']), $what . ': one result per stop');
        foreach ($result['stops'] as $i => $stop) {
            $c->check($stop['spot_id'] === $s['stops'][$i]['spot_id'], $what . ': results in stop order');
            $c->check(($stop['window']['hours'] ?? null) === [], $what . ': the hours of a window are left out');
            $c->check(is_array($stop['adds'] ?? null), $what . ': each stop says what it adds');
            $checkEstimate($c, $stop['orders'], $what . '.result.stops.' . $i . '.orders');
        }
        foreach ($result['timeline']['stops'] as $stop) {
            $c->check($stop['late_minutes'] === 0, $what . ': no stop is reached late');
        }
        foreach ($result['warnings'] as $warning) {
            $c->check(is_string($warning['code']) && is_array($warning['data']), $what . ': a warning has a code and data');
        }
    };

    // ---- where user 1 stands
    $c->as(1)->get('/api/truck/bootstrap')->status(200)->path('data.has_truck', true);
    $today = (string) $c->value('data.today');
    $c->check(preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) === 1, 'bootstrap says what day it is where the truck is');
    $dow = Estimator::dayOfWeek($today);
    $thursday = Estimator::addDays($today, (3 - $dow + 7) % 7 === 0 ? 7 : (3 - $dow + 7) % 7);
    $monday = Estimator::addDays($today, 7 - $dow);
    $c->get('/api/truck/spots')->status(200);
    $active = array_column((array) $c->value('data.spots'), 'id');
    $c->check(in_array($spots['first'], $active, true) && in_array($spots['second'], $active, true), 'the two spots of step 30 are active');
    $c->check(!in_array($spots['archived'], $active, true), 'the archived spot of step 30 is not');
    // Is anybody near a spot at all? Without region data nobody is, and then there is nothing to suggest.
    $somebodyNear = false;
    foreach ((array) $c->value('data.spots') as $spot) {
        $nearby = array_sum((array) ($spot['vectors'][$spot['terms']['visibility']]['nearby'] ?? []));
        $somebodyNear = $somebodyNear || $nearby > 0 || $spot['terms']['host'] !== null;
    }

    // =====================================================================================
    // 1. refusals
    // =====================================================================================

    $refused($c->post($dayPath, []), 'date is required', 'date', 'V1');
    $refused($c->post($dayPath, ['date' => '2026-02-30']), 'date must be a date in the form YYYY-MM-DD', 'date', 'V7');
    $refused($c->post($dayPath, ['date' => $thursday, 'treat_as' => 'saturday']), $treatAs, 'treat_as', 'V4');
    $refused($c->post($dayPath, ['date' => $thursday, 'spot_ids' => []]), 'spot_ids must be a list of 1 to 200 items', 'spot_ids', 'V8');
    $refused($c->post($dayPath, ['date' => $thursday, 'spot_ids' => [$spots['first'], '00000000-0000-4000-8000-000000000000']]), 'spot_ids[1] was not found', 'spot_ids[1]', 'V11');
    $refused($c->post($dayPath, ['date' => $thursday, 'spot_ids' => [$spots['archived']]]), 'spot_ids[0] was not found', 'spot_ids[0]', 'V11');
    $refused($c->post($dayPath, ['date' => $thursday, 'options' => [1, 2]]), 'options must be an object', 'options', 'V9');
    $refused($c->post($dayPath, ['date' => $thursday, 'options' => ['limit' => 0]]), 'options.limit must be a whole number between 1 and 10', 'options.limit', 'V3');
    $refused($c->post($dayPath, ['date' => $thursday, 'options' => ['service_minutes' => 90]]), 'options.service_minutes must be a multiple of 60', 'options.service_minutes');

    $refused($c->post($weekPath, ['date' => $monday]), 'week_start is required', 'week_start', 'V1');
    $refused($c->post($weekPath, ['week_start' => Estimator::addDays($monday, 1)]), 'week_start must be a Monday', 'week_start');
    $refused($c->post($weekPath, ['week_start' => $monday, 'treat_as' => 'sat']), 'treat_as must be an object', 'treat_as', 'V9');
    $refused(
        $c->post($weekPath, ['week_start' => $monday, 'treat_as' => [Estimator::addDays($monday, 2) => 'weekend']]),
        'treat_as.' . Estimator::addDays($monday, 2) . ' must be one of: normal, holiday, mon, tue, wed, thu, fri, sat, sun',
        'treat_as.' . Estimator::addDays($monday, 2),
        'V4'
    );

    // The second user cannot bring the first user's spot into a suggestion.
    $refused($c->as(2)->post($dayPath, ['date' => $thursday, 'spot_ids' => [$spots['first']]]), 'spot_ids[0] was not found', 'spot_ids[0]', 'V11');

    // =====================================================================================
    // 2. a day
    // =====================================================================================

    $c->as(1)->post($dayPath, ['date' => $thursday])->status(200)
        ->path('success', true)
        ->path('data.spots_considered', count($active))
        ->path('data.context.date', $thursday)
        ->path('data.context.treat_as', null)
        ->path('data.context.typical', false)
        ->finite('data.fallback_pairs', 'data.context.fuel_price_per_gal');
    $first = $c->body();
    $day = (array) $c->value('data');
    $c->check(array_keys($day) === ['suggestions', 'spots_considered', 'fallback_pairs', 'context'], 'a day answers suggestions, spots_considered, fallback_pairs and context');
    $c->check(is_array($day['suggestions']) && array_is_list($day['suggestions']) && count($day['suggestions']) <= $limit('day_results'), 'no more suggestions for a day than the model returns by default');
    $c->check($day['fallback_pairs'] >= 0, 'fallback_pairs counts drives');
    $c->check(!str_contains($first, '"data":[]'), 'the data of a warning is an object, also when it is empty');
    $previous = null;
    foreach ($day['suggestions'] as $i => $suggestion) {
        $checkSuggestion($c, $suggestion, $thursday, $active, $service, 'suggestion ' . ($i + 1));
        $c->check($suggestion['position'] === $i + 1, 'suggestions are numbered in rank order');
        $rank = Estimator::qkey((float) $suggestion['take_home']['value']);
        $c->check($previous === null || $rank <= $previous, 'suggestions are ranked by expected take-home');
        $previous = $rank;
    }
    if (!$somebodyNear) {
        $c->check($day['suggestions'] === [], 'nobody is near a spot: nothing to suggest');
    }

    // The same question again: the same answer, byte for byte.
    $c->post($dayPath, ['date' => $thursday])->status(200);
    $c->check($c->body() === $first, 'the same question gets the same answer');

    // A subset of spots, a day treated as a Saturday, one stop a day, one suggestion.
    $c->post($dayPath, [
        'date' => $thursday,
        'treat_as' => 'sat',
        'spot_ids' => [$spots['second'], $spots['second']],
        'options' => ['limit' => 1, 'max_stops_per_day' => 1, 'service_minutes' => 120],
    ])->status(200)
        ->path('data.spots_considered', 1)
        ->path('data.context.treat_as', 'sat')
        ->path('data.context.eff_dow', 5);
    $narrow = (array) $c->value('data.suggestions');
    $c->check(count($narrow) <= 1, 'the limit of suggestions holds');
    foreach ($narrow as $suggestion) {
        $checkSuggestion($c, $suggestion, $thursday, [$spots['second']], 120, 'the narrowed suggestion');
        $c->check(count($suggestion['stops']) === 1, 'one stop a day was asked for');
    }

    // =====================================================================================
    // 3. a suggestion is the day the planner would compute for the same stops
    // =====================================================================================

    if ($day['suggestions'] !== []) {
        $best = $day['suggestions'][0];
        $stops = [];
        foreach ($best['stops'] as $stop) {
            $stops[] = ['kind' => 'spot'] + $stop;
        }
        $c->post('/api/truck/plans/evaluate', ['date' => $thursday, 'stops' => $stops])->status(200);
        $evaluated = (array) $c->value('data.result');
        // The forecast may have been renewed between the two questions: then the day asked again is the
        // one the plan was evaluated with.
        $c->post($dayPath, ['date' => $thursday])->status(200);
        $again = ((array) $c->value('data.suggestions'))[0] ?? null;
        $same = false;
        foreach ([$best, $again] as $candidate) {
            if (!is_array($candidate) || $candidate['stops'] !== $best['stops']) {
                continue;
            }
            $same = $same || ($close($candidate['take_home']['value'], $evaluated['totals']['take_home']['value'])
                && $close($candidate['take_home']['low'], $evaluated['totals']['take_home']['low'])
                && $close($candidate['take_home']['high'], $evaluated['totals']['take_home']['high'])
                && $close($candidate['orders']['value'], $evaluated['totals']['orders']['value'])
                && $candidate['day_minutes'] === $evaluated['timeline']['day_minutes']);
        }
        $c->check($same, 'the best suggestion, evaluated as a plan, is the same day: take-home, orders and minutes');
    }

    // =====================================================================================
    // 4. a week
    // =====================================================================================

    $c->post($weekPath, ['week_start' => $monday])->status(200)
        ->path('success', true)
        ->path('data.spots_considered', count($active))
        ->path('data.week.week_start', $monday)
        ->finite('data.fallback_pairs', 'data.week.leaves_visited');
    $body = $c->body();
    $answer = (array) $c->value('data');
    $week = (array) $answer['week'];
    $c->check(array_keys($answer) === ['week', 'spots_considered', 'fallback_pairs'], 'a week answers week, spots_considered and fallback_pairs');
    $c->check(array_keys($week) === ['week_start', 'days', 'total_take_home', 'visits', 'leaves_visited'], 'the week has the fields of a WeekSuggestion');
    $c->check(str_contains($body, '"visits":{'), 'visits is a map, also when it is empty');
    $c->check(!str_contains($body, '"data":[]'), 'the data of a warning is an object, also when it is empty');
    $c->check(is_array($week['days']) && count($week['days']) === 7, 'a week has seven days');
    $checkEstimate($c, $week['total_take_home'], 'week.total_take_home');
    $sum = ['value' => 0.0, 'low' => 0.0, 'high' => 0.0];
    $visits = [];
    $worked = 0;
    foreach ($week['days'] as $d => $entry) {
        $date = Estimator::addDays($monday, $d);
        $c->check(array_keys($entry) === ['date', 'suggestion'] && $entry['date'] === $date, 'day ' . $d . ' of the week is ' . $date);
        if ($entry['suggestion'] === null) {
            continue;
        }
        $worked++;
        $checkSuggestion($c, $entry['suggestion'], $date, $active, $service, 'the suggestion of ' . $date);
        $c->check($entry['suggestion']['take_home']['value'] > 0, 'a day is suggested only when it is expected to pay');
        foreach (['value', 'low', 'high'] as $key) {
            $sum[$key] += $entry['suggestion']['take_home'][$key];
        }
        foreach ($entry['suggestion']['stops'] as $stop) {
            $visits[$stop['spot_id']] = ($visits[$stop['spot_id']] ?? 0) + 1;
        }
    }
    foreach (['value', 'low', 'high'] as $key) {
        $c->check($close($sum[$key], $week['total_take_home'][$key]), 'the week\'s take-home (' . $key . ') is the sum of its days');
    }
    ksort($visits);
    $reported = (array) $week['visits'];
    ksort($reported);
    $c->check($reported === $visits, 'visits counts the stops at each spot');
    $c->check($worked <= $limit('max_days_per_week'), 'no more days out than the default allows');
    $c->check($visits === [] || max($visits) <= $limit('max_visits_per_spot_per_week'), 'no more visits to a spot than the default allows');
    $c->check($week['leaves_visited'] >= 1, 'the search looked at one week at least');
    if (!$somebodyNear) {
        $c->check($worked === 0, 'nobody is near a spot: the week is seven days off');
    }

    // One day out at most, and that day is the best single day of the week.
    $c->post($weekPath, ['week_start' => $monday, 'options' => ['max_days_per_week' => 1]])->status(200);
    $single = array_values(array_filter((array) $c->value('data.week.days'), static fn (array $entry): bool => $entry['suggestion'] !== null));
    $c->check(count($single) <= 1, 'the limit on working days holds');
    $c->check(($single === []) === ($worked === 0), 'a week with a paying day has one when one day is allowed');
    if ($single !== []) {
        $c->check($close($single[0]['suggestion']['take_home']['value'], $c->value('data.week.total_take_home.value')), 'the total of a one-day week is that day');
        $c->check($single[0]['suggestion']['position'] === 1, 'with one day out, it is that day\'s best plan');
    }

    // =====================================================================================
    // 5. a user without a saved spot
    // =====================================================================================

    $c->as(2)->get('/api/truck/spots')->status(200);
    if ((array) $c->value('data.spots') === []) {
        $c->post($dayPath, ['date' => $thursday])->status(200)
            ->path('data.suggestions', [])
            ->path('data.spots_considered', 0)
            ->path('data.fallback_pairs', 0)
            ->path('data.context.date', $thursday);
        $c->post($weekPath, ['week_start' => $monday])->status(200)
            ->path('data.spots_considered', 0)
            ->path('data.week.days.*.suggestion', null)
            ->path('data.week.total_take_home', ['value' => 0, 'low' => 0, 'high' => 0, 'confidence' => 'fixed'])
            ->path('data.week.leaves_visited', 1);
        $c->check(count((array) $c->value('data.week.days')) === 7, 'seven days off are still seven days');
        $c->check(str_contains($c->body(), '"visits":{}'), 'an empty visits map is an object');
    }

    $state['suggest'] = ['date' => $thursday, 'week_start' => $monday, 'suggestions' => count($day['suggestions']), 'days_worked' => $worked];
};
