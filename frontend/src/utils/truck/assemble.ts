// Truck Planner - from API payloads to model inputs (docs/truck-planner/05_FRONTEND.md 2.5).
//
// Pure functions, no I/O, no clock. Together with api/truck.ts this is the only file that changes
// when a payload changes. Nothing here does model maths: it only arranges what the server sent into
// the shapes the estimator takes, and builds the cache keys of 2.2.

import {
  MODEL_VERSION,
  SEEDS,
  SEEDS_REVISION,
  dayContext,
  roundHalfAway,
  seed,
  typicalContext,
} from './model';
import type {
  Assumptions,
  DayContext,
  LatLng,
  LegMap,
  LocationVectors,
  PlanInput,
  SegmentKey,
  SpotTerms,
  StopInput,
  TreatAs,
  Visibility,
} from './model';
import type { AssumptionsInfo, DayInfo, DriveLeg, DrivePoint, FuelInfo, PlanStop, Spot } from '../../api/truck';

/** Plain code-unit order: the model's rule for identifiers (02_MODEL 1.2). Never a locale comparison. */
function byCodeUnit(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

// -------------------------------------------------------------------------------------------------
// Assumptions
// -------------------------------------------------------------------------------------------------

/**
 * The server runs another model version or another seeds revision than this bundle. Browser and
 * server numbers would disagree, so nothing may be estimated: the gate shows the reload card.
 */
export class VersionMismatch extends Error {
  readonly serverModelVersion: string;
  readonly serverSeedsRevision: number;

  constructor(serverModelVersion: string, serverSeedsRevision: number) {
    super('Truck Planner version mismatch');
    this.name = 'VersionMismatch';
    this.serverModelVersion = serverModelVersion;
    this.serverSeedsRevision = serverSeedsRevision;
  }
}

/** True when a server answer was produced by the model version and seeds revision of this bundle. */
export function versionsMatch(modelVersion: string, seedsRevision: number): boolean {
  return modelVersion === MODEL_VERSION && seedsRevision === SEEDS_REVISION;
}

/** The server's assumptions plus this bundle's seed file. Throws `VersionMismatch` when they differ. */
export function buildAssumptions(info: AssumptionsInfo): Assumptions {
  if (!versionsMatch(info.model_version, info.seeds_revision)) {
    throw new VersionMismatch(info.model_version, info.seeds_revision);
  }
  return { ...info, model_version: MODEL_VERSION, seeds: SEEDS };
}

// -------------------------------------------------------------------------------------------------
// Day contexts
// -------------------------------------------------------------------------------------------------

/**
 * The context of one civil date, rebuilt in the browser so that "Treat this day as" is instant.
 * The forecast passes through untouched (a null `precip_prob` stays null). With `treatAs` null the
 * result must equal the server's `day.context`; development builds check that.
 */
export function buildContext(A: Assumptions, day: DayInfo, treatAs: TreatAs | null): DayContext {
  const ctx = dayContext(
    A,
    day.date,
    treatAs,
    day.context.forecast,
    day.context.fuel_price_per_gal,
    day.context.fuel_price_source,
  );
  if (import.meta.env.DEV && treatAs === null && !sameWithinTolerance(ctx, day.context)) {
    console.warn('[truck] estimator drift', 'day context', day.date, { local: ctx, server: day.context });
  }
  return ctx;
}

/** The same without a forecast and with the fuel price of the bootstrap answer: used when `day-context` cannot be loaded. */
export function degradedContext(A: Assumptions, date: string, treatAs: TreatAs | null, fuel: FuelInfo): DayContext {
  return dayContext(A, date, treatAs, null, fuel.price_per_gal, fuel.source);
}

/**
 * A typical-week context with a fuel price added, for one-stop-day figures on a typical week.
 * `dayPlan` only needs a fuel price in its context, so this is valid input; it raises neither
 * `no_forecast` nor `holiday`.
 */
export function typicalWithFuel(A: Assumptions, dow: number, fuel: FuelInfo): DayContext {
  return { ...typicalContext(A, dow), fuel_price_per_gal: fuel.price_per_gal, fuel_price_source: fuel.source };
}

/**
 * True when the region's time-of-day traffic table changes nothing: every value of
 * `traffic.<matrix>` and its `_typical` value equal 1.0. Drive-time wording then drops the
 * time-of-day adjustment.
 */
export function trafficIsNeutral(A: Assumptions): boolean {
  const matrix = seed<number[][]>(A, 'traffic.' + A.region.traffic_matrix);
  const typical = seed<number>(A, 'traffic.' + A.region.traffic_matrix + '_typical');
  if (typical !== 1.0) return false;
  for (const row of matrix) {
    for (const value of row) {
      if (value !== 1.0) return false;
    }
  }
  return true;
}

// -------------------------------------------------------------------------------------------------
// Spots and stops
// -------------------------------------------------------------------------------------------------

/**
 * The stored vectors of a spot for one visibility (default: the spot's own), or null when the spot
 * has none. This is the ONLY reader of `Spot.vectors`.
 */
export function spotVectors(spot: Spot, visibility: Visibility = spot.terms.visibility): LocationVectors | null {
  if (spot.vectors === null) return null;
  return spot.vectors[visibility] ?? null;
}

/** Spots by id, archived ones included when the list holds them. */
export function indexSpots(spots: readonly Spot[]): Map<string, Spot> {
  const byId = new Map<string, Spot>();
  for (const spot of spots) byId.set(spot.id, spot);
  return byId;
}

/** What `toStopInput` reads of a stop: a saved `PlanStop` or a draft stop of the planner. */
export type StopLike = Pick<
  PlanStop,
  | 'id'
  | 'kind'
  | 'spot_id'
  | 'point'
  | 'open_minute'
  | 'close_minute'
  | 'gap_before_unpaid'
  | 'setup_minutes'
  | 'teardown_minutes'
  | 'fee_flat'
  | 'fee_pct'
  | 'fee_min'
  | 'event'
  | 'catering'
>;

/**
 * One stop as the model takes it.
 *
 * - `spot`: point and terms (with `spot_id`) of the `Spot`, vectors through `spotVectors` (an
 *   archived spot still evaluates). `vectors` is null when the spot has none stored: such a stop
 *   cannot be evaluated and `stopsEvaluable` says so.
 * - `event`: neutral terms that carry only the fee, plus `event`.
 * - `catering`: `catering`.
 *
 * `id` is the stop's own id (a temporary id for an unsaved stop). Returns null when the stop has no
 * place yet: a spot stop whose spot is not in `spotsById`, or an event or catering stop without a point.
 */
export function toStopInput(stop: StopLike, spotsById: ReadonlyMap<string, Spot>): StopInput | null {
  const base = {
    id: stop.id,
    kind: stop.kind,
    open_minute: stop.open_minute,
    close_minute: stop.close_minute,
    gap_before_unpaid: stop.gap_before_unpaid,
    setup_minutes: stop.setup_minutes,
    teardown_minutes: stop.teardown_minutes,
  };
  if (stop.kind === 'spot') {
    const spot = stop.spot_id === null ? undefined : spotsById.get(stop.spot_id);
    if (spot === undefined) return null;
    return {
      ...base,
      spot_id: spot.id,
      point: { lat: spot.point.lat, lng: spot.point.lng },
      terms: { ...spot.terms, spot_id: spot.id },
      vectors: spotVectors(spot),
      event: null,
      catering: null,
    };
  }
  if (stop.point === null) return null;
  const point: LatLng = { lat: stop.point.lat, lng: stop.point.lng };
  if (stop.kind === 'event') {
    const terms: SpotTerms = {
      spot_id: null,
      visibility: 'normal',
      host: null,
      fee_flat: stop.fee_flat,
      fee_pct: stop.fee_pct,
      fee_min: stop.fee_min,
      allowed: null,
    };
    return { ...base, spot_id: null, point, terms, vectors: null, event: stop.event, catering: null };
  }
  return { ...base, spot_id: null, point, terms: null, vectors: null, event: null, catering: stop.catering };
}

/**
 * True when `dayPlan` can take these stops: every stop has a place, every spot stop has terms and
 * vectors, every event stop its event terms and every catering stop its catering terms. The model
 * does not check this itself.
 */
export function stopsEvaluable(stops: readonly (StopInput | null)[]): stops is StopInput[] {
  for (const stop of stops) {
    if (stop === null) return false;
    if (stop.kind === 'spot' && (stop.terms === null || stop.vectors === null)) return false;
    if (stop.kind === 'event' && (stop.terms === null || stop.event === null)) return false;
    if (stop.kind === 'catering' && stop.catering === null) return false;
  }
  return true;
}

/** A day as the owner ordered it. The stops are never reordered. */
export function toPlanInput(date: string, stops: readonly StopInput[]): PlanInput {
  return { date, stops: stops.slice() };
}

/**
 * Drive legs keyed `<from_id>><to_id>`, as the model looks them up. A pair that is absent stays
 * absent: the model fills it with `fallbackLeg` and raises `fallback_drive_time`. Never zero-filled.
 */
export function toLegs(legs: readonly DriveLeg[]): LegMap {
  const out: LegMap = {};
  for (const leg of legs) out[leg.from_id + '>' + leg.to_id] = leg.leg_input;
  return out;
}

/** The points of a drive-time request for a day: the truck's base as `base`, then each stop under its own id. */
export function drivePoints(base: LatLng, stops: readonly StopInput[]): DrivePoint[] {
  const points: DrivePoint[] = [{ id: 'base', lat: base.lat, lng: base.lng }];
  for (const stop of stops) points.push({ id: stop.id, lat: stop.point.lat, lng: stop.point.lng });
  return points;
}

// -------------------------------------------------------------------------------------------------
// Cache keys (2.2)
// -------------------------------------------------------------------------------------------------

/**
 * A coordinate with exactly six decimals, rounded half away from zero (`38.960000`), as the server
 * writes it. Never prints a negative zero.
 */
export function coord6(x: number): string {
  const r = roundHalfAway(x, 6);
  const negative = r < 0;
  const millionths = Math.floor((negative ? -r : r) * 1000000 + 0.5);
  const whole = Math.floor(millionths / 1000000);
  let fraction = String(millionths - whole * 1000000);
  while (fraction.length < 6) fraction = '0' + fraction;
  return (negative ? '-' : '') + whole + '.' + fraction;
}

/** What `hostKey` reads of a host as a request describes it. */
export interface HostKeyInput {
  place_key?: string | null;
  segment?: SegmentKey | null;
  size?: number | null;
}

/**
 * The part of a host that changes the vectors the server computes: `-` without a host, else its
 * linked place, its segment and, unless the segment is a visitor segment, its size, joined with `|`.
 * `only_food` and the size of a visitor host change the estimate but not the vectors, so they are
 * left out. A host whose segment the server will derive from the linked place keeps its size in the
 * key, because the segment group is not known here.
 */
export function hostKey(host: HostKeyInput | null | undefined): string {
  if (host === null || host === undefined) return '-';
  const segment = host.segment ?? null;
  const visitors = segment !== null && SEEDS.segments[segment].group === 'visitors';
  const size = visitors || host.size === null || host.size === undefined ? '' : String(host.size);
  return (host.place_key ?? '') + '|' + (segment ?? '') + '|' + size;
}

/**
 * `hostKey` of a host as the model holds it (`Spot.terms.host`, a draft of it) together with the
 * place it is linked to (`Spot.host_details.place_key`). Two hosts with the same key have the same
 * stored vectors, so an edit that keeps the key needs no new capture.
 */
export function linkedHostKey(
  placeKey: string | null | undefined,
  host: { segment: SegmentKey; size: number } | null,
): string {
  if (host === null && !placeKey) return '-';
  return hostKey({ place_key: placeKey ?? null, segment: host?.segment ?? null, size: host?.size ?? null });
}

/** The sorted list of `<id>@<lat6>,<lng6>` joined with `|`: the same set of points gives the same key in any order. */
export function pointsKey(points: readonly DrivePoint[]): string {
  const parts = points.map((p) => p.id + '@' + coord6(p.lat) + ',' + coord6(p.lng));
  parts.sort(byCodeUnit);
  return parts.join('|');
}

/** The sorted visibility list of a simulate request, joined with commas. */
export function visibilityKey(visibilities: readonly Visibility[]): string {
  const sorted = visibilities.slice();
  sorted.sort(byCodeUnit);
  return sorted.join(',');
}

/** `JSON.stringify` with object keys in ascending order at every level: the option keys of 2.2. */
export function sortedJson(value: unknown): string {
  return JSON.stringify(sortKeys(value));
}

function sortKeys(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(sortKeys);
  if (typeof value !== 'object' || value === null) return value;
  const source = value as Record<string, unknown>;
  const keys = Object.keys(source);
  keys.sort(byCodeUnit);
  const out: Record<string, unknown> = {};
  for (const key of keys) {
    if (source[key] !== undefined) out[key] = sortKeys(source[key]);
  }
  return out;
}

// -------------------------------------------------------------------------------------------------
// Comparing browser and server numbers (the development drift checks of 0.2)
// -------------------------------------------------------------------------------------------------

/** The golden-case tolerance of 02_MODEL 1.4: integral values must be equal, others within a relative 1e-9. */
export function withinTolerance(a: number, b: number): boolean {
  if (a === b) return true;
  if (Math.floor(a) === a && Math.floor(b) === b) return false;
  const scale = Math.max(1.0, Math.abs(a), Math.abs(b));
  return Math.abs(a - b) <= 1e-9 * scale;
}

/** Deep comparison of two JSON values: numbers with `withinTolerance`, everything else exactly. */
export function sameWithinTolerance(a: unknown, b: unknown): boolean {
  if (typeof a === 'number' && typeof b === 'number') return withinTolerance(a, b);
  if (a === b) return true;
  if (typeof a !== 'object' || typeof b !== 'object' || a === null || b === null) return false;
  if (Array.isArray(a) !== Array.isArray(b)) return false;
  if (Array.isArray(a) && Array.isArray(b)) {
    if (a.length !== b.length) return false;
    for (let i = 0; i < a.length; i++) {
      if (!sameWithinTolerance(a[i], b[i])) return false;
    }
    return true;
  }
  const left = a as Record<string, unknown>;
  const right = b as Record<string, unknown>;
  const keys = Object.keys(left);
  if (keys.length !== Object.keys(right).length) return false;
  for (const key of keys) {
    if (!Object.prototype.hasOwnProperty.call(right, key)) return false;
    if (!sameWithinTolerance(left[key], right[key])) return false;
  }
  return true;
}
