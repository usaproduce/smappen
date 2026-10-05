// Truck Planner estimator - the map's bulk scorer (02_MODEL 4.17).
//
// The map recolours every cell on every tick of the hour control, so this file scores cells straight
// out of the pack's Float32Array into buffers the caller owns: nothing is allocated per call. For the
// same feature values the sums are the same doubles as cellScores (the definition, in mapRows.ts);
// only the store into a Float32Array rounds, which is inside the tolerance 02_MODEL 4.17 sets for
// this path (scores to a relative 1e-5, colour bytes to plus or minus 1).
//
// Feature matrix: row-major, 50 numbers per cell in pack column order (03_DATA section 11):
//   0..15  capture.day by segment      c_day_<segment>
//   16..31 capture.eve by segment      c_eve_<segment>
//   32..47 nearby by segment           n_<segment>
//   48     rivals.day                  r_day
//   49     rivals.eve                  r_eve

import { NSEG, SEGMENTS, modFloor, nonZero } from './core';
import { dayWeightRows, mapWeightRows } from './mapRows';
import { SEEDS, seed } from './seeds';
import type {
  Assumptions,
  CalibrationState,
  DayContext,
  MapDomain,
  MapLayer,
  Regime,
  TruckProfile,
} from './types';

export { cellScores, dayWeightRows, mapWeightRows, scoreByte } from './mapRows';

/** Numbers per cell in the feature matrix. */
export const FEATURES_PER_CELL = 50;
/** First of the 16 capture.day columns. */
export const COL_CAPTURE_DAY = 0;
/** First of the 16 capture.eve columns. */
export const COL_CAPTURE_EVE = 16;
/** First of the 16 nearby columns. */
export const COL_NEARBY = 32;
/** The rivals.day column. */
export const COL_RIVALS_DAY = 48;
/** The rivals.eve column. */
export const COL_RIVALS_EVE = 49;

function columnNames(): string[] {
  const names: string[] = [];
  for (let s = 0; s < NSEG; s++) names.push('c_day_' + SEGMENTS[s]);
  for (let s = 0; s < NSEG; s++) names.push('c_eve_' + SEGMENTS[s]);
  for (let s = 0; s < NSEG; s++) names.push('n_' + SEGMENTS[s]);
  names.push('r_day');
  names.push('r_eve');
  return names;
}

/** Names of the 50 feature columns, in order, as the cell pack header lists them. */
export const FEATURE_COLUMNS: readonly string[] = columnNames();

/** The three map layers, in the order scoreCells writes them. */
export const MAP_LAYERS: readonly MapLayer[] = ['opportunity', 'people', 'competition'];

/**
 * The fixed colour domain: the score that maps to byte 255 on each layer (seeds map.*_hi). It is the
 * same for all 168 hours, all regions and all trucks, so a colour always means the same number.
 */
export const MAP_DOMAIN: Readonly<MapDomain> = Object.freeze({
  opportunity: SEEDS.map.opportunity_hi.value,
  people: SEEDS.map.people_hi.value,
  competition: SEEDS.map.competition_hi.value,
});

/** Hours in the weight table: one row per hour of the week. */
export const HOURS_PER_WEEK = 168;

/** The 168 per-hour weight rows as flat typed arrays, ready for scoreCells and scoreLayer. */
export interface MapWeightTable {
  /** 168 x 16, row-major: the opportunity weight of segment s in hour-of-week how is wOpp[how * 16 + s]. */
  wOpp: Float64Array;
  /** 168 x 16, row-major: the presence of segment s in hour-of-week how is wPeople[how * 16 + s]. */
  wPeople: Float64Array;
  /** 168 entries: 0 where the hour's competition regime is day, 1 where it is eve. */
  eve: Uint8Array;
}

/**
 * Precompute the 168 per-hour weight rows from seeds, profile and calibration.
 *
 * Without a context this is the typical week: exactly the numbers of mapWeightRows(A, profile, cal).
 * With the context of a selected date, the 24 rows of that date's day of the week
 * (ctx.dow * 24 .. ctx.dow * 24 + 23) are built from that context instead (its holiday pattern, its
 * "treat this day as"), as dayWeightRows does; the other 144 rows stay the typical week. Either way
 * the row of an hour is found at how = dow * 24 + hour. The rows depend on A, on profile.daypart_fit,
 * on the truck factor of cal and on ctx, and on nothing else: recompute when one of those changes.
 */
export function precomputeMapWeights(
  A: Assumptions,
  profile: TruckProfile,
  cal: CalibrationState | null,
  ctx: DayContext | null = null,
): MapWeightTable {
  const week = mapWeightRows(A, profile, cal);
  const wOpp = new Float64Array(HOURS_PER_WEEK * NSEG);
  const wPeople = new Float64Array(HOURS_PER_WEEK * NSEG);
  const eve = new Uint8Array(HOURS_PER_WEEK);
  const regimeOfHour = seed<Regime[]>(A, 'hours.regime_of_hour');
  for (let how = 0; how < HOURS_PER_WEEK; how++) {
    for (let s = 0; s < NSEG; s++) {
      wOpp[how * NSEG + s] = week.w_opp[how][s];
      wPeople[how * NSEG + s] = week.w_people[how][s];
    }
    eve[how] = regimeOfHour[modFloor(how, 24)] === 'day' ? 0 : 1;
  }
  if (ctx != null) {
    const day = dayWeightRows(A, profile, cal, ctx);
    for (let hour = 0; hour < 24; hour++) {
      const how = ctx.dow * 24 + hour;
      for (let s = 0; s < NSEG; s++) {
        wOpp[how * NSEG + s] = day.w_opp[hour][s];
        wPeople[how * NSEG + s] = day.w_people[hour][s];
      }
    }
  }
  return { wOpp, wPeople, eve };
}

// out[outOffset + c] = min(sum over s of features[50 * c + column + s] * w[wOffset + s], cap) for the
// first n cells: sixteen products added left to right from 0.0, exactly as cellScores adds them.
function weightedSums(
  features: Float32Array,
  n: number,
  column: number,
  w: ArrayLike<number>,
  wOffset: number,
  cap: number,
  out: Float32Array,
  outOffset: number,
): void {
  const w0 = w[wOffset];
  const w1 = w[wOffset + 1];
  const w2 = w[wOffset + 2];
  const w3 = w[wOffset + 3];
  const w4 = w[wOffset + 4];
  const w5 = w[wOffset + 5];
  const w6 = w[wOffset + 6];
  const w7 = w[wOffset + 7];
  const w8 = w[wOffset + 8];
  const w9 = w[wOffset + 9];
  const w10 = w[wOffset + 10];
  const w11 = w[wOffset + 11];
  const w12 = w[wOffset + 12];
  const w13 = w[wOffset + 13];
  const w14 = w[wOffset + 14];
  const w15 = w[wOffset + 15];
  let b = column;
  for (let c = 0; c < n; c++) {
    const o =
      0.0 +
      features[b] * w0 +
      features[b + 1] * w1 +
      features[b + 2] * w2 +
      features[b + 3] * w3 +
      features[b + 4] * w4 +
      features[b + 5] * w5 +
      features[b + 6] * w6 +
      features[b + 7] * w7 +
      features[b + 8] * w8 +
      features[b + 9] * w9 +
      features[b + 10] * w10 +
      features[b + 11] * w11 +
      features[b + 12] * w12 +
      features[b + 13] * w13 +
      features[b + 14] * w14 +
      features[b + 15] * w15;
    out[outOffset + c] = cap < o ? cap : o;
    b += 50;
  }
}

// out[outOffset + c] = features[50 * c + column] for the first n cells.
function copyColumn(features: Float32Array, n: number, column: number, out: Float32Array, outOffset: number): void {
  let b = column;
  for (let c = 0; c < n; c++) {
    out[outOffset + c] = features[b];
    b += 50;
  }
}

function checkCells(features: Float32Array, n: number, out: Float32Array, perCell: number): void {
  if (!(n >= 0) || Math.floor(n) !== n) throw new RangeError('fastPath: n must be a whole number >= 0');
  if (features.length < n * 50) throw new RangeError('fastPath: features holds fewer than 50 numbers per cell');
  if (out.length < n * perCell) throw new RangeError('fastPath: out is too short');
}

function checkHour(how: number): void {
  if (!(how >= 0 && how < HOURS_PER_WEEK) || Math.floor(how) !== how) {
    throw new RangeError('fastPath: how must be a whole number 0..167');
  }
}

/**
 * The typed form of cellScores: score n cells for one hour given that hour's two weight rows, its
 * regime and the capacity. Writes three planes into `out` (length >= 3 * n): opportunity at
 * out[0 .. n), people at out[n .. 2n), competition at out[2n .. 3n). Nothing is allocated.
 */
export function scoreCellsWithRows(
  features: Float32Array,
  n: number,
  wOppRow: ArrayLike<number>,
  wPeopleRow: ArrayLike<number>,
  regime: Regime,
  capacity: number,
  out: Float32Array,
): void {
  checkCells(features, n, out, 3);
  const day = regime === 'day';
  weightedSums(features, n, day ? 0 : 16, wOppRow, 0, capacity, out, 0);
  weightedSums(features, n, 32, wPeopleRow, 0, Infinity, out, n);
  copyColumn(features, n, day ? 48 : 49, out, 2 * n);
}

/**
 * Score n cells for hour-of-week `how` (0..167) on all three layers. Writes three planes into `out`
 * (length >= 3 * n): opportunity at out[0 .. n), people at out[n .. 2n), competition at
 * out[2n .. 3n). Opportunity is capped at `capacity` (profile.capacity_orders_per_hour). Nothing is
 * allocated.
 */
export function scoreCells(
  features: Float32Array,
  n: number,
  table: MapWeightTable,
  how: number,
  capacity: number,
  out: Float32Array,
): void {
  checkCells(features, n, out, 3);
  checkHour(how);
  const day = table.eve[how] === 0;
  weightedSums(features, n, day ? 0 : 16, table.wOpp, how * 16, capacity, out, 0);
  weightedSums(features, n, 32, table.wPeople, how * 16, Infinity, out, n);
  copyColumn(features, n, day ? 48 : 49, out, 2 * n);
}

/**
 * Score n cells for hour-of-week `how` (0..167) on one layer: out[c] is the score of cell c
 * (length >= n). The same numbers as the matching plane of scoreCells; `capacity`
 * (profile.capacity_orders_per_hour) caps the opportunity layer and is not read for the other two.
 * Nothing is allocated.
 */
export function scoreLayer(
  layer: MapLayer,
  features: Float32Array,
  n: number,
  table: MapWeightTable,
  how: number,
  capacity: number,
  out: Float32Array,
): void {
  checkCells(features, n, out, 1);
  checkHour(how);
  const day = table.eve[how] === 0;
  if (layer === 'opportunity') {
    weightedSums(features, n, day ? 0 : 16, table.wOpp, how * 16, capacity, out, 0);
  } else if (layer === 'people') {
    weightedSums(features, n, 32, table.wPeople, how * 16, Infinity, out, 0);
  } else {
    copyColumn(features, n, day ? 48 : 49, out, 0);
  }
}

/**
 * Colour bytes of n scores on the fixed square-root scale: out[c] = scoreByte(scores[offset + c], hi),
 * or 0 when that byte is below minByte (the "no colour" floor of a layer). `hi` is the layer's entry
 * of MAP_DOMAIN. Nothing is allocated.
 */
export function scoresToBytes(
  scores: Float32Array,
  n: number,
  hi: number,
  out: Uint8Array,
  minByte: number = 0,
  offset: number = 0,
): void {
  if (scores.length < offset + n || out.length < n) throw new RangeError('fastPath: buffer too short');
  nonZero(hi, 'hi');
  for (let c = 0; c < n; c++) {
    const x = scores[offset + c] / hi;
    const t = x < 0.0 ? 0.0 : x > 1.0 ? 1.0 : x;
    const byte = Math.floor(255.0 * Math.sqrt(t) + 0.5);
    out[c] = byte < minByte ? 0 : byte;
  }
}
