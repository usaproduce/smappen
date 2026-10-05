// Truck Planner - pure helpers of the Log (docs/truck-planner/05_FRONTEND.md 4.7): which planned stops
// still wait for their numbers, how a logged count sits against its estimate, and the calibration a
// service of a past date is estimated with.

import { calibrate, dayNumber, roundHalfAway } from './model';
import type { Assumptions, CalibrationState, ServiceLogEntry, StopKind } from './model';
import { VERDICT_WORDS, stopFallbackName } from './wording';

/** The part of a plan row (listPlans with stops) that the unlogged list reads. */
export interface PlanLike {
  id: string;
  date: string;
  status: string;
  stops: readonly PlanStopLike[];
}

export interface PlanStopLike {
  id: string;
  kind: StopKind;
  spot_id: string | null;
  label: string;
  open_minute: number;
  close_minute: number;
}

/** The part of a logged service that the unlogged list reads. */
export interface LoggedLike {
  plan_stop_id: string | null;
}

export interface UnloggedStop {
  planId: string;
  date: string;
  stopId: string;
  kind: StopKind;
  spotId: string | null;
  /** The spot's name for a spot stop, the stop's own label for an event or a catering job. */
  name: string;
  openMinute: number;
  closeMinute: number;
  /** Position of the stop in its plan, from 0. */
  stopIndex: number;
}

export interface UnloggedOptions {
  /** Names of the saved spots by id (archived spots included). */
  spotNames?: Readonly<Record<string, string>>;
  /** Plans dated before this date are left out. */
  since?: string;
  /** At most this many stops. */
  limit?: number;
}

/**
 * Stops whose closing time has passed and that no logged service refers to, newest first. Plans
 * that were cancelled are left out; event and catering stops are listed like any other. `now` is
 * the truck's local date and minute of day. A close after midnight (minutes from 1440) belongs to
 * the next civil date.
 */
export function unloggedStops(
  plans: readonly PlanLike[],
  services: readonly LoggedLike[],
  now: { date: string; minute: number },
  opts: UnloggedOptions = {},
): UnloggedStop[] {
  const logged: Record<string, true> = {};
  for (let i = 0; i < services.length; i++) {
    const id = services[i].plan_stop_id;
    if (id !== null && id !== undefined) logged[id] = true;
  }
  const nowAt = dayNumber(now.date) * 1440 + now.minute;
  const found: { at: number; stop: UnloggedStop }[] = [];
  for (let p = 0; p < plans.length; p++) {
    const plan = plans[p];
    if (plan.status === 'cancelled') continue;
    if (opts.since !== undefined && plan.date < opts.since) continue;
    const day = dayNumber(plan.date);
    for (let i = 0; i < plan.stops.length; i++) {
      const s = plan.stops[i];
      const closesAt = day * 1440 + s.close_minute;
      if (closesAt > nowAt) continue;
      if (logged[s.id] === true) continue;
      let name = s.label;
      if (s.kind === 'spot' && s.spot_id !== null && opts.spotNames !== undefined) {
        const known = opts.spotNames[s.spot_id];
        if (typeof known === 'string' && known !== '') name = known;
      }
      if (name === '') name = stopFallbackName(i);
      found.push({
        at: closesAt,
        stop: {
          planId: plan.id,
          date: plan.date,
          stopId: s.id,
          kind: s.kind,
          spotId: s.spot_id,
          name,
          openMinute: s.open_minute,
          closeMinute: s.close_minute,
          stopIndex: i,
        },
      });
    }
  }
  found.sort((a, b) => {
    if (a.at !== b.at) return b.at - a.at;
    if (a.stop.planId !== b.stop.planId) return a.stop.planId < b.stop.planId ? -1 : 1;
    return a.stop.stopIndex - b.stop.stopIndex;
  });
  const limit = opts.limit === undefined ? found.length : opts.limit;
  const out: UnloggedStop[] = [];
  for (let i = 0; i < found.length && i < limit; i++) out.push(found[i].stop);
  return out;
}

export type Verdict = keyof typeof VERDICT_WORDS;

export interface VerdictInput {
  actual: number;
  low: number;
  high: number;
  sold_out: boolean;
}

/**
 * How a logged count sits against the range it was estimated with. The edges count as inside
 * (low <= actual <= high, the test the accuracy report uses). A sold-out service is a minimum, so
 * its place against the range says nothing.
 */
export function verdictOf(v: VerdictInput): Verdict {
  if (v.sold_out) return 'sold_out';
  if (v.actual > v.high) return 'above';
  if (v.actual < v.low) return 'below';
  return 'inside';
}

/** "inside the range", "above the range", "below the range", "sold out, counted as a minimum". */
export function verdictWords(v: VerdictInput): string {
  return VERDICT_WORDS[verdictOf(v)];
}

/**
 * The "Result" cell: the signed difference from the estimate as the owner sees it (the estimate
 * rounded to a whole order), then the verdict. "+5, inside the range".
 */
export function resultText(v: VerdictInput & { predicted: number }): string {
  const diff = roundHalfAway(v.actual, 0) - roundHalfAway(v.predicted, 0);
  const signed = diff > 0 ? '+' + String(diff) : String(diff);
  return signed + ', ' + verdictWords(v);
}

/**
 * The calibration an estimate for `date` may use: only the services logged before that date count.
 * This is what the server stores with a service, so the Log shows the same figure before saving.
 */
export function calibrationBefore(A: Assumptions, entries: readonly ServiceLogEntry[], date: string): CalibrationState {
  const earlier: ServiceLogEntry[] = [];
  for (let i = 0; i < entries.length; i++) {
    if (entries[i].date < date) earlier.push(entries[i]);
  }
  return calibrate(A, earlier, date);
}
