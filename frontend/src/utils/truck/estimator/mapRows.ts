// Truck Planner estimator - map fast path, the definition (02_MODEL 4.17).
//
// These are the plain-array forms that the golden cases run. fastPath.ts holds the bulk scorer the map
// uses; it must agree with cellScores here.

import { NSEG, clamp, min2, modFloor, nonZero } from './core';
import { expandCurves, hourWeights } from './curves';
import { seed } from './seeds';
import type {
  Assumptions,
  CalibrationState,
  CellScores,
  DayContext,
  Daypart,
  MapWeightRows,
  Regime,
  TruckProfile,
} from './types';

/**
 * Weight rows for the 168 hours of a typical week. Per hour of the week and segment: w_opp turns a
 * capture vector into expected orders (presence x intent x menu fit x truck factor), w_people a nearby
 * vector into people present. The map never applies weather, spot factors or hosts.
 */
export function mapWeightRows(A: Assumptions, profile: TruckProfile, cal: CalibrationState | null): MapWeightRows {
  const E = expandCurves(A);
  const tf = cal != null ? cal.truck_factor : 1.0;
  const daypartOfHour = seed<Daypart[]>(A, 'hours.daypart_of_hour');
  const wOpp: number[][] = [];
  const wPeople: number[][] = [];
  for (let how = 0; how < 168; how++) {
    const fit = profile.daypart_fit[daypartOfHour[modFloor(how, 24)]];
    const opp: number[] = [];
    const people: number[] = [];
    for (let s = 0; s < NSEG; s++) {
      opp.push(E.presence[s][how] * E.intent[s][how] * fit * tf);
      people.push(E.presence[s][how]);
    }
    wOpp.push(opp);
    wPeople.push(people);
  }
  return { w_opp: wOpp, w_people: wPeople };
}

/**
 * Weight rows for the 24 clock hours of one context: the rows of mapWeightRows built from
 * hourWeights(A, ctx) instead of the typical week (a selected date with its holiday pattern or its
 * "treat this day as"). For typicalContext(A, dow) they equal rows dow * 24 .. dow * 24 + 23 of
 * mapWeightRows exactly.
 */
export function dayWeightRows(
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
  ctx: DayContext,
): MapWeightRows {
  const w = hourWeights(A, ctx);
  const tf = cal != null ? cal.truck_factor : 1.0;
  const daypartOfHour = seed<Daypart[]>(A, 'hours.daypart_of_hour');
  const wOpp: number[][] = [];
  const wPeople: number[][] = [];
  for (let hour = 0; hour < 24; hour++) {
    const fit = profile.daypart_fit[daypartOfHour[hour]];
    const opp: number[] = [];
    const people: number[] = [];
    for (let s = 0; s < NSEG; s++) {
      opp.push(w.presence[s][hour] * w.intent[s][hour] * fit * tf);
      people.push(w.presence[s][hour]);
    }
    wOpp.push(opp);
    wPeople.push(people);
  }
  return { w_opp: wOpp, w_people: wPeople };
}

/**
 * Score n map cells for one hour. features holds 50 numbers per cell, row-major: capture.day[16],
 * capture.eve[16], nearby[16], rivals.day, rivals.eve. opportunity is capped at capacity.
 */
export function cellScores(
  features: ArrayLike<number>,
  n: number,
  wOppRow: ArrayLike<number>,
  wPeopleRow: ArrayLike<number>,
  regime: Regime,
  capacity: number,
): CellScores {
  const off = regime === 'day' ? 0 : 16;
  const ri = regime === 'day' ? 48 : 49;
  const opportunity = new Array<number>(n).fill(0.0);
  const people = new Array<number>(n).fill(0.0);
  const competition = new Array<number>(n).fill(0.0);
  for (let cell = 0; cell < n; cell++) {
    const b = 50 * cell;
    let o = 0.0;
    for (let s = 0; s < NSEG; s++) o += features[b + off + s] * wOppRow[s];
    opportunity[cell] = min2(o, capacity);
    let p = 0.0;
    for (let s = 0; s < NSEG; s++) p += features[b + 32 + s] * wPeopleRow[s];
    people[cell] = p;
    competition[cell] = features[b + ri];
  }
  return { opportunity, people, competition };
}

/** Map colour byte 0..255 on a square-root scale with a fixed top `hi`. */
export function scoreByte(x: number, hi: number): number {
  const t = clamp(x / nonZero(hi, 'hi'), 0.0, 1.0);
  return Math.floor(255.0 * Math.sqrt(t) + 0.5);
}
