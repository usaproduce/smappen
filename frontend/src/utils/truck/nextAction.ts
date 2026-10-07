// Truck Planner - Today, Week and the map's date switch as pure functions
// (docs/truck-planner/05_FRONTEND.md 4.1, 4.6 and the date mode of 4.2).
//
// No I/O, no clock, no React: "now" is always an argument, in the truck's time zone. Six things live
// here:
//
//   1. The next thing to do: the first event of a day's timeline that is later than now, as one
//      sentence. A day is not cut off at midnight: a late service that began yesterday is still the
//      thing to do at 12:30 AM, and a day that starts before midnight tonight already counts.
//   2. Which days count as planned, and the plan of a date.
//   3. Today's plan in words: when the day is done, how long it is, what the forecast does to a
//      stop's orders.
//   4. "Log what happened": the latest logged service and when it was.
//   5. The fuel price in use and where it comes from.
//   6. The week: the sum of the day results, what was logged on a day, and the date the map shows
//      for a day of the week.
//
// Nothing here does model maths. Times, orders and money come from the estimator through
// utils/truck/model.ts; this module picks, counts and words them. The one sum, the week's total, is
// the model's own `estSum`.

import type { FuelInfo, Plan } from '../../api/truck';
import { dayNumber, dayOfWeek, estSum, floorDiv, holidayOn, qkey } from './model';
import type { Assumptions, DayResult, DayStop, Estimate, Timeline, TimelineEventKind } from './model';
import { fmtClock, fmtCount, fmtDay, fmtDuration, fmtFuel, fmtPercent, fmtWeekday, fmtWindow } from './format';
import { arriveLine, weatherHours } from './planDraft';
import { fuelProductLabel, fuelSourceLine } from './profileForm';
import { howParts, nextDateWithDow } from './time';
import { stopFallbackName } from './wording';

const MINUTES_PER_DAY = 1440;

// -------------------------------------------------------------------------------------------------
// 1. The next thing to do
// -------------------------------------------------------------------------------------------------

/** What the "Next" sentence is about: an event of the timeline, or `finished` when none is left. */
export type NextKind = TimelineEventKind | 'finished';

export interface NextAction {
  kind: NextKind;
  /** The event's minute, from local midnight of the plan's own date; null once nothing is left. */
  minute: number | null;
  /** The stop the event belongs to; null for the events of the day itself. */
  stopIndex: number | null;
  /** "Leave base by 10:19 AM". A heading, so without a full stop; "Done for today." has its own. */
  text: string;
}

/** The sentence of each event; `{t}` is its clock time and `{stop}` the stop's name. */
export const NEXT_TEXT: Readonly<Record<TimelineEventKind, string>> = {
  start_prep: 'Start prep at {t}',
  leave_base: 'Leave base by {t}',
  arrive: 'Arrive at {stop} by {t}',
  setup_start: 'Start setting up at {t}',
  open: 'Open at {t}',
  close: 'Serving until {t}',
  leave: 'Pack up and leave by {t}',
  back_at_base: 'Back at base about {t}',
  done: 'Finish close-out by {t}',
};

export const NEXT_DONE = 'Done for today.';
export const NEXT_CAPTION = 'Next';
export const NEXT_LOG_BUTTON = 'Log your orders';
/** Under the sentence: where it comes from, and what the app does not know. */
export const NEXT_NOTE = 'From your plan and the clock. Truck Planner does not know where the truck is.';

/** The same note for a sentence that comes from the plan of another date. */
export function nextNoteFor(date: string): string {
  return 'From your plan for ' + fmtDay(date, 'medium') + ' and the clock. Truck Planner does not know where the truck is.';
}

function nameOf(stopNames: readonly string[], index: number | null): string {
  if (index === null) return '';
  const name = stopNames[index];
  return typeof name === 'string' && name !== '' ? name : stopFallbackName(index);
}

/**
 * The next thing to do on a day: the first event of its timeline that is later than `minute`.
 *
 * `minute` counts from local midnight of the plan's own date, like the timeline does, so it is 1440
 * or more once that date is over (a service that runs past midnight) and negative on the evening
 * before. The clock time in the sentence is read from the civil day `minute` lies in: at 11 PM a
 * closing time of 1 AM reads "1:00 AM (next day)", and at 12:10 AM it reads "1:00 AM".
 *
 * Events at the same minute are one moment: the first of them is the one named, so a stop the truck
 * reaches just in time says "Arrive at ..." and never "Start setting up at" the same time. Null for
 * a day without a timeline (nothing planned, or a day the model could not evaluate).
 */
export function nextAction(timeline: Timeline, minute: number, stopNames: readonly string[] = []): NextAction | null {
  const events = timeline.events;
  if (events.length === 0) return null;
  for (let i = 0; i < events.length; i++) {
    const e = events[i];
    if (!(e.minute > minute)) continue;
    const shown = e.minute - MINUTES_PER_DAY * floorDiv(minute, MINUTES_PER_DAY);
    const text = NEXT_TEXT[e.kind].split('{t}').join(fmtClock(shown)).split('{stop}').join(nameOf(stopNames, e.stop_index));
    return { kind: e.kind, minute: e.minute, stopIndex: e.stop_index, text };
  }
  return { kind: 'finished', minute: null, stopIndex: null, text: NEXT_DONE };
}

/** A planned day as the "Next" sentence reads it. */
export interface ServiceDay {
  /** Where the day's midnight lies from today's, in days: -1 yesterday, 0 today, 1 tomorrow. */
  offset: number;
  /** The civil date of the plan. */
  date: string;
  timeline: Timeline;
  stopNames: readonly string[];
}

export interface NextUp {
  /** The day the sentence comes from. */
  day: ServiceDay;
  action: NextAction;
}

/**
 * The next thing to do across the days around now. `minute` is the minute of today, 0 to 1439.
 *
 * Yesterday's day counts while it still has an event to come (a service that runs past midnight).
 * Tomorrow's counts once it has begun, or when its next event falls before midnight tonight (prep
 * for a stop that opens in the small hours). Of the days that have something to come the earliest
 * event wins. With nothing to come anywhere the answer is "Done for today." when today had a
 * timeline, and null when it had none.
 */
export function nextUp(days: readonly ServiceDay[], minute: number): NextUp | null {
  let best: NextUp | null = null;
  let bestAt = 0;
  let today: ServiceDay | null = null;
  for (let i = 0; i < days.length; i++) {
    const day = days[i];
    if (day.offset === 0) today = day;
    const shift = MINUTES_PER_DAY * day.offset;
    const action = nextAction(day.timeline, minute - shift, day.stopNames);
    if (action === null || action.minute === null) continue;
    const at = action.minute + shift;
    if (day.offset > 0) {
      const begun = day.timeline.start_prep !== null && day.timeline.start_prep + shift <= minute;
      if (!begun && at >= MINUTES_PER_DAY) continue;
    }
    if (best === null || at < bestAt || (at === bestAt && day.offset < best.day.offset)) {
      best = { day, action };
      bestAt = at;
    }
  }
  if (best !== null) return best;
  if (today !== null && today.timeline.events.length > 0) {
    return { day: today, action: { kind: 'finished', minute: null, stopIndex: null, text: NEXT_DONE } };
  }
  return null;
}

/** A day can still be busy this long after its last closing time: packing up, the drive home, close-out. */
const RUN_ON_MINUTES = 480;
/** A day can begin this long before its first opening time: prep, the drive, setting up. */
const LEAD_IN_MINUTES = 240;

/**
 * True when yesterday's stops may still have something to do at `minute` of today, so that its
 * timeline is worth working out. A generous test on the entered times; the timeline decides.
 */
export function mayStillRun(stops: readonly { close_minute: number }[], minute: number): boolean {
  let last: number | null = null;
  for (const stop of stops) {
    if (last === null || stop.close_minute > last) last = stop.close_minute;
  }
  return last !== null && last + RUN_ON_MINUTES > MINUTES_PER_DAY + minute;
}

/** True when tomorrow's stops may have something to do before midnight tonight. */
export function mayStartTonight(stops: readonly { open_minute: number }[]): boolean {
  let first: number | null = null;
  for (const stop of stops) {
    if (first === null || stop.open_minute < first) first = stop.open_minute;
  }
  return first !== null && first - LEAD_IN_MINUTES < 0;
}

// -------------------------------------------------------------------------------------------------
// 2. Planned days
// -------------------------------------------------------------------------------------------------

type PlanLike = Pick<Plan, 'status' | 'stops'>;

/** A day counts as planned when its plan has at least one stop and was not cancelled. */
export function isPlanned<T extends PlanLike>(plan: T | null | undefined): plan is T {
  return plan !== null && plan !== undefined && plan.status !== 'cancelled' && plan.stops.length > 0;
}

/** The plan of a date in a list (there is at most one per date), or null. */
export function planOn<T extends { date: string }>(plans: readonly T[] | undefined, date: string): T | null {
  if (plans === undefined) return null;
  for (const plan of plans) {
    if (plan.date === date) return plan;
  }
  return null;
}

/** The dates of `dates` that are planned, in the order given. */
export function plannedDates(plans: readonly (PlanLike & { date: string })[] | undefined, dates: readonly string[]): string[] {
  const out: string[] = [];
  for (const date of dates) {
    if (isPlanned(planOn(plans, date))) out.push(date);
  }
  return out;
}

/** "3 of 7 days planned". */
export function plannedCountText(planned: number): string {
  return fmtCount(planned) + ' of 7 days planned';
}

/** True when the model evaluated every stop of the day (a day with a problem in its times has none). */
export function isEvaluated(result: DayResult | null, stopCount: number): result is DayResult {
  return result !== null && stopCount > 0 && result.stops.length === stopCount;
}

// -------------------------------------------------------------------------------------------------
// 3. Today's plan in words
// -------------------------------------------------------------------------------------------------

/** The clock hours a day's weather chip is about: the plan's hours, or 11 AM to 8 PM with nothing planned. */
export function chipHours(stops: readonly { open_minute: number; close_minute: number }[]): { fromHour: number; toHour: number } {
  return stops.length === 0 ? { fromHour: 11, toHour: 20 } : weatherHours(stops);
}

/** "Herndon office park, 11 AM to 2 PM": a stop in one line. */
export function stopLine(name: string, stop: { open_minute: number; close_minute: number }): string {
  return name + ', ' + fmtWindow(stop.open_minute, stop.close_minute);
}

/**
 * When the truck reaches a stop: "Arrive 10:30 AM", or the planner's own "Arrive 2:30 PM, set up
 * from 4:30 PM" when it waits before setting up.
 */
export function arrivalLine(timed: { arrive: number; setup_start: number }): string {
  return timed.setup_start > timed.arrive ? arriveLine(timed) : 'Arrive ' + fmtClock(timed.arrive);
}

/** True once the last thing of the day is behind: the route is then no longer the thing to press. */
export function dayIsDone(timeline: Timeline, minute: number): boolean {
  return timeline.done !== null && minute >= timeline.done;
}

/** "11 h 17 min, prep to done". */
export function dayLengthLine(timeline: Timeline): string {
  return fmtDuration(timeline.day_minutes) + ', prep to done';
}

/** "3 things to check", "1 thing to check"; null with nothing to check. */
export function thingsToCheck(count: number): string | null {
  if (!(count > 0)) return null;
  return fmtCount(count) + (count === 1 ? ' thing to check' : ' things to check');
}

export const PLAN_TEXT = {
  title: "Today's plan",
  editPlan: 'Edit plan',
  daySheet: 'Day sheet',
  draftTag: 'Draft',
  /** A plan the planner holds changes to that are not saved. */
  unsaved: 'The planner holds changes to this day that are not saved yet. The figures here are for the saved day.',
  /** No saved plan, but the planner holds an unsaved one. */
  unsavedOnly: 'You started a plan for today in the planner. It is not saved yet.',
  noFigures: 'This day cannot be worked out yet. Open the planner to see why.',
  failed: "Could not load today's plan.",
} as const;

export type WeatherEffectKind = 'none' | 'lower' | 'higher' | 'mixed' | 'missing' | 'contract';

export interface WeatherEffect {
  kind: WeatherEffectKind;
  /** One or two whole sentences. */
  text: string;
}

export const WEATHER_TEXT = {
  title: 'Weather',
  /** The source line of the card (4.1). */
  source: 'Forecast for the area around your base. National Weather Service (weather.gov).',
  none: 'Nothing in this forecast lowers the estimate.',
  missing: 'No forecast for these hours, so no weather adjustment.',
  contract: 'A catering job is contracted. The weather does not change it.',
  captive: "The host's people are inside the venue, so the weather counts less for them.",
  noStops: 'Around your base',
} as const;

function percentOff(factor: number): string {
  return fmtPercent(factor < 1 ? 1 - factor : factor - 1);
}

/** The sentence for the lowest and the highest weather factor of a stop's forecast hours. */
function effectOf(lo: number, hi: number, what: string): WeatherEffect {
  const one = qkey(1);
  const loKey = qkey(lo);
  const hiKey = qkey(hi);
  if (loKey === one && hiKey === one) return { kind: 'none', text: WEATHER_TEXT.none };
  if (hiKey <= one) {
    // Every hour is at or under the plain figure: the largest cut is 1 - lo, the smallest 1 - hi.
    const most = percentOff(lo);
    const least = percentOff(hi);
    if (hiKey === one) return { kind: 'lower', text: 'This forecast takes up to ' + most + ' off ' + what + ', depending on the hour.' };
    if (most === least) return { kind: 'lower', text: 'This forecast takes ' + most + ' off ' + what + '.' };
    return { kind: 'lower', text: 'This forecast takes ' + least + ' to ' + most + ' off ' + what + ', depending on the hour.' };
  }
  if (loKey >= one) {
    const most = percentOff(hi);
    const least = percentOff(lo);
    if (loKey === one) return { kind: 'higher', text: 'This forecast adds up to ' + most + ' to ' + what + ', depending on the hour.' };
    if (most === least) return { kind: 'higher', text: 'This forecast adds ' + most + ' to ' + what + '.' };
    return { kind: 'higher', text: 'This forecast adds ' + least + ' to ' + most + ' to ' + what + ', depending on the hour.' };
  }
  return {
    kind: 'mixed',
    text: 'This forecast takes up to ' + percentOff(lo) + ' off ' + what + ' in some hours and adds up to ' + percentOff(hi) + ' in others.',
  };
}

/**
 * What the forecast does to a stop's orders, in words. The figures are the model's own weather
 * factors for the stop's hours (02_MODEL 4.6): the factor on walk-up demand for a spot, the hourly
 * factor of an event. Nothing is worked out here beyond reading the smallest and the largest.
 */
export function weatherEffect(stop: DayStop): WeatherEffect {
  if (stop.kind === 'catering') return { kind: 'contract', text: WEATHER_TEXT.contract };

  if (stop.window !== null) {
    const hours = stop.window.hours;
    let lo = Infinity;
    let hi = -Infinity;
    let missing = 0;
    let captive = false;
    for (const h of hours) {
      const r = h.result;
      if (r.factors.weather_state !== 'forecast') {
        missing += 1;
        continue;
      }
      const f = r.factors.weather_open;
      if (f < lo) lo = f;
      if (f > hi) hi = f;
      if (r.host !== null && r.host.mode === 'captive' && qkey(r.factors.weather_captive) !== qkey(f)) captive = true;
    }
    if (hours.length === 0 || missing === hours.length) return { kind: 'missing', text: WEATHER_TEXT.missing };
    const effect = effectOf(lo, hi, 'walk-up orders');
    let text = effect.text;
    if (captive) text += ' ' + WEATHER_TEXT.captive;
    if (missing > 0) {
      text += ' No forecast for ' + fmtCount(missing) + ' of the ' + fmtCount(hours.length) + ' hours.';
    }
    return { kind: effect.kind, text };
  }

  if (stop.event !== null && stop.event.hours.length > 0) {
    let lo = Infinity;
    let hi = -Infinity;
    for (const h of stop.event.hours) {
      if (h.weather < lo) lo = h.weather;
      if (h.weather > hi) hi = h.weather;
    }
    return effectOf(lo, hi, "this event's orders");
  }
  return { kind: 'missing', text: WEATHER_TEXT.missing };
}

// -------------------------------------------------------------------------------------------------
// 4. "Log what happened"
// -------------------------------------------------------------------------------------------------

export const LOG_TEXT = {
  title: 'Log what happened',
  logIt: 'Log it',
  estimateWas: 'The estimate was',
  /** Nothing to log and nothing logged yet. */
  empty: 'After a service, log your orders here. About ten logged services make the dollar figures worth trusting.',
  /** Services were logged, but none in the days this card looks at. */
  quiet: 'Nothing is waiting, and nothing was logged in the last seven days.',
  nothingWaiting: 'Nothing is waiting to be logged.',
  openLog: 'Open the log',
  plansFailed: 'Could not load your planned stops.',
  servicesFailed: 'Could not load your logged services.',
  spotsFailed: 'Could not load your spots.',
} as const;

type Logged = { id: string; date: string; open_minute: number };

/** The service that was served last: by date, then by opening time. Null without one. */
export function latestService<T extends Logged>(services: readonly T[] | undefined): T | null {
  if (services === undefined) return null;
  let best: T | null = null;
  for (const s of services) {
    if (
      best === null ||
      s.date > best.date ||
      (s.date === best.date && (s.open_minute > best.open_minute || (s.open_minute === best.open_minute && s.id < best.id)))
    ) {
      best = s;
    }
  }
  return best;
}

/**
 * When a date was, seen from today, inside a sentence: "today", "yesterday", the weekday for the
 * rest of the last six days, and the date itself beyond that (a weekday alone would then be read as
 * this week's).
 */
export function whenWord(date: string, today: string): string {
  const ago = dayNumber(today) - dayNumber(date);
  if (ago === 0) return 'today';
  if (ago === 1) return 'yesterday';
  if (ago > 1 && ago < 7) return fmtWeekday(dayOfWeek(date), 'long');
  return fmtDay(date, 'medium');
}

/** "Herndon office park, Friday: 52 orders." */
export function latestLine(name: string, service: { date: string; actual: number }, today: string): string {
  return name + ', ' + whenWord(service.date, today) + ': ' + fmtCount(service.actual) + (fmtCount(service.actual) === '1' ? ' order.' : ' orders.');
}

// -------------------------------------------------------------------------------------------------
// 5. The fuel price in use
// -------------------------------------------------------------------------------------------------

export const FUEL_TEXT = {
  title: 'Fuel',
  change: 'Change',
} as const;

export interface FuelLineView {
  /** "Regular gasoline" or "Diesel". */
  label: string;
  /** "$4.195/gal". */
  value: string;
  /** Where the price comes from. */
  sub: string;
}

/**
 * The fuel price the estimates use, with its source (4.1). The product and the source are worded
 * by the functions the fuel card of Settings uses, so the two places always read alike.
 */
export function fuelLine(fuel: FuelInfo): FuelLineView {
  return { label: fuelProductLabel(fuel.product), value: fmtFuel(fuel.price_per_gal), sub: fuelSourceLine(fuel) };
}

// -------------------------------------------------------------------------------------------------
// 6. The week, and the date the map shows
// -------------------------------------------------------------------------------------------------

export const WEEK_TEXT = {
  totalLabel: 'PLANNED TAKE-HOME THIS WEEK',
  helper: "Each day's low and high are added up, so the week's range is wide on purpose.",
  nothing: 'Nothing is planned this week yet.',
  previous: 'Previous week',
  next: 'Next week',
  thisWeek: 'This week',
  today: 'Today',
  draft: 'Draft',
  cancelled: 'Cancelled',
  nothingPlanned: 'Nothing planned',
  planThisDay: 'Plan this day',
  open: 'Open',
  noFigures: 'No figures for this day yet. Open it to see why.',
  whyTitle: 'Why this number',
  whyLead: 'The week is the sum of its planned days. Open a day for its own breakdown.',
  plansFailed: 'Could not load this week.',
} as const;

/** "Week of Oct 5". */
export function weekTitle(weekStart: string): string {
  return 'Week of ' + fmtDay(weekStart, 'short');
}

export interface WeekSum {
  /** `estSum` of the take-home of the planned days that have a figure, in date order; null when none has. */
  total: Estimate | null;
  /** How many days are planned. */
  planned: number;
  /** How many of them are in the total. */
  counted: number;
}

/**
 * The week's take-home: the model's `estSum` over the planned days in date order, so lows add to
 * lows and highs to highs and the label is the weakest of the days'. A planned day without a figure
 * (still loading, or one the model could not evaluate) is left out and counted as missing.
 */
export function weekSum(days: readonly { planned: boolean; result: DayResult | null }[]): WeekSum {
  const parts: Estimate[] = [];
  let planned = 0;
  for (const day of days) {
    if (!day.planned) continue;
    planned += 1;
    if (day.result !== null) parts.push(day.result.totals.take_home);
  }
  return { total: parts.length === 0 ? null : estSum(parts), planned, counted: parts.length };
}

/** "1 planned day has no figures yet and is not in the total."; null when every planned day is counted. */
export function weekMissingLine(sum: WeekSum): string | null {
  const missing = sum.planned - sum.counted;
  if (!(missing > 0)) return null;
  return missing === 1
    ? '1 planned day has no figures yet and is not in the total.'
    : fmtCount(missing) + ' planned days have no figures yet and are not in the total.';
}

/** The orders logged on a date: the sum over its services; null when none was logged. */
export function loggedOrders(services: readonly { date: string; actual: number }[] | undefined, date: string): number | null {
  if (services === undefined) return null;
  let total = 0;
  let any = false;
  for (const s of services) {
    if (s.date !== date) continue;
    any = true;
    total += s.actual;
  }
  return any ? total : null;
}

/** "Logged: 131 orders". */
export function loggedLine(orders: number): string {
  return 'Logged: ' + fmtCount(orders) + (fmtCount(orders) === '1' ? ' order' : ' orders');
}

export const DATE_MODE_TEXT = {
  label: 'What the map shows',
  typical: 'Typical week',
  pick: 'Pick a date',
  noWeather: 'No weather.',
} as const;

/**
 * The date the map shows for an hour of the week while a date is picked: the one date of today and
 * the six days after it that falls on that day of the week. The day buttons of the hour bar, the
 * arrow keys and playback all move the hour, and the picked date follows them through this.
 */
export function dateForHow(today: string, how: number): string {
  return nextDateWithDow(today, howParts(how).dow);
}

/**
 * What a picked date changes on the map: a federal holiday follows its holiday pattern, any other
 * date is the same as its day in the typical week. Weather is never part of the map.
 */
export function dateModeLine(A: Assumptions, date: string): string {
  const holiday = holidayOn(date, A.region.flags);
  if (holiday !== null) return holiday.name + ': the map follows the holiday pattern. ' + DATE_MODE_TEXT.noWeather;
  return 'An ordinary ' + fmtWeekday(dayOfWeek(date), 'long') + ': the same as the typical week. ' + DATE_MODE_TEXT.noWeather;
}
