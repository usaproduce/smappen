// Candidate map cells and the pruning rule (03_DATA.md section 7).

import { cellToLatLng, gridDiskDistances, latLngToCell } from 'h3-js';
import { BucketGrid, haversineM } from './geo.mjs';
import { FIRST_VISITOR_SEGMENT, compareAscii } from './vocabulary.mjs';

/** Rings searched around a source point's own cell. Ring 5 is a guard: accepting one of its cells fails the build. */
export const DISK_RINGS = 5;

/**
 * Enumerates every H3 cell whose centre lies within the walking cutoff of a region source point.
 * @param {{lat: number, lng: number}[]} s0 region source points
 * @param {number} res H3 resolution
 * @param {number} walkCutoffM
 * @param {number} earthRadiusM
 * @returns {{cells: {h3: string, lat: number, lng: number}[], ring5Accepted: number, originCells: number}}
 *          cells sorted by id
 */
export function enumerateCandidates(s0, res, walkCutoffM, earthRadiusM) {
  // Points of the same origin cell share one disk.
  const byOrigin = new Map();
  for (const p of s0) {
    const origin = latLngToCell(p.lat, p.lng, res);
    const list = byOrigin.get(origin);
    if (list) list.push(p); else byOrigin.set(origin, [p]);
  }

  const cellIndex = new Map();
  const ids = [];
  const lats = [];
  const lngs = [];
  const accepted = [];
  let ring5Accepted = 0;

  for (const [origin, pts] of byOrigin) {
    const rings = gridDiskDistances(origin, DISK_RINGS);
    const diskCells = [];
    const diskRings = [];
    for (let k = 0; k < rings.length; k++) {
      for (const id of rings[k]) {
        let index = cellIndex.get(id);
        if (index === undefined) {
          index = ids.length;
          cellIndex.set(id, index);
          const [lat, lng] = cellToLatLng(id);
          ids.push(id);
          lats.push(lat);
          lngs.push(lng);
          accepted.push(0);
        }
        diskCells.push(index);
        diskRings.push(k);
      }
    }
    for (const p of pts) {
      for (let j = 0; j < diskCells.length; j++) {
        const index = diskCells[j];
        const ring = diskRings[j];
        if (accepted[index] && ring < DISK_RINGS) continue;
        if (haversineM(p.lat, p.lng, lats[index], lngs[index], earthRadiusM) <= walkCutoffM) {
          if (ring === DISK_RINGS) ring5Accepted++;
          accepted[index] = 1;
        }
      }
    }
  }

  const cells = [];
  for (let i = 0; i < ids.length; i++) if (accepted[i]) cells.push({ h3: ids[i], lat: lats[i], lng: lngs[i] });
  cells.sort((a, b) => compareAscii(a.h3, b.h3));
  return { cells, ring5Accepted, originCells: byOrigin.size };
}

/** nearby_etl as written to cells.tsv: 12 significant digits, shortest form. */
export function formatNearby(x) {
  return String(Number(x.toPrecision(12)));
}

/**
 * Scores every candidate and keeps those that pass one of the two pruning tests.
 * @param {{h3: string, lat: number, lng: number}[]} candidates sorted by id
 * @param {{lat: number, lng: number, base: Float64Array}[]} points all source points (region and halo), sorted by point_id
 * @param {object} p
 * @param {number} p.walkDecayM @param {number} p.walkCutoffM @param {number} p.earthRadiusM
 * @param {number} p.cellMinNearby @param {number} p.cellMinVenue
 * @returns {{kept: {h3: string, lat: number, lng: number, nearby: number, venue: number}[], stats: object}}
 */
export function pruneCandidates(candidates, points, { walkDecayM, walkCutoffM, earthRadiusM, cellMinNearby, cellMinVenue }) {
  let maxAbsLat = 0;
  for (const p of points) if (Math.abs(p.lat) > maxAbsLat) maxAbsLat = Math.abs(p.lat);
  for (const c of candidates) if (Math.abs(c.lat) > maxAbsLat) maxAbsLat = Math.abs(c.lat);
  const grid = new BucketGrid(walkCutoffM, maxAbsLat, earthRadiusM);
  for (const p of points) grid.add(p.lat, p.lng); // grid index = position in point_id order

  const distance = new Float64Array(points.length);
  const near = [];
  const kept = [];
  const stats = { candidates: candidates.length, kept: 0, keptByNearby: 0, keptByVenueOnly: 0, pairs: 0, maxPointsInRange: 0 };

  for (const cell of candidates) {
    near.length = 0;
    grid.forEachWithin(cell.lat, cell.lng, walkCutoffM, (index, d) => {
      distance[index] = d;
      near.push(index);
    });
    near.sort((a, b) => a - b); // ascending point_id
    let nearby = 0;
    let venue = 0;
    for (let n = 0; n < near.length; n++) {
      const index = near[n];
      const f = Math.exp(-distance[index] / walkDecayM);
      const base = points[index].base;
      for (let s = 0; s < 16; s++) {
        const b = base[s];
        if (b === 0) continue;
        nearby += b * f;
        if (s >= FIRST_VISITOR_SEGMENT) venue += b * f;
      }
    }
    const byNearby = nearby >= cellMinNearby;
    const byVenue = venue >= cellMinVenue;
    if (!byNearby && !byVenue) continue;
    kept.push({ h3: cell.h3, lat: cell.lat, lng: cell.lng, nearby, venue });
    stats.kept++;
    if (byNearby) stats.keptByNearby++; else stats.keptByVenueOnly++;
    stats.pairs += near.length;
    if (near.length > stats.maxPointsInRange) stats.maxPointsInRange = near.length;
  }
  return { kept, stats };
}

/**
 * Resolution-9 occupancy of the region's blocks (gates G10 and G11).
 * @param {object} table block table
 * @param {Int32Array} regionBlocks table positions of the region's blocks
 * @param {Float64Array} corrected 20 corrected sector values per region block
 * @param {number} res
 */
export function occupancy(table, regionBlocks, corrected, res) {
  const cells = new Map(); // h3 -> [residents, raw jobs, corrected jobs]
  let occupied = 0;
  for (let k = 0; k < regionBlocks.length; k++) {
    const i = regionBlocks[k];
    let after = 0;
    for (let s = 0; s < 20; s++) after += corrected[k * 20 + s];
    const residents = table.residents[i];
    const jobs = table.c000[i];
    if (!(residents > 0 || jobs > 0 || after > 0)) continue;
    const id = latLngToCell(table.lat[i], table.lng[i], res);
    let sums = cells.get(id);
    if (!sums) cells.set(id, sums = [0, 0, 0, 0]);
    sums[0] += residents;
    sums[1] += jobs;
    sums[2] += after;
    if ((residents > 0 || jobs > 0) && sums[3] === 0) { sums[3] = 1; occupied++; }
  }
  const top = { residents: { value: 0, h3: null }, jobsRaw: { value: 0, h3: null }, jobsAfter: { value: 0, h3: null } };
  const ids = [...cells.keys()].sort();
  for (const id of ids) {
    const sums = cells.get(id);
    if (sums[0] > top.residents.value) top.residents = { value: sums[0], h3: id };
    if (sums[1] > top.jobsRaw.value) top.jobsRaw = { value: sums[1], h3: id };
    if (sums[2] > top.jobsAfter.value) top.jobsAfter = { value: sums[2], h3: id };
  }
  return { occupiedCells: occupied, maxResidents: top.residents, maxJobsRaw: top.jobsRaw, maxJobsAfter: top.jobsAfter };
}
