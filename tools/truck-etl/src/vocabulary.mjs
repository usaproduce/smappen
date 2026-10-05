// Shared vocabulary and version strings of the pipeline (03_DATA.md section 0).
// Nothing here is a seed: these are the fixed names every runtime agrees on.

export const PIPELINE_VERSION = 'tp-etl-1.0.0';

/** The sixteen segments in shared order (index 0..15). */
export const SEGMENTS = Object.freeze([
  'res',
  'w_office', 'w_health', 'w_edu', 'w_retail', 'w_industrial', 'w_hospitality', 'w_public',
  'v_nightlife', 'v_shopping', 'v_leisure', 'v_campus', 'v_hospital', 'v_transit', 'v_events', 'v_lodging',
]);

/** The seven worker segments, in shared order (segment indexes 1..7). */
export const WORKER_SEGMENTS = Object.freeze(SEGMENTS.slice(1, 8));

/** Index of the first visitor segment: venue_etl sums segment indexes 8..15. */
export const FIRST_VISITOR_SEGMENT = 8;

export const RIVAL_KINDS = Object.freeze(['quick', 'full', 'cafe', 'bar', 'convenience']);

/** The 22 place types in shared vocabulary order. */
export const PLACE_TYPES = Object.freeze([
  'taproom', 'bar', 'restaurant', 'fast_food', 'cafe', 'convenience', 'gym', 'park', 'shopping_centre', 'big_box',
  'campus', 'hospital', 'transit_station', 'events_venue', 'stadium', 'hotel', 'attraction', 'farmers_market',
  'office_park', 'apartment_community', 'industrial_site', 'car_dealership',
]);

/** LODES WAC sector columns CNS01..CNS20, in file order. */
export const SECTORS = Object.freeze(Array.from({ length: 20 }, (_, i) => 'CNS' + String(i + 1).padStart(2, '0')));

/** Zero-based index of CNS04 (construction) among the 20 sectors. */
export const CNS04_INDEX = 3;

export const GEOM_KINDS = Object.freeze(['area', 'building', 'point']);

export const OSM_TYPES = Object.freeze(['node', 'way', 'relation']);

/** Byte-order comparison of two ASCII strings (JavaScript compares UTF-16 code units, identical for ASCII). */
export function compareAscii(a, b) {
  return a < b ? -1 : a > b ? 1 : 0;
}
