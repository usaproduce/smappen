// Truck Planner estimator - conventions (02_MODEL section 1).
//
// Vocabulary in index order, the constants as literals, the integer helpers, the one rounding helper
// and the ranking key. Everything in this folder obeys 02_MODEL 1.5: no clock, no random numbers, no
// locale, no environment, no I/O; sums are plain left-to-right additions; every ordering goes through
// qkey; rounding only through roundHalfAway.

import type {
  Confidence,
  Daypart,
  DayType,
  DowKey,
  ModelErrorCode,
  ModelVersion,
  OverrideErrorCode,
  PlaceType,
  Regime,
  RivalKind,
  SegmentKey,
  Visibility,
  WarningCode,
} from './types';

/** The model version carried by every stored result. */
export const MODEL_VERSION: ModelVersion = 'tps-0.1.0';

// 1.1 Fixed vocabulary, in index order. The seed test compares these with seeds.vocabulary.

/** The sixteen segments in index order. */
export const SEGMENTS: readonly SegmentKey[] = [
  'res',
  'w_office',
  'w_health',
  'w_edu',
  'w_retail',
  'w_industrial',
  'w_hospitality',
  'w_public',
  'v_nightlife',
  'v_shopping',
  'v_leisure',
  'v_campus',
  'v_hospital',
  'v_transit',
  'v_events',
  'v_lodging',
];
/** Competition regimes: loops run day, then eve. */
export const REGIMES: readonly Regime[] = ['day', 'eve'];
/** Rival (food outlet) kinds. */
export const RIVAL_KINDS: readonly RivalKind[] = ['quick', 'full', 'cafe', 'bar', 'convenience'];
/** Day types. */
export const DAY_TYPES: readonly DayType[] = ['weekday', 'saturday', 'sunday'];
/** Day-of-week keys; dow 0 = Monday. */
export const DOW_KEYS: readonly DowKey[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
/** Dayparts. */
export const DAYPARTS: readonly Daypart[] = ['breakfast', 'lunch', 'dinner', 'late'];
/** Visibility levels. */
export const VISIBILITY_LEVELS: readonly Visibility[] = ['hidden', 'normal', 'prominent'];
/** Confidence labels, weakest first. */
export const CONFIDENCE_LABELS: readonly Confidence[] = ['very_rough', 'rough', 'fair', 'good', 'fixed'];
/** Place types, in seed order. */
export const PLACE_TYPES: readonly PlaceType[] = [
  'taproom',
  'bar',
  'restaurant',
  'fast_food',
  'cafe',
  'convenience',
  'gym',
  'park',
  'shopping_centre',
  'big_box',
  'campus',
  'hospital',
  'transit_station',
  'events_venue',
  'stadium',
  'hotel',
  'attraction',
  'farmers_market',
  'office_park',
  'apartment_community',
  'industrial_site',
  'car_dealership',
];
/** Number of segments. */
export const NSEG = 16;

/** Plan warning codes, in the order day_plan emits them (02_MODEL 4.12). */
export const WARNING_CODES: readonly WarningCode[] = [
  'invalid_window',
  'stops_overlap',
  'stop_unreachable',
  'late_arrival',
  'outside_region',
  'stale_vectors',
  'outside_allowed_hours',
  'fallback_drive_time',
  'long_gap',
  'long_day',
  'fee_high',
  'below_break_even',
  'event_thin_crowd',
  'weak_day_loss',
  'capacity_bound',
  'early_start',
  'ends_after_midnight',
  'no_forecast',
  'holiday',
  'weak_seed',
  'default_host_size',
];
/** Error codes of validateOverrides, in step order (02_MODEL 2.2). */
export const OVERRIDE_ERRORS: readonly OverrideErrorCode[] = [
  'unknown_path',
  'not_a_seed',
  'not_overridable',
  'not_a_leaf',
  'wrong_shape',
  'out_of_bounds',
  'not_allowed',
];
/** Codes a ModelError can carry. */
export const MODEL_ERRORS: readonly ModelErrorCode[] = ['invalid_date', 'invalid_window', 'missing_context'];

// 1.2 Constants, written as literals (never a runtime constant). Also in the seed file under
// constants.*; the seed test asserts that the two agree.

/** IUGG mean Earth radius in metres: the only radius in the model. */
export const EARTH_RADIUS_M = 6371008.8;
/** Pi, written out. */
export const PI = 3.141592653589793;
/** Natural logarithm of 2. */
export const LN2 = 0.6931471805599453;
/** 0.9 quantile of the standard normal: half-width of an 80 % interval. */
export const Z80 = 1.2815515655446004;
/** Metres per international mile. */
export const METERS_PER_MILE = 1609.344;
/** 0.5 plus a 1e-9 nudge, added before the floor in roundHalfAway. */
export const ROUND_HALF = 0.500000001;
/** Ranking keys are whole millionths. */
export const QKEY_SCALE = 1000000.0;

/** An error the model document names: invalid_date, invalid_window, missing_context. */
export class ModelError extends Error {
  readonly code: ModelErrorCode;

  constructor(code: ModelErrorCode) {
    super(code);
    this.name = 'ModelError';
    this.code = code;
  }
}

/** floor(a / b) for integers, b > 0: rounds toward minus infinity. */
export function floorDiv(a: number, b: number): number {
  return Math.floor(a / b);
}

/** a - b * floorDiv(a, b): always in 0 .. b-1, also for a negative a. */
export function modFloor(a: number, b: number): number {
  return a - b * Math.floor(a / b);
}

/** lo if x < lo, hi if x > hi, else x. */
export function clamp(x: number, lo: number, hi: number): number {
  if (x < lo) return lo;
  if (x > hi) return hi;
  return x;
}

/** The smaller of two numbers: b only when it is strictly smaller (the two-argument form of 02_MODEL section 7). */
export function min2(a: number, b: number): number {
  return b < a ? b : a;
}

/** The larger of two numbers: b only when it is strictly larger. */
export function max2(a: number, b: number): number {
  return b > a ? b : a;
}

/**
 * A divisor that comes from the caller and must not be zero. The reference stops on a division by
 * zero; JavaScript would carry on with Infinity or NaN and put them on screen.
 */
export function nonZero(x: number, what: string): number {
  if (x === 0) throw new RangeError('division by zero: ' + what);
  return x;
}

// 1.4 Rounding

const POW10: readonly number[] = [
  1.0, 10.0, 100.0, 1000.0, 10000.0, 100000.0, 1000000.0, 10000000.0, 100000000.0, 1000000000.0,
];

/**
 * The only rounding helper: half away from zero at 0..9 decimals, with a 1e-9 nudge so that decimal
 * halves binary cannot represent (2.675) round the way a person expects. Never returns negative zero.
 */
export function roundHalfAway(x: number, decimals: number): number {
  if (!(decimals >= 0 && decimals <= 9) || Math.floor(decimals) !== decimals) {
    throw new RangeError('roundHalfAway: decimals must be an integer 0..9');
  }
  const p = POW10[decimals];
  const a = Math.abs(x) * p;
  const n = Math.floor(a + 0.500000001);
  const r = n / p;
  if (x < 0 && r !== 0) return -r;
  return r;
}

/** Ranking key in whole millionths. Every comparison that decides an order or a tie uses it. */
export function qkey(x: number): number {
  return Math.floor(x * 1000000.0 + 0.5);
}

// Identifiers and maps (02_MODEL 1.5 and section 7): ids are compared as plain strings, code unit by
// code unit; keys of a map are looked up as own properties only.

/** Three-way comparison of two identifiers in plain code-unit order. */
export function cmpStr(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

/** True when `key` is a key of the map itself (never of its prototype). */
export function hasOwn(map: object, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(map, key);
}

/** True for a plain keyed record (not null, not an array). */
export function isDict(x: unknown): x is Record<string, unknown> {
  return typeof x === 'object' && x !== null && !Array.isArray(x);
}

/** Set map[key] as an own property, whatever the key is called (a plain assignment to "__proto__" would not). */
export function putOwn<T>(map: Record<string, T>, key: string, value: T): void {
  if (key === '__proto__') {
    Object.defineProperty(map, key, { value, enumerable: true, writable: true, configurable: true });
  } else {
    map[key] = value;
  }
}

/** Index of a segment key, 0..15. */
export function segmentIndex(segment: SegmentKey): number {
  const i = SEGMENTS.indexOf(segment);
  if (i < 0) throw new Error('unknown segment: ' + String(segment));
  return i;
}
