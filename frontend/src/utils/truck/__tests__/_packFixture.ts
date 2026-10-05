// Truck Planner map engine - a cell-pack writer for tests. Not a test.
//
// It mirrors docs/truck-planner/03_DATA.md section 11 (the format the PHP loader writes): a 12-byte
// prefix, the JSON header, zero padding to a multiple of 8, the ids as little-endian u64 ascending,
// then K columns of N u16 codes, column-major, with code = min(65535, floor(65535 * sqrt(v / scale) + 0.5))
// and scale = the largest value of the column. The decoder under test is utils/truck/map/pack.ts.
//
// Also here: deterministic synthetic regions (real H3 cells around a point, made-up features from a
// fixed linear congruential generator, never the runtime's random source), for the tests that need
// a pack of the size of a real region.

import { cellToLatLng, gridDisk, latLngToCell } from 'h3-js';
import { FEATURE_COLUMNS, MODEL_VERSION, SEGMENTS } from '../model';

export const PACK_K = 50;

/** The centre of Washington, DC: where the synthetic regions sit. */
export const DC_CENTER = { lat: 38.9072, lng: -77.0369 };

export interface PackFixtureOptions {
  /** 15-character H3 ids. Written in the order given: pass them ascending, as the loader does. */
  ids: string[];
  /** Row-major values, 50 per cell (or `columns.length` per cell when columns are given). */
  values: ArrayLike<number>;
  /** Column names. Default: the 50 of the format. */
  columns?: string[];
  /** Keys that replace or extend the header. */
  header?: Record<string, unknown>;
  /** Pad the header JSON with spaces until its byte length leaves this remainder modulo 8. */
  headerBytesMod8?: number;
  /** The 16-bit format version of the prefix. Default 1. */
  formatVersion?: number;
  /** The four magic bytes. Default "TPCP". */
  magic?: string;
}

export interface PackFixture {
  buffer: ArrayBuffer;
  /** Byte length of the JSON header as written. */
  headerBytes: number;
  /** Zero bytes between the header and the sections. */
  padding: number;
  /** Offset of the id section. */
  dataOffset: number;
  /** The decode scale of every column: its largest value. */
  scale: number[];
  /** The header object as written. */
  header: Record<string, unknown>;
}

/** The code of a value: 03_DATA section 11. */
export function quantise(v: number, scale: number): number {
  if (scale === 0) return 0;
  return Math.min(65535, Math.floor(65535 * Math.sqrt(v / scale) + 0.5));
}

/** The largest distance between a value and its decoded code: 03_DATA section 11. */
export function quantBound(v: number, scale: number): number {
  return Math.sqrt(v * scale) / 65535 + scale / (4 * 65535 * 65535);
}

export function encodePack(options: PackFixtureOptions): PackFixture {
  const ids = options.ids;
  const n = ids.length;
  const columns = options.columns ?? [...FEATURE_COLUMNS];
  const k = columns.length;
  const values = options.values;
  if (values.length !== n * k) throw new Error('encodePack: values must hold ' + k + ' numbers per cell');

  const scale: number[] = [];
  for (let j = 0; j < k; j++) {
    let max = 0;
    for (let i = 0; i < n; i++) if (values[i * k + j] > max) max = values[i * k + j];
    scale.push(max);
  }

  // The box of the cell centres, as the loader writes it.
  let latMin = Infinity;
  let latMax = -Infinity;
  let lngMin = Infinity;
  let lngMax = -Infinity;
  for (let i = 0; i < n; i++) {
    const [lat, lng] = cellToLatLng(ids[i]);
    if (lat < latMin) latMin = lat;
    if (lat > latMax) latMax = lat;
    if (lng < lngMin) lngMin = lng;
    if (lng > lngMax) lngMax = lng;
  }
  if (n === 0) {
    latMin = latMax = DC_CENTER.lat;
    lngMin = lngMax = DC_CENTER.lng;
  }

  // Keys in the order of 03_DATA section 11.
  const header: Record<string, unknown> = {
    format: 'tp-cell-pack',
    format_version: 1,
    region_id: 'dc',
    dataset_version: 'dc-20261003-3fa9c2d1',
    model_version: MODEL_VERSION,
    pipeline_version: 'tp-etl-1.0.0',
    h3_res: 9,
    cell_count: n,
    bounds: { lat_min: latMin, lng_min: lngMin, lat_max: latMax, lng_max: lngMax },
    kernel: { earth_radius_m: 6371008.8, walk_decay_m: 400, walk_cutoff_m: 1200, a0: 1.6, visibility: 1 },
    segments: [...SEGMENTS],
    columns,
    quant: { type: 'u16-sqrt', levels: 65535 },
    scale,
    sections: [
      { name: 'h3', type: 'u64le', offset: 0, count: n },
      { name: 'features', type: 'u16le', layout: 'column-major', offset: 8 * n, count: n * k },
    ],
    vintages: { census_reference_date: '2020-04-01', lodes_year: 2023, osm_snapshot_date: '2026-10-03' },
    attribution: ['© OpenStreetMap contributors', 'U.S. Census Bureau, 2020 Census', 'U.S. Census Bureau, LEHD LODES 8.4 (2023)'],
    ...(options.header ?? {}),
  };

  let json = JSON.stringify(header);
  let headerUtf8 = new TextEncoder().encode(json);
  if (options.headerBytesMod8 !== undefined) {
    // Trailing spaces are valid JSON and let a test choose how many padding bytes the file needs.
    while (headerUtf8.length % 8 !== options.headerBytesMod8) {
      json += ' ';
      headerUtf8 = new TextEncoder().encode(json);
    }
  }
  const headerBytes = headerUtf8.length;
  const padding = (8 - ((12 + headerBytes) % 8)) % 8;
  const dataOffset = 12 + headerBytes + padding;

  const buffer = new ArrayBuffer(dataOffset + 8 * n + 2 * n * k);
  const bytes = new Uint8Array(buffer);
  const dv = new DataView(buffer);
  const magic = options.magic ?? 'TPCP';
  for (let i = 0; i < 4; i++) bytes[i] = magic.charCodeAt(i);
  dv.setUint16(4, options.formatVersion ?? 1, true);
  dv.setUint16(6, 0, true);
  dv.setUint32(8, headerBytes, true);
  bytes.set(headerUtf8, 12);

  // Ids: the 15 hexadecimal characters read as a u64, low word first.
  for (let i = 0; i < n; i++) {
    const id = ids[i];
    dv.setUint32(dataOffset + 8 * i, parseInt(id.slice(-8), 16), true);
    dv.setUint32(dataOffset + 8 * i + 4, parseInt(id.slice(0, -8), 16), true);
  }
  const featuresOffset = dataOffset + 8 * n;
  for (let j = 0; j < k; j++) {
    for (let i = 0; i < n; i++) {
      dv.setUint16(featuresOffset + 2 * (j * n + i), quantise(values[i * k + j], scale[j]), true);
    }
  }
  return { buffer, headerBytes, padding, dataOffset, scale, header };
}

// A fixed linear congruential generator (the constants of Numerical Recipes), 32-bit state.
export function lcg(seedValue: number): () => number {
  let state = seedValue >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 4294967296;
  };
}

/**
 * n synthetic cells in pack layout. About a third of the capture and nearby entries are zero, as in
 * real data; magnitudes follow the region build (captures up to a few hundred, nearby up to tens of
 * thousands, rivals up to about a hundred).
 */
export function syntheticValues(n: number, seedValue: number): Float64Array {
  const next = lcg(seedValue);
  const values = new Float64Array(n * PACK_K);
  for (let c = 0; c < n; c++) {
    const b = c * PACK_K;
    const size = 0.05 + 2.0 * next() * next();
    for (let j = 0; j < 32; j++) values[b + j] = next() < 0.33 ? 0.0 : 400.0 * next() * next() * size;
    for (let j = 32; j < 48; j++) values[b + j] = next() < 0.33 ? 0.0 : 30000.0 * next() * next() * size;
    values[b + 48] = 110.0 * next() * next();
    values[b + 49] = 110.0 * next() * next();
  }
  return values;
}

/** n real resolution-9 cells in rings around a point, ascending by id as the loader writes them. */
export function diskIds(n: number, lat: number = DC_CENTER.lat, lng: number = DC_CENTER.lng): string[] {
  const centre = latLngToCell(lat, lng, 9);
  let rings = 0;
  while (3 * rings * (rings + 1) + 1 < n) rings++;
  return gridDisk(centre, rings).slice(0, n).sort();
}

/** A synthetic pack of n cells around a point. */
export function syntheticPack(n: number, seedValue: number = 20261005): PackFixture & { ids: string[]; values: Float64Array } {
  const ids = diskIds(n);
  const values = syntheticValues(n, seedValue);
  return { ...encodePack({ ids, values }), ids, values };
}
