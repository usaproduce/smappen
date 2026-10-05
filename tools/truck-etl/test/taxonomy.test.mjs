import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';
import { loadSeeds } from '../src/seeds.mjs';
import {
  DROP_CLOSED, DROP_NO_RULE, DROP_RELATION_TYPE, DROP_UNNAMED, RULES, TRIGGER_KEYS, classify, deriveRoles, geomKind,
  hasTriggerKey, kitchenState,
} from '../src/taxonomy.mjs';
import { PLACE_TYPES } from '../src/vocabulary.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const seeds = loadSeeds(path.join(HERE, 'fixtures', 'mini', 'seeds.json'));
const PT = seeds.placeTypes;

// One element per rule: [rule, place type, osm type, tags].
const TABLE = [
  ['R01', 'taproom', 'node', { craft: 'brewery', name: 'Old Ox' }],
  ['R01', 'taproom', 'way', { craft: 'winery', name: 'W' }],
  ['R01', 'taproom', 'node', { craft: 'distillery', name: 'D' }],
  ['R01', 'taproom', 'node', { craft: 'cidery', name: 'C' }],
  ['R01', 'taproom', 'node', { microbrewery: 'yes', amenity: 'restaurant', name: 'Brewpub' }],
  ['R02', 'fast_food', 'node', { amenity: 'fast_food' }],
  ['R02', 'fast_food', 'way', { amenity: 'food_court' }],
  ['R02', 'fast_food', 'node', { shop: 'deli' }],
  ['R03', 'restaurant', 'node', { amenity: 'restaurant' }],
  ['R04', 'cafe', 'node', { amenity: 'cafe' }],
  ['R04', 'cafe', 'node', { amenity: 'ice_cream' }],
  ['R04', 'cafe', 'node', { shop: 'bakery' }],
  ['R04', 'cafe', 'node', { shop: 'pastry' }],
  ['R04', 'cafe', 'node', { shop: 'coffee' }],
  ['R05', 'bar', 'node', { amenity: 'bar' }],
  ['R05', 'bar', 'node', { amenity: 'pub' }],
  ['R05', 'bar', 'node', { amenity: 'biergarten' }],
  ['R06', 'convenience', 'node', { shop: 'convenience' }],
  ['R06', 'convenience', 'way', { shop: 'supermarket' }],
  ['R07', 'hospital', 'way', { amenity: 'hospital', name: 'H' }],
  ['R07', 'hospital', 'node', { healthcare: 'hospital', name: 'H' }],
  ['R08', 'campus', 'way', { amenity: 'university', name: 'U' }],
  ['R08', 'campus', 'node', { amenity: 'college', name: 'C' }],
  ['R09', 'transit_station', 'node', { railway: 'station', name: 'S' }],
  ['R09', 'transit_station', 'node', { public_transport: 'station', name: 'S' }],
  ['R09', 'transit_station', 'way', { amenity: 'bus_station', name: 'S' }],
  ['R10', 'stadium', 'way', { leisure: 'stadium', name: 'S' }],
  ['R11', 'events_venue', 'node', { amenity: 'events_venue', name: 'E' }],
  ['R11', 'events_venue', 'node', { amenity: 'conference_centre', name: 'E' }],
  ['R11', 'events_venue', 'node', { amenity: 'exhibition_centre', name: 'E' }],
  ['R11', 'events_venue', 'node', { amenity: 'theatre', name: 'E' }],
  ['R11', 'events_venue', 'node', { amenity: 'cinema', name: 'E' }],
  ['R11', 'events_venue', 'node', { amenity: 'arts_centre', name: 'E' }],
  ['R12', 'hotel', 'way', { tourism: 'hotel', name: 'H' }],
  ['R13', 'attraction', 'way', { tourism: 'museum', name: 'M' }],
  ['R13', 'attraction', 'node', { tourism: 'attraction', name: 'A' }],
  ['R13', 'attraction', 'way', { tourism: 'theme_park', name: 'T' }],
  ['R13', 'attraction', 'way', { tourism: 'zoo', name: 'Z' }],
  ['R13', 'attraction', 'way', { leisure: 'water_park', name: 'W' }],
  ['R14', 'farmers_market', 'node', { amenity: 'marketplace', name: 'M' }],
  ['R15', 'gym', 'node', { leisure: 'fitness_centre', name: 'G' }],
  ['R15', 'gym', 'way', { leisure: 'sports_centre', name: 'G' }],
  ['R15', 'gym', 'way', { leisure: 'sports_hall', name: 'G' }],
  ['R15', 'gym', 'way', { leisure: 'ice_rink', name: 'G' }],
  ['R16', 'park', 'way', { leisure: 'park', name: 'P' }],
  ['R17', 'shopping_centre', 'way', { shop: 'mall', name: 'M' }],
  ['R17', 'shopping_centre', 'relation', { type: 'multipolygon', landuse: 'retail', name: 'M' }],
  ['R18', 'big_box', 'way', { shop: 'department_store', name: 'B' }],
  ['R18', 'big_box', 'way', { shop: 'wholesale', name: 'B' }],
  ['R18', 'big_box', 'way', { shop: 'doityourself', name: 'B' }],
  ['R18', 'big_box', 'node', { shop: 'furniture', name: 'B' }],
  ['R18', 'big_box', 'way', { shop: 'garden_centre', name: 'B' }],
  ['R19', 'car_dealership', 'way', { shop: 'car', name: 'C' }],
  ['R20', 'apartment_community', 'way', { building: 'apartments', name: 'A' }],
  ['R20', 'apartment_community', 'way', { landuse: 'residential', residential: 'apartments', name: 'A' }],
  ['R20', 'apartment_community', 'way', { residential: 'condominium', name: 'A' }],
  ['R21', 'industrial_site', 'way', { landuse: 'industrial', name: 'I' }],
  ['R21', 'industrial_site', 'way', { building: 'industrial', name: 'I' }],
  ['R21', 'industrial_site', 'way', { building: 'warehouse', name: 'I' }],
  ['R21', 'industrial_site', 'node', { industrial: 'depot', name: 'I' }],
  ['R21', 'industrial_site', 'way', { man_made: 'works', name: 'I' }],
  ['R22', 'office_park', 'way', { landuse: 'commercial', name: 'O' }],
  ['R22', 'office_park', 'way', { building: 'office', name: 'O' }],
  ['R22', 'office_park', 'way', { office: 'company', name: 'O' }],
  ['R22', 'office_park', 'relation', { type: 'multipolygon', office: 'government', name: 'O' }],
];

test('tag rules: one element per rule and tag alternative', () => {
  for (const [rule, type, osmType, tags] of TABLE) {
    assert.deepEqual(classify(osmType, tags), { type, rule }, `${rule} ${JSON.stringify(tags)}`);
  }
  // every rule and every place type of the vocabulary is covered
  assert.deepEqual([...new Set(TABLE.map((row) => row[0]))], RULES.map((r) => r.id));
  assert.deepEqual([...new Set(TABLE.map((row) => row[1]))].sort(), [...PLACE_TYPES].sort());
  assert.equal(RULES.length, 22);
});

test('tag rules: first match wins', () => {
  assert.equal(classify('node', { craft: 'brewery', amenity: 'restaurant', name: 'B' }).type, 'taproom'); // R01 before R03
  assert.equal(classify('node', { amenity: 'fast_food', shop: 'convenience' }).type, 'fast_food'); // R02 before R06
  assert.equal(classify('node', { amenity: 'cafe', tourism: 'hotel', name: 'H' }).type, 'cafe'); // R04 before R12
  assert.equal(classify('way', { amenity: 'hospital', building: 'office', name: 'H' }).type, 'hospital'); // R07 before R22
  assert.equal(classify('way', { leisure: 'park', landuse: 'retail', name: 'P' }).type, 'park'); // R16 before R17
  assert.equal(classify('way', { shop: 'car', building: 'warehouse', name: 'C' }).type, 'car_dealership'); // R19 before R21
  assert.equal(classify('way', { building: 'apartments', office: 'yes', name: 'A' }).type, 'apartment_community'); // R20 before R22
});

test('tag rules: drop reasons', () => {
  // no rule: by design (5.1)
  for (const tags of [
    { amenity: 'place_of_worship', name: 'W' }, { amenity: 'clinic', name: 'C' }, { amenity: 'community_centre', name: 'C' },
    { leisure: 'golf_course', name: 'G' }, { shop: 'sports', name: 'S' }, { shop: 'hardware' }, { shop: 'butcher' },
    { tourism: 'motel', name: 'M' }, { tourism: 'hostel', name: 'H' }, { building: 'yes' }, { landuse: 'residential', name: 'R' },
    { office: 'company', name: 'An office node' }, { shop: 'confectionery' },
  ]) {
    assert.deepEqual(classify('node', tags), { drop: DROP_NO_RULE }, JSON.stringify(tags));
  }
  // an office node is a tenant, an office way is a place
  assert.equal(classify('way', { office: 'company', name: 'O' }).type, 'office_park');

  // unnamed where a name is required; the brand does not count; not tried against later rules
  assert.deepEqual(classify('way', { leisure: 'park' }), { drop: DROP_UNNAMED });
  assert.deepEqual(classify('way', { leisure: 'park', name: '   ' }), { drop: DROP_UNNAMED });
  assert.deepEqual(classify('node', { tourism: 'hotel', brand: 'Hilton' }), { drop: DROP_UNNAMED });
  assert.deepEqual(classify('node', { craft: 'brewery', amenity: 'restaurant' }), { drop: DROP_UNNAMED }); // R01 matched, R03 not tried
  assert.equal(classify('node', { amenity: 'restaurant' }).type, 'restaurant'); // food outlets need no name

  // closed markers are tested before the rules
  assert.deepEqual(classify('node', { amenity: 'restaurant', disused: 'yes' }), { drop: DROP_CLOSED });
  assert.deepEqual(classify('node', { amenity: 'cafe', abandoned: 'yes' }), { drop: DROP_CLOSED });
  assert.deepEqual(classify('node', { shop: 'vacant' }), { drop: DROP_CLOSED });
  assert.equal(classify('node', { amenity: 'restaurant', 'disused:amenity': 'cafe' }).type, 'restaurant'); // lifecycle prefixes are not read

  // relations: only multipolygons, tested before everything else
  assert.deepEqual(classify('relation', { type: 'site', amenity: 'university', name: 'U' }), { drop: DROP_RELATION_TYPE });
  assert.deepEqual(classify('relation', { amenity: 'university', name: 'U' }), { drop: DROP_RELATION_TYPE });
  assert.deepEqual(classify('relation', { type: 'route', shop: 'vacant' }), { drop: DROP_RELATION_TYPE });
  assert.equal(classify('relation', { type: 'multipolygon', amenity: 'university', name: 'U' }).type, 'campus');
});

test('trigger keys', () => {
  assert.equal(TRIGGER_KEYS.size, 15);
  assert.equal(hasTriggerKey({ name: 'x', highway: 'residential' }), false);
  assert.equal(hasTriggerKey({ name: 'x', railway: 'station' }), true);
  assert.equal(hasTriggerKey({ public_transport: 'platform' }), true);
  for (const [, , , tags] of TABLE) assert.equal(hasTriggerKey(tags), true, JSON.stringify(tags));
});

test('geom_kind', () => {
  assert.equal(geomKind('node', { building: 'yes' }), 'point');
  assert.equal(geomKind('way', { building: 'retail' }), 'building');
  assert.equal(geomKind('relation', { building: 'yes' }), 'building');
  assert.equal(geomKind('way', { building: 'no' }), 'area');
  assert.equal(geomKind('way', { leisure: 'park' }), 'area');
});

test('kitchen: read from the tags for taprooms and bars, the type default otherwise', () => {
  assert.equal(kitchenState('taproom', { craft: 'brewery' }, PT), 'unknown');
  assert.equal(kitchenState('taproom', { food: 'no', cuisine: 'pizza' }, PT), 'no');
  assert.equal(kitchenState('taproom', { food: 'yes' }, PT), 'yes');
  assert.equal(kitchenState('taproom', { amenity: 'restaurant' }, PT), 'yes');
  assert.equal(kitchenState('bar', { cuisine: 'burger' }, PT), 'yes');
  assert.equal(kitchenState('bar', { cuisine: '  ' }, PT), 'unknown');
  assert.equal(kitchenState('bar', { amenity: 'bar' }, PT), 'unknown');
  assert.equal(kitchenState('restaurant', { food: 'no' }, PT), 'yes');
  assert.equal(kitchenState('gym', { food: 'yes' }, PT), 'no');
  assert.equal(kitchenState('hospital', {}, PT), 'yes');
});

test('roles: rivals', () => {
  const roles = (osmType, type, tags, named = true) => deriveRoles(osmType, type, tags, named, PT);
  assert.equal(roles('node', 'restaurant', { amenity: 'restaurant' }).rival_kind, 'full');
  assert.equal(roles('node', 'fast_food', { amenity: 'fast_food' }).rival_kind, 'quick');
  assert.equal(roles('node', 'cafe', { shop: 'bakery' }).rival_kind, 'cafe');
  assert.equal(roles('node', 'convenience', { shop: 'supermarket' }).rival_kind, 'convenience');
  // a bar is a rival unless it is tagged as serving no food
  assert.equal(roles('node', 'bar', { amenity: 'bar' }).rival_kind, 'bar');
  assert.equal(roles('node', 'bar', { amenity: 'pub', food: 'no' }).rival_kind, null);
  // a taproom is a rival only when the element is also a food outlet
  assert.equal(roles('node', 'taproom', { craft: 'brewery' }).rival_kind, null);
  assert.equal(roles('node', 'taproom', { craft: 'brewery', amenity: 'restaurant' }).rival_kind, 'full');
  assert.equal(roles('node', 'taproom', { craft: 'brewery', amenity: 'cafe' }).rival_kind, 'cafe');
  assert.equal(roles('node', 'taproom', { craft: 'brewery', amenity: 'fast_food', shop: 'convenience' }).rival_kind, 'quick'); // first of R02..R06
  assert.equal(roles('node', 'taproom', { craft: 'brewery', amenity: 'pub', food: 'yes' }).rival_kind, 'bar');
  assert.equal(roles('node', 'taproom', { craft: 'brewery', amenity: 'pub' }).rival_kind, null); // kitchen unknown resolves to the taproom default "no"
  assert.equal(roles('node', 'taproom', { craft: 'brewery', amenity: 'bar', cuisine: 'pizza' }).rival_kind, 'bar');
  for (const type of ['gym', 'park', 'hotel', 'hospital', 'office_park']) assert.equal(roles('way', type, {}).rival_kind, null);
});

test('roles: visitor sources, sizes and host fit', () => {
  const roles = (osmType, type, tags, named = true) => deriveRoles(osmType, type, tags, named, PT);
  assert.deepEqual(roles('way', 'park', { leisure: 'park' }), {
    geom_kind: 'area', kitchen: 'no', rival_kind: null, visitor_segment: 'v_leisure', size_default: 38, host_fit: 0.4,
  });
  assert.deepEqual(roles('node', 'taproom', { craft: 'brewery' }), {
    geom_kind: 'point', kitchen: 'unknown', rival_kind: null, visitor_segment: 'v_nightlife', size_default: 40, host_fit: 1,
  });
  // size 0 means no source row: a stadium is planned as an event stop
  assert.deepEqual([roles('way', 'stadium', {}).visitor_segment, roles('way', 'stadium', {}).size_default], [null, 0]);
  assert.deepEqual([roles('node', 'restaurant', {}).visitor_segment, roles('node', 'restaurant', {}).size_default], [null, 0]);
  // only a campus site polygon carries the campus default size
  assert.deepEqual([roles('way', 'campus', { amenity: 'university' }).visitor_segment, roles('way', 'campus', {}).size_default], ['v_campus', 400]);
  assert.deepEqual([roles('way', 'campus', { building: 'university' }).visitor_segment, roles('way', 'campus', { building: 'yes' }).size_default], [null, 0]);
  assert.deepEqual([roles('node', 'campus', {}).visitor_segment, roles('node', 'campus', {}).size_default], [null, 0]);
  assert.equal(roles('way', 'campus', { building: 'university' }).host_fit, 0.4); // still a possible host
  // host fit is 0 without a name
  assert.equal(roles('node', 'bar', { amenity: 'bar' }, false).host_fit, 0);
  assert.equal(roles('node', 'bar', { amenity: 'bar' }, true).host_fit, 0.3);
  assert.equal(roles('node', 'restaurant', {}, true).host_fit, 0);
  assert.equal(roles('way', 'farmers_market', {}, true).host_fit, 0.8);
});
