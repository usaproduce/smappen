// Truck Planner - saved spots as the Spots pages show them (docs/truck-planner/05_FRONTEND.md 4.4).
//
// Pure functions, no I/O, no clock, no React. Three things live here:
//
//   1. What a list row, a compare column and the spot form's preview print about a spot: the best
//      window of the typical week, a chosen window, the one-stop day. Every figure is computed by
//      the estimator from the spot's stored vectors, with exactly the inputs `useSpotEstimate` hands
//      it, so the list, the compare page and the spot card agree for the same window.
//   2. The small texts built from a spot's terms: the fee as a phrase, the host line.
//   3. The spot form: its draft, its checks and the request bodies of routes 11 and 14
//      (04_BACKEND 4.8). A value outside its range is refused with the range message, never clamped,
//      and a linked host is described by its place key, never by a point id.

import type {
  FuelInfo,
  HostHint,
  HostInput,
  Spot,
  SpotBody,
  SpotPatch,
  SpotTermsInput,
} from '../../api/truck';
import {
  METERS_PER_MILE,
  SEGMENTS,
  bestWindows,
  dayPlan,
  modFloor,
  qkey,
  roundHalfAway,
  stopMoney,
  typicalContext,
  vectorsMatch,
  weekStrip,
  windowOrders,
} from './model';
import type {
  AllowedHours,
  Assumptions,
  BestWindow,
  CalibrationState,
  DayContext,
  DayResult,
  Estimate,
  Host,
  LatLng,
  LegMap,
  LocationVectors,
  SegmentKey,
  SpotTerms,
  StopInput,
  StopMoney,
  TruckProfile,
  Visibility,
  WindowResult,
} from './model';
import { sortedJson, spotVectors, typicalWithFuel } from './assemble';
import { fmtCount, fmtMiles, fmtMoney, fmtMoneyCents, fmtPercent } from './format';
import { nextDateWithDow } from './time';
import {
  FIELD,
  SIZE_UNIT_PHRASE,
  numberRangeMessage,
  placeTypeLabel,
  segmentGroup,
  segmentLabel,
} from './wording';

// -------------------------------------------------------------------------------------------------
// 1. Estimates of a saved spot
// -------------------------------------------------------------------------------------------------

/** A window on a day of the typical week: `dow` 0 = Monday, minutes from local midnight; `close` may pass 1440. */
export interface WindowChoice {
  dow: number;
  open: number;
  close: number;
}

/** The first hour of a window as an hour of the week, and its length in whole hours (for the week strip). */
export function choiceHow(choice: WindowChoice): { how: number; hours: number } {
  const startHour = Math.floor(choice.open / 60);
  const endHour = Math.ceil(choice.close / 60 - 1e-9);
  return { how: modFloor(choice.dow, 7) * 24 + startHour, hours: endHour > startHour ? endHour - startHour : 1 };
}

/** A `bestWindows` result over a week strip as a window of a day. */
export function choiceOfBest(best: BestWindow): WindowChoice {
  const start = modFloor(best.start, 168);
  const hour = start % 24;
  return { dow: (start - hour) / 24, open: hour * 60, close: (hour + best.length) * 60 };
}

/** The seven typical contexts, Monday first. Build once and hand to the functions below. */
export function typicalWeek(A: Assumptions): DayContext[] {
  const out: DayContext[] = [];
  for (let dow = 0; dow < 7; dow++) out.push(typicalContext(A, dow));
  return out;
}

/** The same with the current fuel price, which a one-stop day needs. */
export function typicalWeekWithFuel(A: Assumptions, fuel: FuelInfo): DayContext[] {
  const out: DayContext[] = [];
  for (let dow = 0; dow < 7; dow++) out.push(typicalWithFuel(A, dow, fuel));
  return out;
}

/**
 * Why a spot has, or has not, numbers:
 * `ready` its stored vectors match its terms; `none` it has no stored vectors ("No estimate yet");
 * `mismatch` its stored vectors belong to other terms (its host was saved while the vectors could
 * not be recomputed). The estimator is never called with terms and vectors that do not match, so a
 * mismatched spot prints no figure until the refresh brings new vectors.
 */
export type SpotEstimateState = 'ready' | 'none' | 'mismatch';

/** The stored vectors of a spot when the estimator may use them with the spot's own terms. */
export function usableVectors(
  spot: Spot,
  A: Assumptions,
): { state: SpotEstimateState; vectors: LocationVectors | null } {
  const vectors = spotVectors(spot);
  if (vectors === null) return { state: 'none', vectors: null };
  if (!vectorsMatch(A, spot.terms, vectors)) return { state: 'mismatch', vectors: null };
  return { state: 'ready', vectors };
}

/** Orders and money of one window at a spot. */
export interface WindowFigures {
  choice: WindowChoice;
  window: WindowResult;
  money: StopMoney;
}

/**
 * A window of the typical week at a saved spot, from its stored vectors. Null when the spot has no
 * usable vectors, and for a window the model would refuse (anything but 0 <= open <= close <= 2880),
 * so a half-typed time prints no number instead of failing the page.
 */
export function spotWindowFigures(
  spot: Spot,
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
  typical: readonly DayContext[],
  choice: WindowChoice,
): WindowFigures | null {
  const { vectors } = usableVectors(spot, A);
  if (vectors === null) return null;
  return windowFigures(spot.terms, vectors, A, profile, cal, typical, choice);
}

/** The same for any matching pair of terms and vectors (a form's draft and the vectors it was given). */
export function windowFigures(
  terms: SpotTerms,
  vectors: LocationVectors,
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
  typical: readonly DayContext[],
  choice: WindowChoice,
): WindowFigures | null {
  if (!(choice.open >= 0 && choice.open <= choice.close && choice.close <= 2880)) return null;
  const d = modFloor(choice.dow, 7);
  const window = windowOrders(A, profile, terms, vectors, cal, typical[d], typical[(d + 1) % 7], choice.open, choice.close);
  return { choice: { dow: d, open: choice.open, close: choice.close }, window, money: stopMoney(profile, terms, window.orders) };
}

/** What the Spots list prints for one spot. */
export interface SpotSummary {
  spotId: string;
  state: SpotEstimateState;
  /** Expected orders per hour of the typical week (168 values), or null without usable vectors. */
  week: number[] | null;
  /** The best window of the typical week; null without usable vectors and when no hour has orders. */
  best: WindowChoice | null;
  window: WindowResult | null;
  orders: Estimate | null;
  money: StopMoney | null;
  /** What the window leaves after food, packaging, card fees and the spot fee. */
  contribution: Estimate | null;
}

/**
 * Per spot, the best window of the typical week, its orders and what it leaves after food and fees,
 * in one pass from the stored vectors of each spot. `hours` is the window length (2, 3 or 4).
 * The result is in the order of `spots`.
 */
export function spotSummaries(
  spots: readonly Spot[],
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
  hours: number,
): SpotSummary[] {
  const typical = typicalWeek(A);
  const out: SpotSummary[] = [];
  for (const spot of spots) {
    const usable = usableVectors(spot, A);
    const empty: SpotSummary = {
      spotId: spot.id,
      state: usable.state,
      week: null,
      best: null,
      window: null,
      orders: null,
      money: null,
      contribution: null,
    };
    if (usable.vectors === null) {
      out.push(empty);
      continue;
    }
    const week = weekStrip(A, profile, spot.terms, usable.vectors, cal);
    const picked = bestWindows(week, hours, 1, true);
    if (picked.length === 0) {
      out.push({ ...empty, week });
      continue;
    }
    const figures = windowFigures(spot.terms, usable.vectors, A, profile, cal, typical, choiceOfBest(picked[0]));
    if (figures === null) {
      out.push({ ...empty, week });
      continue;
    }
    out.push({
      spotId: spot.id,
      state: 'ready',
      week,
      best: figures.choice,
      window: figures.window,
      orders: figures.window.orders,
      money: figures.money,
      contribution: figures.money.contribution,
    });
  }
  return out;
}

/**
 * The whole day when a saved spot is its only stop, on a typical week with the current fuel price.
 * The stop, the contexts and the leg keys (`base>{id}`, `{id}>base`) are those `useSpotEstimate`
 * uses, so the figure equals the spot card's. A pair of legs that is absent is filled by the model
 * with a straight-line estimate, which it reports as the warning `fallback_drive_time`.
 */
export function spotOneStopDay(
  spot: Spot,
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
  typicalFuel: readonly DayContext[],
  today: string,
  legs: LegMap,
  choice: WindowChoice,
): DayResult | null {
  const { vectors } = usableVectors(spot, A);
  if (vectors === null) return null;
  if (!(choice.open >= 0 && choice.open <= choice.close && choice.close <= 2880)) return null;
  const d = modFloor(choice.dow, 7);
  const stop: StopInput = {
    id: spot.id,
    kind: 'spot',
    spot_id: spot.terms.spot_id,
    point: spot.point,
    open_minute: choice.open,
    close_minute: choice.close,
    gap_before_unpaid: false,
    setup_minutes: null,
    teardown_minutes: null,
    terms: spot.terms,
    vectors,
    event: null,
    catering: null,
  };
  // The date is only echoed by the model; the contexts are those of a typical week.
  return dayPlan(A, profile, { date: nextDateWithDow(today, d), stops: [stop] }, typicalFuel[d], typicalFuel[(d + 1) % 7], legs, cal);
}

// -------------------------------------------------------------------------------------------------
// The list: search and order
// -------------------------------------------------------------------------------------------------

export type SpotSort = 'best' | 'name';

/** ASCII letters in lower case, everything else as it is: search and name order never use a locale. */
export function asciiLower(text: string): string {
  let out = '';
  for (let i = 0; i < text.length; i++) {
    const c = text.charCodeAt(i);
    out += c >= 65 && c <= 90 ? String.fromCharCode(c + 32) : text[i];
  }
  return out;
}

function byCodeUnit(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

/** The host of a spot in a few words: the host's name, else who its people are, else the linked place's type. */
export function hostLine(spot: Spot): string | null {
  const name = spot.host_details?.name ?? '';
  if (name !== '') return name;
  if (spot.terms.host !== null) return segmentLabel(spot.terms.host.segment);
  const details = spot.host_details;
  if (details !== null && details.place_key !== null && details.place_key !== '') return placeTypeLabel(details.place_type);
  return null;
}

/** True when a spot matches the search text: its name, its host line or its address holds every word. */
export function spotMatches(spot: Spot, query: string): boolean {
  const words = asciiLower(query).split(' ').filter((word) => word !== '');
  if (words.length === 0) return true;
  const text = asciiLower(spot.name + ' ' + (hostLine(spot) ?? '') + ' ' + spot.address);
  return words.every((word) => text.includes(word));
}

/**
 * The ids of the spots in list order. `best`: the best window with the most expected orders first,
 * spots without a figure last; `name`: by name. Ties go to the name, then to the id, so the order
 * never depends on the browser.
 */
export function orderSpots(spots: readonly Spot[], summaries: readonly SpotSummary[], sort: SpotSort): string[] {
  const byId: Record<string, SpotSummary> = {};
  for (const s of summaries) byId[s.spotId] = s;
  const rows = spots.map((spot) => {
    const orders = Object.prototype.hasOwnProperty.call(byId, spot.id) ? byId[spot.id].orders : null;
    return { id: spot.id, name: asciiLower(spot.name), key: orders === null ? null : qkey(orders.value) };
  });
  rows.sort((a, b) => {
    if (sort === 'best') {
      if (a.key !== b.key) {
        if (a.key === null) return 1;
        if (b.key === null) return -1;
        return b.key - a.key;
      }
    }
    const byName = byCodeUnit(a.name, b.name);
    return byName !== 0 ? byName : byCodeUnit(a.id, b.id);
  });
  return rows.map((row) => row.id);
}

// -------------------------------------------------------------------------------------------------
// Compare
// -------------------------------------------------------------------------------------------------

/** At most this many spots side by side. */
export const COMPARE_MAX = 4;

/** `?ids=a,b,c`: the ids in the order given, each once, at most four. */
export function parseCompareIds(param: string | null | undefined): string[] {
  if (param === null || param === undefined) return [];
  const out: string[] = [];
  for (const id of param.split(',')) {
    const trimmed = id.trim();
    if (trimmed !== '' && !out.includes(trimmed) && out.length < COMPARE_MAX) out.push(trimmed);
  }
  return out;
}

/** `?win=`: `best`, or one window for every spot written `dow-open-close` (`3-660-840`). Anything else is `best`. */
export function parseCompareWindow(param: string | null | undefined): 'best' | WindowChoice {
  if (param === null || param === undefined) return 'best';
  const m = /^([0-6])-(\d{1,4})-(\d{1,4})$/.exec(param);
  if (m === null) return 'best';
  const open = Number(m[2]);
  const close = Number(m[3]);
  if (!(open <= close && close <= 2880)) return 'best';
  return { dow: Number(m[1]), open, close };
}

export function formatCompareWindow(win: 'best' | WindowChoice): string {
  return win === 'best' ? 'best' : String(win.dow) + '-' + String(win.open) + '-' + String(win.close);
}

/**
 * Which column carries "Highest expected": the largest expected take-home, compared in whole
 * millionths; on a tie the smallest id. Null when no column has a figure.
 */
export function highestExpected(columns: readonly { id: string; takeHome: Estimate | null }[]): string | null {
  let best: { id: string; key: number } | null = null;
  for (const column of columns) {
    if (column.takeHome === null) continue;
    const key = qkey(column.takeHome.value);
    if (best === null || key > best.key || (key === best.key && column.id < best.id)) best = { id: column.id, key };
  }
  return best === null ? null : best.id;
}

// -------------------------------------------------------------------------------------------------
// 2. Terms as text
// -------------------------------------------------------------------------------------------------

/** A dollar amount of a fee: whole dollars print without cents. */
function feeMoney(x: number): string {
  return Math.floor(x) === x ? fmtMoney(x) : fmtMoneyCents(x);
}

/** A share of sales: whole percents print without a decimal. */
export function sharePercent(f: number): string {
  const percent = roundHalfAway(f * 100, 6);
  return fmtPercent(f, Math.floor(percent) === percent ? 0 : 1);
}

/**
 * The fee of a spot as a phrase: "No fee", "$75 flat", "10% of sales", "$50 flat plus 10% of
 * sales", each followed by ", $75 minimum" when there is one.
 */
export function feeText(terms: { fee_flat: number; fee_pct: number; fee_min: number }): string {
  const flat = terms.fee_flat > 0 ? feeMoney(terms.fee_flat) + ' flat' : '';
  const share = terms.fee_pct > 0 ? sharePercent(terms.fee_pct) + ' of sales' : '';
  const minimum = terms.fee_min > 0 ? feeMoney(terms.fee_min) + ' minimum' : '';
  const paid = flat !== '' && share !== '' ? flat + ' plus ' + share : flat + share;
  if (paid === '' && minimum === '') return 'No fee';
  if (paid === '') return minimum;
  return minimum === '' ? paid : paid + ', ' + minimum;
}

/** "120 people in its busiest hour", "600 people working there": a host size with its unit phrase. */
export function hostSizeText(host: { segment: SegmentKey; size: number }): string {
  return fmtCount(host.size) + ' ' + SIZE_UNIT_PHRASE[segmentGroup(host.segment)];
}

/** The host of a spot for the compare table: its name or who its people are, then its size. */
export function hostText(spot: Spot): string {
  const host = spot.terms.host;
  const line = hostLine(spot);
  if (host === null) return line === null ? 'No host' : line;
  return (line ?? segmentLabel(host.segment)) + ', ' + hostSizeText(host);
}

const METERS_PER_FOOT = 0.3048;
/** Below this many feet a distance is said in feet: every place a spot can be linked to is nearer. */
const FEET_LIMIT = 1000;

/**
 * How far a nearby place is from the point. The places a spot can be linked to lie within a few
 * hundred feet, where tenths of a mile all read the same, so short distances are said in feet,
 * rounded to ten: "160 ft", "660 ft". From 1,000 ft on it is miles, as everywhere else in the app.
 */
export function distanceText(distanceM: number): string {
  const feet = distanceM / METERS_PER_FOOT;
  if (!(feet < FEET_LIMIT)) return fmtMiles(distanceM / METERS_PER_MILE);
  const tens = roundHalfAway(feet / 10, 0) * 10;
  if (tens < 10) return 'under 10 ft';
  if (tens >= FEET_LIMIT) return fmtMiles(distanceM / METERS_PER_MILE);
  return fmtCount(tens) + ' ft';
}

/** The days of an allowed-hours record as a phrase: "Every day", "Mon to Fri", "Mon, Wed, Sat". */
export function allowedDaysText(days: readonly boolean[], weekday: (dow: number) => string): string {
  const on: number[] = [];
  for (let d = 0; d < 7; d++) if (days[d] === true) on.push(d);
  if (on.length === 7) return 'Every day';
  if (on.length === 0) return 'No day';
  const consecutive = on.every((d, i) => i === 0 || d === on[i - 1] + 1);
  if (consecutive && on.length >= 3) return weekday(on[0]) + ' to ' + weekday(on[on.length - 1]);
  return on.map(weekday).join(', ');
}

// -------------------------------------------------------------------------------------------------
// 3. The spot form
// -------------------------------------------------------------------------------------------------

/**
 * What the server accepts for a spot (04_BACKEND 4.8). These are limits of the API, not model
 * assumptions, so they have no seed; the form reads them here and nowhere else.
 */
export const SPOT_LIMITS = {
  nameMax: 120,
  addressMax: 255,
  notesMax: 4000,
  feeMin: 0,
  feeMax: 100000,
  feePctMin: 0,
  feePctMax: 1,
  sizeMin: 1,
  sizeMax: 200000,
  minuteMin: 0,
  minuteMax: 2880,
  hostNameMax: 160,
  hostContactMax: 160,
  hostPhoneMax: 40,
  hostWebsiteMax: 255,
} as const;

/** A place within this distance of the point is offered as the host before anything else (4.3). */
export const HOST_QUESTION_RADIUS_M = 60;

/** The three answers to "Is there a host?". */
export type HostChoice = 'none' | 'place' | 'describe';

/** Everything the spot form edits. Number fields hold null while they are empty. */
export interface SpotDraft {
  name: string;
  point: LatLng | null;
  address: string;
  notes: string;
  visibility: Visibility;
  fee_flat: number | null;
  fee_pct: number | null;
  fee_min: number | null;
  allowedOn: boolean;
  allowedDays: boolean[];
  allowedOpen: number | null;
  allowedClose: number | null;
  hostChoice: HostChoice;
  /** The linked place (`place`). */
  placeKey: string | null;
  /** Who the linked place's people are; null when its kind brings no host of its own. */
  placeSegment: SegmentKey | null;
  /** The typical size of the linked place, 0 when it has none. */
  placeDefaultSize: number;
  placeType: string | null;
  placeName: string;
  /** Who the host's people are (`describe`). */
  segment: SegmentKey | null;
  /** The size the owner typed; null while the field is empty (a linked place then uses its typical size). */
  size: number | null;
  onlyFood: boolean;
  hostName: string;
  hostContact: string;
  hostPhone: string;
  hostWebsite: string;
}

const ALL_DAYS: readonly boolean[] = [true, true, true, true, true, true, true];

export function emptySpotDraft(): SpotDraft {
  return {
    name: '',
    point: null,
    address: '',
    notes: '',
    visibility: 'normal',
    fee_flat: 0,
    fee_pct: 0,
    fee_min: 0,
    allowedOn: false,
    allowedDays: ALL_DAYS.slice(),
    allowedOpen: null,
    allowedClose: null,
    hostChoice: 'none',
    placeKey: null,
    placeSegment: null,
    placeDefaultSize: 0,
    placeType: null,
    placeName: '',
    segment: null,
    size: null,
    onlyFood: false,
    hostName: '',
    hostContact: '',
    hostPhone: '',
    hostWebsite: '',
  };
}

function isSegment(x: unknown): x is SegmentKey {
  return typeof x === 'string' && (SEGMENTS as readonly string[]).includes(x);
}

/** The draft a form starts from: the body of route 11, or any part of it (a clicked point, a saved spot's fields). */
export function draftFromBody(initial: Partial<SpotBody>): SpotDraft {
  const draft = emptySpotDraft();
  if (typeof initial.name === 'string') draft.name = initial.name;
  if (initial.point !== undefined && initial.point !== null) draft.point = { lat: initial.point.lat, lng: initial.point.lng };
  if (typeof initial.address === 'string') draft.address = initial.address;
  if (typeof initial.notes === 'string') draft.notes = initial.notes;
  const terms = initial.terms;
  if (terms !== undefined) {
    if (terms.visibility !== undefined) draft.visibility = terms.visibility;
    if (terms.fee_flat !== undefined) draft.fee_flat = terms.fee_flat;
    if (terms.fee_pct !== undefined) draft.fee_pct = terms.fee_pct;
    if (terms.fee_min !== undefined) draft.fee_min = terms.fee_min;
    if (terms.allowed !== undefined && terms.allowed !== null) {
      draft.allowedOn = true;
      draft.allowedDays = [0, 1, 2, 3, 4, 5, 6].map((d) => terms.allowed !== null && terms.allowed !== undefined && terms.allowed.days[d] === true);
      draft.allowedOpen = terms.allowed.open_minute;
      draft.allowedClose = terms.allowed.close_minute;
    }
    const host = terms.host;
    if (host !== undefined && host !== null) {
      const typed = host.size !== undefined && host.size_source !== 'default';
      if (host.place_key !== undefined && host.place_key !== '') {
        draft.hostChoice = 'place';
        draft.placeKey = host.place_key;
        draft.placeSegment = isSegment(host.segment) ? host.segment : null;
        draft.placeDefaultSize = host.size !== undefined && host.size_source === 'default' ? host.size : 0;
      } else if (isSegment(host.segment)) {
        draft.hostChoice = 'describe';
        draft.segment = host.segment;
      }
      if (draft.hostChoice !== 'none') {
        draft.size = typed && host.size !== undefined ? host.size : null;
        draft.onlyFood = host.only_food === true;
      }
    }
  }
  const details = initial.host_details;
  if (details !== undefined) {
    draft.hostName = details.name ?? '';
    draft.hostContact = details.contact ?? '';
    draft.hostPhone = details.phone ?? '';
    draft.hostWebsite = details.website ?? '';
  }
  return draft;
}

/** A saved spot as the body that would create it again: what its edit form starts from. */
export function spotBodyOf(spot: Spot): SpotBody {
  const host = spot.terms.host;
  const placeKey = spot.host_details?.place_key ?? null;
  let hostInput: HostInput | null = null;
  if (host !== null) {
    hostInput = { segment: host.segment, size: host.size, size_source: host.size_source, only_food: host.only_food };
    if (placeKey !== null && placeKey !== '') hostInput.place_key = placeKey;
  } else if (placeKey !== null && placeKey !== '') {
    hostInput = { place_key: placeKey };
  }
  return {
    name: spot.name,
    point: { lat: spot.point.lat, lng: spot.point.lng },
    address: spot.address,
    notes: spot.notes,
    terms: {
      visibility: spot.terms.visibility,
      fee_flat: spot.terms.fee_flat,
      fee_pct: spot.terms.fee_pct,
      fee_min: spot.terms.fee_min,
      allowed: spot.terms.allowed === null ? null : { ...spot.terms.allowed, days: spot.terms.allowed.days.slice() },
      host: hostInput,
    },
    host_details: {
      name: spot.host_details?.name ?? null,
      contact: spot.host_details?.contact ?? null,
      phone: spot.host_details?.phone ?? null,
      website: spot.host_details?.website ?? null,
    },
  };
}

/** A draft with a nearby place chosen as its host: the place's kind gives the segment, the typical size and the food default. */
export function linkPlace(draft: SpotDraft, hint: HostHint): SpotDraft {
  return {
    ...draft,
    hostChoice: 'place',
    placeKey: hint.place_key,
    placeSegment: hint.host_segment,
    placeDefaultSize: hint.default_size > 0 ? hint.default_size : 0,
    placeType: hint.place_type,
    placeName: hint.name,
    size: null,
    onlyFood: hint.kitchen === 'no',
  };
}

/**
 * What the list of nearby places adds to a draft that was started from a saved spot: the name, the
 * kind and the typical size of its linked place. Nothing the owner entered changes.
 */
export function describeLinkedPlace(draft: SpotDraft, hints: readonly HostHint[] | null | undefined): SpotDraft {
  if (draft.hostChoice !== 'place' || draft.placeKey === null || hints === null || hints === undefined) return draft;
  const hint = hints.find((h) => h.place_key === draft.placeKey);
  if (hint === undefined) return draft;
  return {
    ...draft,
    placeSegment: draft.placeSegment ?? hint.host_segment,
    placeDefaultSize: hint.default_size > 0 ? hint.default_size : draft.placeDefaultSize,
    placeType: hint.place_type,
    placeName: hint.name,
  };
}

/** The segment the host of a draft has, or null when the draft has no host. */
export function draftHostSegment(draft: SpotDraft): SegmentKey | null {
  if (draft.hostChoice === 'place') return draft.placeKey === null ? null : draft.placeSegment;
  if (draft.hostChoice === 'describe') return draft.segment;
  return null;
}

/** The size the host of a draft has: what the owner typed, else the typical size of the linked place. Null when neither. */
export function draftHostSize(draft: SpotDraft): { size: number; source: 'owner' | 'default' } | null {
  if (draft.size !== null) return { size: draft.size, source: 'owner' };
  if (draft.hostChoice === 'place' && draft.placeDefaultSize > 0) return { size: draft.placeDefaultSize, source: 'default' };
  return null;
}

/** One message per field that stops a save. The keys are the form's field ids. */
export interface SpotDraftErrors {
  name?: string;
  point?: string;
  address?: string;
  notes?: string;
  fee_flat?: string;
  fee_pct?: string;
  fee_min?: string;
  allowedDays?: string;
  allowedOpen?: string;
  allowedClose?: string;
  place?: string;
  segment?: string;
  size?: string;
  hostName?: string;
  hostContact?: string;
  hostPhone?: string;
  hostWebsite?: string;
}

function tooLong(max: number): string {
  return 'Use at most ' + fmtCount(max) + ' characters.';
}

function outside(x: number, min: number, max: number): boolean {
  return !(x === x) || x < min || x > max;
}

/**
 * Checks a draft against the rules the server enforces (04_BACKEND 4.8). A value outside its range
 * is reported with the range; nothing is ever moved into range. An empty object means the draft
 * can be saved.
 */
export function validateSpotDraft(draft: SpotDraft): SpotDraftErrors {
  const errors: SpotDraftErrors = {};
  const name = draft.name.trim();
  if (name === '') errors.name = FIELD.required;
  else if (name.length > SPOT_LIMITS.nameMax) errors.name = tooLong(SPOT_LIMITS.nameMax);
  if (draft.point === null) errors.point = 'Pick a place first: search an address, enter coordinates or pick it on the map.';
  else if (outside(draft.point.lat, -90, 90) || outside(draft.point.lng, -180, 180)) {
    errors.point = 'Enter latitude and longitude, like 38.9696, -77.3861.';
  }
  if (draft.address.trim().length > SPOT_LIMITS.addressMax) errors.address = tooLong(SPOT_LIMITS.addressMax);
  if (draft.notes.trim().length > SPOT_LIMITS.notesMax) errors.notes = tooLong(SPOT_LIMITS.notesMax);

  const feeRange = numberRangeMessage(SPOT_LIMITS.feeMin, SPOT_LIMITS.feeMax);
  if (draft.fee_flat !== null && outside(draft.fee_flat, SPOT_LIMITS.feeMin, SPOT_LIMITS.feeMax)) errors.fee_flat = feeRange;
  if (draft.fee_min !== null && outside(draft.fee_min, SPOT_LIMITS.feeMin, SPOT_LIMITS.feeMax)) errors.fee_min = feeRange;
  if (draft.fee_pct !== null && outside(draft.fee_pct, SPOT_LIMITS.feePctMin, SPOT_LIMITS.feePctMax)) {
    errors.fee_pct = numberRangeMessage(SPOT_LIMITS.feePctMin * 100, SPOT_LIMITS.feePctMax * 100);
  }

  if (draft.allowedOn) {
    if (!draft.allowedDays.some((on) => on)) errors.allowedDays = 'Tick at least one day.';
    if (draft.allowedOpen === null) errors.allowedOpen = FIELD.required;
    if (draft.allowedClose === null) errors.allowedClose = FIELD.required;
    if (draft.allowedOpen !== null && draft.allowedClose !== null) {
      if (outside(draft.allowedOpen, SPOT_LIMITS.minuteMin, SPOT_LIMITS.minuteMax)) errors.allowedOpen = FIELD.timeUnreadable;
      else if (outside(draft.allowedClose, SPOT_LIMITS.minuteMin, SPOT_LIMITS.minuteMax)) errors.allowedClose = FIELD.timeUnreadable;
      else if (!(draft.allowedClose > draft.allowedOpen)) errors.allowedClose = 'The closing time must be after the opening time.';
    }
  }

  const sizeRange = numberRangeMessage(SPOT_LIMITS.sizeMin, SPOT_LIMITS.sizeMax);
  if (draft.hostChoice === 'place') {
    if (draft.placeKey === null) errors.place = 'Choose a place from the list, or pick another answer.';
    else if (draft.placeSegment !== null) {
      const size = draftHostSize(draft);
      if (size === null) errors.size = FIELD.required;
      else if (outside(size.size, SPOT_LIMITS.sizeMin, SPOT_LIMITS.sizeMax)) errors.size = sizeRange;
    }
  } else if (draft.hostChoice === 'describe') {
    if (draft.segment === null) errors.segment = "Choose who the host's people are.";
    if (draft.size === null) errors.size = FIELD.required;
    else if (outside(draft.size, SPOT_LIMITS.sizeMin, SPOT_LIMITS.sizeMax)) errors.size = sizeRange;
  }

  if (draft.hostName.trim().length > SPOT_LIMITS.hostNameMax) errors.hostName = tooLong(SPOT_LIMITS.hostNameMax);
  if (draft.hostContact.trim().length > SPOT_LIMITS.hostContactMax) errors.hostContact = tooLong(SPOT_LIMITS.hostContactMax);
  if (draft.hostPhone.trim().length > SPOT_LIMITS.hostPhoneMax) errors.hostPhone = tooLong(SPOT_LIMITS.hostPhoneMax);
  if (draft.hostWebsite.trim().length > SPOT_LIMITS.hostWebsiteMax) errors.hostWebsite = tooLong(SPOT_LIMITS.hostWebsiteMax);
  return errors;
}

export function draftIsValid(draft: SpotDraft): boolean {
  return Object.keys(validateSpotDraft(draft)).length === 0;
}

/**
 * The host of a draft as a request describes it (routes 9, 11 and 14): the place key of a linked
 * place, who the host's people are, its size and whether the truck is the only food. Never a point
 * id: the server derives that. Null without a host; `{ place_key }` alone for a linked place whose
 * kind brings no host of its own (the server then saves the link without a host).
 */
export function draftHostInput(draft: SpotDraft): HostInput | null {
  if (draft.hostChoice === 'none') return null;
  if (draft.hostChoice === 'place') {
    if (draft.placeKey === null) return null;
    if (draft.placeSegment === null) return { place_key: draft.placeKey };
    const size = draftHostSize(draft);
    const input: HostInput = { place_key: draft.placeKey, segment: draft.placeSegment, only_food: draft.onlyFood };
    if (size !== null) {
      input.size = size.size;
      input.size_source = size.source;
    }
    return input;
  }
  if (draft.segment === null) return null;
  const input: HostInput = { segment: draft.segment, only_food: draft.onlyFood };
  if (draft.size !== null) {
    input.size = draft.size;
    input.size_source = 'owner';
  }
  return input;
}

function draftAllowed(draft: SpotDraft): AllowedHours | null {
  if (!draft.allowedOn || draft.allowedOpen === null || draft.allowedClose === null) return null;
  return { days: draft.allowedDays.slice(0, 7), open_minute: draft.allowedOpen, close_minute: draft.allowedClose };
}

function draftTermsInput(draft: SpotDraft): Required<SpotTermsInput> {
  return {
    visibility: draft.visibility,
    fee_flat: draft.fee_flat ?? 0,
    fee_pct: draft.fee_pct ?? 0,
    fee_min: draft.fee_min ?? 0,
    allowed: draftAllowed(draft),
    host: draftHostInput(draft),
  };
}

function textOrNull(text: string): string | null {
  const trimmed = text.trim();
  return trimmed === '' ? null : trimmed;
}

function draftHostDetails(draft: SpotDraft): NonNullable<SpotBody['host_details']> {
  return {
    name: textOrNull(draft.hostName),
    contact: textOrNull(draft.hostContact),
    phone: textOrNull(draft.hostPhone),
    website: textOrNull(draft.hostWebsite),
  };
}

/** The body of route 11 for a valid draft (`validateSpotDraft` gave no error). */
export function bodyFromDraft(draft: SpotDraft): SpotBody {
  if (draft.point === null) throw new Error('bodyFromDraft: the draft has no point');
  return {
    name: draft.name.trim(),
    point: { lat: draft.point.lat, lng: draft.point.lng },
    address: draft.address.trim(),
    notes: textOrNull(draft.notes),
    terms: draftTermsInput(draft),
    host_details: draftHostDetails(draft),
  };
}

/**
 * The body of route 14: only what differs between the draft the form started from and the draft
 * now. Inside `terms` only the changed keys travel, `host` and `allowed` whole (the server replaces
 * those two whole). An empty object means there is nothing to save.
 */
export function patchFromDrafts(base: SpotDraft, draft: SpotDraft): SpotPatch {
  const patch: SpotPatch = {};
  if (draft.name.trim() !== base.name.trim()) patch.name = draft.name.trim();
  if (draft.point !== null && (base.point === null || draft.point.lat !== base.point.lat || draft.point.lng !== base.point.lng)) {
    patch.point = { lat: draft.point.lat, lng: draft.point.lng };
  }
  if (draft.address.trim() !== base.address.trim()) patch.address = draft.address.trim();
  if (textOrNull(draft.notes) !== textOrNull(base.notes)) patch.notes = textOrNull(draft.notes);

  const before = draftTermsInput(base);
  const after = draftTermsInput(draft);
  const terms: SpotTermsInput = {};
  if (after.visibility !== before.visibility) terms.visibility = after.visibility;
  if (after.fee_flat !== before.fee_flat) terms.fee_flat = after.fee_flat;
  if (after.fee_pct !== before.fee_pct) terms.fee_pct = after.fee_pct;
  if (after.fee_min !== before.fee_min) terms.fee_min = after.fee_min;
  if (sortedJson(after.allowed) !== sortedJson(before.allowed)) terms.allowed = after.allowed;
  if (sortedJson(after.host) !== sortedJson(before.host)) terms.host = after.host;
  if (Object.keys(terms).length > 0) patch.terms = terms;

  const detailsBefore = draftHostDetails(base);
  const detailsAfter = draftHostDetails(draft);
  const details: NonNullable<SpotBody['host_details']> = {};
  if (detailsAfter.name !== detailsBefore.name) details.name = detailsAfter.name;
  if (detailsAfter.contact !== detailsBefore.contact) details.contact = detailsAfter.contact;
  if (detailsAfter.phone !== detailsBefore.phone) details.phone = detailsAfter.phone;
  if (detailsAfter.website !== detailsBefore.website) details.website = detailsAfter.website;
  if (Object.keys(details).length > 0) patch.host_details = details;
  return patch;
}

/**
 * The model terms a draft is previewed with. The host is the draft's own description; the estimate
 * hook swaps in the server's resolution of it (the point id the vectors were computed with), so
 * terms and vectors match. A host that has no size yet counts as no host: nothing can be estimated
 * for it.
 */
export function draftModelTerms(draft: SpotDraft, spotId: string | null): SpotTerms {
  const segment = draftHostSegment(draft);
  const size = draftHostSize(draft);
  let host: Host | null = null;
  if (segment !== null && size !== null && size.size > 0) {
    host = {
      segment,
      size: size.size,
      size_source: size.source,
      only_food: draft.onlyFood,
      point_id: null,
      place_type: draft.hostChoice === 'place' ? draft.placeType : null,
    };
  }
  return {
    spot_id: spotId,
    visibility: draft.visibility,
    host,
    fee_flat: draft.fee_flat ?? 0,
    fee_pct: draft.fee_pct ?? 0,
    fee_min: draft.fee_min ?? 0,
    allowed: draftAllowed(draft),
  };
}

/** The place a draft's host is linked to, for the estimate hook (`hostPlaceKey`). */
export function draftPlaceKey(draft: SpotDraft): string | null {
  return draft.hostChoice === 'place' ? draft.placeKey : null;
}

/** The nearest place within 60 m of the point, which the form asks about before anything else. */
export function hostQuestion(hints: readonly HostHint[] | null | undefined): HostHint | null {
  if (hints === null || hints === undefined) return null;
  let nearest: HostHint | null = null;
  for (const hint of hints) {
    if (hint.distance_m > HOST_QUESTION_RADIUS_M) continue;
    if (nearest === null || hint.distance_m < nearest.distance_m) nearest = hint;
  }
  return nearest;
}
