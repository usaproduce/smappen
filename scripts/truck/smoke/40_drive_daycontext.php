<?php
declare(strict_types=1);

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;

/**
 * Smoke step 40: day context, drive times and the owner's corrections (routes 17 to 21).
 *
 *   1. Day context: one ready context per date, today counted in the truck's zone, the holiday of each
 *      date as the model names it (the dates of the table of 02_MODEL.md 4.1 among them), the forecast
 *      hours where the weather service has them, and the fuel price with its source and its date. A
 *      range that is refused names its field.
 *   2. Drive times: every leg comes with a truthful label. On a server without a Google key each one is
 *      a straight-line estimate with the reason `no_key`, equal to the model's own. (On a server that has
 *      a key this step asks the cache only, so that a smoke run never spends Google elements.) One
 *      refusal for each of the messages V1 to V11 a request can raise.
 *   3. Corrections: saved for one direction of one leg, changed by a second save, shown on the legs they
 *      belong to, listed, deleted. The second user neither sees nor deletes them.
 *
 * It starts from the trucks of step 20 and leaves user 1 with one correction, so that the export of a
 * later step has one to show.
 *
 * For the later steps:
 *
 *     $state['drive'] = ['override_id' => id, 'from' => ['lat' => .., 'lng' => ..], 'to' => [...], 'minutes' => 9]
 */
return function (SmokeClient $c, array &$state): void {
    $setup = $state['setup'] ?? null;
    if (!is_array($setup) || !isset($setup['base'], $setup['truck_id'][1], $setup['truck_id'][2])) {
        throw new SmokeFailure('step 40 starts from the two trucks that step 20 leaves in $state[\'setup\']');
    }
    $base = ['lat' => (float) $setup['base']['lat'], 'lng' => (float) $setup['base']['lng']];

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
    // `$metres` north of a point (south when negative), and east of it (west when negative).
    $north = static function (array $point, float $metres): array {
        return ['lat' => $point['lat'] + $metres / 6371008.8 * 180.0 / 3.141592653589793, 'lng' => $point['lng']];
    };
    // A point as the server keys it: rounded to four decimals.
    $rounded = static function (array $point): array {
        return [
            'lat' => Estimator::roundHalfAway($point['lat'] * 10000.0, 0) / 10000.0,
            'lng' => Estimator::roundHalfAway($point['lng'] * 10000.0, 0) / 10000.0,
        ];
    };
    // Two numbers that agree to the tolerance of the model's golden cases.
    $close = static function (mixed $a, mixed $b): bool {
        if (!(is_int($a) || is_float($a)) || !(is_int($b) || is_float($b))) {
            return false;
        }
        return abs($a - $b) <= 1e-9 * max(1.0, abs($a), abs($b));
    };

    // ---- where user 1 stands
    $c->as(1)->get('/api/truck/bootstrap')->status(200)->path('data.has_truck', true);
    $today = (string) $c->value('data.today');
    $zone = (string) $c->value('data.timezone');
    $flags = (array) $c->value('data.assumptions.region.flags');
    $routingState = (string) $c->value('data.routing.state');
    $profile = (array) $c->value('data.truck.profile');
    $c->check(in_array($routingState, ['ok', 'no_key', 'refused', 'backoff'], true), 'routing.state is one of its four values');

    // =====================================================================================
    // 1. day context
    // =====================================================================================

    $forecastSource = 'National Weather Service (weather.gov)';
    $hourKeys = ['hour', 'temp_f', 'precip_prob', 'short_forecast', 'wind_mph'];
    $contextKeys = [
        'date', 'typical', 'dow', 'eff_dow', 'holiday', 'holiday_class', 'treat_as', 'day_type', 'dow_factor',
        'traffic_dow', 'forecast', 'fuel_price_per_gal', 'fuel_price_source',
    ];
    // What every answer of route 17 holds, whatever the range. Answers the days.
    $checkDays = static function (SmokeClient $c, string $from, int $count) use ($flags, $hourKeys, $contextKeys): array {
        $days = $c->value('data.days');
        $fuel = (array) $c->value('data.fuel');
        $c->check(is_array($days) && array_is_list($days) && count($days) === $count, 'one day for each of the ' . $count . ' dates');
        foreach ($days as $i => $day) {
            $date = Estimator::addDays($from, $i);
            $c->check(array_keys($day) === ['date', 'holiday', 'context'], 'a day is a date, a holiday and a context');
            $c->check($day['date'] === $date && $day['context']['date'] === $date, 'the dates follow one another from ' . $from);
            $context = $day['context'];
            $c->check(array_keys($context) === $contextKeys, 'the context is the model\'s DayContext');
            $c->check($context['typical'] === false && $context['treat_as'] === null, 'a context of a real date, built without an override');
            $c->check($context['dow'] === Estimator::dayOfWeek($date) && $context['eff_dow'] === $context['dow'], $date . ' is the day of the week the model says');
            // the holiday is the one the model names for the date under the region's flags
            $holiday = Estimator::holidayOn($date, $flags);
            $c->check($day['holiday'] == $holiday && $context['holiday'] == $holiday, $date . ' has the holiday of the model');
            $c->check($context['holiday_class'] === ($holiday['class'] ?? null), 'and its class');
            $c->check(is_array($context['day_type']) && count($context['day_type']) === 16, 'sixteen day types');
            $c->check(is_array($context['dow_factor']) && count($context['dow_factor']) === 16, 'sixteen day-of-week factors');
            $c->check($context['fuel_price_per_gal'] == $fuel['price_per_gal'] && $context['fuel_price_source'] === $fuel['source'], 'the context carries the fuel price of the answer');
            if ($context['forecast'] !== null) {
                $c->check(is_array($context['forecast']) && count($context['forecast']) === 24, 'a forecast is 24 entries');
                foreach ($context['forecast'] as $hour => $record) {
                    if ($record === null) {
                        continue;
                    }
                    $c->check(array_keys($record) === $hourKeys && $record['hour'] === $hour, 'an hour of the forecast names its hour');
                    foreach (['temp_f', 'precip_prob', 'wind_mph'] as $field) {
                        $c->check($record[$field] === null || is_int($record[$field]) || is_float($record[$field]), $field . ' is a number or null');
                    }
                    $c->check($record['precip_prob'] === null || ($record['precip_prob'] >= 0 && $record['precip_prob'] <= 100), 'a chance of precipitation is a percentage');
                    $c->check($record['short_forecast'] === null || is_string($record['short_forecast']), 'the forecast text is text or null');
                }
            }
        }
        return $days;
    };

    // The default range: today in the truck's zone and the seven days after it.
    $c->get('/api/truck/day-context')->status(200)
        ->path('success', true)
        ->path('data.timezone', $zone)
        ->path('data.today', $today)
        ->path('data.forecast.source', $forecastSource)
        ->path('data.forecast.point', $base)
        ->finite('data.fuel.price_per_gal');
    $c->check(array_keys((array) $c->value('data')) === ['timezone', 'today', 'fuel', 'forecast', 'days'], 'the answer is timezone, today, fuel, forecast and days');
    $c->check(array_keys((array) $c->value('data.forecast')) === ['state', 'generated_at', 'generated_local', 'point', 'source'], 'the forecast block has its five fields');
    $days = $checkDays($c, $today, 8);

    // The fuel price says where it comes from and what date it is of.
    $fuel = (array) $c->value('data.fuel');
    $c->check(array_keys($fuel) === ['price_per_gal', 'source', 'area', 'product', 'period'], 'the fuel price is a FuelInfo');
    $c->check(in_array($fuel['source'], ['eia', 'seed'], true), 'without a price of the owner the source is the weekly price or the seed');
    $c->check(is_string($fuel['period']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fuel['period']) === 1, 'a price that is not the owner\'s has its date');
    $c->check(is_string($fuel['area']) && $fuel['area'] !== '' && $fuel['product'] === 'EPMR', 'gasoline, priced for an area');

    // The forecast says what state it is in; the hours follow from that.
    $forecastState = $c->value('data.forecast.state');
    $c->check(in_array($forecastState, ['fresh', 'stale', 'unavailable'], true), 'forecast.state is one of its three values');
    $withHours = 0;
    foreach ($days as $day) {
        $withHours += $day['context']['forecast'] === null ? 0 : count(array_filter($day['context']['forecast'], static fn ($hour): bool => $hour !== null));
    }
    if ($forecastState === 'unavailable') {
        $c->path('data.forecast.generated_at', null)->path('data.forecast.generated_local', null);
        $c->check($withHours === 0, 'without a forecast no date has forecast hours');
    } else {
        $c->check(is_string($c->value('data.forecast.generated_at')), 'a forecast says when it was made');
        $local = $c->value('data.forecast.generated_local');
        $c->check(is_array($local) && array_keys($local) === ['date', 'minute'] && $local['minute'] >= 0 && $local['minute'] <= 1439, 'and when that was on the truck\'s clock');
        $c->check($withHours > 0, 'a forecast gives hours to the coming days');
    }
    // The same price the bootstrap answer shows (prices are refreshed by this route, so it is read afterwards).
    $c->get('/api/truck/bootstrap')->status(200)->path('data.fuel', $fuel);
    // The service covers six and a half days: a date a month ahead has no forecast, whatever the state.
    $ahead = Estimator::addDays($today, 30);
    $c->get('/api/truck/day-context', ['from' => $ahead, 'to' => $ahead])->status(200)->path('data.days.0.context.forecast', null);
    $checkDays($c, $ahead, 1);

    // One date; both ends of a range count; fourteen dates are the most.
    $c->get('/api/truck/day-context', ['from' => '2026-10-08', 'to' => '2026-10-08'])->status(200)->path('data.today', $today);
    $day = $checkDays($c, '2026-10-08', 1)[0];
    $c->check($day['context']['dow'] === 3 && $day['holiday'] === null, '8 October 2026 is an ordinary Thursday');
    $c->get('/api/truck/day-context', ['from' => '2026-11-20'])->status(200);
    $week = $checkDays($c, '2026-11-20', 8);
    $c->check($week[6]['holiday']['id'] === 'thanksgiving' && $week[6]['context']['holiday_class'] === 'major' && $week[6]['context']['traffic_dow'] === 6, 'Thanksgiving 2026 is a major holiday with Sunday traffic');
    $c->get('/api/truck/day-context', ['from' => '2027-12-20', 'to' => '2028-01-02'])->status(200);
    $turn = array_column($checkDays($c, '2027-12-20', 14), 'holiday', 'date');
    // The holiday table of 02_MODEL.md 4.1: a holiday on a Saturday is observed the day before, on a Sunday the day after.
    $c->check($turn['2027-12-24']['id'] === 'christmas' && $turn['2027-12-24']['date'] === '2027-12-25' && $turn['2027-12-25'] === null, 'Christmas 2027 falls on a Saturday and is observed on the 24th');
    $c->check($turn['2027-12-31']['id'] === 'new_year' && $turn['2027-12-31']['date'] === '2028-01-01' && $turn['2028-01-01'] === null, 'New Year 2028 is observed on 31 December 2027');
    foreach ([
        ['2026-07-03', 'independence', '2026-07-04'], ['2027-06-18', 'juneteenth', '2027-06-19'],
        ['2027-07-05', 'independence', '2027-07-04'], ['2028-11-10', 'veterans', '2028-11-11'],
        ['2029-11-12', 'veterans', '2029-11-11'],
    ] as [$observed, $id, $actual]) {
        $c->get('/api/truck/day-context', ['from' => Estimator::addDays($observed, -1), 'to' => Estimator::addDays($observed, 2)])->status(200);
        $found = array_column($checkDays($c, Estimator::addDays($observed, -1), 4), 'holiday', 'date');
        $c->check($found[$observed]['id'] === $id && $found[$observed]['date'] === $actual && $found[$observed]['observed'] === $observed, $id . ' of ' . $actual . ' is observed on ' . $observed);
        $c->check($found[$actual] === null, 'and is no holiday on the weekend day it falls on');
    }
    // Inauguration Day 2029 falls on a Saturday and has no day in lieu, with or without the region's flag.
    $c->get('/api/truck/day-context', ['from' => '2029-01-19', 'to' => '2029-01-22'])->status(200);
    $c->check(array_column($checkDays($c, '2029-01-19', 4), 'holiday') === [null, null, null, null], 'no holiday around 20 January 2029');

    // Refused ranges.
    $refused($c->get('/api/truck/day-context', ['from' => '08/10/2026']), 'from must be a date in the form YYYY-MM-DD', 'from', 'V7');
    $refused($c->get('/api/truck/day-context', ['from' => '2026-10-08', 'to' => '2026-13-01']), 'to must be a date in the form YYYY-MM-DD', 'to', 'V7');
    $refused($c->get('/api/truck/day-context', ['from' => '2026-10-08', 'to' => '2026-10-07']), 'to must not be before from', 'to');
    $refused($c->get('/api/truck/day-context', ['from' => '2026-10-01', 'to' => '2026-10-15']), 'The date range must be at most 14 days');
    $c->get('/api/truck/day-context', ['from' => '2026-10-01', 'to' => '2026-10-14'])->status(200);
    $checkDays($c, '2026-10-01', 14);

    // The owner's own fuel price wins, and the contexts carry it.
    $c->put('/api/truck/profile', ['fuel_price_override' => 3.999])->status(200);
    $c->get('/api/truck/day-context', ['from' => $today, 'to' => $today])->status(200)
        ->path('data.fuel.source', 'owner')
        ->path('data.fuel.price_per_gal', 3.999)
        ->path('data.fuel.period', null)
        ->path('data.days.0.context.fuel_price_per_gal', 3.999)
        ->path('data.days.0.context.fuel_price_source', 'owner');
    $c->put('/api/truck/profile', ['fuel_price_override' => null])->status(200);
    $c->get('/api/truck/day-context', ['from' => $today, 'to' => $today])->status(200)->path('data.fuel', $fuel);

    // =====================================================================================
    // 2. drive times
    // =====================================================================================

    $near = $north($base, 400.0);
    $far = $north($base, 9000.0);
    $points = [
        ['id' => 'base'] + $base,
        ['id' => 'near'] + $near,
        ['id' => 'far'] + $far,
    ];
    $attribution = 'Drive times and distances: Google Maps Platform. Kept for at most 30 days.';
    $legKeys = [
        'from_id', 'to_id', 'source', 'fetched_on', 'age_days', 'distance_m', 'duration_s', 'toll_state', 'google_toll',
        'toll_source', 'override', 'fallback_reason', 'leg_input',
    ];
    $reasons = ['no_key', 'refused', 'quota', 'budget', 'rate', 'timeout', 'upstream', 'route_not_found', 'cache_only'];
    // A server with a key is asked for its cache only: a smoke run never spends Google elements.
    $noKey = $routingState === 'no_key';
    $ask = static function (array $body) use ($noKey): array {
        return $noKey ? $body : $body + ['fetch' => false];
    };
    // Every leg says truthfully what it is. Answers the legs. `$cacheOnly`: the request said `fetch: false`.
    $checkLegs = static function (SmokeClient $c, array $pairs, bool $cacheOnly = false) use ($legKeys, $reasons, $noKey, $close, $points): array {
        $byId = array_column($points, null, 'id');
        $legs = $c->value('data.legs');
        $c->check(is_array($legs) && array_is_list($legs) && count($legs) === count($pairs), 'one leg per pair');
        foreach ($legs as $i => $leg) {
            $c->check(array_keys($leg) === $legKeys, 'a leg is a DriveLeg');
            $c->check([$leg['from_id'], $leg['to_id']] === $pairs[$i], 'the legs come in pair order');
            $c->check(array_keys($leg['leg_input']) === ['source', 'distance_m', 'duration_s', 'override_minutes', 'toll'], 'with the LegInput of the model');
            $c->check((is_int($leg['distance_m']) || is_float($leg['distance_m'])) && (is_int($leg['duration_s']) || is_float($leg['duration_s'])), 'distance and duration are numbers');
            $c->check(in_array($leg['source'], ['google_routes', 'google_distance_matrix', 'straight_line', 'same_point'], true), 'a leg names its source');
            if ($leg['source'] === 'straight_line') {
                $c->check(in_array($leg['fallback_reason'], $reasons, true), 'a straight line says why it is one');
                $c->check($leg['leg_input']['source'] === 'fallback' && $leg['fetched_on'] === null && $leg['age_days'] === null, 'and is handed to the model as an estimate');
                $c->check($leg['toll_state'] === 'not_asked' && $leg['google_toll'] === null, 'without anything of Google\'s');
                if (isset($byId[$leg['from_id']], $byId[$leg['to_id']])) {
                    $from = $byId[$leg['from_id']];
                    $to = $byId[$leg['to_id']];
                    $model = Estimator::fallbackLeg(Seeds::defaults(), $from['lat'], $from['lng'], $to['lat'], $to['lng']);
                    $c->check($close($leg['distance_m'], $model['distance_m']) && $close($leg['duration_s'], $model['duration_s']), 'the estimate is the model\'s fallback leg');
                    $c->check($close($leg['leg_input']['distance_m'], $model['distance_m']) && $close($leg['leg_input']['duration_s'], $model['duration_s']), 'in the leg input as well');
                }
            } else {
                $c->check($leg['fallback_reason'] === null && $leg['leg_input']['source'] === 'google', 'a leg that is not a straight line has no fallback reason');
            }
            if ($cacheOnly || !$noKey) {
                // Nothing was asked of Google: what the cache does not hold is a straight line that says so.
                $c->check($leg['source'] !== 'straight_line' || in_array($leg['fallback_reason'], ['cache_only', 'route_not_found'], true), 'a leg the cache does not hold is a straight line with the reason cache_only');
            } else {
                $c->check($leg['source'] === 'same_point' || ($leg['source'] === 'straight_line' && $leg['fallback_reason'] === 'no_key'), 'without a Google key every leg is a straight line with the reason no_key');
            }
        }
        return $legs;
    };

    // The default mode is the loop.
    $c->post('/api/truck/drive-times', $ask(['points' => $points]))->status(200)
        ->path('success', true)
        ->path('data.routing.route_key', 'd' . (($profile['avoid_tolls'] ?? false) ? 't' : '') . (($profile['avoid_highways'] ?? false) ? 'h' : ''))
        ->path('data.routing.leg_ttl_days', 30)
        ->path('data.routing.attribution', $attribution)
        ->path('data.routing.state', $routingState);
    $c->check(array_keys((array) $c->value('data.routing')) === ['state', 'route_key', 'leg_ttl_days', 'attribution'], 'the routing block has its four fields');
    $loop = $checkLegs($c, [['base', 'near'], ['near', 'far'], ['far', 'base']]);
    $c->check($loop[0]['source'] !== 'straight_line' || ($loop[0]['distance_m'] > 400 && $loop[0]['distance_m'] < 700), '400 m as the crow flies are a little more by road');
    $c->check($loop[0]['override'] === null && $loop[0]['toll_source'] === 'none' && $loop[0]['leg_input']['toll'] == 0, 'no correction, no toll');
    if ($noKey) {
        $c->check(array_column($loop, 'fallback_reason') === ['no_key', 'no_key', 'no_key'], 'three straight lines, each with the reason no_key');
    }

    // The other modes.
    $c->post('/api/truck/drive-times', $ask(['points' => $points, 'mode' => 'chain']))->status(200);
    $checkLegs($c, [['base', 'near'], ['near', 'far']]);
    $c->post('/api/truck/drive-times', $ask(['points' => $points, 'mode' => 'matrix', 'tolls' => false]))->status(200);
    $checkLegs($c, [['base', 'near'], ['base', 'far'], ['near', 'base'], ['near', 'far'], ['far', 'base'], ['far', 'near']]);
    $c->post('/api/truck/drive-times', $ask(['points' => $points, 'mode' => 'pairs', 'pairs' => [['far', 'base'], ['base', 'far'], ['far', 'base']]]))->status(200);
    $checkLegs($c, [['far', 'base'], ['base', 'far'], ['far', 'base']]);
    // The cache alone, on any server: nothing is asked of Google and the label says so.
    $c->post('/api/truck/drive-times', ['points' => $points, 'mode' => 'chain', 'fetch' => false])->status(200);
    $cached = $checkLegs($c, [['base', 'near'], ['near', 'far']], true);
    if ($noKey) {
        $c->check(array_column($cached, 'fallback_reason') === ['cache_only', 'cache_only'], 'an empty cache answers two straight lines with the reason cache_only');
    }
    // Two pins on one rounded point are the same place: no drive between them.
    $twin = ['id' => 'twin', 'lat' => $rounded($base)['lat'] + 0.00003, 'lng' => $rounded($base)['lng'] - 0.00003];
    $c->post('/api/truck/drive-times', $ask(['points' => [['id' => 'base'] + $rounded($base), $twin], 'mode' => 'chain']))->status(200)
        ->path('data.legs.0.source', 'same_point')
        ->path('data.legs.0.distance_m', 0)
        ->path('data.legs.0.duration_s', 0)
        ->path('data.legs.0.fallback_reason', null)
        ->path('data.legs.0.leg_input', ['source' => 'google', 'distance_m' => 0, 'duration_s' => 0, 'override_minutes' => null, 'toll' => 0]);

    // One refusal for each validation message a request can raise.
    $a = ['id' => 'a'] + $base;
    $b = ['id' => 'b'] + $near;
    $refused($c->post('/api/truck/drive-times', []), 'points is required', 'points', 'V1');
    $refused($c->post('/api/truck/drive-times', ['points' => [$a, ['id' => 'b', 'lat' => 91.5, 'lng' => 0]]]), 'points[1] must have lat between -90 and 90 and lng between -180 and 180', 'points[1]', 'V10');
    $refused($c->post('/api/truck/drive-times', ['points' => [$a, $b], 'mode' => 'star']), 'mode must be one of: loop, chain, matrix, pairs', 'mode', 'V4');
    $refused($c->post('/api/truck/drive-times', ['points' => [$a, ['id' => str_repeat('x', 65)] + $b]]), 'points[1].id must be text of at most 64 characters', 'points[1].id', 'V5');
    $refused($c->post('/api/truck/drive-times', ['points' => [$a, $b], 'tolls' => 'yes']), 'tolls must be true or false', 'tolls', 'V6');
    $refused($c->post('/api/truck/drive-times', ['points' => [$a]]), 'points must be a list of 2 to 60 items', 'points', 'V8');
    $refused($c->post('/api/truck/drive-times', ['points' => [$a, 'b']]), 'points[1] must be an object', 'points[1]', 'V9');
    $refused($c->post('/api/truck/drive-times', ['points' => [$a, $b], 'mode' => 'pairs', 'pairs' => [['a', 'c']]]), 'pairs[0][1] was not found', 'pairs[0][1]', 'V11');
    $refused($c->post('/api/truck/drive-times', ['points' => [$a, $b], 'mode' => 'pairs']), 'pairs is required', 'pairs', 'V1');
    $refused($c->post('/api/truck/drive-times', ['points' => [$a, ['id' => 'a'] + $near]]), 'points[1].id is repeated', 'points[1].id');
    $many = [];
    for ($i = 0; $i < 27; $i++) {
        $many[] = ['id' => 'p' . $i] + $north($base, 100.0 * $i);
    }
    $refused($c->post('/api/truck/drive-times', ['points' => $many, 'mode' => 'matrix']), 'Too many legs in one request (at most 650)');
    $refused($c->sendRaw('POST', '/api/truck/drive-times', '[1, 2]'), 'Request body must be a JSON object');

    // =====================================================================================
    // 3. the owner's corrections
    // =====================================================================================

    $c->get('/api/truck/drive-times/overrides')->status(200)->path('data.overrides', []);

    // Refused saves store nothing.
    $refused($c->put('/api/truck/drive-times/overrides', ['to' => $base, 'minutes' => 14]), 'from is required', 'from', 'V1');
    $refused($c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => ['lat' => 39.0], 'minutes' => 14]), 'to must have lat between -90 and 90 and lng between -180 and 180', 'to', 'V10');
    $refused($c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => $base, 'minutes' => 0]), 'minutes must be a whole number between 1 and 600', 'minutes', 'V3');
    $refused($c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => $base, 'toll' => 500.5]), 'toll must be a number between 0 and 500', 'toll', 'V2');
    $refused($c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => $base, 'minutes' => 14, 'note' => str_repeat('n', 161)]), 'note must be text of at most 160 characters', 'note', 'V5');
    $refused($c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => $base]), 'Give minutes or toll');
    $refused($c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => $base, 'minutes' => null, 'toll' => null]), 'Give minutes or toll');
    $refused($c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => $near, 'minutes' => 5]), 'from and to are the same place');
    $refused($c->put('/api/truck/drive-times/overrides', []), 'from is required', 'from', 'V1');
    $c->get('/api/truck/drive-times/overrides')->status(200)->path('data.overrides', []);

    // A correction with a toll: saved under the two rounded points.
    $c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => $base, 'minutes' => 14, 'toll' => 3.75])->status(200)
        ->path('success', true)
        ->path('data.override.from', $rounded($near))
        ->path('data.override.to', $rounded($base))
        ->path('data.override.minutes', 14)
        ->path('data.override.toll', 3.75)
        ->path('data.override.note', '');
    $saved = (array) $c->value('data.override');
    $c->check(array_keys($saved) === ['id', 'from', 'to', 'minutes', 'toll', 'note', 'updated_at'], 'a correction has its seven fields');
    $c->check(is_string($saved['id']) && strlen($saved['id']) === 36, 'and an id');

    // Read back: in the list, and on the leg it belongs to and on no other.
    $c->get('/api/truck/drive-times/overrides')->status(200)->path('data.overrides', [$saved]);
    $c->post('/api/truck/drive-times', $ask(['points' => $points, 'mode' => 'matrix']))->status(200);
    foreach ($checkLegs($c, [['base', 'near'], ['base', 'far'], ['near', 'base'], ['near', 'far'], ['far', 'base'], ['far', 'near']]) as $leg) {
        if ([$leg['from_id'], $leg['to_id']] === ['near', 'base']) {
            $c->check($leg['override'] == ['id' => $saved['id'], 'minutes' => 14, 'toll' => 3.75, 'note' => ''], 'the leg shows its correction');
            $c->check($leg['leg_input']['override_minutes'] === 14 && $leg['leg_input']['toll'] == 3.75 && $leg['toll_source'] === 'owner', 'and hands the owner\'s minutes and toll to the model');
        } else {
            $c->check($leg['override'] === null && $leg['leg_input']['override_minutes'] === null, 'a correction belongs to one direction of one leg');
        }
    }
    // A pin a few metres off is the same rounded place.
    $c->post('/api/truck/drive-times', $ask(['points' => [['id' => 'near'] + $north($rounded($near), 3.0), ['id' => 'base'] + $rounded($base)], 'mode' => 'chain']))->status(200)
        ->path('data.legs.0.override.id', $saved['id']);

    // A second save of the same leg changes the correction that is there. A key it does not carry keeps its value.
    $c->put('/api/truck/drive-times/overrides', ['from' => $rounded($near), 'to' => $base, 'minutes' => 16, 'note' => 'school run'])->status(200)
        ->path('data.override.id', $saved['id'])
        ->path('data.override.minutes', 16)
        ->path('data.override.toll', 3.75)
        ->path('data.override.note', 'school run');
    $c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => $base, 'toll' => null])->status(200)
        ->path('data.override.id', $saved['id'])
        ->path('data.override.minutes', 16)
        ->path('data.override.toll', null)
        ->path('data.override.note', 'school run');
    // An amount finer than a cent is rounded, and the answer shows what was stored.
    $c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => $base, 'toll' => 2.345])->status(200)->path('data.override.toll', 2.35);
    $changed = (array) $c->value('data.override');
    $c->get('/api/truck/drive-times/overrides')->status(200)->path('data.overrides', [$changed]);

    // ---- the second user
    $c->as(2)->get('/api/truck/drive-times/overrides')->status(200)->path('data.overrides', []);
    $c->delete('/api/truck/drive-times/overrides/' . $saved['id'])->status(404)->path('success', false)->path('error', 'Correction not found');
    $c->post('/api/truck/drive-times', $ask(['points' => [['id' => 'near'] + $near, ['id' => 'base'] + $base], 'mode' => 'chain']))->status(200)
        ->path('data.legs.0.override', null)
        ->path('data.legs.0.leg_input.override_minutes', null);
    // Its own correction of the same leg is its own row.
    $c->put('/api/truck/drive-times/overrides', ['from' => $near, 'to' => $base, 'minutes' => 30])->status(200);
    $theirs = (array) $c->value('data.override');
    $c->check($theirs['id'] !== $saved['id'], 'the second user\'s correction is another one');
    $c->delete('/api/truck/drive-times/overrides/' . $theirs['id'])->status(200)->path('data', ['id' => $theirs['id'], 'deleted' => true]);
    $c->get('/api/truck/drive-times/overrides')->status(200)->path('data.overrides', []);

    // Nothing of that reached the first user's correction. Then it is deleted, once.
    $c->as(1)->get('/api/truck/drive-times/overrides')->status(200)->path('data.overrides', [$changed]);
    $c->delete('/api/truck/drive-times/overrides/' . $saved['id'])->status(200)->path('success', true)->path('data', ['id' => $saved['id'], 'deleted' => true]);
    $c->delete('/api/truck/drive-times/overrides/' . $saved['id'])->status(404)->path('error', 'Correction not found');
    $c->delete('/api/truck/drive-times/overrides/00000000-0000-4000-8000-000000000000')->status(404)->path('error', 'Correction not found');
    $c->get('/api/truck/drive-times/overrides')->status(200)->path('data.overrides', []);
    $c->post('/api/truck/drive-times', $ask(['points' => [['id' => 'near'] + $near, ['id' => 'base'] + $base], 'mode' => 'chain']))->status(200)
        ->path('data.legs.0.override', null)
        ->path('data.legs.0.toll_source', 'none');

    // ---- what the later steps start from: one correction of user 1
    $c->put('/api/truck/drive-times/overrides', ['from' => $base, 'to' => $far, 'minutes' => 9])->status(200)
        ->path('data.override.minutes', 9)
        ->path('data.override.toll', null);
    $kept = (array) $c->value('data.override');
    $c->get('/api/truck/drive-times/overrides')->status(200)->path('data.overrides', [$kept]);
    $state['drive'] = ['override_id' => (string) $kept['id'], 'from' => $kept['from'], 'to' => $kept['to'], 'minutes' => 9];
};
