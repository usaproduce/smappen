// Truck Planner estimator - demand and orders (02_MODEL 4.7).

import { ModelError, NSEG, SEGMENTS, floorDiv, hasOwn, max2, min2, qkey, segmentIndex } from './core';
import { hostCapture, hostExclusion } from './capture';
import { hourColumn } from './curves';
import { typicalContext } from './dates';
import { evidenceFrom, intervalCapped } from './ranges';
import { seed } from './seeds';
import { weatherMultiplier } from './weather';
import type {
  Assumptions,
  BestWindow,
  CalibrationState,
  ClockHour,
  DayContext,
  Daypart,
  HostMode,
  HourHost,
  HourResult,
  HourSegment,
  LocationVectors,
  Regime,
  SpotTerms,
  TruckProfile,
  WeatherDetail,
  WeatherState,
  WindowHour,
  WindowResult,
} from './types';

/** [truck_factor, spot_factor]: what the owner's logged services say. [1.0, 1.0] without logs. */
export function calibrationFactor(cal: CalibrationState | null, spotId: string | null): [number, number] {
  if (cal == null) return [1.0, 1.0];
  if (spotId != null && hasOwn(cal.spots, spotId)) return [cal.truck_factor, cal.spots[spotId].factor];
  return [cal.truck_factor, 1.0];
}

/**
 * Expected orders in one clock hour (0..23) of one context, with every step of the breakdown.
 *
 * demand_raw   before weather and calibration
 * demand_adj   after them, before the capacity cap
 * orders       after the cap, applied once to the hour's total; demand above capacity is lost
 */
export function hourlyOrders(
  A: Assumptions,
  profile: TruckProfile,
  terms: SpotTerms,
  vectors: LocationVectors,
  cal: CalibrationState | null,
  ctx: DayContext,
  hour: number,
): HourResult {
  const w = hourColumn(A, ctx, hour);
  const regime = seed<Regime[]>(A, 'hours.regime_of_hour')[hour];
  const daypart = seed<Daypart[]>(A, 'hours.daypart_of_hour')[hour];
  const fit = profile.daypart_fit[daypart];
  let wxOpen = 1.0;
  let wxCap = 1.0;
  let weatherState: WeatherState = 'typical';
  let detailOpen: WeatherDetail | null = null;
  let detailCap: WeatherDetail | null = null;
  if (!ctx.typical) {
    const record = ctx.forecast != null ? ctx.forecast[hour] : null;
    const fc = record == null ? null : record;
    detailOpen = weatherMultiplier(A, fc, 'open');
    detailCap = weatherMultiplier(A, fc, 'captive');
    wxOpen = detailOpen.multiplier;
    wxCap = detailCap.multiplier;
    weatherState = detailOpen.missing ? 'missing' : 'forecast';
  }
  const factors = calibrationFactor(cal, terms.spot_id);
  const tf = factors[0];
  const sf = factors[1];
  const calib = tf * sf;

  const captureRow = vectors.capture[regime];
  const within = vectors.within == null ? null : vectors.within; // null when decoded from 50 stored numbers
  let demandRaw = 0.0;
  let demandAdj = 0.0;
  let weak = 0.0;
  let defaultPart = 0.0;
  const segments: HourSegment[] = [];
  for (let s = 0; s < NSEG; s++) {
    const presence = w.presence[s];
    const intent = w.intent[s];
    const rawS = captureRow[s] * presence * intent * fit;
    const adjS = rawS * wxOpen * calib;
    demandRaw += rawS;
    demandAdj += adjS;
    if (A.seeds.segments[SEGMENTS[s]].weak) weak += adjS;
    segments.push({
      segment: SEGMENTS[s],
      nearby_present: vectors.nearby[s] * presence,
      within_present: within !== null ? within[s] * presence : null,
      capture: captureRow[s],
      presence,
      intent,
      demand_raw: rawS,
      before_cap: adjS,
      orders: 0.0,
    });
  }

  let hostRow: HourHost | null = null;
  const host = terms.host;
  if (host != null && host.size > 0) {
    const hc = hostCapture(A, host, terms.visibility, vectors.rivals);
    const hs = segmentIndex(host.segment);
    const presence = w.presence[hs];
    const intent = w.intent[hs];
    const rawH = hc[regime] * presence * intent * fit;
    const wxH = hc.mode === 'captive' ? wxCap : wxOpen;
    const adjH = rawH * wxH * calib;
    demandRaw += rawH; // the host is added after the 16 segments
    demandAdj += adjH;
    if (A.seeds.segments[host.segment].weak) weak += adjH;
    if (host.size_source === 'default') defaultPart = adjH;
    hostRow = {
      segment: host.segment,
      mode: hc.mode as HostMode, // never null for a host of size > 0
      size: host.size,
      share: hc.share[regime],
      people_present: host.size * presence,
      presence,
      intent,
      demand_raw: rawH,
      weather: wxH,
      before_cap: adjH,
      orders: 0.0,
    };
  }

  const capacity = profile.capacity_orders_per_hour;
  const orders = min2(demandAdj, capacity);
  const capped = demandAdj > capacity;
  const scale = demandAdj > 0 ? orders / demandAdj : 0.0;
  // who the customers would be, after the cap
  for (let s = 0; s < NSEG; s++) segments[s].orders = segments[s].before_cap * scale;
  if (hostRow !== null) hostRow.orders = hostRow.before_cap * scale;

  return {
    date: ctx.date,
    hour,
    how: ctx.dow * 24 + hour,
    regime,
    daypart,
    segments,
    host: hostRow,
    factors: {
      menu_fit: fit,
      weather_open: wxOpen,
      weather_captive: wxCap,
      weather_state: weatherState,
      weather_detail: detailOpen,
      weather_detail_captive: detailCap,
      truck_factor: tf,
      spot_factor: sf,
    },
    demand_raw: demandRaw,
    demand_adj: demandAdj,
    capacity,
    orders,
    capped,
    weak_part: weak,
    default_size_part: defaultPart,
  };
}

/**
 * Were these vectors built for these terms (same visibility, same host exclusion)? dayPlan warns
 * stale_vectors when not. The two amounts are compared as doubles for exact equality.
 */
export function vectorsMatch(A: Assumptions, terms: SpotTerms, vectors: LocationVectors): boolean {
  const e = hostExclusion(A, terms.host);
  const x = vectors.exclusion;
  if (vectors.visibility !== terms.visibility) return false;
  if (x.point_ids.length !== e.point_ids.length) return false;
  for (let k = 0; k < e.point_ids.length; k++) {
    if (x.point_ids[k] !== e.point_ids[k]) return false; // the same ids in the same order
  }
  return x.segment === e.segment && x.amount === e.amount;
}

/**
 * The loop of windowOrders: every clock hour overlapping [open, close), with the minutes it covers and
 * the share of the hour (fraction). Nothing when open == close, even inside a clock hour.
 */
export function clockHours(open: number, close: number): ClockHour[] {
  const out: ClockHour[] = [];
  if (open === close) return out;
  let hAbs = floorDiv(open, 60);
  while (hAbs * 60 < close) {
    const start = max2(hAbs * 60, open);
    const end = min2((hAbs + 1) * 60, close);
    const dayIndex = floorDiv(hAbs, 24);
    out.push({ day_index: dayIndex, hour: hAbs - 24 * dayIndex, start, end, fraction: (end - start) / 60.0 });
    hAbs += 1;
  }
  return out;
}

/**
 * Expected orders over a service window [open, close) in minutes from local midnight of ctx.date.
 * Hours at or after 1440 belong to the next civil date and use ctxNext (it may be null only when
 * close <= 1440). A partial hour contributes its fraction of that hour's capped orders. Raises
 * invalid_window unless 0 <= open <= close <= 2880, and missing_context when ctxNext is needed and null.
 */
export function windowOrders(
  A: Assumptions,
  profile: TruckProfile,
  terms: SpotTerms,
  vectors: LocationVectors,
  cal: CalibrationState | null,
  ctx: DayContext,
  ctxNext: DayContext | null,
  open: number,
  close: number,
): WindowResult {
  if (!(0 <= open && open <= close && close <= 2880)) throw new ModelError('invalid_window');
  let adjTotal = 0.0;
  let weak = 0.0;
  let dflt = 0.0;
  let capTotal = 0.0;
  let hostOrders = 0.0;
  const bySegment = new Array<number>(NSEG).fill(0.0);
  let cappedHours = 0;
  const hours: WindowHour[] = [];
  const d: number[] = [];
  const c: number[] = [];
  const loop = clockHours(open, close);
  for (let k = 0; k < loop.length; k++) {
    const fraction = loop[k].fraction;
    const cx = loop[k].day_index === 0 ? ctx : ctxNext;
    if (cx == null) throw new ModelError('missing_context');
    const r = hourlyOrders(A, profile, terms, vectors, cal, cx, loop[k].hour);
    d.push(r.demand_adj * fraction);
    c.push(r.capacity * fraction);
    adjTotal += r.demand_adj * fraction;
    capTotal += r.capacity * fraction;
    weak += r.weak_part * fraction;
    dflt += r.default_size_part * fraction;
    for (let s = 0; s < NSEG; s++) bySegment[s] += r.segments[s].orders * fraction;
    if (r.host !== null) hostOrders += r.host.orders * fraction;
    if (r.capped) cappedHours += 1;
    // 0 <= open <= close <= 2880, so an hour lies on the service date (0) or on the next one (1)
    hours.push({ day_index: loop[k].day_index as 0 | 1, hour: loop[k].hour, fraction, result: r });
  }
  const evidence = evidenceFrom(cal, terms.spot_id);
  evidence.weak_share = adjTotal > 0 ? weak / adjTotal : 0.0;
  evidence.default_size_share = adjTotal > 0 ? dflt / adjTotal : 0.0;
  const pair = intervalCapped(A, d, c, evidence);
  return {
    date: ctx.date,
    open_minute: open,
    close_minute: close,
    minutes: close - open,
    hours,
    orders: pair[0],
    by_segment: bySegment,
    host_orders: hostOrders,
    demand_adj: adjTotal,
    capacity_total: capTotal,
    capped_hours: cappedHours,
    evidence,
    spread: pair[1],
  };
}

/** Expected orders for each of the 168 hours of a typical week (no date, no weather). */
export function weekStrip(
  A: Assumptions,
  profile: TruckProfile,
  terms: SpotTerms,
  vectors: LocationVectors,
  cal: CalibrationState | null,
): number[] {
  const out = new Array<number>(168).fill(0.0);
  for (let dow = 0; dow < 7; dow++) {
    const cx = typicalContext(A, dow);
    for (let hour = 0; hour < 24; hour++) {
      out[dow * 24 + hour] = hourlyOrders(A, profile, terms, vectors, cal, cx, hour).orders;
    }
  }
  return out;
}

/**
 * Greedy: the best run of `length` consecutive values, then the best that does not overlap it, and so
 * on, up to topN. Ties go to the earlier start. With circular = true a window may wrap from the end to
 * the start. Windows that touch a masked index (allowed[i] false) or whose total rounds to zero
 * millionths are never returned.
 */
export function bestWindows(
  values: readonly number[],
  length: number,
  topN: number,
  circular: boolean,
  allowed: readonly boolean[] | null = null,
): BestWindow[] {
  const n = values.length;
  if (length > n) return [];
  const candidates: { start: number; total: number; key: number }[] = [];
  const lastStart = circular ? n - 1 : n - length;
  for (let start = 0; start <= lastStart; start++) {
    let total = 0.0;
    let ok = true;
    for (let k = 0; k < length; k++) {
      let i = start + k;
      if (i >= n) i -= n;
      if (allowed != null && !allowed[i]) {
        ok = false;
        break;
      }
      total += values[i];
    }
    if (ok) {
      const key = qkey(total);
      if (key > 0) candidates.push({ start, total, key });
    }
  }
  candidates.sort((a, b) => (a.key !== b.key ? (a.key > b.key ? -1 : 1) : a.start - b.start));
  const picked: BestWindow[] = [];
  const used = new Array<boolean>(n).fill(false);
  for (let j = 0; j < candidates.length; j++) {
    if (picked.length === topN) break;
    const cand = candidates[j];
    let clash = false;
    for (let k = 0; k < length; k++) {
      let i = cand.start + k;
      if (i >= n) i -= n;
      if (used[i]) clash = true;
    }
    if (clash) continue;
    for (let k = 0; k < length; k++) {
      let i = cand.start + k;
      if (i >= n) i -= n;
      used[i] = true;
    }
    picked.push({ start: cand.start, length, total: cand.total });
  }
  return picked;
}
