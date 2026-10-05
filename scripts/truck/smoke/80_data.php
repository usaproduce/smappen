<?php
declare(strict_types=1);

/**
 * Smoke step 80: the data page. Sources, the export and "delete everything" (routes 42 to 44).
 *
 *   1. Sources: the source lines come in id order, finished (no placeholder left in), with a link only
 *      where one belongs; with a usable region the dataset is described, without a truck the lines that
 *      need none are still given.
 *   2. The export is one JSON document that says `"incomplete": false`: a bare document sent as a download,
 *      holding the user's own truck, spots, plans, services, corrections and leads, and nothing fetched
 *      from Google. A second user's export holds nothing of the first.
 *   3. Delete: a third account is made for it, so that the two users of the run keep their data. Without
 *      the exact phrase nothing is deleted. With it the truck data of that organization is gone, bootstrap
 *      says `has_truck: false`, the account stays, and the other two organizations are untouched.
 *
 * It starts from the trucks of step 20 and the spots of step 30, and changes nothing of users 1 and 2.
 */
return function (SmokeClient $c, array &$state): void {
    $setup = $state['setup'] ?? null;
    $spots = $state['spots'] ?? null;
    if (!is_array($setup) || !isset($setup['truck_id'][1], $setup['truck_id'][2]) || !is_array($spots) || !isset($spots['first'])) {
        throw new SmokeFailure('step 80 starts from the trucks of step 20 and the spots of step 30');
    }
    $phrase = 'delete my truck data';
    $osmPage = 'https://www.openstreetmap.org/copyright';
    $lehdPage = 'https://lehd.ces.census.gov/data/';
    $osmSentence = "Place data \u{00A9} OpenStreetMap contributors, available under the Open Database License (ODbL).";
    $zeros = ['services' => 0, 'plan_stops' => 0, 'plans' => 0, 'leads' => 0, 'drive_overrides' => 0, 'spots' => 0, 'trucks' => 0];
    $documentKeys = [
        'export', 'export_version', 'exported_at', 'model_version', 'seeds_revision', 'truck', 'overrides',
        'spots', 'plans', 'services', 'drive_overrides', 'scout_leads', 'attribution', 'incomplete',
    ];
    // What must never be in an export: Google content, and the two parts of a plan that hold it.
    $neverExported = [
        'g_name', 'g_address', 'g_phone', 'g_website', 'g_maps_uri', 'g_fetched_at', '"result"', '"context"', '"legs"', '"timeline"',
        'duration_s', 'distance_m', 'google_routes', 'google_distance_matrix', '"vectors"', 'vectors_bin', 'weather_json',
    ];

    // The source lines of an answer: in id order, finished, linked only where a link belongs.
    $checkLines = static function (SmokeClient $c, mixed $lines) use ($osmPage, $lehdPage): array {
        $c->check(is_array($lines) && array_is_list($lines) && $lines !== [], 'the answer names its sources');
        $ids = [];
        foreach ($lines as $line) {
            $c->check(is_array($line) && array_keys($line) === ['id', 'text', 'url'], 'a source line is an id, a text and a link');
            $c->check(is_int($line['id']) && $line['id'] >= 1 && $line['id'] <= 12, 'a source line carries its number of the data document');
            $c->check($ids === [] || $line['id'] > $ids[count($ids) - 1], 'the source lines come in id order');
            $ids[] = $line['id'];
            $c->check(is_string($line['text']) && $line['text'] !== '' && strpbrk($line['text'], '{}') === false, 'source line ' . $line['id'] . ' is a finished sentence');
            $expected = in_array($line['id'], [1, 2, 10], true) ? $osmPage : ($line['id'] === 4 ? $lehdPage : null);
            $c->check($line['url'] === $expected, 'source line ' . $line['id'] . ' links where it should');
        }
        return $ids;
    };
    // An export as text and decoded: one whole JSON document.
    $export = static function (SmokeClient $c) use ($documentKeys, $neverExported, $osmSentence): array {
        $c->get('/api/truck/export')->status(200);
        $text = $c->body();
        $document = $c->json();
        $c->check(is_array($document), 'the export parses as JSON');
        $c->check(array_keys((array) $document) === $documentKeys, 'the export has its fourteen parts in order');
        $c->check(!array_key_exists('success', (array) $document), 'the export is a bare document, not the envelope');
        $c->check(str_starts_with((string) $c->responseHeader('Content-Type'), 'application/json'), 'the export is sent as JSON');
        $c->check($c->responseHeader('Cache-Control') === 'no-store', 'the export is not to be cached');
        $c->check(
            preg_match('/^attachment; filename="truck-planner-export-\d{8}\.json"$/', (string) $c->responseHeader('Content-Disposition')) === 1,
            'the export is a download named for its date'
        );
        $c->path('export', 'truck-planner')->path('export_version', 1)->path('incomplete', false)->path('attribution', [$osmSentence]);
        $c->check(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $document['exported_at']) === 1, 'the export says when it was made, in UTC');
        $c->check(str_ends_with($text, ',"incomplete":false}'), 'the document ends by saying it is complete');
        $c->check(str_contains($text, '"overrides":{'), 'the overrides are a map, also when there are none');
        foreach (['spots', 'plans', 'services', 'drive_overrides', 'scout_leads'] as $list) {
            $c->check(is_array($document[$list]) && array_is_list($document[$list]), 'the export lists ' . $list);
        }
        foreach ($neverExported as $never) {
            $c->check(!str_contains($text, $never), 'the export holds no ' . $never);
        }
        return [$text, (array) $document];
    };

    // ---- where the two users stand
    $before = [];
    foreach ([1, 2] as $n) {
        $c->as($n)->get('/api/truck/bootstrap')->status(200)->path('data.has_truck', true)->path('data.truck.id', $setup['truck_id'][$n]);
        $before[$n] = ['truck' => $c->value('data.truck'), 'counts' => $c->value('data.counts')];
    }
    $c->as(1)->get('/api/truck/bootstrap')->status(200);
    $today = (string) $c->value('data.today');
    $region = $c->value('data.region');
    $fuel = $c->value('data.fuel');
    $modelVersion = $c->value('data.model_version');
    $seedsRevision = $c->value('data.seeds_revision');
    $usableRegion = is_array($region) && ($region['usable'] ?? false) === true;

    // =====================================================================================
    // 1. sources
    // =====================================================================================

    $c->get('/api/truck/sources')->status(200)
        ->path('success', true)
        ->path('data.model_version', $modelVersion)
        ->path('data.seeds_revision', $seedsRevision)
        ->path('data.fuel', $fuel);
    $sources = (array) $c->value('data');
    $c->check(array_keys($sources) === ['model_version', 'seeds_revision', 'region', 'dataset', 'fuel', 'contact', 'attribution'], 'sources has its seven parts');
    $c->check(is_string($sources['contact']), 'the contact is text, empty when the server has none');
    $ids = $checkLines($c, $sources['attribution']);
    foreach ([1, 2, 3, 6, 8, 9, 12] as $always) {
        $c->check(in_array($always, $ids, true), 'source line ' . $always . ' needs nothing and is always given');
    }
    $c->check(in_array(7, $ids, true) === (($fuel['period'] ?? null) !== null), 'the fuel line is given exactly when the price has a week');
    foreach ($sources['attribution'] as $line) {
        if ($line['id'] === 7) {
            $c->check(str_ends_with($line['text'], 'week of ' . $fuel['period'] . '.'), 'the fuel line names the week of the price');
        }
        if ($line['id'] === 5) {
            $c->check($sources['contact'] !== '' && str_ends_with($line['text'], 'on request: ' . $sources['contact'] . '.'), 'the offer of the places table names the contact address');
        }
    }
    // What needs the region's dataset. (A run that was told there is no region does not look at it.)
    if (!$state['no_region'] && $usableRegion) {
        $c->path('data.region.region_id', $region['region_id'])->path('data.dataset.dataset_version', $region['dataset_version']);
        $c->finite('data.dataset.counts.points', 'data.dataset.counts.places', 'data.dataset.counts.cells', 'data.dataset.totals.residents', 'data.dataset.totals.jobs');
        $c->check(is_array($c->value('data.dataset.warn_gates')), 'the dataset lists its open warnings');
        $c->check($c->value('data.dataset.vintages.osm_snapshot_date') === $region['vintages']['osm_snapshot_date'], 'the dataset shows the vintages of the region');
        foreach ([4, 10] as $needsData) {
            $c->check(in_array($needsData, $ids, true), 'with region data, source line ' . $needsData . ' is filled from its manifest');
        }
        $c->check(in_array(5, $ids, true) === ($sources['contact'] !== ''), 'the offer of the places table is made exactly when there is an address to ask at');
    } elseif (!$state['no_region']) {
        $c->path('data.dataset', null);
        foreach ([4, 5, 10] as $needsData) {
            $c->check(!in_array($needsData, $ids, true), 'without region data, source line ' . $needsData . ' is left out');
        }
    }

    // =====================================================================================
    // 2. the export
    // =====================================================================================

    [$text, $document] = $export($c->as(1));
    $c->check($c->responseHeader('Content-Disposition') === 'attachment; filename="truck-planner-export-' . str_replace('-', '', $today) . '.json"', 'the file is named for today where the truck is');
    $c->check($document['model_version'] === $modelVersion && $document['seeds_revision'] === $seedsRevision, 'the export names the model it was made with');
    $c->check($document['truck'] == $before[1]['truck'], 'the truck of the export is the user\'s truck');

    // The spots are the user's spots, archived ones too, as the spot list shows them, less their vectors.
    $c->get('/api/truck/spots', ['archived' => 1])->status(200);
    $listed = [];
    foreach ((array) $c->value('data.spots') as $spot) {
        unset($spot['vectors'], $spot['vectors_state']);
        $listed[$spot['id']] = $spot;
    }
    $c->check(count($document['spots']) === count($listed), 'every spot of the user is exported');
    foreach ($document['spots'] as $spot) {
        $c->check(!array_key_exists('vectors', $spot) && !array_key_exists('vectors_state', $spot), 'a spot is exported without its vectors');
        $c->check(isset($listed[$spot['id']]) && $listed[$spot['id']] == $spot, 'an exported spot is the spot of the list');
    }
    $c->check(in_array($spots['first'], array_column($document['spots'], 'id'), true), 'the spot of step 30 is in the export');
    $c->check(in_array($spots['archived'], array_column($document['spots'], 'id'), true), 'so is the archived one');
    $c->check(count($document['plans']) === $before[1]['counts']['plans'], 'every plan is exported');
    $c->check(count($document['services']) === $before[1]['counts']['services'], 'every logged service is exported');
    $c->check(count($document['scout_leads']) === $before[1]['counts']['leads'], 'every Scout lead is exported');
    foreach ($document['plans'] as $plan) {
        $c->check(!array_key_exists('result', $plan) && !array_key_exists('context', $plan), 'a plan is exported without its result and context');
        $c->check(in_array($plan['result_state'], ['fresh', 'stale', 'expired', 'none'], true) && is_array($plan['stops']), 'a plan is exported with its stops and the state of its result');
        $c->check($plan['summary'] === null || $plan['result_state'] === 'fresh', 'only a current result gives a summary');
    }
    foreach ($document['scout_leads'] as $lead) {
        $c->check(array_keys($lead) === ['place_key', 'place_name', 'place_type', 'lat', 'lng', 'status', 'notes', 'spot_id', 'google_place_id'], 'a lead is exported without looked-up contact fields');
    }
    // The corrections are those of the corrections list.
    $c->get('/api/truck/drive-times/overrides')->status(200);
    $c->check((array) $c->value('data.overrides') == $document['drive_overrides'], 'the exported corrections are the corrections of the list');

    // The second user's export is its own: nothing of the first user is in it.
    [$theirText, $theirs] = $export($c->as(2));
    $c->check($theirs['truck']['id'] === $setup['truck_id'][2], 'the second user exports the second truck');
    foreach (array_column($document['spots'], 'id') as $id) {
        $c->check(!str_contains($theirText, $id), 'no spot of the first user is in the second user\'s export');
    }
    $c->check(!str_contains($theirText, $setup['truck_id'][1]) && !str_contains($text, $setup['truck_id'][2]), 'no export names the other truck');

    // =====================================================================================
    // 3. delete everything, for a third account
    // =====================================================================================

    $email = 'tp-smoke-' . bin2hex(random_bytes(6)) . '@example.test';
    $c->as(0)->post('/api/auth/register', ['email' => $email, 'password' => 'Smoke-' . bin2hex(random_bytes(9)), 'name' => 'Truck Planner smoke 3'])
        ->status(201)->path('data.user.role', 'owner');
    $c->setToken(3, (string) $c->value('data.token'));
    $thirdOrganization = (string) $c->value('data.user.organization_id');
    $c->check($thirdOrganization !== '' && !in_array($thirdOrganization, array_column($state['users'], 'organization_id'), true), 'the third account is a third organization');

    // Before it has a truck: sources still answers, the export does not, and there is nothing to delete.
    $c->as(3)->get('/api/truck/sources')->status(200)->path('data.region', null)->path('data.dataset', null)->path('data.fuel', null);
    // (Line 11 is there while the time-of-day traffic table is not neutral.)
    $c->check(array_values(array_diff($checkLines($c, $c->value('data.attribution')), [11])) === [1, 2, 3, 6, 8, 9, 12], 'without a truck the lines that need no data are given');
    $c->get('/api/truck/export')->status(409)->path('error', 'Set up your truck first');
    $c->post('/api/truck/data/delete', ['confirm' => $phrase])->status(200)->path('data.deleted', $zeros)->path('message', 'Truck data deleted');

    $c->put('/api/truck/profile', ['name' => 'A truck to delete', 'base' => $setup['base'] + ['address' => ''], 'avg_ticket' => 12])->status(201);
    $c->post('/api/truck/spots', ['name' => 'A spot to delete', 'point' => ['lat' => $setup['base']['lat'], 'lng' => $setup['base']['lng']]])->status(201);
    $c->post('/api/truck/spots', ['name' => 'Another spot to delete', 'point' => ['lat' => 0.5, 'lng' => 0.5]])->status(201);
    $c->get('/api/truck/bootstrap')->status(200)->path('data.has_truck', true)->path('data.counts.spots', 2);
    [, $third] = $export($c);
    $c->check(count($third['spots']) === 2 && $third['truck']['profile']['name'] === 'A truck to delete', 'the third account exports what it just saved');

    // Refused without the exact phrase, and nothing is deleted.
    $c->sendRaw('POST', '/api/truck/data/delete', '')->status(422)->path('error', 'Request body must be a JSON object');
    foreach ([[], ['confirm' => 'Delete my truck data'], ['confirm' => $phrase . ' '], ['confirm' => 'delete'], ['confirm' => true], ['confirmation' => $phrase]] as $body) {
        $c->post('/api/truck/data/delete', $body)->status(422)
            ->path('success', false)
            ->path('error', 'confirm must be exactly: ' . $phrase)
            ->path('details.field', 'confirm');
    }
    $c->as(0)->allow(401)->post('/api/truck/data/delete', ['confirm' => $phrase])->status(401);
    $c->as(3)->get('/api/truck/bootstrap')->status(200)->path('data.has_truck', true)->path('data.counts.spots', 2);

    // With it, everything of that organization is gone.
    $c->post('/api/truck/data/delete', ['confirm' => $phrase])->status(200)
        ->path('success', true)
        ->path('message', 'Truck data deleted')
        ->path('data.deleted', ['services' => 0, 'plan_stops' => 0, 'plans' => 0, 'leads' => 0, 'drive_overrides' => 0, 'spots' => 2, 'trucks' => 1]);
    $c->check(array_keys((array) $c->value('data.deleted')) === array_keys($zeros), 'the seven counts come in the order the rows were deleted');
    $c->get('/api/truck/bootstrap')->status(200)
        ->path('data.has_truck', false)
        ->path('data.truck', null)
        ->path('data.counts', ['spots' => 0, 'plans' => 0, 'services' => 0, 'leads' => 0]);
    $c->get('/api/truck/spots')->status(409)->path('error', 'Set up your truck first');
    $c->get('/api/truck/export')->status(409)->path('error', 'Set up your truck first');
    $c->get('/api/auth/me')->status(200)->path('data.user.email', $email)->path('data.user.organization_id', $thirdOrganization);
    // Again: nothing is left, and that is an answer, not an error.
    $c->post('/api/truck/data/delete', ['confirm' => $phrase])->status(200)->path('data.deleted', $zeros);

    // The other two organizations have what they had.
    foreach ([1, 2] as $n) {
        $c->as($n)->get('/api/truck/bootstrap')->status(200)
            ->path('data.has_truck', true)
            ->path('data.truck', $before[$n]['truck'])
            ->path('data.counts', $before[$n]['counts']);
    }
    $c->as(1)->get('/api/truck/spots/' . $spots['first'])->status(200)->path('data.spot.id', $spots['first']);

    $state['data'] = ['deleted_organization' => $thirdOrganization, 'exported_spots' => count($document['spots'])];
};
