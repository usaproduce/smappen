import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { evaluateGates, formatGates, gateLabel, gatesPass } from '../src/gates.mjs';
import { loadRegion } from '../src/region.mjs';
import { WORKER_SEGMENTS } from '../src/vocabulary.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const region = loadRegion(path.join(HERE, '..', 'regions', 'dc.json')).doc;
const clone = (x) => JSON.parse(JSON.stringify(x));

/** Facts of a `dc` build in which every gate passes. */
function goodFacts() {
  const c = region.checks;
  return {
    placesSource: 'geofabrik',
    raw: { verified: 21, required: 21, missingSidecars: [] },
    pl: { rows: 1000, badRows: 0 },
    lodes: { formats: ['8.4', '8.4', '8.4', '8.4'], vintages: Array(4).fill(region.lodes.vintage) },
    stateResidents: clone(c.state_residents),
    stateBlocks: clone(c.state_blocks),
    wacBlocksNotInPl: 0,
    wacSumMismatches: 0,
    xwalk: { rows: 325888, notInPl: 0, duplicates: 0, missing: 0 },
    region: {
      residents: c.residents, housingUnits: c.housing_units, blocks: c.blocks, blocksWithResidents: c.blocks_with_residents,
      blocksWithJobs: c.blocks_with_jobs, blocksWithEither: c.blocks_with_either, jobs: c.jobs, jobsBySegmentRaw: clone(c.jobs_by_segment),
      cns04Jobs: c.cns04_jobs, counties: region.counties.map((x) => ({ fips: x.fips, residents: x.residents, housing_units: x.housing_units, jobs: x.jobs, blocks: x.blocks })),
    },
    conservation: { maxSegmentError: 4e-14, residentsInRows: c.residents },
    placesFlow: { read: 1000, kept: 400, dropped: 600 },
    jobs: { jobsSpread: 196941, jobsDiscarded: 0, jobsSpreadLost: 0, reviewSpread: 196941, reviewDiscarded: 0, adjustedWithoutRow: 0 },
    geometry: { outsideBox: 0, placesNotInCounty: 0, invalidCells: 0, probeCell: c.h3_probe.cell },
    occupancy: {
      occupiedCells: c.res9_cells_with_residents_or_jobs, maxResidents: { value: c.res9_max_residents, h3: 'a' },
      maxJobsRaw: { value: c.res9_max_jobs_raw, h3: 'b' }, maxJobsAfter: { value: 18644.07, h3: 'c' },
    },
    capsExceeded: 0,
    places: { region: 24886, rivals: 13508, named: 24589, hosts: 11941, hostsWithContact: 4143, hoursRaw: 7384, hoursParsed: 7112, noCoordinates: 0, missingTypes: [] },
    anchors: c.anchors.map((a) => ({ geoid: a.geoid, column: a.column, jobs: a.min + 1 })),
    states: region.states.map((s) => {
      const rows = region.counties.filter((x) => x.fips.startsWith(s.fips));
      const residents = rows.reduce((a, x) => a + x.residents, 0);
      const jobs = rows.reduce((a, x) => a + x.jobs, 0);
      return { fips: s.fips, residents, jobs, blocks: 10, expectedResidents: residents, expectedJobs: jobs };
    }),
    review: { flaggedBlocks: 86, flaggedJobs: 572153, stale: 0, orphan: 0, autoJobs: 0, unconfirmed: 0 },
    halo: { residents: 102158, rowsInTotals: 0 },
    cells: { ring5Accepted: 0, kept: 61460 },
  };
}

function failing(facts) {
  return evaluateGates(facts, region).filter((g) => !g.pass).map((g) => `${gateLabel(g)}:${g.level}`);
}

test('every gate passes on good facts, and each entry has the manifest shape', () => {
  const gates = evaluateGates(goodFacts(), region);
  assert.deepEqual(gates.filter((g) => !g.pass), []);
  assert.equal(gatesPass(gates), true);
  for (const g of gates) {
    assert.deepEqual(Object.keys(g), ['id', 'part', 'level', 'check', 'value', 'expected', 'pass']);
    assert.ok(g.level === 'fail' || g.level === 'warn');
    assert.match(g.id, /^G\d+b?$/);
    assert.ok(g.part === null || /^[a-z0-9_.]+$/.test(g.part));
    assert.doesNotThrow(() => JSON.stringify(g));
  }
  const labels = gates.map(gateLabel);
  assert.equal(new Set(labels).size, labels.length);
  // one entry or more for every pipeline gate G1 to G17, G8b included, in that order
  assert.deepEqual([...new Set(gates.map((g) => g.id))],
    ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8', 'G8b', 'G9', 'G10', 'G11', 'G12', 'G13', 'G14', 'G15', 'G16', 'G17']);
  assert.ok(WORKER_SEGMENTS.every((s) => labels.includes(`G7.jobs.${s}`)));
  // a gate with one check has no part
  assert.deepEqual(gates.filter((g) => g.part === null).map((g) => g.id), ['G2', 'G4', 'G6', 'G10', 'G13', 'G14']);
});

test('each fail-level gate fails on the fact it guards', () => {
  const cases = [
    [(f) => { f.raw.verified = 20; }, 'G1.files:fail'],
    [(f) => { f.pl.badRows = 3; }, 'G2:fail'],
    [(f) => { f.lodes.formats[2] = '8.5'; }, 'G3.format:fail'],
    [(f) => { f.stateResidents['54'] -= 1; }, 'G4:fail'],
    [(f) => { f.wacBlocksNotInPl = 1; }, 'G5.wac_in_pl:fail'],
    [(f) => { f.stateBlocks['11'] += 1; }, 'G5.state_blocks:fail'],
    [(f) => { f.xwalk.missing = 2; }, 'G5.xwalk:fail'],
    [(f) => { f.wacSumMismatches = 1; }, 'G6:fail'],
    [(f) => { f.region.residents -= 57701; }, 'G7.residents:fail'],
    [(f) => { f.region.housingUnits += 1; }, 'G7.housing_units:fail'],
    [(f) => { f.region.blocks -= 1; }, 'G7.blocks:fail'],
    [(f) => { f.region.blocksWithResidents -= 1; }, 'G7.blocks_with_residents:fail'],
    [(f) => { f.region.counties[22].residents = 0; }, 'G7.counties:fail'],
    [(f) => { f.region.counties[3].jobs += 1; }, 'G7.county_jobs:fail'],
    [(f) => { f.region.jobsBySegmentRaw.w_edu += 1; }, 'G7.jobs.w_edu:fail'],
    [(f) => { f.region.cns04Jobs -= 1; }, 'G7.cns04_jobs:fail'],
    [(f) => { f.region.blocksWithJobs += 1; }, 'G7.blocks_with_jobs:fail'],
    [(f) => { f.region.blocksWithEither += 1; }, 'G7.blocks_with_either:fail'],
    [(f) => { f.conservation.maxSegmentError = 2e-9; }, 'G8.segments:fail'],
    [(f) => { f.conservation.residentsInRows -= 5; }, 'G8.residents:fail'],
    [(f) => { f.placesFlow.dropped = 599; }, 'G8.places:fail'],
    [(f) => { f.jobs.jobsDiscarded = 16000; f.jobs.reviewDiscarded = 16000; }, 'G8b.discarded:fail'], // above 0.5 % of 3,140,158
    [(f) => { f.jobs.jobsSpread = 380000; f.jobs.reviewSpread = 380000; }, 'G8b.moved:fail'], // above 12 %
    [(f) => { f.jobs.reviewSpread = 196000; }, 'G8b.review_sums:fail'],
    [(f) => { f.jobs.adjustedWithoutRow = 1; }, 'G8b.review_sums:fail'],
    [(f) => { f.geometry.outsideBox = 1; }, 'G9.fetch_box:fail'],
    [(f) => { f.geometry.placesNotInCounty = 1; }, 'G9.county:fail'],
    [(f) => { f.geometry.invalidCells = 1; }, 'G9.cells:fail'],
    [(f) => { f.geometry.probeCell = '892aaab3047ffff'; }, 'G9.h3_probe:fail'],
    [(f) => { f.capsExceeded = 1; }, 'G11.caps:fail'],
    [(f) => { f.places.noCoordinates = 1; }, 'G12.coordinates:fail'],
    [(f) => { f.anchors[1].jobs = 2000; }, 'G13:fail'], // NIH cut to the automatic cap: corrections file not applied
    [(f) => { f.anchors[0].jobs = null; }, 'G13:fail'],
    [(f) => { f.states[3].residents = 0; }, 'G14:fail'], // West Virginia dropped
    [(f) => { f.states[1].jobs -= 1; }, 'G14:fail'],
    [(f) => { f.halo.residents = 320000; }, 'G16.share:fail'], // above 5 % of the region
    [(f) => { f.halo.rowsInTotals = 1; }, 'G16.totals:fail'],
    [(f) => { f.cells.ring5Accepted = 1; }, 'G17.ring5:fail'],
  ];
  for (const [edit, expected] of cases) {
    const facts = goodFacts();
    edit(facts);
    const result = failing(facts);
    assert.ok(result.includes(expected), `expected ${expected}, got ${JSON.stringify(result)}`);
    assert.equal(gatesPass(evaluateGates(facts, region)), false, expected);
  }
  // the total of raw jobs moves with the counties and the segments
  const facts = goodFacts();
  facts.region.jobs += 1;
  assert.ok(failing(facts).includes('G7.jobs:fail'));
});

test('warn-level gates do not stop a build', () => {
  const cases = [
    [(f) => { f.raw.missingSidecars = ['geofabrik/x.osm.pbf']; }, 'G1.sidecars:warn'],
    [(f) => { f.occupancy.occupiedCells = 29000; }, 'G10:warn'], // beyond 1 %
    [(f) => { f.occupancy.maxResidents.value = 4600; }, 'G11.residents:warn'],
    [(f) => { f.occupancy.maxJobsRaw.value = 39466; }, 'G11.jobs_raw:warn'],
    [(f) => { f.occupancy.maxJobsAfter.value = 39467; }, 'G11.jobs_after:warn'],
    [(f) => { f.places.region = 21999; }, 'G12.places:warn'],
    [(f) => { f.places.rivals = 15000; }, 'G12.rivals:warn'],
    [(f) => { f.places.named = 24000; }, 'G12.named:warn'], // 96.4 %
    [(f) => { f.places.hostsWithContact = 3000; }, 'G12.host_contact:warn'], // 25 %
    [(f) => { f.places.hoursParsed = 6800; }, 'G12.hours:warn'], // 92.1 %
    [(f) => { f.places.missingTypes = ['transit_station']; }, 'G12.types:warn'],
    [(f) => { f.review.flaggedBlocks = 87; }, 'G15.flagged:warn'],
    [(f) => { f.review.stale = 1; }, 'G15.entries:warn'],
    [(f) => { f.review.orphan = 1; }, 'G15.entries:warn'],
    [(f) => { f.review.autoJobs = 300000; }, 'G15.review_pending:warn'], // more than half of the flagged jobs
    [(f) => { f.review.unconfirmed = 86; }, 'G15.unconfirmed:warn'],
    [(f) => { f.cells.kept = 53999; }, 'G17.kept:warn'],
    [(f) => { f.cells.kept = 64001; }, 'G17.kept:warn'],
  ];
  for (const [edit, expected] of cases) {
    const facts = goodFacts();
    edit(facts);
    assert.deepEqual(failing(facts), [expected]);
    assert.equal(gatesPass(evaluateGates(facts, region)), true, expected);
  }
  // within tolerance: 1 % for cells, the ranges of the region file for places
  const facts = goodFacts();
  facts.occupancy.occupiedCells = 29200;
  facts.occupancy.maxResidents.value = 4500;
  facts.places.region = 22000;
  facts.cells.kept = 64000;
  assert.deepEqual(failing(facts), []);
});

test('a missing transit_station type is tolerated on the tile input only', () => {
  const facts = goodFacts();
  facts.places.missingTypes = ['transit_station'];
  facts.placesSource = 'overpass-tiles';
  assert.deepEqual(failing(facts), []);
  facts.places.missingTypes = ['transit_station', 'stadium'];
  assert.deepEqual(failing(facts), ['G12.types:warn']);
});

test('on another LODES vintage the job checks warn within 2 % and are not fatal', () => {
  const facts = goodFacts();
  facts.lodes.vintages = Array(4).fill('20261201_0900');
  facts.region.jobs = Math.round(region.checks.jobs * 1.015);
  facts.region.jobsBySegmentRaw.w_office += 5000; // 0.46 %
  facts.region.cns04Jobs += 100;
  facts.region.blocksWithJobs += 50;
  facts.region.counties[0].jobs += 3000; // 0.45 %
  facts.states[0].jobs += 3000;
  let result = failing(facts);
  assert.deepEqual(result, ['G3.vintage:warn']);
  assert.equal(gatesPass(evaluateGates(facts, region)), true);
  // beyond 2 %: still a warning, not a failure
  facts.region.jobs = Math.round(region.checks.jobs * 1.05);
  facts.region.counties[0].jobs = Math.round(region.counties[0].jobs * 1.05);
  result = failing(facts);
  assert.deepEqual(result, ['G3.vintage:warn', 'G7.county_jobs:warn', 'G7.jobs:warn']);
  assert.equal(gatesPass(evaluateGates(facts, region)), true);
  // residents are never tied to the vintage
  facts.region.residents -= 1;
  assert.equal(gatesPass(evaluateGates(facts, region)), false);
});

test('the console table marks failures and warnings and shows value and expectation', () => {
  const facts = goodFacts();
  facts.review.unconfirmed = 86;
  facts.stateResidents['11'] = 1;
  const text = formatGates(evaluateGates(facts, region));
  assert.match(text, /^ok {3}G1\.files /m);
  assert.match(text, /^FAIL G4 /m);
  assert.match(text, /^WARN G15\.unconfirmed /m);
  assert.match(text, /value 86 {3}expected 0/);
});
