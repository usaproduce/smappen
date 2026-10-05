// Source points and the halo (03_DATA.md section 6).
// A source point is a census block (residents, jobs by worker segment) or a place with a visitor segment.
// People and outlets just outside the county line are kept as a halo with in_region = 0.

import { capHaloBlock } from './corrections.mjs';
import { BucketGrid } from './geo.mjs';
import { sectorsToSegments } from './lodes.mjs';
import { SEGMENTS, compareAscii } from './vocabulary.mjs';

/**
 * @param {object} args
 * @param {object} args.table block table (all blocks of the region's states)
 * @param {Int32Array} args.regionBlocks table positions of the region's blocks, ascending GEOID
 * @param {Float64Array} args.corrected 20 corrected sector values per region block
 * @param {Uint8Array} args.reduced 1 when a region block's own jobs were reduced by a correction
 * @param {object} args.grouping from compileSegmentGrouping
 * @param {object[]} args.places de-duplicated places of the fetch box with county_fips set
 * @param {{total_min: number, single_sector_min: number, single_sector_share: number}} args.thresholds
 * @param {number} args.walkCutoffM
 * @param {number} args.earthRadiusM
 * @returns {{points: object[], places: object[], s0: object[], stats: object}} `points` sorted by point_id,
 *          `places` the kept place rows (in region or halo) sorted by place_key, `s0` the in-region points
 */
export function buildSourcePoints({ table, regionBlocks, corrected, reduced, grouping, places, thresholds, walkCutoffM, earthRadiusM }) {
  const points = [];
  const stats = {
    blockRows: 0,
    placeRows: 0,
    haloBlockRows: 0,
    haloPlaceRows: 0,
    haloResidents: 0,
    haloJobsRaw: 0,
    haloJobsCapped: 0,
    haloBlocksCapped: 0,
    haloVisitorPlaces: 0,
    haloRivalPlaces: 0,
    haloVisitorRoleCleared: 0,
    droppedOutsideRegion: 0,
  };
  const segments = new Float64Array(7);

  const blockPoint = (i, inRegion, jobAdj, sectors, offset) => {
    sectorsToSegments(sectors, offset, grouping, segments, 0);
    const base = new Float64Array(16);
    base[0] = table.residents[i];
    let any = base[0] > 0;
    for (let w = 0; w < 7; w++) {
      base[1 + w] = segments[w];
      if (segments[w] > 0) any = true;
    }
    if (!any) return null;
    return {
      id: `b${table.geoid[i]}`, kind: 'block', ref: table.geoid[i], inRegion, jobAdj, lat: table.lat[i], lng: table.lng[i], base,
    };
  };

  // 6.1 region block rows
  for (let k = 0; k < regionBlocks.length; k++) {
    const point = blockPoint(regionBlocks[k], 1, reduced[k], corrected, k * 20);
    if (point) { points.push(point); stats.blockRows++; }
  }

  // 6.2 region place rows
  const placePoint = (place, inRegion) => {
    const base = new Float64Array(16);
    base[SEGMENTS.indexOf(place.visitor_segment)] = place.size_default;
    return { id: `p${place.place_key}`, kind: 'place', ref: place.place_key, inRegion, jobAdj: 0, lat: place.lat, lng: place.lng, base };
  };
  const keptPlaces = [];
  for (const place of places) {
    if (place.county_fips === null) continue;
    place.in_region = 1;
    keptPlaces.push(place);
    if (place.visitor_segment !== null) { points.push(placePoint(place, 1)); stats.placeRows++; }
  }
  const s0 = points.slice();

  // 6.3 halo. One grid with buckets of 3c serves the 2c and the 3c test.
  let maxAbsLat = 0;
  for (let i = 0; i < table.n; i++) if (Math.abs(table.lat[i]) > maxAbsLat) maxAbsLat = Math.abs(table.lat[i]);
  for (const place of places) if (Math.abs(place.lat) > maxAbsLat) maxAbsLat = Math.abs(place.lat);
  const grid = new BucketGrid(3 * walkCutoffM, maxAbsLat, earthRadiusM);
  for (const p of s0) grid.add(p.lat, p.lng);

  const capped = new Float64Array(20);
  for (let i = 0; i < table.n; i++) {
    if (table.inRegion[i]) continue;
    if (!(table.residents[i] > 0 || table.c000[i] > 0)) continue;
    if (!grid.anyWithin(table.lat[i], table.lng[i], 2 * walkCutoffM)) continue;
    const discarded = table.hasWac[i] ? capHaloBlock(table.cns, i * 20, thresholds, capped) : (capped.fill(0), 0);
    const point = blockPoint(i, 0, discarded > 0 ? 1 : 0, capped, 0);
    if (!point) continue;
    points.push(point);
    stats.haloBlockRows++;
    stats.haloResidents += table.residents[i];
    stats.haloJobsRaw += table.c000[i];
    stats.haloJobsCapped += discarded;
    if (discarded > 0) stats.haloBlocksCapped++;
  }

  for (const place of places) {
    if (place.county_fips !== null) continue;
    place.in_region = 0;
    const visitor = place.visitor_segment !== null && grid.anyWithin(place.lat, place.lng, 2 * walkCutoffM);
    const rival = place.rival_kind !== null && grid.anyWithin(place.lat, place.lng, 3 * walkCutoffM);
    if (!visitor && !rival) { stats.droppedOutsideRegion++; continue; }
    if (visitor) {
      points.push(placePoint(place, 0));
      stats.haloPlaceRows++;
      stats.haloVisitorPlaces++;
    } else if (place.visitor_segment !== null) {
      // Kept as a rival only: its own people are out of reach of every map cell, so it has no source row.
      place.visitor_segment = null;
      place.size_default = 0;
      stats.haloVisitorRoleCleared++;
    }
    if (place.rival_kind !== null) stats.haloRivalPlaces++;
    keptPlaces.push(place);
  }

  points.sort((a, b) => compareAscii(a.id, b.id));
  keptPlaces.sort((a, b) => compareAscii(a.place_key, b.place_key));
  return { points, places: keptPlaces, s0, stats };
}
