// LEHD LODES 8 readers: workplace area characteristics (WAC), the block crosswalk, version.txt and the
// checksum list (03_DATA.md 2.2).

import { scanCsv } from './csv.mjs';
import { CNS04_INDEX, SECTORS, WORKER_SEGMENTS } from './vocabulary.mjs';

export class LodesError extends Error {}

export const WAC_COLUMN_COUNT = 53;
export const XWALK_COLUMN_COUNT = 41;
const WAC_CNS_FIRST = 8; // zero-based index of CNS01
const XWALK_COLUMNS = [0, 5, 15, 35]; // tabblk2020, ctyname, stplcname, milname

/**
 * Parses version.txt.
 * @param {string} text
 * @returns {{format: string|null, vintage: string|null}}
 */
export function parseLodesVersion(text) {
  const format = /Release Format Version\s+(\S+)/.exec(text);
  const vintage = /Data Vintage:\s*(\S+)/.exec(text);
  return { format: format ? format[1] : null, vintage: vintage ? vintage[1] : null };
}

/**
 * Parses `lodes_{usps}.sha256sum`: lines of `<64 hex><two spaces><file name without .gz>`.
 * @param {string} text
 * @returns {Map<string, string>} file name -> lower-case hex digest
 */
export function parseSha256Sums(text) {
  const out = new Map();
  for (const line of text.split('\n')) {
    const m = /^([0-9a-fA-F]{64})\s+\*?(\S.*?)\s*$/.exec(line);
    if (m) out.set(m[2], m[1].toLowerCase());
  }
  return out;
}

/**
 * Reads a WAC file.
 * @param {Buffer} csv uncompressed CSV bytes
 * @returns {{geoids: string[], c000: Int32Array, cns: Int32Array, rows: number, sumMismatches: number}}
 *          `cns` holds 20 values per row (CNS01..CNS20). `sumMismatches` counts rows where they do not add to C000.
 */
export function readWac(csv) {
  const geoids = [];
  const c000 = [];
  const cns = [];
  let sumMismatches = 0;
  const columns = [0, 1];
  for (let i = 0; i < 20; i++) columns.push(WAC_CNS_FIRST + i);
  const { header, records } = scanCsv(csv, columns, (values, fieldCount, recordNumber) => {
    if (fieldCount !== WAC_COLUMN_COUNT) {
      throw new LodesError(`wac: record ${recordNumber} has ${fieldCount} fields, expected ${WAC_COLUMN_COUNT}`);
    }
    const geoid = values[0];
    if (!/^\d{15}$/.test(geoid)) throw new LodesError(`wac: record ${recordNumber} has a bad w_geocode ${JSON.stringify(geoid)}`);
    let sum = 0;
    for (let i = 0; i < 20; i++) {
      const text = values[2 + i];
      if (!/^\d+$/.test(text)) throw new LodesError(`wac: block ${geoid} has a non-numeric ${SECTORS[i]}: ${JSON.stringify(text)}`);
      const v = Number(text);
      cns.push(v);
      sum += v;
    }
    if (!/^\d+$/.test(values[1])) throw new LodesError(`wac: block ${geoid} has a non-numeric C000`);
    const total = Number(values[1]);
    if (sum !== total) sumMismatches++;
    geoids.push(geoid);
    c000.push(total);
  });
  checkWacHeader(header);
  return { geoids, c000: Int32Array.from(c000), cns: Int32Array.from(cns), rows: records, sumMismatches };
}

/** Throws unless the header is the 53-column LODES 8 WAC header. */
export function checkWacHeader(header) {
  const ok = header.length === WAC_COLUMN_COUNT
    && header[0] === 'w_geocode' && header[1] === 'C000' && header[2] === 'CA01'
    && SECTORS.every((name, i) => header[WAC_CNS_FIRST + i] === name);
  if (!ok) {
    throw new LodesError(`wac: unexpected header (${header.length} columns, starts ${header.slice(0, 3).join(',')}): `
      + 'expected 53 columns starting w_geocode,C000,CA01 with CNS01 at position 9 and CNS20 at position 28');
  }
}

/** Throws unless the header is the 41-column LODES 8 crosswalk header. */
export function checkXwalkHeader(header) {
  const ok = header.length === XWALK_COLUMN_COUNT && header[0] === 'tabblk2020'
    && header[5] === 'ctyname' && header[15] === 'stplcname' && header[35] === 'milname';
  if (!ok) {
    throw new LodesError(`xwalk: unexpected header (${header.length} columns, starts ${header.slice(0, 1).join(',')}): `
      + 'expected 41 columns starting tabblk2020 with ctyname, stplcname and milname at positions 6, 16 and 36');
  }
}

/**
 * Streams the crosswalk: calls onBlock(geoid, ctyname, stplcname, milname) per row.
 * @param {Buffer} csv uncompressed CSV bytes
 * @returns {{rows: number}}
 */
export function scanXwalk(csv, onBlock) {
  const { header, records } = scanCsv(csv, XWALK_COLUMNS, (values, fieldCount, recordNumber) => {
    if (fieldCount !== XWALK_COLUMN_COUNT) {
      throw new LodesError(`xwalk: record ${recordNumber} has ${fieldCount} fields, expected ${XWALK_COLUMN_COUNT}`);
    }
    onBlock(values[0], values[1], values[2], values[3]);
  });
  checkXwalkHeader(header);
  return { rows: records };
}

/**
 * Compiles the seed grouping of sectors into segments.
 * @param {Record<string, string[]>} segmentCns seed lists per worker segment
 * @param {number} cns04Weight weight of CNS04 inside its segment
 * @returns {{segmentOfSector: Int8Array, weightOfSector: Float64Array}} worker-segment index 0..6 per sector
 *          (0 = w_office ... 6 = w_public, the order of WORKER_SEGMENTS) and the weight each sector enters with
 */
export function compileSegmentGrouping(segmentCns, cns04Weight) {
  const segmentOfSector = new Int8Array(20).fill(-1);
  const weightOfSector = new Float64Array(20).fill(1);
  WORKER_SEGMENTS.forEach((segment, w) => {
    for (const sector of segmentCns[segment]) segmentOfSector[SECTORS.indexOf(sector)] = w;
  });
  for (let i = 0; i < 20; i++) if (segmentOfSector[i] < 0) throw new LodesError(`sector ${SECTORS[i]} belongs to no worker segment`);
  weightOfSector[CNS04_INDEX] = cns04Weight;
  return { segmentOfSector, weightOfSector };
}

/**
 * Sums 20 sector values into the seven worker-segment bases, sectors in ascending order.
 * @param {ArrayLike<number>} sectors 20 values starting at `offset`
 * @param {number} offset
 * @param {{segmentOfSector: Int8Array, weightOfSector: Float64Array}} grouping
 * @param {Float64Array} out receives 7 values at `outOffset`
 * @param {number} outOffset
 */
export function sectorsToSegments(sectors, offset, grouping, out, outOffset) {
  for (let w = 0; w < 7; w++) out[outOffset + w] = 0;
  for (let i = 0; i < 20; i++) {
    out[outOffset + grouping.segmentOfSector[i]] += grouping.weightOfSector[i] * sectors[offset + i];
  }
}
