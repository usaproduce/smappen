// Truck Planner UI kit - the logic of the components, kept pure so it can be tested in Node
// (docs/truck-planner/05_FRONTEND.md section 3; tests in utils/truck/__tests__/kit.test.ts).
//
// Nothing here touches the DOM or React. The components of this folder are thin shells around these
// functions: what a field commits, how a table sorts, what a weather chip says.

import { modFloor, qkey, roundHalfAway, scoreByte, seed, weatherMultiplier } from '../../../utils/truck/model';
import type { Assumptions, Confidence, DayContext, Estimate, HourForecast } from '../../../utils/truck/model';
import {
  cleanNumberText,
  fmtClock,
  fmtClockShort,
  fmtCount,
  fmtEstimateSpoken,
  fmtNumber,
  fmtPercent,
  fmtPlain,
  fmtTemp,
  fmtWeekday,
  parseClock,
} from '../../../utils/truck/format';
import type { EstimateUnit } from '../../../utils/truck/format';
import {
  FIELD,
  HOLIDAY_CHIP,
  WEATHER,
  captionSpoken,
  confidenceSpoken,
  numberRangeMessage,
  precipClassWord,
  timeRangeMessage,
  treatedAsDay,
} from '../../../utils/truck/wording';

// -------------------------------------------------------------------------------------------------
// RangeValue
// -------------------------------------------------------------------------------------------------

/**
 * The spoken label of an estimate: "60 orders, likely between 33 and 93. Rough: not yet checked
 * against your own sales." A caption such as "TAKE-HOME" leads it in sentence case.
 */
export function estimateAriaLabel(e: Estimate, unit: EstimateUnit, caption?: string): string {
  const lead = caption === undefined || caption === '' ? '' : captionSpoken(caption) + ': ';
  return lead + fmtEstimateSpoken(e, unit) + '. ' + confidenceSpoken(e.confidence);
}

/** The glyph of each confidence label, so the label never rests on colour. */
export const CONFIDENCE_GLYPH: Readonly<Record<Confidence, 'signal-low' | 'signal-medium' | 'signal-high' | 'signal' | 'lock'>> = {
  very_rough: 'signal-low',
  rough: 'signal-medium',
  fair: 'signal-high',
  good: 'signal',
  fixed: 'lock',
};

// -------------------------------------------------------------------------------------------------
// NumberField and MoneyField
// -------------------------------------------------------------------------------------------------

export interface NumberFieldRules {
  /** Bounds in the unit of the value (a fraction of 1 for a percent field). */
  min?: number;
  max?: number;
  /** percent: the value is a fraction, the field shows 30 for 0.30. */
  format?: 'number' | 'percent';
  integer?: boolean;
  required?: boolean;
  /** Decimals shown after a commit; left out, the value prints as typed (trailing zeros dropped). */
  decimals?: number;
  /** What one press of Arrow Up or Down changes, in the unit of the value. */
  step?: number;
}

export type NumberCommit =
  | { ok: true; value: number | null; error: string | null }
  | { ok: false; error: string };

/** "30" -> "0.30": the decimal point moved two places to the left, as text. */
function percentToFraction(clean: string): number {
  let sign = '';
  let body = clean;
  if (body[0] === '-' || body[0] === '+') {
    sign = body[0] === '-' ? '-' : '';
    body = body.slice(1);
  }
  const dot = body.indexOf('.');
  let whole = dot < 0 ? body : body.slice(0, dot);
  const frac = dot < 0 ? '' : body.slice(dot + 1);
  while (whole.length < 3) whole = '0' + whole;
  const n = Number(sign + whole.slice(0, whole.length - 2) + '.' + whole.slice(whole.length - 2) + frac);
  return n === 0 ? 0 : n;
}

function shown(x: number, rules: NumberFieldRules): number {
  return rules.format === 'percent' ? x * 100 : x;
}

/** The message for a value outside the range, with the bounds as the field shows them. */
export function numberFieldRangeMessage(rules: NumberFieldRules): string {
  const lo = rules.min === undefined ? null : roundHalfAway(shown(rules.min, rules), 6);
  const hi = rules.max === undefined ? null : roundHalfAway(shown(rules.max, rules), 6);
  return numberRangeMessage(lo, hi, rules.integer === true);
}

/**
 * What a number field does with its text on blur or Enter. Empty commits null (with "Required"
 * when the field is required). Text that is not a number, a fraction in a whole-number field and a
 * value outside the range do not commit: the caller keeps the last value and shows the message.
 * Values are never clamped.
 */
export function commitNumber(text: string, rules: NumberFieldRules = {}): NumberCommit {
  let empty = true;
  for (let i = 0; i < text.length; i++) {
    const c = text.charCodeAt(i);
    if (!(c === 32 || c === 9 || c === 10 || c === 13 || c === 160)) empty = false;
  }
  if (empty) return { ok: true, value: null, error: rules.required === true ? FIELD.required : null };
  const clean = cleanNumberText(text);
  if (clean === null) return { ok: false, error: numberFieldRangeMessage(rules) };
  const typed = Number(clean);
  if (!(typed === typed) || typed === Infinity || typed === -Infinity) return { ok: false, error: numberFieldRangeMessage(rules) };
  const value = rules.format === 'percent' ? percentToFraction(clean) : typed === 0 ? 0 : typed;
  if (rules.integer === true && Math.floor(typed) !== typed) return { ok: false, error: numberFieldRangeMessage(rules) };
  if ((rules.min !== undefined && value < rules.min) || (rules.max !== undefined && value > rules.max)) {
    return { ok: false, error: numberFieldRangeMessage(rules) };
  }
  return { ok: true, value, error: null };
}

/** The text of a number field at rest. */
export function numberFieldText(value: number | null | undefined, rules: NumberFieldRules = {}): string {
  if (value === null || value === undefined || !(value === value)) return '';
  const x = shown(value, rules);
  if (rules.integer === true) return fmtNumber(x, 0);
  if (rules.decimals !== undefined) return fmtNumber(x, rules.decimals);
  return fmtPlain(x, 6);
}

/**
 * Arrow Up and Down: the value one step further, or null when that would leave the range (the
 * field then stays as it is). The default step is 1 as shown: one percent in a percent field.
 */
export function stepNumber(current: number | null, direction: 1 | -1, rules: NumberFieldRules = {}): number | null {
  const step = rules.step !== undefined && rules.step > 0 ? rules.step : rules.format === 'percent' ? 0.01 : 1;
  const from = current === null ? (rules.min !== undefined ? rules.min : 0) : current;
  const next = current === null && direction === 1 && rules.min !== undefined ? rules.min : roundHalfAway(from + direction * step, 9);
  if (rules.min !== undefined && next < rules.min) return null;
  if (rules.max !== undefined && next > rules.max) return null;
  return next;
}

/** The unit of a field in words, for the label a screen reader hears. */
export function unitWords(prefix?: string, suffix?: string): string {
  const unit = suffix !== undefined && suffix !== '' ? suffix : prefix !== undefined ? prefix : '';
  switch (unit) {
    case '$':
      return 'dollars';
    case '%':
      return 'percent';
    case 'mi':
      return 'miles';
    case 'min':
      return 'minutes';
    case 'gal':
      return 'gallons';
    default:
      return unit;
  }
}

// -------------------------------------------------------------------------------------------------
// TimeField
// -------------------------------------------------------------------------------------------------

export interface TimeFieldRules {
  min?: number;
  max?: number;
  /** The time this one comes after (the opening time, for a closing time). */
  after?: number | null;
  allowNextDay?: boolean;
  step?: 5 | 15;
}

export type TimeCommit = { ok: true; value: number | null } | { ok: false; error: string };

function timeBounds(rules: TimeFieldRules): { lo: number; hi: number } {
  return {
    lo: rules.min === undefined ? 0 : rules.min,
    hi: rules.max === undefined ? (rules.allowNextDay === true ? 2880 : 1440) : rules.max,
  };
}

/**
 * What a time field does with its text. The field shows its value through fmtClock, so the text
 * may still end in "(next day)": that marker is honoured, then the time is read by parseClock.
 * Empty commits null. Text that is not a time, or a time outside min..max, does not commit.
 */
export function commitTime(text: string, rules: TimeFieldRules = {}): TimeCommit {
  let body = text;
  let marked = false;
  const open = body.lastIndexOf('(');
  if (open >= 0 && body.indexOf(')', open) >= 0) {
    const inside = body.slice(open + 1, body.indexOf(')', open));
    marked = /^\s*next\s+day\s*$/i.test(inside);
    body = body.slice(0, open);
  }
  let empty = true;
  for (let i = 0; i < body.length; i++) {
    const c = body.charCodeAt(i);
    if (!(c === 32 || c === 9 || c === 10 || c === 13 || c === 160)) empty = false;
  }
  if (empty && !marked) return { ok: true, value: null };
  let t = marked ? parseClock(body) : parseClock(body, { after: rules.after, allowNextDay: rules.allowNextDay });
  if (t === null) return { ok: false, error: FIELD.timeUnreadable };
  if (marked && t < 1440) t += 1440;
  const { lo, hi } = timeBounds(rules);
  if (t < lo || t > hi) return { ok: false, error: timeRangeMessage(fmtClock(lo), fmtClock(hi)) };
  return { ok: true, value: t };
}

/** The minus and plus buttons: one step earlier or later, or null when that would leave the range. */
export function stepTime(current: number | null, direction: 1 | -1, rules: TimeFieldRules = {}): number | null {
  const step = rules.step === undefined ? 15 : rules.step;
  const { lo, hi } = timeBounds(rules);
  let next: number;
  if (current === null) {
    // an empty field starts just after the time before it, or at 11 AM
    next = rules.after !== null && rules.after !== undefined ? rules.after + step : 660;
    if (next < lo) next = lo;
  } else {
    next = current + direction * step;
  }
  if (next < lo || next > hi) return null;
  return next;
}

// -------------------------------------------------------------------------------------------------
// DataTable
// -------------------------------------------------------------------------------------------------

function compareValues(a: number | string, b: number | string): number {
  const an = typeof a === 'number';
  const bn = typeof b === 'number';
  if (an && bn) {
    const x = a as number;
    const y = b as number;
    // a missing number (NaN) sorts last whatever the direction of the others
    if (x !== x || y !== y) return x !== x && y !== y ? 0 : x !== x ? 1 : -1;
    return x < y ? -1 : x > y ? 1 : 0;
  }
  if (an !== bn) return an ? -1 : 1; // numbers before text
  return a < b ? -1 : a > b ? 1 : 0; // byte-wise: no locale
}

/**
 * Rows in the order of one column. Numbers sort numerically, strings byte-wise, ties by row key
 * (always ascending, so the order is the same whichever way the column is turned).
 */
export function sortRows<T>(
  rows: readonly T[],
  sortValue: (row: T) => number | string,
  dir: 'asc' | 'desc',
  rowKey: (row: T) => string,
): T[] {
  const keyed = rows.map((row) => ({ row, value: sortValue(row), key: rowKey(row) }));
  keyed.sort((a, b) => {
    const nanA = typeof a.value === 'number' && a.value !== a.value;
    const nanB = typeof b.value === 'number' && b.value !== b.value;
    let c = compareValues(a.value, b.value);
    if (dir === 'desc' && !nanA && !nanB) c = -c;
    if (c !== 0) return c;
    return a.key < b.key ? -1 : a.key > b.key ? 1 : 0;
  });
  return keyed.map((k) => k.row);
}

/** The direction a header click asks for: a new column starts ascending, the same one turns round. */
export function nextSort(current: { key: string; dir: 'asc' | 'desc' } | undefined, key: string): { key: string; dir: 'asc' | 'desc' } {
  if (current !== undefined && current.key === key) return { key, dir: current.dir === 'asc' ? 'desc' : 'asc' };
  return { key, dir: 'asc' };
}

// -------------------------------------------------------------------------------------------------
// Tabs
// -------------------------------------------------------------------------------------------------

function slug(text: string): string {
  let out = '';
  for (let i = 0; i < text.length; i++) {
    const c = text.charCodeAt(i);
    if ((c >= 48 && c <= 57) || (c >= 97 && c <= 122)) out += text[i];
    else if (c >= 65 && c <= 90) out += String.fromCharCode(c + 32);
    else if (out.length > 0 && out[out.length - 1] !== '-') out += '-';
  }
  return out;
}

/** The DOM ids of a tab and of the panel it controls, from the label of the tab list and the tab's id. */
export function tabDomIds(ariaLabel: string, tabId: string): { tab: string; panel: string } {
  const base = 'tp-' + slug(ariaLabel) + '-' + slug(tabId);
  return { tab: base + '-tab', panel: base + '-panel' };
}

/** Which tab a key moves to: Left and Right wrap, Home and End jump. Null for any other key. */
export function tabKeyTarget(key: string, index: number, count: number): number | null {
  if (count <= 0) return null;
  if (key === 'ArrowRight') return modFloor(index + 1, count);
  if (key === 'ArrowLeft') return modFloor(index - 1, count);
  if (key === 'Home') return 0;
  if (key === 'End') return count - 1;
  return null;
}

// -------------------------------------------------------------------------------------------------
// Charts
// -------------------------------------------------------------------------------------------------

/** Gridline values of a bar chart: thirds when they are whole, else halves, else the top alone. */
export function yTicks(yMax: number): number[] {
  if (!(yMax > 0)) return [];
  if (Math.floor(yMax / 3) === yMax / 3) return [yMax / 3, (2 * yMax) / 3, yMax];
  if (Math.floor(yMax / 2) === yMax / 2) return [yMax / 2, yMax];
  return [yMax];
}

/** The height of a bar as a share of the chart, 0..1; a value above the top is cut at the top. */
export function barShare(value: number, yMax: number): number {
  if (!(yMax > 0) || !(value > 0)) return 0;
  const t = value / yMax;
  return t > 1 ? 1 : t;
}

/** The colour byte of a week-strip cell: the map's own scale. */
export function stripByte(value: number, yMax: number): number {
  return yMax > 0 ? scoreByte(value, yMax) : 0;
}

export interface StripRun {
  /** Row 0 = Monday. */
  dow: number;
  /** First hour of the run in that row, and how many hours it covers there. */
  hour: number;
  hours: number;
  /** True for the run that holds the first hour of the window: it carries the number. */
  first: boolean;
}

/**
 * A window of the week strip as runs per day row. A window that passes midnight continues on the
 * next row, and one that passes Sunday night wraps to Monday.
 */
export function stripRuns(how: number, hours: number): StripRun[] {
  const out: StripRun[] = [];
  let at = modFloor(how, 168);
  let left = hours > 168 ? 168 : hours;
  let first = true;
  while (left > 0) {
    const hour = at % 24;
    const dow = (at - hour) / 24;
    const take = 24 - hour < left ? 24 - hour : left;
    out.push({ dow, hour, hours: take, first });
    first = false;
    left -= take;
    at = modFloor(at + take, 168);
  }
  return out;
}

/** Where an arrow key moves the cursor of the week strip; rows and columns wrap. Null for other keys. */
export function stripKeyTarget(key: string, how: number): number | null {
  const at = modFloor(how, 168);
  const hour = at % 24;
  const dow = (at - hour) / 24;
  if (key === 'ArrowRight') return dow * 24 + modFloor(hour + 1, 24);
  if (key === 'ArrowLeft') return dow * 24 + modFloor(hour - 1, 24);
  if (key === 'ArrowDown') return modFloor(dow + 1, 7) * 24 + hour;
  if (key === 'ArrowUp') return modFloor(dow - 1, 7) * 24 + hour;
  if (key === 'Home') return dow * 24;
  if (key === 'End') return dow * 24 + 23;
  return null;
}

export interface LabelBox {
  /** Left and right edge of the label's text, in the units of the bar. */
  from: number;
  to: number;
}

/**
 * Which clock labels fit under a timeline bar. Labels are tried in the order given by `first` (the
 * indexes to place before the rest, in that order), then the others from left to right; a label is
 * dropped when its text would come closer than `gap` to one already kept.
 */
export function keepLabels(boxes: readonly LabelBox[], gap: number, first: readonly number[] = []): boolean[] {
  const keep: boolean[] = boxes.map(() => false);
  const tried: boolean[] = boxes.map(() => false);
  const kept: LabelBox[] = [];
  const tryKeep = (i: number) => {
    if (i < 0 || i >= boxes.length || tried[i]) return;
    tried[i] = true;
    for (const k of kept) if (boxes[i].from < k.to + gap && boxes[i].to > k.from - gap) return;
    keep[i] = true;
    kept.push(boxes[i]);
  };
  for (const i of first) tryKeep(i);
  for (let i = 0; i < boxes.length; i++) tryKeep(i);
  return keep;
}

/** The timeline bar is drawn in a view box this wide, with this much room at each end. */
export const TIMELINE_BAR_WIDTH = 720;
export const TIMELINE_BAR_PAD = 10;

/** Where a minute of the day sits along the timeline bar, in the units of its view box. */
export function timelineBarX(start: number, end: number): (minute: number) => number {
  const span = end > start ? end - start : 1;
  return (minute: number) => TIMELINE_BAR_PAD + ((minute - start) / span) * (TIMELINE_BAR_WIDTH - 2 * TIMELINE_BAR_PAD);
}

/**
 * Which marks of a timeline bar get their clock label. The first label starts at its mark, the
 * last one ends at it and the others are centred on theirs; a label takes about 6.2 units a
 * character. Opening and closing times are placed first, so it is the start or the end of the day
 * that gives way when two labels would come closer than 8 units.
 */
export function timelineLabelKeep(marks: readonly { minute: number; kind: 'stop' | 'day' }[], xOf: (minute: number) => number): boolean[] {
  const boxes: LabelBox[] = [];
  const first: number[] = [];
  for (let i = 0; i < marks.length; i++) {
    const width = fmtClockShort(marks[i].minute).length * 6.2;
    const x = xOf(marks[i].minute);
    if (i === 0) boxes.push({ from: x, to: x + width });
    else if (i === marks.length - 1) boxes.push({ from: x - width, to: x });
    else boxes.push({ from: x - width / 2, to: x + width / 2 });
    if (marks[i].kind === 'stop') first.push(i);
  }
  return keepLabels(boxes, 8, first);
}

// -------------------------------------------------------------------------------------------------
// WeatherChip and HolidayChip
// -------------------------------------------------------------------------------------------------

export interface WeatherSummary {
  /** False when no hour has a usable forecast: the chip then reads "No forecast yet". */
  usable: boolean;
  /** "55 to 62°F", "62°F", or null when no hour gives a temperature. */
  temperature: string | null;
  /** The worst precipitation class among the hours, as the model classifies them. */
  precipClass: string;
  /** "Rain", "Storms", "Snow", "Dry". */
  word: string;
  /** "40%", or null for a dry class and when the forecast gives no chance. */
  chance: string | null;
  /** True when the class is not dry and none of its hours carries a chance. */
  noChance: boolean;
  icon: 'sun' | 'rain' | 'wind' | 'thermometer';
  /** The chip text: "55 to 62°F · Rain 40%". */
  text: string;
}

/**
 * The forecast of a stretch of one date in a chip: the temperature range, then the worst
 * precipitation class with the highest chance the forecast gives for it. Hours run from `fromHour`
 * up to but not including `toHour`; an empty stretch is the single hour `fromHour`.
 */
export function weatherSummary(
  A: Assumptions,
  forecast: readonly (HourForecast | null)[] | null,
  fromHour: number,
  toHour: number,
): WeatherSummary {
  const none: WeatherSummary = { usable: false, temperature: null, precipClass: 'dry', word: precipClassWord('dry'), chance: null, noChance: false, icon: 'sun', text: WEATHER.none };
  if (forecast === null) return none;
  const first = fromHour < 0 ? 0 : Math.floor(fromHour);
  let last = Math.floor(toHour) - 1;
  if (last < first) last = first;
  if (last > 23) last = 23;

  const order = seed<string[]>(A, 'weather.precip_classes.order');
  let tempLo: number | null = null;
  let tempHi: number | null = null;
  let worst: string | null = null;
  let worstPull = 0;
  let windy = false;
  let harsh = false;
  let any = false;
  const chanceByClass: Record<string, number | null> = {};

  for (let h = first; h <= last; h++) {
    const fc = forecast[h];
    if (fc === null || fc === undefined) continue;
    const detail = weatherMultiplier(A, fc, 'open');
    if (detail.missing) continue;
    any = true;
    if (fc.temp_f !== null) {
      if (tempLo === null || fc.temp_f < tempLo) tempLo = fc.temp_f;
      if (tempHi === null || fc.temp_f > tempHi) tempHi = fc.temp_f;
    }
    if (detail.temp < 1) harsh = true;
    if (detail.wind_band !== null && detail.wind < 1) windy = true;
    const cls = detail.precip_class === null ? 'dry' : detail.precip_class;
    const pull = seed<number>(A, 'weather.precip_classes.rows.' + cls + '.open');
    const better = worst === null || qkey(pull) < qkey(worstPull) || (qkey(pull) === qkey(worstPull) && order.indexOf(cls) < order.indexOf(worst));
    if (better) {
      worst = cls;
      worstPull = pull;
    }
    if (!Object.prototype.hasOwnProperty.call(chanceByClass, cls)) chanceByClass[cls] = null;
    if (fc.precip_prob !== null) {
      const known = chanceByClass[cls];
      if (known === null || fc.precip_prob > known) chanceByClass[cls] = fc.precip_prob;
    }
  }
  if (!any || worst === null) return none;

  let temperature: string | null = null;
  if (tempLo !== null && tempHi !== null) {
    temperature = roundHalfAway(tempLo, 0) === roundHalfAway(tempHi, 0) ? fmtTemp(tempHi) : fmtCount(tempLo) + ' to ' + fmtTemp(tempHi);
  }
  const word = precipClassWord(worst);
  const dry = worst === 'dry';
  const best = chanceByClass[worst];
  const chance = dry || best === null || best === undefined ? null : fmtPercent(best / 100);
  const noChance = !dry && chance === null;
  const wet = word + (chance === null ? '' : ' ' + chance);
  return {
    usable: true,
    temperature,
    precipClass: worst,
    word,
    chance,
    noChance,
    icon: !dry ? 'rain' : windy ? 'wind' : harsh ? 'thermometer' : 'sun',
    text: temperature === null ? wet : temperature + ' · ' + wet,
  };
}

/** The title of a weather chip: whose forecast it is, how old, and what it leaves out. */
export function weatherTitle(summary: WeatherSummary, stale: { minute: number } | null): string {
  let title: string = WEATHER.title;
  if (stale !== null) title += ' from ' + fmtClock(stale.minute) + WEATHER.staleTail;
  if (summary.noChance) title += '. ' + WEATHER.noChance;
  return title;
}

const DOW_KEYS: readonly string[] = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

/**
 * What the holiday chip says for a day, or null on an ordinary day: the holiday's name, "Treated
 * as a Saturday" when the owner picked a weekday, "Holiday ignored" when the owner switched a
 * holiday off.
 */
export function holidayChipText(ctx: DayContext): string | null {
  const treat = ctx.treat_as;
  if (treat !== null) {
    const dow = DOW_KEYS.indexOf(treat);
    if (dow >= 0) return treatedAsDay(fmtWeekday(dow, 'long'));
    if (treat === 'normal') return ctx.holiday === null ? null : HOLIDAY_CHIP.ignored;
    if (treat === 'holiday') return ctx.holiday === null ? HOLIDAY_CHIP.byChoice : ctx.holiday.name;
  }
  return ctx.holiday === null ? null : ctx.holiday.name;
}
