// Truck Planner - a day as the owner edits it (docs/truck-planner/05_FRONTEND.md 4.5).
//
// Pure functions, no I/O, no clock, no React. Five things live here:
//
//   1. What a stop is called and where it is.
//   2. Changes to the draft of a day: add a stop where its window falls in the day, move, remove,
//      edit. The stops already in the day never change places on their own: only the owner
//      reorders them.
//   3. The window a new stop starts with.
//   4. The body of routes 23 and 26 (04_BACKEND 4.11), and whether the draft differs from the day
//      the server holds.
//   5. What the planner prints beside its estimates: the drive rows with their source and toll,
//      the cost lines, the sentences of "what this stop adds".
//
// Nothing here does model maths. Orders, money and times come from the estimator through
// utils/truck/model.ts; this module arranges, compares and words them.

import type { DriveLeg, Plan, PlanBody, PlanStopBody, Spot } from '../../api/truck';
import {
  draftFromPlan,
  isTemporaryStopId,
  newDraftStopId,
  type DraftStop,
  type PlanDraft,
} from '../../stores/truckPlanDraftStore';
import { METERS_PER_MILE, bestWindows, hourlyOrders, seed, vectorsMatch } from './model';
import type {
  Assumptions,
  CalibrationState,
  DayContext,
  DayResult,
  DayTotals,
  Estimate,
  LatLng,
  StopAdds,
  StopKind,
  Timeline,
  TreatAs,
  TruckProfile,
  UnpaidGapAlternative,
} from './model';
import { sortedJson, spotVectors } from './assemble';
import {
  fmtCeil,
  fmtClock,
  fmtCount,
  fmtDuration,
  fmtFuel,
  fmtHours,
  fmtMiles,
  fmtMoney,
  fmtMoneyCents,
  fmtNumber,
  fmtPercent,
} from './format';
import {
  FUEL_SOURCE_PHRASE,
  MONEY_LINE_LABELS,
  TREAT_AS_AUTOMATIC,
  TREAT_AS_LABELS,
  driveFallbackReason,
  driveSourceLabel,
  stopFallbackName,
} from './wording';

// -------------------------------------------------------------------------------------------------
// 1. Names and places
// -------------------------------------------------------------------------------------------------

/** The tag on a stop card. */
export const KIND_LABELS: Readonly<Record<StopKind, string>> = {
  spot: 'Spot',
  event: 'Event',
  catering: 'Catering',
};

/** What a stop reads of the saved spots: by id, deleted spots included. */
export type SpotIndex = ReadonlyMap<string, Spot>;

type Named = Pick<DraftStop, 'kind' | 'spot_id' | 'label'>;
type Placed = Pick<DraftStop, 'kind' | 'spot_id' | 'point'>;

/** The spot of a spot stop, or null (another kind of stop, or a spot that is not in the list). */
export function spotOfStop(stop: Pick<DraftStop, 'kind' | 'spot_id'>, spotsById: SpotIndex): Spot | null {
  if (stop.kind !== 'spot' || stop.spot_id === null) return null;
  return spotsById.get(stop.spot_id) ?? null;
}

/**
 * The name of a stop: the spot's name, or the name the owner gave an event or a catering job.
 * Empty when there is none.
 */
export function stopName(stop: Named, spotsById: SpotIndex): string {
  if (stop.kind === 'spot') {
    const spot = spotOfStop(stop, spotsById);
    return spot === null ? '' : spot.name.trim();
  }
  return stop.label.trim();
}

/** The names of a day's stops in order. A stop without a name reads "Stop 2". */
export function stopNames(stops: readonly Named[], spotsById: SpotIndex): string[] {
  return stops.map((stop, index) => {
    const name = stopName(stop, spotsById);
    return name !== '' ? name : stopFallbackName(index);
  });
}

/** Where a stop is: the spot's point, or the point of an event or a catering job. Null without one. */
export function stopPoint(stop: Placed, spotsById: SpotIndex): LatLng | null {
  if (stop.kind === 'spot') {
    const spot = spotOfStop(stop, spotsById);
    return spot === null ? null : { lat: spot.point.lat, lng: spot.point.lng };
  }
  return stop.point === null ? null : { lat: stop.point.lat, lng: stop.point.lng };
}

/** The address of a stop: the spot's, or the one entered for an event or a catering job. */
export function stopAddress(stop: Pick<DraftStop, 'kind' | 'spot_id' | 'address'>, spotsById: SpotIndex): string {
  if (stop.kind === 'spot') {
    const spot = spotOfStop(stop, spotsById);
    return spot === null ? '' : spot.address;
  }
  return stop.address;
}

/** The points of the day's route in the owner's order (stops without a place are left out). */
export function routePoints(stops: readonly Placed[], spotsById: SpotIndex): LatLng[] {
  const out: LatLng[] = [];
  for (const stop of stops) {
    const point = stopPoint(stop, spotsById);
    if (point !== null) out.push(point);
  }
  return out;
}

/** A stop as the calendar file takes it. */
export interface CalendarStop {
  id: string;
  name: string;
  address: string;
  point: { lat: number; lng: number };
}

/** The day's stops for the calendar file. Stops without a place are left out. */
export function calendarStops(stops: readonly DraftStop[], spotsById: SpotIndex): CalendarStop[] {
  const names = stopNames(stops, spotsById);
  const out: CalendarStop[] = [];
  for (let i = 0; i < stops.length; i++) {
    const point = stopPoint(stops[i], spotsById);
    if (point !== null) out.push({ id: stops[i].id, name: names[i], address: stopAddress(stops[i], spotsById), point });
  }
  return out;
}

/**
 * Why a stop cannot be evaluated yet, or null when it can:
 * `spot_missing` its spot is not in the list; `no_estimate` the spot has no stored vectors;
 * `details_missing` an event or a catering job still lacks its place or its terms.
 */
export type StopProblem = 'spot_missing' | 'no_estimate' | 'details_missing';

export function stopProblem(stop: DraftStop, spotsById: SpotIndex): StopProblem | null {
  if (stop.kind === 'spot') {
    const spot = spotOfStop(stop, spotsById);
    if (spot === null) return 'spot_missing';
    return spotVectors(spot) === null ? 'no_estimate' : null;
  }
  if (stop.point === null) return 'details_missing';
  if (stop.kind === 'event') return stop.event === null ? 'details_missing' : null;
  return stop.catering === null ? 'details_missing' : null;
}

// -------------------------------------------------------------------------------------------------
// 2. Changes to a draft
// -------------------------------------------------------------------------------------------------

/** A service window in minutes from local midnight of the service date. */
export interface StopWindow {
  open: number;
  close: number;
}

function blankStop(kind: StopKind, window: StopWindow): DraftStop {
  return {
    id: newDraftStopId(),
    kind,
    spot_id: null,
    label: '',
    point: null,
    address: '',
    open_minute: window.open,
    close_minute: window.close,
    gap_before_unpaid: false,
    setup_minutes: null,
    teardown_minutes: null,
    fee_flat: 0,
    fee_pct: 0,
    fee_min: 0,
    event: null,
    catering: null,
  };
}

/** A new stop at a saved spot. Its id is temporary until the day is saved. */
export function newSpotStop(spotId: string, window: StopWindow): DraftStop {
  return { ...blankStop('spot', window), spot_id: spotId };
}

/**
 * A new event stop, before its form is filled in. The fee starts from the typical terms of the
 * seed file (a share of sales with a minimum); the owner changes it to the real ones.
 */
export function newEventStop(A: Assumptions, window: StopWindow): DraftStop {
  return {
    ...blankStop('event', window),
    fee_pct: seed<number>(A, 'events.suggested_fee_pct'),
    fee_min: seed<number>(A, 'events.suggested_fee_min'),
  };
}

/** A new catering stop, before its form is filled in. */
export function newCateringStop(window: StopWindow): DraftStop {
  return blankStop('catering', window);
}

/**
 * Where a new stop goes: before the first stop that opens at or after its closing time, else last.
 * The stops already in the day keep their order.
 */
export function insertIndex(stops: readonly Pick<DraftStop, 'open_minute'>[], window: StopWindow): number {
  for (let i = 0; i < stops.length; i++) {
    if (stops[i].open_minute >= window.close) return i;
  }
  return stops.length;
}

/** The day with one more stop, placed by its window unless a position is given. */
export function addStop(draft: PlanDraft, stop: DraftStop, index?: number): PlanDraft {
  const at = index === undefined ? insertIndex(draft.stops, { open: stop.open_minute, close: stop.close_minute }) : index;
  const stops = draft.stops.slice();
  stops.splice(at < 0 ? 0 : at > stops.length ? stops.length : at, 0, stop);
  return { ...draft, stops };
}

/** The position of a stop in the day, or -1. */
export function stopIndex(draft: PlanDraft, stopId: string): number {
  for (let i = 0; i < draft.stops.length; i++) {
    if (draft.stops[i].id === stopId) return i;
  }
  return -1;
}

/**
 * Move a stop to another position, counted in the list as it stands now (0 = first). The other
 * stops keep their order. The same draft comes back when nothing moves.
 */
export function moveStopTo(draft: PlanDraft, stopId: string, toIndex: number): PlanDraft {
  const from = stopIndex(draft, stopId);
  if (from < 0) return draft;
  const last = draft.stops.length - 1;
  const to = toIndex < 0 ? 0 : toIndex > last ? last : toIndex;
  if (to === from) return draft;
  const stops = draft.stops.slice();
  const [stop] = stops.splice(from, 1);
  stops.splice(to, 0, stop);
  return { ...draft, stops };
}

/** "Move earlier" (-1) and "Move later" (1). Nothing happens at the ends of the day. */
export function moveStop(draft: PlanDraft, stopId: string, delta: -1 | 1): PlanDraft {
  const from = stopIndex(draft, stopId);
  if (from < 0) return draft;
  return moveStopTo(draft, stopId, from + delta);
}

/** The day without one stop. */
export function removeStop(draft: PlanDraft, stopId: string): PlanDraft {
  if (stopIndex(draft, stopId) < 0) return draft;
  return { ...draft, stops: draft.stops.filter((stop) => stop.id !== stopId) };
}

/** Change fields of one stop. Its id and its kind stay what they are. */
export function patchStop(draft: PlanDraft, stopId: string, patch: Partial<DraftStop>): PlanDraft {
  if (stopIndex(draft, stopId) < 0) return draft;
  return {
    ...draft,
    stops: draft.stops.map((stop) => (stop.id === stopId ? { ...stop, ...patch, id: stop.id, kind: stop.kind } : stop)),
  };
}

/** "Treat this day as". */
export function setTreatAs(draft: PlanDraft, treatAs: TreatAs | null): PlanDraft {
  return draft.treat_as === treatAs ? draft : { ...draft, treat_as: treatAs };
}

/**
 * "Mark the wait as unpaid": every stop after the first that has a wait before it gets the flag.
 * The timeline is the one the draft was evaluated to.
 */
export function markWaitsUnpaid(draft: PlanDraft, timeline: Timeline): PlanDraft {
  let touched = false;
  const stops = draft.stops.map((stop, index) => {
    const timed = timeline.stops[index];
    if (index === 0 || timed === undefined || !(timed.gap_before_minutes > 0) || stop.gap_before_unpaid) return stop;
    touched = true;
    return { ...stop, gap_before_unpaid: true };
  });
  return touched ? { ...draft, stops } : draft;
}

/** The stops of a suggested day in place of the day's own. Every stop is new. */
export function replaceStops(
  draft: PlanDraft,
  stops: readonly { spot_id: string; open_minute: number; close_minute: number }[],
): PlanDraft {
  return {
    ...draft,
    stops: stops.map((stop) => newSpotStop(stop.spot_id, { open: stop.open_minute, close: stop.close_minute })),
  };
}

/** "A day holds at most 8 stops." */
export function stopLimitText(max: number): string {
  return 'A day holds at most ' + fmtCount(max) + ' stops.';
}

/** What the live region says after a move. */
export function movedMessage(name: string, position: number, count: number): string {
  return 'Moved ' + name + ' to position ' + fmtCount(position) + ' of ' + fmtCount(count) + '.';
}

/** What the live region says after a stop was added. */
export function addedMessage(name: string, position: number, count: number): string {
  return 'Added ' + name + ' as stop ' + fmtCount(position) + ' of ' + fmtCount(count) + '.';
}

/** What the live region says after a stop was removed. */
export function removedMessage(name: string): string {
  return 'Removed ' + name + '.';
}

// -------------------------------------------------------------------------------------------------
// 3. The window a new stop starts with
// -------------------------------------------------------------------------------------------------

/** 11 AM to 2 PM: what a new stop gets when no better window is found. */
export const DEFAULT_WINDOW: Readonly<StopWindow> = { open: 660, close: 840 };

const FIRST_HOUR = 6;
const LAST_HOUR = 24;
const WINDOW_HOURS = 3;

type Busy = Pick<DraftStop, 'open_minute' | 'close_minute'>;

/** True when the clock hour [hour, hour + 1) of the service date overlaps none of the stops. */
function hourFree(hour: number, others: readonly Busy[]): boolean {
  for (const stop of others) {
    if (hour * 60 < stop.close_minute && (hour + 1) * 60 > stop.open_minute) return false;
  }
  return true;
}

/**
 * The window a new stop at a saved spot starts with: the spot's best three hours on that date among
 * the hours 6 AM to midnight that overlap none of the other stops, from the model's own
 * `bestWindows` over the hourly orders of the date. The days and hours the owner set for the spot
 * are respected when a window fits inside them. Without a context, without usable vectors or
 * without a free window with orders: 11 AM to 2 PM.
 */
export function defaultSpotWindow(
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
  ctx: DayContext | null | undefined,
  spot: Spot,
  others: readonly Busy[],
): StopWindow {
  const fallback = { open: DEFAULT_WINDOW.open, close: DEFAULT_WINDOW.close };
  if (ctx === null || ctx === undefined) return fallback;
  const vectors = spotVectors(spot);
  // The estimator is never called with terms and vectors that do not match (rule 6 of 2.5).
  if (vectors === null || !vectorsMatch(A, spot.terms, vectors)) return fallback;
  const terms = { ...spot.terms, spot_id: spot.id };

  const values: number[] = [];
  const free: boolean[] = [];
  const inHours: boolean[] = [];
  const allowed = spot.terms.allowed;
  for (let hour = FIRST_HOUR; hour < LAST_HOUR; hour++) {
    values.push(hourlyOrders(A, profile, terms, vectors, cal, ctx, hour).orders);
    const isFree = hourFree(hour, others);
    free.push(isFree);
    inHours.push(
      isFree &&
        (allowed === null ||
          (allowed.days[ctx.dow] === true && hour * 60 >= allowed.open_minute && (hour + 1) * 60 <= allowed.close_minute)),
    );
  }
  let best = bestWindows(values, WINDOW_HOURS, 1, false, inHours);
  if (best.length === 0 && allowed !== null) best = bestWindows(values, WINDOW_HOURS, 1, false, free);
  if (best.length === 0) return fallback;
  const open = (FIRST_HOUR + best[0].start) * 60;
  return { open, close: open + WINDOW_HOURS * 60 };
}

/**
 * The window a new event or catering stop starts with (the owner sets the real one in the card):
 * 11 AM to 2 PM when that is free, else the first three free hours after the last stop, else the
 * last three free hours before it (a gap between two stops, or the morning before a long one), and
 * 11 AM to 2 PM all the same when the day has no three free hours left.
 */
export function freeWindow(others: readonly Busy[]): StopWindow {
  const fallback = { open: DEFAULT_WINDOW.open, close: DEFAULT_WINDOW.close };
  const fits = (startHour: number): boolean => {
    for (let hour = startHour; hour < startHour + WINDOW_HOURS; hour++) {
      if (!hourFree(hour, others)) return false;
    }
    return true;
  };
  const from = (hour: number): StopWindow => ({ open: hour * 60, close: (hour + WINDOW_HOURS) * 60 });
  if (fits(DEFAULT_WINDOW.open / 60)) return fallback;
  let latest = 0;
  for (const stop of others) {
    if (stop.close_minute > latest) latest = stop.close_minute;
  }
  for (let hour = Math.ceil(latest / 60); hour + WINDOW_HOURS <= LAST_HOUR; hour++) {
    if (hour >= FIRST_HOUR && fits(hour)) return from(hour);
  }
  for (let hour = LAST_HOUR - WINDOW_HOURS; hour >= FIRST_HOUR; hour--) {
    if (fits(hour)) return from(hour);
  }
  return fallback;
}

/** What `?add=<spotId>&open=<minute>&close=<minute>` asks for; null without a spot. */
export interface AddRequest {
  spotId: string;
  /** The window the link names, or null when it names none (or one that is not a window). */
  window: StopWindow | null;
}

function minuteParam(text: string | null): number | null {
  if (text === null || !/^[0-9]{1,4}$/.test(text)) return null;
  const minute = Number(text);
  return minute >= 0 && minute <= 2880 ? minute : null;
}

/** Read the planner's prefill parameters (1.2). A value that is not valid counts as absent. */
export function addRequest(params: { get(name: string): string | null }): AddRequest | null {
  const spotId = params.get('add');
  if (spotId === null || spotId === '') return null;
  const open = minuteParam(params.get('open'));
  const close = minuteParam(params.get('close'));
  return { spotId, window: open !== null && close !== null && close > open ? { open, close } : null };
}

// -------------------------------------------------------------------------------------------------
// 4. Saving
// -------------------------------------------------------------------------------------------------

function stopBody(stop: DraftStop): PlanStopBody {
  const body: PlanStopBody = { kind: stop.kind, open_minute: stop.open_minute, close_minute: stop.close_minute };
  // A stop the server has not seen is sent without an id: the server gives it one.
  if (!isTemporaryStopId(stop.id)) body.id = stop.id;
  body.gap_before_unpaid = stop.gap_before_unpaid;
  body.setup_minutes = stop.setup_minutes;
  body.teardown_minutes = stop.teardown_minutes;
  if (stop.kind === 'spot') {
    // A spot's place and fees are its own terms: nothing else is sent for it.
    body.spot_id = stop.spot_id;
    return body;
  }
  body.point = stop.point === null ? null : { lat: stop.point.lat, lng: stop.point.lng };
  body.label = stop.label;
  body.address = stop.address;
  if (stop.kind === 'event') {
    body.fee_flat = stop.fee_flat;
    body.fee_pct = stop.fee_pct;
    body.fee_min = stop.fee_min;
    body.event = stop.event;
  } else {
    body.catering = stop.catering;
  }
  return body;
}

/**
 * The body of routes 23 and 26: the date, "Treat this day as", the notes, `status: 'planned'` and
 * the stops in the owner's order. Saving a day always makes it a planned day.
 */
export function planBody(draft: PlanDraft): PlanBody {
  return {
    date: draft.date,
    treat_as: draft.treat_as,
    notes: draft.notes === '' ? null : draft.notes,
    status: 'planned',
    stops: draft.stops.map(stopBody),
  };
}

/** What `draftChanged` reads of the day the server holds. */
export type SavedDay = Pick<Plan, 'id' | 'date' | 'treat_as' | 'notes' | 'stops'>;

/**
 * True when saving the draft would change the day the server holds (null: the date has no saved
 * day): another stop, another order, another time or flag, another "Treat this day as". A stop that
 * was removed and added again counts as a change, because the server would store it as a new stop.
 */
export function draftChanged(draft: PlanDraft, saved: SavedDay | null): boolean {
  if (saved === null) return draft.stops.length > 0 || draft.treat_as !== null || draft.notes !== '';
  const before = planBody({ ...draftFromPlan(saved), date: draft.date });
  return sortedJson(planBody(draft)) !== sortedJson(before);
}

/** The save state the header prints. `none`: an empty day nobody has touched. */
export type SaveState = 'none' | 'new' | 'unsaved' | 'saved' | 'draft';

export const SAVE_STATE_TEXT: Readonly<Record<Exclude<SaveState, 'none'>, string>> = {
  new: 'Not saved yet',
  unsaved: 'Unsaved changes',
  saved: 'All changes saved',
  draft: 'Draft: save the day to confirm it',
};

/**
 * Which save state a day is in. A saved day whose status is `draft` (a suggested day nobody has
 * confirmed) says so until it is saved, which makes it a planned day.
 */
export function saveStateOf(saved: Pick<Plan, 'status'> | null, changed: boolean): SaveState {
  if (saved === null) return changed ? 'new' : 'none';
  if (changed) return 'unsaved';
  return saved.status === 'draft' ? 'draft' : 'saved';
}

export const SAVE_HINTS = {
  times: 'Fix the times first.',
  details: 'Finish the event or catering details first.',
  spot: 'Choose a spot for every stop first.',
} as const;

function windowIsValid(stop: Busy): boolean {
  return stop.open_minute >= 0 && stop.close_minute <= 2880 && stop.close_minute > stop.open_minute;
}

/**
 * Why the day cannot be saved as it stands, as the hint beside "Save day"; null when it can.
 * "Fix the times first." while a stop closes before it opens (the model's `invalid_window`, which
 * the server refuses too); the other two while a stop lacks what the server requires of it.
 */
export function saveBlocker(draft: PlanDraft, result: DayResult | null): string | null {
  if (result !== null && result.warnings.some((w) => w.code === 'invalid_window')) return SAVE_HINTS.times;
  for (const stop of draft.stops) {
    if (!windowIsValid(stop)) return SAVE_HINTS.times;
  }
  for (const stop of draft.stops) {
    if (stop.kind === 'spot') {
      if (stop.spot_id === null) return SAVE_HINTS.spot;
      continue;
    }
    if (stop.point === null) return SAVE_HINTS.details;
    if (stop.kind === 'event' && stop.event === null) return SAVE_HINTS.details;
    if (stop.kind === 'catering') {
      const terms = stop.catering;
      if (terms === null || (terms.price_per_head === null && terms.guarantee === null)) return SAVE_HINTS.details;
    }
  }
  return null;
}

// -------------------------------------------------------------------------------------------------
// 5. What the planner prints beside its estimates
// -------------------------------------------------------------------------------------------------

/** The options of "Treat this day as", in the order of the select. */
export const TREAT_AS_OPTIONS: readonly { value: TreatAs | null; label: string }[] = [
  { value: null, label: TREAT_AS_AUTOMATIC },
  { value: 'normal', label: TREAT_AS_LABELS.normal },
  { value: 'holiday', label: TREAT_AS_LABELS.holiday },
  { value: 'mon', label: TREAT_AS_LABELS.mon },
  { value: 'tue', label: TREAT_AS_LABELS.tue },
  { value: 'wed', label: TREAT_AS_LABELS.wed },
  { value: 'thu', label: TREAT_AS_LABELS.thu },
  { value: 'fri', label: TREAT_AS_LABELS.fri },
  { value: 'sat', label: TREAT_AS_LABELS.sat },
  { value: 'sun', label: TREAT_AS_LABELS.sun },
];

/** The value of a "Treat this day as" option; the empty text is "Automatic". */
export function treatAsOf(text: string): TreatAs | null {
  for (const option of TREAT_AS_OPTIONS) {
    if (option.value !== null && option.value === text) return option.value;
  }
  return null;
}

/**
 * The clock hours the header's weather chip is about: from the first opening to the last closing
 * (10 AM to 8 PM while nothing is planned). The chip reads one date, so a day that runs past
 * midnight stops at 24.
 */
export function weatherHours(stops: readonly Busy[]): { fromHour: number; toHour: number } {
  if (stops.length === 0) return { fromHour: 10, toHour: 20 };
  let open = stops[0].open_minute;
  let close = stops[0].close_minute;
  for (const stop of stops) {
    if (stop.open_minute < open) open = stop.open_minute;
    if (stop.close_minute > close) close = stop.close_minute;
  }
  let fromHour = Math.floor(open / 60);
  if (fromHour < 0) fromHour = 0;
  if (fromHour > 23) fromHour = 23;
  let toHour = Math.ceil(close / 60);
  if (toHour > 24) toHour = 24;
  if (toHour <= fromHour) toHour = fromHour + 1;
  return { fromHour, toHour };
}

export type LegPosition = 'first' | 'between' | 'last';

/** One drive of the day as its row prints it. */
export interface LegView {
  /** `<from_id>><to_id>`: the model's key of the leg. */
  key: string;
  position: LegPosition;
  /** `base` or the id of a stop. */
  fromId: string;
  toId: string;
  /** Index of the stop the drive leaves from and of the stop it leads to; null for the base. */
  fromStop: number | null;
  toStop: number | null;
  minutes: number;
  miles: number;
  /** "Drive from base: 11 min, 4.9 mi", "Drive: ...", "Drive back to base: ...". */
  title: string;
  /** The source label of 6.7: "Your time", "Google drive time, adjusted for 2:20 PM traffic", "Straight-line estimate". */
  source: string;
  /** A straight-line estimate: the label then carries a caution icon. */
  straight: boolean;
  /** Why Google did not supply the leg, when the server said. */
  reason: string | null;
  /** "Toll $3.75, Google's estimate" and its two siblings; null without a toll. */
  toll: string | null;
  /** "Start prep at 9:34 AM. Leave base by 10:19 AM", "Leave at 2:20 PM", "Leave at 8:20 PM. Back at base about 8:21 PM, done by 8:51 PM". */
  times: string;
  /** The leg as the server sent it; null when the model filled the pair with its own straight line. */
  sent: DriveLeg | null;
}

/** The toll line of a leg (4.5), or null: the owner's figure wins over Google's estimate. */
export function tollLine(sent: DriveLeg | null): string | null {
  if (sent === null) return null;
  if (sent.override !== null && sent.override.toll !== null) {
    return 'Toll ' + fmtMoneyCents(sent.override.toll) + ', your figure';
  }
  if (sent.toll_state === 'estimate' && sent.google_toll !== null) {
    return 'Toll ' + fmtMoneyCents(sent.google_toll) + ", Google's estimate";
  }
  if (sent.toll_state === 'unknown') return 'Tolls on this route, amount unknown';
  return null;
}

function findSent(sent: readonly DriveLeg[], fromId: string, toId: string): DriveLeg | null {
  for (const leg of sent) {
    if (leg.from_id === fromId && leg.to_id === toId) return leg;
  }
  return null;
}

/**
 * The drives of an evaluated day, in order: base to the first stop, stop to stop, the last stop
 * back to base. Minutes, miles and clock times are the model's (`timeline.legs`); the wording of
 * the source and the toll comes from the leg the server sent for the same pair.
 */
export function legViews(timeline: Timeline, sent: readonly DriveLeg[], trafficNeutral: boolean): LegView[] {
  const out: LegView[] = [];
  const legs = timeline.legs;
  for (let i = 0; i < legs.length; i++) {
    const leg = legs[i];
    const fromId = leg.from_id === null ? '' : leg.from_id;
    const toId = leg.to_id === null ? '' : leg.to_id;
    const position: LegPosition = i === 0 ? 'first' : i === legs.length - 1 ? 'last' : 'between';
    const server = findSent(sent, fromId, toId);
    const straight = leg.source === 'fallback';
    const figures = fmtDuration(leg.minutes) + ', ' + fmtMiles(leg.miles);
    const lead = position === 'first' ? 'Drive from base' : position === 'last' ? 'Drive back to base' : 'Drive';
    let times: string;
    if (position === 'first') {
      times = 'Start prep at ' + fmtClock(timeline.start_prep) + '. Leave base by ' + fmtClock(leg.depart_minute);
    } else if (position === 'last') {
      times =
        'Leave at ' +
        fmtClock(leg.depart_minute) +
        '. Back at base about ' +
        fmtClock(timeline.back_at_base) +
        ', done by ' +
        fmtClock(timeline.done);
    } else {
      times = 'Leave at ' + fmtClock(leg.depart_minute);
    }
    out.push({
      key: fromId + '>' + toId,
      position,
      fromId,
      toId,
      fromStop: i === 0 ? null : i - 1,
      toStop: i === legs.length - 1 ? null : i,
      minutes: leg.minutes,
      miles: leg.miles,
      title: lead + ': ' + figures,
      source: driveSourceLabel({
        legSource: leg.source,
        driveSource: server === null ? null : server.source,
        departMinute: leg.depart_minute,
        trafficNeutral,
      }),
      straight,
      reason: straight && server !== null ? driveFallbackReason(server.fallback_reason) : null,
      toll: tollLine(server),
      times,
      sent: server,
    });
  }
  return out;
}

/**
 * The first line of the leg editor: what the drive is estimated at before the owner's own time.
 * "Google: 10 min, 4.8 mi, before the time-of-day adjustment." or "Straight-line estimate: ...";
 * the tail is dropped while the region's traffic table changes nothing. A drive of a few seconds
 * reads "under 1 min", never "0 min": the row above it counts a minute for it.
 */
export function legEstimateLine(sent: DriveLeg | null, trafficNeutral: boolean): string {
  if (sent === null) return 'Straight-line estimate.';
  if (sent.source === 'same_point') return 'Same place: no drive.';
  const minutes = sent.duration_s / 60.0;
  const time = minutes > 0 && minutes < 0.5 ? 'under 1 min' : fmtDuration(minutes);
  const figures = time + ', ' + fmtMiles(sent.distance_m / METERS_PER_MILE);
  const lead = sent.source === 'straight_line' ? 'Straight-line estimate: ' : 'Google: ';
  return lead + figures + (trafficNeutral ? '.' : ', before the time-of-day adjustment.');
}

/** "What this stop adds" in the parts its lines print. */
export interface AddsView {
  takeHome: Estimate;
  /** "for 5.8 hours more:", before the per-hour figure; null when the stop adds no time to the day. */
  hoursLead: string | null;
  perHour: Estimate | null;
  /** "Needs 26 orders to pay for itself."; null for a catering job, which is contracted, not sold by the order. */
  breakEven: string | null;
  /** A loss said in words (6.1): "On a weak day this stop loses money." */
  loss: string | null;
  /** "Uses a straight-line drive estimate." when the day without this stop rests on one. */
  fallback: string | null;
}

/**
 * `codes` are the codes of the model's warnings about this stop: the model decides whether the stop
 * loses money (`below_break_even`) or only does so on a weak day (`weak_day_loss`).
 */
export function addsView(adds: StopAdds, kind: StopKind, codes: readonly string[] = []): AddsView {
  let breakEven: string | null = null;
  if (kind !== 'catering') {
    if (adds.break_even_orders === null) breakEven = 'It cannot pay for itself at these terms.';
    else {
      const orders = fmtCeil(adds.break_even_orders);
      breakEven = 'Needs ' + orders + (orders === '1' ? ' order' : ' orders') + ' to pay for itself.';
    }
  }
  let loss: string | null = null;
  if (codes.indexOf('below_break_even') >= 0) loss = 'This stop is expected to lose money.';
  else if (codes.indexOf('weak_day_loss') >= 0) loss = 'On a weak day this stop loses money.';
  return {
    takeHome: adds.take_home,
    hoursLead: adds.per_hour === null ? null : 'for ' + fmtHours(adds.hours) + ' more:',
    perHour: adds.per_hour,
    breakEven,
    loss,
    fallback: adds.uses_fallback_leg ? 'Uses a straight-line drive estimate.' : null,
  };
}

/** The codes of the model's warnings about one stop of the day. */
export function stopWarningCodes(result: DayResult, index: number): string[] {
  const out: string[] = [];
  for (const warning of result.warnings) {
    if (warning.stop_index === index) out.push(warning.code);
  }
  return out;
}

/**
 * The warnings a stop card repeats, because they are about the times being edited in it: every
 * problem, a late arrival, and hours outside the ones set for the spot. The full list is "Things
 * to check".
 */
export function isStopNotice(row: { level: string; code: string; stopIndex: number | null }, index: number): boolean {
  if (row.stopIndex !== index) return false;
  return row.level === 'error' || row.code === 'late_arrival' || row.code === 'outside_allowed_hours';
}

/** One of the day's own costs, or one fact about the day: a label, its figure, a second line. */
export interface SummaryLine {
  label: string;
  value: string;
  sub: string | null;
}

/**
 * The day's own costs: labour, fuel, tolls and the fixed cost of a service day. They are amounts
 * the owner's settings fix once the timeline is known, not estimates, so each prints as one figure.
 */
export function costLines(totals: DayTotals, profile: TruckProfile, ctx: DayContext): SummaryLine[] {
  const burden = profile.payroll_burden_pct > 0 ? ' + ' + fmtPercent(profile.payroll_burden_pct) : '';
  const source = ctx.fuel_price_source === null ? '' : ' (' + FUEL_SOURCE_PHRASE[ctx.fuel_price_source] + ')';
  return [
    {
      label: MONEY_LINE_LABELS.labour,
      value: fmtMoney(totals.labour.value),
      sub:
        fmtNumber(totals.paid_hours, 1) +
        ' paid hours x ' +
        fmtCount(profile.paid_crew) +
        ' crew x ' +
        fmtMoneyCents(profile.wage_per_hour) +
        burden,
    },
    {
      label: MONEY_LINE_LABELS.fuel,
      value: fmtMoneyCents(totals.fuel.value),
      sub:
        fmtNumber(totals.drive_gallons, 3) +
        ' gal driving + ' +
        fmtNumber(totals.generator_gallons, 3) +
        ' gal generator at ' +
        fmtFuel(ctx.fuel_price_per_gal) +
        source,
    },
    { label: MONEY_LINE_LABELS.tolls, value: fmtMoneyCents(totals.tolls.value), sub: null },
    { label: MONEY_LINE_LABELS.fixed_cost, value: fmtMoney(totals.fixed_cost.value), sub: null },
  ];
}

/** "Day length", "Driving" and, when there is one, "Unpaid break". */
export function dayFacts(timeline: Timeline): SummaryLine[] {
  const out: SummaryLine[] = [
    { label: 'Day length', value: fmtDuration(timeline.day_minutes), sub: null },
    { label: 'Driving', value: fmtDuration(timeline.drive_minutes) + ', ' + fmtMiles(timeline.miles), sub: null },
  ];
  if (timeline.unpaid_gap_minutes > 0) {
    out.push({ label: 'Unpaid break', value: fmtDuration(timeline.unpaid_gap_minutes), sub: null });
  }
  return out;
}

/** "11.3 hours worked": the line under take-home per hour. */
export function workedLine(totals: DayTotals): string {
  return fmtHours(totals.work_hours) + ' worked';
}

/** "Saves $79 in wages." in three parts, so the figure alone can take the colour of a saving. */
export function wagesSaved(alternative: UnpaidGapAlternative): { lead: string; figure: string; tail: string } {
  return { lead: 'Saves ', figure: fmtMoney(alternative.labour_saved), tail: ' in wages.' };
}

/** "Arrive 2:30 PM, set up from 4:30 PM": the line of a stop card, from the timeline. */
export function arriveLine(timed: { arrive: number; setup_start: number }): string {
  return 'Arrive ' + fmtClock(timed.arrive) + ', set up from ' + fmtClock(timed.setup_start);
}

/** "The 2 h wait before this stop is an unpaid break": the label of the switch on a stop card. */
export function waitToggleLabel(gapMinutes: number): string {
  return 'The ' + fmtDuration(gapMinutes) + ' wait before this stop is an unpaid break';
}
