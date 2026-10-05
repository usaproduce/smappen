import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { CountiesError, buildCounties, countyOf } from '../src/counties.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));

const square = (x0, y0, x1, y1) => [[x0, y0], [x1, y0], [x1, y1], [x0, y1], [x0, y0]];
const feature = (geoid, geometry) => ({ type: 'Feature', properties: { GEOID: geoid, NAME: geoid }, geometry });

const COLLECTION = {
  type: 'FeatureCollection',
  features: [
    // listed out of order on purpose: lookups go by ascending FIPS
    feature('20002', { type: 'Polygon', coordinates: [square(-77.5, 38.5, -76.5, 39.5)] }),
    // a polygon with a hole, overlapping 20002
    feature('10001', { type: 'Polygon', coordinates: [square(-78, 38, -77, 39), square(-77.6, 38.4, -77.4, 38.6)] }),
    // two separate parts
    feature('30003', { type: 'MultiPolygon', coordinates: [[square(-80, 40, -79, 41)], [square(-75, 40, -74, 41), square(-74.6, 40.4, -74.4, 40.6)]] }),
  ],
};

test('point in polygon: holes, multipolygons, bounding boxes', () => {
  const lookup = buildCounties(COLLECTION, ['10001', '20002', '30003']);
  assert.deepEqual(lookup.counties.map((c) => c.fips), ['10001', '20002', '30003']);
  assert.equal(countyOf(lookup, 38.2, -77.8), '10001');
  assert.equal(countyOf(lookup, 38.5, -77.5), '20002'); // inside the hole of 10001, and on the corner region of 20002
  assert.equal(countyOf(lookup, 38.45, -77.55), null); // inside the hole, outside 20002
  assert.equal(countyOf(lookup, 38.8, -77.2), '10001'); // overlap: the lower FIPS wins
  assert.equal(countyOf(lookup, 39.2, -76.8), '20002');
  assert.equal(countyOf(lookup, 40.5, -79.5), '30003');
  assert.equal(countyOf(lookup, 40.2, -74.8), '30003');
  assert.equal(countyOf(lookup, 40.5, -74.5), null); // hole of the second part
  assert.equal(countyOf(lookup, 40.5, -77), null); // between the two parts, inside the feature's bounding box
  assert.equal(countyOf(lookup, 10, 10), null);
  assert.deepEqual(lookup.bounds, { lat_min: 38, lng_min: -80, lat_max: 41, lng_max: -74 });
});

test('validation of the TIGERweb response', () => {
  assert.throws(() => buildCounties({ error: { code: 400, message: 'Invalid query' } }, ['10001']), /answered an error/);
  assert.throws(() => buildCounties({ ...COLLECTION, exceededTransferLimit: true }, ['10001', '20002', '30003']), /cut off/);
  assert.throws(() => buildCounties(COLLECTION, ['10001', '20002']), /30003 is not a county of the region/);
  assert.throws(() => buildCounties(COLLECTION, ['10001', '20002', '30003', '40004']), /40004 of the region file is missing/);
  assert.throws(() => buildCounties({ type: 'FeatureCollection', features: [COLLECTION.features[0], COLLECTION.features[0]] }, ['20002']), /appears twice/);
  assert.throws(() => buildCounties({ type: 'Feature' }, []), CountiesError);
  assert.throws(() => buildCounties({ type: 'FeatureCollection', features: [feature('10001', { type: 'Point', coordinates: [0, 0] })] }, ['10001']), /no Polygon/);
  assert.throws(() => buildCounties({ type: 'FeatureCollection', features: [feature('10001', { type: 'Polygon', coordinates: [[[0, 0], [1, 1]]] })] }, ['10001']), /fewer than 4/);
});

test('the Falls Church polygon of the mini fixture (real TIGERweb geometry)', () => {
  const doc = JSON.parse(fs.readFileSync(path.join(HERE, 'fixtures', 'mini', 'raw', 'tigerweb', 'counties_mini.geojson'), 'utf8'));
  const lookup = buildCounties(doc, ['51610']);
  assert.equal(countyOf(lookup, 38.8847220, -77.1756027), '51610'); // the county's internal point
  assert.equal(countyOf(lookup, 38.8823, -77.1711), '51610'); // City Hall
  assert.equal(countyOf(lookup, 38.8690, -77.1513), null); // Seven Corners is in Fairfax County
  assert.equal(countyOf(lookup, 38.8816, -77.1125), null); // Ballston is in Arlington County
  assert.deepEqual(lookup.bounds, { lat_min: 38.87247, lng_min: -77.195, lat_max: 38.89989, lng_max: -77.1497 });
});
