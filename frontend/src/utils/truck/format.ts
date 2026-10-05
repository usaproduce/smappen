// Truck Planner formatters and parsers (docs/truck-planner/05_FRONTEND.md 3.1 and 3.6).
//
// Pure functions with fixed en-US output: no Intl, no Date, no locale and no runtime rounding. Every
// rounding goes through roundHalfAway from the model, digits are written and grouped by hand, and the
// same input always gives the same text on every machine. null and undefined give the em dash (the
// house convention of utils/format.ts). Ranges are written with the word "to", never with a dash,
// because a low can be negative.

import { dayOfWeek, floorDiv, modFloor, parseDate, roundHalfAway } from './model';
import type { Estimate } from './model';

/** Printed where there is no value. */
export const DASH = '—';

/** What an estimate counts: orders, dollars, or dollars per hour. */
export type EstimateUnit = 'orders' | 'money' | 'money_per_hour';

type Num = number | null | undefined;

const POW10: readonly number[] = [1, 10, 100, 1000, 10000, 100000, 1000000, 10000000, 100000000, 1000000000];

const WEEKDAYS_LONG: readonly string[] = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const WEEKDAYS_SHORT: readonly string[] = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const MONTHS_SHORT: readonly string[] = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function isNum(x: Num): x is number {
  return typeof x === 'number' && x === x && x !== Infinity && x !== -Infinity;
}

/** "1244215" -> "1,244,215". */
function groupDigits(digits: string): string {
  const n = digits.length;
  let out = '';
  for (let i = 0; i < n; i++) {
    if (i > 0 && (n - i) % 3 === 0) out += ',';
    out += digits[i];
  }
  return out;
}

/**
 * The digits of x rounded half away from zero at `decimals` (0..9), and whether a minus sign is due.
 * The sign follows the rounded value, so -0.4 at 0 decimals is "0" without a sign.
 */
function digitsOf(x: number, decimals: number, grouped: boolean): { negative: boolean; text: string } {
  const r = roundHalfAway(x, decimals);
  const p = POW10[decimals];
  const scaled = roundHalfAway(Math.abs(r) * p, 0); // an exact whole number of 10^-decimals units
  const frac = scaled % p;
  const whole = (scaled - frac) / p;
  let text = String(whole);
  if (grouped) text = groupDigits(text);
  if (decimals > 0) {
    let f = String(frac);
    while (f.length < decimals) f = '0' + f;
    text += '.' + f;
  }
  return { negative: r < 0, text };
}

function signed(x: number, decimals: number, grouped: boolean): string {
  const d = digitsOf(x, decimals, grouped);
  return (d.negative ? '-' : '') + d.text;
}

function dollars(x: number, decimals: number): string {
  const d = digitsOf(x, decimals, true);
  return (d.negative ? '-$' : '$') + d.text;
}

// -------------------------------------------------------------------------------------------------
// Numbers and money
// -------------------------------------------------------------------------------------------------

/** A plain figure with fixed decimals, grouped: factors, gallons, bounds. `0.963784, 2` -> "0.96". */
export function fmtNumber(x: Num, decimals = 0): string {
  if (!isNum(x)) return DASH;
  return signed(x, decimals, true);
}

/** A plain figure with up to `maxDecimals` decimals, trailing zeros dropped. `0.50` -> "0.5". */
export function fmtPlain(x: Num, maxDecimals = 3): string {
  if (!isNum(x)) return DASH;
  let text = signed(x, maxDecimals, true);
  if (maxDecimals > 0) {
    let end = text.length;
    while (text[end - 1] === '0') end--;
    if (text[end - 1] === '.') end--;
    text = text.slice(0, end);
  }
  return text;
}

/** Whole dollars; the minus sign comes before the dollar sign; never "-$0". */
export function fmtMoney(x: Num): string {
  if (!isNum(x)) return DASH;
  return dollars(x, 0);
}

/** Dollars and cents, for unit prices. */
export function fmtMoneyCents(x: Num): string {
  if (!isNum(x)) return DASH;
  return dollars(x, 2);
}

/** A fuel price: three decimals, per gallon. */
export function fmtFuel(x: Num): string {
  if (!isNum(x)) return DASH;
  return dollars(x, 3) + '/gal';
}

/** A whole number. */
export function fmtCount(x: Num): string {
  if (!isNum(x)) return DASH;
  return signed(x, 0, true);
}

/** One decimal, for per-hour table cells under 10. */
export function fmtCount1(x: Num): string {
  if (!isNum(x)) return DASH;
  return signed(x, 1, true);
}

/** Rounded up, for break-even orders: ceil(x - 1e-9), so 24.0000000001 is still 24. */
export function fmtCeil(x: Num): string {
  if (!isNum(x)) return DASH;
  return signed(Math.ceil(x - 1e-9), 0, true);
}

/** Dollars per hour. */
export function fmtPerHour(x: Num): string {
  if (!isNum(x)) return DASH;
  return dollars(x, 0) + ' an hour';
}

/**
 * A count of people, which carries no range: a whole number below 100, two significant digits
 * from 100. `437.11` -> "440".
 */
export function fmtAbout(x: Num): string {
  if (!isNum(x)) return DASH;
  const a = Math.abs(x);
  if (roundHalfAway(a, 0) < 100) return signed(x, 0, true);
  let unit = 1;
  while (a / unit >= 100) unit *= 10;
  const rounded = roundHalfAway(a / unit, 0) * unit;
  return signed(x < 0 ? -rounded : rounded, 0, true);
}

/** A fraction of 1 as a percentage. `0.3` -> "30%". */
export function fmtPercent(f: Num, decimals = 0): string {
  if (!isNum(f)) return DASH;
  return signed(f * 100, decimals, true) + '%';
}

/** Miles: one decimal under 100, whole from 100, and "under 0.1 mi" for anything below 0.05. */
export function fmtMiles(mi: Num): string {
  if (!isNum(mi)) return DASH;
  if (mi < 0.05) return 'under 0.1 mi';
  if (roundHalfAway(mi, 1) < 100) return signed(mi, 1, true) + ' mi';
  return signed(mi, 0, true) + ' mi';
}

/** Whole minutes as "45 min", "1 h 5 min", "2 h". */
export function fmtDuration(min: Num): string {
  if (!isNum(min)) return DASH;
  const total = roundHalfAway(min, 0);
  const a = Math.abs(total);
  const sign = total < 0 ? '-' : '';
  const m = a % 60;
  const h = (a - m) / 60;
  if (h === 0) return sign + String(m) + ' min';
  if (m === 0) return sign + groupDigits(String(h)) + ' h';
  return sign + groupDigits(String(h)) + ' h ' + String(m) + ' min';
}

/** Hours with one decimal for "more hours" sentences; a whole number drops the decimal. */
export function fmtHours(h: Num): string {
  if (!isNum(h)) return DASH;
  const tenths = roundHalfAway(Math.abs(roundHalfAway(h, 1)) * 10, 0);
  const text = tenths % 10 === 0 ? signed(h, 0, true) : signed(h, 1, true);
  return text + (tenths === 10 ? ' hour' : ' hours');
}

/** A temperature in whole degrees Fahrenheit. */
export function fmtTemp(f: Num): string {
  if (!isNum(f)) return DASH;
  return signed(f, 0, false) + '°F';
}

/** Coordinates with four decimals: "38.9600, -77.3600". */
export function fmtCoord(lat: Num, lng: Num): string {
  if (!isNum(lat) || !isNum(lng)) return DASH;
  return signed(lat, 4, false) + ', ' + signed(lng, 4, false);
}

/** A number with exactly `decimals` decimals and no grouping, as map links and cache keys write it. */
export function fmtFixed(x: number, decimals: number): string {
  return signed(x, decimals, false);
}

/** "+1" and ten digits become "(301) 742-8261"; anything else is returned unchanged. */
export function fmtPhone(s: string | null | undefined): string {
  if (s === null || s === undefined) return DASH;
  const m = /^\+1(\d{3})(\d{3})(\d{4})$/.exec(s);
  if (!m) return s;
  return '(' + m[1] + ') ' + m[2] + '-' + m[3];
}

// -------------------------------------------------------------------------------------------------
// Estimates
// -------------------------------------------------------------------------------------------------

/** The printed parts of an estimate; RangeValue lays them out, fmtEstimate joins them. */
export interface EstimateParts {
  /** The value, rounded for the unit: "60", "$482", "-$35". */
  value: string;
  /** "orders", "order", "an hour", or "" for plain money. */
  unitWord: string;
  low: string;
  high: string;
  /** False for a fixed amount and when low and high print the same. */
  hasRange: boolean;
  /** True when the printed value carries a minus sign. */
  negative: boolean;
}

function unitNumber(x: number, unit: EstimateUnit): string {
  return unit === 'orders' ? signed(x, 0, true) : dollars(x, 0);
}

/** Value, low and high are each rounded by the formatter of the unit. */
export function estimateParts(e: Estimate, unit: EstimateUnit): EstimateParts {
  const value = unitNumber(e.value, unit);
  const low = unitNumber(e.low, unit);
  const high = unitNumber(e.high, unit);
  let unitWord = '';
  if (unit === 'orders') unitWord = roundHalfAway(e.value, 0) === 1 ? 'order' : 'orders';
  else if (unit === 'money_per_hour') unitWord = 'an hour';
  return {
    value,
    unitWord,
    low,
    high,
    hasRange: e.confidence !== 'fixed' && low !== high,
    negative: roundHalfAway(e.value, 0) < 0,
  };
}

/** "33 to 93", "$85 to $659", "-$43 to $353". */
export function fmtRange(e: Estimate | null | undefined, unit: EstimateUnit): string {
  if (e === null || e === undefined) return DASH;
  return unitNumber(e.low, unit) + ' to ' + unitNumber(e.high, unit);
}

/**
 * Value, unit word, then the range in brackets: "60 orders (33 to 93)", "$135 (-$43 to $353)",
 * "$43 an hour ($4 to $90)". No range for a fixed amount or when low and high print the same.
 */
export function fmtEstimate(e: Estimate | null | undefined, unit: EstimateUnit): string {
  if (e === null || e === undefined) return DASH;
  const p = estimateParts(e, unit);
  let out = p.value;
  if (p.unitWord !== '') out += ' ' + p.unitWord;
  if (p.hasRange) out += ' (' + p.low + ' to ' + p.high + ')';
  return out;
}

/**
 * The same estimate for a sentence or a screen reader: "60 orders, likely between 33 and 93".
 * No full stop: the caller ends the sentence.
 */
export function fmtEstimateSpoken(e: Estimate | null | undefined, unit: EstimateUnit): string {
  if (e === null || e === undefined) return DASH;
  const p = estimateParts(e, unit);
  let out = p.value;
  if (p.unitWord !== '') out += ' ' + p.unitWord;
  if (p.hasRange) out += ', likely between ' + p.low + ' and ' + p.high;
  return out;
}

// -------------------------------------------------------------------------------------------------
// Clock times, hours of the week, dates
// -------------------------------------------------------------------------------------------------

function daySuffix(day: number): string {
  if (day === 0) return '';
  if (day === 1) return ' (next day)';
  if (day === -1) return ' (day before)';
  return day > 0 ? ' (' + String(day) + ' days later)' : ' (' + String(-day) + ' days before)';
}

function clockText(min: number, short: boolean): string {
  const total = roundHalfAway(min, 0);
  const day = floorDiv(total, 1440);
  const ofDay = total - 1440 * day;
  const hour = floorDiv(ofDay, 60);
  const minute = ofDay - 60 * hour;
  const h12 = hour % 12 === 0 ? 12 : hour % 12;
  let text = String(h12);
  if (!short || minute !== 0) text += ':' + (minute < 10 ? '0' : '') + String(minute);
  return text + (hour < 12 ? ' AM' : ' PM') + daySuffix(day);
}

/**
 * Minutes from midnight of the service date on the 12-hour clock: "9:34 AM". A minute of the next
 * day adds " (next day)", one of the day before " (day before)".
 */
export function fmtClock(min: Num): string {
  if (!isNum(min)) return DASH;
  return clockText(min, false);
}

/** The same without ":00": "11 AM", "2:30 PM". */
export function fmtClockShort(min: Num): string {
  if (!isNum(min)) return DASH;
  return clockText(min, true);
}

/** A service window: "11 AM to 2 PM", "9:30 PM to 1 AM (next day)". */
export function fmtWindow(open: Num, close: Num): string {
  if (!isNum(open) || !isNum(close)) return DASH;
  return clockText(open, true) + ' to ' + clockText(close, true);
}

/** Chart axis only: "12a", "1p". */
export function fmtHourTick(h: Num): string {
  if (!isNum(h)) return DASH;
  const hour = modFloor(roundHalfAway(h, 0), 24);
  return String(hour % 12 === 0 ? 12 : hour % 12) + (hour < 12 ? 'a' : 'p');
}

/** A weekday by index, 0 = Monday: "Thursday" or "Thu". */
export function fmtWeekday(dow: Num, style: 'long' | 'short' = 'long'): string {
  if (!isNum(dow)) return DASH;
  const i = modFloor(roundHalfAway(dow, 0), 7);
  return style === 'short' ? WEEKDAYS_SHORT[i] : WEEKDAYS_LONG[i];
}

/** An hour of the week (0 = Monday 12 AM): "Thu 12 PM". */
export function fmtHow(how: Num): string {
  if (!isNum(how)) return DASH;
  const i = modFloor(roundHalfAway(how, 0), 168);
  const hour = i % 24;
  return WEEKDAYS_SHORT[(i - hour) / 24] + ' ' + clockText(hour * 60, true);
}

/** The same in full: "Thursday, 12 PM to 1 PM". */
export function fmtHowLong(how: Num): string {
  if (!isNum(how)) return DASH;
  const i = modFloor(roundHalfAway(how, 0), 168);
  const hour = i % 24;
  return (
    WEEKDAYS_LONG[(i - hour) / 24] + ', ' + clockText(hour * 60, true) + ' to ' + clockText(((hour + 1) % 24) * 60, true)
  );
}

/**
 * A civil date "YYYY-MM-DD": long "Thu, Oct 8, 2026", medium "Thu, Oct 8", short "Oct 8". The
 * weekday comes from the model's dayOfWeek, the month from the string; no Date object is involved.
 */
export function fmtDay(date: string | null | undefined, style: 'long' | 'medium' | 'short' = 'medium'): string {
  if (date === null || date === undefined) return DASH;
  let month: number;
  let day: number;
  let year: number;
  let dow: number;
  try {
    const ymd = parseDate(date);
    year = ymd[0];
    month = ymd[1];
    day = ymd[2];
    dow = dayOfWeek(date);
  } catch {
    return DASH;
  }
  const monthDay = MONTHS_SHORT[month - 1] + ' ' + String(day);
  if (style === 'short') return monthDay;
  if (style === 'long') return WEEKDAYS_SHORT[dow] + ', ' + monthDay + ', ' + String(year);
  return WEEKDAYS_SHORT[dow] + ', ' + monthDay;
}

// -------------------------------------------------------------------------------------------------
// Parsers
// -------------------------------------------------------------------------------------------------

const DECIMAL = /^[+-]?(?:\d+\.?\d*|\.\d+)$/;

/**
 * The text of a number field as typed: "$", commas, spaces and one trailing "%" are dropped. What
 * is left must be a plain decimal number (digits, at most one point, an optional sign); anything
 * else, the empty string included, gives null. Returned as text so a caller can shift the decimal
 * point without a binary detour.
 */
export function cleanNumberText(text: string): string | null {
  let s = '';
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (c === '$' || c === ',' || c === ' ' || c === '\t' || c === '\n' || c === '\r' || c === ' ') continue;
    s += c;
  }
  if (s.length > 0 && s[s.length - 1] === '%') s = s.slice(0, s.length - 1);
  if (!DECIMAL.test(s)) return null;
  return s;
}

/** A typed number, or null for anything that is not a plain finite decimal number. */
export function parseNumber(text: string | null | undefined): number | null {
  if (text === null || text === undefined) return null;
  const s = cleanNumberText(text);
  if (s === null) return null;
  const n = Number(s);
  if (!isNum(n)) return null;
  return n === 0 ? 0 : n; // never negative zero
}

/**
 * "38.9696, -77.3861": two decimal numbers separated by a comma or spaces, latitude -90..90,
 * longitude -180..180.
 */
export function parseCoords(text: string | null | undefined): { lat: number; lng: number } | null {
  if (text === null || text === undefined) return null;
  const m = /^\s*([+-]?(?:\d+\.?\d*|\.\d+))\s*(?:,\s*|\s+)([+-]?(?:\d+\.?\d*|\.\d+))\s*$/.exec(text);
  if (!m) return null;
  const lat = Number(m[1]);
  const lng = Number(m[2]);
  if (!isNum(lat) || !isNum(lng)) return null;
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
  return { lat: lat === 0 ? 0 : lat, lng: lng === 0 ? 0 : lng };
}

export interface ParseClockOptions {
  /** The time this one has to come after (the opening time, for a closing time). */
  after?: number | null;
  /** The time may fall on the next day (minutes from 1440). */
  allowNextDay?: boolean;
}

/** Only A-Z become a-z; spaces and dots are dropped. */
function clockKey(text: string): string {
  let out = '';
  for (let i = 0; i < text.length; i++) {
    const code = text.charCodeAt(i);
    if (code === 32 || code === 9 || code === 10 || code === 13 || code === 160 || code === 46) continue;
    out += code >= 65 && code <= 90 ? String.fromCharCode(code + 32) : text[i];
  }
  return out;
}

/**
 * A typed time of day as minutes from midnight, or null when it cannot be read (05_FRONTEND 3.6).
 * "11" -> 660, "2" -> 840, "930" -> 570, "14:15" -> 855, "12a" -> 0, "noon" -> 720.
 */
export function parseClock(text: string | null | undefined, opts: ParseClockOptions = {}): number | null {
  if (text === null || text === undefined) return null;
  const after = opts.after === null || opts.after === undefined ? null : opts.after;
  const nextDay = opts.allowNextDay === true;
  const s = clockKey(text);

  let t: number;
  if (s === 'noon') t = 720;
  else if (s === 'midnight') t = 0;
  else {
    const m = /^(\d{1,2})(?::?(\d{2}))?(a|am|p|pm)?$/.exec(s);
    if (!m) return null;
    const hour = Number(m[1]);
    const minute = m[2] === undefined ? 0 : Number(m[2]);
    if (minute > 59) return null;
    const suffix = m[3];
    if (suffix !== undefined) {
      if (hour < 1 || hour > 12) return null;
      t = (hour % 12) * 60 + minute + (suffix[0] === 'p' ? 720 : 0);
    } else if (hour === 0) {
      t = minute;
    } else if (hour >= 13 && hour <= 23) {
      t = hour * 60 + minute;
    } else if (hour === 24) {
      if (minute !== 0) return null;
      t = 1440;
    } else if (hour > 24) {
      return null;
    } else {
      // 1..12 without a suffix has two readings.
      const am = (hour % 12) * 60 + minute;
      const pm = am + 720;
      const byHabit = hour >= 7 && hour <= 11 ? am : pm;
      if (after === null) t = byHabit;
      else if (am > after) t = am;
      else if (pm > after) t = pm;
      else if (nextDay && am + 1440 > after) t = am + 1440;
      else if (nextDay && pm + 1440 > after) t = pm + 1440;
      else t = byHabit;
    }
  }
  if (nextDay && after !== null && t <= after) t += 1440;
  return t;
}
