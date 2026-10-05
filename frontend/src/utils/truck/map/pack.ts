// Truck Planner map engine - the cell pack (docs/truck-planner/03_DATA.md section 11,
// docs/truck-planner/05_FRONTEND.md 5.2).
//
// The pack is the one binary the map downloads per region and data version: N hexagon ids and, for
// each, 50 numbers (capture by day and by evening, people nearby, rival pull). It feeds colours only.
// No number printed anywhere comes from it: its values are 16-bit codes, and every figure the owner
// reads is computed from exact vectors.
//
// Layout, all integers little-endian:
//
//   0   4    magic "TPCP"
//   4   2    format version (1)
//   6   2    flags (reserved)
//   8   4    H, the byte length of the JSON header
//   12  H    the JSON header, UTF-8
//   ..  P    zero bytes up to the next multiple of 8
//   D   8N   the ids as u64, ascending
//   ..  2NK  the features as u16 codes, column-major: value = scale[j] * (code / 65535)^2
//
// Pure: runs in Node and in the browser.

import { FEATURE_COLUMNS } from '../model';

/** The JSON header of a cell pack (03_DATA section 11). */
export interface CellPackHeader {
  format: string;
  format_version: number;
  region_id: string;
  dataset_version: string;
  model_version: string;
  pipeline_version: string;
  h3_res: number;
  cell_count: number;
  /** The box of the cell centres. */
  bounds: { lat_min: number; lng_min: number; lat_max: number; lng_max: number };
  /** The build-scope seed values the pack was built with. */
  kernel: Record<string, unknown>;
  segments: string[];
  /** 50 names: `c_day_<seg>` x 16, `c_eve_<seg>` x 16, `n_<seg>` x 16, `r_day`, `r_eve`. */
  columns: string[];
  quant: { type: string; levels: number };
  /** The largest value of each column: the decode scale. */
  scale: number[];
  sections: { name: string; type: string; layout?: string; offset: number; count: number }[];
  vintages: { census_reference_date: string; lodes_year: number; osm_snapshot_date: string };
  attribution: string[];
}

/** A decoded cell pack. It feeds colours only: no number printed anywhere comes from it. */
export interface CellPack {
  header: CellPackHeader;
  /** Number of cells. */
  n: number;
  /** Features per cell (50). */
  k: number;
  /** H3 ids as 15-character strings, ascending. */
  ids: string[];
  /** Row-major: the `k` features of cell `i` start at `i * k`. */
  features: Float32Array;
}

export type PackErrorCode = 'bad_magic' | 'bad_version' | 'bad_header' | 'bad_length' | 'bad_columns';

/** What `decodePack` throws. `code` says which part of the file is wrong. */
export class PackError extends Error {
  readonly code: PackErrorCode;

  constructor(code: PackErrorCode, message: string) {
    super(message);
    this.name = 'PackError';
    this.code = code;
  }
}

/** "TPCP", read big-endian. */
export const PACK_MAGIC = 0x54504350;
export const PACK_FORMAT_VERSION = 1;
/** Bytes before the JSON header. */
export const PACK_PREFIX_BYTES = 12;
/** The largest code of the quantisation. */
export const PACK_LEVELS = 65535;

function isWhole(x: unknown): x is number {
  return typeof x === 'number' && Number.isFinite(x) && Math.floor(x) === x;
}

function isFiniteNumber(x: unknown): x is number {
  return typeof x === 'number' && Number.isFinite(x);
}

/** Where the two sections start: the first multiple of 8 at or after the end of the header. */
export function packDataOffset(headerBytes: number): number {
  return PACK_PREFIX_BYTES + headerBytes + ((8 - ((PACK_PREFIX_BYTES + headerBytes) % 8)) % 8);
}

function readHeader(bytes: Uint8Array): CellPackHeader {
  let parsed: unknown;
  try {
    parsed = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
  } catch {
    throw new PackError('bad_header', 'The pack header is not valid JSON.');
  }
  if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
    throw new PackError('bad_header', 'The pack header is not an object.');
  }
  const h = parsed as Record<string, unknown>;
  if (!isWhole(h.cell_count) || h.cell_count < 0) {
    throw new PackError('bad_header', 'The pack header has no cell count.');
  }
  if (!Array.isArray(h.columns) || !h.columns.every((name) => typeof name === 'string')) {
    throw new PackError('bad_header', 'The pack header has no column list.');
  }
  if (
    !Array.isArray(h.scale) ||
    h.scale.length !== h.columns.length ||
    !h.scale.every((s) => isFiniteNumber(s) && s >= 0)
  ) {
    throw new PackError('bad_header', 'The pack header has no scale for every column.');
  }
  if (!isWhole(h.h3_res) || h.h3_res < 0 || h.h3_res > 15) {
    throw new PackError('bad_header', 'The pack header has no hexagon resolution.');
  }
  if (typeof h.model_version !== 'string' || typeof h.dataset_version !== 'string') {
    throw new PackError('bad_header', 'The pack header has no versions.');
  }
  const b = h.bounds as Record<string, unknown> | null | undefined;
  if (
    typeof b !== 'object' ||
    b === null ||
    !isFiniteNumber(b.lat_min) ||
    !isFiniteNumber(b.lat_max) ||
    !isFiniteNumber(b.lng_min) ||
    !isFiniteNumber(b.lng_max)
  ) {
    throw new PackError('bad_header', 'The pack header has no bounds.');
  }
  return parsed as CellPackHeader;
}

/**
 * Decode a cell pack. Throws `PackError`:
 *
 *   bad_magic    not a pack at all (an error page, an empty answer, a cut-off download)
 *   bad_version  a pack of another format version
 *   bad_header   the JSON header cannot be read or lacks what the map needs
 *   bad_columns  the feature columns are not the 50 the map's scorer expects, in its order
 *   bad_length   the file is not as long as its header says
 *
 * All reads go through a DataView with explicit little-endian, so the result is the same on any
 * machine. Nothing is allocated before the length has been checked.
 */
export function decodePack(buf: ArrayBuffer): CellPack {
  let dv: DataView;
  try {
    dv = new DataView(buf);
  } catch {
    throw new PackError('bad_magic', 'Not a cell pack.');
  }
  const total = dv.byteLength;
  if (total < 4 || dv.getUint32(0, false) !== PACK_MAGIC) {
    throw new PackError('bad_magic', 'Not a cell pack.');
  }
  if (total < 6 || dv.getUint16(4, true) !== PACK_FORMAT_VERSION) {
    throw new PackError('bad_version', 'Unsupported cell pack version.');
  }
  if (total < PACK_PREFIX_BYTES) {
    throw new PackError('bad_header', 'The pack ends before its header.');
  }
  const headerBytes = dv.getUint32(8, true);
  if (PACK_PREFIX_BYTES + headerBytes > total) {
    throw new PackError('bad_header', 'The pack ends before its header.');
  }
  const header = readHeader(new Uint8Array(buf, PACK_PREFIX_BYTES, headerBytes));

  const n = header.cell_count;
  const k = header.columns.length;
  let columnsOk = k === FEATURE_COLUMNS.length;
  for (let j = 0; columnsOk && j < k; j++) columnsOk = header.columns[j] === FEATURE_COLUMNS[j];
  if (!columnsOk) {
    throw new PackError('bad_columns', 'The pack does not hold the 50 feature columns the map reads.');
  }

  const dataOffset = packDataOffset(headerBytes);
  if (total !== dataOffset + 8 * n + 2 * n * k) {
    throw new PackError('bad_length', 'The pack is not as long as its header says.');
  }

  // Ids: two u32 per cell, low word first; the id is the 15 hexadecimal characters of the u64.
  const ids = new Array<string>(n);
  for (let i = 0; i < n; i++) {
    const lo = dv.getUint32(dataOffset + 8 * i, true);
    const hi = dv.getUint32(dataOffset + 8 * i + 4, true);
    ids[i] = hi.toString(16) + lo.toString(16).padStart(8, '0');
  }

  // Features: column-major codes in the file, row-major numbers for the scorer.
  const featuresOffset = dataOffset + 8 * n;
  const features = new Float32Array(n * k);
  for (let j = 0; j < k; j++) {
    const s = header.scale[j] / (PACK_LEVELS * PACK_LEVELS);
    let at = featuresOffset + 2 * j * n;
    let to = j;
    for (let i = 0; i < n; i++) {
      const q = dv.getUint16(at, true);
      features[to] = s * q * q;
      at += 2;
      to += k;
    }
  }

  return { header, n, k, ids, features };
}
