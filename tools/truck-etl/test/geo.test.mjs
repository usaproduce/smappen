import assert from 'node:assert/strict';
import { test } from 'node:test';
import { BucketGrid, boxHalfWidths, haversineM, insideBox } from '../src/geo.mjs';

const R = 6371008.8;

function close(actual, expected, tolerance) {
  assert.ok(Math.abs(actual - expected) <= tolerance, `${actual} is not within ${tolerance} of ${expected}`);
}

test('haversineM reproduces the reference vectors of 02_MODEL.md 4.3', () => {
  close(haversineM(38.96, -77.36, 38.9696, -77.3861, R), 2496.298749, 5e-7);
  close(haversineM(38.96, -77.36, 38.9635972815, -77.36, R), 400.000005, 5e-7);
  assert.equal(haversineM(38.96, -77.36, 38.96, -77.36, R), 0);
  const exact = 38.96 + 400 / 6371008.8 * 180.0 / 3.141592653589793;
  close(haversineM(38.96, -77.36, exact, -77.36, R), 400, 1e-9);
});

test('haversineM is symmetric and clamps antipodes', () => {
  assert.equal(haversineM(10, 20, 30, 40, R), haversineM(30, 40, 10, 20, R));
  close(haversineM(0, 0, 0, 180, R), Math.PI * R, 1e-6);
});

test('boxHalfWidths gives the worked numbers of 03_DATA.md 9.3', () => {
  const { dLat, dLng } = boxHalfWidths(1200, 38.9, R);
  close(dLat, 0.0108998, 5e-8);
  close(dLng, 0.0140056, 5e-8);
});

test('BucketGrid finds exactly the items within the radius, edge included', () => {
  const grid = new BucketGrid(1200, 39.8, R);
  const items = [];
  // a lattice of points around (38.9, -77.0), about 150 m apart
  for (let i = -12; i <= 12; i++) {
    for (let j = -12; j <= 12; j++) {
      const lat = 38.9 + i * 0.00135;
      const lng = -77.0 + j * 0.00173;
      items.push([lat, lng]);
      grid.add(lat, lng);
    }
  }
  for (const [qLat, qLng] of [[38.9, -77.0], [38.9087, -77.0113], [38.8921, -76.9879]]) {
    const expected = [];
    items.forEach(([lat, lng], index) => { if (haversineM(qLat, qLng, lat, lng, R) <= 1200) expected.push(index); });
    const found = [];
    grid.forEachWithin(qLat, qLng, 1200, (index, d) => {
      assert.equal(d, haversineM(qLat, qLng, items[index][0], items[index][1], R));
      found.push(index);
    });
    assert.deepEqual(found.sort((a, b) => a - b), expected);
    assert.ok(expected.length > 100);
  }
  assert.equal(grid.anyWithin(38.9, -77.0, 10), true);
  assert.equal(grid.anyWithin(39.5, -77.0, 1200), false);
  assert.throws(() => grid.forEachWithin(38.9, -77.0, 1300, () => {}), /cannot serve/);
});

test('BucketGrid keeps an item that lies exactly on the radius', () => {
  const grid = new BucketGrid(1300, 39, R);
  const lat = 38.96 + 1200 / 6371008.8 * 180.0 / 3.141592653589793;
  grid.add(lat, -77.36);
  const d = haversineM(38.96, -77.36, lat, -77.36, R);
  assert.equal(grid.anyWithin(38.96, -77.36, d), true);
  assert.equal(grid.anyWithin(38.96, -77.36, d - 1e-6), false);
});

test('insideBox is inclusive', () => {
  const box = { south: 1, west: 2, north: 3, east: 4 };
  assert.equal(insideBox(1, 2, box), true);
  assert.equal(insideBox(3, 4, box), true);
  assert.equal(insideBox(0.999, 3, box), false);
  assert.equal(insideBox(2, 4.001, box), false);
});
