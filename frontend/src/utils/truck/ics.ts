// Truck Planner - a day as a calendar file (docs/truck-planner/05_FRONTEND.md 4.10).
//
// Pure: no I/O, no clock, no React. The same input always gives the same bytes; the one moment of
// "now" a calendar file carries (DTSTAMP) is handed in by the caller. Times are the model's own
// minutes of the day, turned into UTC instants with the truck's time zone, so the file needs no
// time-zone block and reads at the right local time in any calendar.
//
// What is written (RFC 5545): one event for the whole day, from the start of prep to done, and one
// event per stop, from the time it really opens to the time it closes. Text values are escaped,
// lines end in CRLF and are folded at 75 octets.

import { zonedToUtcStamp } from './clock';
import { fmtClock, fmtCoord, fmtEstimate, fmtFixed, type EstimateUnit } from './format';
import type { Estimate, Timeline } from './model';
import { timelineRows } from './timelineView';
import { STANDING, confidenceLabel, stopFallbackName } from './wording';

/** One stop of the day as its calendar entry needs it. */
export interface IcsStop {
  /** The stop's id: the same id the timeline's drives carry. */
  id: string;
  name: string;
  /** Empty when the stop has none: the entry then gives the coordinates. */
  address: string;
  point: { lat: number; lng: number };
  /** The stop's orders as the model estimated them. */
  orders: Estimate;
}

export interface DayIcsInput {
  /** The service date, `YYYY-MM-DD`. */
  date: string;
  /** The truck's IANA time zone: every minute of the timeline is a wall time in it. */
  timeZone: string;
  /** The day as the model timed it. */
  timeline: Timeline;
  /** The day's stops in the owner's order. */
  stops: IcsStop[];
  /**
   * The truck's name. Part of the input of 4.10, which gives the content of every property and
   * names the truck in none of them: it is not written to the file.
   */
  truckName: string;
  /** The host the app is served from: the part of every UID after the "@". */
  host: string;
  /** The moment the file is made, `YYYYMMDDTHHMMSSZ` (`utcStamp(nowEpochMs())` at the call site). */
  dtstamp: string;
}

/** The media type of the file. */
export const ICS_MIME = 'text/calendar;charset=utf-8';

/** `truck-day-2026-10-08.ics`. */
export function icsFileName(date: string): string {
  return 'truck-day-' + date + '.ics';
}

/**
 * An estimate with its label in one phrase, for a printed line and for a calendar entry:
 * "60 orders (33 to 93), rough", "$482 ($42 to $1,012), rough", "80 orders, fixed". The range and
 * the label always travel with the value.
 */
export function estimateText(e: Estimate, unit: EstimateUnit): string {
  return fmtEstimate(e, unit) + ', ' + confidenceLabel(e.confidence).toLowerCase();
}

/**
 * A text value: backslash, semicolon and comma are escaped and a line break is written `\n`
 * (a CRLF pair counts as one break). Any other control character but the tab becomes a space.
 */
export function icsText(text: string): string {
  let out = '';
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    const code = text.charCodeAt(i);
    if (c === '\\') out += '\\\\';
    else if (c === ';') out += '\\;';
    else if (c === ',') out += '\\,';
    else if (c === '\r') {
      out += '\\n';
      if (i + 1 < text.length && text[i + 1] === '\n') i++;
    } else if (c === '\n') out += '\\n';
    else if ((code < 32 && code !== 9) || code === 127) out += ' ';
    else out += c;
  }
  return out;
}

const FOLD_OCTETS = 75;

/**
 * One content line folded at 75 octets of UTF-8: a longer line continues after CRLF and one space,
 * which counts toward the 75 of the line it starts. A character is never cut: one that would cross
 * the limit moves to the next line whole.
 */
export function icsFold(line: string): string {
  let out = '';
  let octets = 0;
  let i = 0;
  while (i < line.length) {
    const code = line.charCodeAt(i);
    let units = 1;
    let size = 3;
    if (code < 0x80) size = 1;
    else if (code < 0x800) size = 2;
    else if (code >= 0xd800 && code <= 0xdbff && i + 1 < line.length) {
      const next = line.charCodeAt(i + 1);
      if (next >= 0xdc00 && next <= 0xdfff) {
        size = 4;
        units = 2;
      }
    }
    if (octets + size > FOLD_OCTETS) {
      out += '\r\n ';
      octets = 1;
    }
    out += line.slice(i, i + units);
    octets += size;
    i += units;
  }
  return out;
}

/**
 * The stop of the input that stands at each position of the timeline, or null where there is none.
 * The drive that leads to stop `i` carries that stop's id, which is how the two are matched; a
 * timeline without ids is matched by position when it has as many stops as the input.
 */
function stopsInOrder(timeline: Timeline, stops: readonly IcsStop[]): (IcsStop | null)[] {
  const out: (IcsStop | null)[] = [];
  for (let i = 0; i < timeline.stops.length; i++) {
    const leg = i < timeline.legs.length ? timeline.legs[i] : null;
    const id = leg === null ? null : leg.to_id;
    let found: IcsStop | null = null;
    if (id !== null) {
      for (const stop of stops) {
        if (stop.id === id) {
          found = stop;
          break;
        }
      }
    }
    if (found === null && stops.length === timeline.stops.length) found = stops[i];
    out.push(found);
  }
  return out;
}

/**
 * The calendar file of one day. An empty timeline gives a calendar without events; a stop the
 * input does not hold gets no entry of its own.
 */
export function buildDayIcs(input: DayIcsInput): string {
  const { date, timeZone, timeline } = input;
  const placed = stopsInOrder(timeline, input.stops);
  const names = placed.map((stop, index) => (stop !== null && stop.name.trim() !== '' ? stop.name.trim() : stopFallbackName(index)));
  const at = (minute: number): string => zonedToUtcStamp(timeZone, date, minute);
  const uid = (key: string): string => 'UID:' + icsText('tp-' + date + '-' + key + '@' + input.host);

  const lines: string[] = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//smappen//Truck Planner//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH'];

  if (timeline.start_prep !== null && timeline.done !== null) {
    const rows = timelineRows(timeline, names);
    lines.push(
      'BEGIN:VEVENT',
      uid('day'),
      'DTSTAMP:' + input.dtstamp,
      'DTSTART:' + at(timeline.start_prep),
      'DTEND:' + at(timeline.done),
      'SUMMARY:' + icsText('Truck day: ' + names.join(', ')),
      'DESCRIPTION:' + icsText(rows.map((row) => fmtClock(row.minute) + ' ' + row.label).join('\n')),
      'END:VEVENT',
    );
  }

  for (let i = 0; i < timeline.stops.length; i++) {
    const stop = placed[i];
    if (stop === null) continue;
    const timed = timeline.stops[i];
    // The truck leaves for a stop when the drive that leads to it starts.
    const leg = i < timeline.legs.length ? timeline.legs[i] : null;
    const leaveBy = leg !== null && leg.depart_minute !== null ? leg.depart_minute : i === 0 ? timeline.leave_base : null;
    const times =
      (leaveBy === null ? '' : 'Leave by ' + fmtClock(leaveBy) + '. ') +
      'Arrive ' +
      fmtClock(timed.arrive) +
      '. Open ' +
      fmtClock(timed.effective_open) +
      '. Close ' +
      fmtClock(timed.close) +
      '. Leave ' +
      fmtClock(timed.leave) +
      '.';
    const description = [times, 'Estimate: ' + estimateText(stop.orders, 'orders') + '.', STANDING.noticeLine].join('\n');
    const address = stop.address.trim();
    lines.push(
      'BEGIN:VEVENT',
      uid(stop.id),
      'DTSTAMP:' + input.dtstamp,
      'DTSTART:' + at(timed.effective_open),
      'DTEND:' + at(timed.close),
      'SUMMARY:' + icsText(names[i]),
      'LOCATION:' + icsText(address !== '' ? address : fmtCoord(stop.point.lat, stop.point.lng)),
      'GEO:' + fmtFixed(stop.point.lat, 6) + ';' + fmtFixed(stop.point.lng, 6),
      'DESCRIPTION:' + icsText(description),
      'END:VEVENT',
    );
  }

  lines.push('END:VCALENDAR');
  return lines.map(icsFold).join('\r\n') + '\r\n';
}
