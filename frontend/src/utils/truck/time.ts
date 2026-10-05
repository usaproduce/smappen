// Truck Planner - hours of the week and week dates (docs/truck-planner/05_FRONTEND.md 3.1).
//
// Pure helpers on the model's own calendar: how = dow * 24 + hour with dow 0 = Monday, dates are
// "YYYY-MM-DD" civil dates. Nothing here reads a clock; "today" is always an argument.

import { HOURS_PER_WEEK, addDays, dayOfWeek, modFloor } from './model';

/** The hour of the week of a day (0 = Monday) and a clock hour. Both wrap. */
export function howOf(dow: number, hour: number): number {
  return modFloor(dow, 7) * 24 + modFloor(hour, 24);
}

/** The day (0 = Monday) and clock hour of an hour of the week; any integer wraps into 0..167. */
export function howParts(how: number): { dow: number; hour: number } {
  const h = modFloor(how, HOURS_PER_WEEK);
  const hour = h % 24;
  return { dow: (h - hour) / 24, hour };
}

/** The Monday of the week that holds `date`. */
export function mondayOf(date: string): string {
  return addDays(date, -dayOfWeek(date));
}

/** The seven dates of the week that starts on `weekStart`, in order. */
export function weekDates(weekStart: string): string[] {
  const out: string[] = [];
  for (let i = 0; i < 7; i++) out.push(addDays(weekStart, i));
  return out;
}

/** The first date from `today` on that falls on weekday `dow` (0 = Monday); today itself counts. */
export function nextDateWithDow(today: string, dow: number): string {
  return addDays(today, modFloor(dow - dayOfWeek(today), 7));
}
