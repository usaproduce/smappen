// Job review rule and corrections (03_DATA.md 4.2 to 4.4).
// LODES reports some employers at one payroll address. Flagged blocks are capped, spread over their county
// or kept, as the committed corrections file says; a flagged block without an entry gets the automatic cap.

import fs from 'node:fs';
import crypto from 'node:crypto';
import { SECTORS } from './vocabulary.mjs';

export class CorrectionsError extends Error {}

export const TREATMENT_NONE = 0;
export const TREATMENT_KEEP = 1;
export const TREATMENT_CAP = 2;
export const TREATMENT_SPREAD = 3;
export const TREATMENT_DROP = 4;
export const TREATMENT_AUTO_SPREAD = 5;
export const TREATMENT_NAMES = Object.freeze(['', 'keep', 'cap', 'spread', 'drop', 'auto_spread']);

const ACTIONS = { keep: TREATMENT_KEEP, cap: TREATMENT_CAP, spread: TREATMENT_SPREAD, drop: TREATMENT_DROP };
const STALE_SHARE = 0.25;

function bad(message) {
  throw new CorrectionsError(`corrections file: ${message}`);
}

/**
 * Validates a parsed corrections document.
 * @param {object} doc
 * @param {string} regionId
 * @returns {Map<string, {action: string, treatment: number, cap: number|null, sectors: number[]|null, reason: string,
 *           c000AtReview: number, reviewed: string, confirmed: boolean}>} entries keyed by block GEOID
 */
export function validateCorrections(doc, regionId) {
  if (doc === null || typeof doc !== 'object' || Array.isArray(doc)) bad('must be a JSON object');
  if (doc.schema !== 1) bad('schema must be 1');
  if (doc.region !== regionId) bad(`region is ${JSON.stringify(doc.region)}, expected ${JSON.stringify(regionId)}`);
  if (typeof doc.version !== 'string' || doc.version === '') bad('version must be a non-empty string');
  if (doc.lodes === null || typeof doc.lodes !== 'object') bad('lodes must be an object {year, job_type, vintage}');
  if (doc.entries === null || typeof doc.entries !== 'object' || Array.isArray(doc.entries)) bad('entries must be an object keyed by block GEOID');

  const entries = new Map();
  for (const [geoid, e] of Object.entries(doc.entries)) {
    const at = `entries.${geoid}`;
    if (!/^\d{15}$/.test(geoid)) bad(`${at}: the key must be a 15-digit block GEOID`);
    if (e === null || typeof e !== 'object' || Array.isArray(e)) bad(`${at} must be an object`);
    if (!Object.prototype.hasOwnProperty.call(ACTIONS, e.action)) bad(`${at}.action must be keep, cap, spread or drop`);
    let cap = null;
    if (e.action === 'cap') {
      if (typeof e.cap !== 'number' || !Number.isFinite(e.cap) || e.cap < 0) bad(`${at}.cap is required for action cap and must be >= 0`);
      cap = e.cap;
    } else if (e.action === 'spread') {
      if ('cap' in e && (typeof e.cap !== 'number' || !Number.isFinite(e.cap) || e.cap < 0)) bad(`${at}.cap must be a number >= 0`);
      cap = 'cap' in e ? e.cap : 0;
    } else if (e.action === 'drop') {
      if ('cap' in e) bad(`${at}.cap is not allowed for action drop`);
      cap = 0;
    } else if ('cap' in e) {
      bad(`${at}.cap is not allowed for action keep`);
    }
    let sectors = null;
    if ('sectors' in e) {
      if (e.action === 'keep') bad(`${at}.sectors is not allowed for action keep`);
      if (!Array.isArray(e.sectors) || e.sectors.length === 0) bad(`${at}.sectors must be a non-empty list such as ["CNS15"]`);
      sectors = [];
      for (const name of e.sectors) {
        const index = SECTORS.indexOf(name);
        if (index < 0) bad(`${at}.sectors holds ${JSON.stringify(name)}, expected CNS01..CNS20`);
        if (sectors.includes(index)) bad(`${at}.sectors lists ${name} twice`);
        sectors.push(index);
      }
      sectors.sort((a, b) => a - b);
    }
    if (typeof e.reason !== 'string' || e.reason.trim() === '') bad(`${at}.reason is required`);
    if (!Number.isInteger(e.c000_at_review) || e.c000_at_review < 0) bad(`${at}.c000_at_review is required and must be a whole number`);
    if (typeof e.reviewed !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(e.reviewed)) bad(`${at}.reviewed is required (YYYY-MM-DD)`);
    if ('confirmed' in e && typeof e.confirmed !== 'boolean') bad(`${at}.confirmed must be true or false`);
    entries.set(geoid, {
      action: e.action,
      treatment: ACTIONS[e.action],
      cap,
      sectors,
      reason: e.reason,
      c000AtReview: e.c000_at_review,
      reviewed: e.reviewed,
      confirmed: e.confirmed !== false,
    });
  }
  return entries;
}

/**
 * Loads the corrections file of a region.
 * @returns {{version: string, sha256: string, entries: ReturnType<typeof validateCorrections>, path: string}}
 */
export function loadCorrections(filePath, regionId) {
  let bytes;
  try {
    bytes = fs.readFileSync(filePath);
  } catch (err) {
    throw new CorrectionsError(`corrections file: cannot read ${filePath}: ${err.message}`);
  }
  let doc;
  try {
    doc = JSON.parse(bytes.toString('utf8'));
  } catch (err) {
    throw new CorrectionsError(`corrections file: ${filePath} is not valid JSON: ${err.message}`);
  }
  const entries = validateCorrections(doc, regionId);
  return { version: doc.version, sha256: crypto.createHash('sha256').update(bytes).digest('hex'), entries, path: filePath };
}

/**
 * Review rule on raw counts: 'A', 'B', 'AB' or '' (03_DATA.md 4.2).
 * @param {number} c000 all jobs of the block
 * @param {number} maxSector largest single sector count
 * @param {{total_min: number, single_sector_min: number, single_sector_share: number}} thresholds
 */
export function reviewRule(c000, maxSector, thresholds) {
  const a = c000 >= thresholds.total_min;
  const b = c000 >= thresholds.single_sector_min && c000 > 0 && maxSector / c000 >= thresholds.single_sector_share;
  return (a ? 'A' : '') + (b ? 'B' : '');
}

/** The automatic cap of a flagged block: the single-sector threshold under rule B, else the total threshold. */
export function automaticCap(rule, thresholds) {
  return rule.includes('B') ? thresholds.single_sector_min : thresholds.total_min;
}

function maxOf20(values, offset) {
  let m = 0;
  for (let i = 0; i < 20; i++) if (values[offset + i] > m) m = values[offset + i];
  return m;
}

function sumOf20(values, offset) {
  let s = 0;
  for (let i = 0; i < 20; i++) s += values[offset + i];
  return s;
}

/**
 * Applies the corrections to the region's blocks.
 *
 * @param {object} input
 * @param {string[]} input.geoids region block GEOIDs in ascending order
 * @param {Int32Array} input.residents POP100 per block
 * @param {Int32Array} input.c000 C000 per block (0 when the block has no WAC row)
 * @param {Int32Array} input.raw 20 raw sector counts per block (zeros when the block has no WAC row)
 * @param {Uint8Array} input.hasWac 1 when the block has a WAC row
 * @param {Map<string, object>} input.entries validated corrections entries
 * @param {{total_min: number, single_sector_min: number, single_sector_share: number}} input.thresholds
 * @returns corrected sectors, per-block treatment facts and totals
 */
export function applyCorrections({ geoids, residents, c000, raw, hasWac, entries, thresholds }) {
  const n = geoids.length;
  const corrected = Float64Array.from(raw);
  const rule = new Array(n).fill('');
  const treatment = new Uint8Array(n);
  const cap = new Float64Array(n).fill(NaN);
  const reduced = new Uint8Array(n);
  const jobsSpread = new Float64Array(n);
  const jobsDiscarded = new Float64Array(n);
  const jobsLost = new Float64Array(n);
  const entryState = new Array(n).fill('');
  const discardedBySector = new Float64Array(20);

  const indexOf = new Map();
  for (let i = 0; i < n; i++) indexOf.set(geoids[i], i);

  // Entries that cannot be applied: the GEOID is outside the region or the block has no WAC row.
  const orphans = [];
  for (const geoid of entries.keys()) {
    const i = indexOf.get(geoid);
    if (i === undefined || !hasWac[i]) orphans.push(geoid);
  }
  orphans.sort();

  let flaggedBlocks = 0;
  let flaggedJobs = 0;
  let autoBlocks = 0;
  let autoJobs = 0;
  let staleEntries = 0;
  let unconfirmedEntries = 0;
  const spreads = []; // {i, excess: Float64Array(20)} in ascending GEOID order

  for (let i = 0; i < n; i++) {
    const o = i * 20;
    const total = hasWac[i] ? c000[i] : 0;
    const r = hasWac[i] ? reviewRule(total, maxOf20(raw, o), thresholds) : '';
    rule[i] = r;
    if (r !== '') { flaggedBlocks++; flaggedJobs += total; }

    const entry = hasWac[i] ? entries.get(geoids[i]) : undefined;
    let sectors = null;
    if (entry) {
      treatment[i] = entry.treatment;
      if (entry.cap !== null) cap[i] = entry.cap;
      sectors = entry.sectors;
      const stale = entry.c000AtReview === 0
        ? total !== 0
        : Math.abs(total - entry.c000AtReview) / entry.c000AtReview > STALE_SHARE;
      entryState[i] = stale ? 'stale' : 'ok';
      if (stale) staleEntries++;
      if (!entry.confirmed) unconfirmedEntries++;
    } else if (r !== '') {
      treatment[i] = TREATMENT_AUTO_SPREAD;
      cap[i] = automaticCap(r, thresholds);
      autoBlocks++;
      autoJobs += total;
    }

    const t = treatment[i];
    if (t === TREATMENT_NONE || t === TREATMENT_KEEP) continue;

    // Reduction (4.4 step 1).
    let cur = 0;
    if (sectors) for (const s of sectors) cur += raw[o + s];
    else cur = sumOf20(raw, o);
    const keepJobs = Math.min(cap[i], cur);
    const f = cur > 0 ? keepJobs / cur : 0;
    const excess = new Float64Array(20);
    let excessTotal = 0;
    const reduceSector = (s) => {
      const value = raw[o + s];
      corrected[o + s] = value * f;
      excess[s] = value * (1 - f);
      excessTotal += excess[s];
    };
    if (sectors) for (const s of sectors) reduceSector(s);
    else for (let s = 0; s < 20; s++) reduceSector(s);
    if (excessTotal > 0) reduced[i] = 1;
    if (t === TREATMENT_SPREAD || t === TREATMENT_AUTO_SPREAD) {
      spreads.push({ i, excess, excessTotal });
    } else {
      jobsDiscarded[i] = excessTotal;
      for (let s = 0; s < 20; s++) discardedBySector[s] += excess[s];
    }
  }

  // Spread (4.4 step 3): receivers are the county's blocks with residents that carry no reducing treatment.
  const byCounty = new Map();
  for (let i = 0; i < n; i++) {
    if (residents[i] <= 0) continue;
    const t = treatment[i];
    if (t !== TREATMENT_NONE && t !== TREATMENT_KEEP) continue;
    const county = geoids[i].slice(0, 5);
    let list = byCounty.get(county);
    if (!list) byCounty.set(county, list = { blocks: [], population: 0 });
    list.blocks.push(i);
    list.population += residents[i];
  }
  for (const { i, excess, excessTotal } of spreads) {
    if (excessTotal <= 0) continue;
    const receivers = byCounty.get(geoids[i].slice(0, 5));
    if (!receivers || receivers.population <= 0) {
      jobsLost[i] = excessTotal;
      for (let s = 0; s < 20; s++) discardedBySector[s] += excess[s];
      continue;
    }
    jobsSpread[i] = excessTotal;
    const population = receivers.population;
    for (const r of receivers.blocks) {
      const o = r * 20;
      const pop = residents[r];
      for (let s = 0; s < 20; s++) {
        if (excess[s] !== 0) corrected[o + s] += excess[s] * pop / population;
      }
    }
  }

  let totalSpread = 0;
  let totalDiscarded = 0;
  let totalLost = 0;
  let blocksAdjusted = 0;
  for (let i = 0; i < n; i++) {
    totalSpread += jobsSpread[i];
    totalDiscarded += jobsDiscarded[i];
    totalLost += jobsLost[i];
    if (jobsSpread[i] > 0) blocksAdjusted++;
  }

  return {
    corrected,
    rule,
    treatment,
    cap,
    reduced,
    jobsSpread,
    jobsDiscarded,
    jobsLost,
    entryState,
    orphans,
    discardedBySector,
    totals: {
      flaggedBlocks,
      flaggedJobs,
      autoBlocks,
      autoJobs,
      staleEntries,
      orphanEntries: orphans.length,
      unconfirmedEntries,
      jobsSpread: totalSpread,
      jobsDiscarded: totalDiscarded,
      jobsSpreadLost: totalLost,
      blocksAdjusted,
    },
  };
}

/**
 * Halo rule (4.4 step 5): a halo block that meets rule A or B is cut to the automatic cap, the excess discarded.
 * @param {ArrayLike<number>} raw 20 raw sector counts at `offset`
 * @param {number} offset
 * @param {Float64Array} out receives the 20 corrected values
 * @returns {number} jobs discarded (0 when the block is not flagged)
 */
export function capHaloBlock(raw, offset, thresholds, out) {
  const c000 = sumOf20(raw, offset);
  const r = reviewRule(c000, maxOf20(raw, offset), thresholds);
  if (r === '') {
    for (let s = 0; s < 20; s++) out[s] = raw[offset + s];
    return 0;
  }
  const f = Math.min(automaticCap(r, thresholds), c000) / c000;
  let discarded = 0;
  for (let s = 0; s < 20; s++) {
    out[s] = raw[offset + s] * f;
    discarded += raw[offset + s] * (1 - f);
  }
  return discarded;
}
