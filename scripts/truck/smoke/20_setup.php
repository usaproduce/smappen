<?php
declare(strict_types=1);

/**
 * Smoke step 20: truck setup. Bootstrap, profile and assumptions (routes 1 to 6).
 *
 *   1. Before a truck exists the bootstrap and the profile answer without one, and a first save that is
 *      refused (validation messages V1 to V6 and V8 to V11) writes nothing.
 *   2. The first save creates the truck from three fields. Every other field is the default that the
 *      bootstrap answer announced.
 *   3. A later save changes only what it carries (V12 when it carries nothing). Money comes back as it
 *      was stored, to the cent.
 *   4. Assumptions: changes are merged, a number of 17 digits reads back as the same double, every error
 *      code of the model has its 422 and stores nothing, null and reset take paths out, and an empty map
 *      is sent as {}.
 *   5. The second user neither sees nor changes the first one's truck, whatever its requests carry.
 *
 * The step leaves both users with a truck. User 1's is the plain default truck "Smoke & Ember" (average
 * ticket 15, no overrides). When the run has a usable region and is not --no-region, the truck is based
 * at the centre of the first such region and belongs to it. Otherwise it is based at Sterling, VA.
 *
 * For the later steps:
 *
 *     $state['setup'] = ['truck_id' => [1 => id, 2 => id], 'region_id' => string, 'timezone' => string,
 *                        'base' => ['lat' => float, 'lng' => float]]          (region, zone and base of user 1)
 */
return function (SmokeClient $c, array &$state): void {
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
    // Where a new truck lands by default: the first region (ascending id) whose box holds the base.
    $regionOf = static function (array $regions, array $point): ?array {
        foreach ($regions as $region) {
            $box = $region['bbox'];
            if ($point['lat'] >= $box['lat_min'] && $point['lat'] <= $box['lat_max']
                && $point['lng'] >= $box['lng_min'] && $point['lng'] <= $box['lng_max']) {
                return $region;
            }
        }
        return null;
    };

    // ---- 1. before a truck exists
    $c->as(1)->get('/api/truck/bootstrap')->status(200)
        ->path('data.has_truck', false)
        ->path('data.truck', null)
        ->path('data.region', null)
        ->path('data.fuel', null)
        ->path('data.timezone', null)
        ->path('data.today', null)
        ->path('data.now_minute', null)
        ->path('data.assumptions.region.id', 'none')
        ->path('data.assumptions.region.traffic_matrix', 'us_mean')
        ->path('data.counts', ['spots' => 0, 'plans' => 0, 'services' => 0, 'leads' => 0])
        ->path('data.calibration.truck_factor', 1)
        ->path('data.calibration.truck_n', 0)
        ->finite('data.seeds_revision', 'data.limits.*', 'data.profile_defaults.avg_ticket');
    $modelVersion = $c->value('data.model_version');
    $seedsRevision = $c->value('data.seeds_revision');
    $c->check(is_string($modelVersion) && $modelVersion !== '', 'the model version is named');
    $c->path('data.assumptions.model_version', $modelVersion)->path('data.assumptions.seeds_revision', $seedsRevision);
    $c->check(str_contains($c->body(), '"overrides":{}'), 'an empty override map is sent as {}');
    $c->check(str_contains($c->body(), '"spots":{}'), 'an empty map of spot factors is sent as {}');
    $c->check(count((array) $c->value('data.limits')) === 7, 'the answer carries the seven limits');
    $c->check(in_array($c->value('data.routing.state'), ['ok', 'no_key', 'refused', 'backoff'], true), 'routing.state is one of its four values');
    $regions = $c->value('data.regions');
    $c->check(is_array($regions) && array_is_list($regions), 'regions is a list');
    $defaults = $c->value('data.profile_defaults');
    $c->check(is_array($defaults) && !isset($defaults['name'], $defaults['base'], $defaults['region_id']), 'the defaults leave out name, base and region');

    $c->get('/api/truck/profile')->status(200)->path('data.truck', null)->path('data.profile_defaults', $defaults);

    // The base of user 1: inside a usable region when the run has one.
    $home = null;
    if (!$state['no_region']) {
        foreach ($regions as $region) {
            if ($region['usable'] === true) {
                $home = $region;
                break;
            }
        }
    }
    $base = $home === null ? ['lat' => 39.003, 'lng' => -77.405] : ['lat' => $home['center']['lat'], 'lng' => $home['center']['lng']];
    $expected = $regionOf($regions, $base);
    $first = ['name' => 'Smoke & Ember', 'base' => $base + ['address' => 'Sterling, VA'], 'avg_ticket' => 15];

    // A first save that is refused, one per validation message a profile can raise.
    $refused($c->put('/api/truck/profile', []), 'name is required', 'name', 'V1');
    $refused($c->put('/api/truck/profile', ['avg_ticket' => 0.5] + $first), 'avg_ticket must be a number between 1 and 200', 'avg_ticket', 'V2');
    $refused($c->put('/api/truck/profile', $first + ['paid_crew' => 1.5]), 'paid_crew must be a whole number between 0 and 12', 'paid_crew', 'V3');
    $refused($c->put('/api/truck/profile', $first + ['fuel_type' => 'electric']), 'fuel_type must be one of: gasoline, diesel', 'fuel_type', 'V4');
    $refused($c->put('/api/truck/profile', ['name' => str_repeat('n', 121)] + $first), 'name must be text of at most 120 characters', 'name', 'V5');
    $refused($c->put('/api/truck/profile', $first + ['tips_include' => 'yes']), 'tips_include must be true or false', 'tips_include', 'V6');
    $refused($c->put('/api/truck/profile', $first + ['licence_counties' => '51107']), 'licence_counties must be a list of 0 to 60 items', 'licence_counties', 'V8');
    $refused($c->put('/api/truck/profile', ['base' => 'Sterling, VA'] + $first), 'base must be an object', 'base', 'V9');
    $refused(
        $c->put('/api/truck/profile', ['base' => ['lat' => 139, 'lng' => -77.405]] + $first),
        'base must have lat between -90 and 90 and lng between -180 and 180',
        'base',
        'V10'
    );
    $refused($c->put('/api/truck/profile', $first + ['region_id' => 'atlantis']), 'region_id was not found', 'region_id', 'V11');
    $refused($c->put('/api/truck/profile', $first + ['timezone' => 'Mars/Olympus']), 'timezone must be an IANA time zone name', 'timezone');
    $refused($c->put('/api/truck/profile', $first + ['licence_counties' => ['5110']]), 'licence_counties[0] must be a 5-digit county code', 'licence_counties[0]');
    $refused($c->sendRaw('PUT', '/api/truck/profile', '["not", "an", "object"]'), 'Request body must be a JSON object');
    $refused($c->sendRaw('PUT', '/api/truck/profile', '{"name": '), 'Request body must be a JSON object');
    $c->get('/api/truck/bootstrap')->status(200)->path('data.has_truck', false);

    // ---- 2. the first save
    $c->put('/api/truck/profile', $first)->status(201)
        ->path('success', true)
        ->path('message', 'Truck created')
        ->path('data.truck.profile.name', 'Smoke & Ember')
        ->path('data.truck.profile.base', $base + ['address' => 'Sterling, VA'])
        ->path('data.truck.profile.avg_ticket', 15)
        ->path('data.truck.profile.region_id', $expected === null ? 'none' : $expected['region_id'])
        ->finite('data.fuel.price_per_gal');
    foreach ($defaults as $field => $value) {
        if ($field !== 'avg_ticket') {
            $c->path('data.truck.profile.' . $field, $value);
        }
    }
    $c->check(count((array) $c->value('data.truck.profile')) === count($defaults) + 3, 'the profile is name, region, base and the defaulted fields');
    $truckId = $c->value('data.truck.id');
    $zone = $c->value('data.truck.timezone');
    $warnings = $c->value('data.warnings');
    $c->check(is_string($truckId) && strlen($truckId) === 36, 'the truck has an id');
    $c->check(is_array($warnings) && array_is_list($warnings), 'warnings is a list');
    $c->check(in_array($c->value('data.fuel.source'), ['owner', 'eia', 'seed'], true), 'the fuel price names its source');
    if ($expected === null) {
        // No region: the zone is assumed, and the answer says so.
        $c->path('data.region', null)->path('data.truck.timezone', 'America/New_York');
        $c->check(in_array('timezone_assumed', $warnings, true), 'a truck without a region is told that its time zone was assumed');
    } else {
        $c->path('data.region.region_id', $expected['region_id'])->path('data.truck.timezone', $expected['timezone']);
        $c->check(!in_array('timezone_assumed', $warnings, true), 'a truck in a region has the region\'s time zone');
    }
    $created = $c->value('data.truck');

    $c->get('/api/truck/profile')->status(200)->path('data.truck', $created)->path('data.profile_defaults', $defaults);

    // ---- 3. later saves
    $c->put('/api/truck/profile', ['wage_per_hour' => 17.29, 'daypart_fit' => ['late' => 0.5], 'paid_crew' => 3])->status(200)
        ->path('data.truck.id', $truckId)
        ->path('data.truck.profile.wage_per_hour', 17.29)
        ->path('data.truck.profile.paid_crew', 3)
        ->path('data.truck.profile.daypart_fit', ['late' => 0.5] + $defaults['daypart_fit'])
        ->path('data.truck.profile.name', 'Smoke & Ember')
        ->path('data.truck.profile.avg_ticket', 15)
        ->path('data.truck.profile.base', $base + ['address' => 'Sterling, VA'])
        ->path('data.truck.profile.region_id', $created['profile']['region_id'])
        ->path('data.truck.timezone', $zone)
        ->path('data.truck.created_at', $created['created_at']);
    $c->check(!array_key_exists('message', (array) $c->json()) || $c->value('message') !== 'Truck created', 'a later save creates nothing');

    // Dollars in, whole cents in the table, the same dollars out.
    $money = [
        'avg_ticket' => 12.34, 'wage_per_hour' => 19.99, 'packaging_per_order' => 0.29, 'card_fee_fixed' => 0.07,
        'fixed_cost_per_service_day' => 1.15, 'fuel_price_override' => 4.195,
    ];
    $c->put('/api/truck/profile', $money)->status(200)
        ->path('data.fuel.source', 'owner')
        ->path('data.fuel.price_per_gal', 4.195);
    foreach ($money as $field => $value) {
        $c->path('data.truck.profile.' . $field, $value);
    }
    $c->get('/api/truck/profile')->status(200);
    foreach ($money as $field => $value) {
        $c->path('data.truck.profile.' . $field, $value);
    }
    foreach ([1.0, 1.01, 4.35, 9.99, 10.1, 19.99, 33.33, 64.1, 100.07, 199.99, 200.0] as $dollars) {
        $c->put('/api/truck/profile', ['avg_ticket' => $dollars])->status(200)->path('data.truck.profile.avg_ticket', $dollars);
    }
    // An amount finer than a cent is rounded, and the answer shows what was stored.
    $c->put('/api/truck/profile', ['avg_ticket' => 12.345])->status(200)->path('data.truck.profile.avg_ticket', 12.35);
    $c->put('/api/truck/profile', ['fuel_price_override' => null])->status(200)->path('data.truck.profile.fuel_price_override', null);
    $c->check($c->value('data.fuel.source') !== 'owner', 'without a price of the owner the fuel price has another source');

    // A save that carries nothing, and saves that are refused, change nothing.
    $refused($c->put('/api/truck/profile', ['colour' => 'red']), 'Nothing to update', null, 'V12');
    $refused($c->put('/api/truck/profile', []), 'Nothing to update', null, 'V12');
    $refused(
        $c->put('/api/truck/profile', ['base' => ['lat' => 39.1]]),
        'base must have lat between -90 and 90 and lng between -180 and 180',
        'base',
        'V10'
    );
    $refused($c->put('/api/truck/profile', ['base' => ['state' => 'C1']]), 'base.state must be a two-letter state code', 'base.state');
    $refused($c->put('/api/truck/profile', ['daypart_fit' => ['lunch' => 1.5]]), 'daypart_fit.lunch must be a number between 0 and 1', 'daypart_fit.lunch', 'V2');
    $refused($c->put('/api/truck/profile', ['region_id' => 'atlantis', 'name' => 'Not saved']), 'region_id was not found', 'region_id', 'V11');
    $c->get('/api/truck/profile')->status(200)->path('data.truck.profile.name', 'Smoke & Ember')->path('data.truck.profile.avg_ticket', 12.35);

    // Licence counties belong to the truck's region. A truck without a region takes any code.
    $county = $expected['counties'][0]['fips'] ?? null;
    if ($expected === null) {
        $c->put('/api/truck/profile', ['licence_counties' => ['51107', '51107', '11001']])->status(200)
            ->path('data.truck.profile.licence_counties', ['51107', '11001']);
    } elseif ($county !== null) {
        $c->put('/api/truck/profile', ['licence_counties' => [$county, $county]])->status(200)
            ->path('data.truck.profile.licence_counties', [$county]);
        $refused(
            $c->put('/api/truck/profile', ['licence_counties' => [$county, '00000']]),
            'licence_counties[1] is not a county of this region',
            'licence_counties[1]'
        );
    }

    // The region can be given up and, when it can be used, chosen again. The truck's own region is always taken.
    $c->put('/api/truck/profile', ['region_id' => 'none'])->status(200)
        ->path('data.truck.profile.region_id', 'none')
        ->path('data.region', null)
        ->path('data.truck.timezone', $zone);
    $c->put('/api/truck/profile', ['region_id' => 'none', 'timezone' => 'America/Chicago'])->status(200)
        ->path('data.truck.timezone', 'America/Chicago');
    if ($home !== null) {
        $c->put('/api/truck/profile', ['region_id' => $home['region_id']])->status(200)
            ->path('data.truck.profile.region_id', $home['region_id'])
            ->path('data.region.region_id', $home['region_id'])
            ->path('data.truck.timezone', $home['timezone']);
    }

    // ---- 4. assumptions
    $c->get('/api/truck/assumptions')->status(200)
        ->path('data.assumptions.model_version', $modelVersion)
        ->path('data.assumptions.seeds_revision', $seedsRevision)
        ->path('data.assumptions.region.id', $home === null ? 'none' : $home['region_id']);
    $c->check(str_contains($c->body(), '"overrides":{}'), 'an empty override map is sent as {}');

    // 0.20000010000000001 needs all 17 digits: a store that reprints numbers would hand back 0.2000001.
    $c->put('/api/truck/assumptions', ['overrides' => ['host.captive_share' => 0.6, 'weather.floor' => 0.20000010000000001]])->status(200)
        ->path('data.assumptions.overrides', ['host.captive_share' => 0.6, 'weather.floor' => 0.20000010000000001]);
    $c->check(str_contains($c->body(), '"weather.floor":0.20000010000000001'), 'the long number is sent with every digit');
    $c->get('/api/truck/assumptions')->status(200)
        ->path('data.assumptions.overrides', ['host.captive_share' => 0.6, 'weather.floor' => 0.20000010000000001]);
    $c->check(str_contains($c->body(), '"weather.floor":0.20000010000000001'), 'the long number reads back with every digit');
    $c->check($c->value('data.assumptions.overrides')['weather.floor'] !== 0.2000001, 'the long number is not the short one');

    // One refusal per error code of the model. The sentence names the path and the code, the details list them.
    $invalid = [
        ['no.such.path', 1, 'unknown_path'],
        ['host.captive_share.value', 0.5, 'not_a_seed'],
        ['kernel.outside_option_a0', 2.0, 'not_overridable'],
        ['segments.w_office.presence', ['weekday' => [1]], 'not_a_leaf'],
        ['segments.w_office.presence.weekday', array_fill(0, 23, 0.1), 'wrong_shape'],
        ['events.attendance_haircut', 1.5, 'out_of_bounds'],
        ['segments.res.holiday_day_type.major', 'monday', 'not_allowed'],
    ];
    foreach ($invalid as [$path, $value, $code]) {
        $c->put('/api/truck/assumptions', ['overrides' => [$path => $value]])->status(422)
            ->path('success', false)
            ->path('error', 'overrides.' . $path . ': ' . $code)
            ->path('details', [['path' => $path, 'error' => $code]]);
    }
    // Several at once: the first in path order is named, all are listed, and the valid one is not stored.
    $c->put('/api/truck/assumptions', ['overrides' => ['weather.floor' => 7, 'no.such.path' => 1, 'host.shared_kitchen_share' => 0.4]])->status(422)
        ->path('error', 'overrides.no.such.path: unknown_path')
        ->path('details', [['path' => 'no.such.path', 'error' => 'unknown_path'], ['path' => 'weather.floor', 'error' => 'out_of_bounds']]);
    $refused($c->put('/api/truck/assumptions', []), 'overrides is required', 'overrides', 'V1');
    $refused($c->put('/api/truck/assumptions', ['overrides' => 'none']), 'overrides must be an object', 'overrides', 'V9');
    $tooMany = [];
    for ($i = 0; $i <= 200; $i++) {
        $tooMany['path.' . $i] = 1;
    }
    $refused($c->put('/api/truck/assumptions', ['overrides' => $tooMany]), 'overrides must have at most 200 entries', 'overrides');
    $c->get('/api/truck/assumptions')->status(200)
        ->path('data.assumptions.overrides', ['host.captive_share' => 0.6, 'weather.floor' => 0.20000010000000001]);

    // null takes a path out, and a change map without entries changes nothing.
    $c->put('/api/truck/assumptions', ['overrides' => ['weather.floor' => null, 'events.attendance_haircut' => 0.5]])->status(200)
        ->path('data.assumptions.overrides', ['events.attendance_haircut' => 0.5, 'host.captive_share' => 0.6]);
    $c->put('/api/truck/assumptions', ['overrides' => new stdClass()])->status(200)
        ->path('data.assumptions.overrides', ['events.attendance_haircut' => 0.5, 'host.captive_share' => 0.6]);

    // Reset: named paths (one that is not overridden is ignored), then everything, with and without a body.
    $refused($c->post('/api/truck/assumptions/reset', ['paths' => 'host.captive_share']), 'paths must be a list of 0 to 200 items', 'paths', 'V8');
    $refused($c->post('/api/truck/assumptions/reset', ['paths' => [7]]), 'paths[0] must be text of at most 200 characters', 'paths[0]', 'V5');
    $c->post('/api/truck/assumptions/reset', ['paths' => ['host.captive_share', 'no.such.path', 'weather.floor']])->status(200)
        ->path('data.assumptions.overrides', ['events.attendance_haircut' => 0.5]);
    $c->post('/api/truck/assumptions/reset', [])->status(200);
    $c->check(str_contains($c->body(), '"overrides":{}'), 'after a reset the override map is {}');
    $c->put('/api/truck/assumptions', ['overrides' => ['host.captive_share' => 0.6]])->status(200)
        ->path('data.assumptions.overrides', ['host.captive_share' => 0.6]);
    $c->post('/api/truck/assumptions/reset')->status(200);
    $c->check(str_contains($c->body(), '"overrides":{}'), 'a reset without a body resets everything');
    $c->get('/api/truck/assumptions')->status(200);
    $c->check(str_contains($c->body(), '"overrides":{}'), 'the reset map reads back as {}');

    // ---- 5. the second user
    $c->as(2)->get('/api/truck/bootstrap', ['organization_id' => $state['users'][1]['organization_id'], 'truck_id' => $truckId])->status(200)
        ->path('data.has_truck', false)
        ->path('data.truck', null);
    $c->get('/api/truck/profile')->status(200)->path('data.truck', null);
    $c->get('/api/truck/assumptions')->status(409)->path('error', 'Set up your truck first');
    $c->put('/api/truck/assumptions', ['overrides' => ['host.captive_share' => 0.9]])->status(409)->path('error', 'Set up your truck first');
    $c->post('/api/truck/assumptions/reset', [])->status(409)->path('error', 'Set up your truck first');

    // Its first save names the first user's truck and organization. Neither is read: it gets a truck of its own.
    $c->put('/api/truck/profile', [
        'id' => $truckId,
        'truck_id' => $truckId,
        'organization_id' => $state['users'][1]['organization_id'],
        'name' => 'Second truck',
        'base' => ['lat' => 38.9, 'lng' => -77.03, 'address' => 'Washington, DC'],
        'avg_ticket' => 11,
    ])->status(201)->path('data.truck.profile.name', 'Second truck');
    $otherId = $c->value('data.truck.id');
    $c->check(is_string($otherId) && $otherId !== $truckId, 'the second user has a truck of its own');
    $c->put('/api/truck/profile', ['name' => 'Second truck, renamed', 'avg_ticket' => 9.5])->status(200);
    $c->put('/api/truck/assumptions', ['overrides' => ['host.captive_share' => 0.9]])->status(200);
    $c->get('/api/truck/bootstrap')->status(200)
        ->path('data.has_truck', true)
        ->path('data.truck.id', $otherId)
        ->path('data.assumptions.overrides', ['host.captive_share' => 0.9]);

    // Nothing of that reached the first user's truck.
    $c->as(1)->get('/api/truck/profile')->status(200)
        ->path('data.truck.id', $truckId)
        ->path('data.truck.profile.name', 'Smoke & Ember')
        ->path('data.truck.profile.avg_ticket', 12.35);
    $c->get('/api/truck/assumptions')->status(200);
    $c->check(str_contains($c->body(), '"overrides":{}'), 'the first user\'s overrides are untouched');

    // ---- what the later steps start from: the plain default truck of user 1
    // Its region: the usable one chosen above, which a base at its centre keeps. Without one the base
    // is on its own again: the default region of the point, or none with the zone of the first save
    // (the zone of the body is read only while the truck has no region).
    $region = $home ?? $expected;
    $regionId = $region === null ? 'none' : $region['region_id'];
    $finalZone = $home === null ? $zone : $home['timezone'];
    $plain = ['name' => 'Smoke & Ember', 'base' => $base + ['address' => 'Sterling, VA'], 'avg_ticket' => 15] + $defaults;
    $c->put('/api/truck/profile', $plain + ['timezone' => $zone])->status(200);
    foreach ($plain as $field => $value) {
        $c->path('data.truck.profile.' . $field, $value);
    }
    $c->path('data.truck.profile.region_id', $regionId)->path('data.truck.timezone', $finalZone);
    $saved = $c->value('data.truck');

    $c->get('/api/truck/bootstrap')->status(200)
        ->path('data.has_truck', true)
        ->path('data.truck', $saved)
        ->path('data.truck.id', $truckId)
        ->path('data.timezone', $finalZone)
        ->path('data.assumptions.region.id', $regionId)
        ->path('data.counts', ['spots' => 0, 'plans' => 0, 'services' => 0, 'leads' => 0])
        ->finite('data.now_minute', 'data.fuel.price_per_gal', 'data.calibration.truck_factor', 'data.calibration.truck_n');
    $c->check(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $c->value('data.today')) === 1, 'today is a civil date');
    $c->check($c->value('data.now_minute') >= 0 && $c->value('data.now_minute') <= 1439, 'now_minute is a minute of the day');
    $c->check($c->value('data.calibration.as_of') === $c->value('data.today'), 'the calibration is as of today in the truck\'s zone');
    if ($region === null) {
        $c->path('data.region', null);
    } else {
        $c->path('data.region.region_id', $regionId);
    }

    $state['setup'] = [
        'truck_id' => [1 => $truckId, 2 => $otherId],
        'region_id' => (string) $c->value('data.truck.profile.region_id'),
        'timezone' => (string) $c->value('data.timezone'),
        'base' => $base,
    ];
};
