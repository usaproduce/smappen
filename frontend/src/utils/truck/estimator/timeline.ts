// Truck Planner estimator - timeline (02_MODEL 4.11).

import { hasOwn, max2, min2 } from './core';
import { fallbackLeg, legMinutes } from './driving';
import type {
  Assumptions,
  DayContext,
  LatLng,
  Leg,
  LegInput,
  LegMap,
  Timeline,
  TimelineEvent,
  TimelineEventKind,
  TimelineStop,
  TimelineStopInput,
  TruckProfile,
} from './types';

/**
 * Every leg key dayPlan can look up, no duplicates: the plan as ordered, then the legs that appear when
 * one stop is left out. Keys are "<from_id>><to_id>" with the literal id "base" for the truck's base.
 */
export function requiredLegKeys(stops: readonly { id: string }[]): string[] {
  if (stops.length === 0) return [];
  const ids: string[] = ['base'];
  for (let i = 0; i < stops.length; i++) ids.push(stops[i].id);
  ids.push('base');
  const keys: string[] = [];
  for (let i = 0; i < ids.length - 1; i++) keys.push(ids[i] + '>' + ids[i + 1]);
  if (stops.length >= 2) {
    for (let i = 1; i <= stops.length; i++) keys.push(ids[i - 1] + '>' + ids[i + 1]);
  }
  return keys;
}

/** The timeline of a plan with no stops: all counts 0, the four day marks null. */
export function emptyTimeline(): Timeline {
  return {
    events: [],
    stops: [],
    legs: [],
    start_prep: null,
    leave_base: null,
    back_at_base: null,
    done: null,
    day_minutes: 0,
    paid_minutes: 0,
    unpaid_gap_minutes: 0,
    drive_minutes: 0,
    service_minutes: 0,
    generator_minutes: 0,
    miles: 0.0,
    tolls: 0.0,
  };
}

/**
 * The day's clock, in whole minutes from local midnight of the service date.
 *
 * The first stop is worked backward from its opening time (the truck leaves base just in time); every
 * later stop forward. The owner's opening and closing times are never moved: a truck that cannot be
 * set up in time is late and serves from effective_open. Stops are never reordered. A leg missing from
 * `legs` is a fallbackLeg between the two points (profile.base for "base").
 */
export function buildTimeline(
  A: Assumptions,
  profile: TruckProfile,
  ctx: DayContext,
  stops: readonly TimelineStopInput[],
  legs: LegMap,
): Timeline {
  if (stops.length === 0) return emptyTimeline();

  const pointOf = (stopId: string): LatLng => {
    if (stopId === 'base') return profile.base;
    for (let k = 0; k < stops.length; k++) {
      if (stops[k].id === stopId) return stops[k].point;
    }
    throw new Error('buildTimeline: no stop with id ' + stopId);
  };
  const legInput = (fromId: string, toId: string): LegInput => {
    const key = fromId + '>' + toId;
    if (hasOwn(legs, key)) return legs[key];
    const p1 = pointOf(fromId);
    const p2 = pointOf(toId);
    return fallbackLeg(A, p1.lat, p1.lng, p2.lat, p2.lng);
  };
  const setup = (s: TimelineStopInput): number => (s.setup_minutes != null ? s.setup_minutes : profile.setup_minutes);
  const teardown = (s: TimelineStopInput): number =>
    s.teardown_minutes != null ? s.teardown_minutes : profile.teardown_minutes;

  const events: TimelineEvent[] = [];
  const emit = (kind: TimelineEventKind, at: number, stopIndex: number | null): void => {
    let minute = at;
    if (events.length > 0 && minute < events[events.length - 1].minute) {
      minute = events[events.length - 1].minute; // time never runs backwards
    }
    events.push({ kind, minute, stop_index: stopIndex });
  };

  // 1. First stop: work backward from its opening time.
  const s0 = stops[0];
  let arrive = s0.open_minute - setup(s0);
  const L0 = legInput('base', s0.id);
  const lookup = arrive - Math.floor((L0.duration_s / 60.0) * profile.truck_time_factor); // departure guess
  const leg0 = legMinutes(A, profile, L0, ctx, lookup);
  leg0.from_id = 'base';
  leg0.to_id = s0.id;
  const leaveBase = arrive - leg0.minutes;
  leg0.depart_minute = leaveBase;
  const startPrep = leaveBase - profile.prep_minutes;
  emit('start_prep', startPrep, null);
  emit('leave_base', leaveBase, null);
  const outLegs: Leg[] = [leg0];

  // 2. Each stop in order, forward.
  const outStops: TimelineStop[] = [];
  let unpaid = 0;
  let serviceMinutes = 0;
  let generatorMinutes = 0;
  let prevLeave = 0;
  for (let i = 0; i < stops.length; i++) {
    const s = stops[i];
    if (i > 0) {
      const leg = legMinutes(A, profile, legInput(stops[i - 1].id, s.id), ctx, prevLeave);
      leg.from_id = stops[i - 1].id;
      leg.to_id = s.id;
      leg.depart_minute = prevLeave;
      outLegs.push(leg);
      arrive = prevLeave + leg.minutes;
    }
    let gap = s.open_minute - setup(s) - arrive;
    let late = 0;
    if (gap < 0) {
      late = -gap;
      gap = 0;
    }
    const setupStart = arrive + gap;
    const effectiveOpen = min2(setupStart + setup(s), s.close_minute);
    const gapUnpaid = Boolean(s.gap_before_unpaid) && i > 0;
    if (gapUnpaid) unpaid += gap;
    const leave = max2(s.close_minute + teardown(s), setupStart);
    emit('arrive', arrive, i);
    emit('setup_start', setupStart, i);
    emit('open', effectiveOpen, i);
    emit('close', s.close_minute, i);
    emit('leave', leave, i);
    serviceMinutes += s.close_minute - effectiveOpen;
    generatorMinutes += leave - setupStart; // setup start to the end of teardown
    outStops.push({
      stop_index: i,
      arrive,
      setup_start: setupStart,
      open: s.open_minute,
      effective_open: effectiveOpen,
      close: s.close_minute,
      leave,
      gap_before_minutes: gap,
      gap_unpaid: gapUnpaid,
      late_minutes: late,
    });
    prevLeave = leave;
  }

  // 3. Back to base.
  const last = stops[stops.length - 1];
  const legN = legMinutes(A, profile, legInput(last.id, 'base'), ctx, prevLeave);
  legN.from_id = last.id;
  legN.to_id = 'base';
  legN.depart_minute = prevLeave;
  outLegs.push(legN);
  const backAtBase = prevLeave + legN.minutes;
  const done = backAtBase + profile.closeout_minutes;
  emit('back_at_base', backAtBase, null);
  emit('done', done, null);

  let driveMinutes = 0;
  let miles = 0.0;
  let tolls = 0.0;
  for (let k = 0; k < outLegs.length; k++) {
    driveMinutes += outLegs[k].minutes;
    miles += outLegs[k].miles;
    tolls += outLegs[k].toll;
  }
  const dayMinutes = done - startPrep;
  return {
    events,
    stops: outStops,
    legs: outLegs,
    start_prep: startPrep,
    leave_base: leaveBase,
    back_at_base: backAtBase,
    done,
    day_minutes: dayMinutes,
    paid_minutes: dayMinutes - unpaid,
    unpaid_gap_minutes: unpaid,
    drive_minutes: driveMinutes,
    service_minutes: serviceMinutes,
    generator_minutes: generatorMinutes,
    miles,
    tolls,
  };
}
