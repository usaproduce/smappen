#!/usr/bin/env node
// Developer tool: cuts the committed end-to-end fixture test/fixtures/mini out of the real raw files of the
// `dc` region. Not run by the test suite and not needed to run it.
//
//   node tools/truck-etl/test-support/make-mini-fixture.mjs --raw-dir=<raw cache that holds the dc files>
//
// The mini region is Falls Church city, Virginia (county 51610, 164 census blocks, all of them), plus a strip
// of Arlington and Fairfax blocks to the east that serves as "the rest of the state" for the halo rule. Every
// row and element is real; only the selection is artificial. The tool writes raw files in the cache layout of
// 03_DATA.md section 3, a region file whose check values are measured from the cut, a corrections file that
// exercises every treatment, a frozen copy of the seed values the pipeline reads, and the expected hashes.

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import zlib from 'node:zlib';
import { parseArgs } from '../src/args.mjs';
import { reviewRule } from '../src/corrections.mjs';
import { geofabrikFiles } from '../src/download.mjs';
import { gateLabel } from '../src/gates.mjs';
import { readPbf, readPbfHeader } from '../src/pbf.mjs';
import { buildRegion } from '../src/pipeline.mjs';
import { loadRegion } from '../src/region.mjs';
import { TRIGGER_KEYS, classify } from '../src/taxonomy.mjs';
import { PLACE_TYPES, SECTORS, WORKER_SEGMENTS } from '../src/vocabulary.mjs';
import { readZipMember } from '../src/zip.mjs';
import { writePbf } from './pbf-writer.mjs';
import { writeZip } from './zip-writer.mjs';

const PACKAGE_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REPO_DIR = path.resolve(PACKAGE_DIR, '..', '..');

const COUNTY = '51610';
const STATE = { usps: 'va', fips: '51', pl_dir: 'Virginia', geofabrik: 'virginia' };
// Strip east of the city: blocks whose internal point lies in it are the out-of-region part of the state file.
const BAND = { south: 38.8800, north: 38.8920, east: -77.1010 };
const FETCH_BOX = { south: 38.868, west: -77.2, north: 38.904, east: -77.1 };
// OSM elements are cut from a slightly larger box, so a few lie outside the fetch box.
const CUT_BOX = { south: 38.866, west: -77.203, north: 38.906, east: -77.097 };
const JOB_REVIEW = { total_min: 500, single_sector_min: 200, single_sector_share: 0.9 };
const NO_RULE_SAMPLE = 97; // one in so many elements without a rule is kept in the fixture
const UNNAMED_SAMPLE = 5; // one in so many elements dropped for a missing name is kept in the fixture

function sha256(buf) {
  return crypto.createHash('sha256').update(buf).digest('hex');
}

function write(file, data) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, data);
  return data.length;
}

function lines(buf) {
  const out = [];
  let p = 0;
  while (p < buf.length) {
    let e = buf.indexOf(0x0a, p);
    if (e < 0) e = buf.length;
    out.push(buf.subarray(p, e));
    p = e + 1;
  }
  return out;
}

async function main() {
  const args = parseArgs(process.argv.slice(2), { values: ['raw-dir', 'out'], flags: [] });
  if (!args['raw-dir']) throw new Error('--raw-dir=<raw cache with the dc files> is required');
  const rawDir = path.resolve(args['raw-dir']);
  const outDir = path.resolve(args.out || path.join(PACKAGE_DIR, 'test', 'fixtures', 'mini'));
  const dc = loadRegion(path.join(PACKAGE_DIR, 'regions', 'dc.json')).doc;
  const dcCounty = dc.counties.find((c) => c.fips === COUNTY);
  fs.rmSync(outDir, { recursive: true, force: true });
  const sizes = {};

  // ---- county polygon ----
  const counties = JSON.parse(fs.readFileSync(path.join(rawDir, 'tigerweb', 'counties_dc.geojson'), 'utf8'));
  const feature = counties.features.find((f) => f.properties.GEOID === COUNTY);
  let west = Infinity;
  for (const ring of feature.geometry.coordinates) for (const [x] of ring) if (x < west) west = x;
  let east = -Infinity;
  for (const ring of feature.geometry.coordinates) for (const [x] of ring) if (x > east) east = x;
  const geojson = {
    type: 'FeatureCollection',
    features: [{ type: 'Feature', properties: { GEOID: COUNTY, NAME: feature.properties.NAME }, geometry: feature.geometry }],
  };
  sizes.counties = write(path.join(outDir, 'raw', 'tigerweb', 'counties_mini.geojson'), `${JSON.stringify(geojson)}\n`);
  const inBand = (lat, lng) => lat >= BAND.south && lat <= BAND.north && lng > east && lng <= BAND.east;

  // ---- blocks: PL 94-171 geo header ----
  const geo = readZipMember(fs.readFileSync(path.join(rawDir, 'census', 'pl2020', 'va2020.pl.zip')), 'vageo2020.pl');
  const keptLines = [];
  const geoids = new Set();
  for (const line of lines(geo)) {
    if (line.length === 0) continue;
    const f = line.toString('latin1').split('|');
    if (f[2] === '040') { keptLines.push(line); continue; } // the state row: not a block
    if (f[2] === '050' && f[9] === COUNTY) { keptLines.push(line); continue; } // the county row: not a block
    if (f[2] !== '750') continue;
    if (f[9].startsWith(COUNTY) || inBand(Number(f[92]), Number(f[93]))) {
      keptLines.push(line);
      geoids.add(f[9]);
    }
  }
  const geoCut = Buffer.concat(keptLines.map((l) => Buffer.concat([l, Buffer.from('\n')])));
  sizes.pl = write(path.join(outDir, 'raw', 'census', 'pl2020', 'va2020.pl.zip'), writeZip([{ name: 'vageo2020.pl', data: geoCut }]));

  // ---- LODES: WAC, crosswalk, version, checksums ----
  const lodesDir = path.join(outDir, 'raw', 'lodes8', 'va');
  const cutCsv = (name) => {
    const all = lines(zlib.gunzipSync(fs.readFileSync(path.join(rawDir, 'lodes8', 'va', `${name}.gz`))));
    const kept = [all[0]];
    for (let i = 1; i < all.length; i++) {
      const geoid = all[i].subarray(0, 15).toString('latin1');
      if (geoids.has(geoid)) kept.push(all[i]);
    }
    const csv = Buffer.concat(kept.map((l) => Buffer.concat([l, Buffer.from('\n')])));
    sizes[name] = write(path.join(lodesDir, `${name}.gz`), zlib.gzipSync(csv, { level: 9 }));
    return { name, csv, rows: kept.length - 1 };
  };
  const wacName = `va_wac_${dc.lodes.segment}_${dc.lodes.job_type}_${dc.lodes.year}.csv`;
  const wac = cutCsv(wacName);
  const xwalk = cutCsv('va_xwalk.csv');
  write(path.join(lodesDir, 'lodes_va.sha256sum'), `${sha256(wac.csv)}  ${wac.name}\n${sha256(xwalk.csv)}  ${xwalk.name}\n`);
  write(path.join(lodesDir, 'version.txt'), fs.readFileSync(path.join(rawDir, 'lodes8', 'va', 'version.txt')));

  // ---- OpenStreetMap: cut the Virginia extract ----
  const pbfDir = path.join(rawDir, 'geofabrik');
  const source = fs.readdirSync(pbfDir).filter((n) => /^virginia-\d{6}\.osm\.pbf$/.test(n)).sort().pop();
  if (!source) throw new Error(`no virginia-YYMMDD.osm.pbf in ${pbfDir}`);
  const yymmdd = /-(\d{6})\.osm\.pbf$/.exec(source)[1];
  const sourcePath = path.join(pbfDir, source);
  const wantedPoint = (el) => {
    const lat = el.latE7 / 1e7;
    const lng = el.lngE7 / 1e7;
    return lng <= east + 0.003 || (lat >= BAND.south - 0.0015 && lat <= BAND.north + 0.0015);
  };
  // Pass 1: every element the taxonomy keeps, every closed element and non-multipolygon relation, and a sample
  // of the elements dropped for a missing name or for matching no rule.
  const first = readPbf(sourcePath, {
    triggerKeys: TRIGGER_KEYS,
    box: CUT_BOX,
    select: (osmType, tags, id) => {
      const r = classify(osmType, tags);
      if (r.type !== undefined) return r;
      if (r.drop === 'dropped_no_rule') return id % NO_RULE_SAMPLE === 0 ? r : null;
      if (r.drop === 'dropped_unnamed') return id % UNNAMED_SAMPLE === 0 ? r : null;
      return r;
    },
  });
  const chosen = new Set(first.elements.filter(wantedPoint).map((el) => `${el.osmType}/${el.id}`));
  // Pass 2: the same elements with their raw geometry.
  const second = readPbf(sourcePath, {
    triggerKeys: TRIGGER_KEYS,
    captureGeometry: true,
    select: (osmType, tags, id) => chosen.has(`${osmType}/${id}`),
  });
  const nodes = new Map();
  const ways = new Map();
  const relations = [];
  const needNode = (id) => {
    if (nodes.has(id)) return;
    const c = second.geometry.nodes.get(id);
    if (c) nodes.set(id, { id, latE7: c[0], lngE7: c[1] });
  };
  const needWay = (id, tags) => {
    const refs = second.geometry.wayRefs.get(id);
    if (!refs) return false;
    const existing = ways.get(id);
    if (existing) { if (tags) existing.tags = tags; return true; }
    ways.set(id, { id, refs, tags: tags || {} });
    for (const ref of refs) needNode(ref);
    return true;
  };
  for (const el of second.elements) {
    if (el.osmType === 'node') nodes.set(el.id, { id: el.id, latE7: el.latE7, lngE7: el.lngE7, tags: el.tags });
    else if (el.osmType === 'way') needWay(el.id, el.tags);
    else {
      const members = [];
      for (const m of second.geometry.relationMembers.get(el.id) || []) {
        if (m.type === 1 && needWay(m.ref, null)) members.push({ type: 1, ref: m.ref, role: '' });
        else if (m.type === 0 && second.geometry.nodes.has(m.ref)) { needNode(m.ref); members.push({ type: 0, ref: m.ref, role: '' }); }
      }
      relations.push({ id: el.id, members, tags: el.tags });
    }
  }
  const header = readPbfHeader(sourcePath);
  const pbf = writePbf(
    { nodes: [...nodes.values()], ways: [...ways.values()], relations },
    { replicationTimestamp: header.replicationTimestamp, blockSize: 4000 },
  );
  const [md5Spec, pbfSpec] = geofabrikFiles(STATE, yymmdd);
  sizes.pbf = write(path.join(outDir, 'raw', ...pbfSpec.path.split('/')), pbf);
  write(path.join(outDir, 'raw', ...md5Spec.path.split('/')),
    `${crypto.createHash('md5').update(pbf).digest('hex')}  ${path.posix.basename(pbfSpec.path)}\n`);

  // ---- frozen seed values (only the paths the pipeline reads) ----
  const realSeeds = JSON.parse(fs.readFileSync(path.join(REPO_DIR, 'docs', 'truck-planner', 'reference', 'tp_seeds.json'), 'utf8'));
  const seeds = {
    about: 'Frozen copy of the seed values the pipeline reads, for the mini fixture only. The real seeds are docs/truck-planner/reference/tp_seeds.json.',
    model_version: realSeeds.model_version,
    seeds_revision: realSeeds.seeds_revision,
    constants: { earth_radius_m: { value: realSeeds.constants.earth_radius_m.value } },
    kernel: {
      walk_decay_m: { value: realSeeds.kernel.walk_decay_m.value },
      walk_cutoff_m: { value: realSeeds.kernel.walk_cutoff_m.value },
    },
    segments: Object.fromEntries(WORKER_SEGMENTS.map((s) => [s, { lodes_cns: realSeeds.segments[s].lodes_cns }])),
    etl: {
      cns04_weight: { value: realSeeds.etl.cns04_weight.value },
      cell_min_nearby: { value: realSeeds.etl.cell_min_nearby.value },
      cell_min_venue: { value: realSeeds.etl.cell_min_venue.value },
    },
    place_types: {
      rows: Object.fromEntries(PLACE_TYPES.map((t) => {
        const r = realSeeds.place_types.rows[t];
        return [t, {
          visitor_segment: r.visitor_segment, default_size: r.default_size, rival_kind: r.rival_kind, host_fit: r.host_fit, kitchen_default: r.kitchen_default,
        }];
      })),
    },
    traffic: { us_mean: { value: 1 }, us_mean_typical: { value: 1 } },
  };
  const seedsFile = path.join(outDir, 'seeds.json');
  write(seedsFile, `${JSON.stringify(seeds, null, 2)}\n`);

  // ---- corrections: one entry per treatment, chosen from the flagged blocks of the cut ----
  const wacRows = lines(wac.csv).slice(1).filter((l) => l.length > 0).map((l) => l.toString('latin1').split(','));
  const flagged = [];
  for (const f of wacRows) {
    if (!f[0].startsWith(COUNTY)) continue;
    const sectors = f.slice(8, 28).map(Number);
    const c000 = Number(f[1]);
    if (reviewRule(c000, Math.max(...sectors), JOB_REVIEW) !== '') flagged.push({ geoid: f[0], c000, sectors });
  }
  flagged.sort((a, b) => (b.c000 - a.c000) || (a.geoid < b.geoid ? -1 : 1));
  if (flagged.length < 5) throw new Error(`only ${flagged.length} flagged blocks: lower the job_review thresholds of the fixture`);
  const top = (b) => SECTORS[b.sectors.indexOf(Math.max(...b.sectors))];
  const entries = {};
  entries[flagged[0].geoid] = { action: 'keep', c000_at_review: flagged[0].c000, reviewed: '2026-10-05', reason: 'Fixture: the largest block is kept as a real site.' };
  entries[flagged[1].geoid] = { action: 'spread', cap: 100, c000_at_review: flagged[1].c000, reviewed: '2026-10-05', confirmed: false, reason: 'Fixture: spread over the county, 100 jobs left on site.' };
  entries[flagged[2].geoid] = { action: 'cap', cap: 50, sectors: [top(flagged[2])], c000_at_review: flagged[2].c000, reviewed: '2026-10-05', reason: 'Fixture: the largest sector is capped at 50, the excess discarded.' };
  entries[flagged[3].geoid] = { action: 'spread', c000_at_review: flagged[3].c000 * 2, reviewed: '2026-10-05', reason: 'Fixture: a stale entry (the count at review was twice today\'s), spread with nothing left on site.' };
  // flagged[4] and beyond have no entry: the automatic treatment applies.
  entries['510594712041005'] = { action: 'drop', c000_at_review: 11706, reviewed: '2026-10-05', reason: 'Fixture: an orphan entry, the block is outside the mini region.' };
  const correctionsFile = path.join(outDir, 'mini.jobs.json');
  const corrections = { schema: 1, region: 'mini', version: '2026-10-05.1', lodes: { year: dc.lodes.year, job_type: dc.lodes.job_type, vintage: dc.lodes.vintage }, entries };
  write(correctionsFile, `${JSON.stringify(corrections, null, 2)}\n`);

  // ---- region file: provisional checks, one calibration build, then the measured values ----
  const anchor = flagged[0];
  const region = {
    schema: 1,
    id: 'mini',
    name: 'Falls Church test region',
    timezone: dc.timezone,
    h3_res: 9,
    membership: 'geoid_prefix',
    block_point: 'census_intpt',
    map_center: { lat: 38.8847, lng: -77.1756 },
    fetch_box: FETCH_BOX,
    states: [STATE],
    census: dc.census,
    lodes: dc.lodes,
    fuel_area_by_state: { VA: 'R1Z' },
    holidays: { inauguration_day: true, inauguration_day_counties: [COUNTY] },
    job_review: JOB_REVIEW,
    counties: [dcCounty],
    checks: {
      state_residents: { 51: 0 }, state_blocks: { 51: 0 },
      residents: dcCounty.residents, housing_units: dcCounty.housing_units, jobs: dcCounty.jobs, blocks: dcCounty.blocks,
      blocks_with_residents: 0, blocks_with_jobs: 0, blocks_with_either: 0,
      jobs_by_segment: Object.fromEntries(WORKER_SEGMENTS.map((s) => [s, 0])),
      cns04_jobs: 0,
      res9_cells_with_residents_or_jobs: 0, res9_max_residents: 0, res9_max_jobs_raw: 0, res9_max_jobs_after: 0,
      job_review: { blocks: 0, jobs: 0 },
      job_movement: { discarded_share_max: 0.1, moved_share_max: 0.5 },
      halo_residents_share_max: 10,
      places: { min: 0, max: 0 }, rivals: { min: 0, max: 0 },
      hosts_with_contact_share: { min: 0.1, max: 0.7 },
      cells_kept: { min: 0, max: 0 },
      anchors: [{ geoid: anchor.geoid, label: 'Largest block of the fixture (kept)', column: top(anchor), min: Math.max(...anchor.sectors) }],
      h3_probe: dc.checks.h3_probe,
    },
  };
  const regionFile = path.join(outDir, 'mini.json');
  const build = (doc) => {
    write(regionFile, `${JSON.stringify(doc, null, 2)}\n`);
    return buildRegion({
      regionFile, rawDir: path.join(outDir, 'raw'), outDir: path.join(outDir, 'unused'), seedsFile, correctionsFile, offline: true, write: false,
    });
  };
  fs.rmSync(path.join(outDir, 'raw', 'index.json'), { force: true });
  const trial = await build(region);
  const value = (label) => trial.gates.find((g) => gateLabel(g) === label).value;
  const around = (n) => ({ min: Math.floor(n * 0.9), max: Math.ceil(n * 1.1) });
  const m = trial.manifest;
  Object.assign(region.checks, {
    state_residents: value('G4'),
    state_blocks: value('G5.state_blocks'),
    blocks_with_residents: m.counts.blocks.with_residents,
    blocks_with_jobs: m.counts.blocks.with_jobs,
    blocks_with_either: m.counts.blocks.with_either,
    jobs_by_segment: Object.fromEntries(WORKER_SEGMENTS.map((s) => [s, value(`G7.jobs.${s}`)])),
    cns04_jobs: m.totals.jobs_by_sector.CNS04,
    res9_cells_with_residents_or_jobs: m.counts.cells.occupied,
    res9_max_residents: m.counts.cells.max_residents,
    res9_max_jobs_raw: m.counts.cells.max_jobs_raw,
    res9_max_jobs_after: Math.ceil(m.counts.cells.max_jobs_after * 1.25),
    job_review: { blocks: m.totals.review_blocks, jobs: m.totals.review_jobs },
    places: around(m.counts.places.in_region),
    rivals: around(m.counts.rivals.total),
    cells_kept: around(m.counts.cells.kept),
  });
  const final = await build(region);
  fs.rmSync(path.join(outDir, 'raw', 'index.json'), { force: true }); // the index is rebuilt by each run of the tests

  const expected = {
    about: 'Expected result of building test/fixtures/mini with its frozen seeds. Regenerate with test-support/make-mini-fixture.mjs.',
    dataset_version: final.datasetVersion,
    outputs: final.manifest.outputs,
    counts: {
      points: final.manifest.counts.points,
      places_total: final.manifest.counts.places.total,
      places_in_region: final.manifest.counts.places.in_region,
      cells: { candidates: final.manifest.counts.cells.candidates, kept: final.manifest.counts.cells.kept },
    },
    totals: {
      residents: final.manifest.totals.residents,
      jobs: final.manifest.totals.jobs,
      jobs_spread: final.manifest.totals.jobs_spread,
      jobs_discarded: final.manifest.totals.jobs_discarded,
    },
    gates_not_passed: final.gates.filter((g) => !g.pass).map((g) => ({ gate: gateLabel(g), level: g.level })),
  };
  write(path.join(outDir, 'expected.json'), `${JSON.stringify(expected, null, 2)}\n`);
  // cells.tsv is the one file whose numbers come from exp() and trigonometry: the test compares it with a tolerance
  // when its bytes differ on another runtime.
  write(path.join(outDir, 'expected-cells.tsv'), final.files['cells.tsv']);

  const out = [
    `fixture written to ${outDir}`,
    `blocks ${geoids.size} (region ${dcCounty.blocks}), WAC rows ${wac.rows}, crosswalk rows ${xwalk.rows}`,
    `OSM: ${nodes.size} nodes, ${ways.size} ways, ${relations.length} relations from ${source}`,
    `flagged blocks ${flagged.length}`,
    `sizes (bytes): ${JSON.stringify(sizes)}`,
    `dataset_version ${final.datasetVersion}; gates not passed: ${JSON.stringify(expected.gates_not_passed)}`,
    `points ${final.manifest.counts.points.total}, places ${final.manifest.counts.places.total}, cells ${final.manifest.counts.cells.kept} of ${final.manifest.counts.cells.candidates}`,
  ];
  process.stdout.write(`${out.join('\n')}\n`);
  if (!final.ok) throw new Error('the fixture build does not pass its gates');
}

main().catch((err) => {
  process.stderr.write(`${err.stack || err}\n`);
  process.exit(1);
});
