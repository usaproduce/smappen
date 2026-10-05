// De-duplication of classified places (03_DATA.md 5.4).
// Step 1 (identity across state extracts or tiles) happens where the inputs are merged. This module holds the
// order of preference and steps 2 (same venue) and 3 (a site and its parts).

import { BucketGrid } from './geo.mjs';
import { comparableName } from './normalise.mjs';

export const SAME_VENUE_RADIUS_M = 60;
export const SITE_RADIUS_M = Object.freeze({
  campus: 400,
  hospital: 250,
  transit_station: 250,
  stadium: 250,
  shopping_centre: 150,
});

const GEOM_RANK = { area: 0, building: 1, point: 2 };
const TYPE_RANK = { relation: 0, way: 1, node: 2 };

/** Fields the kept row fills from a dropped one when its own value is empty. */
const FILL_FIELDS = ['phone', 'website', 'addr_line', 'city', 'state_code', 'postcode', 'cuisine', 'brand'];

/** Order of preference: geom_kind (area, building, point), osm_type (relation, way, node), lower osm_id. */
export function comparePreference(a, b) {
  return (GEOM_RANK[a.geom_kind] - GEOM_RANK[b.geom_kind])
    || (TYPE_RANK[a.osm_type] - TYPE_RANK[b.osm_type])
    || (a.osm_id - b.osm_id);
}

/** Copies the listed attributes from `dropped` into `kept` where `kept` has none. */
export function fillFrom(kept, dropped) {
  for (const key of FILL_FIELDS) {
    if (kept[key] === null && dropped[key] !== null) kept[key] = dropped[key];
  }
  if (kept.opening_hours_raw === null && dropped.opening_hours_raw !== null) {
    kept.opening_hours_raw = dropped.opening_hours_raw;
    kept.hours_mask = dropped.hours_mask;
  }
}

/**
 * One merge pass in order of preference.
 * @param {object[]} places rows with place_type, name, lat, lng and the preference fields
 * @param {(p: object) => number} radiusOf merge radius in metres for a row, 0 = the row never merges
 * @param {boolean} sameName whether the kept and the dropped row must have equal, non-empty comparable names
 * @param {number} earthRadiusM
 * @returns {{kept: object[], merged: number, mergedByType: Record<string, number>}}
 */
function mergePass(places, radiusOf, sameName, earthRadiusM) {
  const ordered = places.slice().sort(comparePreference);
  let maxRadius = 0;
  let maxAbsLat = 0;
  for (const p of ordered) {
    const r = radiusOf(p);
    if (r > maxRadius) maxRadius = r;
    if (Math.abs(p.lat) > maxAbsLat) maxAbsLat = Math.abs(p.lat);
  }
  const kept = [];
  const mergedByType = {};
  let merged = 0;
  if (maxRadius === 0) return { kept: ordered, merged, mergedByType };

  const grid = new BucketGrid(maxRadius, maxAbsLat, earthRadiusM);
  const gridRows = []; // grid index -> kept row, in processing order
  const gridNames = [];
  for (const p of ordered) {
    const r = radiusOf(p);
    if (r === 0) { kept.push(p); continue; }
    const name = sameName ? comparableName(p.name) : '';
    let absorber = -1;
    grid.forEachWithin(p.lat, p.lng, r, (index) => {
      const q = gridRows[index];
      if (q.place_type !== p.place_type) return;
      if (sameName && gridNames[index] !== name) return;
      if (absorber < 0 || index < absorber) absorber = index;
    });
    if (absorber >= 0) {
      fillFrom(gridRows[absorber], p);
      merged++;
      mergedByType[p.place_type] = (mergedByType[p.place_type] || 0) + 1;
      continue;
    }
    kept.push(p);
    gridRows[grid.add(p.lat, p.lng)] = p;
    gridNames.push(name);
  }
  return { kept, merged, mergedByType };
}

/**
 * Steps 2 and 3 of the de-duplication.
 * @param {object[]} places
 * @param {number} earthRadiusM
 * @returns {{places: object[], mergedSameVenue: number, mergedSitePart: number, sitePartByType: Record<string, number>}}
 */
export function dedupePlaces(places, earthRadiusM) {
  // Step 2: same place type, equal non-empty comparable names, at most 60 m apart.
  const venue = mergePass(places, (p) => (comparableName(p.name) === '' ? 0 : SAME_VENUE_RADIUS_M), true, earthRadiusM);
  // Step 3: same place type within the type's site radius, whatever the names.
  const site = mergePass(venue.kept, (p) => SITE_RADIUS_M[p.place_type] || 0, false, earthRadiusM);
  return {
    places: site.kept,
    mergedSameVenue: venue.merged,
    mergedSitePart: site.merged,
    sitePartByType: site.mergedByType,
  };
}
