// End to end on the committed fixture test/fixtures/mini: Falls Church city, Virginia (164 real census blocks,
// their real jobs, and the OpenStreetMap elements of the area) plus a strip of neighbouring blocks for the halo.

import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { after, before, test } from 'node:test';
import zlib from 'node:zlib';
import { getResolution, isValidCell } from 'h3-js';
import { scanCsv } from '../src/csv.mjs';
import { gateLabel } from '../src/gates.mjs';
import { buildRegion } from '../src/pipeline.mjs';
import { REVIEW_COLUMNS } from '../src/review.mjs';
import { PIPELINE_VERSION } from '../src/vocabulary.mjs';
import { readZipMember } from '../src/zip.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PACKAGE = path.resolve(HERE, '..');
const MINI = path.join(HERE, 'fixtures', 'mini');
const REAL_SEEDS = path.resolve(PACKAGE, '..', '..', 'docs', 'truck-planner', 'reference', 'tp_seeds.json');
const expected = JSON.parse(fs.readFileSync(path.join(MINI, 'expected.json'), 'utf8'));
const FILES = ['points.tsv', 'places.ndjson', 'cells.tsv', 'job_review.csv', 'manifest.json'];
const sha = (buf) => crypto.createHash('sha256').update(buf).digest('hex');

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'truck-etl-e2e-'));
after(() => fs.rmSync(tmp, { recursive: true, force: true }));

/** A private copy of the fixture's raw cache: a build writes index.json into the raw directory. */
function rawCopy(name) {
  const dir = path.join(tmp, name);
  fs.cpSync(path.join(MINI, 'raw'), dir, { recursive: true });
  return dir;
}

function options(rawDir, outName, extra = {}) {
  return {
    regionFile: path.join(MINI, 'mini.json'), rawDir, outDir: path.join(tmp, outName), seedsFile: path.join(MINI, 'seeds.json'),
    correctionsFile: path.join(MINI, 'mini.jobs.json'), offline: true, ...extra,
  };
}

let first;
let second;
before(async () => {
  first = await buildRegion(options(rawCopy('raw-a'), 'out-a'));
  second = await buildRegion(options(rawCopy('raw-b'), 'out-b'));
});

const tsv = (text) => text.trimEnd().split('\n').map((line) => line.split('\t'));

test('G18 determinism: two builds of the same inputs give byte-identical files', () => {
  assert.equal(first.ok, true);
  assert.equal(second.ok, true);
  assert.equal(first.datasetVersion, second.datasetVersion);
  assert.equal(path.basename(first.outputDir), first.datasetVersion);
  for (const name of FILES) {
    const a = fs.readFileSync(path.join(first.outputDir, name));
    const b = fs.readFileSync(path.join(second.outputDir, name));
    assert.ok(a.equals(b), `${name} differs between two runs`);
    assert.equal(a.toString('utf8'), first.files[name]);
    assert.ok(!a.includes(0x0d), `${name} holds a carriage return`);
    assert.equal(a[a.length - 1], 0x0a, `${name} has no final newline`);
    assert.notDeepEqual([a[0], a[1], a[2]], [0xef, 0xbb, 0xbf], `${name} starts with a byte order mark`);
  }
  assert.deepEqual(fs.readdirSync(first.outputDir).sort(), [...FILES].sort());
});

test('the result matches the recorded expectation', () => {
  const m = first.manifest;
  const byFile = Object.fromEntries(m.outputs.map((o) => [o.file, o]));
  for (const o of expected.outputs) {
    if (o.file === 'cells.tsv') continue;
    assert.deepEqual(byFile[o.file], o, o.file);
  }
  // cells.tsv depends on exp() and trigonometry: identical bytes on the reference runtime, else equal within 1e-9
  const cellsExpected = fs.readFileSync(path.join(MINI, 'expected-cells.tsv'), 'utf8');
  if (first.files['cells.tsv'] === cellsExpected) {
    assert.equal(first.datasetVersion, expected.dataset_version);
  } else {
    const a = tsv(first.files['cells.tsv']);
    const b = tsv(cellsExpected);
    assert.equal(a.length, b.length);
    a.forEach((row, i) => {
      assert.equal(row[0], b[i][0]);
      for (let c = 1; c < 4; c++) {
        if (i === 0) assert.equal(row[c], b[i][c]);
        else assert.ok(Math.abs(Number(row[c]) - Number(b[i][c])) <= 1e-9 * Math.abs(Number(b[i][c])), `cells.tsv row ${i} column ${c}`);
      }
    });
  }
  assert.deepEqual(m.counts.points, expected.counts.points);
  assert.equal(m.counts.places.total, expected.counts.places_total);
  assert.equal(m.counts.places.in_region, expected.counts.places_in_region);
  assert.deepEqual({ candidates: m.counts.cells.candidates, kept: m.counts.cells.kept }, expected.counts.cells);
  assert.deepEqual({ residents: m.totals.residents, jobs: m.totals.jobs, jobs_spread: m.totals.jobs_spread, jobs_discarded: m.totals.jobs_discarded }, expected.totals);
  assert.deepEqual(first.gates.filter((g) => !g.pass).map((g) => ({ gate: gateLabel(g), level: g.level })), expected.gates_not_passed);
});

test('totals agree with an independent reading of the raw files and with the official county counts', () => {
  // Falls Church city, 2020 Census: 14,658 residents, 6,172 housing units, 164 blocks. LODES 2023: 10,691 jobs.
  const geo = readZipMember(fs.readFileSync(path.join(MINI, 'raw', 'census', 'pl2020', 'va2020.pl.zip')), 'vageo2020.pl').toString('latin1');
  let residents = 0;
  let housing = 0;
  let blocks = 0;
  let allBlocks = 0;
  for (const line of geo.split('\n')) {
    const f = line.split('|');
    if (f[2] !== '750') continue;
    allBlocks++;
    if (!f[9].startsWith('51610')) continue;
    blocks++;
    residents += Number(f[90]);
    housing += Number(f[91]);
  }
  assert.deepEqual([residents, housing, blocks], [14658, 6172, 164]);
  const wac = zlib.gunzipSync(fs.readFileSync(path.join(MINI, 'raw', 'lodes8', 'va', 'va_wac_S000_JT00_2023.csv.gz'))).toString('utf8');
  let jobs = 0;
  let construction = 0;
  for (const line of wac.trimEnd().split('\n').slice(1)) {
    const f = line.split(',');
    if (!f[0].startsWith('51610')) continue;
    jobs += Number(f[1]);
    construction += Number(f[11]); // CNS04
  }
  assert.equal(jobs, 10691);

  const m = first.manifest;
  assert.deepEqual([m.totals.residents, m.totals.housing_units, m.totals.jobs, m.counts.blocks.region, m.counts.blocks.state], [14658, 6172, 10691, 164, allBlocks]);
  assert.equal(m.totals.jobs_by_sector.CNS04, construction);
  assert.deepEqual(m.totals.by_county, [{ fips: '51610', residents: 14658, housing_units: 6172, jobs: 10691, blocks: 164 }]);

  // points.tsv: residents of the region rows, and the worker bases = raw jobs, construction at 0.3, less what was discarded
  const rows = tsv(first.files['points.tsv']).slice(1);
  let rowResidents = 0;
  let rowWorkers = 0;
  for (const r of rows) {
    if (r[3] !== '1' || r[1] !== 'block') continue;
    rowResidents += Number(r[7]);
    for (let c = 8; c <= 14; c++) rowWorkers += Number(r[c]);
  }
  assert.equal(rowResidents, 14658);
  // the one discarding entry caps a retail sector (weight 1), so the discarded jobs count in full
  const expectedWorkers = jobs - 0.7 * construction - m.totals.jobs_discarded - m.totals.jobs_spread_lost;
  assert.ok(Math.abs(rowWorkers - expectedWorkers) <= 1e-9 * expectedWorkers, `${rowWorkers} != ${expectedWorkers}`);
  assert.ok(Math.abs(m.totals.base_by_segment.res - 14658) === 0);
});

test('points.tsv: order, shape, halo', () => {
  const lines = first.files['points.tsv'].trimEnd().split('\n');
  assert.equal(lines[0].split('\t').length, 23);
  const rows = lines.slice(1).map((l) => l.split('\t'));
  assert.equal(rows.length, first.manifest.outputs[0].rows);
  const ids = rows.map((r) => r[0]);
  assert.deepEqual(ids, ids.slice().sort()); // byte order
  assert.equal(new Set(ids).size, ids.length);
  for (const r of rows) {
    assert.equal(r.length, 23);
    assert.match(r[0], /^(b\d{15}|p[nwr]\d+)$/);
    assert.equal(r[0].slice(1), r[2]);
    assert.equal(r[1], r[0][0] === 'b' ? 'block' : 'place');
    assert.ok(r[3] === '0' || r[3] === '1');
    assert.ok(Number(r[5]) >= 38.868 && Number(r[5]) <= 38.904 && Number(r[6]) >= -77.2 && Number(r[6]) <= -77.1); // the fetch box
    assert.ok(r.slice(7).every((v) => Number(v) >= 0 && Number.isFinite(Number(v))));
    assert.ok(r.slice(7).some((v) => Number(v) > 0), `${r[0]} has no base`);
    if (r[1] === 'block') {
      assert.equal(r[3], r[2].startsWith('51610') ? '1' : '0'); // membership is the GEOID prefix
      assert.ok(r.slice(15).every((v) => v === '0'));
    } else {
      assert.equal(r.slice(7).filter((v) => v !== '0').length, 1); // one visitor segment
    }
    assert.ok((r[5].split('.')[1] || '').length <= 7 && (r[6].split('.')[1] || '').length <= 7);
  }
  const c = first.manifest.counts.points;
  assert.equal(rows.filter((r) => r[1] === 'block' && r[3] === '1').length, c.block);
  assert.equal(rows.filter((r) => r[1] === 'place' && r[3] === '1').length, c.place);
  assert.equal(rows.filter((r) => r[1] === 'block' && r[3] === '0').length, c.halo_block);
  assert.equal(rows.filter((r) => r[1] === 'place' && r[3] === '0').length, c.halo_place);
  assert.ok(c.halo_block > 0 && c.halo_place > 0);
  // the strip of outside blocks reaches beyond the halo: some of them are not kept
  assert.ok(c.halo_block < first.manifest.counts.blocks.state - first.manifest.counts.blocks.region);
  // corrected blocks are marked
  assert.ok(rows.filter((r) => r[4] === '1').length >= 3);
});

test('places.ndjson: order, keys, roles, and the link to the source points', () => {
  const places = first.files['places.ndjson'].trimEnd().split('\n').map((l) => JSON.parse(l));
  const keys = places.map((p) => p.place_key);
  assert.deepEqual(keys, keys.slice().sort());
  assert.equal(new Set(keys).size, keys.length);
  for (const p of places) {
    assert.equal(Object.keys(p).length, 26);
    assert.equal(p.place_key, { node: 'n', way: 'w', relation: 'r' }[p.osm_type] + p.osm_id);
    assert.equal(p.in_region === 1, p.county_fips === '51610');
    assert.ok(p.in_region === 1 || p.county_fips === null);
    assert.equal(p.visitor_segment === null, p.size_default === 0);
    if (p.in_region === 0) assert.ok(p.rival_kind !== null || p.visitor_segment !== null, `${p.place_key}: a halo place is a rival or a visitor source`);
    if (p.hours_mask !== null) assert.match(p.hours_mask, /^[0-9a-f]{42}$/);
    if (p.phone !== null) assert.match(p.phone, /^\+1[2-9]\d{2}[2-9]\d{6}$/);
    if (p.name === null) assert.equal(p.host_fit, 0);
  }
  const pointIds = new Set(tsv(first.files['points.tsv']).slice(1).map((r) => r[0]));
  const visitorKeys = places.filter((p) => p.visitor_segment !== null).map((p) => `p${p.place_key}`);
  assert.deepEqual(visitorKeys.sort(), [...pointIds].filter((id) => id.startsWith('p')).sort());

  // real places of Falls Church
  const byName = (name) => places.find((p) => p.name === name);
  const park = byName('Cavalier Trail Park');
  assert.deepEqual([park.place_key, park.place_type, park.geom_kind, park.in_region, park.visitor_segment, park.size_default, park.host_fit, park.kitchen],
    ['w29655755', 'park', 'area', 1, 'v_leisure', 38, 0.4, 'no']);
  assert.deepEqual([park.lat, park.lng, park.city, park.state_code, park.postcode], [38.8806467, -77.1796236, 'Falls Church', 'VA', '22046']);
  const mall = byName('Seven Corners Shopping Center'); // in Fairfax County: outside the region, kept in the halo
  assert.deepEqual([mall.place_key, mall.in_region, mall.county_fips, mall.visitor_segment, mall.phone], ['r3465838', 0, null, 'v_shopping', '+13019866200']);
  const cafe = byName('Little Falls Cafe');
  assert.deepEqual([cafe.place_type, cafe.rival_kind, cafe.cuisine, cafe.geom_kind], ['restaurant', 'full', 'crepe', 'point']);
  const m = first.manifest.counts;
  assert.equal(places.filter((p) => p.in_region === 1).length, m.places.in_region);
  assert.equal(places.filter((p) => p.in_region === 1 && p.rival_kind !== null).length, m.rivals.total);
  assert.equal(places.filter((p) => p.in_region === 1 && p.host_fit > 0).length, m.hosts.total);
});

test('cells.tsv: valid resolution-9 cells in order, each passing a pruning test', () => {
  const rows = tsv(first.files['cells.tsv']);
  assert.deepEqual(rows[0], ['h3', 'lat', 'lng', 'nearby_etl']);
  const cells = rows.slice(1);
  const ids = cells.map((r) => r[0]);
  assert.deepEqual(ids, ids.slice().sort());
  for (const r of cells) {
    assert.match(r[0], /^[0-9a-f]{15}$/);
    assert.ok(isValidCell(r[0]) && getResolution(r[0]) === 9);
    assert.ok(Number(r[3]) > 0);
    assert.ok(r[3].replace(/[-.]/g, '').replace(/^0+/, '').length <= 12);
  }
  const m = first.manifest.counts.cells;
  assert.equal(cells.length, m.kept);
  assert.ok(m.kept < m.candidates);
  // a cell is kept on nearby people (>= 100) or on venue visitors alone
  assert.equal(cells.filter((r) => Number(r[3]) < 100).length, m.kept_by_venue_test_only);
});

test('job_review.csv: every treatment of the fixture corrections file', () => {
  const lines = first.files['job_review.csv'].trimEnd().split('\n');
  const rows = [];
  const { header } = scanCsv(Buffer.from(first.files['job_review.csv']), null, (values) => {
    rows.push(Object.fromEntries(values.map((v, i) => [REVIEW_COLUMNS[i], v])));
  });
  assert.deepEqual(header, [...REVIEW_COLUMNS]);
  assert.equal(header.length, 29);
  const corrections = JSON.parse(fs.readFileSync(path.join(MINI, 'mini.jobs.json'), 'utf8')).entries;
  const byGeoid = Object.fromEntries(rows.map((r) => [r.geoid, r]));
  assert.equal(rows.length, 7); // six flagged blocks and the orphan entry
  for (const [geoid, entry] of Object.entries(corrections)) assert.equal(byGeoid[geoid].treatment, entry.action);
  const treatments = rows.map((r) => r.treatment).sort();
  assert.deepEqual(treatments, ['auto_spread', 'auto_spread', 'cap', 'drop', 'keep', 'spread', 'spread']);
  assert.deepEqual(rows.map((r) => r.entry_state).sort(), ['', '', 'ok', 'ok', 'ok', 'orphan', 'stale']);
  assert.equal(rows[rows.length - 1].entry_state, 'orphan'); // orphans come last
  const c000 = rows.slice(0, -1).map((r) => Number(r.c000));
  assert.deepEqual(c000, c000.slice().sort((a, b) => b - a)); // descending
  const keep = rows.find((r) => r.treatment === 'keep');
  assert.deepEqual([keep.jobs_spread, keep.jobs_discarded, keep.manual, keep.confirmed, keep.cap], ['0', '0', '1', '1', '']);
  assert.ok(Number(keep.jobs_after) >= Number(keep.c000)); // kept, and it may receive spread jobs
  const capped = rows.find((r) => r.treatment === 'cap');
  assert.equal(Number(capped.jobs_discarded), first.manifest.totals.jobs_discarded);
  assert.equal(capped.cap, '50');
  const spread = rows.filter((r) => r.treatment === 'spread' || r.treatment === 'auto_spread');
  assert.equal(spread.reduce((a, r) => a + Number(r.jobs_spread), 0), first.manifest.totals.jobs_spread);
  assert.equal(first.manifest.totals.blocks_adjusted, 4);
  for (const r of rows.slice(0, -1)) {
    assert.match(r.map_url, /^https:\/\/www\.google\.com\/maps\/search\/\?api=1&query=38\.\d+%2C-77\.\d+$/);
    assert.equal(r.county_fips, '51610');
  }
  assert.ok(lines.some((l) => l.includes('"Falls Church city, VA"'))); // crosswalk labels, quoted because of the comma
});

test('manifest.json: identity, inputs, sources, gates and outputs', () => {
  const m = JSON.parse(first.files['manifest.json']);
  assert.deepEqual([m.schema, m.region_id, m.pipeline_version, m.model_version, m.dataset_version], [1, 'mini', PIPELINE_VERSION, 'tps-0.1.0', first.datasetVersion]);
  assert.match(m.dataset_version, /^mini-20261003-[0-9a-f]{8}$/);
  assert.deepEqual(m.region, JSON.parse(fs.readFileSync(path.join(MINI, 'mini.json'), 'utf8')));
  assert.deepEqual(m.bounds, { lat_min: 38.87247, lng_min: -77.195, lat_max: 38.89989, lng_max: -77.1497 });
  assert.equal(m.inputs.region_file_sha256, sha(fs.readFileSync(path.join(MINI, 'mini.json'))));
  assert.equal(m.inputs.seeds_sha256, sha(fs.readFileSync(path.join(MINI, 'seeds.json'))));
  assert.equal(m.inputs.corrections_sha256, sha(fs.readFileSync(path.join(MINI, 'mini.jobs.json'))));
  assert.deepEqual([m.inputs.seeds_revision, m.inputs.corrections_version, m.inputs.places_source], [1, '2026-10-05.1', 'geofabrik']);
  assert.deepEqual(m.vintages, {
    census_reference_date: '2020-04-01', lodes_year: 2023, lodes_format: '8.4', lodes_vintage: '20251202_1657',
    osm_snapshot_date: '2026-10-03', osm_replication_timestamp: '2026-10-03T20:20:50Z',
  });
  assert.deepEqual(m.sources.map((s) => s.kind), ['census_pl', 'lodes_wac', 'lodes_xwalk', 'lodes_version', 'tigerweb', 'osm_pbf']);
  for (const s of m.sources) assert.equal(s.sha256, sha(fs.readFileSync(path.join(MINI, 'raw', ...s.path.split('/')))));
  for (const o of m.outputs) {
    const bytes = fs.readFileSync(path.join(first.outputDir, o.file));
    assert.deepEqual([o.bytes, o.sha256], [bytes.length, sha(bytes)]);
  }
  assert.deepEqual(m.outputs.map((o) => o.file), ['points.tsv', 'places.ndjson', 'cells.tsv', 'job_review.csv']);
  assert.ok(m.gates.length >= 50);
  assert.ok(m.gates.every((g) => g.level !== 'fail' || g.pass === true));
  assert.ok(!JSON.stringify(m).match(/20\d\d-\d\d-\d\dT\d\d:\d\d:\d\d\.\d/)); // no run timestamp
  // places read = places kept + every dropped and merged counter
  const c = m.counts;
  const dropped = Object.entries(c).filter(([k]) => /^(dropped|merged)_/.test(k)).reduce((a, [, v]) => a + v, 0);
  assert.equal(c.elements_read, c.places.total + dropped);
  assert.ok(c.dropped_outside_box > 0 && c.dropped_outside_region > 0 && c.dropped_unnamed > 0 && c.dropped_closed > 0 && c.dropped_no_rule > 0 && c.dropped_relation_type > 0);
});

test('the fixture also builds with the repository seed file', async () => {
  const result = await buildRegion(options(rawCopy('raw-c'), 'out-c', { seedsFile: REAL_SEEDS, write: false }));
  assert.equal(result.outputDir, null);
  assert.equal(result.manifest.totals.residents, 14658);
  assert.ok(result.gates.filter((g) => g.level === 'fail').every((g) => g.pass || g.id === 'G12' || g.id === 'G17'),
    JSON.stringify(result.gates.filter((g) => !g.pass)));
});

/**
 * A stand-in for the download hosts: answers the real source addresses of the mini region with the fixture's raw
 * files, honours conditional requests, and notes whether a request ever started before the previous body was read.
 */
function fakeHosts() {
  const raw = (rel) => fs.readFileSync(path.join(MINI, 'raw', ...rel.split('/')));
  const PL = 'https://www2.census.gov/programs-surveys/decennial/2020/data/01-Redistricting_File--PL_94-171/Virginia/va2020.pl.zip';
  const LODES = 'https://lehd.ces.census.gov/data/lodes/LODES8/va';
  const GEOFABRIK = 'https://download.geofabrik.de/north-america/us';
  const TIGERWEB = 'https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/State_County/MapServer/1/query';
  const served = new Map([
    [PL, { body: raw('census/pl2020/va2020.pl.zip'), lastModified: 'Thu, 12 Aug 2021 13:35:00 GMT' }],
    [`${LODES}/version.txt`, { body: raw('lodes8/va/version.txt'), lastModified: 'Tue, 02 Dec 2025 21:57:00 GMT' }],
    [`${LODES}/lodes_va.sha256sum`, { body: raw('lodes8/va/lodes_va.sha256sum'), lastModified: 'Wed, 03 Dec 2025 13:05:59 GMT' }],
    [`${LODES}/wac/va_wac_S000_JT00_2023.csv.gz`, { body: raw('lodes8/va/va_wac_S000_JT00_2023.csv.gz'), etag: '"wac-1"', lastModified: 'Tue, 02 Dec 2025 22:10:00 GMT' }],
    [`${LODES}/va_xwalk.csv.gz`, { body: raw('lodes8/va/va_xwalk.csv.gz'), etag: '"xwalk-1"', lastModified: 'Tue, 02 Dec 2025 22:11:00 GMT' }],
    [TIGERWEB, { body: raw('tigerweb/counties_mini.geojson'), etag: '"counties-1"', lastModified: null }],
    [`${GEOFABRIK}/virginia-261003.osm.pbf.md5`, { body: raw('geofabrik/virginia-261003.osm.pbf.md5'), lastModified: 'Sun, 04 Oct 2026 01:20:00 GMT' }],
    [`${GEOFABRIK}/virginia-261003.osm.pbf`, { body: raw('geofabrik/virginia-261003.osm.pbf'), etag: '"pbf-1"', lastModified: 'Sun, 04 Oct 2026 01:19:00 GMT' }],
  ]);
  const hosts = { requests: [], overlapped: false };
  let unread = 0;
  hosts.fetchImpl = async (url, init = {}) => {
    const method = init.method || 'GET';
    const headers = init.headers || {};
    if (unread > 0) hosts.overlapped = true;
    hosts.requests.push({ method, url, address: url.split('?')[0], headers, redirect: init.redirect });
    if (method === 'HEAD' && url === `${GEOFABRIK}/virginia-latest.osm.pbf`) {
      return new Response(null, { status: 307, headers: { Location: `${GEOFABRIK}/virginia-261003.osm.pbf` } });
    }
    const entry = served.get(url.split('?')[0]);
    if (!entry || method !== 'GET') return new Response('not found', { status: 404 });
    const answer = { 'Content-Length': String(entry.body.length) };
    if (entry.etag) answer.ETag = entry.etag;
    if (entry.lastModified) answer['Last-Modified'] = entry.lastModified;
    const current = entry.etag ? headers['If-None-Match'] === entry.etag : (entry.lastModified !== null && headers['If-Modified-Since'] === entry.lastModified);
    if (current) return new Response(null, { status: 304, headers: answer });
    unread++;
    let sent = false;
    const body = new ReadableStream({
      pull(controller) {
        if (!sent) { sent = true; controller.enqueue(new Uint8Array(entry.body)); return; }
        unread--;
        controller.close();
      },
    });
    return new Response(body, { status: 200, headers: answer });
  };
  return hosts;
}

test('an online build fetches each file once and in order, and gives the same files as the offline build', async () => {
  const hosts = fakeHosts();
  const rawDir = path.join(tmp, 'raw-online'); // empty: everything is downloaded
  const online = (outName) => buildRegion(options(rawDir, outName, { offline: false, contact: 'ops@example.test', fetchImpl: hosts.fetchImpl }));
  const built = await online('out-online');
  assert.equal(built.ok, true);
  assert.deepEqual(hosts.requests.map((r) => `${r.method} ${r.address}`), [
    'GET https://www2.census.gov/programs-surveys/decennial/2020/data/01-Redistricting_File--PL_94-171/Virginia/va2020.pl.zip',
    'GET https://lehd.ces.census.gov/data/lodes/LODES8/va/version.txt',
    'GET https://lehd.ces.census.gov/data/lodes/LODES8/va/lodes_va.sha256sum',
    'GET https://lehd.ces.census.gov/data/lodes/LODES8/va/wac/va_wac_S000_JT00_2023.csv.gz',
    'GET https://lehd.ces.census.gov/data/lodes/LODES8/va/va_xwalk.csv.gz',
    'GET https://tigerweb.geo.census.gov/arcgis/rest/services/TIGERweb/State_County/MapServer/1/query',
    'HEAD https://download.geofabrik.de/north-america/us/virginia-latest.osm.pbf',
    'GET https://download.geofabrik.de/north-america/us/virginia-261003.osm.pbf.md5',
    'GET https://download.geofabrik.de/north-america/us/virginia-261003.osm.pbf',
  ]);
  for (const r of hosts.requests) {
    assert.equal(r.headers['User-Agent'], 'TruckPlanner-ETL/1.0.0 (contact: ops@example.test)');
    assert.equal(r.redirect, 'manual');
  }
  assert.equal(hosts.overlapped, false); // one request at a time
  assert.match(hosts.requests[5].url, /\?where=GEOID%20IN%20\('51610'\)&outFields=GEOID,NAME,AREALAND,INTPTLAT,INTPTLON&returnGeometry=true&outSR=4326&geometryPrecision=5&f=geojson$/);

  // the cache holds the fixture's files under the names of section 3, no partial file, and one index entry each
  const cached = [];
  const walk = (dir, rel) => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      if (e.isDirectory()) walk(path.join(dir, e.name), `${rel}${e.name}/`);
      else cached.push(rel + e.name);
    }
  };
  walk(rawDir, '');
  const index = JSON.parse(fs.readFileSync(path.join(rawDir, 'index.json'), 'utf8'));
  assert.deepEqual(cached.sort(), ['index.json', ...index.map((e) => e.path)].sort());
  assert.equal(index.length, 8);
  for (const e of index) {
    const bytes = fs.readFileSync(path.join(rawDir, ...e.path.split('/')));
    assert.ok(bytes.equals(fs.readFileSync(path.join(MINI, 'raw', ...e.path.split('/')))), e.path);
    assert.deepEqual([e.http_status, e.bytes, e.sha256], [200, bytes.length, sha(bytes)]);
  }
  const pbfEntry = index.find((e) => e.path === 'geofabrik/virginia-261003.osm.pbf');
  assert.deepEqual([pbfEntry.url, pbfEntry.final_url, pbfEntry.etag], ['https://download.geofabrik.de/north-america/us/virginia-latest.osm.pbf',
    'https://download.geofabrik.de/north-america/us/virginia-261003.osm.pbf', '"pbf-1"']);

  // the four data files are those of the offline build; the manifest differs only in what the downloads recorded
  assert.equal(built.datasetVersion, first.datasetVersion);
  for (const name of ['points.tsv', 'places.ndjson', 'cells.tsv', 'job_review.csv']) assert.equal(built.files[name], first.files[name], name);
  const withoutDownloadFacts = (m) => ({ ...m, sources: m.sources.map((s) => ({ ...s, final_url: null, last_modified: null })) });
  assert.deepEqual(withoutDownloadFacts(built.manifest), withoutDownloadFacts(first.manifest));
  const sources = Object.fromEntries(built.manifest.sources.map((s) => [s.kind, s]));
  assert.deepEqual([sources.osm_pbf.final_url, sources.osm_pbf.last_modified], ['https://download.geofabrik.de/north-america/us/virginia-261003.osm.pbf', 'Sun, 04 Oct 2026 01:19:00 GMT']);
  assert.deepEqual([sources.census_pl.final_url, sources.census_pl.last_modified], [sources.census_pl.url, 'Thu, 12 Aug 2021 13:35:00 GMT']);
  assert.equal(sources.tigerweb.last_modified, null);

  // a second run asks again only for what can change upstream, with a conditional request, and builds the same bytes
  hosts.requests.length = 0;
  const again = await online('out-online-2');
  assert.deepEqual(hosts.requests.map((r) => [r.method, r.address.split('/').pop(), r.headers['If-None-Match'] || r.headers['If-Modified-Since'] || null]), [
    ['GET', 'version.txt', 'Tue, 02 Dec 2025 21:57:00 GMT'],
    ['GET', 'lodes_va.sha256sum', 'Wed, 03 Dec 2025 13:05:59 GMT'],
    ['GET', 'va_wac_S000_JT00_2023.csv.gz', '"wac-1"'],
    ['GET', 'va_xwalk.csv.gz', '"xwalk-1"'],
    ['GET', 'query', '"counties-1"'],
    ['HEAD', 'virginia-latest.osm.pbf', null],
  ]);
  assert.equal(hosts.overlapped, false);
  for (const name of FILES) assert.equal(again.files[name], built.files[name], name);
  assert.deepEqual(JSON.parse(fs.readFileSync(path.join(rawDir, 'index.json'), 'utf8')), index);
});

function cli(args) {
  return spawnSync(process.execPath, [path.join(PACKAGE, 'bin', 'build-region.mjs'), ...args], { encoding: 'utf8', env: { ...process.env, TP_CONTACT_EMAIL: '', TP_RAW_DIR: '' } });
}

test('command line: exit 0 on success, 2 on a failed gate, 1 on usage and I/O errors', () => {
  const raw = rawCopy('raw-cli');
  const common = ['--region-file', path.join(MINI, 'mini.json'), '--raw-dir', raw, `--seeds=${path.join(MINI, 'seeds.json')}`,
    `--corrections=${path.join(MINI, 'mini.jobs.json')}`, '--offline'];
  const ok = cli([...common, '--out', path.join(tmp, 'cli-out')]);
  assert.equal(ok.status, 0, ok.stderr);
  assert.match(ok.stdout, new RegExp(`dataset_version {2}${first.datasetVersion}`));
  assert.match(ok.stdout, /gates +\d+ passed, 4 warnings, 0 failed/);
  assert.match(ok.stdout, /WARN G15\.unconfirmed/);
  for (const name of FILES) {
    assert.ok(fs.readFileSync(path.join(tmp, 'cli-out', first.datasetVersion, name)).equals(fs.readFileSync(path.join(first.outputDir, name))), name);
  }
  // --out-dir is the parent of all regions: <out-dir>/<region>/<dataset_version>/
  const parent = cli([...common, `--out-dir=${path.join(tmp, 'cli-parent')}`]);
  assert.equal(parent.status, 0, parent.stderr);
  assert.ok(fs.existsSync(path.join(tmp, 'cli-parent', 'mini', first.datasetVersion, 'manifest.json')));

  // a wrong check value: the residents gate fails, the files go to _failed
  const badRegion = JSON.parse(fs.readFileSync(path.join(MINI, 'mini.json'), 'utf8'));
  badRegion.checks.residents += 1;
  const badFile = path.join(tmp, 'mini-bad.json');
  fs.writeFileSync(badFile, JSON.stringify(badRegion));
  const failed = cli([...common.slice(2), '--region-file', badFile, '--out', path.join(tmp, 'cli-bad')]);
  assert.equal(failed.status, 2, failed.stderr);
  assert.match(failed.stdout, /FAIL G7\.residents/);
  assert.deepEqual(fs.readdirSync(path.join(tmp, 'cli-bad')), ['_failed']);
  const failedManifest = JSON.parse(fs.readFileSync(path.join(tmp, 'cli-bad', '_failed', 'manifest.json'), 'utf8'));
  assert.deepEqual(failedManifest.gates.filter((g) => g.level === 'fail' && !g.pass).map((g) => [gateLabel(g), g.value, g.expected]),
    [['G7.residents', 14658, 14659], ['G8.residents', 14658, 14659]]);

  // usage and I/O errors
  assert.equal(cli([]).status, 1);
  assert.equal(cli(['--region=nowhere']).status, 1);
  assert.equal(cli([...common, '--out', 'x', '--out-dir', 'y']).status, 1);
  assert.equal(cli([...common, '--frobnicate']).status, 1);
  const missing = cli([...common.slice(0, 2), '--raw-dir', path.join(tmp, 'empty-raw'), ...common.slice(4), '--out', path.join(tmp, 'cli-none')]);
  assert.equal(missing.status, 1);
  assert.match(missing.stderr, /is missing and --offline forbids downloads/);
  // without --offline and without a contact address nothing can be requested: a missing file stops the run ...
  const online = common.filter((a) => a !== '--offline');
  const noContact = cli([...online.slice(0, 2), '--raw-dir', path.join(tmp, 'empty-raw-2'), ...online.slice(4), '--out', path.join(tmp, 'cli-none-2')]);
  assert.equal(noContact.status, 1);
  assert.match(noContact.stderr, /is missing and cannot be downloaded without a contact address: pass --contact=<email> or set TP_CONTACT_EMAIL/);
  assert.equal(fs.existsSync(path.join(tmp, 'cli-none-2')), false);
  // ... and a complete raw cache is built as with --offline, with a notice
  const quiet = cli([...online, '--out', path.join(tmp, 'cli-quiet')]);
  assert.equal(quiet.status, 0, quiet.stderr);
  assert.match(quiet.stderr, /no contact address \(--contact or TP_CONTACT_EMAIL\): nothing is requested, the raw cache is used as with --offline/);
  assert.match(quiet.stdout, /raw cache +.*\(no contact address, nothing requested; 0 requests\)/);
  assert.match(ok.stdout, /raw cache +.*\(--offline; 0 requests\)/);
  for (const name of FILES) {
    assert.ok(fs.readFileSync(path.join(tmp, 'cli-quiet', first.datasetVersion, name)).equals(fs.readFileSync(path.join(first.outputDir, name))), name);
  }
  assert.equal(cli(['--help']).status, 0);
});

test('a corrupt raw file fails gate G1 with exit 2 and writes nothing', () => {
  const raw = rawCopy('raw-corrupt');
  const wac = path.join(raw, 'lodes8', 'va', 'va_wac_S000_JT00_2023.csv.gz');
  const original = zlib.gunzipSync(fs.readFileSync(wac)).toString('utf8');
  const csv = original.replace('20251202', '20251203'); // one digit of one row
  assert.notEqual(csv, original);
  fs.writeFileSync(wac, zlib.gzipSync(Buffer.from(csv)));
  const result = cli(['--region-file', path.join(MINI, 'mini.json'), '--raw-dir', raw, `--seeds=${path.join(MINI, 'seeds.json')}`,
    `--corrections=${path.join(MINI, 'mini.jobs.json')}`, '--offline', '--out', path.join(tmp, 'cli-corrupt')]);
  assert.equal(result.status, 2, result.stderr);
  assert.match(result.stderr, /gate G1 failed: .*fails its SHA-256 check/);
  assert.equal(fs.existsSync(path.join(tmp, 'cli-corrupt')), false);
});
