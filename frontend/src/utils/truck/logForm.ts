// Truck Planner - the Log's services tab as pure functions (docs/truck-planner/05_FRONTEND.md 4.7).
//
// No I/O, no clock, no React. Five things live here:
//
//   1. The address of the page: which tab, and what a link asks the quick entry to start with.
//   2. The quick entry: its draft, the planned stop it may come from, its checks and the request
//      bodies of routes 32 and 34 (04_BACKEND 4.13). A value outside its range is refused with the
//      range message, never moved into range.
//   3. The estimate line: the figure the server will keep with the service (04_BACKEND 5.7), so the
//      owner sees before saving what the service will be judged against.
//   4. The result card: how the day compared, in words, and what it changed.
//   5. The history: its date range and the texts of its cells.
//
// A sold-out service is a minimum, not a measurement, and every sentence here says so.

import type { ServiceBody, ServiceLog, Spot } from '../../api/truck';
import { addDays, dayNumber, parseDate, roundHalfAway, windowOrders } from './model';
import type {
  Assumptions,
  CalibrationState,
  DayContext,
  DayResult,
  Estimate,
  LocationVectors,
  ServiceLogEntry,
  SpotTerms,
  StopKind,
  TreatAs,
  TruckProfile,
  WindowResult,
} from './model';
import { spotVectors } from './assemble';
import { DASH, fmtCount, fmtDay, fmtNumber } from './format';
import { calibrationBefore, resultText, verdictOf } from './logView';
import type { Verdict } from './logView';
import { FIELD, VERDICT_WORDS, numberRangeMessage } from './wording';

// -------------------------------------------------------------------------------------------------
// Limits of the API (04_BACKEND 4.13). Not model assumptions, so they have no seed.
// -------------------------------------------------------------------------------------------------

export const SERVICE_LIMITS = {
  actualMin: 0,
  actualMax: 5000,
  salesMin: 0,
  salesMax: 1000000,
  notesMax: 2000,
  minuteMin: 0,
  minuteMax: 2880,
  idMax: 36,
  /** A list of services holds at most this many dates, both ends counted. */
  rangeDays: 730,
  /** What the list shows before the owner picks dates: today and this many days before it. */
  defaultDaysBack: 90,
  /** Planned stops wait for their numbers for this many days. */
  pendingDays: 7,
} as const;

function isDate(text: string): boolean {
  try {
    parseDate(text);
    return true;
  } catch {
    return false;
  }
}

function outside(x: number, min: number, max: number): boolean {
  return !(x === x) || x < min || x > max;
}

function hasKey(map: object, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(map, key);
}

// -------------------------------------------------------------------------------------------------
// 1. The address of the page (05_FRONTEND 1.2)
// -------------------------------------------------------------------------------------------------

export type LogTab = 'services' | 'accuracy';

/** What a link asks the quick entry to start with. A value that is not valid counts as absent. */
export interface LogPrefill {
  spotId: string | null;
  date: string | null;
  open: number | null;
  close: number | null;
  /** A plan stop id: the entry comes from that planned stop. */
  stopId: string | null;
}

export interface LogParams {
  tab: LogTab;
  /** `new=1`: put the quick entry in front of the owner. */
  wantsNew: boolean;
  /** Null when the address names nothing to fill in. */
  prefill: LogPrefill | null;
}

/** The parameters the page takes once and then removes from its address. */
export const LOG_ENTRY_PARAMS: readonly string[] = ['new', 'spot', 'date', 'open', 'close', 'stop'];

function idParam(text: string | null): string | null {
  if (text === null) return null;
  const id = text.trim();
  return id === '' || id.length > SERVICE_LIMITS.idMax ? null : id;
}

function minuteParam(text: string | null): number | null {
  if (text === null || !/^\d{1,4}$/.test(text)) return null;
  const minute = Number(text);
  return outside(minute, SERVICE_LIMITS.minuteMin, SERVICE_LIMITS.minuteMax) ? null : minute;
}

/**
 * Reads `tab`, `new`, `spot`, `date`, `open`, `close` and `stop`. A date after today, a time outside
 * the day and the day after it, and a closing time that is not after the opening time count as
 * absent. A link that starts an entry always shows the services tab.
 */
export function readLogParams(get: (key: string) => string | null, today: string): LogParams {
  const wantsNew = get('new') === '1';
  const rawDate = get('date');
  const date = rawDate !== null && isDate(rawDate) && rawDate <= today ? rawDate : null;
  const open = minuteParam(get('open'));
  let close = minuteParam(get('close'));
  if (close !== null && open !== null && close <= open) close = null;
  const prefill: LogPrefill = { spotId: idParam(get('spot')), date, open, close, stopId: idParam(get('stop')) };
  const named =
    prefill.spotId !== null || prefill.date !== null || prefill.open !== null || prefill.close !== null || prefill.stopId !== null;
  const starts = wantsNew || named;
  return {
    tab: !starts && get('tab') === 'accuracy' ? 'accuracy' : 'services',
    wantsNew,
    prefill: named ? prefill : null,
  };
}

/** What `logHref` reads of a planned stop: an `UnloggedStop` of logView.ts has all of it. */
export interface StopToLog {
  stopId: string;
  spotId: string | null;
  date: string;
  openMinute: number;
  closeMinute: number;
}

/** The link "Log it" of a planned stop: the quick entry, filled in with that stop. */
export function logHref(stop: StopToLog): string {
  let href = '/truck/log?new=1&stop=' + encodeURIComponent(stop.stopId);
  if (stop.spotId !== null) href += '&spot=' + encodeURIComponent(stop.spotId);
  return href + '&date=' + stop.date + '&open=' + String(stop.openMinute) + '&close=' + String(stop.closeMinute);
}

// -------------------------------------------------------------------------------------------------
// 2. The quick entry
// -------------------------------------------------------------------------------------------------

/** Everything the quick entry edits. Number and time fields hold null while they are empty. */
export interface ServiceDraft {
  /** A saved spot, an event or a catering job. Null until the owner chooses. */
  kind: StopKind | null;
  /** The saved spot, when `kind` is `spot`. */
  spotId: string | null;
  date: string;
  open: number | null;
  close: number | null;
  /** Orders served. */
  actual: number | null;
  sales: number | null;
  soldOut: boolean;
  notes: string;
  /** The planned stop the entry comes from. It is sent only while the entry still describes that stop. */
  planStopId: string | null;
}

export function emptyServiceDraft(date: string): ServiceDraft {
  return {
    kind: null,
    spotId: null,
    date,
    open: null,
    close: null,
    actual: null,
    sales: null,
    soldOut: false,
    notes: '',
    planStopId: null,
  };
}

/** The values of the two entries of the "Spot" list that are not saved spots. */
export const CHOICE_EVENT = 'kind:event';
export const CHOICE_CATERING = 'kind:catering';

/** The value of the "Spot" list for a draft: a spot id, one of the two kinds, or '' while nothing is chosen. */
export function choiceOf(draft: ServiceDraft): string {
  if (draft.kind === 'event') return CHOICE_EVENT;
  if (draft.kind === 'catering') return CHOICE_CATERING;
  if (draft.kind === 'spot' && draft.spotId !== null) return draft.spotId;
  return '';
}

/** A pick in the "Spot" list. "An event" and "A catering job" set the kind and drop the spot. */
export function applyChoice(draft: ServiceDraft, value: string): ServiceDraft {
  if (value === CHOICE_EVENT) return { ...draft, kind: 'event', spotId: null };
  if (value === CHOICE_CATERING) return { ...draft, kind: 'catering', spotId: null };
  if (value === '') return { ...draft, kind: null, spotId: null };
  return { ...draft, kind: 'spot', spotId: value };
}

/** One saved spot of the "Spot" list. */
export interface SpotChoice {
  id: string;
  /** The spot's name; a deleted spot says so. */
  label: string;
}

/**
 * The saved spots the "Spot" list offers, in the order of the spot list: every spot that is not
 * deleted, and the entry's own spot even when it is (a planned stop at a spot that was deleted since
 * can still be logged, and a service logged there can still be edited).
 */
export function spotChoices(spots: readonly Pick<Spot, 'id' | 'name' | 'archived'>[], chosenSpotId: string | null): SpotChoice[] {
  const choices: SpotChoice[] = [];
  for (let i = 0; i < spots.length; i++) {
    const spot = spots[i];
    if (!spot.archived) choices.push({ id: spot.id, label: spot.name });
    else if (spot.id === chosenSpotId) choices.push({ id: spot.id, label: spot.name + ' (deleted)' });
  }
  return choices;
}

/** A planned stop as the quick entry needs it. */
export interface PlannedStop {
  planId: string;
  date: string;
  treatAs: TreatAs | null;
  stopId: string;
  kind: StopKind;
  spotId: string | null;
  /** The stop's own name: set for events and catering jobs. */
  label: string;
  open: number;
  close: number;
}

/** The part of a plan (a row of the plan list read with its stops, or a plan) that holds its stops. */
export interface PlanStopsLike {
  id: string;
  date: string;
  treat_as: TreatAs | null;
  stops: readonly {
    id: string;
    kind: StopKind;
    spot_id: string | null;
    label: string;
    open_minute: number;
    close_minute: number;
  }[];
}

/** The stop with this id among the plans, or null. */
export function findPlannedStop(plans: readonly PlanStopsLike[], stopId: string | null): PlannedStop | null {
  if (stopId === null) return null;
  for (let p = 0; p < plans.length; p++) {
    const plan = plans[p];
    for (let i = 0; i < plan.stops.length; i++) {
      const stop = plan.stops[i];
      if (stop.id !== stopId) continue;
      return {
        planId: plan.id,
        date: plan.date,
        treatAs: plan.treat_as,
        stopId: stop.id,
        kind: stop.kind,
        spotId: stop.spot_id,
        label: stop.label,
        open: stop.open_minute,
        close: stop.close_minute,
      };
    }
  }
  return null;
}

/**
 * True while the entry still describes the planned stop it came from: the same kind, the same date
 * and, for a spot stop, the same spot. The hours may differ (the truck opened late): the entry then
 * stays with its stop and takes a new estimate. An entry moved to another spot or date is a service
 * of its own, so its stop goes on waiting under "Not logged yet".
 */
export function linkHolds(draft: ServiceDraft, stop: PlannedStop | null): stop is PlannedStop {
  if (stop === null || draft.planStopId !== stop.stopId) return false;
  if (draft.kind !== stop.kind || draft.date !== stop.date) return false;
  return stop.kind !== 'spot' || draft.spotId === stop.spotId;
}

/** What a planned stop may still fill in once the plans have arrived: whatever the link left open. */
export interface StopFill {
  stopId: string;
  kind: boolean;
  date: boolean;
  open: boolean;
  close: boolean;
}

/**
 * The draft a link starts with. A link from a planned event or catering job names no spot, and a
 * short link may name the stop alone: `fill` says what the stop itself has to supply.
 */
export function draftFromPrefill(prefill: LogPrefill | null, today: string): { draft: ServiceDraft; fill: StopFill | null } {
  const draft = emptyServiceDraft(today);
  if (prefill === null) return { draft, fill: null };
  if (prefill.spotId !== null) {
    draft.kind = 'spot';
    draft.spotId = prefill.spotId;
  }
  if (prefill.date !== null) draft.date = prefill.date;
  draft.open = prefill.open;
  draft.close = prefill.close;
  draft.planStopId = prefill.stopId;
  if (prefill.stopId === null) return { draft, fill: null };
  const fill: StopFill = {
    stopId: prefill.stopId,
    kind: prefill.spotId === null,
    date: prefill.date === null,
    open: prefill.open === null,
    close: prefill.close === null,
  };
  return { draft, fill: fill.kind || fill.date || fill.open || fill.close ? fill : null };
}

/**
 * Fills what the link left open from the planned stop. What the owner has entered since stays.
 * A logged service keeps no name of its own, so a planned event or catering job also puts its
 * name into the notes while they are empty: the history can then tell one event from another.
 */
export function fillFromStop(draft: ServiceDraft, fill: StopFill, stop: PlannedStop): ServiceDraft {
  if (draft.planStopId !== fill.stopId || stop.stopId !== fill.stopId) return draft;
  const next = { ...draft };
  if (fill.kind && draft.kind === null) {
    next.kind = stop.kind;
    next.spotId = stop.spotId;
    if (stop.kind !== 'spot' && draft.notes.trim() === '' && stop.label.trim() !== '') next.notes = stop.label.trim();
  }
  if (fill.date) next.date = stop.date;
  if (fill.open && draft.open === null) next.open = stop.open;
  if (fill.close && draft.close === null) next.close = stop.close;
  return next;
}

/** The draft of a logged service, for "Edit". */
export function draftFromService(service: ServiceLog): ServiceDraft {
  return {
    kind: service.kind,
    spotId: service.kind === 'spot' ? service.spot_id : null,
    date: service.date,
    open: service.open_minute,
    close: service.close_minute,
    actual: service.actual,
    sales: service.sales,
    soldOut: service.sold_out,
    notes: service.notes ?? '',
    planStopId: service.plan_stop_id,
  };
}

/** One message per field that stops a save. */
export interface ServiceDraftErrors {
  spot?: string;
  date?: string;
  open?: string;
  close?: string;
  actual?: string;
  sales?: string;
  notes?: string;
}

export const ENTRY_TEXT = {
  chooseSpot: 'Choose a spot, an event or a catering job.',
  pickDate: 'Pick a date.',
  futureDate: 'The date must not be after today.',
  closeAfterOpen: 'The closing time must be after the opening time.',
  checkFields: 'Check the marked fields first. Nothing was saved.',
  nothingChanged: 'Nothing has changed.',
  saveFailed: 'Could not save the service.',
} as const;

/**
 * Checks a draft against the rules the server enforces (04_BACKEND 4.13). `spotIds` are the spots
 * the list offers (null while they are on their way): a spot that is not among them is not a choice.
 * An empty object means the draft can be saved.
 */
export function validateServiceDraft(draft: ServiceDraft, today: string, spotIds: ReadonlySet<string> | null = null): ServiceDraftErrors {
  const errors: ServiceDraftErrors = {};
  if (draft.kind === null) errors.spot = ENTRY_TEXT.chooseSpot;
  else if (draft.kind === 'spot' && (draft.spotId === null || (spotIds !== null && !spotIds.has(draft.spotId)))) {
    errors.spot = ENTRY_TEXT.chooseSpot;
  }

  if (!isDate(draft.date)) errors.date = ENTRY_TEXT.pickDate;
  else if (draft.date > today) errors.date = ENTRY_TEXT.futureDate;

  if (draft.open === null) errors.open = FIELD.required;
  else if (outside(draft.open, SERVICE_LIMITS.minuteMin, SERVICE_LIMITS.minuteMax)) errors.open = FIELD.timeUnreadable;
  if (draft.close === null) errors.close = FIELD.required;
  else if (outside(draft.close, SERVICE_LIMITS.minuteMin, SERVICE_LIMITS.minuteMax)) errors.close = FIELD.timeUnreadable;
  else if (draft.open !== null && errors.open === undefined && !(draft.close > draft.open)) errors.close = ENTRY_TEXT.closeAfterOpen;

  if (draft.actual === null) errors.actual = FIELD.required;
  else if (Math.floor(draft.actual) !== draft.actual || outside(draft.actual, SERVICE_LIMITS.actualMin, SERVICE_LIMITS.actualMax)) {
    errors.actual = numberRangeMessage(SERVICE_LIMITS.actualMin, SERVICE_LIMITS.actualMax, true);
  }
  if (draft.sales !== null && outside(draft.sales, SERVICE_LIMITS.salesMin, SERVICE_LIMITS.salesMax)) {
    errors.sales = numberRangeMessage(SERVICE_LIMITS.salesMin, SERVICE_LIMITS.salesMax);
  }
  if (draft.notes.trim().length > SERVICE_LIMITS.notesMax) {
    errors.notes = 'Use at most ' + fmtCount(SERVICE_LIMITS.notesMax) + ' characters.';
  }
  return errors;
}

/** Sales as they are sent and stored: whole cents. */
function cents(x: number | null): number | null {
  return x === null ? null : roundHalfAway(x, 2);
}

function notesOf(text: string): string | null {
  const trimmed = text.trim();
  return trimmed === '' ? null : trimmed;
}

/**
 * The body of route 32 for a draft that passed `validateServiceDraft`. `linked` says whether the
 * entry still describes its planned stop (`linkHolds`): only then is `plan_stop_id` sent, and the
 * server takes the plan's "treat this day as" with it. An event or a catering job carries no spot.
 */
export function serviceBody(draft: ServiceDraft, linked: boolean): ServiceBody {
  const kind: StopKind = draft.kind ?? 'spot';
  const body: ServiceBody = {
    kind,
    date: draft.date,
    open_minute: draft.open ?? 0,
    close_minute: draft.close ?? 0,
    actual: draft.actual ?? 0,
    sold_out: draft.soldOut,
  };
  if (kind === 'spot') body.spot_id = draft.spotId;
  const sales = cents(draft.sales);
  if (sales !== null) body.sales = sales;
  const notes = notesOf(draft.notes);
  if (notes !== null) body.notes = notes;
  if (linked && draft.planStopId !== null) body.plan_stop_id = draft.planStopId;
  return body;
}

/**
 * The body of route 34: only what differs from the logged service. `unlink` is true when the entry
 * no longer describes the planned stop it was logged from (`linkHolds` is false for a stop that is
 * known): the link is then taken away. An empty object means there is nothing to save.
 */
export function servicePatch(original: ServiceLog, draft: ServiceDraft, unlink: boolean): Partial<ServiceBody> {
  const patch: Partial<ServiceBody> = {};
  const kind: StopKind = draft.kind ?? original.kind;
  if (kind !== original.kind) patch.kind = kind;
  if (kind === 'spot' && draft.spotId !== null && draft.spotId !== original.spot_id) patch.spot_id = draft.spotId;
  if (draft.date !== original.date) patch.date = draft.date;
  if (draft.open !== null && draft.open !== original.open_minute) patch.open_minute = draft.open;
  if (draft.close !== null && draft.close !== original.close_minute) patch.close_minute = draft.close;
  if (draft.actual !== null && draft.actual !== original.actual) patch.actual = draft.actual;
  const sales = cents(draft.sales);
  if (sales !== cents(original.sales)) patch.sales = sales;
  if (draft.soldOut !== original.sold_out) patch.sold_out = draft.soldOut;
  const notes = notesOf(draft.notes);
  if (notes !== notesOf(original.notes ?? '')) patch.notes = notes;
  if (unlink && original.plan_stop_id !== null) patch.plan_stop_id = null;
  return patch;
}

/**
 * True when saving this edit makes the server work the estimate out again: the kind, the spot, the
 * date, the hours or the plan link changed. An edit of the count, the sales, the sold-out switch
 * or the notes keeps the estimate the service was logged with.
 */
export function estimateRebuilt(original: ServiceLog, draft: ServiceDraft, unlink: boolean): boolean {
  const patch = servicePatch(original, draft, unlink);
  return (
    patch.kind !== undefined ||
    patch.spot_id !== undefined ||
    patch.date !== undefined ||
    patch.open_minute !== undefined ||
    patch.close_minute !== undefined ||
    patch.plan_stop_id !== undefined
  );
}

// -------------------------------------------------------------------------------------------------
// 3. The estimate line (04_BACKEND 5.7)
// -------------------------------------------------------------------------------------------------

export const ESTIMATE_TEXT = {
  caption: 'The estimate for this service',
  fromLog: 'Uses only the services logged before this date.',
  fromPlan: 'The figure your saved plan showed for this stop.',
  kept: 'The estimate kept with this service. It stays unless you change the spot, the date or the hours.',
  none: 'No estimate for this spot and time.',
  noneForService: 'No estimate for this service.',
  noneKept: 'No estimate was kept with this service.',
  notPlanned: 'An event or a catering job has an estimate only when it is logged from a planned stop.',
  noVectors: 'This spot has no estimate yet.',
  hint: 'Choose the spot and the hours to see the estimate.',
  failed: 'The estimate could not be worked out: your logged services did not load.',
} as const;

/** The prediction kept with a logged service as the estimate it was, or null when none was kept. */
export function storedEstimate(service: Pick<ServiceLog, 'prediction'>): Estimate | null {
  const p = service.prediction;
  if (p === null) return null;
  return { value: p.predicted, low: p.low, high: p.high, confidence: p.confidence };
}

/** What `plannedStopEstimate` reads of the entry. */
export interface EntryForEstimate {
  stopId: string;
  kind: StopKind;
  spotId: string | null;
  date: string;
  open: number;
  close: number;
  /** The "treat this day as" the service will carry. */
  treatAs: TreatAs | null;
}

/**
 * Rule 1: the figure a saved plan showed for the stop the entry comes from, which is what the
 * server then keeps with the service. It applies when the plan still has its stored result, that
 * result holds the stop, and the plan's date and "treat this day as" are the entry's. A spot stop
 * must also be at the entry's spot and have run exactly the entry's hours, counted from the time
 * the truck could open (`effective_open`). Anything else is null: rule 2 takes over.
 */
export function plannedStopEstimate(
  plan: { date: string; treat_as: TreatAs | null; result: DayResult | null } | null | undefined,
  entry: EntryForEstimate,
): Estimate | null {
  if (plan === null || plan === undefined || plan.result === null) return null;
  if (plan.date !== entry.date || plan.treat_as !== entry.treatAs) return null;
  const result = plan.result;
  for (let i = 0; i < result.stops.length; i++) {
    const stop = result.stops[i];
    if (stop.id !== entry.stopId) continue;
    if (stop.kind !== entry.kind) return null;
    if (entry.kind !== 'spot') return stop.orders;
    if (stop.spot_id !== entry.spotId) return null;
    for (let k = 0; k < result.timeline.stops.length; k++) {
      const times = result.timeline.stops[k];
      if (times.stop_index !== stop.stop_index) continue;
      return times.effective_open === entry.open && times.close === entry.close ? stop.orders : null;
    }
    return null;
  }
  return null;
}

/** Rule 2 with everything it was worked out from, so "Why this number" can show the same steps. */
export interface LoggedEstimate {
  window: WindowResult;
  terms: SpotTerms;
  vectors: LocationVectors;
  /** What the services logged before the date say: the calibration this estimate was computed with. */
  cal: CalibrationState;
}

export interface LoggedEstimateInput {
  A: Assumptions;
  profile: TruckProfile;
  spot: Spot;
  /** Every logged service that carries a full prediction (route 37 without a range). */
  entries: readonly ServiceLogEntry[];
  /** The service being edited: it is never one of its own entries. */
  exceptServiceId?: string | null;
  date: string;
  open: number;
  close: number;
  /** The context of `date`, built with the "treat this day as" the service will carry. */
  ctx: DayContext;
  /** The context of the next date; needed only for hours past midnight. */
  ctxNext: DayContext | null;
}

/**
 * Rule 2: the window estimate of the chosen spot with its own terms and stored vectors, the
 * contexts of the date, and a calibration that knows only the services logged before that date.
 * Null for a spot without stored vectors, for hours the model would refuse and for hours past
 * midnight without the next date's context.
 */
export function loggedEstimate(input: LoggedEstimateInput): LoggedEstimate | null {
  const { A, profile, spot, date, open, close, ctx, ctxNext } = input;
  const vectors = spotVectors(spot);
  if (vectors === null) return null;
  if (!isDate(date)) return null;
  if (!(open >= 0 && close > open && close <= SERVICE_LIMITS.minuteMax)) return null;
  if (close > 1440 && ctxNext === null) return null;
  const except = input.exceptServiceId ?? null;
  const entries: ServiceLogEntry[] = [];
  for (let i = 0; i < input.entries.length; i++) {
    if (except === null || input.entries[i].service_id !== except) entries.push(input.entries[i]);
  }
  const cal = calibrationBefore(A, entries, date);
  const terms: SpotTerms = { ...spot.terms, spot_id: spot.id };
  const window = windowOrders(A, profile, terms, vectors, cal, ctx, ctxNext, open, close);
  return { window, terms, vectors, cal };
}

/** What the estimate line prints. */
export type EstimateLine =
  /** Nothing to estimate yet: the spot or the hours are still open. */
  | { kind: 'hint'; text: string }
  /** What it needs is on its way. */
  | { kind: 'wait' }
  /** The services that calibrate it could not be loaded. */
  | { kind: 'failed'; text: string }
  | { kind: 'none'; text: string; help: string | null }
  /** `detail` is set when the browser worked the figure out itself: it can then be explained. */
  | { kind: 'estimate'; estimate: Estimate; help: string; detail: LoggedEstimate | null };

export interface EstimateLineInput {
  A: Assumptions;
  profile: TruckProfile;
  draft: ServiceDraft;
  /** The logged service being edited, or null for a new entry. */
  original: ServiceLog | null;
  /**
   * The planned stop of the entry: `none`, `holds` (the entry still describes it), or `wait` while
   * the plans that would say so are on their way.
   */
  link: 'none' | 'holds' | 'wait';
  /** The plan of that stop with its stored result (route 25): undefined while it is on its way. */
  plan: { date: string; treat_as: TreatAs | null; result: DayResult | null } | null | undefined;
  /** True when the plan could not be loaded: rule 2 then stands in. */
  planFailed: boolean;
  treatAs: TreatAs | null;
  /** The chosen spot: undefined while the spots are on their way, null when it is not among them. */
  spot: Spot | null | undefined;
  /** Route 37 without a range: undefined while on its way. */
  entries: readonly ServiceLogEntry[] | undefined;
  entriesFailed: boolean;
  /** The contexts of the date and of the next date: null while they are on their way. */
  ctx: DayContext | null;
  ctxNext: DayContext | null;
}

/**
 * The estimate line of the quick entry, in the order the server decides it (04_BACKEND 5.7): an
 * edit that leaves the spot, the date and the hours alone keeps the stored estimate; an entry from
 * a planned stop takes the plan's figure when rule 1 holds; every other spot entry is rule 2; an
 * event or a catering job that is not a planned stop of its kind has none.
 */
export function estimateLine(input: EstimateLineInput): EstimateLine {
  const { draft, original } = input;
  if (original !== null && !estimateRebuilt(original, draft, false)) {
    const kept = storedEstimate(original);
    if (kept === null) return { kind: 'none', text: ESTIMATE_TEXT.noneKept, help: null };
    return { kind: 'estimate', estimate: kept, help: ESTIMATE_TEXT.kept, detail: null };
  }
  if (draft.kind === null || draft.open === null || draft.close === null || (draft.kind === 'spot' && draft.spotId === null)) {
    return { kind: 'hint', text: ESTIMATE_TEXT.hint };
  }
  const open = draft.open;
  const close = draft.close;
  if (!isDate(draft.date) || !(open >= 0 && close > open && close <= SERVICE_LIMITS.minuteMax)) {
    return { kind: 'none', text: ESTIMATE_TEXT.none, help: null };
  }

  if (input.link === 'wait') return { kind: 'wait' };
  if (input.link === 'holds' && draft.planStopId !== null && !input.planFailed) {
    if (input.plan === undefined) return { kind: 'wait' };
    const planned = plannedStopEstimate(input.plan, {
      stopId: draft.planStopId,
      kind: draft.kind,
      spotId: draft.spotId,
      date: draft.date,
      open,
      close,
      treatAs: input.treatAs,
    });
    if (planned !== null) return { kind: 'estimate', estimate: planned, help: ESTIMATE_TEXT.fromPlan, detail: null };
  }

  if (draft.kind !== 'spot') return { kind: 'none', text: ESTIMATE_TEXT.noneForService, help: ESTIMATE_TEXT.notPlanned };
  if (input.spot === undefined) return { kind: 'wait' };
  if (input.spot === null) return { kind: 'none', text: ESTIMATE_TEXT.none, help: null };
  if (spotVectors(input.spot) === null) return { kind: 'none', text: ESTIMATE_TEXT.none, help: ESTIMATE_TEXT.noVectors };
  if (input.entries === undefined) return input.entriesFailed ? { kind: 'failed', text: ESTIMATE_TEXT.failed } : { kind: 'wait' };
  if (input.ctx === null || (close > 1440 && input.ctxNext === null)) return { kind: 'wait' };

  const detail = loggedEstimate({
    A: input.A,
    profile: input.profile,
    spot: input.spot,
    entries: input.entries,
    exceptServiceId: original === null ? null : original.id,
    date: draft.date,
    open,
    close,
    ctx: input.ctx,
    ctxNext: input.ctxNext,
  });
  if (detail === null) return { kind: 'none', text: ESTIMATE_TEXT.none, help: null };
  return { kind: 'estimate', estimate: detail.window.orders, help: ESTIMATE_TEXT.fromLog, detail };
}

// -------------------------------------------------------------------------------------------------
// 4. Names, and the result card
// -------------------------------------------------------------------------------------------------

/** Names of the saved spots by id (deleted spots included, when the list holds them). */
export function spotNamesOf(spots: readonly Pick<Spot, 'id' | 'name'>[]): Record<string, string> {
  const names: Record<string, string> = Object.create(null) as Record<string, string>;
  for (let i = 0; i < spots.length; i++) names[spots[i].id] = spots[i].name;
  return names;
}

const NAME_TEXT = {
  event: 'Event',
  catering: 'Catering job',
  anEvent: 'an event',
  aCateringJob: 'a catering job',
  unknownSpot: 'A spot that is no longer listed',
  anUnknownSpot: 'a spot that is no longer listed',
} as const;

function knownName(spotId: string | null, names: Readonly<Record<string, string>>): string | null {
  if (spotId === null || !hasKey(names, spotId)) return null;
  const name = names[spotId];
  return typeof name === 'string' && name !== '' ? name : null;
}

/** What a list calls the place of a service: the spot's name, "Event" or "Catering job". */
export function serviceName(service: { kind: StopKind; spot_id: string | null }, names: Readonly<Record<string, string>>): string {
  if (service.kind === 'event') return NAME_TEXT.event;
  if (service.kind === 'catering') return NAME_TEXT.catering;
  return knownName(service.spot_id, names) ?? NAME_TEXT.unknownSpot;
}

/**
 * The same inside a sentence, after "at": the spot's name, "an event", "a catering job". `label` is
 * the name of the planned stop the service was logged from, when it has one.
 */
export function serviceWhere(
  service: { kind: StopKind; spot_id: string | null },
  names: Readonly<Record<string, string>>,
  label: string | null = null,
): string {
  if (service.kind === 'spot') return knownName(service.spot_id, names) ?? NAME_TEXT.anUnknownSpot;
  if (label !== null && label.trim() !== '') return label.trim();
  return service.kind === 'event' ? NAME_TEXT.anEvent : NAME_TEXT.aCateringJob;
}

function ordersWord(n: number): string {
  return roundHalfAway(n, 0) === 1 ? 'order' : 'orders';
}

function factorText(x: number): string {
  return 'x' + fmtNumber(x, 2);
}

/** The factor a spot's estimates are multiplied by on top of the truck's: 1 until it has one of its own. */
function spotFactorOf(cal: CalibrationState, spotId: string | null): number {
  return spotId !== null && hasKey(cal.spots, spotId) ? cal.spots[spotId].factor : 1.0;
}

/** What the result card says. Every string is a whole sentence. */
export interface ResultText {
  /** "Logged 52 orders at Reston office park." */
  headline: string;
  /** The stored prediction, for `RangeValue`; null when none was kept. */
  estimate: Estimate | null;
  /** How the count sits against that estimate; null without one. */
  verdict: Verdict | null;
  /** "inside the range", ... */
  verdictWords: string | null;
  /** "8 fewer than the estimate.", or what a sold-out count means. Null without an estimate. */
  compared: string | null;
  /** What the logged services now do to the estimates. */
  factors: string;
  /** The same before this service was saved; null when not known or when it does not apply. */
  before: string | null;
}

export const RESULT_TEXT = {
  estimateWas: 'The estimate was',
  onTheEstimate: 'Right on the estimate.',
  soldOut: 'You sold out, so this count is a minimum, not a measurement. It moves the estimates only when it says more than your other results at this spot.',
  soldOutNoEstimate: 'You sold out, so this count is a minimum, not a measurement.',
  notForRecords: 'Events and catering jobs are kept for your records. They do not adjust estimates.',
  noEstimateKept: 'No estimate was kept with this service, so it is not scored and does not adjust estimates.',
  unchanged: 'The same as before.',
} as const;

function factorsPair(cal: CalibrationState, spotId: string | null): string {
  return 'truck ' + factorText(cal.truck_factor) + ', this spot ' + factorText(spotFactorOf(cal, spotId));
}

/**
 * The sentences of the result card, from the answer of a save: the logged service with its stored
 * prediction, the calibration that results from it and, when known, the calibration from before.
 * `where` comes from `serviceWhere`; `edited` words the last line for a change to an old service.
 */
export function resultCardText(
  service: ServiceLog,
  calibration: CalibrationState,
  before: CalibrationState | null,
  where: string,
  edited = false,
): ResultText {
  const headline = 'Logged ' + fmtCount(service.actual) + ' ' + ordersWord(service.actual) + ' at ' + where + '.';
  const estimate = storedEstimate(service);
  let verdict: Verdict | null = null;
  let compared: string | null = service.sold_out ? RESULT_TEXT.soldOutNoEstimate : null;
  if (estimate !== null) {
    verdict = verdictOf({ actual: service.actual, low: estimate.low, high: estimate.high, sold_out: service.sold_out });
    if (service.sold_out) compared = RESULT_TEXT.soldOut;
    else {
      const diff = roundHalfAway(service.actual, 0) - roundHalfAway(estimate.value, 0);
      if (diff === 0) compared = RESULT_TEXT.onTheEstimate;
      else if (diff > 0) compared = fmtCount(diff) + ' more than the estimate.';
      else compared = fmtCount(-diff) + ' fewer than the estimate.';
    }
  }

  let factors: string;
  let was: string | null = null;
  if (service.kind !== 'spot') factors = RESULT_TEXT.notForRecords;
  else if (service.prediction === null) factors = RESULT_TEXT.noEstimateKept;
  else {
    const now = factorsPair(calibration, service.spot_id);
    factors = 'Your results now adjust estimates: ' + now + '.';
    if (before !== null) {
      const then = factorsPair(before, service.spot_id);
      was = then === now ? RESULT_TEXT.unchanged : 'Before this ' + (edited ? 'change' : 'service') + ': ' + then + '.';
    }
  }
  return {
    headline,
    estimate,
    verdict,
    verdictWords: verdict === null ? null : VERDICT_WORDS[verdict],
    compared,
    factors,
    before: was,
  };
}

// -------------------------------------------------------------------------------------------------
// 5. The history
// -------------------------------------------------------------------------------------------------

/** The DOM id of the history block: the page moves the focus there after a service is deleted. */
export const HISTORY_ID = 'tp-log-history';

/** The "Result" cell: "+5, inside the range"; without an estimate only a sold-out service has words. */
export function serviceResult(service: Pick<ServiceLog, 'actual' | 'sold_out' | 'prediction'>): string {
  const p = service.prediction;
  if (p === null) return service.sold_out ? VERDICT_WORDS.sold_out : DASH;
  return resultText({ actual: service.actual, low: p.low, high: p.high, sold_out: service.sold_out, predicted: p.predicted });
}

/**
 * True for a row the page shows before the server has answered: a service that was just entered
 * sits in the list under a temporary id (`new-1`, `new-2`, ...) until the saved one replaces it.
 * Such a row can be neither edited nor deleted yet. A stored id is a UUID and never starts so.
 */
export function isUnsavedService(service: Pick<ServiceLog, 'id'>): boolean {
  return service.id.startsWith('new-');
}

/** The order of the "Date" column: by date, then by opening time, so two services of a day keep their order. */
export function serviceWhen(service: Pick<ServiceLog, 'date' | 'open_minute'>): number {
  let day = 0;
  try {
    day = dayNumber(service.date);
  } catch {
    day = 0;
  }
  return day * (SERVICE_LIMITS.minuteMax + 1) + service.open_minute;
}

/** A date in a history cell: with its year only when that is not this year. */
export function historyDate(date: string, today: string): string {
  return fmtDay(date, date.slice(0, 4) === today.slice(0, 4) ? 'medium' : 'long');
}

/** The three filters of the history. The two dates are the values of date fields: '' while empty. */
export interface HistoryFilter {
  spotId: string;
  from: string;
  to: string;
}

/** What the history shows at first: every spot, today and the 90 days before it (the server's own default). */
export function defaultHistoryFilter(today: string): HistoryFilter {
  return { spotId: '', from: addDays(today, -SERVICE_LIMITS.defaultDaysBack), to: today };
}

export interface HistoryRange {
  /** What to ask route 31 for; empty for the default range, which is then the server's. */
  args: { from?: string; to?: string; spot_id?: string };
  /** True when the filter is the default one. */
  isDefault: boolean;
}

export const HISTORY_TEXT = {
  pickDate: 'Pick a date.',
  futureDate: 'The date must not be after today.',
  order: 'The last date must not be before the first.',
  tooLong: 'A list holds at most 730 days. Pick a later first date or an earlier last date.',
} as const;

/**
 * The request of a history filter, or the reason it cannot be sent. The rules are the server's
 * (04_BACKEND 4.13): both dates are real dates, the last is not before the first, and the range
 * holds at most 730 dates with both ends counted. Nothing is moved into range.
 */
export function historyRange(
  filter: HistoryFilter,
  today: string,
): { ok: true; range: HistoryRange } | { ok: false; errors: { from?: string; to?: string } } {
  const errors: { from?: string; to?: string } = {};
  if (!isDate(filter.from)) errors.from = HISTORY_TEXT.pickDate;
  else if (filter.from > today) errors.from = HISTORY_TEXT.futureDate;
  if (!isDate(filter.to)) errors.to = HISTORY_TEXT.pickDate;
  else if (filter.to > today) errors.to = HISTORY_TEXT.futureDate;
  if (errors.from === undefined && errors.to === undefined) {
    if (filter.to < filter.from) errors.to = HISTORY_TEXT.order;
    else if (dayNumber(filter.to) - dayNumber(filter.from) + 1 > SERVICE_LIMITS.rangeDays) errors.from = HISTORY_TEXT.tooLong;
  }
  if (errors.from !== undefined || errors.to !== undefined) return { ok: false, errors };
  const start = defaultHistoryFilter(today);
  const isDefault = filter.spotId === '' && filter.from === start.from && filter.to === start.to;
  if (isDefault) return { ok: true, range: { args: {}, isDefault } };
  const args: HistoryRange['args'] = { from: filter.from, to: filter.to };
  if (filter.spotId !== '') args.spot_id = filter.spotId;
  return { ok: true, range: { args, isDefault } };
}
