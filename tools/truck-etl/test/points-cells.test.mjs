import assert from 'node:assert/strict';
import { test } from 'node:test';
import { cellToLatLng, getResolution, gridDisk, isValidCell, latLngToCell } from 'h3-js';
import { DISK_RINGS, enumerateCandidates, formatNearby, occupancy, pruneCandidates } from '../src/cells.mjs';
import { haversineM } from '../src/geo.mjs';
import { compileSegmentGrouping } from '../src/lodes.mjs';
import { buildSourcePoints } from '../src/points.mjs';

const R = 6371008.8;
const M_PER_DEG_LAT = (Math.PI * R) / 180;
const SEGMENT_CNS = {
  w_office: ['CNS09', 'CNS10', 'CNS11', 'CNS12', 'CNS13', 'CNS14'], w_health: ['CNS16'], w_edu: ['CNS15'], w_retail: ['CNS07'],
  w_industrial: ['CNS01', 'CNS02', 'CNS03', 'CNS04', 'CNS05', 'CNS06', 'CNS08'], w_hospitality: ['CNS17', 'CNS18'], w_public: ['CNS19', 'CNS20'],
};
const T = { total_min: 5000, single_sector_min: 2000, single_sector_share: 0.9 };
const north = (m) => 38.9 + m / M_PER_DEG_LAT;

/** Block table from rows of [geoid, inRegion, north metres, residents, sectors {index: jobs}]. */
function tableOf(rows) {
  const n = rows.length;
  const table = {
    n, geoid: rows.map((r) => r[0]), lat: Float64Array.from(rows.map((r) => north(r[2]))), lng: new Float64Array(n).fill(-77.0),
    residents: Int32Array.from(rows.map((r) => r[3])), inRegion: Uint8Array.from(rows.map((r) => r[1])),
    hasWac: new Uint8Array(n), c000: new Int32Array(n), cns: new Int32Array(n * 20),
  };
  rows.forEach((r, i) => {
    for (const [s, jobs] of Object.entries(r[4] || {})) {
      table.hasWac[i] = 1;
      table.cns[i * 20 + Number(s)] = jobs;
      table.c000[i] += jobs;
    }
  });
  return table;
}

const placeAt = (key, meters, extra) => ({
  place_key: key, lat: north(meters), lng: -77.0, county_fips: null, in_region: 0, rival_kind: null, visitor_segment: null, size_default: 0, ...extra,
});

test('source points: block rows, place rows, the halo and its three distances', () => {
  const table = tableOf([
    ['510010000000001', 1, 0, 607, { 5: 5, 8: 1, 10: 30, 11: 8, 12: 6, 13: 11, 18: 10 }], // the worked example of 6.1
    ['510010000000002', 1, 100, 0, { 3: 10 }], // construction only: 0.3 x 10
    ['510010000000003', 1, 200, 0, null], // nothing: no row
    ['510990000000001', 0, 2300, 40, { 6: 7 }], // halo: within 2,400 m of a region point
    ['510990000000002', 0, 2900, 50, null], // beyond 2,400 m of every region point (the park at 400 m is 2,500 m away)
    ['510990000000003', 0, 2350, 0, null], // no residents and no jobs: no row
    ['510990000000004', 0, 2390, 0, { 14: 9000 }], // halo and flagged: cut to the automatic cap of 2,000
  ]);
  const regionBlocks = Int32Array.from([0, 1, 2]);
  const corrected = new Float64Array(60);
  for (let k = 0; k < 3; k++) for (let s = 0; s < 20; s++) corrected[k * 20 + s] = table.cns[k * 20 + s];
  corrected[0 * 20 + 10] = 30.5; // a block that received spread jobs keeps its fraction
  const places = [
    placeAt('n1', 400, { county_fips: '51001', visitor_segment: 'v_leisure', size_default: 38 }), // region park: a source point
    placeAt('n2', 50, { county_fips: '51001', rival_kind: 'full' }), // region restaurant: a rival, no source point
    placeAt('n3', 2700, { visitor_segment: 'v_nightlife', size_default: 45, rival_kind: 'bar' }), // halo bar within 2,400 m of the park
    placeAt('n4', 3900, { visitor_segment: 'v_nightlife', size_default: 45, rival_kind: 'bar' }), // beyond 2,400 m, within 3,600 m: rival only
    placeAt('n5', 4100, { rival_kind: 'quick' }), // beyond 3,600 m: dropped
    placeAt('n6', 2000, {}), // outside the region and neither rival nor visitor source: dropped
    placeAt('n7', 2000, { visitor_segment: 'v_shopping', size_default: 150 }), // halo visitor source without a rival kind
  ];
  const out = buildSourcePoints({
    table, regionBlocks, corrected, reduced: Uint8Array.from([0, 1, 0]), grouping: compileSegmentGrouping(SEGMENT_CNS, 0.3),
    places, thresholds: T, walkCutoffM: 1200, earthRadiusM: R,
  });

  assert.deepEqual(out.points.map((p) => p.id), ['b510010000000001', 'b510010000000002', 'b510990000000001', 'b510990000000004', 'pn1', 'pn3', 'pn7']);
  const byId = Object.fromEntries(out.points.map((p) => [p.id, p]));
  // b_res 607, office 56.5 (with the spread fraction), industrial 5, public 10
  assert.deepEqual([...byId.b510010000000001.base], [607, 56.5, 0, 0, 0, 5, 0, 10, 0, 0, 0, 0, 0, 0, 0, 0]);
  assert.deepEqual([byId.b510010000000001.kind, byId.b510010000000001.ref, byId.b510010000000001.inRegion, byId.b510010000000001.jobAdj], ['block', '510010000000001', 1, 0]);
  assert.equal(byId.b510010000000002.base[5], 3); // 0.3 x 10
  assert.equal(byId.b510010000000002.jobAdj, 1);
  assert.deepEqual([byId.b510990000000001.inRegion, byId.b510990000000001.base[0], byId.b510990000000001.base[4]], [0, 40, 7]);
  assert.deepEqual([byId.b510990000000004.jobAdj, byId.b510990000000004.base[3]], [1, 2000]);
  assert.deepEqual([...byId.pn1.base], [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 38, 0, 0, 0, 0, 0]);
  assert.deepEqual([byId.pn1.kind, byId.pn1.ref, byId.pn1.inRegion], ['place', 'n1', 1]);
  assert.deepEqual([byId.pn3.inRegion, byId.pn3.base[8]], [0, 45]);

  assert.deepEqual(out.places.map((p) => [p.place_key, p.in_region]), [['n1', 1], ['n2', 1], ['n3', 0], ['n4', 0], ['n7', 0]]);
  // the far bar is kept as a rival only: no source row, so no visitor role
  const far = out.places.find((p) => p.place_key === 'n4');
  assert.deepEqual([far.rival_kind, far.visitor_segment, far.size_default], ['bar', null, 0]);
  assert.deepEqual(out.s0.map((p) => p.id), ['b510010000000001', 'b510010000000002', 'pn1']);
  assert.deepEqual(out.stats, {
    blockRows: 2, placeRows: 1, haloBlockRows: 2, haloPlaceRows: 2, haloResidents: 40, haloJobsRaw: 9007, haloJobsCapped: 7000,
    haloBlocksCapped: 1, haloVisitorPlaces: 2, haloRivalPlaces: 2, haloVisitorRoleCleared: 1, droppedOutsideRegion: 2,
  });
  // every kept place with a visitor segment has a source row, and the other way round
  assert.deepEqual(out.places.filter((p) => p.visitor_segment !== null).map((p) => `p${p.place_key}`), out.points.filter((p) => p.kind === 'place').map((p) => p.id));
});

test('candidate cells: every cell whose centre is within the cutoff of a source point, and no other', () => {
  const s0 = [{ lat: 38.9696, lng: -77.3861 }, { lat: 38.9712, lng: -77.3790 }, { lat: 38.9001, lng: -77.0002 }];
  const out = enumerateCandidates(s0, 9, 1200, R);
  assert.equal(out.ring5Accepted, 0);
  assert.equal(out.originCells, 3);
  // brute force over a wider disk
  const expected = new Set();
  for (const p of s0) {
    for (const id of gridDisk(latLngToCell(p.lat, p.lng, 9), 8)) {
      const [lat, lng] = cellToLatLng(id);
      if (haversineM(p.lat, p.lng, lat, lng, R) <= 1200) expected.add(id);
    }
  }
  assert.deepEqual(out.cells.map((c) => c.h3), [...expected].sort());
  assert.ok(out.cells.length > 60 && out.cells.length < 120);
  for (const c of out.cells) {
    assert.ok(isValidCell(c.h3));
    assert.equal(getResolution(c.h3), 9);
    assert.match(c.h3, /^[0-9a-f]{15}$/);
    assert.deepEqual([c.lat, c.lng], cellToLatLng(c.h3));
  }
  assert.equal(latLngToCell(38.9696, -77.3861, 9), '892aaab3043ffff'); // the probe of the region file
  assert.ok(out.cells.some((c) => c.h3 === '892aaab3043ffff'));
});

test('candidate cells: the result does not depend on the order of the source points', () => {
  const s0 = [];
  for (let i = 0; i < 60; i++) s0.push({ lat: 38.85 + ((i * 37) % 60) * 0.0011, lng: -77.2 + ((i * 17) % 60) * 0.0013 });
  const a = enumerateCandidates(s0, 9, 1200, R);
  const b = enumerateCandidates(s0.slice().reverse(), 9, 1200, R);
  const c = enumerateCandidates(s0.slice(30).concat(s0.slice(0, 30)), 9, 1200, R);
  assert.deepEqual(b.cells, a.cells);
  assert.deepEqual(c.cells, a.cells);
  assert.deepEqual(a.cells.map((x) => x.h3), a.cells.map((x) => x.h3).slice().sort());
});

test('candidate cells: the ring-5 guard counts acceptances when the disk is too small', () => {
  assert.equal(DISK_RINGS, 5);
  const out = enumerateCandidates([{ lat: 38.9, lng: -77.0 }], 9, 2500, R); // a cutoff the disk cannot cover
  assert.ok(out.ring5Accepted > 0);
});

function basePoint(id, meters, segment, amount) {
  const base = new Float64Array(16);
  base[segment] = amount;
  return { id, lat: north(meters), lng: -77.0, base };
}

test('pruning: nearby_etl, the two thresholds and the summation over points in id order', () => {
  const cell = { h3: 'x', lat: 38.9, lng: -77.0 };
  const params = { walkDecayM: 400, walkCutoffM: 1200, earthRadiusM: R, cellMinNearby: 100, cellMinVenue: 15 };
  const expect = (points, kept, nearby, venue) => {
    const out = pruneCandidates([cell], points, params);
    assert.equal(out.kept.length, kept ? 1 : 0);
    if (kept) {
      assert.ok(Math.abs(out.kept[0].nearby - nearby) <= 1e-9 * nearby, `${out.kept[0].nearby} != ${nearby}`);
      assert.ok(Math.abs(out.kept[0].venue - venue) <= 1e-9 * Math.max(1, venue));
    }
    return out;
  };
  // 300 residents 400 m away: 300 / e = 110.36: kept by the first test
  expect([basePoint('b1', 400, 0, 300)], true, 300 * Math.exp(-1), 0);
  // 250 residents 400 m away: 91.97: dropped
  expect([basePoint('b1', 400, 0, 250)], false);
  // a park of 38 visitors 370 m away: 15.07 venue visitors: kept by the second test alone
  const park = expect([basePoint('p1', 370, 10, 38)], true, 38 * Math.exp(-370 / 400), 38 * Math.exp(-370 / 400));
  assert.deepEqual([park.stats.keptByNearby, park.stats.keptByVenueOnly], [0, 1]);
  // the same park 380 m away: 14.70: dropped
  expect([basePoint('p1', 380, 10, 38)], false);
  // the cutoff is inclusive at 1,200 m; a point beyond it contributes nothing
  const edge = 1200 - 1e-6;
  expect([basePoint('b1', edge, 1, 3000)], true, 3000 * Math.exp(-edge / 400), 0);
  expect([basePoint('b1', 1201, 1, 1e9)], false);
  // several points: the sum runs over the points in the order given (ascending point_id)
  const points = [basePoint('b1', 100, 0, 50), basePoint('b2', 300, 1, 80), basePoint('p1', 50, 8, 40)];
  let sum = 0;
  for (const p of points) {
    const d = haversineM(38.9, -77.0, p.lat, p.lng, R);
    for (let s = 0; s < 16; s++) sum += p.base[s] * Math.exp(-d / 400);
  }
  const out = pruneCandidates([cell], points, params);
  assert.equal(out.kept[0].nearby, sum); // bit for bit
  assert.deepEqual([out.stats.candidates, out.stats.kept, out.stats.pairs, out.stats.maxPointsInRange], [1, 1, 3, 3]);
});

test('nearby_etl is written with 12 significant digits', () => {
  assert.equal(formatNearby(110.36383235143269), '110.363832351');
  assert.equal(formatNearby(100), '100');
  assert.equal(formatNearby(0.000012345678901234), '0.0000123456789012');
  assert.equal(formatNearby(123456789012345.6), '123456789012000');
  assert.equal(formatNearby(1.5e-7), '1.5e-7');
  assert.equal(formatNearby(15.000000000004), '15');
});

test('occupancy: cells with residents or raw jobs and the largest cells', () => {
  const table = tableOf([
    ['510010000000001', 1, 0, 100, { 0: 10 }],
    ['510010000000002', 1, 1, 50, { 0: 5 }], // the same cell as the first block
    ['510010000000003', 1, 5000, 0, { 0: 70 }],
    ['510010000000004', 1, 9000, 0, null], // empty, but it received spread jobs
  ]);
  const corrected = new Float64Array(80);
  corrected[0] = 10; corrected[20] = 5; corrected[40] = 20; corrected[60] = 50;
  const out = occupancy(table, Int32Array.from([0, 1, 2, 3]), corrected, 9);
  assert.equal(out.occupiedCells, 2);
  assert.equal(out.maxResidents.value, 150);
  assert.equal(out.maxJobsRaw.value, 70);
  assert.equal(out.maxJobsAfter.value, 50);
  assert.equal(out.maxResidents.h3, latLngToCell(38.9, -77.0, 9));
});
