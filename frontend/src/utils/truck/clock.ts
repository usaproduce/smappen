// Truck Planner - the clock (docs/truck-planner/05_FRONTEND.md 2.7).
//
// The model never reads a clock, but the screens have to know "today" and "now" in the truck's time
// zone. This is the ONE file of Truck Planner that may use Date and Intl (the determinism guard
// enforces it). It never uses the device's time zone, an ISO string or a weekday getter: the only
// thing taken from the device is the epoch, and the server corrects even that (clockSkewMinutes).
//
// Everything works in whole minutes. "Wall minutes" below means minutes since 1970-01-01 00:00 of a
// wall-clock reading (a civil date plus a minute of day), in whichever zone the reading was taken.

import { addDays, dayNumber, floorDiv } from './model';

const MS_PER_MINUTE = 60000;
const MINUTES_PER_DAY = 1440;

/** The device's epoch in milliseconds. The only clock read in Truck Planner. */
export function nowEpochMs(): number {
  return Date.now();
}

const formatters = new Map<string, Intl.DateTimeFormat>();

function formatterFor(timeZone: string): Intl.DateTimeFormat {
  let f = formatters.get(timeZone);
  if (f === undefined) {
    f = new Intl.DateTimeFormat('en-US', {
      timeZone,
      hourCycle: 'h23',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
    });
    formatters.set(timeZone, f);
  }
  return f;
}

/** The civil date and the minute of day that a clock in `timeZone` shows at the instant `epochMs`. */
export function regionNow(timeZone: string, epochMs: number): { date: string; minute: number } {
  const parts = formatterFor(timeZone).formatToParts(new Date(epochMs));
  let year = '';
  let month = '';
  let day = '';
  let hour = 0;
  let minute = 0;
  for (const part of parts) {
    if (part.type === 'year') year = part.value;
    else if (part.type === 'month') month = part.value;
    else if (part.type === 'day') day = part.value;
    else if (part.type === 'hour') hour = Number(part.value);
    else if (part.type === 'minute') minute = Number(part.value);
  }
  if (hour === 24) hour = 0; // some engines print midnight as 24 even with the h23 cycle
  return { date: year + '-' + month + '-' + day, minute: hour * 60 + minute };
}

/** Minutes since 1970-01-01 00:00 of a wall-clock reading. */
function wallMinutes(reading: { date: string; minute: number }): number {
  return dayNumber(reading.date) * MINUTES_PER_DAY + reading.minute;
}

/**
 * How far the device's clock is from the server's, in whole minutes (positive: the device is behind).
 *
 * `serverToday` and `serverNowMinute` are `today` and `now_minute` of a bootstrap answer; `epochMs`
 * is the device's epoch at the moment the answer arrived. A difference of one minute or less is
 * treated as zero (it is transit time and rounding, not a wrong clock).
 */
export function clockSkewMinutes(
  serverToday: string,
  serverNowMinute: number,
  timeZone: string,
  epochMs: number,
): number {
  const server = wallMinutes({ date: serverToday, minute: serverNowMinute });
  const device = wallMinutes(regionNow(timeZone, epochMs));
  const skew = server - device;
  return skew >= -1 && skew <= 1 ? 0 : skew;
}

function two(n: number): string {
  return n < 10 ? '0' + n : String(n);
}

/** `YYYYMMDDTHHMMSSZ` for an instant, by integer arithmetic on the epoch (no Date getters). */
export function utcStamp(epochMs: number): string {
  const totalSeconds = floorDiv(epochMs, 1000);
  const days = floorDiv(totalSeconds, 86400);
  const secondOfDay = totalSeconds - days * 86400;
  const hour = floorDiv(secondOfDay, 3600);
  const minute = floorDiv(secondOfDay - hour * 3600, 60);
  const second = secondOfDay - hour * 3600 - minute * 60;
  const date = addDays('1970-01-01', days); // YYYY-MM-DD
  return date.slice(0, 4) + date.slice(5, 7) + date.slice(8, 10) + 'T' + two(hour) + two(minute) + two(second) + 'Z';
}

/**
 * The UTC instant, as `YYYYMMDDTHHMMSSZ`, whose wall-clock reading in `timeZone` is `date` plus
 * `minute`. Minutes below 0 or from 1440 belong to the neighbouring civil date.
 *
 * Method: take the reading as if it were UTC, correct by the difference `regionNow` reports, and
 * repeat once (the offset at the corrected instant may differ from the offset at the first guess).
 * A wall time that occurs twice (the hour repeated when daylight time ends) takes the earlier
 * instant. A wall time that does not occur (the hour skipped when daylight time starts) is read with
 * the offset in force before the change, which is what calendars do with such a time.
 */
export function zonedToUtcStamp(timeZone: string, date: string, minute: number): string {
  const dayShift = floorDiv(minute, MINUTES_PER_DAY);
  const target = (dayNumber(date) + dayShift) * MINUTES_PER_DAY + (minute - dayShift * MINUTES_PER_DAY);

  const readingAt = (epochMinute: number): number => wallMinutes(regionNow(timeZone, epochMinute * MS_PER_MINUTE));

  let guess = target;
  guess += target - readingAt(guess);
  guess += target - readingAt(guess);

  // The same wall time read with the offset in force a day earlier. It is the answer when it is the
  // earlier of two instants with this reading, and when no instant has this reading at all.
  const dayBefore = guess - MINUTES_PER_DAY;
  const earlier = target - (readingAt(dayBefore) - dayBefore);
  const guessHits = readingAt(guess) === target;
  const earlierHits = readingAt(earlier) === target;

  let instant = guess;
  if (earlierHits && earlier <= guess) instant = earlier;
  else if (!guessHits) instant = earlier;

  return utcStamp(instant * MS_PER_MINUTE);
}
