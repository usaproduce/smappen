// Serialisation of the four data files (03_DATA.md 8.1 to 8.4). Text is UTF-8 with LF line ends, no BOM and
// a final newline. Numbers are written with Number.prototype.toString(), the shortest decimal that round-trips.

import crypto from 'node:crypto';
import { formatNearby } from './cells.mjs';
import { csvLine } from './csv.mjs';
import { REVIEW_COLUMNS } from './review.mjs';
import { SEGMENTS } from './vocabulary.mjs';

export const POINT_COLUMNS = Object.freeze([
  'point_id', 'src_kind', 'src_ref', 'in_region', 'job_adj', 'lat', 'lng', ...SEGMENTS.map((s) => `b_${s}`),
]);

export const PLACE_KEYS = Object.freeze([
  'place_key', 'osm_type', 'osm_id', 'place_type', 'geom_kind', 'in_region', 'county_fips', 'name', 'brand', 'lat',
  'lng', 'rival_kind', 'visitor_segment', 'size_default', 'host_fit', 'kitchen', 'phone', 'website', 'addr_line',
  'city', 'state_code', 'postcode', 'cuisine', 'opening_hours_raw', 'hours_mask', 'tags',
]);

export const CELL_COLUMNS = Object.freeze(['h3', 'lat', 'lng', 'nearby_etl']);

function num(x) {
  if (typeof x !== 'number' || !Number.isFinite(x)) throw new Error(`writer: ${x} is not a finite number`);
  return String(x === 0 ? 0 : x);
}

/** points.tsv: tab-separated, header row, rows in the order given (the caller sorts by point_id). */
export function renderPoints(points) {
  const lines = [POINT_COLUMNS.join('\t')];
  for (const p of points) {
    const cells = [p.id, p.kind, p.ref, p.inRegion ? '1' : '0', p.jobAdj ? '1' : '0', num(p.lat), num(p.lng)];
    for (let s = 0; s < 16; s++) cells.push(num(p.base[s]));
    lines.push(cells.join('\t'));
  }
  return `${lines.join('\n')}\n`;
}

/** One places.ndjson object with the keys in file order. */
export function placeObject(place) {
  return {
    place_key: place.place_key,
    osm_type: place.osm_type,
    osm_id: String(place.osm_id),
    place_type: place.place_type,
    geom_kind: place.geom_kind,
    in_region: place.in_region ? 1 : 0,
    county_fips: place.county_fips,
    name: place.name,
    brand: place.brand,
    lat: place.lat,
    lng: place.lng,
    rival_kind: place.rival_kind,
    visitor_segment: place.visitor_segment,
    size_default: place.size_default,
    host_fit: place.host_fit,
    kitchen: place.kitchen,
    phone: place.phone,
    website: place.website,
    addr_line: place.addr_line,
    city: place.city,
    state_code: place.state_code,
    postcode: place.postcode,
    cuisine: place.cuisine,
    opening_hours_raw: place.opening_hours_raw,
    hours_mask: place.hours_mask,
    tags: place.tags,
  };
}

/** places.ndjson: one JSON object per line, rows in the order given (the caller sorts by place_key). */
export function renderPlaces(places) {
  let out = '';
  for (const place of places) out += `${JSON.stringify(placeObject(place))}\n`;
  return out;
}

/** cells.tsv: tab-separated, header row, kept cells in the order given (the caller sorts by h3). */
export function renderCells(cells) {
  const lines = [CELL_COLUMNS.join('\t')];
  for (const c of cells) lines.push(`${c.h3}\t${num(c.lat)}\t${num(c.lng)}\t${formatNearby(c.nearby)}`);
  return `${lines.join('\n')}\n`;
}

/** job_review.csv: RFC 4180 quoting, header row, rows in the order given. */
export function renderReview(rows) {
  const lines = [csvLine(REVIEW_COLUMNS)];
  for (const row of rows) {
    lines.push(csvLine(REVIEW_COLUMNS.map((column) => {
      const v = row[column];
      return typeof v === 'number' ? num(v) : v;
    })));
  }
  return `${lines.join('\n')}\n`;
}

/** SHA-256 of a UTF-8 string or buffer, lower-case hex. */
export function sha256Hex(data) {
  return crypto.createHash('sha256').update(typeof data === 'string' ? Buffer.from(data, 'utf8') : data).digest('hex');
}

/** {file, bytes, sha256, rows} of one rendered file. `rows` is the number of data rows (the header excluded). */
export function describeOutput(file, content, rows) {
  const buffer = Buffer.from(content, 'utf8');
  return { file, bytes: buffer.length, sha256: sha256Hex(buffer), rows };
}
