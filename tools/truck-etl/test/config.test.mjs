import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { parseArgs, UsageError } from '../src/args.mjs';
import { RegionError, countySet, loadRegion, validateRegion } from '../src/region.mjs';
import { SeedError, loadSeeds, seedsFromDocument } from '../src/seeds.mjs';
import { PLACE_TYPES, RIVAL_KINDS, SECTORS, SEGMENTS, WORKER_SEGMENTS, compareAscii } from '../src/vocabulary.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REAL_SEEDS = path.join(HERE, '..', '..', '..', 'docs', 'truck-planner', 'reference', 'tp_seeds.json');
const MINI_SEEDS = path.join(HERE, 'fixtures', 'mini', 'seeds.json');
const clone = (x) => JSON.parse(JSON.stringify(x));

test('shared vocabulary', () => {
  assert.deepEqual(SEGMENTS, ['res', 'w_office', 'w_health', 'w_edu', 'w_retail', 'w_industrial', 'w_hospitality', 'w_public',
    'v_nightlife', 'v_shopping', 'v_leisure', 'v_campus', 'v_hospital', 'v_transit', 'v_events', 'v_lodging']);
  assert.deepEqual(WORKER_SEGMENTS, SEGMENTS.slice(1, 8));
  assert.deepEqual(RIVAL_KINDS, ['quick', 'full', 'cafe', 'bar', 'convenience']);
  assert.equal(PLACE_TYPES.length, 22);
  assert.deepEqual([SECTORS[0], SECTORS[3], SECTORS[19], SECTORS.length], ['CNS01', 'CNS04', 'CNS20', 20]);
  assert.deepEqual(['pw10', 'b2', 'pn9', 'b10'].sort(compareAscii), ['b10', 'b2', 'pn9', 'pw10']); // byte order, not numeric
});

test('seed adapter: the repository seed file', () => {
  const seeds = loadSeeds(REAL_SEEDS);
  assert.equal(seeds.modelVersion, 'tps-0.1.0');
  assert.ok(Number.isInteger(seeds.seedsRevision));
  assert.equal(seeds.earthRadiusM, 6371008.8);
  assert.ok(seeds.walkDecayM > 0 && seeds.walkCutoffM > seeds.walkDecayM);
  assert.ok(seeds.cns04Weight >= 0 && seeds.cns04Weight <= 1);
  assert.ok(seeds.cellMinNearby >= 0 && seeds.cellMinVenue >= 0);
  assert.deepEqual(Object.keys(seeds.segmentCns), [...WORKER_SEGMENTS]);
  assert.deepEqual(Object.values(seeds.segmentCns).flat().sort(), [...SECTORS]);
  assert.deepEqual(Object.keys(seeds.placeTypes), [...PLACE_TYPES]);
  for (const type of PLACE_TYPES) {
    assert.deepEqual(Object.keys(seeds.placeTypes[type]), ['visitor_segment', 'default_size', 'rival_kind', 'host_fit', 'kitchen_default']);
  }
  assert.match(seeds.sha256, /^[0-9a-f]{64}$/);
  assert.equal(seeds.hasTrafficMatrix('dc'), true);
  assert.equal(seeds.hasTrafficMatrix('us_mean'), true);
  assert.equal(seeds.hasTrafficMatrix('atlantis'), false);
});

test('seed adapter: the frozen fixture copy holds the values of seeds revision 1', () => {
  const seeds = loadSeeds(MINI_SEEDS);
  assert.deepEqual([seeds.modelVersion, seeds.seedsRevision, seeds.walkDecayM, seeds.walkCutoffM, seeds.cns04Weight, seeds.cellMinNearby, seeds.cellMinVenue],
    ['tps-0.1.0', 1, 400, 1200, 0.3, 100, 15]);
  assert.deepEqual(seeds.segmentCns.w_industrial, ['CNS01', 'CNS02', 'CNS03', 'CNS04', 'CNS05', 'CNS06', 'CNS08']);
  assert.deepEqual(seeds.placeTypes.bar, { visitor_segment: 'v_nightlife', default_size: 45, rival_kind: 'bar', host_fit: 0.3, kitchen_default: 'yes' });
  assert.deepEqual(seeds.placeTypes.stadium, { visitor_segment: 'v_events', default_size: 0, rival_kind: null, host_fit: 0.3, kitchen_default: 'yes' });
  assert.equal(seeds.hasTrafficMatrix('us_mean'), true);
  assert.equal(seeds.hasTrafficMatrix('dc'), false);
});

test('seed adapter: a missing or malformed value is an error that names the path', () => {
  const doc = JSON.parse(fs.readFileSync(MINI_SEEDS, 'utf8'));
  const broken = (edit, pattern) => {
    const copy = clone(doc);
    edit(copy);
    assert.throws(() => seedsFromDocument(copy), pattern);
  };
  broken((d) => { delete d.etl.cns04_weight; }, /missing value at etl\.cns04_weight\.value/);
  broken((d) => { delete d.etl; }, /missing value at etl\.cns04_weight\.value/);
  broken((d) => { delete d.kernel.walk_cutoff_m.value; }, /kernel\.walk_cutoff_m\.value/);
  broken((d) => { d.etl.cell_min_venue.value = '15'; }, /etl\.cell_min_venue\.value must be a finite number/);
  broken((d) => { d.etl.cns04_weight.value = 1.5; }, /outside 0\.\.1/);
  broken((d) => { delete d.model_version; }, /missing value at model_version/);
  broken((d) => { d.seeds_revision = 1.5; }, /seeds_revision must be an integer/);
  broken((d) => { delete d.place_types.rows.taproom.host_fit; }, /place_types\.rows\.taproom\.host_fit/);
  broken((d) => { delete d.place_types.rows.car_dealership; }, /place_types\.rows\.car_dealership/);
  broken((d) => { d.place_types.rows.bar.rival_kind = 'pub'; }, /place_types\.rows\.bar\.rival_kind is not a rival kind/);
  broken((d) => { d.place_types.rows.gym.visitor_segment = 'v_gym'; }, /not a segment/);
  broken((d) => { d.place_types.rows.gym.kitchen_default = 'unknown'; }, /kitchen_default must be/);
  // every sector in exactly one segment list
  broken((d) => { d.segments.w_health.lodes_cns.push('CNS15'); }, /CNS15 appears in both/);
  broken((d) => { d.segments.w_public.lodes_cns = ['CNS19']; }, /CNS20 appears in no/);
  broken((d) => { d.segments.w_retail.lodes_cns = ['CNS7']; }, /unknown sector/);
  assert.throws(() => loadSeeds(path.join(HERE, 'no-such-seeds.json')), SeedError);
});

test('region file: dc.json is valid and carries the documented check values', () => {
  const { doc, sha256 } = loadRegion(path.join(HERE, '..', 'regions', 'dc.json'));
  assert.match(sha256, /^[0-9a-f]{64}$/);
  assert.deepEqual([doc.id, doc.cbsa, doc.h3_res, doc.timezone, doc.traffic_matrix], ['dc', '47900', 9, 'America/New_York', 'dc']);
  assert.equal(doc.counties.length, 23);
  assert.equal(countySet(doc).size, 23);
  assert.deepEqual(doc.states.map((s) => s.usps), ['dc', 'md', 'va', 'wv']);
  const sum = (key) => doc.counties.reduce((a, c) => a + c[key], 0);
  assert.deepEqual([sum('residents'), sum('housing_units'), sum('jobs'), sum('blocks')], [6278542, 2458414, 3140158, 64615]);
  assert.deepEqual([doc.checks.residents, doc.checks.housing_units, doc.checks.jobs, doc.checks.blocks], [6278542, 2458414, 3140158, 64615]);
  assert.equal(Object.values(doc.checks.jobs_by_segment).reduce((a, b) => a + b, 0), doc.checks.jobs);
  assert.equal(Object.values(doc.checks.state_blocks).reduce((a, b) => a + b, 0), 325888);
  assert.deepEqual(doc.checks.job_review, { blocks: 86, jobs: 572153 });
  assert.equal(doc.checks.anchors.length, 4);
  assert.deepEqual(doc.fetch_box, { south: 37.95, west: -78.4, north: 39.75, east: -76.62 });
  // every county lies in a state of the file; the West Virginia county is there
  assert.ok(doc.counties.some((c) => c.fips === '54037'));
});

test('region file: validation errors name the field', () => {
  const good = loadRegion(path.join(HERE, 'fixtures', 'mini', 'mini.json')).doc;
  const broken = (edit, pattern) => {
    const copy = clone(good);
    edit(copy);
    assert.throws(() => validateRegion(copy), pattern);
  };
  assert.equal(validateRegion(clone(good)).id, 'mini');
  broken((d) => { d.schema = 2; }, /schema must be 1/);
  broken((d) => { d.id = 'Mini Region'; }, /id = /);
  broken((d) => { d.h3_res = 10; }, /h3_res must be 9/);
  broken((d) => { d.membership = 'crosswalk'; }, /membership must be "geoid_prefix"/);
  broken((d) => { d.block_point = 'lodes'; }, /block_point must be "census_intpt"/);
  broken((d) => { d.fetch_box.north = d.fetch_box.south; }, /south < north/);
  broken((d) => { d.states = []; }, /states must be a non-empty list/);
  broken((d) => { d.states[0].usps = 'VA'; }, /states\[0\]\.usps/);
  broken((d) => { d.counties[0].fips = '11001'; }, /belongs to no state/);
  broken((d) => { d.counties.push(d.counties[0]); }, /listed twice/);
  broken((d) => { d.counties[0].residents = -1; }, /counties\[0\]\.residents/);
  broken((d) => { delete d.checks.cells_kept; }, /checks\.cells_kept must be an object/);
  broken((d) => { d.checks.places = { min: 10, max: 5 }; }, /checks\.places\.max/);
  broken((d) => { delete d.checks.halo_residents_share_max; }, /checks\.halo_residents_share_max/);
  broken((d) => { delete d.checks.job_movement; }, /checks\.job_movement/);
  broken((d) => { d.checks.anchors[0].column = 'C000'; }, /column must be one of CNS01\.\.CNS20/);
  broken((d) => { d.checks.anchors[0].geoid = '110010002012001'; }, /outside the region/);
  broken((d) => { d.checks.h3_probe.cell = '892AAAB3043FFFF'; }, /checks\.h3_probe\.cell/);
  broken((d) => { delete d.checks.state_residents['51']; }, /checks\.state_residents\.51/);
  broken((d) => { d.lodes.format = 'eight'; }, /lodes\.format/);
  broken((d) => { d.job_review.single_sector_share = 1.2; }, /job_review\.single_sector_share/);
  assert.throws(() => loadRegion(path.join(HERE, 'no-such-region.json')), RegionError);
});

test('command line arguments: --name=value, --name value and flags', () => {
  const spec = { values: ['region', 'raw-dir', 'out'], flags: ['offline'] };
  assert.deepEqual(parseArgs(['--region=dc', '--raw-dir', 'C:\\data\\raw', '--offline', '--out=a=b'], spec),
    { region: 'dc', 'raw-dir': 'C:\\data\\raw', offline: true, out: 'a=b' });
  assert.deepEqual(parseArgs([], spec), {});
  assert.throws(() => parseArgs(['--region'], spec), /--region needs a value/);
  assert.throws(() => parseArgs(['--region', '--offline'], spec), /--region needs a value/);
  assert.throws(() => parseArgs(['--region='], spec), /--region needs a value/);
  assert.throws(() => parseArgs(['--offline=yes'], spec), /takes no value/);
  assert.throws(() => parseArgs(['--regoin=dc'], spec), /unknown option --regoin/);
  assert.throws(() => parseArgs(['dc'], spec), UsageError);
  assert.throws(() => parseArgs(['--region=dc', '--region=mini'], spec), /given twice/);
});
