// Truck Planner - the Log's accuracy tab as pure functions (docs/truck-planner/05_FRONTEND.md 4.7).
//
// No I/O, no clock, no React. The figures are the server's accuracy report (02_MODEL 4.13) and the
// calibration of the truck; this file only words them and places the marks of the chart. Nothing is
// recomputed: a sold-out service is counted, never scored, because its count is a minimum.

import { roundHalfAway } from './model';
import type { AccuracyBlock, AccuracyReport, CalibrationState, ServiceLogEntry } from './model';
import { DASH, fmtCount, fmtDay, fmtNumber, fmtPercent } from './format';
import { serviceName } from './logForm';
import { verdictWords } from './logView';

function hasKey(map: object, key: string): boolean {
  return Object.prototype.hasOwnProperty.call(map, key);
}

/** "1 service", "6 services". */
export function servicesText(n: number): string {
  return fmtCount(n) + (n === 1 ? ' service' : ' services');
}

/** A calibration factor as it is printed everywhere: "x0.96". */
export function factorText(x: number): string {
  return 'x' + fmtNumber(x, 2);
}

// -------------------------------------------------------------------------------------------------
// Bias
// -------------------------------------------------------------------------------------------------

/** Within this much either way the estimates count as on target. */
export const BIAS_ON_TARGET = 0.02;

export type BiasSide = 'high' | 'low' | 'on_target';

/** Above 0 the estimates were too high (02_MODEL 4.13). */
export function biasSide(bias: number): BiasSide {
  if (bias > BIAS_ON_TARGET) return 'high';
  if (bias < -BIAS_ON_TARGET) return 'low';
  return 'on_target';
}

function biasAmount(bias: number): string {
  return fmtPercent(bias < 0 ? -bias : bias);
}

/** A table cell: "7% high", "3% low", "On target"; the em dash without a figure. */
export function biasWords(bias: number | null): string {
  if (bias === null) return DASH;
  const side = biasSide(bias);
  if (side === 'on_target') return 'On target';
  return biasAmount(bias) + (side === 'high' ? ' high' : ' low');
}

/** "Estimates ran 7% high", "Estimates ran 3% low", "Estimates were on target". */
export function biasSentence(bias: number | null): string {
  if (bias === null) return DASH;
  const side = biasSide(bias);
  if (side === 'on_target') return 'Estimates were on target';
  return 'Estimates ran ' + biasAmount(bias) + (side === 'high' ? ' high' : ' low');
}

/** The second line of the bias tile: how the model did before the owner's results were used. */
export function rawBiasLine(rawBias: number | null): string {
  if (rawBias === null) return '';
  const side = biasSide(rawBias);
  const lead = 'Before your results were used: ';
  if (side === 'on_target') return lead + 'on target';
  return lead + biasAmount(rawBias) + (side === 'high' ? ' high' : ' low');
}

// -------------------------------------------------------------------------------------------------
// Tiles
// -------------------------------------------------------------------------------------------------

export const ACCURACY_TEXT = {
  heading: 'How the estimates are doing',
  needsScored: 'Needs a service that did not sell out.',
  needsOrders: 'Needs a scored service with at least one order.',
  missLine: 'average gap between estimate and actual',
  insideLine: 'About 8 in 10 is what the ranges aim for.',
  chartTitle: 'Estimates against actuals',
  bySpotTitle: 'By spot',
  factorsTitle: 'Your results in the model',
  factorHelp: 'Above 1 means you sell more than the generic model expects.',
  noTruckFactor: 'No logged service adjusts the truck factor yet. It stays at x1.00.',
  noSpotFactors: 'No spot has a factor of its own yet.',
  emptyTitle: 'Nothing to score yet',
  emptyBody: 'Log a few services and this page shows how close the estimates were.',
  loadFailed: 'Could not load how the estimates did.',
} as const;

/** How many scored services landed inside their range. */
export function insideCount(block: Pick<AccuracyBlock, 'coverage' | 'n_scored'>): number {
  return block.coverage === null ? 0 : roundHalfAway(block.coverage * block.n_scored, 0);
}

/** "6 of 6"; the em dash when nothing was scored. */
export function insideText(block: Pick<AccuracyBlock, 'coverage' | 'n_scored'>): string {
  if (block.coverage === null || block.n_scored === 0) return DASH;
  return fmtCount(insideCount(block)) + ' of ' + fmtCount(block.n_scored);
}

export interface AccuracyTile {
  id: 'logged' | 'bias' | 'miss' | 'inside';
  caption: string;
  /** The figure, or for the bias tile the sentence. */
  value: string;
  /** The line under it. */
  line: string;
  /** True when `value` is a sentence: it is then set smaller than a figure. */
  wordy: boolean;
}

/** The four tiles. With nothing scored, three of them show the em dash and say what is missing. */
export function accuracyTiles(report: AccuracyBlock): AccuracyTile[] {
  const logged: AccuracyTile = {
    id: 'logged',
    caption: 'Services logged',
    value: fmtCount(report.n_total),
    line: fmtCount(report.n_scored) + ' scored, ' + fmtCount(report.n_sold_out) + ' sold out',
    wordy: false,
  };
  const scored = report.n_scored > 0;
  const missing = ACCURACY_TEXT.needsScored;
  const bias: AccuracyTile =
    scored && report.bias !== null
      ? { id: 'bias', caption: 'Bias', value: biasSentence(report.bias), line: rawBiasLine(report.raw_bias), wordy: true }
      : { id: 'bias', caption: 'Bias', value: DASH, line: scored ? ACCURACY_TEXT.needsOrders : missing, wordy: false };
  const miss: AccuracyTile =
    scored && report.mape !== null
      ? { id: 'miss', caption: 'Typical miss', value: fmtPercent(report.mape), line: ACCURACY_TEXT.missLine, wordy: false }
      : { id: 'miss', caption: 'Typical miss', value: DASH, line: missing, wordy: false };
  const inside: AccuracyTile =
    scored && report.coverage !== null
      ? { id: 'inside', caption: 'Inside the range', value: insideText(report), line: ACCURACY_TEXT.insideLine, wordy: false }
      : { id: 'inside', caption: 'Inside the range', value: DASH, line: missing, wordy: false };
  return [logged, bias, miss, inside];
}

/**
 * The logged services of the range that are not in the report: "3 logged services are not scored.
 * Events, catering jobs and services without an estimate are left out."; null when every service is
 * scored. The server counts every log without a full prediction here, so an event logged from a
 * plan is among them although the history shows the figure its plan gave it: the sentence names
 * the kinds and does not say that these services have no estimate.
 */
export function unscoredNote(count: number): string | null {
  if (!(count > 0)) return null;
  const which = ' Events, catering jobs and services without an estimate are left out.';
  if (count === 1) return '1 logged service is not scored.' + which;
  return fmtCount(count) + ' logged services are not scored.' + which;
}

// -------------------------------------------------------------------------------------------------
// The chart "Estimates against actuals"
// -------------------------------------------------------------------------------------------------

/** The chart shows this many services at most: the latest ones. */
export const CHART_SERVICES = 30;

function cmp(a: string, b: string): number {
  return a < b ? -1 : a > b ? 1 : 0;
}

/**
 * The services the chart shows: the last 30 in the order they were served, by date and, within a
 * day, by opening time (lunch before dinner, as the history lists them); the id only settles a tie.
 */
export function chartEntries(entries: readonly ServiceLogEntry[]): ServiceLogEntry[] {
  const sorted = entries.slice().sort((a, b) => cmp(a.date, b.date) || a.open_minute - b.open_minute || cmp(a.service_id, b.service_id));
  return sorted.length > CHART_SERVICES ? sorted.slice(sorted.length - CHART_SERVICES) : sorted;
}

/**
 * The point of the chart in one sentence, printed above it and read out for it: how many of the
 * scored services it shows landed inside their range. `total` is the number of services there are;
 * when the chart shows fewer, the sentence says which.
 */
export function chartSummary(charted: readonly ServiceLogEntry[], total: number): string {
  let scored = 0;
  let inside = 0;
  for (let i = 0; i < charted.length; i++) {
    const e = charted[i];
    if (e.sold_out) continue;
    scored += 1;
    if (e.low <= e.actual && e.actual <= e.high) inside += 1;
  }
  const lead = total > charted.length ? 'The last ' + fmtCount(charted.length) + ' services: ' : '';
  if (charted.length === 0) return 'No service to show yet.';
  if (scored === 0) {
    return lead === ''
      ? 'Nothing is scored yet: every service here sold out, so its count is a minimum.'
      : lead + 'none is scored, because each one sold out and its count is a minimum.';
  }
  return (
    lead +
    fmtCount(inside) +
    ' of ' +
    fmtCount(scored) +
    (scored === 1 ? ' scored service' : ' scored services') +
    ' landed inside the estimated range.'
  );
}

/** The drawing, in the units of its view box. */
export const ACCURACY_CHART = {
  width: 720,
  height: 244,
  left: 38,
  right: 12,
  /** Room above the top gridline for the name of the axis. */
  top: 24,
  bottom: 30,
  /** The widest a service's column gets, so ten services fill the width. Thirty share it. */
  slot: 64,
  /** Width of the bar from low to high. */
  bar: 8,
  /** Width of the tick at the estimate. */
  tick: 16,
  /** Radius of the dot at the actual. */
  dot: 4.5,
  /** Size of the axis text. */
  text: 11,
} as const;

/**
 * The top of the orders axis: four equal whole steps that reach `max`, the step being the smallest
 * of 1, 2, 3, 4, 5, 6, 8, 10, 15, 20, 25, 30, 40, 50, 60, 80, 100, ... that does. 48 gives 60
 * (gridlines at 15, 30, 45 and 60), 110 gives 120.
 */
export function chartTop(max: number): number {
  if (!(max > 0)) return 4;
  const bases = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 8];
  for (let magnitude = 1; magnitude <= 1000000; magnitude *= 10) {
    for (let b = 0; b < bases.length; b++) {
      const step = bases[b] * magnitude;
      if (Math.floor(step) !== step) continue; // orders are whole: no step of 1.5 or 2.5
      if (4 * step >= max) return 4 * step;
    }
  }
  return 4 * Math.ceil(max / 4);
}

export interface ChartMark {
  id: string;
  /** Centre of the column. */
  x: number;
  yLow: number;
  yHigh: number;
  yEstimate: number;
  yActual: number;
  /** Drawn as a hollow ring: the count is a minimum. */
  soldOut: boolean;
  /** The service in words, for the pointer. */
  title: string;
}

export interface ChartLayout {
  /** The value at the top gridline. */
  top: number;
  /** The y of zero orders. */
  baseline: number;
  /** Four gridlines: the orders each stands for, and where it is drawn. */
  gridlines: { orders: number; y: number }[];
  marks: ChartMark[];
  /** Date ticks: those that fit without touching, the first and the last before any other. */
  dates: { x: number; text: string }[];
}

/** Which of the date ticks to print: the first, the last when it fits, then every one that fits between. */
function keepDates(boxes: readonly { from: number; to: number }[], gap: number): boolean[] {
  const keep: boolean[] = boxes.map(() => false);
  const n = boxes.length;
  if (n === 0) return keep;
  keep[0] = true;
  let right = boxes[0].to;
  let limit = Infinity;
  if (n > 1 && boxes[n - 1].from >= right + gap) {
    keep[n - 1] = true;
    limit = boxes[n - 1].from - gap;
  }
  for (let i = 1; i < n - 1; i++) {
    if (boxes[i].from >= right + gap && boxes[i].to <= limit) {
      keep[i] = true;
      right = boxes[i].to;
    }
  }
  return keep;
}

/**
 * Where everything of the chart goes. One column per service, oldest at the left; the columns keep
 * to the left when there are few, so a short log does not look like a long one. A value above the
 * top of the axis is drawn at the top.
 */
export function chartLayout(charted: readonly ServiceLogEntry[], names: Readonly<Record<string, string>>): ChartLayout {
  const C = ACCURACY_CHART;
  const innerW = C.width - C.left - C.right;
  const innerH = C.height - C.top - C.bottom;
  const baseline = C.top + innerH;
  let max = 0;
  for (let i = 0; i < charted.length; i++) {
    const e = charted[i];
    if (e.high > max) max = e.high;
    if (e.actual > max) max = e.actual;
    if (e.predicted > max) max = e.predicted;
  }
  const top = chartTop(max);
  const yOf = (v: number): number => {
    const share = v <= 0 ? 0 : v >= top ? 1 : v / top;
    return baseline - share * innerH;
  };
  const gridlines: { orders: number; y: number }[] = [];
  for (let k = 1; k <= 4; k++) gridlines.push({ orders: (top * k) / 4, y: yOf((top * k) / 4) });

  const n = charted.length;
  const slot = n === 0 ? C.slot : Math.min(C.slot, innerW / n);
  const marks: ChartMark[] = [];
  const dateBoxes: { from: number; to: number }[] = [];
  const dateTicks: { x: number; text: string }[] = [];
  for (let i = 0; i < n; i++) {
    const e = charted[i];
    const x = C.left + slot * (i + 0.5);
    marks.push({
      id: e.service_id,
      x,
      yLow: yOf(e.low),
      yHigh: yOf(e.high),
      yEstimate: yOf(e.predicted),
      yActual: yOf(e.actual),
      soldOut: e.sold_out,
      title:
        fmtDay(e.date, 'medium') +
        ', ' +
        serviceName(e, names) +
        ': ' +
        fmtCount(e.actual) +
        (e.actual === 1 ? ' order, ' : ' orders, ') +
        verdictWords({ actual: e.actual, low: e.low, high: e.high, sold_out: e.sold_out }),
    });
    // A day with two services is named once, under the first.
    if (i > 0 && charted[i - 1].date === e.date) continue;
    const text = fmtDay(e.date, 'short');
    const half = (text.length * C.text * 0.6) / 2;
    // A date at either end stays inside the drawing, clear of the orders axis.
    let at = x;
    if (at - half < C.left) at = C.left + half;
    if (at + half > C.width - 2) at = C.width - 2 - half;
    dateBoxes.push({ from: at - half, to: at + half });
    dateTicks.push({ x: at, text });
  }
  const keep = keepDates(dateBoxes, 6);
  const dates: { x: number; text: string }[] = [];
  for (let i = 0; i < dateTicks.length; i++) if (keep[i]) dates.push(dateTicks[i]);
  return { top, baseline, gridlines, marks, dates };
}

/** The legend of the chart: what each mark is. A sold-out count says in words that it is a minimum. */
export const CHART_LEGEND = {
  range: 'Estimated range',
  estimate: 'Estimate',
  actual: 'Orders served',
  soldOut: 'Sold out: a minimum',
} as const;

// -------------------------------------------------------------------------------------------------
// By spot
// -------------------------------------------------------------------------------------------------

export interface SpotAccuracyRow {
  spotId: string;
  name: string;
  services: number;
  soldOut: number;
  bias: number | null;
  biasText: string;
  miss: number | null;
  missText: string;
  coverage: number | null;
  insideText: string;
  /** The spot's own factor; null while it has none. */
  factor: number | null;
  factorText: string;
}

/** One row per spot of the report, in the report's order (spot id ascending). */
export function spotAccuracyRows(
  report: Pick<AccuracyReport, 'by_spot'>,
  cal: Pick<CalibrationState, 'spots'>,
  names: Readonly<Record<string, string>>,
): SpotAccuracyRow[] {
  const rows: SpotAccuracyRow[] = [];
  for (let i = 0; i < report.by_spot.length; i++) {
    const block = report.by_spot[i];
    const factor = hasKey(cal.spots, block.spot_id) ? cal.spots[block.spot_id].factor : null;
    rows.push({
      spotId: block.spot_id,
      name: serviceName({ kind: 'spot', spot_id: block.spot_id }, names),
      services: block.n_total,
      soldOut: block.n_sold_out,
      bias: block.bias,
      biasText: biasWords(block.bias),
      miss: block.mape,
      missText: block.mape === null ? DASH : fmtPercent(block.mape),
      coverage: block.coverage,
      insideText: insideText(block),
      factor,
      factorText: factor === null ? DASH : factorText(factor),
    });
  }
  return rows;
}

// -------------------------------------------------------------------------------------------------
// "Your results in the model"
// -------------------------------------------------------------------------------------------------

/** "Truck factor x0.96 from 6 services." */
export function truckFactorSentence(cal: Pick<CalibrationState, 'truck_factor' | 'truck_n'>): string {
  if (!(cal.truck_n > 0)) return ACCURACY_TEXT.noTruckFactor;
  return 'Truck factor ' + factorText(cal.truck_factor) + ' from ' + servicesText(cal.truck_n) + '.';
}

export interface SpotFactorLine {
  spotId: string;
  name: string;
  /** "x1.03" */
  factor: string;
  /** "from 3 services" */
  from: string;
  /** "x1.03 from 3 services" */
  text: string;
}

/** One line per spot that has a factor of its own, by name (byte order), then by id. */
export function spotFactorLines(cal: Pick<CalibrationState, 'spots'>, names: Readonly<Record<string, string>>): SpotFactorLine[] {
  const lines: SpotFactorLine[] = [];
  for (const spotId of Object.keys(cal.spots)) {
    const spot = cal.spots[spotId];
    const factor = factorText(spot.factor);
    const from = 'from ' + servicesText(spot.n);
    lines.push({ spotId, name: serviceName({ kind: 'spot', spot_id: spotId }, names), factor, from, text: factor + ' ' + from });
  }
  lines.sort((a, b) => cmp(a.name, b.name) || cmp(a.spotId, b.spotId));
  return lines;
}
