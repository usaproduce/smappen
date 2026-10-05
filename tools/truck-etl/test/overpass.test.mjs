import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { after, test } from 'node:test';
import zlib from 'node:zlib';
import {
  OverpassError, fetchOverpassTiles, listTiles, overpassQuery, readOverpassDocument, readOverpassTile, splitTile, tileGrid,
} from '../src/overpass.mjs';
import { RULES, classify } from '../src/taxonomy.mjs';

const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'truck-etl-ov-'));
after(() => fs.rmSync(tmp, { recursive: true, force: true }));

const select = (type, tags) => { const r = classify(type, tags); return r.type ? r : null; };
const doc = (elements, extra = {}) => ({
  version: 0.6, generator: 'Overpass API', osm3s: { timestamp_osm_base: '2026-10-05T01:05:12Z', copyright: 'ODbL' }, elements, ...extra,
});

test('tile reader: nodes, bounds, centre fallback, missing geometry, the fetch box', () => {
  // Shapes follow real responses of the source recon (out tags center) and of the documented query (out tags bb).
  const out = readOverpassDocument(doc([
    { type: 'node', id: 3413156622, lat: 38.9455121, lon: -77.4516722, tags: { amenity: 'cafe', name: 'Starbucks' } },
    { type: 'way', id: 264230766, bounds: { minlat: 38.9680001, minlon: -77.4143, maxlat: 38.9684, maxlon: -77.4140002 }, tags: { building: 'office', name: 'Leasing Office' } },
    { type: 'way', id: 5, center: { lat: 38.9681974, lon: -77.4141855 }, tags: { leisure: 'park', name: 'Centre only' } },
    { type: 'relation', id: 6, bounds: { minlat: 38.9, minlon: -77.5, maxlat: 38.91, maxlon: -77.49 }, tags: { type: 'multipolygon', landuse: 'retail', name: 'Plaza' } },
    { type: 'relation', id: 7, bounds: { minlat: 38.9, minlon: -77.5, maxlat: 38.91, maxlon: -77.49 }, tags: { type: 'site', amenity: 'university', name: 'Site relation' } },
    { type: 'way', id: 8, tags: { leisure: 'park', name: 'No geometry' } },
    { type: 'node', id: 9, lat: 40.5, lon: -77.4, tags: { amenity: 'cafe' } },
    { type: 'node', id: 10, lat: 38.9, lon: -77.4, tags: { highway: 'crossing' } },
    { type: 'node', id: 11, lat: 38.9, lon: -77.4 },
    { type: 'way', id: 12, center: { lat: 38.9, lon: -77.4 }, tags: { building: 'yes' } },
  ]), { select, box: { south: 38, west: -78, north: 39, east: -77 } });
  assert.equal(out.timestamp, '2026-10-05T01:05:12Z');
  assert.deepEqual(out.stats, { nodes: 4, ways: 4, relations: 2, triggered: 8, noGeometry: 1, outsideBox: 1 });
  const byKey = Object.fromEntries(out.elements.map((e) => [`${e.osmType}/${e.id}`, e]));
  assert.deepEqual(Object.keys(byKey), ['node/3413156622', 'way/264230766', 'way/5', 'relation/6']);
  assert.deepEqual([byKey['node/3413156622'].latE7, byKey['node/3413156622'].lngE7, byKey['node/3413156622'].box], [389455121, -774516722, null]);
  // bounds: the centre formula of the PBF reader, floor((min + max + 1) / 2)
  assert.deepEqual(byKey['way/264230766'].box, { minLatE7: 389680001, minLngE7: -774143000, maxLatE7: 389684000, maxLngE7: -774140002 });
  assert.deepEqual([byKey['way/264230766'].latE7, byKey['way/264230766'].lngE7], [389682001, -774141501]);
  // centre only: rounded to 1e-7 degrees
  assert.deepEqual([byKey['way/5'].latE7, byKey['way/5'].lngE7, byKey['way/5'].box], [389681974, -774141855, null]);
  assert.equal(byKey['relation/6'].payload.type, 'shopping_centre');
});

test('tile reader: a remark or a missing timestamp fails', () => {
  assert.throws(() => readOverpassDocument(doc([], { remark: 'runtime error: Query timed out in "query" at line 9 after 72 seconds.' }), { select }),
    /carries a remark: runtime error: Query timed out/);
  assert.throws(() => readOverpassDocument({ elements: [] }, { select }), /timestamp_osm_base/);
  assert.throws(() => readOverpassDocument({ osm3s: {} }, { select }), OverpassError);
});

test('tile files: gzip JSON named t*.json.gz, read in name order', () => {
  const dir = path.join(tmp, 'tiles');
  fs.mkdirSync(dir);
  const tile = (name, elements) => fs.writeFileSync(path.join(dir, name), zlib.gzipSync(JSON.stringify(doc(elements))));
  tile('t10.json.gz', [{ type: 'node', id: 1, lat: 38.9, lon: -77.4, tags: { amenity: 'bar' } }]);
  tile('t01.json.gz', []);
  fs.writeFileSync(path.join(dir, 'log.txt'), 'not a tile');
  fs.writeFileSync(path.join(dir, 'broken.json.gz'), 'not a tile either'); // does not start with t
  assert.deepEqual(listTiles(dir).map((f) => path.basename(f)), ['t01.json.gz', 't10.json.gz']);
  assert.equal(readOverpassTile(path.join(dir, 't10.json.gz'), { select }).elements.length, 1);
  fs.writeFileSync(path.join(dir, 't99.json.gz'), 'plain text');
  assert.throws(() => readOverpassTile(path.join(dir, 't99.json.gz'), { select }), /not gzipped JSON/);
  assert.throws(() => listTiles(path.join(tmp, 'absent')), OverpassError);
});

test('the Overpass query asks for every tag value the taxonomy can match', () => {
  const q = overpassQuery({ south: 37.95, west: -78.4, north: 39.75, east: -76.62 });
  assert.ok(q.startsWith('[out:json][timeout:60][bbox:37.95,-78.4,39.75,-76.62];\n(\n'));
  assert.ok(q.endsWith(');\nout tags bb qt;\n'));
  // one element per rule alternative, classified, must be selected by a line of the query
  const samples = [
    { amenity: 'restaurant' }, { amenity: 'fast_food' }, { amenity: 'food_court' }, { amenity: 'cafe' }, { amenity: 'ice_cream' }, { amenity: 'bar' },
    { amenity: 'pub' }, { amenity: 'biergarten' }, { amenity: 'hospital' }, { amenity: 'university' }, { amenity: 'college' }, { amenity: 'bus_station' },
    { amenity: 'events_venue' }, { amenity: 'conference_centre' }, { amenity: 'exhibition_centre' }, { amenity: 'theatre' }, { amenity: 'cinema' },
    { amenity: 'arts_centre' }, { amenity: 'marketplace' }, { craft: 'brewery' }, { craft: 'winery' }, { craft: 'distillery' }, { craft: 'cidery' },
    { microbrewery: 'yes' }, { shop: 'deli' }, { shop: 'bakery' }, { shop: 'pastry' }, { shop: 'coffee' }, { shop: 'convenience' }, { shop: 'supermarket' },
    { shop: 'mall' }, { shop: 'department_store' }, { shop: 'wholesale' }, { shop: 'doityourself' }, { shop: 'furniture' }, { shop: 'garden_centre' },
    { shop: 'car' }, { healthcare: 'hospital' }, { railway: 'station' }, { public_transport: 'station' }, { leisure: 'stadium' },
    { leisure: 'fitness_centre' }, { leisure: 'sports_centre' }, { leisure: 'sports_hall' }, { leisure: 'ice_rink' }, { leisure: 'park' },
    { leisure: 'water_park' }, { tourism: 'hotel' }, { tourism: 'museum' }, { tourism: 'attraction' }, { tourism: 'theme_park' }, { tourism: 'zoo' },
    { landuse: 'retail' }, { landuse: 'industrial' }, { landuse: 'commercial' }, { building: 'apartments' }, { building: 'industrial' },
    { building: 'warehouse' }, { building: 'office' }, { residential: 'apartments' }, { residential: 'condominium' }, { industrial: 'depot' },
    { man_made: 'works' }, { office: 'company' },
  ];
  const lines = q.split('\n').filter((l) => /^\s+(nwr|way|relation)\[/.test(l));
  assert.equal(lines.length, 16);
  const selects = (tags) => lines.some((line) => {
    const m = /\["([a-z_]+)"(?:~"\^\(([^)]*)\)\$"|="([^"]*)")?\]/.exec(line);
    const [, key, alternatives, exact] = m;
    if (!(key in tags)) return false;
    if (alternatives) return alternatives.split('|').includes(tags[key]);
    return exact === undefined || exact === tags[key];
  });
  for (const tags of samples) {
    assert.ok(classify('way', { ...tags, name: 'N' }).type, JSON.stringify(tags));
    assert.ok(selects(tags), `the query misses ${JSON.stringify(tags)}`);
  }
  assert.equal(RULES.length, 22);
});

test('tile grid and splitting', () => {
  const tiles = tileGrid({ south: 37.95, west: -78.4, north: 39.75, east: -76.62 }, 4, 4);
  assert.deepEqual(tiles.map((t) => t.name), ['t00', 't01', 't02', 't03', 't10', 't11', 't12', 't13', 't20', 't21', 't22', 't23', 't30', 't31', 't32', 't33']);
  assert.deepEqual([tiles[0].box.south, tiles[0].box.west, tiles[15].box.north, tiles[15].box.east], [37.95, -78.4, 39.75, -76.62]);
  // neighbours share their edges exactly: no gap and no overlap
  assert.equal(tiles[0].box.east, tiles[1].box.west);
  assert.equal(tiles[0].box.north, tiles[4].box.south);
  const four = splitTile(tiles[5]);
  assert.deepEqual(four.map((t) => t.name), ['t11a', 't11b', 't11c', 't11d']);
  assert.deepEqual([four[0].box.south, four[0].box.west, four[3].box.north, four[3].box.east],
    [tiles[5].box.south, tiles[5].box.west, tiles[5].box.north, tiles[5].box.east]);
  assert.equal(four[0].box.east, four[1].box.west);
  assert.equal(four[0].box.north, four[2].box.south);
});

test('fetching tiles: one request at a time, back-off on 429 and 504, split on a remark', async () => {
  const outDir = path.join(tmp, 'fetched');
  const calls = [];
  const sleeps = [];
  let active = 0;
  const answers = {
    t00: [429, 504, 200],
    t01: ['remark'],
    t01a: [200], t01b: [200], t01c: [200], t01d: [200],
  };
  const fetchImpl = async (url, init) => {
    assert.equal(active, 0, 'requests must not overlap');
    active++;
    const query = decodeURIComponent(init.body.replace(/^data=/, ''));
    const bbox = /\[bbox:([^\]]+)\]/.exec(query)[1];
    const name = Object.keys(boxes).find((n) => boxes[n] === bbox);
    calls.push([name, url, init.method, init.headers['User-Agent']]);
    const answer = answers[name].shift();
    await Promise.resolve();
    active--;
    if (answer === 'remark') return new Response(JSON.stringify(doc([], { remark: 'runtime error: Query run out of memory' })), { status: 200 });
    if (answer !== 200) return new Response('busy', { status: answer });
    return new Response(JSON.stringify(doc([{ type: 'node', id: calls.length, lat: 38.9, lon: -77.4, tags: { amenity: 'cafe' } }])), { status: 200 });
  };
  const tiles = tileGrid({ south: 38, west: -78, north: 39, east: -77 }, 1, 2);
  const boxes = {};
  const boxText = (b) => `${b.south},${b.west},${b.north},${b.east}`;
  for (const t of tiles) boxes[t.name] = boxText(t.box);
  for (const t of splitTile(tiles[1])) boxes[t.name] = boxText(t.box);

  const result = await fetchOverpassTiles({
    tiles, outDir, userAgent: 'TruckPlanner-ETL/1.0.0 (contact: dev@example.test)', fetchImpl, sleep: async (s) => { sleeps.push(s); },
  });
  assert.deepEqual(calls.map((c) => c[0]), ['t00', 't00', 't00', 't01', 't01a', 't01b', 't01c', 't01d']);
  assert.deepEqual(calls[0].slice(1), ['https://overpass-api.de/api/interpreter', 'POST', 'TruckPlanner-ETL/1.0.0 (contact: dev@example.test)']);
  assert.deepEqual(sleeps, [30, 60]); // at least 30 s after 429 or 504
  assert.deepEqual(result.written.map((f) => path.basename(f)), ['t00.json.gz', 't01a.json.gz', 't01b.json.gz', 't01c.json.gz', 't01d.json.gz']);
  assert.deepEqual([result.requests, result.waits, result.splits], [8, 2, 1]);
  // what was written is what the tile reader reads
  assert.deepEqual(listTiles(outDir).map((f) => path.basename(f)), ['t00.json.gz', 't01a.json.gz', 't01b.json.gz', 't01c.json.gz', 't01d.json.gz']);
  assert.equal(readOverpassTile(path.join(outDir, 't00.json.gz'), { select }).elements.length, 1);
});

test('fetching tiles: any other status stops the run, and so does a tile that is refused every time', async () => {
  const tiles = tileGrid({ south: 38, west: -78, north: 39, east: -77 }, 1, 1);
  const base = { tiles, outDir: path.join(tmp, 'stop'), userAgent: 'ua', sleep: async () => {} };
  await assert.rejects(fetchOverpassTiles({ ...base, fetchImpl: async () => new Response('Not Acceptable', { status: 406 }) }), /HTTP 406 for tile t00/);
  await assert.rejects(fetchOverpassTiles({ ...base, fetchImpl: async () => new Response('busy', { status: 429 }), maxAttempts: 3 }), /refused 3 times/);
  await assert.rejects(fetchOverpassTiles({ ...base, fetchImpl: async () => new Response('<html>', { status: 200 }) }), /did not answer JSON/);
  await assert.rejects(fetchOverpassTiles({
    ...base, maxSplits: 1, fetchImpl: async () => new Response(JSON.stringify(doc([], { remark: 'timed out' })), { status: 200 }),
  }), /still carries a remark after 1 splits/);
  assert.equal(fs.existsSync(path.join(tmp, 'stop', 't00.json.gz')), false);
});
