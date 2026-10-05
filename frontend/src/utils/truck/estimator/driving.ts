// Truck Planner estimator - driving (02_MODEL 4.10).

import { floorDiv, min2, modFloor, roundHalfAway } from './core';
import { haversineM } from './geometry';
import { seed } from './seeds';
import type { Assumptions, DayContext, Leg, LegInput, LegResultSource, TruckProfile } from './types';

/**
 * A labelled straight-line estimate for when no routed leg exists: road distance = straight line x
 * detour factor; the first local miles at the local speed, the rest at the trunk speed; free-flow.
 */
export function fallbackLeg(A: Assumptions, lat1: number, lng1: number, lat2: number, lng2: number): LegInput {
  const roadM = haversineM(lat1, lng1, lat2, lng2) * seed<number>(A, 'drive_fallback.detour_factor');
  const miles = roadM / 1609.344;
  const local = min2(miles, seed<number>(A, 'drive_fallback.local_miles'));
  const ffMin =
    (local / seed<number>(A, 'drive_fallback.local_mph')) * 60.0 +
    ((miles - local) / seed<number>(A, 'drive_fallback.trunk_mph')) * 60.0;
  return { source: 'fallback', distance_m: roadM, duration_s: ffMin * 60.0, override_minutes: null, toll: 0.0 };
}

/**
 * [factor, dow, hour]: travel time over free-flow time for the clock hour containing `minute`. On the
 * service date the context's traffic_dow applies; a minute before that date's midnight or after its
 * end belongs to a neighbouring civil date and uses that date's real day of the week.
 */
export function trafficFactor(A: Assumptions, ctx: DayContext, minute: number): [number, number, number] {
  const dayShift = floorDiv(minute, 1440);
  const m = minute - 1440 * dayShift;
  const dow = dayShift === 0 ? ctx.traffic_dow : modFloor(ctx.dow + dayShift, 7);
  const hour = floorDiv(m, 60);
  return [seed<number[][]>(A, 'traffic.' + A.region.traffic_matrix)[dow][hour], dow, hour];
}

/**
 * Drive minutes of one leg for a departure in the clock hour of lookupMinute.
 *
 * fallback leg: free-flow minutes x table factor. google leg: Google's traffic-unaware duration already
 * holds average traffic, so it is scaled by factor / the table's typical value. The owner's override
 * replaces the whole computation. Whole minutes; at least 1 for any real distance.
 */
export function legMinutes(
  A: Assumptions,
  profile: TruckProfile,
  leg: LegInput,
  ctx: DayContext,
  lookupMinute: number,
): Leg {
  const baseMinutes = leg.duration_s / 60.0;
  const lookup = trafficFactor(A, ctx, lookupMinute);
  const factor = lookup[0];
  let timeFactor: number;
  if (leg.source === 'google') {
    timeFactor = factor / seed<number>(A, 'traffic.' + A.region.traffic_matrix + '_typical');
  } else {
    timeFactor = factor;
  }
  const raw = baseMinutes * timeFactor * profile.truck_time_factor;
  let minutes: number;
  let source: LegResultSource;
  if (leg.override_minutes != null) {
    minutes = leg.override_minutes;
    source = 'override';
  } else {
    minutes = roundHalfAway(raw, 0);
    if (minutes < 1 && leg.distance_m > 0) minutes = 1;
    source = leg.source;
  }
  const miles = leg.distance_m / 1609.344;
  return {
    from_id: null,
    to_id: null,
    source,
    distance_m: leg.distance_m,
    miles,
    base_minutes: baseMinutes,
    depart_minute: null,
    traffic_lookup_minute: lookupMinute,
    traffic_dow: lookup[1],
    traffic_hour: lookup[2],
    traffic_factor: factor,
    time_factor: timeFactor,
    truck_time_factor: profile.truck_time_factor,
    raw_minutes: raw,
    minutes,
    toll: leg.toll,
  };
}
