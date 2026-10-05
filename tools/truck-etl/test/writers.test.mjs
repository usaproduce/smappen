import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { TREATMENT_AUTO_SPREAD, TREATMENT_KEEP, TREATMENT_SPREAD } from '../src/corrections.mjs';
import { ATTRIBUTION, buildManifest, datasetVersion, manifestParameters, renderManifest } from '../src/manifest.mjs';
import { loadRegion } from '../src/region.mjs';
import { REVIEW_COLUMNS, buildReviewRows, mapUrl, topSectors } from '../src/review.mjs';
import { loadSeeds } from '../src/seeds.mjs';
import { PIPELINE_VERSION, PLACE_TYPES } from '../src/vocabulary.mjs';
import {
  CELL_COLUMNS, PLACE_KEYS, POINT_COLUMNS, describeOutput, renderCells, renderPlaces, renderPoints, renderReview, sha256Hex,
} from '../src/writers.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));

test('points.tsv: 23 columns, header, number formatting', () => {
  assert.equal(POINT_COLUMNS.length, 23);
  assert.deepEqual(POINT_COLUMNS.slice(0, 9), ['point_id', 'src_kind', 'src_ref', 'in_region', 'job_adj', 'lat', 'lng', 'b_res', 'b_w_office']);
  assert.equal(POINT_COLUMNS[22], 'b_v_lodging');
  const base = new Float64Array(16);
  base[0] = 607; base[1] = 56; base[5] = 1.5; base[7] = 1 / 3; base[2] = 1e-7; base[3] = -0;
  const text = renderPoints([
    { id: 'b110010001011000', kind: 'block', ref: '110010001011000', inRegion: 1, jobAdj: 0, lat: 38.9100683, lng: -77.0528631, base },
    { id: 'pw264230766', kind: 'place', ref: 'w264230766', inRegion: 0, jobAdj: 0, lat: 38.9681974, lng: -77.4141855, base: new Float64Array(16) },
  ]);
  const lines = text.split('\n');
  assert.equal(lines.length, 4);
  assert.equal(lines[3], ''); // final newline
  assert.equal(lines[0], POINT_COLUMNS.join('\t'));
  assert.equal(lines[1], 'b110010001011000\tblock\t110010001011000\t1\t0\t38.9100683\t-77.0528631\t607\t56\t1e-7\t0\t0\t1.5\t0\t0.3333333333333333\t0\t0\t0\t0\t0\t0\t0\t0');
  assert.equal(lines[2].split('\t').length, 23);
  assert.ok(lines[2].startsWith('pw264230766\tplace\tw264230766\t0\t0\t38.9681974\t-77.4141855\t0\t'));
  assert.ok(!text.includes('\r'));
  assert.throws(() => renderPoints([{ id: 'x', kind: 'block', ref: 'x', inRegion: 1, jobAdj: 0, lat: NaN, lng: 0, base }]), /not a finite number/);
});

test('places.ndjson: the example line of 03_DATA.md 8.2, key order, nulls', () => {
  assert.equal(PLACE_KEYS.length, 26);
  const place = {
    hours_mask: 'f0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1f', // fields given out of order on purpose
    place_key: 'n3413156622', osm_type: 'node', osm_id: 3413156622, place_type: 'cafe', geom_kind: 'point', in_region: 1, county_fips: '51107',
    name: 'Starbucks', brand: 'Starbucks', lat: 38.9455121, lng: -77.4516722, rival_kind: 'cafe', visitor_segment: null, size_default: 0,
    host_fit: 0, kitchen: 'yes', phone: '+13017428261',
    website: 'https://www.starbucks.com/store-locator/store/12403/iad-terminal-d-gate-d-15-44844-aviation-dr-sterling-va-20166-us',
    addr_line: '44844 Aviation Drive', city: 'Sterling', state_code: 'VA', postcode: '20166', cuisine: 'coffee_shop', opening_hours_raw: '04:30-21:00',
    tags: { 'brand:wikidata': 'Q37158', takeaway: 'yes' }, phoneRaw: true, websiteRaw: true,
  };
  const expected = '{"place_key":"n3413156622","osm_type":"node","osm_id":"3413156622","place_type":"cafe","geom_kind":"point","in_region":1,"county_fips":"51107","name":"Starbucks","brand":"Starbucks","lat":38.9455121,"lng":-77.4516722,"rival_kind":"cafe","visitor_segment":null,"size_default":0,"host_fit":0,"kitchen":"yes","phone":"+13017428261","website":"https://www.starbucks.com/store-locator/store/12403/iad-terminal-d-gate-d-15-44844-aviation-dr-sterling-va-20166-us","addr_line":"44844 Aviation Drive","city":"Sterling","state_code":"VA","postcode":"20166","cuisine":"coffee_shop","opening_hours_raw":"04:30-21:00","hours_mask":"f0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1ff0ff1f","tags":{"brand:wikidata":"Q37158","takeaway":"yes"}}';
  const text = renderPlaces([place, { ...place, place_key: 'w1', osm_type: 'way', osm_id: 1, name: 'Café "Zoë"\n', tags: null, county_fips: null, in_region: 0 }]);
  const lines = text.split('\n');
  assert.equal(lines[0], expected);
  assert.equal(lines.length, 3);
  const second = JSON.parse(lines[1]);
  assert.deepEqual(Object.keys(second), [...PLACE_KEYS]);
  assert.deepEqual([second.name, second.tags, second.county_fips, second.in_region, second.osm_id], ['Café "Zoë"\n', null, null, 0, '1']);
  assert.ok(lines[1].includes('Café')); // UTF-8, not escaped
});

test('cells.tsv: columns and number formatting', () => {
  assert.deepEqual(CELL_COLUMNS, ['h3', 'lat', 'lng', 'nearby_etl']);
  const text = renderCells([
    { h3: '892aaab3043ffff', lat: 38.96975058361889, lng: -77.38547127693856, nearby: 1234.5678901234567 },
    { h3: '892aaab3047ffff', lat: 38.97, lng: -77.39, nearby: 100 },
  ]);
  assert.equal(text, 'h3\tlat\tlng\tnearby_etl\n892aaab3043ffff\t38.96975058361889\t-77.38547127693856\t1234.56789012\n892aaab3047ffff\t38.97\t-77.39\t100\n');
});

test('job_review.csv: header, quoting, numbers', () => {
  assert.equal(REVIEW_COLUMNS.length, 29);
  const row = Object.fromEntries(REVIEW_COLUMNS.map((c) => [c, '']));
  Object.assign(row, {
    geoid: '510594525011000', county_name: 'Fairfax County, VA', lat: 38.8019059, lng: -77.1754695, c000: 39466, top1_share: 0.9726, jobs_after: 500,
    jobs_spread: 38966, cap: 500, manual: 1, confirmed: 0, reason: 'A "payroll" address,\nsecond line',
  });
  const lines = renderReview([row]).split('\n');
  assert.equal(lines[0], REVIEW_COLUMNS.join(','));
  assert.ok(lines[1].startsWith('510594525011000,,"Fairfax County, VA",,,38.8019059,-77.1754695,'));
  assert.ok(renderReview([row]).endsWith(',"A ""payroll"" address,\nsecond line"\n'));
});

test('describeOutput and sha256Hex', () => {
  const text = 'h\na\nb\n';
  assert.deepEqual(describeOutput('x.tsv', text, 2), {
    file: 'x.tsv', bytes: 6, sha256: crypto.createHash('sha256').update(text).digest('hex'), rows: 2,
  });
  assert.equal(describeOutput('x', 'café\n', 1).bytes, 6); // UTF-8 bytes, not characters
  assert.equal(sha256Hex(''), 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
});

test('dataset_version: region, snapshot date and the first 8 hex of the hash of six lines', () => {
  const hashes = { points: 'a'.repeat(64), places: 'b'.repeat(64), cells: 'c'.repeat(64), review: 'd'.repeat(64) };
  const lines = `${'a'.repeat(64)}\n${'b'.repeat(64)}\n${'c'.repeat(64)}\n${'d'.repeat(64)}\ntps-0.1.0\n1\n`;
  const h8 = crypto.createHash('sha256').update(lines).digest('hex').slice(0, 8);
  assert.equal(datasetVersion('dc', '2026-10-03', hashes, 'tps-0.1.0', 1), `dc-20261003-${h8}`);
  // the model version and the seeds revision change the version although no file changed
  assert.notEqual(datasetVersion('dc', '2026-10-03', hashes, 'tps-0.1.0', 2), `dc-20261003-${h8}`);
  assert.notEqual(datasetVersion('dc', '2026-10-03', hashes, 'tps-0.2.0', 1), `dc-20261003-${h8}`);
  assert.notEqual(datasetVersion('dc', '2026-10-03', { ...hashes, cells: 'e'.repeat(64) }, 'tps-0.1.0', 1), `dc-20261003-${h8}`);
  assert.match(datasetVersion('dc', '2026-10-03', hashes, 'tps-0.1.0', 1), /^[a-z0-9-]{1,48}$/);
  assert.throws(() => datasetVersion('a-very-long-region-identifier-that-does-not-fit-at-all', '2026-10-03', hashes, 'tps-0.1.0', 1), /not valid/);
});

test('manifest: key order, parameters, attribution, rendering', () => {
  const seeds = loadSeeds(path.join(HERE, 'fixtures', 'mini', 'seeds.json'));
  const region = loadRegion(path.join(HERE, 'fixtures', 'mini', 'mini.json')).doc;
  const parameters = manifestParameters(seeds, region);
  assert.deepEqual(Object.keys(parameters), [
    'walk_decay_m', 'walk_cutoff_m', 'earth_radius_m', 'cns04_weight', 'cell_min_nearby', 'cell_min_venue', 'segment_cns', 'place_types', 'h3_res', 'job_review',
  ]);
  assert.deepEqual([parameters.walk_decay_m, parameters.walk_cutoff_m, parameters.earth_radius_m, parameters.cns04_weight, parameters.cell_min_nearby, parameters.cell_min_venue, parameters.h3_res],
    [400, 1200, 6371008.8, 0.3, 100, 15, 9]);
  assert.deepEqual(Object.keys(parameters.place_types), [...PLACE_TYPES]);
  assert.deepEqual(parameters.place_types.taproom, { visitor_segment: 'v_nightlife', default_size: 40, rival_kind: null, host_fit: 1, kitchen_default: 'no' });
  assert.deepEqual(parameters.segment_cns.w_public, ['CNS19', 'CNS20']);
  assert.deepEqual(parameters.job_review, region.job_review);

  const manifest = buildManifest({
    datasetVersion: 'mini-20261003-00000000', region, bounds: { lat_min: 1, lng_min: 2, lat_max: 3, lng_max: 4 },
    inputs: { model_version: 'tps-0.1.0', region_file_sha256: 'r', seeds_revision: 1, seeds_sha256: 's', corrections_version: 'c', corrections_sha256: 'h', places_source: 'geofabrik' },
    parameters, sources: [], vintages: { osm_snapshot_date: '2026-10-03' }, counts: {}, totals: {}, gates: [], outputs: [],
  });
  assert.deepEqual(Object.keys(manifest), [
    'schema', 'dataset_version', 'region_id', 'pipeline_version', 'model_version', 'region', 'bounds', 'inputs', 'parameters', 'sources', 'vintages',
    'counts', 'totals', 'gates', 'outputs', 'attribution',
  ]);
  assert.deepEqual([manifest.schema, manifest.region_id, manifest.pipeline_version, manifest.model_version], [1, 'mini', PIPELINE_VERSION, 'tps-0.1.0']);
  assert.equal(PIPELINE_VERSION, 'tp-etl-1.0.0');
  assert.deepEqual(Object.keys(manifest.inputs), ['region_file_sha256', 'seeds_revision', 'seeds_sha256', 'corrections_version', 'corrections_sha256', 'places_source']);
  assert.equal(manifest.region, region); // verbatim
  assert.deepEqual(Object.keys(manifest.attribution), ['osm', 'osm_long', 'residents', 'jobs', 'places', 'boundaries']);
  assert.equal(manifest.attribution.osm, '© OpenStreetMap contributors');
  assert.ok(manifest.attribution.places.includes('OpenStreetMap snapshot of 2026-10-03 (Geofabrik extracts)'));
  assert.ok(manifest.attribution.places.endsWith('on request: {contact}.'));
  assert.ok(manifest.attribution.jobs.includes('{blocks_adjusted} payroll-address blocks holding {jobs_spread} jobs'));
  assert.ok(ATTRIBUTION.places.includes('{osm_snapshot_date}')); // the template itself is not modified
  const text = renderManifest(manifest);
  assert.ok(text.startsWith('{\n  "schema": 1,\n  "dataset_version": "mini-20261003-00000000",\n'));
  assert.ok(text.endsWith('}\n'));
  assert.ok(!text.includes('\r'));
  assert.deepEqual(JSON.parse(text), manifest);
});

test('review rows: sectors, treatment, hint, order and orphans', () => {
  assert.equal(mapUrl(38.8019059, -77.1754695), 'https://www.google.com/maps/search/?api=1&query=38.8019059%2C-77.1754695');
  const raw = new Int32Array(40);
  raw[14] = 900; raw[11] = 60; raw[6] = 60; raw[0] = 5; // ties go to the lower sector number
  assert.deepEqual(topSectors(raw, 0), [{ sector: 'CNS15', jobs: 900 }, { sector: 'CNS07', jobs: 60 }, { sector: 'CNS12', jobs: 60 }]);

  // Three region blocks: a spread payroll address, a kept hospital, an unflagged block.
  const table = {
    geoid: ['510010000000001', '510010000000002', '510010000000003'],
    lat: Float64Array.from([38.9, 38.91, 38.92]), lng: Float64Array.from([-77.0, -77.0, -77.0]),
    residents: Int32Array.from([10, 0, 300]), landArea: Float64Array.from([1000, 2000, 3000]),
    hasWac: Uint8Array.from([1, 1, 1]), c000: Int32Array.from([1025, 7000, 20]), cns: new Int32Array(60),
  };
  table.cns.set(raw.subarray(0, 20), 0);
  table.cns[20 + 15] = 7000;
  table.cns[40 + 6] = 20;
  const corrected = Float64Array.from(table.cns);
  corrected[14] = 87.8; corrected[11] = 5.9; corrected[6] = 5.9; corrected[0] = 0.4; // block 1 cut to 100
  const result = {
    corrected,
    rule: ['B', 'A', ''],
    treatment: Uint8Array.from([TREATMENT_SPREAD, TREATMENT_KEEP, 0]),
    cap: Float64Array.from([100, NaN, NaN]),
    jobsSpread: Float64Array.from([925, 0, 0]),
    jobsDiscarded: Float64Array.from([0, 0, 0]),
    entryState: ['stale', 'ok', ''],
    orphans: ['519990000000009'],
  };
  const entries = new Map([
    ['510010000000001', { action: 'spread', cap: 100, confirmed: false, reason: 'School payroll address' }],
    ['510010000000002', { action: 'keep', cap: null, confirmed: true, reason: 'Hospital' }],
    ['519990000000009', { action: 'drop', cap: 0, confirmed: true, reason: 'Typing error?' }],
  ]);
  const labels = new Map([['510010000000001', { county: 'Test County, VA', place: 'Testville CDP, VA', military: '' }]]);
  const places = [
    { place_key: 'w5', place_type: 'hospital', name: 'General Hospital', lat: 38.9102, lng: -77.0 },
    { place_key: 'w6', place_type: 'campus', name: 'Far Campus', lat: 38.95, lng: -77.0 },
    { place_key: 'w7', place_type: 'park', name: 'Park at the door', lat: 38.9, lng: -77.0 },
  ];
  const rows = buildReviewRows({ table, regionBlocks: Int32Array.from([0, 1, 2]), result, entries, labels, places, earthRadiusM: 6371008.8 });
  assert.deepEqual(rows.map((r) => r.geoid), ['510010000000002', '510010000000001', '519990000000009']); // c000 descending, orphans last
  const [hospital, school, orphan] = rows;
  assert.deepEqual(Object.keys(school), [...REVIEW_COLUMNS]);
  assert.deepEqual(school, {
    geoid: '510010000000001', county_fips: '51001', county_name: 'Test County, VA', place_name: 'Testville CDP, VA', military_name: '',
    lat: 38.9, lng: -77, map_url: 'https://www.google.com/maps/search/?api=1&query=38.9%2C-77', residents: 10, land_area_m2: 1000, c000: 1025,
    top1_sector: 'CNS15', top1_jobs: 900, top1_share: 0.878, top2_sector: 'CNS07', top2_jobs: 60, top3_sector: 'CNS12', top3_jobs: 60,
    rule: 'B', treatment: 'spread', manual: 1, confirmed: 0, cap: 100, jobs_after: 100, jobs_spread: 925, jobs_discarded: 0, entry_state: 'stale',
    hint_place: '', reason: 'School payroll address',
  });
  assert.deepEqual([hospital.treatment, hospital.confirmed, hospital.cap, hospital.jobs_after, hospital.county_name], ['keep', 1, '', 7000, '']);
  assert.equal(hospital.hint_place, 'hospital: General Hospital (22 m)'); // 0.0002 degrees of latitude
  assert.deepEqual([orphan.treatment, orphan.entry_state, orphan.manual, orphan.confirmed, orphan.c000, orphan.lat, orphan.county_fips, orphan.cap],
    ['drop', 'orphan', 1, 1, '', '', '', 0]);

  // an automatic treatment has no entry: manual 0, confirmed empty
  result.treatment[2] = TREATMENT_AUTO_SPREAD;
  result.rule[2] = 'A';
  result.cap[2] = 5000;
  const auto = buildReviewRows({ table, regionBlocks: Int32Array.from([0, 1, 2]), result, entries, labels, places, earthRadiusM: 6371008.8 })
    .find((r) => r.geoid === '510010000000003');
  assert.deepEqual([auto.treatment, auto.manual, auto.confirmed, auto.cap, auto.entry_state, auto.reason], ['auto_spread', 0, '', 5000, '', '']);
});
