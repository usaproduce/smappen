// OpenStreetMap opening_hours to a 168-bit weekly mask (03_DATA.md 5.7).
// Only a small grammar is accepted. Anything else gives null, which means unknown, never closed.

const DAY_INDEX = { mo: 0, tu: 1, we: 2, th: 3, fr: 4, sa: 5, su: 6 };
const DAYSPEC = /^(mo|tu|we|th|fr|sa|su|ph)(?:-(mo|tu|we|th|fr|sa|su))?$/;
const STARTS_WITH_DAY = /^(?:mo|tu|we|th|fr|sa|su|ph)(?![a-z0-9_])/;
const SPAN = /^(\d{1,2}):(\d{2})-(\d{1,2}):(\d{2})\+?$/;
const OPEN_END = /^(\d{1,2}):(\d{2})\+$/;
const MINUTES_PER_DAY = 1440;
const MINUTES_PER_WEEK = 10080;

/** Longest raw value that is parsed, in Unicode code points (the column length of opening_hours_raw). */
export const HOURS_RAW_MAX = 255;

/**
 * @param {string[]} tokens dayspec strings
 * @returns {{days: number[], holidayOnly: boolean} | null}
 */
function parseDays(tokens) {
  const days = [];
  let sawDay = false;
  for (const token of tokens) {
    const m = DAYSPEC.exec(token);
    if (!m) return null;
    if (m[1] === 'ph') {
      if (m[2]) return null;
      continue;
    }
    let a = DAY_INDEX[m[1]];
    const b = m[2] ? DAY_INDEX[m[2]] : a;
    for (;;) {
      if (!days.includes(a)) days.push(a);
      if (a === b) break;
      a = (a + 1) % 7;
    }
    sawDay = true;
  }
  return { days, holidayOnly: !sawDay };
}

/** @returns {[number, number] | null} start and end in minutes from the day's midnight */
function parseSpan(token) {
  let m = SPAN.exec(token);
  if (m) {
    const a = Number(m[1]) * 60 + Number(m[2]);
    let b = Number(m[3]) * 60 + Number(m[4]);
    if (Number(m[2]) > 59 || Number(m[4]) > 59 || a >= MINUTES_PER_DAY || b > 2 * MINUTES_PER_DAY) return null;
    if (b <= a) b += MINUTES_PER_DAY;
    if (b - a > MINUTES_PER_DAY) return null;
    return [a, b];
  }
  m = OPEN_END.exec(token);
  if (m) {
    const a = Number(m[1]) * 60 + Number(m[2]);
    if (Number(m[2]) > 59 || a >= MINUTES_PER_DAY) return null;
    return [a, MINUTES_PER_DAY];
  }
  return null;
}

/**
 * Parses an opening_hours value into its weekly spans.
 * @param {unknown} raw
 * @returns {number[][][] | null} per weekday (Monday = 0) a list of [start, end) minute spans, or null when the
 *          value is outside the grammar
 */
export function parseOpeningHoursSpans(raw) {
  if (typeof raw !== 'string') return null;
  let s = raw.trim().toLowerCase();
  if (s === '' || Array.from(s).length > HOURS_RAW_MAX) return null;
  s = s.replace(/\s+/g, ' ').replace(/ ?- ?/g, '-').replace(/ ?, ?/g, ',').replace(/ ?; ?/g, ';').replace(/;+$/, '');
  if (s === '') return null;

  /** @type {number[][][]} */
  const spans = [[], [], [], [], [], [], []];
  for (const rule of s.split(';')) {
    if (rule === '') return null;
    // A comma starts a new sub only when the next piece begins with a day and the current sub already
    // contains a digit, "off" or "closed".
    const subs = [];
    let current = null;
    for (const piece of rule.split(',')) {
      if (current !== null && STARTS_WITH_DAY.test(piece) && /\d|off|closed/.test(current)) {
        subs.push(current);
        current = piece;
      } else {
        current = current === null ? piece : `${current},${piece}`;
      }
    }
    subs.push(current);

    for (let i = 0; i < subs.length; i++) {
      const sub = subs[i];
      if (sub === '24/7') {
        for (let d = 0; d < 7; d++) spans[d] = [[0, MINUTES_PER_DAY]];
        continue;
      }
      let dayPart = '';
      let timePart = sub;
      if (STARTS_WITH_DAY.test(sub)) {
        const space = sub.indexOf(' ');
        if (space < 0) { dayPart = sub; timePart = ''; } else { dayPart = sub.slice(0, space); timePart = sub.slice(space + 1); }
      }
      let days = [0, 1, 2, 3, 4, 5, 6];
      let holidayOnly = false;
      if (dayPart !== '') {
        const parsed = parseDays(dayPart.split(','));
        if (!parsed) return null;
        days = parsed.days;
        holidayOnly = parsed.holidayOnly;
      }
      let list;
      if (timePart === 'off' || timePart === 'closed') {
        if (dayPart === '') return null;
        list = [];
      } else if (timePart === '') {
        if (dayPart === '') return null;
        list = [[0, MINUTES_PER_DAY]];
      } else {
        list = [];
        for (const token of timePart.split(',')) {
          const span = parseSpan(token);
          if (!span) return null;
          list.push(span);
        }
      }
      if (holidayOnly) continue; // a sub naming only "ph" is validated and skipped
      // The first sub of a rule replaces the list of each day it names. Later subs of the same rule add to it.
      for (const d of days) spans[d] = i > 0 ? spans[d].concat(list) : list.slice();
    }
  }
  return spans;
}

/**
 * @param {unknown} raw opening_hours value
 * @returns {string | null} 42 lower-case hex characters (21 bytes; bit h = dow * 24 + hour, Monday = 0, is bit
 *          h & 7 of byte h >> 3), or null when the value is outside the grammar
 */
export function parseOpeningHours(raw) {
  const spans = parseOpeningHoursSpans(raw);
  if (!spans) return null;
  const open = new Uint8Array(MINUTES_PER_WEEK);
  for (let d = 0; d < 7; d++) {
    for (const [a, b] of spans[d]) {
      for (let x = a; x < b; x++) open[(d * MINUTES_PER_DAY + x) % MINUTES_PER_WEEK] = 1;
    }
  }
  const bytes = new Uint8Array(21);
  for (let h = 0; h < 168; h++) {
    let minutes = 0;
    for (let x = 0; x < 60; x++) minutes += open[h * 60 + x];
    if (minutes >= 30) bytes[h >> 3] |= 1 << (h & 7);
  }
  return Buffer.from(bytes).toString('hex');
}

/** Number of open hours in a mask. */
export function openHours(maskHex) {
  let n = 0;
  for (const byte of Buffer.from(maskHex, 'hex')) {
    let x = byte;
    while (x) { n += x & 1; x >>= 1; }
  }
  return n;
}
