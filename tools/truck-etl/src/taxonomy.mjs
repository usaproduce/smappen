// Place taxonomy: OSM tags to place type and roles (03_DATA.md 5.1 to 5.3).

/** An element is tested only when it carries one of these keys. */
export const TRIGGER_KEYS = new Set([
  'amenity', 'craft', 'microbrewery', 'shop', 'healthcare', 'railway', 'public_transport', 'leisure', 'tourism',
  'landuse', 'building', 'residential', 'industrial', 'man_made', 'office',
]);

export const DROP_RELATION_TYPE = 'dropped_relation_type';
export const DROP_CLOSED = 'dropped_closed';
export const DROP_NO_RULE = 'dropped_no_rule';
export const DROP_UNNAMED = 'dropped_unnamed';

// Shared result objects: classify runs for every tagged building of a state extract.
const RESULT_RELATION_TYPE = Object.freeze({ drop: DROP_RELATION_TYPE });
const RESULT_CLOSED = Object.freeze({ drop: DROP_CLOSED });
const RESULT_NO_RULE = Object.freeze({ drop: DROP_NO_RULE });
const RESULT_UNNAMED = Object.freeze({ drop: DROP_UNNAMED });

const set = (...values) => new Set(values);
const CRAFT = set('brewery', 'winery', 'distillery', 'cidery');
const FAST_FOOD_AMENITY = set('fast_food', 'food_court');
const CAFE_AMENITY = set('cafe', 'ice_cream');
const CAFE_SHOP = set('bakery', 'pastry', 'coffee');
const BAR_AMENITY = set('bar', 'pub', 'biergarten');
const CONVENIENCE_SHOP = set('convenience', 'supermarket');
const CAMPUS_AMENITY = set('university', 'college');
const EVENTS_AMENITY = set('events_venue', 'conference_centre', 'exhibition_centre', 'theatre', 'cinema', 'arts_centre');
const ATTRACTION_TOURISM = set('museum', 'attraction', 'theme_park', 'zoo');
const GYM_LEISURE = set('fitness_centre', 'sports_centre', 'sports_hall', 'ice_rink');
const BIG_BOX_SHOP = set('department_store', 'wholesale', 'doityourself', 'furniture', 'garden_centre');
const APARTMENT_RESIDENTIAL = set('apartments', 'condominium');
const INDUSTRIAL_BUILDING = set('industrial', 'warehouse');
const KITCHEN_AMENITY = set('restaurant', 'fast_food', 'cafe', 'food_court');

const present = (v) => typeof v === 'string' && v !== '';

/**
 * The tag rules in order. The first rule whose test holds decides the place type.
 * `named` = the element needs a non-empty `name` tag (the `brand` fallback of 5.6 does not count).
 */
export const RULES = Object.freeze([
  { id: 'R01', type: 'taproom', named: true, test: (t) => CRAFT.has(t.craft) || t.microbrewery === 'yes' },
  { id: 'R02', type: 'fast_food', named: false, test: (t) => FAST_FOOD_AMENITY.has(t.amenity) || t.shop === 'deli' },
  { id: 'R03', type: 'restaurant', named: false, test: (t) => t.amenity === 'restaurant' },
  { id: 'R04', type: 'cafe', named: false, test: (t) => CAFE_AMENITY.has(t.amenity) || CAFE_SHOP.has(t.shop) },
  { id: 'R05', type: 'bar', named: false, test: (t) => BAR_AMENITY.has(t.amenity) },
  { id: 'R06', type: 'convenience', named: false, test: (t) => CONVENIENCE_SHOP.has(t.shop) },
  { id: 'R07', type: 'hospital', named: true, test: (t) => t.amenity === 'hospital' || t.healthcare === 'hospital' },
  { id: 'R08', type: 'campus', named: true, test: (t) => CAMPUS_AMENITY.has(t.amenity) },
  {
    id: 'R09', type: 'transit_station', named: true,
    test: (t) => t.railway === 'station' || t.public_transport === 'station' || t.amenity === 'bus_station',
  },
  { id: 'R10', type: 'stadium', named: true, test: (t) => t.leisure === 'stadium' },
  { id: 'R11', type: 'events_venue', named: true, test: (t) => EVENTS_AMENITY.has(t.amenity) },
  { id: 'R12', type: 'hotel', named: true, test: (t) => t.tourism === 'hotel' },
  { id: 'R13', type: 'attraction', named: true, test: (t) => ATTRACTION_TOURISM.has(t.tourism) || t.leisure === 'water_park' },
  { id: 'R14', type: 'farmers_market', named: true, test: (t) => t.amenity === 'marketplace' },
  { id: 'R15', type: 'gym', named: true, test: (t) => GYM_LEISURE.has(t.leisure) },
  { id: 'R16', type: 'park', named: true, test: (t) => t.leisure === 'park' },
  { id: 'R17', type: 'shopping_centre', named: true, test: (t) => t.shop === 'mall' || t.landuse === 'retail' },
  { id: 'R18', type: 'big_box', named: true, test: (t) => BIG_BOX_SHOP.has(t.shop) },
  { id: 'R19', type: 'car_dealership', named: true, test: (t) => t.shop === 'car' },
  {
    id: 'R20', type: 'apartment_community', named: true,
    test: (t) => t.building === 'apartments' || APARTMENT_RESIDENTIAL.has(t.residential),
  },
  {
    id: 'R21', type: 'industrial_site', named: true,
    test: (t) => t.landuse === 'industrial' || INDUSTRIAL_BUILDING.has(t.building) || present(t.industrial) || t.man_made === 'works',
  },
  {
    id: 'R22', type: 'office_park', named: true,
    test: (t, osmType) => t.landuse === 'commercial' || t.building === 'office' || (present(t.office) && osmType !== 'node'),
  },
]);

/** R02..R06: the food rules a taproom may also satisfy (5.2, rival_kind). */
const FOOD_RULES = RULES.slice(1, 6);
const FOOD_TYPES = new Set(FOOD_RULES.map((r) => r.type));

/** True when the element has a `name` tag that is not empty after trimming. */
export function hasNameTag(tags) {
  return typeof tags.name === 'string' && tags.name.trim() !== '';
}

/**
 * Classifies one element that carries at least one trigger key.
 * Order of tests: relation type, closed markers, the rules in order, the required name.
 * @param {'node'|'way'|'relation'} osmType
 * @param {Record<string, string>} tags
 * @returns {{type: string, rule: string} | {drop: string}}
 */
export function classify(osmType, tags) {
  if (osmType === 'relation' && tags.type !== 'multipolygon') return RESULT_RELATION_TYPE;
  if (tags.disused === 'yes' || tags.abandoned === 'yes' || tags.shop === 'vacant') return RESULT_CLOSED;
  for (let i = 0; i < RULES.length; i++) {
    const rule = RULES[i];
    if (!rule.test(tags, osmType)) continue;
    if (rule.named && !hasNameTag(tags)) return RESULT_UNNAMED;
    return { type: rule.type, rule: rule.id };
  }
  return RESULT_NO_RULE;
}

/** True when the element has at least one trigger key. */
export function hasTriggerKey(tags) {
  for (const key in tags) if (TRIGGER_KEYS.has(key)) return true;
  return false;
}

/** `point` for a node, `building` for a way or relation with a `building` tag other than `no`, else `area`. */
export function geomKind(osmType, tags) {
  if (osmType === 'node') return 'point';
  return present(tags.building) && tags.building !== 'no' ? 'building' : 'area';
}

/** Three-state kitchen flag (5.2). Only taprooms and bars are read from the tags. */
export function kitchenState(placeType, tags, placeTypes) {
  if (placeType !== 'taproom' && placeType !== 'bar') return placeTypes[placeType].kitchen_default;
  if (tags.food === 'no') return 'no';
  if (tags.food === 'yes') return 'yes';
  if (KITCHEN_AMENITY.has(tags.amenity)) return 'yes';
  if (typeof tags.cuisine === 'string' && tags.cuisine.trim() !== '') return 'yes';
  return 'unknown';
}

/**
 * Roles of a classified element (5.2).
 * @param {'node'|'way'|'relation'} osmType
 * @param {string} placeType
 * @param {Record<string, string>} tags
 * @param {boolean} named whether the place has a name after normalisation (name tag, else brand)
 * @param {Record<string, {visitor_segment: string|null, default_size: number, rival_kind: string|null, host_fit: number, kitchen_default: string}>} placeTypes seed rows
 */
export function deriveRoles(osmType, placeType, tags, named, placeTypes) {
  const seed = placeTypes[placeType];
  const geom = geomKind(osmType, tags);
  const kitchen = kitchenState(placeType, tags, placeTypes);
  const resolvedKitchen = kitchen === 'unknown' ? seed.kitchen_default : kitchen;

  let food = FOOD_TYPES.has(placeType) ? placeType : null;
  if (food === null) {
    for (const rule of FOOD_RULES) {
      if (rule.test(tags, osmType)) { food = rule.type; break; }
    }
  }
  let rivalKind;
  if (food !== null) rivalKind = food === 'bar' && resolvedKitchen === 'no' ? null : placeTypes[food].rival_kind;
  else rivalKind = seed.rival_kind;

  let visitorSegment = seed.visitor_segment;
  let sizeDefault = seed.default_size;
  if (visitorSegment === null || !(sizeDefault > 0) || (placeType === 'campus' && geom !== 'area')) {
    visitorSegment = null;
    sizeDefault = 0;
  }
  return {
    geom_kind: geom,
    kitchen,
    rival_kind: rivalKind,
    visitor_segment: visitorSegment,
    size_default: sizeDefault,
    host_fit: named ? seed.host_fit : 0,
  };
}
