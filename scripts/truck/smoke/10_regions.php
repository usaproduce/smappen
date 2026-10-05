<?php
declare(strict_types=1);

use App\TruckPlanner\Model\Seeds;
use App\TruckPlanner\Services\CellPackWriter;

/**
 * Smoke step 10: the regions and the cell pack (routes 7 and 8).
 *
 *   1. GET /api/truck/regions lists every region with the fields of RegionInfo. Neither route needs a truck.
 *   2. A region that cannot be used carries no pack. A pack that does not exist answers 404 in the envelope.
 *   3. For every usable region the pack answers 200 with the right length: gzip-encoded, cached for good,
 *      with a validator. The bytes are the pack the region announces (length and SHA-256), a cell pack of
 *      format 1 whose header names this region, this dataset version and this model, and whose sections
 *      read back: ascending cell ids, 50 columns, every code on its column's scale.
 *   4. The same request with If-None-Match answers 304 without a body. Without gzip the same bytes arrive
 *      plain, under a validator of their own.
 *
 * With --no-region the checks of 3 and 4 are skipped: nothing is loaded.
 *
 * For the later steps: $state['regions'] = ['usable' => [RegionInfo, ...]] (empty with --no-region).
 */
return function (SmokeClient $c, array &$state): void {
    $infoKeys = ['region_id', 'name', 'timezone', 'h3_res', 'center', 'bbox', 'dataset_version', 'usable', 'unusable_reason', 'pack', 'vintages', 'counties'];

    // ---- 1. the list
    $c->as(1)->get('/api/truck/regions')->status(200)->path('success', true);
    $regions = $c->value('data.regions');
    $c->check(is_array($regions) && array_is_list($regions), 'data.regions is a list');
    $usable = [];
    $previousId = '';
    foreach ($regions as $i => $region) {
        $c->check(is_array($region) && array_keys($region) === $infoKeys, 'regions.' . $i . ' has the fields of RegionInfo, in order');
        $c->check(strcmp((string) $region['region_id'], $previousId) > 0, 'the regions come in ascending id');
        $previousId = (string) $region['region_id'];
        $c->finite('data.regions.' . $i . '.center.lat', 'data.regions.' . $i . '.center.lng', 'data.regions.' . $i . '.h3_res')
            ->finite('data.regions.' . $i . '.bbox.lat_min', 'data.regions.' . $i . '.bbox.lng_min', 'data.regions.' . $i . '.bbox.lat_max', 'data.regions.' . $i . '.bbox.lng_max');
        $c->check(is_array($region['counties']) && $region['counties'] !== [], 'a region lists its counties');

        // ---- 2. usable or not
        if ($region['usable'] === true) {
            $c->check($region['unusable_reason'] === null && is_array($region['pack']) && is_string($region['dataset_version']), 'a usable region has a pack and no reason');
            $c->check(
                $region['pack']['url'] === '/api/truck/regions/' . $region['region_id'] . '/pack/' . $region['dataset_version'],
                'pack.url is the path of route 8 for the live version'
            );
            $c->check(is_array($region['vintages']) && isset($region['vintages']['census_reference_date'], $region['vintages']['lodes_year'], $region['vintages']['osm_snapshot_date']), 'a usable region shows its vintages');
            $usable[] = $region;
        } else {
            $c->check($region['usable'] === false && $region['pack'] === null, 'a region that cannot be used carries no pack');
            $c->check(in_array($region['unusable_reason'], ['not_loaded', 'build_mismatch'], true), 'and says why: not_loaded or build_mismatch');
        }
    }
    // the same list for another tenant: reference data belongs to no organization
    $c->as(2)->get('/api/truck/regions')->status(200);
    $c->check($c->value('data.regions') === $regions, 'both tenants see the same regions');

    // a pack that does not exist, and ids that are not ids
    foreach (['nowhere/pack/nowhere-00000000-00000000', 'nowhere/pack/Not_A_Version', 'NOWHERE/pack/nowhere-00000000-00000000'] as $tail) {
        $c->as(1)->header('Accept-Encoding', 'gzip')->get('/api/truck/regions/' . $tail)
            ->status(404)->path('success', false)->path('error', 'Not found');
        $c->check($c->responseHeader('Cache-Control') === null || !str_contains((string) $c->responseHeader('Cache-Control'), 'immutable'), 'an error answer is never cached for good');
    }

    $state['regions'] = ['usable' => $usable];
    if ($state['no_region']) {
        return;
    }
    $c->check($usable !== [], 'a region is loaded and usable (run with --no-region when none is)');

    // ---- 3. the pack of every usable region
    foreach ($usable as $region) {
        $id = $region['region_id'];
        $version = $region['dataset_version'];
        $pack = $region['pack'];
        // a version of the region that is not loaded
        $c->as(1)->get('/api/truck/regions/' . $id . '/pack/' . $id . '-00000000-00000000')->status(404)->path('error', 'Not found');

        $c->as(1)->header('Accept-Encoding', 'gzip')->get($pack['url'])->status(200);
        $gz = $c->body();
        $etag = (string) $c->responseHeader('ETag');
        $c->check($c->responseHeader('Content-Encoding') === 'gzip', $id . ': the pack is served gzip-encoded');
        $c->check($c->responseHeader('Content-Type') === 'application/octet-stream', $id . ': Content-Type application/octet-stream');
        $c->check($c->responseHeader('Cache-Control') === 'private, max-age=31536000, immutable', $id . ': cached for good, privately');
        $c->check($etag === '"' . substr($pack['sha256'], 0, 32) . '-gz"', $id . ': the validator is the start of the pack hash, marked gzip');
        $c->check(str_contains(strtolower((string) $c->responseHeader('Vary')), 'accept-encoding'), $id . ': Vary names Accept-Encoding');
        $c->check(!str_contains(strtolower((string) $c->responseHeader('Vary')), 'authorization'), $id . ': a new login must not invalidate the cached pack');
        $c->check($c->responseHeader('X-Content-Type-Options') === 'nosniff', $id . ': nosniff');
        $c->check(strlen($gz) === $pack['gz_bytes'] && (int) $c->responseHeader('Content-Length') === $pack['gz_bytes'], $id . ': the body has the announced compressed length');

        $bytes = gzdecode($gz);
        $c->check(is_string($bytes) && strlen($bytes) === $pack['bytes'], $id . ': the pack has the announced length');
        $bytes = (string) $bytes;
        $c->check(hash('sha256', $bytes) === $pack['sha256'], $id . ': the pack has the announced SHA-256');
        $c->check(substr($bytes, 0, 4) === CellPackWriter::MAGIC, $id . ': the bytes start with TPCP');

        try {
            $layout = CellPackWriter::layout($bytes);
            $ids = CellPackWriter::ids($bytes, $layout);
        } catch (Throwable $e) {
            $c->check(false, $id . ': the pack cannot be read: ' . $e->getMessage());
            return;
        }
        $header = $layout['header'];
        $c->check($pack['format_version'] === CellPackWriter::FORMAT_VERSION && $header['format_version'] === CellPackWriter::FORMAT_VERSION, $id . ': format version 1');
        $c->check($header['format'] === CellPackWriter::FORMAT, $id . ': format tp-cell-pack');
        $c->check($header['region_id'] === $id && $header['dataset_version'] === $version, $id . ': the header names the region and the version of the address');
        $c->check($header['model_version'] === Seeds::defaults()['model_version'], $id . ': the pack was built for this model');
        $c->check($layout['n'] === $pack['cell_count'] && $header['cell_count'] === $pack['cell_count'] && $layout['n'] > 0, $id . ': the announced number of cells');
        $c->check($layout['k'] === CellPackWriter::COLUMNS && $header['columns'] === CellPackWriter::columnNames(), $id . ': the 50 columns in pack order');
        $c->check($header['segments'] === CellPackWriter::segments(), $id . ': the 16 segments in shared order');
        $c->check($header['h3_res'] === $region['h3_res'], $id . ': the resolution of the region');
        $c->check(
            ($header['vintages']['census_reference_date'] ?? null) === $region['vintages']['census_reference_date']
            && ($header['vintages']['lodes_year'] ?? null) === $region['vintages']['lodes_year']
            && ($header['vintages']['osm_snapshot_date'] ?? null) === $region['vintages']['osm_snapshot_date'],
            $id . ': the vintages of the legend'
        );
        $c->check(is_array($header['attribution'] ?? null) && ($header['attribution'][0] ?? '') === '© OpenStreetMap contributors', $id . ': the attribution of the place data');
        foreach (['earth_radius_m', 'walk_decay_m', 'walk_cutoff_m', 'a0', 'visibility', 'regime_of_hour', 'rival_weights'] as $key) {
            $c->check(isset($header['kernel'][$key]), $id . ': the kernel block has ' . $key);
        }

        $ascending = true;
        foreach ($ids as $i => $cell) {
            if (preg_match('/^[0-9a-f]{15}$/', $cell) !== 1 || ($i > 0 && strcmp($cell, $ids[$i - 1]) <= 0)) {
                $ascending = false;
                break;
            }
        }
        $c->check($ascending, $id . ': the cell ids are 15 hexadecimal characters, ascending');

        // every column on its own scale: nothing above it, its largest value exact, an empty column all zero
        $onScale = true;
        $people = 0.0;
        for ($j = 0; $j < $layout['k']; $j++) {
            $scale = (float) $header['scale'][$j];
            $column = CellPackWriter::column($bytes, $j, $layout);
            $largest = max($column);
            if (count($column) !== $layout['n'] || min($column) < 0.0 || $largest !== $scale) {
                $onScale = false;
            }
            if ($j >= 32 && $j < 48) {
                $people += $scale;
            }
        }
        $c->check($onScale, $id . ': every column decodes onto its scale, with its largest value exact');
        $c->check($people > 0.0, $id . ': the cells hold people nearby');

        // another tenant gets the same bytes
        $c->as(2)->header('Accept-Encoding', 'gzip')->get($pack['url'])->status(200);
        $c->check($c->body() === $gz, $id . ': the pack is the same for every tenant');

        // ---- 4. the browser already holds it
        $c->as(1)->header('Accept-Encoding', 'gzip')->header('If-None-Match', $etag)->get($pack['url'])->status(304);
        $c->check($c->body() === '', $id . ': 304 has no body');
        $c->check($c->responseHeader('ETag') === $etag, $id . ': 304 repeats the validator');
        $c->check($c->responseHeader('Cache-Control') === 'private, max-age=31536000, immutable', $id . ': 304 repeats the caching rule');
        $c->as(1)->header('Accept-Encoding', 'gzip')->header('If-None-Match', '"something-else", ' . $etag)->get($pack['url'])->status(304);
        $c->as(1)->header('Accept-Encoding', 'gzip')->header('If-None-Match', '"something-else"')->get($pack['url'])->status(200);

        // without gzip: the same bytes, plain, under a validator of their own
        $c->as(1)->get($pack['url'])->status(200);
        $c->check($c->responseHeader('Content-Encoding') === null, $id . ': no encoding when gzip is not accepted');
        $c->check($c->body() === $bytes, $id . ': the plain body is the pack');
        $c->check((int) $c->responseHeader('Content-Length') === $pack['bytes'], $id . ': the plain length');
        $plainTag = '"' . substr($pack['sha256'], 0, 32) . '"';
        $c->check($c->responseHeader('ETag') === $plainTag, $id . ': the plain validator carries no gzip mark');
        $c->as(1)->header('If-None-Match', $plainTag)->get($pack['url'])->status(304);
        // the validator of the other encoding is not this one's
        $c->as(1)->header('If-None-Match', $etag)->get($pack['url'])->status(200);
    }
};
