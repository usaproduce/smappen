// Truck Planner estimator - capture: the location vectors, and the host term (02_MODEL 4.4, 4.5).
//
// The browser receives vectors from the server and never calls rivalsAtOrigin, captureAtPoint or
// hostLinkPoint in production; it carries them so that one golden file serves all three runtimes.

import { MODEL_VERSION, NSEG, cmpStr, min2, segmentIndex } from './core';
import { haversineM, walkWeight } from './geometry';
import { seed, seedNumber, segmentSeed } from './seeds';
import type {
  Assumptions,
  Exclusion,
  Host,
  HostCapture,
  LocationVectors,
  Outlet,
  RegimePair,
  RivalWeightSeed,
  SourcePoint,
  Visibility,
} from './types';

// 02_MODEL 1.5: source points and outlets are processed in ascending id. The input is not modified.
function byId<T extends { id: string }>(items: readonly T[]): T[] {
  return items.slice().sort((a, b) => cmpStr(a.id, b.id));
}

/** The pull of food outlets on people standing at (lat, lng), per competition regime. */
export function rivalsAtOrigin(A: Assumptions, lat: number, lng: number, outlets: readonly Outlet[]): RegimePair {
  const out: RegimePair = { day: 0.0, eve: 0.0 };
  const sorted = byId(outlets);
  for (let k = 0; k < sorted.length; k++) {
    const o = sorted[k];
    const f = walkWeight(A, haversineM(lat, lng, o.lat, o.lng));
    if (f === 0.0) continue;
    const w = seed<RivalWeightSeed>(A, 'kernel.rival_weight.' + o.kind);
    if (typeof w.day !== 'number' || typeof w.eve !== 'number') throw new TypeError('not a rival kind: ' + String(o.kind));
    out.day += w.day * f;
    out.eve += w.eve * f;
  }
  return out;
}

/**
 * What a declared host removes from the catchment so that its people are not counted twice: (1) the
 * linked place's own source point; (2) for workers and residents, up to host.size units of the same
 * segment from the nearest points. The removed people come back through the host term.
 */
export function hostExclusion(A: Assumptions, host: Host | null): Exclusion {
  if (host == null) return { point_ids: [], segment: null, amount: 0.0 };
  const ids: string[] = host.point_id != null ? [host.point_id] : [];
  const group = segmentSeed(A, host.segment).group;
  if (group === 'workers' || group === 'residents') {
    return { point_ids: ids, segment: host.segment, amount: host.size };
  }
  return { point_ids: ids, segment: null, amount: 0.0 };
}

/**
 * For a venue host that carries no link: the id of the nearest source point holding the host's segment
 * within host.venue_link_radius_m (nearest by whole millimetres, ties to the smaller id), or null.
 */
export function hostLinkPoint(
  A: Assumptions,
  lat: number,
  lng: number,
  host: Host,
  sources: readonly SourcePoint[],
): string | null {
  const si = segmentIndex(host.segment);
  let bestId: string | null = null;
  let bestKey = 0;
  const sorted = byId(sources);
  for (let k = 0; k < sorted.length; k++) {
    const c = sorted[k];
    if (c.base[si] <= 0) continue;
    const d = haversineM(lat, lng, c.lat, c.lng);
    if (d > seed<number>(A, 'host.venue_link_radius_m')) continue;
    const key = Math.floor(d * 1000.0 + 0.5);
    if (bestId === null || key < bestKey) {
      bestId = c.id;
      bestKey = key;
    }
  }
  return bestId;
}

interface CaptureRow {
  id: string;
  d: number;
  f: number;
  base: number[];
  rivals: RegimePair;
}

/**
 * The location vectors of a truck parked at (lat, lng).
 *
 * capture[regime][s]  units of segment-s base the truck would win per unit of presence and intent
 * nearby[s]           distance-weighted base within walking distance
 * within[s]           base within the cutoff, after exclusion, not distance-weighted
 * rivals[regime]      pull of food outlets at the truck's own position
 *
 * The backend sets in_region, region_id and dataset_version on what it returns.
 */
export function captureAtPoint(
  A: Assumptions,
  lat: number,
  lng: number,
  visibility: Visibility,
  sources: readonly SourcePoint[],
  outlets: readonly Outlet[],
  exclusion: Exclusion,
): LocationVectors {
  const V = seedNumber(A, 'kernel.visibility.' + visibility);
  const A0 = seed<number>(A, 'kernel.outside_option_a0');
  const rows: CaptureRow[] = [];
  const sorted = byId(sources);
  for (let k = 0; k < sorted.length; k++) {
    const c = sorted[k];
    if (exclusion.point_ids.indexOf(c.id) >= 0) continue;
    const d = haversineM(lat, lng, c.lat, c.lng);
    const f = walkWeight(A, d);
    if (f === 0.0) continue;
    rows.push({ id: c.id, d, f, base: c.base.slice(), rivals: c.rivals });
  }
  let taken = 0.0;
  if (exclusion.segment != null && exclusion.amount > 0) {
    const si = segmentIndex(exclusion.segment);
    let remaining = exclusion.amount;
    const radius = seed<number>(A, 'host.exclusion_radius_m');
    const near: { key: number; row: CaptureRow }[] = [];
    for (let k = 0; k < rows.length; k++) {
      if (rows[k].d <= radius) near.push({ key: Math.floor(rows[k].d * 1000.0 + 0.5), row: rows[k] });
    }
    near.sort((a, b) => (a.key !== b.key ? a.key - b.key : cmpStr(a.row.id, b.row.id))); // nearest first, ties by id
    for (let k = 0; k < near.length; k++) {
      if (remaining <= 0) break;
      const r = near[k].row;
      const take = min2(r.base[si], remaining);
      r.base[si] -= take;
      remaining -= take;
      taken += take;
    }
  }
  const captureDay = new Array<number>(NSEG).fill(0.0);
  const captureEve = new Array<number>(NSEG).fill(0.0);
  const nearby = new Array<number>(NSEG).fill(0.0);
  const within = new Array<number>(NSEG).fill(0.0);
  for (let k = 0; k < rows.length; k++) {
    // still ascending id
    const r = rows[k];
    const shareDay = (r.f * V) / (A0 + r.f * V + r.rivals.day);
    for (let s = 0; s < NSEG; s++) captureDay[s] += r.base[s] * shareDay;
    const shareEve = (r.f * V) / (A0 + r.f * V + r.rivals.eve);
    for (let s = 0; s < NSEG; s++) captureEve[s] += r.base[s] * shareEve;
    for (let s = 0; s < NSEG; s++) {
      nearby[s] += r.base[s] * r.f;
      within[s] += r.base[s];
    }
  }
  return {
    capture: { day: captureDay, eve: captureEve },
    nearby,
    within,
    rivals: rivalsAtOrigin(A, lat, lng, outlets),
    visibility,
    in_region: true,
    region_id: null,
    exclusion,
    excluded_amount: taken,
    points_used: rows.length,
    dataset_version: null,
    model_version: MODEL_VERSION,
  };
}

/**
 * The host's own people the truck would win per unit of presence and intent, per regime.
 *
 * captive (v_nightlife, v_events): people inside a venue; a flat share, the truck being the only food
 * or not. open (everything else): the host's people are a source at distance zero, with the on-site
 * kitchen (if any) as an extra rival of weight K. rivalsHere is LocationVectors.rivals.
 */
export function hostCapture(
  A: Assumptions,
  host: Host | null,
  visibility: Visibility,
  rivalsHere: RegimePair,
): HostCapture {
  if (host == null || host.size <= 0) {
    return { day: 0.0, eve: 0.0, share: { day: 0.0, eve: 0.0 }, mode: null };
  }
  const mode = segmentSeed(A, host.segment).host_mode;
  let shareDay: number;
  let shareEve: number;
  if (mode === 'captive') {
    const sh = host.only_food ? seed<number>(A, 'host.captive_share') : seed<number>(A, 'host.shared_kitchen_share');
    shareDay = sh;
    shareEve = sh;
  } else {
    const V = seedNumber(A, 'kernel.visibility.' + visibility);
    const A0 = seed<number>(A, 'kernel.outside_option_a0');
    const K = host.only_food ? 0.0 : seed<number>(A, 'host.onsite_kitchen_weight');
    shareDay = V / (A0 + V + rivalsHere.day + K);
    shareEve = V / (A0 + V + rivalsHere.eve + K);
  }
  return {
    day: host.size * shareDay,
    eve: host.size * shareEve,
    share: { day: shareDay, eve: shareEve },
    mode,
  };
}
