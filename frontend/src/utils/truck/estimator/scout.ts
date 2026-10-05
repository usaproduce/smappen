// Truck Planner estimator - scouting (02_MODEL 4.16).

import { NSEG, cmpStr, floorDiv, min2, modFloor, nonZero, qkey, segmentIndex } from './core';
import { hostCapture } from './capture';
import { typicalContext } from './dates';
import { bestWindows, windowOrders } from './demand';
import { mapWeightRows } from './mapRows';
import { stopMoney } from './money';
import { evidenceFrom, interval } from './ranges';
import { SEEDS, seed } from './seeds';
import { buildTimeline } from './timeline';
import type {
  Assumptions,
  CalibrationState,
  Host,
  LegMap,
  LocationVectors,
  MapWeightRows,
  PlaceInput,
  PlaceTypeSeed,
  Regime,
  ScoutResult,
  SpotTerms,
  TimelineStopInput,
  TruckProfile,
} from './types';

/**
 * The week strip from the weight rows of mapWeightRows in one pass (truck factor only, no spot
 * factor). Equal to weekStrip to the model tolerance when terms.spot_id is null.
 */
export function stripFromRows(
  A: Assumptions,
  profile: TruckProfile,
  terms: SpotTerms,
  vectors: LocationVectors,
  rows: MapWeightRows,
): number[] {
  const regimeOfHour = seed<Regime[]>(A, 'hours.regime_of_hour');
  const host = terms.host;
  const hasHost = host != null && host.size > 0;
  const hc = hasHost ? hostCapture(A, host, terms.visibility, vectors.rivals) : null;
  const hs = hasHost ? segmentIndex((host as Host).segment) : 0;
  const out = new Array<number>(168).fill(0.0);
  for (let how = 0; how < 168; how++) {
    const regime = regimeOfHour[modFloor(how, 24)];
    let o = 0.0;
    for (let s = 0; s < NSEG; s++) o += vectors.capture[regime][s] * rows.w_opp[how][s];
    if (hc !== null) o += hc[regime] * rows.w_opp[how][hs];
    out[how] = min2(o, profile.capacity_orders_per_hour);
  }
  return out;
}

/**
 * One candidate host: its best window in a typical week, what it would leave, the cost of driving
 * there and back, and a score that ranks (it is never shown as money). null for a place type that does
 * not host trucks. `legs` is keyed "base><place_id>" and "<place_id>>base". position is 0 until
 * scoutRank numbers the results.
 */
export function scoutEstimate(
  A: Assumptions,
  profile: TruckProfile,
  place: PlaceInput,
  legs: LegMap,
  cal: CalibrationState | null,
  fuelPricePerGal: number,
): ScoutResult | null {
  const row = seed<PlaceTypeSeed>(A, 'place_types.rows.' + place.place_type);
  if (row.host_fit <= 0) return null;
  const kitchen: 'yes' | 'no' =
    place.kitchen == null || place.kitchen === 'unknown' ? row.kitchen_default : place.kitchen;
  let host: Host | null = null;
  if (row.host_segment != null && place.size_default > 0) {
    host = {
      segment: row.host_segment,
      size: place.size_default,
      size_source: 'default',
      only_food: kitchen === 'no',
      point_id: place.point_id == null ? null : place.point_id,
      place_type: place.place_type,
    };
  }
  const terms: SpotTerms = {
    spot_id: null,
    visibility: 'normal',
    host,
    fee_flat: 0.0,
    fee_pct: 0.0,
    fee_min: 0.0,
    allowed: null,
  };
  const rows = mapWeightRows(A, profile, cal); // the same for every place of a request
  const strip = stripFromRows(A, profile, terms, place.vectors, rows);
  const windowMinutes = seed<number>(A, 'scout.window_minutes');
  const b = bestWindows(strip, floorDiv(windowMinutes, 60), 1, true);
  const hostSize = host !== null ? place.size_default : 0.0;
  if (b.length === 0) {
    const label = interval(A, 0.0, evidenceFrom(cal, null))[0].confidence;
    return {
      place_id: place.place_id,
      place_type: place.place_type,
      position: 0,
      host_fit: row.host_fit,
      kitchen,
      host_segment: row.host_segment,
      host_size: hostSize,
      size_source: 'default',
      best_window: null,
      orders: { value: 0.0, low: 0.0, high: 0.0, confidence: label },
      contribution: { value: 0.0, low: 0.0, high: 0.0, confidence: label },
      round_trip: { minutes: 0, miles: 0.0, cost: 0.0 },
      score: 0.0,
    };
  }
  const dow = floorDiv(b[0].start, 24);
  const openMinute = modFloor(b[0].start, 24) * 60;
  const closeMinute = openMinute + windowMinutes;
  const ctx = typicalContext(A, dow);
  const W = windowOrders(
    A,
    profile,
    terms,
    place.vectors,
    cal,
    ctx,
    typicalContext(A, modFloor(dow + 1, 7)),
    openMinute,
    closeMinute,
  );
  const money = stopMoney(profile, terms, W.orders);
  const stop: TimelineStopInput = {
    id: place.place_id,
    point: place.point,
    open_minute: openMinute,
    close_minute: closeMinute,
    gap_before_unpaid: false,
    setup_minutes: null,
    teardown_minutes: null,
  };
  const T = buildTimeline(A, profile, ctx, [stop], legs);
  if (typeof fuelPricePerGal !== 'number') {
    throw new TypeError('scoutEstimate: fuel_price_per_gal must be a number');
  }
  const cost =
    (T.drive_minutes / 60.0) * profile.paid_crew * profile.wage_per_hour * (1.0 + profile.payroll_burden_pct) +
    (T.miles / nonZero(profile.mpg, 'profile.mpg')) * fuelPricePerGal +
    T.tolls;
  return {
    place_id: place.place_id,
    place_type: place.place_type,
    position: 0,
    host_fit: row.host_fit,
    kitchen,
    host_segment: row.host_segment,
    host_size: hostSize,
    size_source: 'default',
    best_window: { dow, open_minute: openMinute, close_minute: closeMinute },
    orders: W.orders,
    contribution: money.contribution,
    round_trip: { minutes: T.drive_minutes, miles: T.miles, cost },
    score: row.host_fit * money.contribution.value - cost,
  };
}

/** Best score first (whole millionths), ties by place_id; numbered from 1; at most scout.max_results. */
export function scoutRank(results: readonly ScoutResult[]): ScoutResult[] {
  const keyed: { key: number; r: ScoutResult }[] = [];
  for (let k = 0; k < results.length; k++) keyed.push({ key: qkey(results[k].score), r: results[k] });
  keyed.sort((a, b) => (a.key !== b.key ? (a.key > b.key ? -1 : 1) : cmpStr(a.r.place_id, b.r.place_id)));
  const out: ScoutResult[] = [];
  const top = keyed.slice(0, SEEDS.scout.max_results.value);
  for (let k = 0; k < top.length; k++) out.push({ ...top[k].r, position: out.length + 1 });
  return out;
}
