<?php
declare(strict_types=1);

use App\TruckPlanner\Model\Estimator;
use App\TruckPlanner\Model\Seeds;

/**
 * Smoke step 60: Scout and its leads (routes 38 to 41).
 *
 *   1. A query that fails names its field, and a key that is no possible host of the truck's region answers
 *      404 on every lead route. Without usable region data the list answers 409 with the sentence that
 *      says why, and the step ends there.
 *   2. The list: at most 50 places in rank order, each with the model's result (orders and contribution as
 *      ranges with a confidence label, the round trip within the drive limit), its OpenStreetMap columns,
 *      the owner's lead, a map link that needs no API call and where its drive legs came from. The standing
 *      reminder and the sources travel with it. A second call is served from the caches and says so.
 *      For the best places the orders are the model's window on the vectors simulate answers at the place.
 *   3. The drive limit and the licence counties of the profile narrow the list.
 *   4. Leads: created on first touch, changed key by key (V12 when a change carries nothing), listed with
 *      their place. A hidden lead leaves the ranking and comes back when nothing is hidden.
 *   5. The contact lookup: on a server without a Google key it answers 503 with its sentence and stores
 *      nothing. (On a server with a key the step does not call it: a lookup is billed.)
 *   6. Save as spot, for one place of each kind the region offers (with a typical size, without one, without
 *      a host segment): the spot stands at the place, is linked to it and carries no looked-up detail; a
 *      place without a typical size needs the owner's figure; a place is saved once until its spot is
 *      archived.
 *   7. The second user has leads of its own for the same places and gets 404 for the first user's spot.
 *
 * It starts from the trucks of step 20. The spots it saves are archived again and the profile is put back,
 * so user 1 is left as step 30 left it, with a few leads more.
 *
 * For the later steps:
 *
 *     $state['scout'] = ['place_key' => string|null, 'lead_id' => string|null]     (a lead of user 1, when a region is loaded)
 */
return function (SmokeClient $c, array &$state): void {
    $setup = $state['setup'] ?? null;
    if (!is_array($setup) || !isset($setup['truck_id'][1], $setup['truck_id'][2])) {
        throw new SmokeFailure('step 60 starts from the two trucks that step 20 leaves in $state[\'setup\']');
    }
    $statuses = 'new, shortlisted, contacted, booked, declined, hidden';
    $mapsLink = 'https://www.google.com/maps/search/?api=1&query=';
    $notice = 'Permission to trade here and local rules are yours to check.';
    $noPlace = 'Place not found';
    $state['scout'] = ['place_key' => null, 'lead_id' => null];

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
    // The place keys of a list, in rank order.
    $keysOf = static function (mixed $candidates): array {
        $keys = [];
        foreach ((array) $candidates as $candidate) {
            $keys[] = (string) $candidate['place']['place_key'];
        }
        return $keys;
    };
    // One candidate as the specification describes it.
    $checkCandidate = static function (SmokeClient $c, array $candidate, int $position, int $limit) use ($mapsLink): void {
        $c->check(array_keys($candidate) === ['result', 'place', 'lead', 'maps_url', 'leg_sources'], 'a candidate has the fields of the specification, in order');
        $result = $candidate['result'];
        $place = $candidate['place'];
        $c->check(
            array_keys($result) === ['place_id', 'place_type', 'position', 'host_fit', 'kitchen', 'host_segment', 'host_size', 'size_source',
                'best_window', 'orders', 'contribution', 'round_trip', 'score'],
            'a result has the fields of the model\'s ScoutResult'
        );
        $c->check(
            array_keys($place) === ['place_key', 'name', 'brand', 'place_type', 'lat', 'lng', 'county_fips', 'addr_line', 'city', 'state_code',
                'postcode', 'phone', 'website', 'opening_hours_raw', 'kitchen'],
            'a place has its OpenStreetMap columns'
        );
        $c->check($result['position'] === $position, 'positions count from 1 without a gap');
        $c->check($result['place_id'] === $place['place_key'] && $result['place_type'] === $place['place_type'], 'result and place are the same place');
        $c->check(is_string($place['name']) && $place['name'] !== '', 'a possible host has a name');
        $c->check(is_string($place['county_fips']) && strlen($place['county_fips']) === 5, 'and lies in a county of the region');
        $c->check($result['host_fit'] > 0 && $result['host_fit'] <= 1, 'its kind of place hosts trucks at least sometimes');
        $c->check(in_array($result['kitchen'], ['yes', 'no'], true) && in_array($place['kitchen'], ['yes', 'no', 'unknown'], true), 'the kitchen is resolved in the result');
        // Every estimate is a range with its confidence label.
        foreach (['orders', 'contribution'] as $estimate) {
            $e = $result[$estimate];
            $c->check(is_array($e) && array_keys($e) === ['value', 'low', 'high', 'confidence'], $estimate . ' is an estimate');
            $c->check((is_int($e['value']) || is_float($e['value'])) && $e['low'] <= $e['value'] && $e['value'] <= $e['high'], $estimate . ' is a range around its value');
            $c->check(in_array($e['confidence'], ['very_rough', 'rough', 'fair', 'good'], true), $estimate . ' has a confidence label');
        }
        if ($result['best_window'] === null) {
            $c->check($result['orders']['value'] == 0 && $result['round_trip']['minutes'] === 0, 'a place without a window has no orders and no trip');
        } else {
            $window = $result['best_window'];
            $c->check($window['dow'] >= 0 && $window['dow'] <= 6 && $window['close_minute'] - $window['open_minute'] === 180, 'the best window is three hours of a weekday');
            $c->check($result['orders']['value'] > 0, 'a place with a window has orders');
        }
        $c->check(is_int($result['round_trip']['minutes']) && $result['round_trip']['minutes'] <= 2 * $limit, 'the round trip is within twice the drive limit');
        $c->check($candidate['lead']['place_key'] === $place['place_key'], 'the lead is the place\'s');
        $c->check(
            is_string($candidate['maps_url']) && str_starts_with($candidate['maps_url'], $mapsLink) && strlen($candidate['maps_url']) > strlen($mapsLink),
            'every candidate has a map link'
        );
        $c->check(
            in_array($candidate['leg_sources']['out'] ?? null, ['google_routes', 'google_distance_matrix', 'straight_line', 'same_point'], true)
            && in_array($candidate['leg_sources']['back'] ?? null, ['google_routes', 'google_distance_matrix', 'straight_line', 'same_point'], true),
            'both legs say where they came from'
        );
    };

    // ---- where user 1 stands
    $c->as(1)->get('/api/truck/bootstrap')->status(200)->path('data.has_truck', true);
    $profile = $c->value('data.truck.profile');
    $region = $c->value('data.region');
    $calibration = $c->value('data.calibration');
    $overrides = (array) $c->value('data.assumptions.overrides');
    $regionBlock = $c->value('data.assumptions.region');
    $noKey = $c->value('data.routing.state') === 'no_key';
    $withRegion = !$state['no_region'] && is_array($region) && ($region['usable'] ?? false) === true;
    $limit = (int) $profile['scout_drive_minutes_limit'];
    $c->check($profile['licence_counties'] === [], 'step 20 leaves user 1 without licence counties');

    // =====================================================================================
    // 1. what is refused whatever the region
    // =====================================================================================
    $refused($c->get('/api/truck/scout', ['hide' => 'gone']), 'hide must be one of: ' . $statuses, 'hide', 'V4');
    $refused($c->get('/api/truck/scout', ['hide' => 'hidden,']), 'hide must be one of: ' . $statuses, 'hide', 'V4');
    $refused($c->get('/api/truck/scout', ['refresh' => 'yes']), 'refresh must be true or false', 'refresh', 'V6');

    // A key that is no place, and texts that cannot be keys: 404 on every lead route, with or without a body.
    foreach (['n0', 'w%201', 'no-such-place-key-at-all'] as $key) {
        $c->put('/api/truck/scout/leads/' . $key, ['status' => 'hidden'])->status(404)->path('success', false)->path('error', $noPlace);
        $c->post('/api/truck/scout/leads/' . $key . '/contact')->status(404)->path('error', $noPlace);
        $c->post('/api/truck/scout/leads/' . $key . '/spot', [])->status(404)->path('error', $noPlace);
    }
    $refused($c->post('/api/truck/scout/leads/n0/contact', ['force' => 'yes']), 'force must be true or false', 'force', 'V6');
    $c->get('/api/truck/bootstrap')->status(200);
    $leadsBefore = (int) $c->value('data.counts.leads');

    if (!$withRegion) {
        // Without usable region data there is nothing to rank, and the answer says which of the two it is.
        // (With --no-region on a server whose region is usable, nothing is asserted here.)
        $reason = is_array($region) ? ($region['unusable_reason'] ?? null) : 'none';
        if ($reason === 'build_mismatch') {
            $c->get('/api/truck/scout')->status(409)->path('success', false)->path('error', 'Region data was built with different model constants');
        } elseif ($reason !== null) {
            $c->get('/api/truck/scout')->status(409)->path('success', false)->path('error', 'Scouting needs a loaded region');
        }
        tp_out('    60: the ranked list and the lead checks need a usable region and are skipped');
        return;
    }
    $regionId = (string) $region['region_id'];
    $dataset = (string) $region['dataset_version'];

    // =====================================================================================
    // 2. the list
    // =====================================================================================
    $c->as(1)->get('/api/truck/scout')->status(200)->path('success', true)
        ->path('data.truncated', false)
        ->path('data.limit_minutes', $limit)
        ->path('data.licence_counties', [])
        ->path('data.dataset_version', $dataset)
        ->path('data.cached', false)
        ->path('data.notice', $notice)
        ->finite('data.screened', 'data.candidates.*.result.score', 'data.candidates.*.result.round_trip.miles', 'data.candidates.*.result.round_trip.cost',
            'data.candidates.*.place.lat', 'data.candidates.*.place.lng');
    $list = $c->value('data');
    $c->check(
        array_keys($list) === ['candidates', 'screened', 'truncated', 'limit_minutes', 'licence_counties', 'dataset_version', 'cached', 'notice', 'attribution'],
        'the list has the fields of the specification, in order'
    );
    $candidates = $list['candidates'];
    $c->check(is_array($candidates) && array_is_list($candidates) && $candidates !== [], 'a loaded region has places that could host a truck within reach of its centre');
    $c->check(count($candidates) <= 50 && $list['screened'] >= count($candidates), 'at most 50 places, out of those that were screened');
    foreach ($candidates as $i => $candidate) {
        $checkCandidate($c, $candidate, $i + 1, $limit);
        $c->check($candidate['lead'] === ['id' => null, 'place_key' => $candidate['place']['place_key'], 'status' => 'new', 'notes' => null, 'spot_id' => null, 'google' => null], 'a place nobody touched has the lead of a new place');
        // No place id is stored yet: the link is a pin at the place's own point, to six decimals.
        $c->check(
            $candidate['maps_url'] === $mapsLink . sprintf('%.6F', Estimator::roundHalfAway((float) $candidate['place']['lat'], 6))
                . '%2C' . sprintf('%.6F', Estimator::roundHalfAway((float) $candidate['place']['lng'], 6)),
            'the map link is the pin at the place'
        );
        if ($noKey) {
            $c->check($candidate['leg_sources'] === ['out' => 'straight_line', 'back' => 'straight_line'], 'without a Google key every leg is a straight-line estimate');
        }
        if ($i > 0) {
            $c->check(
                Estimator::qkey((float) $candidates[$i - 1]['result']['score']) >= Estimator::qkey((float) $candidate['result']['score']),
                'the list is in rank order'
            );
        }
    }
    $c->check(count(array_unique($keysOf($candidates))) === count($candidates), 'each place once');
    $attribution = $list['attribution'];
    $c->check(is_array($attribution) && count($attribution) === 3, 'the list names its three sources');
    foreach ((array) $attribution as $line) {
        $c->check(is_string($line) && $line !== '' && !str_contains($line, '{'), 'a source line is a finished sentence');
    }
    $c->check(str_contains((string) $attribution[0], 'OpenStreetMap contributors') && str_contains((string) $attribution[1], 'Open Database License'), 'places are credited to OpenStreetMap');
    $c->check(str_starts_with((string) $attribution[2], 'Drive times and distances: Google Maps Platform'), 'and drive times to Google');
    // The standing reminder is the only thing the list says about rules. (That no sentence of the backend
    // states that a place may be used is held by the house-rules test, which reads every string of it.)
    $c->check($list['notice'] === $notice, 'the reminder travels with the list');

    // Warm: served from the caches, and the same list.
    $c->get('/api/truck/scout')->status(200)->path('data.cached', true)->path('data.screened', $list['screened']);
    $c->check($c->value('data.candidates') === $candidates, 'the cached list is the list');
    $c->get('/api/truck/scout', ['hide' => 'hidden'])->status(200)->path('data.cached', true);
    // refresh computes it again, to the same result
    $c->get('/api/truck/scout', ['refresh' => 1])->status(200)->path('data.cached', false);
    $c->check($c->value('data.candidates') === $candidates, 'a refreshed list is the same list');

    // The orders of the best places are the model's window on the vectors simulate answers at the place:
    // the screen picked the places, the model made the numbers.
    $A = Seeds::assumptions($overrides, is_array($regionBlock) ? $regionBlock : null);
    foreach (array_slice($candidates, 0, 3) as $candidate) {
        $result = $candidate['result'];
        if ($result['best_window'] === null) {
            continue;
        }
        $key = $candidate['place']['place_key'];
        $body = ['point' => ['lat' => $candidate['place']['lat'], 'lng' => $candidate['place']['lng']], 'visibilities' => ['normal']];
        if ($result['host_size'] > 0) {
            $body['terms'] = ['visibility' => 'normal', 'host' => ['place_key' => $key]];
        }
        $c->post('/api/truck/simulate', $body)->status(200);
        $terms = ['spot_id' => null, 'visibility' => 'normal', 'host' => $c->value('data.host'), 'fee_flat' => 0.0, 'fee_pct' => 0.0, 'fee_min' => 0.0, 'allowed' => null];
        $window = $result['best_window'];
        $orders = Estimator::windowOrders(
            $A,
            $profile,
            $terms,
            $c->value('data.vectors.normal'),
            is_array($calibration) ? $calibration : null,
            Estimator::typicalContext($A, $window['dow']),
            Estimator::typicalContext($A, ($window['dow'] + 1) % 7),
            $window['open_minute'],
            $window['close_minute']
        )['orders'];
        foreach (['value', 'low', 'high'] as $part) {
            $c->check($close($orders[$part], $result['orders'][$part]), 'orders.' . $part . ' of ' . $key . ' is the model\'s window on the exact vectors at the place');
        }
        $c->check($orders['confidence'] === $result['orders']['confidence'], 'with the model\'s confidence label');
        $money = Estimator::stopMoney($profile, $terms, $orders)['contribution'];
        $c->check($close($money['value'], $result['contribution']['value']), 'and the contribution of ' . $key . ' is the model\'s on those orders');
    }

    // =====================================================================================
    // 3. the drive limit and the licence counties narrow the list
    // =====================================================================================
    $county = (string) $candidates[0]['place']['county_fips'];
    $c->put('/api/truck/profile', ['scout_drive_minutes_limit' => 5, 'licence_counties' => [$county]])->status(200)
        ->path('data.truck.profile.scout_drive_minutes_limit', 5)
        ->path('data.truck.profile.licence_counties', [$county]);
    $c->get('/api/truck/scout')->status(200)
        ->path('data.limit_minutes', 5)
        ->path('data.licence_counties', [$county])
        ->path('data.cached', false);
    $narrow = (array) $c->value('data.candidates');
    $c->check($c->value('data.screened') <= $list['screened'], 'a shorter drive and one county screen no more places');
    $c->check(count($narrow) <= count($candidates), 'and list no more of them');
    foreach ($narrow as $i => $candidate) {
        $checkCandidate($c, $candidate, $i + 1, 5);
        $c->check($candidate['place']['county_fips'] === $county, 'only places of the licence county are listed');
    }
    $c->put('/api/truck/profile', ['scout_drive_minutes_limit' => $limit, 'licence_counties' => []])->status(200)
        ->path('data.truck.profile.scout_drive_minutes_limit', $limit)
        ->path('data.truck.profile.licence_counties', []);
    $c->get('/api/truck/scout')->status(200)->path('data.limit_minutes', $limit);
    $c->check($c->value('data.candidates') === $candidates, 'with the profile put back the list is the one of before');

    // =====================================================================================
    // 4. leads
    // =====================================================================================
    $first = (string) $candidates[0]['place']['place_key'];
    $leadPath = '/api/truck/scout/leads/' . $first;

    // refused bodies write nothing
    $refused($c->put($leadPath, []), 'Nothing to update', null, 'V12');
    $refused($c->put($leadPath, ['colour' => 'red']), 'Nothing to update', null, 'V12');
    $refused($c->put($leadPath, ['status' => 'maybe']), 'status must be one of: ' . $statuses, 'status', 'V4');
    $refused($c->put($leadPath, ['status' => null]), 'status is required', 'status', 'V1');
    $refused($c->put($leadPath, ['notes' => str_repeat('n', 4001)]), 'notes must be text of at most 4000 characters', 'notes', 'V5');
    $refused($c->sendRaw('PUT', $leadPath, '["hidden"]'), 'Request body must be a JSON object');
    $c->get('/api/truck/bootstrap')->status(200)->path('data.counts.leads', $leadsBefore);

    // the first touch creates the lead; later ones change the keys they carry
    $c->put($leadPath, ['status' => 'contacted', 'notes' => '  Spoke to the manager, call back Tuesday  '])->status(200)
        ->path('success', true)
        ->path('data.lead.place_key', $first)
        ->path('data.lead.status', 'contacted')
        ->path('data.lead.notes', 'Spoke to the manager, call back Tuesday')
        ->path('data.lead.spot_id', null)
        ->path('data.lead.google', null);
    $lead = $c->value('data.lead');
    $leadId = (string) $lead['id'];
    $c->check(array_keys($lead) === ['id', 'place_key', 'status', 'notes', 'spot_id', 'google'] && strlen($leadId) === 36, 'a lead has the fields of the specification and an id');
    $c->put($leadPath, ['notes' => 'Booked for Friday'])->status(200)
        ->path('data.lead.id', $leadId)
        ->path('data.lead.status', 'contacted')
        ->path('data.lead.notes', 'Booked for Friday');
    $c->put($leadPath, ['status' => 'booked', 'id' => 'another', 'organization_id' => $state['users'][2]['organization_id']])->status(200)
        ->path('data.lead.id', $leadId)
        ->path('data.lead.status', 'booked')
        ->path('data.lead.notes', 'Booked for Friday');
    $c->put($leadPath, ['notes' => null])->status(200)->path('data.lead.notes', null)->path('data.lead.status', 'booked');
    $c->get('/api/truck/bootstrap')->status(200)->path('data.counts.leads', $leadsBefore + 1);

    // the list shows the lead with its place, from the caches
    $c->get('/api/truck/scout')->status(200)->path('data.cached', true)
        ->path('data.candidates.0.lead', ['id' => $leadId, 'place_key' => $first, 'status' => 'booked', 'notes' => null, 'spot_id' => null, 'google' => null]);
    $c->check($keysOf($c->value('data.candidates')) === $keysOf($candidates), 'a status that is not hidden leaves the ranking as it was');

    // a hidden lead leaves the ranking, and the others move up
    $c->put($leadPath, ['status' => 'hidden'])->status(200)->path('data.lead.status', 'hidden');
    $c->get('/api/truck/scout')->status(200);
    $withoutFirst = (array) $c->value('data.candidates');
    $c->check(!in_array($first, $keysOf($withoutFirst), true), 'a hidden place is not listed');
    $c->check($c->value('data.screened') === $list['screened'] - 1, 'and is not screened');
    foreach ($withoutFirst as $i => $candidate) {
        $c->check($candidate['result']['position'] === $i + 1, 'the places after it move up');
    }
    if (count($candidates) > 1) {
        $c->check($withoutFirst[0]['place']['place_key'] === $candidates[1]['place']['place_key'], 'the second place is now the first');
    }
    // nothing hidden: it is back, with its status
    $c->get('/api/truck/scout', ['hide' => ''])->status(200)->path('data.candidates.0.place.place_key', $first)->path('data.candidates.0.lead.status', 'hidden');
    $c->check($keysOf($c->value('data.candidates')) === $keysOf($candidates), 'with nothing hidden the list is whole');
    // a place nobody touched is new: hiding new leaves the places the owner touched
    $c->get('/api/truck/scout', ['hide' => 'new,hidden'])->status(200);
    $c->check($keysOf($c->value('data.candidates')) === [], 'hiding new and hidden leaves nothing while the one lead is hidden');
    $c->get('/api/truck/scout', ['hide' => 'new,declined'])->status(200)->path('data.screened', 1);
    $c->check($keysOf($c->value('data.candidates')) === [$first], 'hiding new and declined leaves the one place the owner touched');
    $c->put($leadPath, ['status' => 'shortlisted'])->status(200)->path('data.lead.status', 'shortlisted');

    // =====================================================================================
    // 5. the contact lookup
    // =====================================================================================
    $refused($c->post($leadPath . '/contact', ['force' => 1]), 'force must be true or false', 'force', 'V6');
    if ($noKey) {
        foreach ([null, [], ['force' => true]] as $body) {
            $c->allow(503)->post($leadPath . '/contact', $body)->status(503)
                ->path('success', false)
                ->path('error', 'Contact lookup is not available on this server');
        }
        // nothing was stored, also for a place that had no lead yet
        $c->put($leadPath, ['notes' => 'no lookup here'])->status(200)->path('data.lead.google', null);
        if (count($candidates) > 1) {
            $untouched = (string) $candidates[1]['place']['place_key'];
            $c->allow(503)->post('/api/truck/scout/leads/' . $untouched . '/contact')->status(503);
            $c->get('/api/truck/bootstrap')->status(200)->path('data.counts.leads', $leadsBefore + 1);
        }
    } else {
        tp_out('    60: this server has a Google key, the contact lookup is not called (a lookup is billed)');
    }

    // =====================================================================================
    // 6. save as spot
    // =====================================================================================
    $c->get('/api/truck/spots')->status(200);
    $spotsBefore = array_column((array) $c->value('data.spots'), 'id');
    $saved = [];

    // one place of each kind the lists hold: with a typical size, without one, without a host segment.
    // The short list around the base of part 3 is looked at as well: it often holds kinds the long one lacks.
    $kinds = ['sized' => null, 'unsized' => null, 'hostless' => null];
    foreach (array_merge($candidates, $narrow) as $candidate) {
        $result = $candidate['result'];
        $kind = $result['host_segment'] === null ? 'hostless' : ($result['host_size'] > 0 ? 'sized' : 'unsized');
        $kinds[$kind] ??= $candidate;
    }
    // Where office buildings fill both lists, a place with a typical size may still stand beside one of
    // them. Simulate names the possible hosts around a point; a lead on one of those, and the list of the
    // touched places only, give its candidate.
    if ($kinds['sized'] === null) {
        $tries = 0;
        foreach (array_slice($candidates, 0, 5) as $listed) {
            $c->post('/api/truck/simulate', ['point' => ['lat' => $listed['place']['lat'], 'lng' => $listed['place']['lng']], 'visibilities' => ['normal']])->status(200);
            foreach ((array) $c->value('data.hosts_nearby') as $hint) {
                if ($kinds['sized'] !== null || $tries >= 3 || $hint['host_segment'] === null || !($hint['default_size'] > 0)) {
                    continue;
                }
                $tries++;
                $c->put('/api/truck/scout/leads/' . $hint['place_key'], ['status' => 'shortlisted'])->status(200)->path('data.lead.status', 'shortlisted');
                $c->get('/api/truck/scout', ['hide' => 'new,hidden'])->status(200);
                foreach ((array) $c->value('data.candidates') as $touched) {
                    if ($touched['place']['place_key'] === $hint['place_key'] && $touched['result']['host_size'] > 0) {
                        $c->check($touched['lead']['status'] === 'shortlisted', 'a touched place is listed with its lead');
                        $kinds['sized'] = $touched;
                    }
                }
            }
        }
    }

    // refused bodies save nothing
    $spotPath = $leadPath . '/spot';
    $refused($c->post($spotPath, ['name' => str_repeat('n', 121)]), 'name must be text of at most 120 characters', 'name', 'V5');
    $refused($c->post($spotPath, ['visibility' => 'loud']), 'visibility must be one of: hidden, normal, prominent', 'visibility', 'V4');
    $refused($c->post($spotPath, ['host_size' => 0]), 'host_size must be a number between 1 and 200000', 'host_size', 'V2');
    $refused($c->post($spotPath, ['only_food' => 'yes']), 'only_food must be true or false', 'only_food', 'V6');

    foreach ($kinds as $kind => $candidate) {
        if ($candidate === null) {
            tp_out('    60: the lists hold no ' . $kind . ' place, that way of saving a spot is not exercised');
            continue;
        }
        $key = (string) $candidate['place']['place_key'];
        $path = '/api/truck/scout/leads/' . $key . '/spot';
        $body = [];
        if ($kind === 'unsized') {
            // An office park, an apartment community: no typical size, so the owner's figure is needed.
            $refused($c->post($path), 'host_size is required for this kind of place', 'host_size');
            $refused($c->post($path, ['only_food' => true]), 'host_size is required for this kind of place', 'host_size');
            $body = ['host_size' => 600, 'visibility' => 'prominent', 'name' => '  Smoke scout spot  '];
        }
        $c->post($path, $body)->status(201)
            ->path('success', true)
            ->path('message', 'Spot saved')
            ->path('data.spot.point', ['lat' => $candidate['place']['lat'], 'lng' => $candidate['place']['lng']])
            ->path('data.spot.host_details.place_key', $key)
            ->path('data.spot.host_details.place_type', $candidate['place']['place_type'])
            ->path('data.spot.host_details.name', $candidate['place']['name'])
            ->path('data.spot.host_details.phone', $candidate['place']['phone'])
            ->path('data.spot.host_details.website', $candidate['place']['website'])
            ->path('data.spot.host_details.google_place_id', null)
            ->path('data.spot.vectors_state', 'fresh')
            ->path('data.spot.archived', false)
            ->path('data.lead.place_key', $key)
            ->path('data.lead.google', null);
        $spot = $c->value('data.spot');
        $spotId = (string) $spot['id'];
        $saved[$kind] = $spotId;
        $c->path('data.lead.spot_id', $spotId);
        $c->check(array_keys((array) $c->value('data')) === ['spot', 'lead'], 'the answer is the spot and the lead');
        $c->check(array_keys((array) $spot['vectors']) === ['hidden', 'normal', 'prominent'], 'the spot has its vectors for the three visibility levels');
        if ($kind === 'unsized') {
            $c->path('data.spot.name', 'Smoke scout spot')
                ->path('data.spot.terms.visibility', 'prominent')
                ->path('data.spot.terms.host.segment', $candidate['result']['host_segment'])
                ->path('data.spot.terms.host.size', 600)
                ->path('data.spot.terms.host.size_source', 'owner')
                ->path('data.spot.terms.host.place_type', $candidate['place']['place_type']);
        } elseif ($kind === 'sized') {
            $c->path('data.spot.name', mb_substr((string) $candidate['place']['name'], 0, 120))
                ->path('data.spot.terms.visibility', 'normal')
                ->path('data.spot.terms.host.segment', $candidate['result']['host_segment'])
                ->path('data.spot.terms.host.size', $candidate['result']['host_size'])
                ->path('data.spot.terms.host.size_source', 'default')
                ->path('data.spot.terms.host.only_food', $candidate['result']['kitchen'] === 'no')
                ->path('data.spot.terms.host.place_type', $candidate['place']['place_type']);
        } else {
            $c->path('data.spot.terms.host', null);
        }
        // a new lead is shortlisted; the status the owner chose stays
        $c->path('data.lead.status', 'shortlisted');

        // the spot is an ordinary spot, and saving the place again is refused
        $c->get('/api/truck/spots/' . $spotId)->status(200)->path('data.spot.id', $spotId)->path('data.spot.host_details.place_key', $key);
        $c->post($path, $body)->status(409)->path('success', false)->path('error', 'This place is already saved as a spot');
        $c->get('/api/truck/scout', ['hide' => ''])->status(200);
        foreach ((array) $c->value('data.candidates') as $listed) {
            if ($listed['place']['place_key'] === $key) {
                $c->check($listed['lead']['spot_id'] === $spotId, 'the list shows the place as saved');
            }
        }
    }
    $c->check($saved !== [], 'a place of the list was saved as a spot');

    // Archiving the spot frees the place: the lead reads as not saved, and the place can be saved again.
    $kind = array_key_first($saved);
    $key = (string) $kinds[$kind]['place']['place_key'];
    $path = '/api/truck/scout/leads/' . $key;
    $c->delete('/api/truck/spots/' . $saved[$kind])->status(200)->path('data.archived', true);
    $c->put($path, ['notes' => 'the spot was archived'])->status(200)->path('data.lead.spot_id', null)->path('data.lead.status', 'shortlisted');
    $c->post($path . '/spot', ['host_size' => 300])->status(201)->path('data.lead.place_key', $key);
    $again = (string) $c->value('data.spot.id');
    $c->check($again !== $saved[$kind], 'a second spot, not the archived one');
    $c->path('data.lead.spot_id', $again);
    $saved[$kind] = $again;

    // =====================================================================================
    // 7. the second user
    // =====================================================================================
    $c->as(2)->get('/api/truck/spots/' . $again)->status(404)->path('error', 'Spot not found');
    $c->get('/api/truck/scout', ['hide' => ''])->status(200, 409);
    if ($c->lastStatus() === 409) {
        // Its truck stands outside every loaded region: nothing to rank, and no place to keep a lead on.
        $c->path('error', 'Scouting needs a loaded region');
        $c->put($leadPath, ['status' => 'hidden'])->status(404)->path('error', $noPlace);
        $c->post($leadPath . '/spot')->status(404)->path('error', $noPlace);
    } else {
        // The same places, and leads of its own: none of what the first user wrote is there.
        foreach ((array) $c->value('data.candidates') as $candidate) {
            $c->check($candidate['lead']['id'] === null && $candidate['lead']['notes'] === null && $candidate['lead']['spot_id'] === null, 'the second user sees no lead of the first');
        }
        $c->put($leadPath, ['status' => 'declined', 'notes' => 'second user'])->status(200, 404);
        if ($c->lastStatus() === 200) {
            $c->path('data.lead.status', 'declined')->path('data.lead.spot_id', null);
            $c->check($c->value('data.lead.id') !== $leadId, 'the second user\'s lead is another row');
        }
    }
    // The first user's lead is as it was.
    $c->as(1)->put($leadPath, ['notes' => 'still mine'])->status(200)
        ->path('data.lead.id', $leadId)
        ->path('data.lead.notes', 'still mine');
    $c->check($c->value('data.lead.status') !== 'declined', 'another tenant\'s change did not reach it');

    // ---- what the later steps start from: the spots of step 30, and the profile as it was
    foreach ($saved as $spotId) {
        $c->delete('/api/truck/spots/' . $spotId)->status(200)->path('data.archived', true);
    }
    $c->get('/api/truck/spots')->status(200);
    $c->check(array_column((array) $c->value('data.spots'), 'id') === $spotsBefore, 'user 1 is left with the spots it had');
    $c->get('/api/truck/profile')->status(200)
        ->path('data.truck.profile.scout_drive_minutes_limit', $limit)
        ->path('data.truck.profile.licence_counties', []);
    $state['scout'] = ['place_key' => $first, 'lead_id' => $leadId];
};
