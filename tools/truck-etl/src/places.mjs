// Places stage: classified OSM elements to place rows (03_DATA.md section 5).
// Order: taxonomy (while reading), fetch box, normalisation and roles, de-duplication, county assignment.
// The source of the elements (Geofabrik extracts or saved Overpass tiles) is hidden behind `inputs`.

import { countyOf } from './counties.mjs';
import { dedupePlaces } from './dedupe.mjs';
import { normaliseAttributes } from './normalise.mjs';
import { classify, deriveRoles } from './taxonomy.mjs';
import { compareAscii } from './vocabulary.mjs';

const TYPE_LETTER = { node: 'n', way: 'w', relation: 'r' };

/** Every counter of the places stage. Places read = places kept + the sum of all of these. */
export const DROP_COUNTERS = Object.freeze([
  'dropped_relation_type', 'dropped_closed', 'dropped_no_rule', 'dropped_unnamed', 'dropped_no_geometry',
  'dropped_outside_box', 'merged_identity', 'merged_same_venue', 'merged_site_part', 'dropped_outside_region',
]);

/** `n`, `w` or `r` plus the decimal OSM id. */
export function placeKey(osmType, osmId) {
  return TYPE_LETTER[osmType] + String(osmId);
}

/**
 * Builds the selector the readers call for every element that has a trigger key.
 * It classifies the element and counts the reason when it is dropped.
 * @param {Record<string, number>} counters receives the dropped_* counts of 5.1
 */
export function makeSelector(counters) {
  return (osmType, tags) => {
    const result = classify(osmType, tags);
    if (result.drop !== undefined) {
      counters[result.drop]++;
      return null;
    }
    return result;
  };
}

/** Turns one kept element into a place row (5.2, 5.6). */
export function placeRow(element, placeTypes) {
  const { osmType, id, tags, payload } = element;
  const attributes = normaliseAttributes(tags);
  const roles = deriveRoles(osmType, payload.type, tags, attributes.name !== null, placeTypes);
  return {
    place_key: placeKey(osmType, id),
    osm_type: osmType,
    osm_id: id,
    place_type: payload.type,
    geom_kind: roles.geom_kind,
    in_region: 0,
    county_fips: null,
    name: attributes.name,
    brand: attributes.brand,
    lat: element.latE7 / 1e7,
    lng: element.lngE7 / 1e7,
    rival_kind: roles.rival_kind,
    visitor_segment: roles.visitor_segment,
    size_default: roles.size_default,
    host_fit: roles.host_fit,
    kitchen: roles.kitchen,
    phone: attributes.phone,
    website: attributes.website,
    addr_line: attributes.addr_line,
    city: attributes.city,
    state_code: attributes.state_code,
    postcode: attributes.postcode,
    cuisine: attributes.cuisine,
    opening_hours_raw: attributes.opening_hours_raw,
    hours_mask: attributes.hours_mask,
    tags: attributes.tags,
    // not written: coverage bookkeeping
    phoneRaw: attributes.phoneRaw,
    websiteRaw: attributes.websiteRaw,
  };
}

/**
 * Runs the places stage.
 * @param {object} args
 * @param {{label: string, read: (options: object) => {elements: object[], stats: object}}[]} args.inputs state
 *        extracts in the state order of the region file, or tiles in name order
 * @param {{south: number, west: number, north: number, east: number}} args.fetchBox
 * @param {object} args.placeTypes seed rows by place type
 * @param {object} args.counties lookup from buildCounties
 * @param {number} args.earthRadiusM
 * @param {Set<string>} args.triggerKeys
 * @returns {{places: object[], counters: Record<string, number>, read: number, inputStats: object[]}}
 *          `places` are all de-duplicated places of the fetch box, sorted by place_key, with `county_fips` set
 *          for those inside a county. `read` counts the elements that had a trigger key.
 */
export function collectPlaces({ inputs, fetchBox, placeTypes, counties, earthRadiusM, triggerKeys }) {
  const counters = {};
  for (const name of DROP_COUNTERS) counters[name] = 0;
  const select = makeSelector(counters);

  const byKey = new Map();
  const inputStats = [];
  let read = 0;
  for (const input of inputs) {
    const { elements, stats } = input.read({ triggerKeys, select, box: fetchBox });
    read += stats.triggered;
    counters.dropped_no_geometry += stats.noGeometry;
    counters.dropped_outside_box += stats.outsideBox;
    let kept = 0;
    for (const element of elements) {
      const key = placeKey(element.osmType, element.id);
      // Step 1, identity: the same element in two extracts or tiles. The first input wins.
      if (byKey.has(key)) { counters.merged_identity++; continue; }
      byKey.set(key, placeRow(element, placeTypes));
      kept++;
    }
    inputStats.push({ label: input.label, ...stats, kept });
  }

  const deduped = dedupePlaces([...byKey.values()], earthRadiusM);
  counters.merged_same_venue = deduped.mergedSameVenue;
  counters.merged_site_part = deduped.mergedSitePart;

  const places = deduped.places;
  for (const p of places) p.county_fips = countyOf(counties, p.lat, p.lng);
  places.sort((a, b) => compareAscii(a.place_key, b.place_key));
  return { places, counters, read, inputStats, sitePartByType: deduped.sitePartByType };
}
