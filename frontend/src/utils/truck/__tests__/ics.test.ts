// Truck Planner - the calendar file of a day (docs/truck-planner/05_FRONTEND.md 4.10 and 8.2).
//
// The day used throughout is the worked day of 02_MODEL 4.12, which is also the blueprint's day
// sheet: Thursday 2026-10-08 in Eastern time, prep from 9:34 AM, the office park 11 AM to 2 PM, the
// taproom 5 PM to 8 PM, done at 8:51 PM. Its timeline and its orders are the reference's own
// (golden case g18-001), so every time asserted here is one the model produced.

import { describe, expect, it } from 'vitest';
import type { DayResult } from '../model';
import { ICS_MIME, buildDayIcs, estimateText, icsFileName, icsFold, icsText, type DayIcsInput, type IcsStop } from '../ics';
import { STANDING } from '../wording';
import { goldenCase, spec } from './_kitFixtures';

const worked = goldenCase('g18-001');
const result = worked.expected as DayResult;
const DATE = worked.args.plan.date as string;
const ZONE = 'America/New_York';

/** The two stops as the planner hands them to the calendar button, with the model's orders. */
function stops(): IcsStop[] {
  return [
    { id: 'office', name: 'Herndon office park', address: '12950 Worldgate Dr, Herndon, VA 20170', point: { lat: 38.96, lng: -77.36 }, orders: result.stops[0].orders },
    { id: 'taproom', name: 'Sterling taproom', address: '', point: { lat: 39.0035, lng: -77.4035 }, orders: result.stops[1].orders },
  ];
}

function input(patch: Partial<DayIcsInput> = {}): DayIcsInput {
  return {
    date: DATE,
    timeZone: ZONE,
    timeline: result.timeline,
    stops: stops(),
    truckName: 'Blue Crab Tacos',
    host: 'app.example.com',
    dtstamp: '20261007T231500Z',
    ...patch,
  };
}

/** The content lines of a file: physical lines joined where they were folded. */
function contentLines(file: string): string[] {
  const lines = file.split('\r\n ').join('').split('\r\n');
  expect(lines[lines.length - 1]).toBe(''); // the file ends with a line break
  return lines.slice(0, -1);
}

/** The events of a file, each as its content lines between BEGIN:VEVENT and END:VEVENT. */
function events(file: string): string[][] {
  const out: string[][] = [];
  let current: string[] | null = null;
  for (const line of contentLines(file)) {
    if (line === 'BEGIN:VEVENT') current = [];
    else if (line === 'END:VEVENT') {
      if (current !== null) out.push(current);
      current = null;
    } else if (current !== null) current.push(line);
  }
  return out;
}

/** The value of a property of an event. */
function valueOf(event: readonly string[], name: string): string | null {
  for (const line of event) {
    if (line.startsWith(name + ':')) return line.slice(name.length + 1);
  }
  return null;
}

function octets(text: string): number {
  return Buffer.byteLength(text, 'utf8');
}

// -------------------------------------------------------------------------------------------------

describe('the worked day as a calendar file', () => {
  const file = buildDayIcs(input());
  const [day, first, second] = events(file);

  it('is the day of the blueprint', () => {
    expect(DATE).toBe('2026-10-08');
    expect(result.timeline.start_prep).toBe(574);
    expect(result.timeline.stops[0].effective_open).toBe(660);
    expect(result.timeline.done).toBe(1251);
  });

  it('opens with the five calendar lines and closes the calendar', () => {
    const lines = contentLines(file);
    expect(lines.slice(0, 5)).toEqual(
      spec(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//smappen//Truck Planner//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH']),
    );
    expect(lines[lines.length - 1]).toBe('END:VCALENDAR');
    // UTC instants only: no time-zone block and no zone parameter is needed.
    expect(file).not.toContain('VTIMEZONE');
    expect(file).not.toContain('TZID');
  });

  it('gives three events: the day and one per stop', () => {
    expect(spec(events(file))).toHaveLength(3);
    expect(file.split('BEGIN:VEVENT').length - 1).toBe(3);
    expect(file.split('END:VEVENT').length - 1).toBe(3);
  });

  it('the day runs from the start of prep, 9:34 AM Eastern, to done', () => {
    expect(spec(day)).toContain('DTSTART:20261008T133400Z');
    // 8:51 PM Eastern daylight time is 00:51 UTC on the next date.
    expect(valueOf(day, 'DTEND')).toBe('20261009T005100Z');
    expect(valueOf(day, 'SUMMARY')).toBe(spec('Truck day: Herndon office park\\, Sterling taproom'));
  });

  it("the day's description is the timeline, one event a line", () => {
    expect(valueOf(day, 'DESCRIPTION')).toBe(
      [
        '9:34 AM Start prep',
        '10:19 AM Leave base',
        '10:30 AM Arrive at Herndon office park',
        '11:00 AM Open',
        '2:00 PM Close',
        '2:20 PM Leave Herndon office park',
        '2:30 PM Arrive at Sterling taproom',
        '4:30 PM Start setting up',
        '5:00 PM Open',
        '8:00 PM Close',
        '8:20 PM Leave Sterling taproom',
        '8:21 PM Back at base',
        '8:51 PM Done',
      ].join('\\n'),
    );
  });

  it('the first stop runs from the time it opens, 11 AM Eastern, to the time it closes', () => {
    expect(spec(valueOf(first, 'DTSTART'))).toBe('20261008T150000Z');
    expect(valueOf(first, 'DTEND')).toBe('20261008T180000Z');
    expect(valueOf(first, 'SUMMARY')).toBe('Herndon office park');
    expect(valueOf(second, 'DTSTART')).toBe('20261008T210000Z');
    expect(valueOf(second, 'DTEND')).toBe('20261009T000000Z');
    expect(valueOf(second, 'SUMMARY')).toBe('Sterling taproom');
  });

  it('a stop says when to leave, arrive, open, close and leave, then its estimate with range and label, then the standing notice', () => {
    expect(valueOf(first, 'DESCRIPTION')).toBe(
      [
        'Leave by 10:19 AM. Arrive 10:30 AM. Open 11:00 AM. Close 2:00 PM. Leave 2:20 PM.',
        'Estimate: 60 orders (33 to 93)\\, rough.',
        STANDING.noticeLine,
      ].join('\\n'),
    );
    expect(valueOf(second, 'DESCRIPTION')).toBe(
      [
        'Leave by 2:20 PM. Arrive 2:30 PM. Open 5:00 PM. Close 8:00 PM. Leave 8:20 PM.',
        'Estimate: 39 orders (21 to 62)\\, rough.',
        STANDING.noticeLine,
      ].join('\\n'),
    );
  });

  it('a stop is placed by its address, or by its coordinates when it has none', () => {
    expect(valueOf(first, 'LOCATION')).toBe('12950 Worldgate Dr\\, Herndon\\, VA 20170');
    expect(valueOf(second, 'LOCATION')).toBe('39.0035\\, -77.4035');
    // GEO is a pair of numbers, not text: its semicolon is the separator and is not escaped.
    expect(valueOf(first, 'GEO')).toBe('38.960000;-77.360000');
    expect(valueOf(second, 'GEO')).toBe('39.003500;-77.403500');
  });

  it('every event carries its own UID and the stamp it was handed', () => {
    expect(valueOf(day, 'UID')).toBe('tp-2026-10-08-day@app.example.com');
    expect(valueOf(first, 'UID')).toBe('tp-2026-10-08-office@app.example.com');
    expect(valueOf(second, 'UID')).toBe('tp-2026-10-08-taproom@app.example.com');
    for (const event of [day, first, second]) expect(valueOf(event, 'DTSTAMP')).toBe('20261007T231500Z');
  });

  it('never prints an estimate without its range and its label', () => {
    expect(estimateText(result.stops[0].orders, 'orders')).toBe('60 orders (33 to 93), rough');
    expect(estimateText(result.totals.take_home, 'money')).toBe('$482 ($42 to $1,012), rough');
    expect(estimateText(result.totals.take_home_per_hour, 'money_per_hour')).toBe('$43 an hour ($4 to $90), rough');
    // A fixed amount is one figure, and says that it is fixed.
    expect(estimateText({ value: 80, low: 80, high: 80, confidence: 'fixed' }, 'orders')).toBe('80 orders, fixed');
    expect(estimateText({ value: 3.4, low: 0.2, high: 9.6, confidence: 'very_rough' }, 'orders')).toBe('3 orders (0 to 10), very rough');
  });

  it('is named after its date and typed as a calendar', () => {
    expect(icsFileName(DATE)).toBe('truck-day-2026-10-08.ics');
    expect(ICS_MIME).toBe('text/calendar;charset=utf-8');
  });
});

describe('times of the day in other places and at other hours', () => {
  it('a stop that closes after midnight ends on the next date', () => {
    const timeline = JSON.parse(JSON.stringify(result.timeline)) as DayResult['timeline'];
    timeline.stops[1].close = 1500; // 1:00 AM of the next day
    const [, , second] = events(buildDayIcs(input({ timeline })));
    expect(valueOf(second, 'DTEND')).toBe('20261009T050000Z');
    expect(valueOf(second, 'DESCRIPTION')).toContain('Close 1:00 AM (next day).');
  });

  it("uses the truck's time zone, not the machine's", () => {
    const [day, first] = events(buildDayIcs(input({ timeZone: 'America/Chicago' })));
    expect(valueOf(day, 'DTSTART')).toBe('20261008T143400Z');
    expect(valueOf(first, 'DTSTART')).toBe('20261008T160000Z');
  });

  it('matches stops to the timeline by their ids, whatever order they are handed over in', () => {
    const [, first, second] = events(buildDayIcs(input({ stops: stops().reverse() })));
    expect(valueOf(first, 'SUMMARY')).toBe('Herndon office park');
    expect(valueOf(first, 'DTSTART')).toBe('20261008T150000Z');
    expect(valueOf(second, 'SUMMARY')).toBe('Sterling taproom');
  });

  it('a stop the input does not hold gets no entry and is still named in the day', () => {
    const list = events(buildDayIcs(input({ stops: [stops()[1]] })));
    expect(list).toHaveLength(2);
    expect(valueOf(list[0], 'SUMMARY')).toBe('Truck day: Stop 1\\, Sterling taproom');
    expect(valueOf(list[1], 'UID')).toBe('tp-2026-10-08-taproom@app.example.com');
  });

  it('an empty day is a calendar without events', () => {
    const timeline: DayResult['timeline'] = {
      events: [],
      stops: [],
      legs: [],
      start_prep: null,
      leave_base: null,
      back_at_base: null,
      done: null,
      day_minutes: 0,
      paid_minutes: 0,
      unpaid_gap_minutes: 0,
      drive_minutes: 0,
      service_minutes: 0,
      generator_minutes: 0,
      miles: 0,
      tolls: 0,
    };
    expect(buildDayIcs(input({ timeline, stops: [] }))).toBe(
      'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//smappen//Truck Planner//EN\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\nEND:VCALENDAR\r\n',
    );
  });
});

describe('line endings', () => {
  const file = buildDayIcs(input());

  it('every line ends in CRLF', () => {
    expect(file.endsWith('\r\n')).toBe(true);
    // No line feed without its carriage return, and no carriage return without its line feed.
    expect(file.split('\r\n').join('')).not.toMatch(/[\r\n]/);
    expect(file.split('\r\n').length - 1).toBeGreaterThan(25);
  });

  it('a line break inside a text is written as the two characters \\n, never as a break', () => {
    const [day] = events(file);
    expect(valueOf(day, 'DESCRIPTION')).toContain('9:34 AM Start prep\\n10:19 AM Leave base');
  });
});

describe('folding at 75 octets', () => {
  it('leaves a line of 75 octets alone and folds one of 76', () => {
    expect(icsFold('x'.repeat(75))).toBe('x'.repeat(75));
    expect(icsFold('x'.repeat(76))).toBe('x'.repeat(75) + '\r\n x');
  });

  it('counts the space that starts a continuation line', () => {
    const parts = icsFold('y'.repeat(200)).split('\r\n');
    expect(parts.map((part) => part.length)).toEqual([75, 75, 52]);
    expect(parts[1].startsWith(' y')).toBe(true);
    expect(parts.map((part) => (part.startsWith(' ') ? part.slice(1) : part)).join('')).toBe('y'.repeat(200));
  });

  it('never cuts a multi-byte character: one that would cross the limit moves to the next line whole', () => {
    // 74 octets, then a two-octet letter that would take octets 75 and 76.
    const line = 'SUMMARY:' + 'a'.repeat(66) + 'é' + 'b'.repeat(10);
    const [head, tail] = icsFold(line).split('\r\n');
    expect(head).toBe('SUMMARY:' + 'a'.repeat(66));
    expect(octets(head)).toBe(74);
    expect(tail).toBe(' é' + 'b'.repeat(10));
    // One octet earlier it still fits: the line is then exactly 75 octets.
    const fits = icsFold('SUMMARY:' + 'a'.repeat(65) + 'é' + 'b'.repeat(10)).split('\r\n');
    expect(fits[0]).toBe('SUMMARY:' + 'a'.repeat(65) + 'é');
    expect(octets(fits[0])).toBe(75);
    // A four-octet character (two UTF-16 units) is kept together as well.
    const wide = icsFold('SUMMARY:' + 'a'.repeat(65) + '\u{1F69A}' + 'b').split('\r\n');
    expect(wide).toEqual(['SUMMARY:' + 'a'.repeat(65), ' \u{1F69A}b']);
    // And a three-octet one.
    const three = icsFold('SUMMARY:' + 'a'.repeat(66) + '€' + 'b').split('\r\n');
    expect(three).toEqual(['SUMMARY:' + 'a'.repeat(66), ' €b']);
  });

  it('holds for a whole file with long and accented names', () => {
    const named = stops();
    named[0].name = 'Café Señor Müller at the Réunion office park, north entrance by the fountain';
    named[0].address = '12950 Worldgate Drive, Suite 400 (loading dock behind the café), Herndon, Virginia 20170, États-Unis';
    named[1].name = 'Brasserie Zoë \u{1F69A} taproom and beer garden, Sterling';
    const file = buildDayIcs(input({ stops: named }));
    const physical = file.split('\r\n');
    for (const line of physical) expect(octets(line), line).toBeLessThanOrEqual(75);
    expect(physical.some((line) => line.startsWith(' '))).toBe(true);
    // Encoded and decoded again, no character was broken.
    expect(Buffer.from(file, 'utf8').toString('utf8')).toBe(file);
    for (const line of physical) expect(Buffer.from(line, 'utf8').toString('utf8')).not.toContain('�');
    // Unfolded, the content is what was written.
    const [day, first, second] = events(file);
    expect(valueOf(day, 'SUMMARY')).toBe('Truck day: ' + icsText(named[0].name + ', ' + named[1].name));
    expect(valueOf(first, 'SUMMARY')).toBe(icsText(named[0].name));
    expect(valueOf(first, 'LOCATION')).toBe(icsText(named[0].address));
    expect(valueOf(second, 'SUMMARY')).toBe(icsText(named[1].name));
  });
});

describe('escaping', () => {
  it('escapes backslash, semicolon and comma, and writes a line break as \\n', () => {
    expect(icsText('a,b')).toBe('a\\,b');
    expect(icsText('a;b')).toBe('a\\;b');
    expect(icsText('a\\b')).toBe('a\\\\b');
    expect(icsText('a\nb')).toBe('a\\nb');
    expect(icsText('a\r\nb')).toBe('a\\nb');
    expect(icsText('a\rb')).toBe('a\\nb');
    expect(icsText('Fish, chips; and a \\ slash\nsecond line')).toBe('Fish\\, chips\\; and a \\\\ slash\\nsecond line');
    // A colon and a quote need no escape in a text value; other control characters are not passed on.
    expect(icsText('Open: 11 "sharp"')).toBe('Open: 11 "sharp"');
    expect(icsText('a\u0000b\u0007c\td')).toBe('a b c\td');
  });

  it('escapes the names and the address a stop was given', () => {
    const named = stops();
    named[0].name = 'Fish, chips; and \\ more\nby the gate';
    named[0].address = 'Lot 4; Gate B, Herndon';
    const [day, first] = events(buildDayIcs(input({ stops: named })));
    expect(valueOf(first, 'SUMMARY')).toBe('Fish\\, chips\\; and \\\\ more\\nby the gate');
    expect(valueOf(first, 'LOCATION')).toBe('Lot 4\\; Gate B\\, Herndon');
    expect(valueOf(day, 'SUMMARY')).toBe('Truck day: Fish\\, chips\\; and \\\\ more\\nby the gate\\, Sterling taproom');
    expect(valueOf(day, 'DESCRIPTION')).toContain('Arrive at Fish\\, chips\\; and \\\\ more\\nby the gate\\n');
  });
});

describe('the same input always gives the same bytes', () => {
  it('twice from one input, and from an equal copy of it', () => {
    const a = buildDayIcs(input());
    const b = buildDayIcs(input());
    const c = buildDayIcs(JSON.parse(JSON.stringify(input())) as DayIcsInput);
    expect(Buffer.from(a, 'utf8').equals(Buffer.from(b, 'utf8'))).toBe(true);
    expect(Buffer.from(a, 'utf8').equals(Buffer.from(c, 'utf8'))).toBe(true);
  });

  it('reads no clock: only the stamp it is handed changes the file', () => {
    const a = buildDayIcs(input());
    const later = buildDayIcs(input({ dtstamp: '20261008T010000Z' }));
    expect(later).not.toBe(a);
    expect(later.split('20261008T010000Z').join('20261007T231500Z')).toBe(a);
  });

  it('does not change what it is given', () => {
    const given = input();
    const before = JSON.stringify(given);
    buildDayIcs(given);
    expect(JSON.stringify(given)).toBe(before);
  });
});
