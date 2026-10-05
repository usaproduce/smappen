<?php
declare(strict_types=1);

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;

/**
 * Smoke step 30: simulate and saved spots (routes 9 to 16).
 *
 *   1. Simulate answers every point: one with nothing in reach gets zero vectors and an empty week, never
 *      an error. A body that fails names its field, with one refusal for each of the messages V1 to V11.
 *   2. Spots: saved with the vectors of the three visibility levels, read back as saved, changed key by
 *      key (V12 when a change carries nothing), refreshed one by one and in a sweep, archived and still
 *      there.
 *   3. The second user gets 404 for every request that names the first user's spot.
 *   4. With a usable region: at the truck's base the vectors hold people, the outlets come nearest first,
 *      the estimate is the model's week on those vectors, a saved spot stores exactly what simulate
 *      answered, and a spot goes stale and fresh again when the truck leaves its region and returns. If
 *      a place that could host the truck is found near the base, a host linked to it takes that place's
 *      own point out of the catchment.
 *
 * It starts from the trucks of step 20 and leaves user 1 with two spots and one archived spot.
 *
 * For the later steps:
 *
 *     $state['spots'] = ['first' => id, 'second' => id, 'archived' => id, 'point' => ['lat' => .., 'lng' => ..]]
 *
 * `first` stands at `point` (the truck's base), `second` 400 m north of it; both have no host and no fee.
 */
return function (SmokeClient $c, array &$state): void {
    $setup = $state['setup'] ?? null;
    if (!is_array($setup) || !isset($setup['base'], $setup['truck_id'][1], $setup['truck_id'][2])) {
        throw new SmokeFailure('step 30 starts from the two trucks that step 20 leaves in $state[\'setup\']');
    }
    $base = ['lat' => (float) $setup['base']['lat'], 'lng' => (float) $setup['base']['lng']];
    $levels = ['hidden', 'normal', 'prominent'];
    $zeros = array_fill(0, 16, 0);
    $mapsLink = 'https://www.google.com/maps/search/?api=1&query=';

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
    // `$metres` north of a point (south when negative).
    $north = static function (array $point, float $metres): array {
        return ['lat' => $point['lat'] + $metres / 6371008.8 * 180.0 / 3.141592653589793, 'lng' => $point['lng']];
    };
    // Two numbers that agree to the tolerance of the model's golden cases.
    $close = static function (mixed $a, mixed $b): bool {
        if (!(is_int($a) || is_float($a)) || !(is_int($b) || is_float($b))) {
            return false;
        }
        return abs($a - $b) <= 1e-9 * max(1.0, abs($a), abs($b));
    };
    // A LocationVectors as the API sends it.
    $checkVectors = static function (SmokeClient $c, mixed $vectors, string $level, string $what): void {
        $c->check(is_array($vectors), $what . ': vectors for ' . $level);
        foreach ([['capture', 'day'], ['capture', 'eve'], ['nearby']] as $path) {
            $list = $vectors;
            foreach ($path as $key) {
                $list = is_array($list) ? ($list[$key] ?? null) : null;
            }
            $c->check(is_array($list) && count($list) === 16, $what . ': ' . $level . '.' . implode('.', $path) . ' has 16 numbers');
            foreach ((array) $list as $x) {
                $c->check((is_int($x) || is_float($x)) && $x >= 0, $what . ': ' . $level . '.' . implode('.', $path) . ' holds numbers');
            }
        }
        $c->check(is_array($vectors['rivals'] ?? null) && isset($vectors['rivals']['day'], $vectors['rivals']['eve']), $what . ': rivals by regime');
        $c->check(($vectors['visibility'] ?? null) === $level, $what . ': the vectors name their visibility');
        $c->check(is_bool($vectors['in_region'] ?? null), $what . ': in_region is a flag');
        $c->check(is_array($vectors['exclusion'] ?? null) && is_array($vectors['exclusion']['point_ids'] ?? null), $what . ': the exclusion is given');
        $c->check(is_string($vectors['model_version'] ?? null), $what . ': the model version is named');
    };

    // ---- where user 1 stands
    $c->as(1)->get('/api/truck/bootstrap')->status(200)->path('data.has_truck', true);
    $modelVersion = $c->value('data.model_version');
    $seedsRevision = $c->value('data.seeds_revision');
    $profile = $c->value('data.truck.profile');
    $regionId = (string) $c->value('data.truck.profile.region_id');
    $region = $c->value('data.region');
    $withRegion = !$state['no_region'] && is_array($region) && ($region['usable'] ?? false) === true;
    $dataset = $withRegion ? (string) $region['dataset_version'] : null;
    $c->check($c->value('data.calibration.truck_factor') == 1, 'a truck without logged services has the truck factor 1');
    $c->check($c->value('data.assumptions.overrides') === [], 'step 20 leaves user 1 without overrides');

    // =====================================================================================
    // 1. simulate
    // =====================================================================================

    // A point with nothing in reach, in any region: zero vectors, an empty week, never an error.
    $nowhere = ['lat' => 0.5, 'lng' => 0.5];
    $c->post('/api/truck/simulate', ['point' => $nowhere, 'visibilities' => $levels])->status(200)
        ->path('success', true)
        ->path('data.located.in_region', false)
        ->path('data.located.county_fips', null)
        ->path('data.host', null)
        ->path('data.outlets', [])
        ->path('data.outlets_total', 0)
        ->path('data.hosts_nearby', [])
        ->path('data.estimate.week_strip', array_fill(0, 168, 0))
        ->path('data.estimate.best_windows', [])
        ->path('data.estimate.typical', null)
        ->path('data.estimate.dated', null)
        ->path('data.calibration', ['truck_factor' => 1, 'spot_factor' => 1])
        ->path('data.model_version', $modelVersion)
        ->path('data.seeds_revision', $seedsRevision);
    $c->check(array_keys((array) $c->value('data.vectors')) === $levels, 'one vector set per requested visibility, in the order asked');
    foreach ($levels as $level) {
        $checkVectors($c, $c->value('data.vectors.' . $level), $level, 'simulate');
        $c->path('data.vectors.' . $level . '.capture.day', $zeros)
            ->path('data.vectors.' . $level . '.capture.eve', $zeros)
            ->path('data.vectors.' . $level . '.nearby', $zeros)
            ->path('data.vectors.' . $level . '.rivals', ['day' => 0, 'eve' => 0])
            ->path('data.vectors.' . $level . '.points_used', 0)
            ->path('data.vectors.' . $level . '.in_region', false);
    }
    $attribution = $c->value('data.attribution');
    $c->check(is_array($attribution) && count($attribution) >= 2, 'the answer names its sources');
    foreach ((array) $attribution as $line) {
        $c->check(is_string($line) && $line !== '' && !str_contains($line, '{'), 'a source line is a finished sentence');
    }
    $c->check(str_contains((string) $attribution[0], 'OpenStreetMap contributors'), 'places are credited to OpenStreetMap');

    // Without `visibilities` the answer holds the visibility of the terms, else normal.
    $c->post('/api/truck/simulate', ['point' => $nowhere])->status(200);
    $c->check(array_keys((array) $c->value('data.vectors')) === ['normal'], 'normal is the default visibility');
    $c->post('/api/truck/simulate', ['point' => $nowhere, 'terms' => ['visibility' => 'hidden', 'fee_pct' => 0.1]])->status(200);
    $c->check(array_keys((array) $c->value('data.vectors')) === ['hidden'], 'the visibility of the terms is the default');

    // A described host needs no place. Its people come through the host term, also where nobody lives.
    $c->post('/api/truck/simulate', [
        'point' => $nowhere,
        'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120, 'only_food' => true]],
    ])->status(200)
        ->path('data.host.segment', 'v_nightlife')
        ->path('data.host.size', 120)
        ->path('data.host.size_source', 'owner')
        ->path('data.host.only_food', true)
        ->path('data.host.point_id', null)
        ->path('data.host.place_type', null)
        ->path('data.estimate.typical.dow', 5)
        ->path('data.estimate.typical.open_minute', 1020)
        ->path('data.estimate.typical.close_minute', 1200)
        ->path('data.estimate.typical.window.orders.confidence', 'rough')
        ->finite('data.estimate.week_strip.*', 'data.estimate.typical.window.orders.value', 'data.estimate.typical.money.contribution.value');
    $orders = $c->value('data.estimate.typical.window.orders');
    $c->check($close($orders['value'], 64.548), 'a taproom of 120 with the truck as its only food: 64.548 orders on Saturday 17:00 to 20:00');
    $c->check($orders['low'] < $orders['value'] && $orders['value'] < $orders['high'], 'the estimate is a range around its value');
    $c->check(count((array) $c->value('data.estimate.typical.window.hours')) === 3, 'the window carries its hours');
    $c->check(count((array) $c->value('data.estimate.best_windows')) === 3, 'three best windows');

    // A date adds that day's window and its context.
    $c->post('/api/truck/simulate', [
        'point' => $nowhere,
        'terms' => ['host' => ['segment' => 'v_nightlife', 'size' => 120, 'only_food' => true]],
        'date' => '2026-10-08',
        'open_minute' => 1020,
        'close_minute' => 1200,
    ])->status(200)
        ->path('data.estimate.dated.date', '2026-10-08')
        ->path('data.estimate.dated.context.date', '2026-10-08')
        ->path('data.estimate.dated.context.dow', 3)
        ->path('data.estimate.dated.context.typical', false)
        ->path('data.estimate.dated.window.open_minute', 1020)
        ->path('data.estimate.dated.window.close_minute', 1200)
        ->finite('data.estimate.dated.window.orders.value', 'data.estimate.dated.window.orders.low', 'data.estimate.dated.window.orders.high', 'data.estimate.dated.money.contribution.value');
    $c->check(is_string($c->value('data.estimate.dated.window.orders.confidence')), 'the dated estimate has its confidence label');
    $c->check($c->value('data.estimate.dated.window.orders.value') > 0, 'a Thursday evening at a taproom has orders');

    // One refusal for each message a simulate body can raise.
    $refused($c->post('/api/truck/simulate', []), 'point is required', 'point', 'V1');
    $refused($c->post('/api/truck/simulate', ['point' => $nowhere, 'terms' => ['fee_pct' => 1.5]]), 'terms.fee_pct must be a number between 0 and 1', 'terms.fee_pct', 'V2');
    $refused(
        $c->post('/api/truck/simulate', ['point' => $nowhere, 'date' => '2026-10-08', 'open_minute' => 60.5, 'close_minute' => 120]),
        'open_minute must be a whole number between 0 and 2880',
        'open_minute',
        'V3'
    );
    $refused($c->post('/api/truck/simulate', ['point' => $nowhere, 'visibilities' => ['bright']]), 'visibilities[0] must be one of: hidden, normal, prominent', 'visibilities[0]', 'V4');
    $refused($c->post('/api/truck/simulate', ['point' => $nowhere, 'spot_id' => 12]), 'spot_id must be text of at most 36 characters', 'spot_id', 'V5');
    $refused(
        $c->post('/api/truck/simulate', ['point' => $nowhere, 'terms' => ['host' => ['segment' => 'w_office', 'size' => 100, 'only_food' => 'yes']]]),
        'terms.host.only_food must be true or false',
        'terms.host.only_food',
        'V6'
    );
    $refused(
        $c->post('/api/truck/simulate', ['point' => $nowhere, 'date' => '08/10/2026', 'open_minute' => 660, 'close_minute' => 840]),
        'date must be a date in the form YYYY-MM-DD',
        'date',
        'V7'
    );
    $refused($c->post('/api/truck/simulate', ['point' => $nowhere, 'visibilities' => []]), 'visibilities must be a list of 1 to 3 items', 'visibilities', 'V8');
    $refused($c->post('/api/truck/simulate', ['point' => $nowhere, 'terms' => 'none']), 'terms must be an object', 'terms', 'V9');
    $refused(
        $c->post('/api/truck/simulate', ['point' => ['lat' => 95, 'lng' => 0]]),
        'point must have lat between -90 and 90 and lng between -180 and 180',
        'point',
        'V10'
    );
    $refused(
        $c->post('/api/truck/simulate', ['point' => $nowhere, 'spot_id' => '00000000-0000-4000-8000-000000000000']),
        'spot_id was not found',
        'spot_id',
        'V11'
    );
    $refused(
        $c->post('/api/truck/simulate', ['point' => $nowhere, 'terms' => ['host' => ['place_key' => 'n0']]]),
        'terms.host.place_key was not found',
        'terms.host.place_key',
        'V11'
    );
    $refused($c->post('/api/truck/simulate', ['point' => $nowhere, 'terms' => ['host' => ['size' => 100]]]), 'terms.host.segment is required', 'terms.host.segment', 'V1');
    $refused(
        $c->post('/api/truck/simulate', ['point' => $nowhere, 'terms' => ['host' => ['segment' => 'w_office']]]),
        'terms.host.size is required for this kind of place',
        'terms.host.size'
    );
    $refused($c->post('/api/truck/simulate', ['point' => $nowhere, 'date' => '2026-10-08']), 'date, open_minute and close_minute must be given together');
    $refused(
        $c->post('/api/truck/simulate', ['point' => $nowhere, 'date' => '2026-10-08', 'open_minute' => 840, 'close_minute' => 660]),
        'close_minute must be after open_minute',
        'close_minute'
    );
    $refused($c->sendRaw('POST', '/api/truck/simulate', '[1, 2]'), 'Request body must be a JSON object');

    // =====================================================================================
    // 4a. with a usable region: people, outlets and the model's own week at the truck's base
    // =====================================================================================
    $atBase = null;
    if ($withRegion) {
        $c->post('/api/truck/simulate', ['point' => $base, 'visibilities' => $levels])->status(200)
            ->path('data.located.region_id', $regionId)
            ->path('data.dataset_version', $dataset)
            ->path('data.host', null)
            ->finite('data.estimate.week_strip.*', 'data.outlets.*.distance_m', 'data.outlets_total');
        $atBase = $c->value('data');
        $c->check(array_keys($atBase['vectors']) === $levels, 'one vector set per visibility at the base');
        foreach ($levels as $level) {
            $checkVectors($c, $atBase['vectors'][$level], $level, 'simulate at the base');
            $c->path('data.vectors.' . $level . '.dataset_version', $dataset)->path('data.vectors.' . $level . '.region_id', $regionId);
        }
        $c->check($atBase['vectors']['normal']['points_used'] > 0, 'the centre of a loaded region has source points in reach');
        $c->check(array_sum($atBase['vectors']['normal']['nearby']) > 0, 'and people among them');
        $c->check(count($atBase['vectors']['normal']['within'] ?? []) === 16, 'simulate also tells who is within reach, unweighted');
        $c->check(
            array_sum($atBase['vectors']['hidden']['capture']['day']) < array_sum($atBase['vectors']['normal']['capture']['day'])
            && array_sum($atBase['vectors']['normal']['capture']['day']) < array_sum($atBase['vectors']['prominent']['capture']['day']),
            'a truck that is easier to see captures more'
        );

        // outlets: nearest first, at most 60 of the total. (The order is decided on the exact distance; the
        // distance shown is rounded to a tenth of a metre, so equal shown distances say nothing about keys.)
        $outlets = $atBase['outlets'];
        $c->check(count($outlets) <= 60 && $atBase['outlets_total'] >= count($outlets), 'at most 60 outlets, and the total before the cut');
        for ($i = 1; $i < count($outlets); $i++) {
            $c->check($outlets[$i - 1]['distance_m'] <= $outlets[$i]['distance_m'], 'outlets come nearest first');
        }
        $c->check(count(array_unique(array_column($outlets, 'place_key'))) === count($outlets), 'each outlet once');
        foreach ($outlets as $outlet) {
            $c->check($outlet['distance_m'] <= 1200.1 && is_string($outlet['rival_kind']), 'an outlet is a food rival within walking reach');
        }
        $c->check(count($atBase['hosts_nearby']) <= 10, 'at most ten possible hosts');
        $c->check(count($atBase['attribution']) === 3 && str_starts_with($atBase['attribution'][2], 'Jobs: '), 'with a dataset the jobs source is named too');

        // The estimate is the model on these vectors, with the first requested visibility.
        $terms = ['spot_id' => null, 'visibility' => 'hidden', 'host' => null, 'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null];
        $week = Estimator::weekStrip(Seeds::defaults(), $profile, $terms, $atBase['vectors']['hidden'], null);
        $c->check(count($atBase['estimate']['week_strip']) === 168, 'a week has 168 hours');
        foreach ($week as $how => $expected) {
            $c->check($close($atBase['estimate']['week_strip'][$how], $expected), 'hour ' . $how . ' of the week is the model\'s on the answered vectors');
        }
        $best = Estimator::bestWindows($week, 3, 3, true);
        $c->check(count($atBase['estimate']['best_windows']) === count($best), 'the best windows are the model\'s');
        foreach ($best as $i => $window) {
            $c->check(
                $atBase['estimate']['best_windows'][$i]['start'] === $window['start'] && $close($atBase['estimate']['best_windows'][$i]['total'], $window['total']),
                'best window ' . ($i + 1) . ' is the model\'s'
            );
        }
        if ($best !== []) {
            $typical = $atBase['estimate']['typical'];
            $c->check($typical['dow'] * 1440 + $typical['open_minute'] === $best[0]['start'] * 60, 'the typical window is the best one');
            $c->check($close($typical['window']['orders']['value'], $best[0]['total']), 'and its orders are that window\'s total');
            $c->check(
                $typical['window']['orders']['low'] <= $typical['window']['orders']['value']
                && $typical['window']['orders']['value'] <= $typical['window']['orders']['high'],
                'a range around the value'
            );
            $c->check(in_array($typical['window']['orders']['confidence'], ['very_rough', 'rough', 'fair', 'good'], true), 'with a confidence label');
            $c->check(count($typical['window']['hours'][0]['result']['segments']) === 16, 'and the breakdown by segment behind it');
        }
    }

    // =====================================================================================
    // 2. spots
    // =====================================================================================
    $c->get('/api/truck/spots')->status(200)->path('data.spots', []);

    $c->post('/api/truck/spots', [
        'name' => '  Smoke spot  ',
        'point' => $base,
        'terms' => ['visibility' => 'prominent', 'fee_pct' => 0.1, 'fee_min' => 75],
    ])->status(201)
        ->path('success', true)
        ->path('message', 'Spot saved')
        ->path('data.spot.name', 'Smoke spot')
        ->path('data.spot.point', $base)
        ->path('data.spot.address', '')
        ->path('data.spot.notes', null)
        ->path('data.spot.host_details', null)
        ->path('data.spot.vectors_state', 'fresh')
        ->path('data.spot.logs', ['count' => 0, 'last_date' => null])
        ->path('data.spot.archived', false)
        ->path('data.spot.maps_url', $mapsLink . sprintf('%.6F', $base['lat']) . '%2C' . sprintf('%.6F', $base['lng']));
    $first = $c->value('data.spot');
    $firstId = (string) $first['id'];
    $c->check(strlen($firstId) === 36, 'the spot has an id');
    $c->path('data.spot.terms', ['spot_id' => $firstId, 'visibility' => 'prominent', 'host' => null, 'fee_flat' => 0, 'fee_pct' => 0.1, 'fee_min' => 75, 'allowed' => null]);
    $c->check(
        array_keys($first) === ['id', 'name', 'point', 'address', 'county_fips', 'notes', 'terms', 'host_details', 'vectors', 'vectors_state', 'logs', 'maps_url', 'archived', 'created_at', 'updated_at'],
        'a spot has the fields of the specification, in its order'
    );
    $c->check(array_keys((array) $first['vectors']) === $levels, 'vectors are stored for the three visibility levels');
    foreach ($levels as $level) {
        $checkVectors($c, $first['vectors'][$level], $level, 'a saved spot');
        $c->path('data.spot.vectors.' . $level . '.within', null);
    }
    if ($atBase !== null) {
        // What was stored is what simulate answered at the same point, number for number.
        foreach ($levels as $level) {
            foreach (['capture', 'nearby', 'rivals', 'in_region', 'region_id', 'exclusion', 'excluded_amount', 'points_used', 'dataset_version'] as $part) {
                $c->path('data.spot.vectors.' . $level . '.' . $part, $atBase['vectors'][$level][$part]);
            }
        }
        $c->path('data.spot.county_fips', $atBase['located']['county_fips']);
    }

    $c->get('/api/truck/spots/' . $firstId)->status(200)->path('data.spot', $first);
    $c->get('/api/truck/spots')->status(200)->path('data.spots', [$first]);
    $c->get('/api/truck/bootstrap')->status(200)->path('data.counts.spots', 1);

    // Changes that leave the vectors alone: every key the body carries, and no other.
    $c->put('/api/truck/spots/' . $firstId, [
        'name' => 'Smoke spot, renamed',
        'address' => '1 Example Street',
        'notes' => 'By the loading dock',
        'terms' => [
            'visibility' => 'hidden',
            'fee_flat' => 12.345,
            'allowed' => ['days' => [true, true, true, true, true, false, false], 'open_minute' => 660, 'close_minute' => 840],
        ],
        'host_details' => ['name' => 'Example Plaza', 'phone' => '703-555-0100'],
    ])->status(200)
        ->path('data.spot.id', $firstId)
        ->path('data.spot.name', 'Smoke spot, renamed')
        ->path('data.spot.address', '1 Example Street')
        ->path('data.spot.notes', 'By the loading dock')
        ->path('data.spot.terms.visibility', 'hidden')
        ->path('data.spot.terms.fee_flat', 12.35)
        ->path('data.spot.terms.fee_pct', 0.1)
        ->path('data.spot.terms.fee_min', 75)
        ->path('data.spot.terms.allowed', ['days' => [true, true, true, true, true, false, false], 'open_minute' => 660, 'close_minute' => 840])
        ->path('data.spot.host_details', ['place_type' => null, 'name' => 'Example Plaza', 'contact' => null, 'phone' => '703-555-0100', 'website' => null, 'place_key' => null, 'google_place_id' => null])
        ->path('data.spot.vectors', $first['vectors'])
        ->path('data.spot.vectors_state', 'fresh')
        ->path('data.spot.point', $base)
        ->path('data.spot.created_at', $first['created_at']);
    $c->put('/api/truck/spots/' . $firstId, ['notes' => null, 'terms' => ['allowed' => null, 'fee_flat' => 0], 'host_details' => ['name' => null, 'phone' => null]])->status(200)
        ->path('data.spot.notes', null)
        ->path('data.spot.terms.allowed', null)
        ->path('data.spot.terms.fee_flat', 0)
        ->path('data.spot.terms.fee_min', 75)
        ->path('data.spot.host_details', null)
        ->path('data.spot.name', 'Smoke spot, renamed');

    // A change that carries nothing, and changes that are refused, change nothing.
    $refused($c->put('/api/truck/spots/' . $firstId, []), 'Nothing to update', null, 'V12');
    $refused($c->put('/api/truck/spots/' . $firstId, ['colour' => 'red']), 'Nothing to update', null, 'V12');
    $refused($c->put('/api/truck/spots/' . $firstId, ['name' => str_repeat('n', 121)]), 'name must be text of at most 120 characters', 'name', 'V5');
    $refused($c->put('/api/truck/spots/' . $firstId, ['name' => 'Not saved', 'terms' => ['fee_min' => -1]]), 'terms.fee_min must be a number between 0 and 100000', 'terms.fee_min', 'V2');
    $refused(
        $c->put('/api/truck/spots/' . $firstId, ['terms' => ['allowed' => ['days' => [true, true, true, true, true, true], 'open_minute' => 0, 'close_minute' => 60]]]),
        'terms.allowed.days must be a list of 7 to 7 items',
        'terms.allowed.days',
        'V8'
    );
    $refused(
        $c->put('/api/truck/spots/' . $firstId, ['terms' => ['allowed' => ['days' => array_fill(0, 7, true), 'open_minute' => 840, 'close_minute' => 660]]]),
        'terms.allowed.close_minute must be after open_minute',
        'terms.allowed.close_minute'
    );
    $refused($c->post('/api/truck/spots', ['point' => $base]), 'name is required', 'name', 'V1');
    $refused($c->post('/api/truck/spots', ['name' => 'No point']), 'point is required', 'point', 'V1');
    $refused($c->post('/api/truck/spots', ['name' => 'x', 'point' => $base, 'terms' => ['visibility' => 'loud']]), 'terms.visibility must be one of: hidden, normal, prominent', 'terms.visibility', 'V4');
    $refused($c->get('/api/truck/spots', ['archived' => 'yes']), 'archived must be true or false', 'archived', 'V6');
    $c->get('/api/truck/spots/' . $firstId)->status(200)->path('data.spot.name', 'Smoke spot, renamed')->path('data.spot.terms.fee_min', 75);
    $c->get('/api/truck/bootstrap')->status(200)->path('data.counts.spots', 1);

    // A host of workers: its people are taken out of the blocks around the truck, the vectors are computed again.
    $c->put('/api/truck/spots/' . $firstId, ['terms' => ['visibility' => 'prominent', 'host' => ['segment' => 'w_office', 'size' => 600, 'only_food' => true]]])->status(200)
        ->path('data.spot.terms.host', ['segment' => 'w_office', 'size' => 600, 'size_source' => 'owner', 'only_food' => true, 'point_id' => null, 'place_type' => null])
        ->path('data.spot.vectors_state', 'fresh');
    foreach ($levels as $level) {
        $c->path('data.spot.vectors.' . $level . '.exclusion', ['point_ids' => [], 'segment' => 'w_office', 'amount' => 600]);
    }
    $taken = $c->value('data.spot.vectors.normal.excluded_amount');
    $c->check((is_int($taken) || is_float($taken)) && $taken >= 0 && $taken <= 600, 'at most the declared 600 workers are taken out');
    if ($atBase !== null) {
        $c->check(
            $close($c->value('data.spot.vectors.normal.nearby.1') + 0.0, $atBase['vectors']['normal']['nearby'][1] + 0.0) === ($taken == 0),
            'the office workers in reach go down exactly when some were taken out'
        );
    }
    // Only-food is not what the vectors are computed from; removing the host is.
    $withHost = $c->value('data.spot.vectors');
    $c->put('/api/truck/spots/' . $firstId, ['terms' => ['host' => ['segment' => 'w_office', 'size' => 600, 'only_food' => false]]])->status(200)
        ->path('data.spot.terms.host.only_food', false)
        ->path('data.spot.vectors', $withHost);
    $c->put('/api/truck/spots/' . $firstId, ['terms' => ['host' => null]])->status(200)
        ->path('data.spot.terms.host', null)
        ->path('data.spot.vectors', $first['vectors']);

    // A moved pin is computed again where it now stands.
    $moved = $north($base, 400.0);
    $c->put('/api/truck/spots/' . $firstId, ['point' => $moved])->status(200)
        ->path('data.spot.point', $moved)
        ->path('data.spot.vectors_state', 'fresh');
    $c->check(str_starts_with((string) $c->value('data.spot.maps_url'), $mapsLink), 'the map link follows the pin');
    if ($withRegion) {
        $c->check($c->value('data.spot.vectors') !== $first['vectors'], '400 m further north the vectors are other ones');
        $there = $c->value('data.spot.vectors');
        $c->post('/api/truck/simulate', ['point' => $moved, 'visibilities' => $levels])->status(200);
        foreach ($levels as $level) {
            $c->path('data.vectors.' . $level . '.capture', $there[$level]['capture'])->path('data.vectors.' . $level . '.nearby', $there[$level]['nearby']);
        }
    }
    // A short move, which the owner's drive-time corrections follow, is computed again just the same.
    $nudged = $north($base, 30.0);
    $c->put('/api/truck/spots/' . $firstId, ['point' => $nudged])->status(200)
        ->path('data.spot.point', $nudged)
        ->path('data.spot.vectors_state', 'fresh');
    $c->put('/api/truck/spots/' . $firstId, ['point' => $base, 'name' => 'Smoke spot', 'address' => '', 'terms' => ['fee_pct' => 0, 'fee_min' => 0, 'visibility' => 'normal']])->status(200)
        ->path('data.spot.vectors', $first['vectors'])
        ->path('data.spot.terms', ['spot_id' => $firstId, 'visibility' => 'normal', 'host' => null, 'fee_flat' => 0, 'fee_pct' => 0, 'fee_min' => 0, 'allowed' => null]);

    // The spot's calibration factor is asked for by its id.
    $c->post('/api/truck/simulate', ['point' => $base, 'spot_id' => $firstId])->status(200)
        ->path('data.calibration', ['truck_factor' => 1, 'spot_factor' => 1]);

    // Refresh: one spot whatever its state, then the sweep, which finds nothing to do.
    $c->post('/api/truck/spots/' . $firstId . '/refresh')->status(200)
        ->path('data.spot.id', $firstId)
        ->path('data.spot.vectors', $first['vectors'])
        ->path('data.spot.vectors_state', 'fresh');
    $c->post('/api/truck/spots/' . $firstId . '/refresh', [])->status(200)->path('data.spot.vectors_state', 'fresh');
    $c->post('/api/truck/spots/refresh')->status(200)->path('data', ['refreshed' => 0, 'remaining' => 0]);
    $c->post('/api/truck/spots/refresh', [])->status(200)->path('data', ['refreshed' => 0, 'remaining' => 0]);

    // A second spot and one to archive.
    $c->post('/api/truck/spots', ['name' => 'Smoke spot north', 'point' => $moved])->status(201)->path('data.spot.terms.visibility', 'normal');
    $secondId = (string) $c->value('data.spot.id');
    $c->post('/api/truck/spots', ['name' => 'A spot to archive', 'point' => $north($base, -200.0), 'notes' => 'gone soon'])->status(201);
    $archived = $c->value('data.spot');
    $archivedId = (string) $archived['id'];
    $c->get('/api/truck/spots')->status(200);
    $c->check(array_column((array) $c->value('data.spots'), 'name') === ['A spot to archive', 'Smoke spot', 'Smoke spot north'], 'the list is ordered by name');

    // Archive: the row stays and is still read by its id; the list leaves it out unless asked.
    $c->delete('/api/truck/spots/' . $archivedId)->status(200)->path('data', ['id' => $archivedId, 'archived' => true]);
    $c->get('/api/truck/spots')->status(200);
    $c->check(array_column((array) $c->value('data.spots'), 'id') === [$firstId, $secondId], 'an archived spot leaves the list');
    $c->get('/api/truck/spots', ['archived' => 1])->status(200);
    $c->check(array_column((array) $c->value('data.spots'), 'id') === [$archivedId, $firstId, $secondId], 'and is listed again on request');
    $c->check(array_column((array) $c->value('data.spots'), 'archived') === [true, false, false], 'marked as archived');
    $c->get('/api/truck/spots', ['archived' => 0])->status(200);
    $c->check(count((array) $c->value('data.spots')) === 2, 'archived=0 is the plain list');
    $c->get('/api/truck/spots/' . $archivedId)->status(200)
        ->path('data.spot.archived', true)
        ->path('data.spot.notes', 'gone soon')
        ->path('data.spot.vectors', $archived['vectors']);
    $c->delete('/api/truck/spots/' . $archivedId)->status(200)->path('data', ['id' => $archivedId, 'archived' => true]);
    $c->get('/api/truck/bootstrap')->status(200)->path('data.counts.spots', 2);

    // An id that is not a spot.
    $missing = '00000000-0000-4000-8000-000000000000';
    $c->get('/api/truck/spots/' . $missing)->status(404)->path('success', false)->path('error', 'Spot not found');
    $c->put('/api/truck/spots/' . $missing, ['name' => 'x'])->status(404)->path('error', 'Spot not found');
    $c->delete('/api/truck/spots/' . $missing)->status(404)->path('error', 'Spot not found');
    $c->post('/api/truck/spots/' . $missing . '/refresh')->status(404)->path('error', 'Spot not found');

    // =====================================================================================
    // 3. the second user
    // =====================================================================================
    $c->as(2)->get('/api/truck/spots')->status(200)->path('data.spots', []);
    $c->get('/api/truck/spots', ['archived' => 1])->status(200)->path('data.spots', []);
    $c->get('/api/truck/spots/' . $firstId)->status(404)->path('error', 'Spot not found');
    $c->put('/api/truck/spots/' . $firstId, ['name' => 'Taken over', 'point' => $nowhere])->status(404)->path('error', 'Spot not found');
    $c->delete('/api/truck/spots/' . $firstId)->status(404)->path('error', 'Spot not found');
    $c->post('/api/truck/spots/' . $firstId . '/refresh')->status(404)->path('error', 'Spot not found');
    $refused($c->post('/api/truck/simulate', ['point' => $nowhere, 'spot_id' => $firstId]), 'spot_id was not found', 'spot_id', 'V11');
    $c->post('/api/truck/spots/refresh')->status(200)->path('data', ['refreshed' => 0, 'remaining' => 0]);
    // A spot of its own, named like the first user's: two tenants, two rows.
    $c->post('/api/truck/spots', ['name' => 'Smoke spot', 'point' => $nowhere, 'id' => $firstId, 'organization_id' => $state['users'][1]['organization_id']])->status(201);
    $otherId = (string) $c->value('data.spot.id');
    $c->check($otherId !== $firstId, 'an id in a body is not read');
    $c->get('/api/truck/spots')->status(200);
    $c->check(array_column((array) $c->value('data.spots'), 'id') === [$otherId], 'the second user sees its own spot only');

    $c->as(1)->get('/api/truck/spots/' . $otherId)->status(404)->path('error', 'Spot not found');
    $c->get('/api/truck/spots/' . $firstId)->status(200)
        ->path('data.spot.name', 'Smoke spot')
        ->path('data.spot.point', $base)
        ->path('data.spot.archived', false)
        ->path('data.spot.vectors', $first['vectors']);
    $c->get('/api/truck/spots')->status(200);
    $c->check(array_column((array) $c->value('data.spots'), 'id') === [$firstId, $secondId], 'the first user\'s list is as it was');

    // =====================================================================================
    // 4b. with a usable region: stale and fresh, and a host linked to a place
    // =====================================================================================
    if ($withRegion) {
        // The truck leaves its region: what was computed for the region is stale there, and a sweep
        // computes what the new region gives, which is nothing.
        $zone = (string) $setup['timezone'];
        $c->put('/api/truck/profile', ['region_id' => 'none'])->status(200)->path('data.truck.profile.region_id', 'none');
        $c->get('/api/truck/spots')->status(200);
        $c->check(array_column((array) $c->value('data.spots'), 'vectors_state') === ['stale', 'stale'], 'spots of the region are stale once the truck has none');
        $c->path('data.spots.0.vectors', $first['vectors']);
        $c->post('/api/truck/spots/refresh')->status(200)->path('data', ['refreshed' => 2, 'remaining' => 0]);
        $c->get('/api/truck/spots/' . $firstId)->status(200)
            ->path('data.spot.vectors_state', 'fresh')
            ->path('data.spot.vectors.normal.nearby', $zeros)
            ->path('data.spot.vectors.normal.dataset_version', null);
        $c->get('/api/truck/spots/' . $archivedId)->status(200)->path('data.spot.vectors_state', 'stale');

        // Back in the region: stale again, and fresh after a refresh, with the vectors of before.
        $c->put('/api/truck/profile', ['region_id' => $regionId])->status(200)
            ->path('data.truck.profile.region_id', $regionId)
            ->path('data.truck.timezone', $zone);
        $c->get('/api/truck/spots/' . $firstId)->status(200)->path('data.spot.vectors_state', 'stale');
        $c->post('/api/truck/spots/' . $firstId . '/refresh')->status(200)
            ->path('data.spot.vectors_state', 'fresh')
            ->path('data.spot.vectors', $first['vectors']);
        $c->post('/api/truck/spots/refresh')->status(200)->path('data', ['refreshed' => 1, 'remaining' => 0]);
        $c->get('/api/truck/spots')->status(200);
        $c->check(array_column((array) $c->value('data.spots'), 'vectors_state') === ['fresh', 'fresh'], 'every listed spot is fresh again');
        $c->post('/api/truck/spots/' . $archivedId . '/refresh')->status(200)
            ->path('data.spot.vectors_state', 'fresh')
            ->path('data.spot.archived', true)
            ->path('data.spot.vectors', $archived['vectors']);

        // A place near the base that could host the truck and is a visitor source of its own.
        $hint = null;
        foreach ([[0, 0], [0, 400], [0, -400], [400, 0], [-400, 0], [400, 400], [-400, -400], [400, -400], [-400, 400], [0, 800], [0, -800], [800, 0], [-800, 0]] as [$up, $right]) {
            $at = $north($base, (float) $up);
            $at['lng'] += $right / 6371008.8 * 180.0 / 3.141592653589793 / max(0.01, cos($at['lat'] * 3.141592653589793 / 180.0));
            $c->post('/api/truck/simulate', ['point' => $at])->status(200);
            foreach ((array) $c->value('data.hosts_nearby') as $candidate) {
                $c->check(
                    array_keys($candidate) === ['place_key', 'name', 'place_type', 'lat', 'lng', 'distance_m', 'host_segment', 'default_size', 'kitchen', 'point_id'],
                    'a possible host has the fields of the specification'
                );
                if ($hint === null && $candidate['point_id'] !== null && $candidate['host_segment'] !== null && $candidate['default_size'] > 0) {
                    $hint = $candidate;
                }
            }
            if ($hint !== null) {
                break;
            }
        }
        if ($hint === null) {
            tp_out('    30: no visitor place that could host a truck within 1 km of the base, host link checks skipped');
        } else {
            $place = ['lat' => $hint['lat'], 'lng' => $hint['lng']];
            // Linked by its key: segment, size and only-food come from the place, its own point is taken out.
            $c->post('/api/truck/simulate', ['point' => $place, 'visibilities' => $levels, 'terms' => ['host' => ['place_key' => $hint['place_key']]]])->status(200)
                ->path('data.host.segment', $hint['host_segment'])
                ->path('data.host.size', $hint['default_size'])
                ->path('data.host.size_source', 'default')
                ->path('data.host.only_food', $hint['kitchen'] === 'no')
                ->path('data.host.point_id', $hint['point_id'])
                ->path('data.host.place_type', $hint['place_type']);
            $linked = $c->value('data');
            foreach ($levels as $level) {
                $c->path('data.vectors.' . $level . '.exclusion.point_ids', [$hint['point_id']]);
            }
            $c->check($hint['point_id'] === 'p' . $hint['place_key'], 'a place\'s own source point is "p" and its key');

            // Saved the same way, the spot stores the link and exactly those vectors.
            $c->post('/api/truck/spots', ['name' => 'A spot to archive, at a host', 'point' => $place, 'terms' => ['host' => ['place_key' => $hint['place_key']]]])->status(201)
                ->path('data.spot.terms.host', $linked['host'])
                ->path('data.spot.host_details.place_key', $hint['place_key'])
                ->path('data.spot.host_details.place_type', $hint['place_type'])
                ->path('data.spot.vectors_state', 'fresh');
            $hostSpotId = (string) $c->value('data.spot.id');
            foreach ($levels as $level) {
                $c->path('data.spot.vectors.' . $level . '.capture', $linked['vectors'][$level]['capture'])
                    ->path('data.spot.vectors.' . $level . '.nearby', $linked['vectors'][$level]['nearby'])
                    ->path('data.spot.vectors.' . $level . '.exclusion', $linked['vectors'][$level]['exclusion']);
            }

            // Described without a key at the same point: the rule links a place of that segment by itself.
            $c->put('/api/truck/spots/' . $hostSpotId, ['terms' => ['host' => ['segment' => $hint['host_segment'], 'size' => 120, 'only_food' => true]]])->status(200)
                ->path('data.spot.terms.host.size', 120)
                ->path('data.spot.terms.host.size_source', 'owner');
            $pointId = $c->value('data.spot.terms.host.point_id');
            $c->check(is_string($pointId) && str_starts_with($pointId, 'p'), 'a described venue host standing at a venue takes a venue\'s point');
            $c->path('data.spot.vectors.normal.exclusion.point_ids', [$pointId]);
            $c->check($c->value('data.spot.host_details.place_key') === substr((string) $pointId, 1), 'and is linked to that place');

            // An unknown key stays a refusal, and the spot leaves the list again.
            $refused(
                $c->put('/api/truck/spots/' . $hostSpotId, ['terms' => ['host' => ['place_key' => 'n0']]]),
                'terms.host.place_key was not found',
                'terms.host.place_key',
                'V11'
            );
            $c->delete('/api/truck/spots/' . $hostSpotId)->status(200)->path('data.archived', true);
        }
    }

    // ---- what the later steps start from
    $c->get('/api/truck/spots')->status(200);
    $c->check(array_column((array) $c->value('data.spots'), 'id') === [$firstId, $secondId], 'user 1 is left with two spots');
    foreach ((array) $c->value('data.spots') as $spot) {
        $c->check($spot['vectors_state'] === 'fresh' && $spot['terms']['host'] === null && $spot['terms']['fee_pct'] == 0, 'each fresh, without host and without fee');
    }
    $state['spots'] = ['first' => $firstId, 'second' => $secondId, 'archived' => $archivedId, 'point' => $base];
};
