import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { buildCounties } from '../src/counties.mjs';
import { SAME_VENUE_RADIUS_M, SITE_RADIUS_M, comparePreference, dedupePlaces, fillFrom } from '../src/dedupe.mjs';
import { DROP_COUNTERS, collectPlaces, placeKey, placeRow } from '../src/places.mjs';
import { loadSeeds } from '../src/seeds.mjs';
import { TRIGGER_KEYS, classify } from '../src/taxonomy.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const seeds = loadSeeds(path.join(HERE, 'fixtures', 'mini', 'seeds.json'));
const R = seeds.earthRadiusM;
const M_PER_DEG_LAT = (Math.PI * R) / 180;

/** A place row at `north` metres north of a base point. */
function place(osmType, osmId, placeType, name, north = 0, extra = {}) {
  return {
    place_key: placeKey(osmType, osmId), osm_type: osmType, osm_id: osmId, place_type: placeType,
    geom_kind: osmType === 'node' ? 'point' : 'area', name, lat: 38.9 + north / M_PER_DEG_LAT, lng: -77.0,
    brand: null, phone: null, website: null, addr_line: null, city: null, state_code: null, postcode: null, cuisine: null,
    opening_hours_raw: null, hours_mask: null, ...extra,
  };
}

test('preference: area, building, point; relation, way, node; lower id', () => {
  const rows = [
    { geom_kind: 'point', osm_type: 'node', osm_id: 1 },
    { geom_kind: 'building', osm_type: 'way', osm_id: 9 },
    { geom_kind: 'area', osm_type: 'way', osm_id: 7 },
    { geom_kind: 'area', osm_type: 'way', osm_id: 3 },
    { geom_kind: 'area', osm_type: 'relation', osm_id: 50 },
    { geom_kind: 'building', osm_type: 'relation', osm_id: 2 },
  ];
  assert.deepEqual(rows.slice().sort(comparePreference).map((r) => `${r.geom_kind}/${r.osm_type}/${r.osm_id}`),
    ['area/relation/50', 'area/way/3', 'area/way/7', 'building/relation/2', 'building/way/9', 'point/node/1']);
});

test('same venue: same type, equal comparable names, at most 60 m', () => {
  assert.equal(SAME_VENUE_RADIUS_M, 60);
  const rows = [
    place('node', 1, 'cafe', 'Joe\'s Café', 0, { phone: '+17035550123', opening_hours_raw: 'Mo 10:00-12:00', hours_mask: 'aa' }),
    place('way', 2, 'cafe', 'JOE\'S CAFE', 30, { geom_kind: 'building', website: 'https://joes.example' }),
    place('node', 3, 'cafe', 'Joe\'s Cafe', 59), // 29 m from the kept way 2
    place('node', 4, 'cafe', 'Joe s cafe', 95), // 65 m from way 2: stays
    place('node', 5, 'restaurant', 'Joe\'s Cafe', 10), // another type: stays
    place('node', 6, 'cafe', null, 5), // no name: never merges by name
    place('node', 7, 'cafe', null, 6),
  ];
  const out = dedupePlaces(rows, R);
  assert.equal(out.mergedSameVenue, 2);
  assert.equal(out.mergedSitePart, 0);
  const keys = out.places.map((p) => p.place_key).sort();
  assert.deepEqual(keys, ['n4', 'n5', 'n6', 'n7', 'w2']);
  // the kept row (the building) fills its empty fields from the dropped node, hours as a pair
  const kept = out.places.find((p) => p.place_key === 'w2');
  assert.deepEqual([kept.phone, kept.website, kept.opening_hours_raw, kept.hours_mask], ['+17035550123', 'https://joes.example', 'Mo 10:00-12:00', 'aa']);
});

test('same venue: the radius is inclusive at 60 m and exclusive beyond', () => {
  const at = (north) => dedupePlaces([place('way', 1, 'cafe', 'A', 0), place('node', 2, 'cafe', 'A', north)], R).mergedSameVenue;
  assert.equal(at(59.9), 1);
  assert.equal(at(60.1), 0);
});

test('site and its parts: same type within the type radius, whatever the name', () => {
  assert.deepEqual(SITE_RADIUS_M, { campus: 400, hospital: 250, transit_station: 250, stadium: 250, shopping_centre: 150 });
  const rows = [
    place('relation', 1, 'campus', 'State University', 0),
    place('way', 2, 'campus', 'Science Hall', 390, { geom_kind: 'building', website: 'https://hall.example' }),
    place('way', 3, 'campus', 'Far College', 410),
    place('node', 4, 'hospital', 'General Hospital', 0),
    place('node', 5, 'hospital', 'Emergency', 249),
    place('node', 6, 'hospital', 'Other Hospital', 251),
    place('way', 7, 'shopping_centre', 'Plaza', 0),
    place('way', 8, 'shopping_centre', 'Plaza Annex', 149),
    place('node', 9, 'transit_station', 'Central', 0),
    place('node', 10, 'transit_station', 'Central (bus)', 200),
    place('way', 11, 'stadium', 'Arena', 0),
    place('node', 12, 'stadium', 'Arena Gate', 100),
    place('way', 13, 'park', 'North Park', 0),
    place('way', 14, 'park', 'South Park', 10), // parks never merge as site parts
  ];
  const out = dedupePlaces(rows, R);
  assert.equal(out.mergedSameVenue, 0);
  assert.equal(out.mergedSitePart, 5);
  assert.deepEqual(out.sitePartByType, { campus: 1, hospital: 1, shopping_centre: 1, transit_station: 1, stadium: 1 });
  assert.deepEqual(out.places.map((p) => p.place_key).sort(), ['n4', 'n6', 'n9', 'r1', 'w11', 'w13', 'w14', 'w3', 'w7']);
  assert.equal(out.places.find((p) => p.place_key === 'r1').website, 'https://hall.example');
});

test('an element merges into the earliest kept element that satisfies the test', () => {
  // two kept areas 100 m apart (different names); a point named like the second lies between them
  const rows = [
    place('way', 1, 'hospital', 'A', 0),
    place('way', 2, 'hospital', 'B', 300),
    place('node', 3, 'hospital', 'C', 150, { phone: '+17035550123' }),
  ];
  const out = dedupePlaces(rows, R);
  assert.equal(out.mergedSitePart, 1);
  assert.equal(out.places.find((p) => p.place_key === 'w1').phone, '+17035550123'); // way 1 comes first in order of preference
  assert.equal(out.places.find((p) => p.place_key === 'w2').phone, null);
});

test('fillFrom copies only into empty fields', () => {
  const kept = place('way', 1, 'cafe', 'A', 0, { phone: '+17035550001', city: null, brand: null });
  const dropped = place('node', 2, 'cafe', 'A', 0, { phone: '+17035550002', city: 'Herndon', brand: 'B', cuisine: 'coffee_shop', state_code: 'VA', postcode: '20170', addr_line: '1 Elden St' });
  fillFrom(kept, dropped);
  assert.deepEqual([kept.phone, kept.city, kept.brand, kept.cuisine, kept.state_code, kept.postcode, kept.addr_line],
    ['+17035550001', 'Herndon', 'B', 'coffee_shop', 'VA', '20170', '1 Elden St']);
});

const SQUARE = {
  type: 'FeatureCollection',
  features: [{
    type: 'Feature', properties: { GEOID: '99001' },
    geometry: { type: 'Polygon', coordinates: [[[-77.01, 38.89], [-76.99, 38.89], [-76.99, 38.91], [-77.01, 38.91], [-77.01, 38.89]]] },
  }],
};

function element(osmType, id, tags, lat, lng) {
  const payload = classify(osmType, tags);
  return { osmType, id, tags, payload, latE7: Math.round(lat * 1e7), lngE7: Math.round(lng * 1e7), box: null };
}

test('place row: key, coordinates and roles', () => {
  const row = placeRow(element('way', 264230766, { amenity: 'cafe', building: 'yes', name: 'Cafe', phone: '703 555 0123' }, 38.9681974, -77.4141855), seeds.placeTypes);
  assert.equal(row.place_key, 'w264230766');
  assert.deepEqual([row.osm_type, row.osm_id, row.place_type, row.geom_kind], ['way', 264230766, 'cafe', 'building']);
  assert.deepEqual([row.lat, row.lng], [38.9681974, -77.4141855]);
  assert.deepEqual([row.rival_kind, row.visitor_segment, row.size_default, row.host_fit, row.kitchen], ['cafe', null, 0, 0, 'yes']);
  assert.equal(row.phone, '+17035550123');
  assert.equal(placeKey('node', 1), 'n1');
  assert.equal(placeKey('relation', 13000000000), 'r13000000000');
});

test('places stage: counters, identity across inputs, county assignment, order', () => {
  // Two inputs (two state extracts). The second repeats an element of the first.
  const stats = (triggered, noGeometry = 0, outsideBox = 0) => ({ triggered, noGeometry, outsideBox });
  const cafe = { amenity: 'cafe', name: 'Border Cafe' };
  const inputs = [
    {
      label: 'aa',
      read: ({ select, triggerKeys }) => {
        assert.equal(triggerKeys, TRIGGER_KEYS);
        const candidates = [
          ['node', 1, cafe, 38.9, -77.0],
          ['node', 2, { amenity: 'restaurant' }, 38.9005, -77.0],
          ['node', 3, { leisure: 'park' }, 38.9, -77.0], // unnamed
          ['node', 4, { amenity: 'cafe', disused: 'yes' }, 38.9, -77.0], // closed
          ['node', 5, { building: 'yes' }, 38.9, -77.0], // no rule
          ['relation', 6, { type: 'site', amenity: 'university', name: 'U' }, 38.9, -77.0], // not a multipolygon
          ['way', 7, { leisure: 'park', name: 'Outside Park' }, 38.95, -77.0], // outside every county
        ];
        const elements = [];
        for (const [type, id, tags, lat, lng] of candidates) if (select(type, tags, id)) elements.push(element(type, id, tags, lat, lng));
        return { elements, stats: stats(candidates.length + 2, 1, 1) }; // the reader also met one without geometry and one outside the box
      },
    },
    {
      label: 'bb',
      read: ({ select }) => {
        const elements = [];
        if (select('node', cafe, 1)) elements.push(element('node', 1, { ...cafe, name: 'Second copy' }, 38.9, -77.0));
        if (select('way', { amenity: 'cafe', name: 'border cafe', website: 'cafe.example' }, 8)) {
          elements.push(element('way', 8, { amenity: 'cafe', name: 'border cafe', website: 'cafe.example' }, 38.90001, -77.0));
        }
        return { elements, stats: stats(2) };
      },
    },
  ];
  const counties = buildCounties(SQUARE, ['99001']);
  const out = collectPlaces({
    inputs, fetchBox: { south: 38, west: -78, north: 40, east: -76 }, placeTypes: seeds.placeTypes, counties, earthRadiusM: R, triggerKeys: TRIGGER_KEYS,
  });
  assert.equal(out.read, 11);
  assert.deepEqual(out.counters, {
    dropped_relation_type: 1, dropped_closed: 1, dropped_no_rule: 1, dropped_unnamed: 1, dropped_no_geometry: 1, dropped_outside_box: 1,
    merged_identity: 1, merged_same_venue: 1, merged_site_part: 0, dropped_outside_region: 0,
  });
  assert.deepEqual(Object.keys(out.counters), [...DROP_COUNTERS]);
  // the first input wins the identity; the node then merges into the way of the same name (area before point)
  assert.deepEqual(out.places.map((p) => p.place_key), ['n2', 'w7', 'w8']);
  assert.deepEqual(out.places.map((p) => p.county_fips), ['99001', null, '99001']);
  const kept = out.places.find((p) => p.place_key === 'w8');
  assert.equal(kept.website, 'https://cafe.example');
  // conservation: read = kept + every counter
  const sum = Object.values(out.counters).reduce((a, b) => a + b, 0);
  assert.equal(out.read, out.places.length + sum);
});
