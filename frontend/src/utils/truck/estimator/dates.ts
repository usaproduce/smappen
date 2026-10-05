// Truck Planner estimator - dates, federal holidays, day context (02_MODEL 4.1).
//
// Civil dates are computed by hand on day numbers (days since 1970-01-01, proleptic Gregorian). Nothing
// here reads a clock or a time zone: "today" is always an input chosen by the caller.

import { DOW_KEYS, ModelError, NSEG, floorDiv, hasOwn, modFloor } from './core';
import { SEEDS, dowFactors, holidayDayType } from './seeds';
import type {
  Assumptions,
  CivilDate,
  DayContext,
  DayType,
  FuelPriceSource,
  Holiday,
  HolidayClass,
  HourForecast,
  TreatAs,
} from './types';

/** Days since 1970-01-01 of a civil date. Integers only. */
export function daysFromCivil(y: number, m: number, d: number): number {
  const y2 = m <= 2 ? y - 1 : y;
  const era = floorDiv(y2, 400);
  const yoe = y2 - era * 400;
  const mp = modFloor(m + 9, 12); // March = 0 ... February = 11
  const doy = floorDiv(153 * mp + 2, 5) + d - 1;
  const doe = yoe * 365 + floorDiv(yoe, 4) - floorDiv(yoe, 100) + doy;
  return era * 146097 + doe - 719468;
}

/** The inverse of daysFromCivil: [year, month, day]. */
export function civilFromDays(z: number): CivilDate {
  const z2 = z + 719468;
  const era = floorDiv(z2, 146097);
  const doe = z2 - era * 146097;
  const yoe = floorDiv(doe - floorDiv(doe, 1460) + floorDiv(doe, 36524) - floorDiv(doe, 146096), 365);
  const y = yoe + era * 400;
  const doy = doe - (365 * yoe + floorDiv(yoe, 4) - floorDiv(yoe, 100));
  const mp = floorDiv(5 * doy + 2, 153);
  const d = doy - floorDiv(153 * mp + 2, 5) + 1;
  const m = mp < 10 ? mp + 3 : mp - 9;
  return [m <= 2 ? y + 1 : y, m, d];
}

const DIGIT_POSITIONS: readonly number[] = [0, 1, 2, 3, 5, 6, 8, 9];

function digitsAt(s: string, from: number, to: number): number {
  let n = 0;
  for (let i = from; i < to; i++) n = n * 10 + (s.charCodeAt(i) - 48);
  return n;
}

/**
 * Parse exactly "YYYY-MM-DD" in ASCII digits. Raises invalid_date unless it is a real date with
 * 1970 <= year <= 2199.
 */
export function parseDate(s: string): CivilDate {
  let ok = typeof s === 'string' && s.length === 10 && s.charCodeAt(4) === 45 && s.charCodeAt(7) === 45;
  if (ok) {
    for (let k = 0; k < DIGIT_POSITIONS.length; k++) {
      const c = s.charCodeAt(DIGIT_POSITIONS[k]);
      if (c < 48 || c > 57) ok = false;
    }
  }
  if (!ok) throw new ModelError('invalid_date');
  const y = digitsAt(s, 0, 4);
  const m = digitsAt(s, 5, 7);
  const d = digitsAt(s, 8, 10);
  if (y < 1970 || y > 2199) throw new ModelError('invalid_date');
  const back = civilFromDays(daysFromCivil(y, m, d));
  if (back[0] !== y || back[1] !== m || back[2] !== d) throw new ModelError('invalid_date');
  return [y, m, d];
}

// Zero-padded decimal of an integer to at least `width` characters, the sign counted in the width.
function zeroPad(n: number, width: number): string {
  const negative = n < 0;
  let digits = String(negative ? -n : n);
  const room = negative ? width - 1 : width;
  while (digits.length < room) digits = '0' + digits;
  return negative ? '-' + digits : digits;
}

/** Zero-padded "YYYY-MM-DD". */
export function formatDate(y: number, m: number, d: number): string {
  return zeroPad(y, 4) + '-' + zeroPad(m, 2) + '-' + zeroPad(d, 2);
}

/** Day number of a date string (parses it, so it raises invalid_date). */
export function dayNumber(date: string): number {
  const ymd = parseDate(date);
  return daysFromCivil(ymd[0], ymd[1], ymd[2]);
}

/** Date string of a day number (no range check: holidays are computed for any year). */
export function dateOfDay(z: number): string {
  const ymd = civilFromDays(z);
  return formatDate(ymd[0], ymd[1], ymd[2]);
}

/** 0 = Monday ... 6 = Sunday. The only source of a day of the week in the model. */
export function dayOfWeek(date: string): number {
  return modFloor(dayNumber(date) + 3, 7);
}

/** The date n days after (or, for a negative n, before) a date. */
export function addDays(date: string, n: number): string {
  return dateOfDay(dayNumber(date) + n);
}

// Federal holidays ----------------------------------------------------------------------------------

function nthWeekdayDay(year: number, month: number, dow: number, n: number): number {
  const first = daysFromCivil(year, month, 1);
  return first + modFloor(dow - modFloor(first + 3, 7), 7) + 7 * (n - 1);
}

function lastWeekdayDay(year: number, month: number, dow: number): number {
  const nextFirst = month === 12 ? daysFromCivil(year + 1, 1, 1) : daysFromCivil(year, month + 1, 1);
  const last = nextFirst - 1;
  return last - modFloor(modFloor(last + 3, 7) - dow, 7);
}

/** The n-th (1-based) given weekday of a month, as a date. */
export function nthWeekday(year: number, month: number, dow: number, n: number): string {
  return dateOfDay(nthWeekdayDay(year, month, dow, n));
}

/** The last given weekday of a month, as a date. */
export function lastWeekday(year: number, month: number, dow: number): string {
  return dateOfDay(lastWeekdayDay(year, month, dow));
}

/**
 * Federal holidays whose actual date is in `year`, sorted by (date, rule order). Works on day numbers,
 * so it accepts any year (holidayOn asks for the year after the date's).
 */
export function federalHolidays(year: number, flags: Record<string, boolean>): Holiday[] {
  const rules = SEEDS.holidays.rules;
  const found: { day: number; order: number; holiday: Holiday }[] = [];
  for (let k = 0; k < rules.length; k++) {
    const rule = rules[k];
    const order = k + 1; // rule order 1..12 = position in the file
    if (rule.from_year != null && year < rule.from_year) continue;
    if (rule.region_flag != null && !(hasOwn(flags, rule.region_flag) && flags[rule.region_flag] === true)) continue;
    let day: number;
    let observed: number | null;
    if (rule.rule === 'fixed') {
      day = daysFromCivil(year, rule.month, rule.day as number);
      const dow = modFloor(day + 3, 7);
      observed = dow === 5 ? day - 1 : dow === 6 ? day + 1 : day;
    } else if (rule.rule === 'nth_weekday') {
      day = nthWeekdayDay(year, rule.month, rule.dow as number, rule.n as number);
      observed = day;
    } else if (rule.rule === 'last_weekday') {
      day = lastWeekdayDay(year, rule.month, rule.dow as number);
      observed = day;
    } else {
      // "inauguration"
      if (year < 1969 || modFloor(year - 1965, 4) !== 0) continue;
      day = daysFromCivil(year, rule.month, rule.day as number);
      const dow = modFloor(day + 3, 7);
      observed = dow === 6 ? day + 1 : dow === 5 ? null : day; // no day in lieu of a Saturday
    }
    found.push({
      day,
      order,
      holiday: {
        id: rule.id,
        name: rule.name,
        class: rule.class,
        date: dateOfDay(day),
        observed: observed === null ? null : dateOfDay(observed),
      },
    });
  }
  found.sort((a, b) => (a.day !== b.day ? a.day - b.day : a.order - b.order));
  const out: Holiday[] = [];
  for (let k = 0; k < found.length; k++) out.push(found[k].holiday);
  return out;
}

function ruleOrder(holidayId: string): number {
  const rules = SEEDS.holidays.rules;
  for (let k = 0; k < rules.length; k++) {
    if (rules[k].id === holidayId) return k + 1;
  }
  return rules.length + 1;
}

/**
 * The federal holiday observed on `date`, or null. New Year's Day can be observed on 31 December of
 * the year before, so two years are searched. Major beats minor, then the earlier rule.
 */
export function holidayOn(date: string, flags: Record<string, boolean>): Holiday | null {
  const y = parseDate(date)[0];
  let best: Holiday | null = null;
  let bestClass = 0;
  let bestOrder = 0;
  for (let year = y; year <= y + 1; year++) {
    const list = federalHolidays(year, flags);
    for (let k = 0; k < list.length; k++) {
      const h = list[k];
      if (h.observed !== date) continue;
      const cls = h.class === 'major' ? 0 : 1;
      const order = ruleOrder(h.id);
      if (best === null || cls < bestClass || (cls === bestClass && order < bestOrder)) {
        best = h;
        bestClass = cls;
        bestOrder = order;
      }
    }
  }
  return best;
}

// Day context ---------------------------------------------------------------------------------------

/**
 * Internal: assemble a DayContext. Day types and Monday-Friday factors per segment follow the effective
 * day of the week and the holiday class; the holiday record is kept even when treat_as suppresses it.
 */
export function makeContext(
  A: Assumptions,
  date: string | null,
  dow: number,
  effDow: number,
  cls: HolidayClass | null,
  hol: Holiday | null,
  treatAs: TreatAs | null,
  forecast: (HourForecast | null)[] | null,
  fuelPricePerGal: number | null,
  fuelPriceSource: FuelPriceSource | null,
  typical: boolean,
): DayContext {
  const baseType: DayType = effDow <= 4 ? 'weekday' : effDow === 5 ? 'saturday' : 'sunday';
  const dayType: DayType[] = [];
  const dowFactor: number[] = [];
  for (let s = 0; s < NSEG; s++) {
    let t: DayType = baseType;
    if (cls != null) {
      t = holidayDayType(A, s, cls); // seed "segments.<s>.holiday_day_type.<class>"
      if (t === 'weekday') t = baseType;
    }
    dayType.push(t);
    dowFactor.push(t === 'weekday' ? dowFactors(A, s)[effDow] : 1.0); // seed "segments.<s>.dow_factor"
  }
  return {
    date,
    typical,
    dow,
    eff_dow: effDow,
    holiday: hol,
    holiday_class: cls,
    treat_as: treatAs,
    day_type: dayType,
    dow_factor: dowFactor,
    traffic_dow: cls === 'major' ? 6 : effDow,
    forecast,
    fuel_price_per_gal: fuelPricePerGal,
    fuel_price_source: fuelPriceSource,
  };
}

/**
 * The context of one civil date: day types and Monday-Friday factors per segment, the holiday, the
 * traffic row, and the forecast and fuel price handed through unchanged. Every argument is required;
 * null is accepted for the last four. treatAs: null = automatic, "normal" = ignore a holiday,
 * "holiday" = treat as a major holiday, a day key = behave like that day of the week.
 */
export function dayContext(
  A: Assumptions,
  date: string,
  treatAs: TreatAs | null,
  forecast: (HourForecast | null)[] | null,
  fuelPricePerGal: number | null,
  fuelPriceSource: FuelPriceSource | null,
): DayContext {
  const dow = dayOfWeek(date);
  const hol = holidayOn(date, A.region.flags);
  let effDow = dow;
  let cls: HolidayClass | null = hol !== null ? hol.class : null;
  const asDow = treatAs == null ? -1 : (DOW_KEYS as readonly string[]).indexOf(treatAs);
  if (asDow >= 0) {
    effDow = asDow; // behave like that day of the week
    cls = null;
  } else if (treatAs === 'holiday') {
    cls = 'major';
  } else if (treatAs === 'normal') {
    cls = null;
  }
  return makeContext(A, date, dow, effDow, cls, hol, treatAs, forecast, fuelPricePerGal, fuelPriceSource, false);
}

/** A day of a typical week: no date, no holiday, no weather, no fuel price. */
export function typicalContext(A: Assumptions, dow: number): DayContext {
  return makeContext(A, null, dow, dow, null, null, null, null, null, null, true);
}
