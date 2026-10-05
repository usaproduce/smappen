// Normalisation of place attributes (03_DATA.md 5.6). Every length limit counts Unicode code points and cuts on
// a code point boundary, which is how MySQL counts VARCHAR(n).

import { HOURS_RAW_MAX, parseOpeningHours } from './hours.mjs';

const TAG_KEYS = Object.freeze([
  'brand:wikidata', 'operator', 'takeaway', 'drive_through', 'outdoor_seating', 'delivery', 'rooms', 'beds',
  'capacity', 'building:levels', 'food', 'email',
].sort());

/** Cuts a string to at most `max` code points. */
export function cutCodePoints(s, max) {
  if (s.length <= max) return s; // UTF-16 length is an upper bound of the code point count
  const points = Array.from(s);
  return points.length <= max ? s : points.slice(0, max).join('');
}

function text(value) {
  return typeof value === 'string' ? value : '';
}

/** Trimmed tag value cut to `max` code points, or null when empty. */
function field(value, max) {
  const s = cutCodePoints(text(value).trim(), max).trim();
  return s === '' ? null : s;
}

/** `name` tag, else `brand` tag: trimmed, whitespace collapsed, NFC, at most 160 code points. Null when empty. */
export function normaliseName(tags) {
  for (const key of ['name', 'brand']) {
    const s = cutCodePoints(text(tags[key]).replace(/\s+/g, ' ').trim().normalize('NFC'), 160).trim();
    if (s !== '') return s;
  }
  return null;
}

/**
 * Comparison key of a name for de-duplication (5.4): NFKD, combining marks removed, lower case, "&" read as
 * "and", every run of other characters outside a-z and 0-9 to one space, trimmed.
 */
export function comparableName(name) {
  if (name === null || name === undefined) return '';
  return name.normalize('NFKD').replace(/\p{M}/gu, '').toLowerCase().replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, ' ').trim();
}

/** US phone number as +1 and ten digits, or null. */
export function normalisePhone(raw) {
  const value = text(raw).trim();
  if (value === '') return null;
  const first = value.split(/[;,/]| or /)[0];
  let d = first.replace(/(?:ext\.?|x)\s*\d+\s*$/i, '').replace(/[^0-9+]/g, '');
  if (d.startsWith('+1')) d = d.slice(2);
  else if (d.startsWith('001')) d = d.slice(3);
  else if (d.startsWith('+')) return null;
  if (d.length === 11 && d[0] === '1') d = d.slice(1);
  if (!/^[2-9]\d{2}[2-9]\d{6}$/.test(d)) return null;
  return `+1${d}`;
}

/** http(s) URL with a lower-cased scheme and host, or null. */
export function normaliseWebsite(raw) {
  let s = text(raw).split(';')[0].trim();
  if (s === '' || /\s/.test(s)) return null;
  if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(s)) s = `https://${s}`;
  const m = /^(https?):\/\/([^/?#]+)(.*)$/i.exec(s);
  if (!m) return null;
  const host = m[2].toLowerCase();
  if (!/^[a-z0-9.-]+\.[a-z]{2,}(:\d+)?$/.test(host)) return null;
  const out = `${m[1].toLowerCase()}://${host}${m[3]}`;
  return Array.from(out).length <= 255 ? out : null;
}

function firstNonEmpty(tags, keys) {
  for (const key of keys) {
    if (text(tags[key]).trim() !== '') return tags[key];
  }
  return null;
}

/** Street address line, at most 200 code points, or null. */
export function normaliseAddrLine(tags) {
  const street = text(tags['addr:street']).trim();
  let line;
  if (street !== '') {
    const number = text(tags['addr:housenumber']).trim();
    const unit = text(tags['addr:unit']).trim();
    line = (number !== '' ? `${number} ${street}` : street) + (unit !== '' ? `, ${unit}` : '');
  } else {
    line = text(tags['addr:full']).trim();
  }
  return field(line, 200);
}

/** Up to six cuisine values, lower case with underscores, joined with ";" in at most 120 code points, or null. */
export function normaliseCuisine(raw) {
  const values = [];
  for (const part of text(raw).split(/[;,]/)) {
    const v = part.trim().toLowerCase().replace(/\s+/g, '_');
    if (v !== '' && !values.includes(v)) values.push(v);
  }
  let kept = values.slice(0, 6);
  while (kept.length > 0 && Array.from(kept.join(';')).length > 120) kept = kept.slice(0, -1);
  return kept.length > 0 ? kept.join(';') : null;
}

/** The kept subset of raw tags with sorted keys, or null when none is present. */
export function normaliseTags(tags) {
  let out = null;
  for (const key of TAG_KEYS) {
    const v = text(tags[key]).trim();
    if (v === '') continue;
    if (out === null) out = {};
    out[key] = v;
  }
  return out;
}

/**
 * All normalised attributes of one element.
 * @param {Record<string, string>} tags raw OSM tags
 * @returns {{name: string|null, brand: string|null, phone: string|null, website: string|null, addr_line: string|null,
 *            city: string|null, state_code: string|null, postcode: string|null, cuisine: string|null,
 *            opening_hours_raw: string|null, hours_mask: string|null, tags: object|null,
 *            phoneRaw: boolean, websiteRaw: boolean}}
 *          `phoneRaw` and `websiteRaw` say whether a raw value was present (coverage counters)
 */
export function normaliseAttributes(tags) {
  const phoneRaw = firstNonEmpty(tags, ['phone', 'contact:phone']);
  const websiteRaw = firstNonEmpty(tags, ['website', 'contact:website']);
  const state = text(tags['addr:state']).trim();
  const postcode = text(tags['addr:postcode']).trim();
  const hoursValue = text(tags.opening_hours).trim();
  const hoursTooLong = Array.from(hoursValue).length > HOURS_RAW_MAX;
  return {
    name: normaliseName(tags),
    brand: field(tags.brand, 120),
    phone: normalisePhone(phoneRaw),
    website: normaliseWebsite(websiteRaw),
    addr_line: normaliseAddrLine(tags),
    city: field(tags['addr:city'], 80),
    state_code: /^[A-Za-z]{2}$/.test(state) ? state.toUpperCase() : null,
    postcode: /^\d{5}(-\d{4})?$/.test(postcode) ? postcode.slice(0, 5) : null,
    cuisine: normaliseCuisine(tags.cuisine),
    opening_hours_raw: hoursValue === '' ? null : cutCodePoints(hoursValue, HOURS_RAW_MAX),
    hours_mask: hoursValue === '' || hoursTooLong ? null : parseOpeningHours(hoursValue),
    tags: normaliseTags(tags),
    phoneRaw: phoneRaw !== null,
    websiteRaw: websiteRaw !== null,
  };
}
