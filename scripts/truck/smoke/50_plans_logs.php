<?php
declare(strict_types=1);

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;

/**
 * Smoke step 50: day plans, logged services, calibration and accuracy (routes 22 to 28 and 31 to 37).
 *
 *   1. Plans: a body is evaluated without being stored (route 24) and then saved with its result (23).
 *      The stored result is the model's own day plan on the inputs the answer names: the step computes
 *      it again with the Estimator and compares number for number. One plan per date (409). A change
 *      replaces the stops as a set and keeps the ids that are sent back (26); a stored plan is evaluated
 *      again on request (28). Event and catering stops are planned like spot stops.
 *   2. Without a Google key every drive leg of a plan is a labelled straight-line estimate.
 *   3. Services: a log linked to a planned stop takes the figures the plan showed; any other log gets its
 *      own estimate; a second log for one spot and time is refused (409); a change of the count leaves
 *      the prediction alone and a change of the window rebuilds it.
 *   4. Calibration and accuracy are the model's on the logged services, and move when a service is
 *      logged. After a change of an assumption the raw predictions are computed again, once, and the
 *      stored plan is stale until it is evaluated again.
 *   5. The second user gets 404 for the first user's plan and service, and cannot plan or log at the first
 *      user's spots or stops.
 *   6. Deleting a plan keeps the services that were logged against it and clears their link.
 *
 * It starts from the trucks of step 20 and the spots of step 30. For orders that do not depend on region
 * data it saves one more spot of user 1, a taproom host, and archives it again at the end: the active
 * spots of user 1 are left as step 30 left them, and so are the assumptions.
 *
 * For the later steps:
 *
 *     $state['plans'] = ['plan_ids' => [id, id], 'service_id' => id, 'host_spot' => id]
 *
 * User 1 is left with two plans (tomorrow: an event and a catering job; the day after: the two spots of
 * step 30), one logged service at the archived host spot, and therefore a truck factor that is not 1.
 */
return function (SmokeClient $c, array &$state): void {
    $setup = $state['setup'] ?? null;
    $spots = $state['spots'] ?? null;
    if (!is_array($setup) || !isset($setup['base'], $setup['truck_id'][1]) || !is_array($spots) || !isset($spots['first'], $spots['second'], $spots['archived'])) {
        throw new SmokeFailure('step 50 starts from the trucks of step 20 and the spots of step 30');
    }
    $base = ['lat' => (float) $setup['base']['lat'], 'lng' => (float) $setup['base']['lng']];
    $missing = '00000000-0000-4000-8000-000000000000';
    $labels = ['very_rough', 'rough', 'fair', 'good', 'fixed'];
    $treatAs = 'treat_as must be one of: normal, holiday, mon, tue, wed, thu, fri, sat, sun';

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
    // Two values that agree: numbers to the tolerance of the model's golden cases, everything else exactly.
    $same = static function (mixed $a, mixed $b) use (&$same): bool {
        $aNumber = is_int($a) || is_float($a);
        $bNumber = is_int($b) || is_float($b);
        if ($aNumber && $bNumber) {
            return abs($a - $b) <= 1e-9 * max(1.0, abs($a), abs($b));
        }
        if (is_array($a) && is_array($b)) {
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!$same($value, $b[$key])) {
                    return false;
                }
            }
            return true;
        }
        return $a === $b;
    };
    // An Estimate: a value inside its range, with a confidence label.
    $isEstimate = static function (mixed $e) use ($labels): bool {
        return is_array($e) && array_keys($e) === ['value', 'low', 'high', 'confidence']
            && (is_int($e['value']) || is_float($e['value'])) && $e['low'] <= $e['value'] && $e['value'] <= $e['high']
            && in_array($e['confidence'], $labels, true);
    };
    // The model's StopInput of a spot stop, from the API's Spot.
    $spotStop = static function (string $id, array $spot, int $open, int $close): array {
        return [
            'id' => $id, 'kind' => 'spot', 'spot_id' => $spot['id'], 'point' => $spot['point'], 'open_minute' => $open, 'close_minute' => $close,
            'gap_before_unpaid' => false, 'setup_minutes' => null, 'teardown_minutes' => null,
            'terms' => $spot['terms'], 'vectors' => $spot['vectors'][$spot['terms']['visibility']], 'event' => null, 'catering' => null,
        ];
    };
    // The drive legs of an answer as the model takes them.
    $legInputs = static function (array $context): array {
        $legs = [];
        foreach ($context['legs'] as $leg) {
            $legs[$leg['from_id'] . '>' . $leg['to_id']] = $leg['leg_input'];
        }
        return $legs;
    };
    // A logged service as the model reads it (ServiceLogEntry), from the API's ServiceLog.
    $entry = static function (array $service): array {
        return [
            'service_id' => $service['id'], 'kind' => $service['kind'], 'spot_id' => $service['spot_id'], 'date' => $service['date'],
            'open_minute' => $service['open_minute'], 'close_minute' => $service['close_minute'], 'actual' => (float) $service['actual'],
            'sold_out' => $service['sold_out'], 'predicted_raw' => $service['prediction']['predicted_raw'],
            'predicted' => $service['prediction']['predicted'], 'low' => $service['prediction']['low'], 'high' => $service['prediction']['high'],
        ];
    };

    // ---- where user 1 stands
    $c->as(1)->get('/api/truck/bootstrap')->status(200)->path('data.has_truck', true)->path('data.counts.plans', 0)->path('data.counts.services', 0);
    $today = (string) $c->value('data.today');
    $c->check(preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) === 1, 'bootstrap says what day it is where the truck is');
    $tomorrow = Estimator::addDays($today, 1);
    $dayAfter = Estimator::addDays($today, 2);
    $lastWeek = Estimator::addDays($today, -7);
    $profile = (array) $c->value('data.truck.profile');
    $modelVersion = $c->value('data.model_version');
    $seedsRevision = $c->value('data.seeds_revision');
    $c->check((array) $c->value('data.assumptions.overrides') === [], 'user 1 has no overrides when step 50 starts');
    $A = Seeds::assumptions([], (array) $c->value('data.assumptions.region'));
    $c->check($c->value('data.calibration.truck_factor') == 1 && $c->value('data.calibration.truck_n') === 0, 'no service was logged yet: the truck factor is 1');
    $noKey = $c->value('data.routing.state') === 'no_key';

    // A taproom host 800 m north of the base: 120 people in its busiest hour who can only eat at the truck.
    // The host term gives orders with or without region data.
    $hostPoint = ['lat' => $base['lat'] + 800.0 / 6371008.8 * 180.0 / 3.141592653589793, 'lng' => $base['lng']];
    $c->post('/api/truck/spots', [
        'name' => 'Smoke taproom',
        'point' => $hostPoint,
        'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120, 'only_food' => true]],
    ])->status(201)->path('data.spot.vectors_state', 'fresh');
    $host = (array) $c->value('data.spot');
    $hostId = (string) $host['id'];
    $c->get('/api/truck/spots/' . $spots['first'])->status(200);
    $first = (array) $c->value('data.spot');
    $c->get('/api/truck/spots/' . $spots['second'])->status(200);
    $second = (array) $c->value('data.spot');

    // =====================================================================================
    // 1. plans: nothing yet, and what a request may not be
    // =====================================================================================
    $c->get('/api/truck/plans')->status(200)->path('data.plans', []);
    $c->get('/api/truck/plans', ['stops' => 1, 'from' => $today, 'to' => $today])->status(200)->path('data.plans', []);
    $refused($c->get('/api/truck/plans', ['from' => '10/08/2026']), 'from must be a date in the form YYYY-MM-DD', 'from', 'V7');
    $refused($c->get('/api/truck/plans', ['from' => $tomorrow, 'to' => $today]), 'to must not be before from', 'to');
    $refused($c->get('/api/truck/plans', ['from' => $today, 'to' => Estimator::addDays($today, 92)]), 'The date range must be at most 92 days');
    $c->get('/api/truck/plans', ['from' => $today, 'to' => Estimator::addDays($today, 91)])->status(200);
    $refused($c->get('/api/truck/plans', ['stops' => 'yes']), 'stops must be true or false', 'stops', 'V6');

    $lunch = ['kind' => 'spot', 'spot_id' => $spots['first'], 'open_minute' => 660, 'close_minute' => 840];
    $evening = ['kind' => 'spot', 'spot_id' => $hostId, 'open_minute' => 1020, 'close_minute' => 1200];
    $day = ['date' => $today, 'stops' => [$lunch, $evening]];
    $refused($c->post('/api/truck/plans', []), 'date is required', 'date', 'V1');
    $refused($c->post('/api/truck/plans', ['date' => $today, 'treat_as' => 'saturday']), $treatAs, 'treat_as', 'V4');
    $refused($c->post('/api/truck/plans', ['date' => $today, 'status' => 'open']), 'status must be one of: draft, planned, done, cancelled', 'status', 'V4');
    $refused($c->post('/api/truck/plans', ['date' => $today, 'stops' => array_fill(0, 9, $lunch)]), 'stops must be a list of 0 to 8 items', 'stops', 'V8');
    $refused($c->post('/api/truck/plans', ['date' => $today, 'stops' => [$lunch, 'dinner']]), 'stops[1] must be an object', 'stops[1]', 'V9');
    $refused($c->post('/api/truck/plans', ['date' => $today, 'stops' => [['kind' => 'market'] + $lunch]]), 'stops[0].kind must be one of: spot, event, catering', 'stops[0].kind', 'V4');
    $refused($c->post('/api/truck/plans', ['date' => $today, 'stops' => [$lunch, ['spot_id' => $missing] + $evening]]), 'stops[1].spot_id was not found', 'stops[1].spot_id', 'V11');
    $refused(
        $c->post('/api/truck/plans', ['date' => $today, 'stops' => [['open_minute' => 660.5] + $lunch]]),
        'stops[0].open_minute must be a whole number between 0 and 2880',
        'stops[0].open_minute',
        'V3'
    );
    $refused($c->post('/api/truck/plans', ['date' => $today, 'stops' => [['close_minute' => 660] + $lunch]]), 'stops[0].close_minute must be after open_minute', 'stops[0].close_minute');
    $refused(
        $c->post('/api/truck/plans', ['date' => $today, 'stops' => [['kind' => 'event', 'point' => ['lat' => 95, 'lng' => 0], 'open_minute' => 600, 'close_minute' => 700]]]),
        'stops[0].point must have lat between -90 and 90 and lng between -180 and 180',
        'stops[0].point',
        'V10'
    );
    $refused(
        $c->post('/api/truck/plans', ['date' => $today, 'stops' => [['kind' => 'event', 'point' => $base, 'open_minute' => 600, 'close_minute' => 700]]]),
        'stops[0].event is required',
        'stops[0].event',
        'V1'
    );
    $refused(
        $c->post('/api/truck/plans', ['date' => $today, 'stops' => [['kind' => 'event', 'point' => $base, 'open_minute' => 600, 'close_minute' => 700, 'fee_pct' => 1.5, 'event' => ['attendance' => 500, 'vendors' => 2, 'event_type' => 'general']]]]),
        'stops[0].fee_pct must be a number between 0 and 1',
        'stops[0].fee_pct',
        'V2'
    );
    $refused(
        $c->post('/api/truck/plans', ['date' => $today, 'stops' => [['kind' => 'catering', 'point' => $base, 'open_minute' => 600, 'close_minute' => 700, 'catering' => ['headcount' => 40]]]]),
        'stops[0].catering needs price_per_head or guarantee',
        'stops[0].catering'
    );
    $refused($c->post('/api/truck/plans/evaluate', ['stops' => []]), 'date is required', 'date', 'V1');
    $refused($c->sendRaw('POST', '/api/truck/plans', '[1, 2]'), 'Request body must be a JSON object');
    $c->get('/api/truck/plans', ['from' => $today, 'to' => $today])->status(200)->path('data.plans', []);

    // =====================================================================================
    // 2. a preview (route 24): evaluated, not stored
    // =====================================================================================
    $c->post('/api/truck/plans/evaluate', $day + ['name' => 'not read', 'status' => 'not read either'])->status(200)
        ->path('success', true)
        ->path('data.result.model_version', $modelVersion)
        ->path('data.result.seeds_revision', $seedsRevision)
        ->path('data.result.date', $today)
        ->path('data.context.ctx.date', $today)
        ->path('data.context.ctx_next.date', $tomorrow)
        ->path('data.context.ctx.treat_as', null)
        ->path('data.context.calibration', ['as_of' => $today, 'truck_factor' => 1, 'truck_n' => 0])
        ->path('data.context.model_version', $modelVersion)
        ->finite('data.result.totals.take_home.value', 'data.result.totals.day_hours', 'data.result.timeline.start_prep', 'data.result.timeline.done', 'data.context.ctx.fuel_price_per_gal');
    $preview = (array) $c->value('data');
    $c->check(array_keys($preview) === ['result', 'context'], 'a preview answers the result and its context');
    $c->check(array_column($preview['result']['stops'], 'id') === ['stop:1', 'stop:2'], 'a previewed stop without an id is called by its position');
    $c->check(!str_contains($c->body(), '"data":[]'), 'the data of a warning is an object, also when it is empty');
    $c->get('/api/truck/plans', ['from' => $today, 'to' => $today])->status(200)->path('data.plans', []);

    // =====================================================================================
    // 1a. a plan is saved with its result (route 23)
    // =====================================================================================
    $c->post('/api/truck/plans', $day + ['name' => '  Smoke day  ', 'status' => 'planned', 'notes' => 'Bring the awning'])->status(201)
        ->path('success', true)
        ->path('message', 'Plan saved')
        ->path('data.plan.date', $today)
        ->path('data.plan.name', 'Smoke day')
        ->path('data.plan.treat_as', null)
        ->path('data.plan.notes', 'Bring the awning')
        ->path('data.plan.status', 'planned')
        ->path('data.plan.result_state', 'fresh')
        ->path('data.plan.result.date', $today)
        ->path('data.plan.context.uses_google_legs', false);
    $plan = (array) $c->value('data.plan');
    $planId = (string) $plan['id'];
    $c->check(!str_contains($c->body(), '"data":[]'), 'the data of a stored warning is an object, also when it is empty');
    $c->check(
        array_keys($plan) === ['id', 'date', 'name', 'treat_as', 'notes', 'status', 'stops', 'result', 'context', 'result_state', 'evaluated_at', 'maps_route_url', 'created_at', 'updated_at'],
        'a plan has the fields of the specification, in its order'
    );
    $c->check(strlen($planId) === 36 && is_string($plan['evaluated_at']), 'the plan has an id and the time it was evaluated');
    $c->check(count($plan['stops']) === 2, 'two stops, in the order sent');
    [$stopA, $stopB] = $plan['stops'];
    $c->check(
        array_keys($stopA) === ['id', 'kind', 'spot_id', 'label', 'point', 'address', 'open_minute', 'close_minute', 'gap_before_unpaid', 'setup_minutes', 'teardown_minutes', 'fee_flat', 'fee_pct', 'fee_min', 'event', 'catering'],
        'a stop has the fields of the specification, in its order'
    );
    $c->check($stopA['spot_id'] === $spots['first'] && $stopB['spot_id'] === $hostId && $stopA['point'] === null, 'a spot stop names its spot and takes its point from it');
    $c->check(strlen((string) $stopA['id']) === 36 && $stopA['id'] !== $stopB['id'], 'every stop has an id of its own');
    $c->check(
        str_starts_with((string) $plan['maps_route_url'], 'https://www.google.com/maps/dir/?api=1&origin=' . sprintf('%.6F', $base['lat']) . '%2C' . sprintf('%.6F', $base['lng']) . '&destination=')
        && str_contains((string) $plan['maps_route_url'], '&waypoints=') && str_ends_with((string) $plan['maps_route_url'], '&travelmode=driving'),
        'the route link runs from the base through the stops and back'
    );

    // The result: a timeline, the orders and money of each stop as ranges with a confidence label, the totals.
    $result = $plan['result'];
    $c->check(array_keys($result) === ['model_version', 'seeds_revision', 'date', 'timeline', 'stops', 'totals', 'unpaid_gap_alternative', 'warnings'], 'the result is a DayResult');
    $c->check(array_column($result['stops'], 'id') === [$stopA['id'], $stopB['id']], 'the stops of the result are the stops of the plan');
    foreach ($result['stops'] as $stop) {
        $c->check($isEstimate($stop['orders']) && $isEstimate($stop['money']['contribution']) && $isEstimate($stop['adds']['take_home']), 'what a stop brings is a range with a confidence label');
    }
    foreach (['orders', 'sales', 'take_home', 'take_home_per_hour', 'labour', 'fuel'] as $line) {
        $c->check($isEstimate($result['totals'][$line]), 'the day total ' . $line . ' is a range with a confidence label');
    }
    $timeline = $result['timeline'];
    $c->check($timeline['done'] - $timeline['start_prep'] === $timeline['day_minutes'] && $timeline['stops'][0]['open'] === 660 && $timeline['stops'][1]['close'] === 1200, 'the timeline keeps the owner\'s hours');
    $c->check($result['stops'][1]['orders']['value'] > 3, 'a taproom of 120 with the truck as its only food has orders in the evening');
    foreach ($result['warnings'] as $warning) {
        $c->check(array_keys($warning) === ['code', 'level', 'stop_index', 'data'] && is_array($warning['data']), 'a warning is a code, a level, a stop and its data');
    }

    // The context: the two day contexts, every leg the model looked up, the calibration, the fuel price.
    $context = $plan['context'];
    $c->check(
        array_keys($context) === ['ctx', 'ctx_next', 'legs', 'calibration', 'fuel', 'model_version', 'seeds_revision', 'dataset_version', 'uses_google_legs'],
        'the context is an EvalContext'
    );
    $c->check($context['ctx']['date'] === $today && $context['ctx_next']['date'] === $tomorrow, 'the contexts of the date and of the next date');
    $c->check($context['calibration'] == ['as_of' => $today, 'truck_factor' => 1, 'truck_n' => 0], 'evaluated with the calibration of today');
    $pairs = array_map(static fn (array $leg): array => [$leg['from_id'], $leg['to_id']], $context['legs']);
    $c->check(
        $pairs === [['base', $stopA['id']], [$stopA['id'], $stopB['id']], [$stopB['id'], 'base'], ['base', $stopB['id']], [$stopA['id'], 'base']],
        'the legs of the day as ordered, then the legs that appear when one stop is left out'
    );
    foreach ($context['legs'] as $leg) {
        $c->check(in_array($leg['source'], ['straight_line', 'same_point', 'google_routes', 'google_distance_matrix'], true), 'a leg says where it comes from');
        if ($noKey) {
            // ---- 2. without a Google key: labelled straight lines (and no distance at all between equal points)
            $c->check(in_array($leg['source'], ['straight_line', 'same_point'], true), 'without a Google key no leg comes from Google');
            $c->check($leg['source'] === 'same_point' || $leg['fallback_reason'] === 'no_key', 'a straight-line leg says why it is one');
            $c->check($leg['leg_input']['source'] === ($leg['source'] === 'straight_line' ? 'fallback' : 'google'), 'and the model is told so');
        }
    }
    if ($noKey) {
        $c->check(in_array('fallback_drive_time', array_column($result['warnings'], 'code'), true), 'the day says that its drive times are estimates');
    }

    // The stored result is the model's day plan on exactly these inputs.
    $inputs = [$spotStop($stopA['id'], $first, 660, 840), $spotStop($stopB['id'], $host, 1020, 1200)];
    $expected = Estimator::dayPlan($A, $profile, ['date' => $today, 'stops' => $inputs], $context['ctx'], $context['ctx_next'], $legInputs($context), Estimator::calibrate($A, [], $today));
    $c->check($same($expected, $result), 'the stored result equals Estimator::dayPlan on the same inputs');
    $c->check($same($preview['result']['totals'], $result['totals']) || $preview['context']['ctx'] !== $context['ctx'], 'and it is what the preview answered');

    // Read back: by id, in the list, in the list with stops.
    $c->get('/api/truck/plans/' . $planId)->status(200)->path('data.plan', $plan);
    $c->get('/api/truck/plans', ['from' => $today, 'to' => $today])->status(200);
    $rows = (array) $c->value('data.plans');
    $c->check(count($rows) === 1 && array_keys($rows[0]) === ['id', 'date', 'name', 'treat_as', 'status', 'stop_count', 'result_state', 'summary', 'updated_at'], 'a listed plan has the fields of the specification');
    $c->path('data.plans.0.id', $planId)->path('data.plans.0.stop_count', 2)->path('data.plans.0.result_state', 'fresh')
        ->path('data.plans.0.summary', ['orders' => $result['totals']['orders'], 'take_home' => $result['totals']['take_home'], 'day_hours' => $result['totals']['day_hours']]);
    $c->get('/api/truck/plans', ['from' => $today, 'to' => $today, 'stops' => 1])->status(200);
    $withStops = (array) $c->value('data.plans.0');
    $c->check(
        array_keys($withStops) === ['id', 'date', 'name', 'treat_as', 'notes', 'status', 'stops', 'stop_count', 'result_state', 'evaluated_at', 'summary', 'maps_route_url', 'created_at', 'updated_at'],
        'with stops=1 a listed plan is a plan without its result and context'
    );
    $c->path('data.plans.0.stops', $plan['stops'])->path('data.plans.0.maps_route_url', $plan['maps_route_url']);
    $c->get('/api/truck/plans')->status(200)->path('data.plans.0.id', $planId);
    $c->get('/api/truck/bootstrap')->status(200)->path('data.counts.plans', 1);

    // One plan per date.
    $c->post('/api/truck/plans', ['date' => $today])->status(409)->path('success', false)->path('error', 'A plan already exists for this date');

    // =====================================================================================
    // 1b. changes (route 26) and a new evaluation on request (route 28)
    // =====================================================================================
    $refused($c->put('/api/truck/plans/' . $planId, []), 'Nothing to update', null, 'V12');
    $refused($c->put('/api/truck/plans/' . $planId, ['colour' => 'red']), 'Nothing to update', null, 'V12');
    $refused($c->put('/api/truck/plans/' . $planId, ['name' => str_repeat('n', 121)]), 'name must be text of at most 120 characters', 'name', 'V5');
    $c->put('/api/truck/plans/' . $planId, ['name' => 'Smoke day, renamed', 'notes' => ''])->status(200)
        ->path('data.plan.name', 'Smoke day, renamed')
        ->path('data.plan.notes', null)
        ->path('data.plan.status', 'planned')
        ->path('data.plan.stops', $plan['stops'])
        ->path('data.plan.result_state', 'fresh');

    // The stops are replaced as a set: the evening stop comes back with its id and other hours, lunch is
    // dropped, and a stop at the second spot is new. An id that is not this plan's is not taken over.
    $c->put('/api/truck/plans/' . $planId, ['stops' => [
        ['id' => $missing, 'kind' => 'spot', 'spot_id' => $spots['second'], 'open_minute' => 600, 'close_minute' => 780],
        ['id' => $stopB['id']] + $evening,
    ]])->status(200)->path('data.plan.result_state', 'fresh');
    $changed = (array) $c->value('data.plan');
    $c->check(count($changed['stops']) === 2 && $changed['stops'][1]['id'] === $stopB['id'], 'a stop that is sent back with its id keeps it');
    $c->check(!in_array($changed['stops'][0]['id'], [$stopA['id'], $missing], true), 'a new stop gets a new id');
    $c->check($changed['stops'][0]['spot_id'] === $spots['second'] && $changed['stops'][0]['open_minute'] === 600, 'the stops are the ones sent, in the order sent');
    $c->check(array_column($changed['result']['stops'], 'id') === array_column($changed['stops'], 'id'), 'the plan was evaluated again with its new stops');
    $c->check($changed['result']['timeline']['stops'][0]['open'] === 600, 'and its timeline follows');
    $newStop = (string) $changed['stops'][0]['id'];
    $expected = Estimator::dayPlan(
        $A,
        $profile,
        ['date' => $today, 'stops' => [$spotStop($newStop, $second, 600, 780), $spotStop($stopB['id'], $host, 1020, 1200)]],
        $changed['context']['ctx'],
        $changed['context']['ctx_next'],
        $legInputs($changed['context']),
        Estimator::calibrate($A, [], $today)
    );
    $c->check($same($expected, $changed['result']), 'the changed plan\'s stored result is the model\'s as well');
    $c->get('/api/truck/plans/' . $planId)->status(200)->path('data.plan.stops', $changed['stops']);

    // Evaluated again on request, with or without a body.
    $c->post('/api/truck/plans/' . $planId . '/evaluate')->status(200)->path('data.plan.id', $planId)->path('data.plan.result_state', 'fresh')->path('data.plan.stops', $changed['stops']);
    $c->post('/api/truck/plans/' . $planId . '/evaluate', [])->status(200)->path('data.plan.result_state', 'fresh');
    $plan = (array) $c->value('data.plan');

    // An event and a catering job, tomorrow. Its date cannot be moved onto today's plan.
    $c->post('/api/truck/plans', ['date' => $tomorrow, 'name' => 'Smoke fair', 'stops' => [
        [
            'kind' => 'event', 'point' => $hostPoint, 'label' => 'Fall fair', 'address' => '1 Fair Way', 'open_minute' => 660, 'close_minute' => 900,
            'fee_pct' => 0.1, 'fee_min' => 75.005, 'event' => ['attendance' => 2000, 'vendors' => 6, 'event_type' => 'general'],
        ],
        [
            'kind' => 'catering', 'point' => $base, 'label' => 'Office party', 'open_minute' => 1020, 'close_minute' => 1140, 'gap_before_unpaid' => true,
            'catering' => ['headcount' => 80, 'price_per_head' => 14, 'guarantee' => 1000, 'food_cost' => null],
        ],
    ]])->status(201)
        ->path('data.plan.status', 'draft')
        ->path('data.plan.stops.0.kind', 'event')
        ->path('data.plan.stops.0.spot_id', null)
        ->path('data.plan.stops.0.point', $hostPoint)
        ->path('data.plan.stops.0.label', 'Fall fair')
        ->path('data.plan.stops.0.fee_min', 75.01)
        ->path('data.plan.stops.0.event', ['attendance' => 2000, 'vendors' => 6, 'event_type' => 'general'])
        ->path('data.plan.stops.0.catering', null)
        ->path('data.plan.stops.1.kind', 'catering')
        ->path('data.plan.stops.1.gap_before_unpaid', true)
        ->path('data.plan.stops.1.catering', ['headcount' => 80, 'price_per_head' => 14, 'guarantee' => 1000, 'food_cost' => null])
        ->path('data.plan.result.stops.0.kind', 'event')
        ->path('data.plan.result.stops.0.orders.confidence', 'very_rough')
        ->path('data.plan.result.stops.1.kind', 'catering')
        ->path('data.plan.result.stops.1.orders', ['value' => 80, 'low' => 80, 'high' => 80, 'confidence' => 'fixed'])
        ->path('data.plan.result.stops.1.money.sales.value', 1120)
        ->path('data.plan.result_state', 'fresh');
    $fair = (array) $c->value('data.plan');
    $fairId = (string) $fair['id'];
    $c->check($isEstimate($fair['result']['stops'][0]['orders']) && $fair['result']['stops'][0]['orders']['value'] > 0, 'an event is estimated from its attendance');
    $c->put('/api/truck/plans/' . $fairId, ['date' => $today])->status(409)->path('error', 'A plan already exists for this date');
    $c->get('/api/truck/plans/' . $fairId)->status(200)->path('data.plan.date', $tomorrow);
    $c->get('/api/truck/plans')->status(200);
    $c->check(array_column((array) $c->value('data.plans'), 'id') === [$planId, $fairId], 'the plans are listed by date');

    // An id that is no plan.
    $c->get('/api/truck/plans/' . $missing)->status(404)->path('success', false)->path('error', 'Plan not found');
    $c->put('/api/truck/plans/' . $missing, ['name' => 'x'])->status(404)->path('error', 'Plan not found');
    $c->delete('/api/truck/plans/' . $missing)->status(404)->path('error', 'Plan not found');
    $c->post('/api/truck/plans/' . $missing . '/evaluate')->status(404)->path('error', 'Plan not found');

    // =====================================================================================
    // 3. logged services
    // =====================================================================================
    $c->get('/api/truck/services')->status(200)->path('data.services', []);
    $service = ['spot_id' => $hostId, 'date' => $today, 'open_minute' => 1020, 'close_minute' => 1200, 'actual' => 10];
    $refused($c->post('/api/truck/services', []), 'spot_id is required', 'spot_id', 'V1');
    $refused($c->post('/api/truck/services', ['spot_id' => $missing] + $service), 'spot_id was not found', 'spot_id', 'V11');
    $refused($c->post('/api/truck/services', ['kind' => 'market'] + $service), 'kind must be one of: spot, event, catering', 'kind', 'V4');
    $refused($c->post('/api/truck/services', ['date' => $tomorrow] + $service), 'date must not be in the future', 'date');
    $refused($c->post('/api/truck/services', ['date' => 'today'] + $service), 'date must be a date in the form YYYY-MM-DD', 'date', 'V7');
    $refused($c->post('/api/truck/services', ['close_minute' => 1020] + $service), 'close_minute must be after open_minute', 'close_minute');
    $refused($c->post('/api/truck/services', ['actual' => 5001] + $service), 'actual must be a whole number between 0 and 5000', 'actual', 'V3');
    $refused($c->post('/api/truck/services', ['sales' => -1] + $service), 'sales must be a number between 0 and 1000000', 'sales', 'V2');
    $refused($c->post('/api/truck/services', ['sold_out' => 'no'] + $service), 'sold_out must be true or false', 'sold_out', 'V6');
    $refused($c->post('/api/truck/services', ['notes' => str_repeat('n', 2001)] + $service), 'notes must be text of at most 2000 characters', 'notes', 'V5');
    $refused($c->post('/api/truck/services', ['plan_stop_id' => $missing] + $service), 'plan_stop_id was not found', 'plan_stop_id', 'V11');
    $refused($c->post('/api/truck/services', ['treat_as' => 'weekend'] + $service), $treatAs, 'treat_as', 'V4');
    $refused($c->get('/api/truck/services', ['from' => Estimator::addDays($today, -730)]), 'The date range must be at most 730 days');
    $refused($c->get('/api/truck/services', ['from' => $today, 'to' => $lastWeek]), 'to must not be before from', 'to');
    $c->get('/api/truck/services')->status(200)->path('data.services', []);

    // Logged against the planned evening stop, with the planned hours: the estimate is the one the plan showed.
    $planned = $plan['result']['stops'][1];
    $half = max(1, (int) Estimator::roundHalfAway($planned['orders']['value'] * 0.5, 0));
    $c->post('/api/truck/services', ['actual' => $half, 'plan_stop_id' => $stopB['id'], 'sales' => 123.456, 'notes' => '  Slow evening  '] + $service)->status(201)
        ->path('success', true)
        ->path('message', 'Service logged')
        ->path('data.service.kind', 'spot')
        ->path('data.service.spot_id', $hostId)
        ->path('data.service.plan_id', $planId)
        ->path('data.service.plan_stop_id', $stopB['id'])
        ->path('data.service.date', $today)
        ->path('data.service.actual', $half)
        ->path('data.service.sales', 123.46)
        ->path('data.service.sold_out', false)
        ->path('data.service.notes', 'Slow evening')
        ->path('data.service.source', 'manual')
        ->path('data.service.external_key', null)
        ->path('data.service.treat_as', null)
        ->path('data.service.prediction.basis', 'plan')
        ->path('data.service.prediction.predicted', $planned['orders']['value'])
        ->path('data.service.prediction.low', $planned['orders']['low'])
        ->path('data.service.prediction.high', $planned['orders']['high'])
        ->path('data.service.prediction.confidence', $planned['orders']['confidence'])
        ->path('data.service.prediction.model_version', $modelVersion)
        ->path('data.service.prediction.seeds_revision', $seedsRevision)
        ->path('data.service.prediction.detail.plan_id', $planId)
        ->path('data.calibration.truck_n', 1)
        ->path('data.calibration.as_of', $today)
        ->finite('data.service.prediction.predicted_raw', 'data.calibration.truck_factor');
    $logged = (array) $c->value('data.service');
    $c->check(
        array_keys($logged) === ['id', 'kind', 'spot_id', 'plan_id', 'plan_stop_id', 'date', 'open_minute', 'close_minute', 'actual', 'sales', 'sold_out', 'notes', 'source', 'external_key', 'treat_as', 'prediction', 'created_at', 'updated_at'],
        'a logged service has the fields of the specification, in its order'
    );
    $c->check(
        array_keys($logged['prediction']) === ['predicted_raw', 'predicted', 'low', 'high', 'confidence', 'basis', 'model_version', 'seeds_revision', 'dataset_version', 'detail'],
        'and its prediction too'
    );
    $c->check($same($logged['prediction']['predicted_raw'], $planned['orders']['value']), 'with no service before it the raw prediction is the planned figure');
    $afterFirst = (array) $c->value('data.calibration');
    $c->check($afterFirst['truck_factor'] < 1 && array_keys($afterFirst['spots']) === [$hostId], 'half the estimated orders pull the truck factor below 1');
    $c->check($same(Estimator::calibrate($A, [$entry($logged)], $today), $afterFirst), 'the calibration answered is the model\'s on the logged service');
    $c->post('/api/truck/services', ['actual' => 99] + $service)->status(409)->path('success', false)->path('error', 'A service is already logged for this spot and time');

    // A week earlier, not planned: the service gets its own estimate, from the services before it (none).
    $c->post('/api/truck/services', ['date' => $lastWeek, 'actual' => 44, 'sold_out' => true] + $service)->status(201)
        ->path('data.service.plan_id', null)
        ->path('data.service.sold_out', true)
        ->path('data.service.prediction.basis', 'log')
        ->path('data.service.prediction.detail.plan_id', null)
        ->path('data.calibration.truck_n', 2);
    $earlier = (array) $c->value('data.service');
    $c->check($earlier['prediction']['predicted'] === $earlier['prediction']['predicted_raw'], 'nothing was logged before it: the estimate is the model\'s alone');
    $c->check($isEstimate(['value' => $earlier['prediction']['predicted'], 'low' => $earlier['prediction']['low'], 'high' => $earlier['prediction']['high'], 'confidence' => $earlier['prediction']['confidence']]), 'an estimate is a range with a confidence label');
    $c->check(!str_contains($c->body(), '"detail":[]') && !str_contains($c->body(), '"spots":[]'), 'maps are sent as objects');

    // Read back: newest first, one by one, by spot.
    $c->get('/api/truck/services')->status(200)->path('data.services', [$logged, $earlier]);
    $c->get('/api/truck/services', ['from' => $lastWeek, 'to' => $lastWeek])->status(200)->path('data.services', [$earlier]);
    $c->get('/api/truck/services', ['spot_id' => $spots['first']])->status(200)->path('data.services', []);
    $c->get('/api/truck/services', ['spot_id' => $hostId])->status(200);
    $c->check(count((array) $c->value('data.services')) === 2, 'the services of one spot');
    $c->get('/api/truck/services/' . $earlier['id'])->status(200)->path('data.service', $earlier);
    $c->get('/api/truck/services/' . $missing)->status(404)->path('error', 'Service not found');

    // A change of the count leaves the prediction as it is; a change of the hours builds it again.
    $refused($c->put('/api/truck/services/' . $earlier['id'], []), 'Nothing to update', null, 'V12');
    $c->put('/api/truck/services/' . $earlier['id'], ['actual' => 48, 'sold_out' => false, 'notes' => 'Counted again'])->status(200)
        ->path('data.service.actual', 48)
        ->path('data.service.sold_out', false)
        ->path('data.service.notes', 'Counted again')
        ->path('data.service.prediction', $earlier['prediction'])
        ->path('data.calibration.truck_n', 2);
    $c->put('/api/truck/services/' . $earlier['id'], ['close_minute' => 1140])->status(200)
        ->path('data.service.close_minute', 1140)
        ->path('data.service.prediction.basis', 'log');
    $shorter = (array) $c->value('data.service');
    $c->check($shorter['prediction']['predicted_raw'] < $earlier['prediction']['predicted_raw'], 'two hours are estimated lower than three');
    $c->put('/api/truck/services/' . $earlier['id'], ['open_minute' => 1020, 'close_minute' => 1200, 'date' => $today])->status(409)
        ->path('error', 'A service is already logged for this spot and time');
    $c->put('/api/truck/services/' . $missing, ['actual' => 1])->status(404)->path('error', 'Service not found');
    $c->get('/api/truck/services/' . $earlier['id'])->status(200)->path('data.service', $shorter);

    // =====================================================================================
    // 4. calibration and accuracy
    // =====================================================================================
    $c->get('/api/truck/accuracy')->status(200)
        ->path('success', true)
        ->path('data.accuracy.n_total', 2)
        ->path('data.accuracy.n_scored', 2)
        ->path('data.accuracy.n_sold_out', 0)
        ->path('data.unscored_without_prediction', 0)
        ->finite('data.accuracy.bias', 'data.accuracy.mape', 'data.accuracy.coverage', 'data.accuracy.raw_bias', 'data.accuracy.raw_mape');
    $accuracy = (array) $c->value('data');
    $c->check(array_keys($accuracy) === ['accuracy', 'entries', 'unscored_without_prediction'], 'the accuracy answer has the fields of the specification');
    $entries = [$entry($shorter), $entry($logged)];
    $c->check($same($entries, $accuracy['entries']), 'the entries are the logged services as the model reads them, oldest first');
    $c->check($same(Estimator::accuracyReport($entries), $accuracy['accuracy']), 'the report is the model\'s on those entries');
    $c->check(count($accuracy['accuracy']['by_spot']) === 1 && $accuracy['accuracy']['by_spot'][0]['spot_id'] === $hostId, 'both services were at one spot');
    $c->get('/api/truck/accuracy', ['from' => $today, 'to' => $today])->status(200)->path('data.accuracy.n_total', 1)->path('data.entries.0.service_id', $logged['id']);
    $c->get('/api/truck/accuracy', ['to' => Estimator::addDays($lastWeek, -1)])->status(200)->path('data.accuracy.n_total', 0)->path('data.entries', [])->path('data.accuracy.bias', null);
    $refused($c->get('/api/truck/accuracy', ['from' => 'last week']), 'from must be a date in the form YYYY-MM-DD', 'from', 'V7');
    $refused($c->get('/api/truck/accuracy', ['from' => $today, 'to' => $lastWeek]), 'to must not be before from', 'to');

    $c->get('/api/truck/calibration')->status(200)
        ->path('success', true)
        ->path('data.as_of', $today)
        ->path('data.log_count', 2)
        ->path('data.eligible_count', 2)
        ->path('data.raw_recomputed', 0)
        ->path('data.calibration.truck_n', 2);
    $calibration = (array) $c->value('data');
    $c->check(array_keys($calibration) === ['calibration', 'as_of', 'log_count', 'eligible_count', 'raw_recomputed'], 'the calibration answer has the fields of the specification');
    $c->check($same(Estimator::calibrate($A, $entries, $today), $calibration['calibration']), 'the calibration is the model\'s on the logged services');
    $c->check($calibration['calibration']['truck_factor'] != 1 && $calibration['calibration']['truck_factor'] !== $afterFirst['truck_factor'], 'a second service moved the truck factor again');
    $c->get('/api/truck/bootstrap')->status(200)->path('data.calibration', $calibration['calibration'])->path('data.counts.services', 2);

    // The saved day was evaluated before any service was logged: it is stale now, and still shown.
    // (Time stamps are whole seconds: the pause makes "after" certain.)
    $c->get('/api/truck/plans/' . $planId)->status(200);
    $c->check(in_array($c->value('data.plan.result_state'), ['fresh', 'stale'], true) && $c->value('data.plan.result') !== null, 'a stored result is shown while it is fresh or stale');
    sleep(1);
    $c->put('/api/truck/assumptions', ['overrides' => ['host.captive_share' => 0.6]])->status(200)->path('data.assumptions.overrides', ['host.captive_share' => 0.6]);
    $c->get('/api/truck/plans/' . $planId)->status(200)->path('data.plan.result_state', 'stale')->path('data.plan.result.date', $today);
    $c->get('/api/truck/plans', ['from' => $today, 'to' => $today])->status(200)->path('data.plans.0.result_state', 'stale');
    $c->check($c->value('data.plans.0.summary') !== null, 'a stale plan is listed with its summary');

    // The assumption changed what the model says about a taproom: the raw predictions of both services are
    // computed again, once. What the owner was shown stays.
    $A2 = Seeds::assumptions(['host.captive_share' => 0.6], $A['region']);
    $c->get('/api/truck/calibration')->status(200)->path('data.raw_recomputed', 2)->path('data.log_count', 2)->path('data.eligible_count', 2);
    $recomputed = (array) $c->value('data.calibration');
    $c->get('/api/truck/calibration')->status(200)->path('data.raw_recomputed', 0)->path('data.calibration', $recomputed);
    $c->get('/api/truck/services/' . $logged['id'])->status(200)
        ->path('data.service.prediction.predicted', $logged['prediction']['predicted'])
        ->path('data.service.prediction.low', $logged['prediction']['low'])
        ->path('data.service.prediction.basis', 'plan');
    $rawNow = $c->value('data.service.prediction.predicted_raw');
    $c->check($rawNow < $logged['prediction']['predicted_raw'], 'fewer guests of the taproom eat from the truck: the raw prediction is lower');
    $c->get('/api/truck/accuracy')->status(200);
    $c->check($same(Estimator::calibrate($A2, (array) $c->value('data.entries'), $today), $recomputed), 'and the calibration is the model\'s on the new raw predictions');
    $c->check($recomputed['truck_factor'] > $calibration['calibration']['truck_factor'], 'so the truck did less badly than it seemed');

    // Evaluated again, the day is fresh, with the logged services and the assumption in it.
    $c->post('/api/truck/plans/' . $planId . '/evaluate')->status(200)
        ->path('data.plan.result_state', 'fresh')
        ->path('data.plan.context.calibration.truck_n', 2)
        ->path('data.plan.context.calibration.truck_factor', $recomputed['truck_factor']);
    $c->check($c->value('data.plan.result.stops.1.orders.value') < $planned['orders']['value'], 'the evening is estimated lower than before');

    // The assumption is taken back: step 50 leaves the assumptions as it found them.
    $c->post('/api/truck/assumptions/reset', [])->status(200)->path('data.assumptions.overrides', []);
    $c->check(str_contains($c->body(), '"overrides":{}'), 'an empty map of overrides is an object');
    $c->get('/api/truck/calibration')->status(200)->path('data.raw_recomputed', 2);
    $c->check($same($calibration['calibration'], (array) $c->value('data.calibration')), 'with the assumption taken back the calibration is what it was');

    // =====================================================================================
    // 5. the second user
    // =====================================================================================
    $c->as(2)->get('/api/truck/plans')->status(200)->path('data.plans', []);
    $c->get('/api/truck/plans/' . $planId)->status(404)->path('success', false)->path('error', 'Plan not found');
    $c->put('/api/truck/plans/' . $planId, ['name' => 'Taken over'])->status(404)->path('error', 'Plan not found');
    $c->post('/api/truck/plans/' . $planId . '/evaluate')->status(404)->path('error', 'Plan not found');
    $c->delete('/api/truck/plans/' . $planId)->status(404)->path('error', 'Plan not found');
    $c->get('/api/truck/services')->status(200)->path('data.services', []);
    $c->get('/api/truck/services/' . $logged['id'])->status(404)->path('success', false)->path('error', 'Service not found');
    $c->put('/api/truck/services/' . $logged['id'], ['actual' => 1])->status(404)->path('error', 'Service not found');
    $c->delete('/api/truck/services/' . $logged['id'])->status(404)->path('error', 'Service not found');
    $c->get('/api/truck/calibration')->status(200)->path('data.log_count', 0)->path('data.calibration.truck_factor', 1)->path('data.calibration.truck_n', 0);
    $c->check(str_contains($c->body(), '"spots":{}'), 'an empty map of spot factors is an object');
    $c->get('/api/truck/accuracy')->status(200)->path('data.accuracy.n_total', 0)->path('data.entries', []);
    // It cannot plan or log at the first user's spot, or log against the first user's planned stop.
    $refused($c->post('/api/truck/plans', ['date' => $today, 'stops' => [$evening]]), 'stops[0].spot_id was not found', 'stops[0].spot_id', 'V11');
    $refused($c->post('/api/truck/plans/evaluate', ['date' => $today, 'stops' => [$evening]]), 'stops[0].spot_id was not found', 'stops[0].spot_id', 'V11');
    $refused($c->post('/api/truck/services', $service), 'spot_id was not found', 'spot_id', 'V11');
    $refused(
        $c->post('/api/truck/services', ['kind' => 'event', 'date' => $today, 'open_minute' => 600, 'close_minute' => 660, 'actual' => 1, 'plan_stop_id' => $stopB['id']]),
        'plan_stop_id was not found',
        'plan_stop_id',
        'V11'
    );
    // A plan of its own on the same date: two tenants, two calendars. An id in a body is not read.
    $c->post('/api/truck/plans', ['date' => $today, 'id' => $planId, 'organization_id' => $state['users'][1]['organization_id']])->status(201)->path('data.plan.stops', []);
    $otherPlan = (string) $c->value('data.plan.id');
    $c->check($otherPlan !== $planId && $c->value('data.plan.maps_route_url') === null, 'the second user\'s plan is its own, and a day without stops has no route');
    $c->delete('/api/truck/plans/' . $otherPlan)->status(200)->path('data', ['id' => $otherPlan, 'deleted' => true]);
    $c->get('/api/truck/plans')->status(200)->path('data.plans', []);

    $c->as(1)->get('/api/truck/plans/' . $otherPlan)->status(404)->path('error', 'Plan not found');
    $c->get('/api/truck/plans/' . $planId)->status(200)->path('data.plan.name', 'Smoke day, renamed')->path('data.plan.stops', $changed['stops']);
    $c->get('/api/truck/services/' . $logged['id'])->status(200)->path('data.service.actual', $half);

    // =====================================================================================
    // 6. deletes
    // =====================================================================================
    $c->delete('/api/truck/services/' . $earlier['id'])->status(200)
        ->path('data.id', $earlier['id'])
        ->path('data.deleted', true)
        ->path('data.calibration.truck_n', 1);
    $c->check($same($afterFirst, (array) $c->value('data.calibration')), 'without the second service the calibration is what the first one alone gave');
    $c->get('/api/truck/services/' . $earlier['id'])->status(404)->path('error', 'Service not found');
    $c->delete('/api/truck/services/' . $earlier['id'])->status(404)->path('error', 'Service not found');

    // The plan goes; the service that was logged against it keeps its numbers and loses the link.
    $c->delete('/api/truck/plans/' . $planId)->status(200)->path('data', ['id' => $planId, 'deleted' => true]);
    $c->get('/api/truck/plans/' . $planId)->status(404)->path('error', 'Plan not found');
    $c->get('/api/truck/services/' . $logged['id'])->status(200)
        ->path('data.service.plan_id', null)
        ->path('data.service.plan_stop_id', null)
        ->path('data.service.actual', $half)
        ->path('data.service.prediction.basis', 'plan')
        ->path('data.service.prediction.predicted', $logged['prediction']['predicted']);
    $c->get('/api/truck/calibration')->status(200)->path('data.log_count', 1)->path('data.calibration.truck_n', 1);

    // ---- what the later steps start from
    // The host spot is archived: plans and logs keep referring to it, and the active spots are those of step 30.
    $c->delete('/api/truck/spots/' . $hostId)->status(200)->path('data.archived', true);
    $c->post('/api/truck/plans/evaluate', ['date' => $dayAfter, 'stops' => [['id' => 'evening'] + $evening]])->status(200)->path('data.result.stops.0.id', 'evening');
    $c->check($c->value('data.result.stops.0.orders.value') > 0, 'an archived spot can still be planned');
    $c->post('/api/truck/plans', ['date' => $dayAfter, 'name' => 'Smoke lunch and dinner', 'stops' => [
        $lunch,
        ['kind' => 'spot', 'spot_id' => $spots['second'], 'open_minute' => 1020, 'close_minute' => 1200],
    ]])->status(201)->path('data.plan.result_state', 'fresh')->path('data.plan.context.calibration.truck_n', 1);
    $keptId = (string) $c->value('data.plan.id');
    $c->get('/api/truck/plans', ['stops' => 1])->status(200);
    $c->check(array_column((array) $c->value('data.plans'), 'id') === [$fairId, $keptId], 'user 1 is left with two plans');
    $c->get('/api/truck/spots')->status(200);
    $c->check(array_column((array) $c->value('data.spots'), 'id') === [$spots['first'], $spots['second']], 'and with the two active spots of step 30');
    $c->get('/api/truck/bootstrap')->status(200)->path('data.counts.plans', 2)->path('data.counts.services', 1)->path('data.assumptions.overrides', []);
    $state['plans'] = ['plan_ids' => [$fairId, $keptId], 'service_id' => $logged['id'], 'host_spot' => $hostId];
};
