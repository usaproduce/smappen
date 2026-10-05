// Truck Planner - the sentences of the planner warnings (docs/truck-planner/05_FRONTEND.md 6.5).
//
// The model decides which warnings a day carries and in which order (02_MODEL 4.12); this module
// only words them. Numbers are read from Warning.data, whose keys the model fixes, and the holiday
// name from the day's context. An unknown code prints a fallback sentence and is logged once, so a
// new model warning never crashes the page.

import type { DayContext, DayResult, Warning, WarningLevel } from './model';
import { fmtClock, fmtDuration, fmtPercent, fmtPlain } from './format';
import {
  WARNING_HOLIDAY_BY_CHOICE,
  WARNING_PREFIX,
  WARNING_TEXT,
  WARNING_UNKNOWN,
  stopFallbackName,
} from './wording';

/** A warning as it arrives: one of the 21 known codes, or a code a later model version added. */
export type WarningLike =
  | Warning
  | { code: string; level: WarningLevel; stop_index: number | null; data: Record<string, unknown> };

const loggedUnknown: Record<string, true> = {};

function num(data: unknown, key: string): number | null {
  if (data === null || typeof data !== 'object') return null;
  const v = (data as Record<string, unknown>)[key];
  return typeof v === 'number' && v === v ? v : null;
}

function fill(template: string, values: Record<string, string>): string {
  let out = template;
  for (const key of Object.keys(values)) {
    out = out.split('{' + key + '}').join(values[key]);
  }
  return out;
}

/** The name of the stop a warning is about; a stop without a name reads "Stop 2". */
export function warningStopName(w: WarningLike, stopNames: readonly string[]): string {
  if (w.stop_index === null || w.stop_index === undefined) return '';
  const name = stopNames[w.stop_index];
  return typeof name === 'string' && name !== '' ? name : stopFallbackName(w.stop_index);
}

/**
 * The sentence of one warning. `result` is the day the warning belongs to and `ctx` the context of
 * its date; `stopNames[i]` names stop i of the plan.
 */
export function warningText(
  w: WarningLike,
  result: DayResult | null,
  stopNames: readonly string[],
  ctx: DayContext | null,
): string {
  if (!Object.prototype.hasOwnProperty.call(WARNING_TEXT, w.code)) {
    if (loggedUnknown[w.code] !== true) {
      loggedUnknown[w.code] = true;
      console.warn('[truck] unknown warning code', w.code);
    }
    return WARNING_UNKNOWN;
  }
  const code = w.code as Warning['code'];
  const data: unknown = w.data;
  const values: Record<string, string> = { stop: warningStopName(w, stopNames) };

  switch (code) {
    case 'late_arrival': {
      const late = num(data, 'late_minutes');
      values.late_minutes = late === null ? '' : fmtPlain(late, 0);
      values.effective_open = fmtClock(num(data, 'effective_open'));
      break;
    }
    case 'long_gap':
      values.gap = fmtDuration(num(data, 'gap_before_minutes'));
      break;
    case 'long_day': {
      const minutes = num(data, 'day_minutes');
      values.day_length = fmtDuration(minutes === null && result !== null ? result.timeline.day_minutes : minutes);
      break;
    }
    case 'fee_high': {
      const fee = num(data, 'spot_fee');
      const sales = num(data, 'sales');
      values.fee_share = fee !== null && sales !== null && sales > 0 ? fmtPercent(fee / sales) : fmtPercent(null);
      break;
    }
    case 'early_start': {
      const start = num(data, 'start_prep');
      values.start_prep = fmtClock(start === null && result !== null ? result.timeline.start_prep : start);
      break;
    }
    case 'ends_after_midnight': {
      const done = num(data, 'done');
      values.done = fmtClock(done === null && result !== null ? result.timeline.done : done);
      break;
    }
    case 'holiday':
      // The class can come from "Treat this day as: a holiday" on an ordinary date: there is no name then.
      if (ctx === null || ctx.holiday === null) return WARNING_HOLIDAY_BY_CHOICE;
      values.holiday = ctx.holiday.name;
      break;
    default:
      break;
  }
  return fill(WARNING_TEXT[code], values);
}

export interface WarningRow {
  /** Position in the model's list; stable as a React key together with the code. */
  index: number;
  code: string;
  level: WarningLevel;
  /** "Problem:", "Check:" or "Note:". Visible text, so the level never rests on colour. */
  prefix: string;
  text: string;
  stopIndex: number | null;
}

/** The warnings of a day in the model's order, worded. */
export function warningRows(result: DayResult, stopNames: readonly string[], ctx: DayContext | null): WarningRow[] {
  const out: WarningRow[] = [];
  const list: readonly WarningLike[] = result.warnings;
  for (let i = 0; i < list.length; i++) {
    const w = list[i];
    const level: WarningLevel = w.level === 'error' || w.level === 'warn' ? w.level : 'info';
    out.push({
      index: i,
      code: w.code,
      level,
      prefix: WARNING_PREFIX[level],
      text: warningText(w, result, stopNames, ctx),
      stopIndex: w.stop_index === undefined ? null : w.stop_index,
    });
  }
  return out;
}
