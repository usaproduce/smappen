// The Log's accuracy tab (docs/truck-planner/05_FRONTEND.md 4.7 and 8.2).
//
// The fixture is the example of 02_MODEL 4.13: seven services at three spots, six of them scored and
// one sold out (golden cases g19-001 and g19-027). The tiles, the chart sentence, the table "By spot"
// and the factor lines are checked against the figures that section prints.

import { describe, expect, it } from 'vitest';
import { accuracyReport, calibrate, makeAssumptions } from '../model';
import type { AccuracyReport, CalibrationState, ServiceLogEntry } from '../model';
import {
  ACCURACY_CHART,
  ACCURACY_TEXT,
  BIAS_ON_TARGET,
  CHART_LEGEND,
  CHART_SERVICES,
  accuracyTiles,
  biasSentence,
  biasSide,
  biasWords,
  chartEntries,
  chartLayout,
  chartSummary,
  chartTop,
  factorText,
  insideCount,
  insideText,
  rawBiasLine,
  servicesText,
  spotAccuracyRows,
  spotFactorLines,
  truckFactorSentence,
  unscoredNote,
} from '../accuracyView';
import { goldenCase, spec, specCount } from './_kitFixtures';

const calCase = goldenCase('g19-001');
const A = makeAssumptions(calCase.args.A.overrides, calCase.args.A.region);
const SEVEN: ServiceLogEntry[] = goldenCase('g19-027').args.entries;
const report: AccuracyReport = accuracyReport(SEVEN);
const cal: CalibrationState = calibrate(A, calCase.args.services, '2026-10-04');
const NAMES = { A: 'Reston office park', B: 'Sterling taproom', C: 'Quiet corner' };

function entry(id: string, date: string, actual: number, predicted: number, low: number, high: number, soldOut = false, spot = 'A'): ServiceLogEntry {
  return { service_id: id, kind: 'spot', spot_id: spot, date, open_minute: 660, close_minute: 840, actual, sold_out: soldOut, predicted_raw: predicted, predicted, low, high };
}

describe('the example of 02_MODEL 4.13 as the page prints it', () => {
  it('is the report the model documents', () => {
    expect(report).toEqual(goldenCase('g19-027').expected);
    expect([spec(report.n_total), spec(report.n_scored), spec(report.n_sold_out)]).toEqual([7, 6, 1]);
    expect(spec(report.bias)).toBeCloseTo(0.068548, 6);
    expect(spec(report.mape)).toBeCloseTo(0.196514, 6);
    expect(spec(report.coverage)).toBe(1);
  });

  it('fills the four tiles', () => {
    const [logged, bias, miss, inside] = accuracyTiles(report);
    expect(logged).toEqual({ id: 'logged', caption: 'Services logged', value: '7', line: '6 scored, 1 sold out', wordy: false });
    expect(bias).toEqual({
      id: 'bias',
      caption: 'Bias',
      value: spec('Estimates ran 7% high'),
      line: spec('Before your results were used: 7% high'),
      wordy: true,
    });
    expect(miss).toEqual({ id: 'miss', caption: 'Typical miss', value: '20%', line: spec('average gap between estimate and actual'), wordy: false });
    expect(inside).toEqual({ id: 'inside', caption: 'Inside the range', value: '6 of 6', line: spec('About 8 in 10 is what the ranges aim for.'), wordy: false });
  });

  it('says in one sentence what the chart shows', () => {
    const charted = chartEntries(SEVEN);
    expect(charted.map((e) => e.service_id)).toEqual(['s1', 's2', 's3', 's4', 's5', 's6', 's7']);
    expect(spec(chartSummary(charted, SEVEN.length))).toBe('6 of 6 scored services landed inside the estimated range.');
  });

  it('fills the table "By spot"', () => {
    const rows = spotAccuracyRows(report, cal, NAMES);
    expect(rows.map((r) => [r.name, r.services, r.soldOut, r.biasText, r.missText, r.insideText, r.factorText])).toEqual([
      ['Reston office park', 3, 0, '3% low', '12%', '3 of 3', 'x1.03'],
      ['Sterling taproom', 3, 1, '39% high', '39%', '2 of 2', 'x0.94'],
      ['Quiet corner', 1, 0, '5% high', '5%', '1 of 1', 'x1.00'],
    ]);
    // the figures behind the words, for sorting
    expect(spec(rows[0].bias)).toBeCloseTo(-0.029255, 6);
    expect(spec(rows[1].bias)).toBeCloseTo(0.386207, 6);
    expect(spec(rows[1].miss)).toBeCloseTo(0.38881, 6);
    expect(spec(rows[2].miss)).toBeCloseTo(0.05, 6);
    expect(spec(rows[0].factor)).toBeCloseTo(1.034623, 6);
    expect(spec(rows[1].factor)).toBeCloseTo(0.937663, 6);
    expect(spec(rows[2].factor)).toBeCloseTo(0.999196, 6);
  });

  it('says what the owner results do to the model', () => {
    expect(spec(cal.truck_factor)).toBeCloseTo(0.963784, 6);
    expect(spec(cal.truck_n)).toBe(6);
    expect(spec(truckFactorSentence(cal))).toBe('Truck factor x0.96 from 6 services.');
    expect(spotFactorLines(cal, NAMES).map((l) => [l.name, l.text])).toEqual([
      ['Quiet corner', 'x1.00 from 1 service'],
      ['Reston office park', 'x1.03 from 3 services'],
      ['Sterling taproom', 'x0.94 from 3 services'],
    ]);
    expect(spotFactorLines(cal, NAMES)[1]).toEqual({ spotId: 'A', name: 'Reston office park', factor: 'x1.03', from: 'from 3 services', text: 'x1.03 from 3 services' });
    expect(spec(ACCURACY_TEXT.factorHelp)).toBe('Above 1 means you sell more than the generic model expects.');
  });
});

describe('bias', () => {
  it('reads high above 2 %, low below minus 2 %, and on target in between', () => {
    expect(BIAS_ON_TARGET).toBe(0.02);
    expect(biasSide(0.02)).toBe('on_target');
    expect(biasSide(-0.02)).toBe('on_target');
    expect(biasSide(0)).toBe('on_target');
    expect(biasSide(0.0201)).toBe('high');
    expect(biasSide(-0.0201)).toBe('low');
    expect(biasSentence(0.0201)).toBe('Estimates ran 2% high');
    expect(biasSentence(-0.31)).toBe('Estimates ran 31% low');
    expect(biasSentence(1.5)).toBe('Estimates ran 150% high');
    expect(spec(biasSentence(0.004))).toBe('Estimates were on target');
    expect(biasSentence(null)).toBe('—');
    expect(biasWords(0.068548)).toBe('7% high');
    expect(biasWords(-0.029255)).toBe('3% low');
    expect(biasWords(-0.01)).toBe('On target');
    expect(biasWords(null)).toBe('—');
  });

  it('says how the model did before the logged results were used', () => {
    expect(rawBiasLine(0.12)).toBe('Before your results were used: 12% high');
    expect(rawBiasLine(-0.068548)).toBe('Before your results were used: 7% low');
    expect(rawBiasLine(0.01)).toBe('Before your results were used: on target');
    expect(rawBiasLine(null)).toBe('');
  });
});

describe('tiles without a scored service', () => {
  it('show the em dash and say what is missing', () => {
    const soldOutOnly = accuracyReport([entry('a', '2026-10-01', 45, 39.4, 20.8, 62.2, true), entry('b', '2026-10-02', 50, 39.4, 20.8, 62.2, true)]);
    expect(soldOutOnly.n_scored).toBe(0);
    const tiles = accuracyTiles(soldOutOnly);
    expect(tiles[0]).toMatchObject({ value: '2', line: '0 scored, 2 sold out' });
    for (const tile of tiles.slice(1)) {
      expect(tile.value).toBe('—');
      expect(tile.line).toBe(spec('Needs a service that did not sell out.'));
      expect(tile.wordy).toBe(false);
    }
    expect(accuracyTiles(accuracyReport([]))[0]).toMatchObject({ value: '0', line: '0 scored, 0 sold out' });
  });

  it('do not invent a bias when no order was served', () => {
    const zeros = accuracyReport([entry('a', '2026-10-01', 0, 3.2, 0.5, 7.9)]);
    expect(zeros.bias).toBeNull();
    const tiles = accuracyTiles(zeros);
    expect(tiles[1]).toMatchObject({ value: '—', line: ACCURACY_TEXT.needsOrders });
    expect(tiles[2].value).toBe('320%');
    expect(tiles[3].value).toBe('0 of 1');
  });

  it('counts the services inside their range from the coverage', () => {
    expect(insideCount({ coverage: 2 / 3, n_scored: 3 })).toBe(2);
    expect(insideText({ coverage: 2 / 3, n_scored: 3 })).toBe('2 of 3');
    expect(insideText({ coverage: 0.8, n_scored: 10 })).toBe('8 of 10');
    expect(insideText({ coverage: null, n_scored: 0 })).toBe('—');
  });

  it('says how many logged services are not scored, and which kinds are left out', () => {
    expect(unscoredNote(0)).toBeNull();
    expect(unscoredNote(1)).toBe('1 logged service is not scored. Events, catering jobs and services without an estimate are left out.');
    expect(spec(unscoredNote(3))).toBe('3 logged services are not scored. Events, catering jobs and services without an estimate are left out.');
  });
});

describe('the chart', () => {
  it('draws the services of one day in the order they were served', () => {
    // the dinner was given the smaller id: the opening time decides, not the id
    const dinner: ServiceLogEntry = { ...entry('a-dinner', '2026-10-02', 75, 65, 38, 98, true), open_minute: 1020, close_minute: 1200 };
    const lunch = entry('b-lunch', '2026-10-02', 70, 54, 31, 81);
    const dayBefore = entry('c-before', '2026-10-01', 58, 73, 41, 95);
    expect(chartEntries([dinner, lunch, dayBefore]).map((e) => e.service_id)).toEqual(['c-before', 'b-lunch', 'a-dinner']);
    // two services with the same date and opening time: the id settles it, so the order never flips
    const twin = entry('a-twin', '2026-10-02', 60, 54, 31, 81);
    expect(chartEntries([lunch, twin]).map((e) => e.service_id)).toEqual(['a-twin', 'b-lunch']);
    expect(chartEntries([twin, lunch]).map((e) => e.service_id)).toEqual(['a-twin', 'b-lunch']);
  });

  it('shows the last 30 services in date order and says so', () => {
    const many: ServiceLogEntry[] = [];
    // given newest first, as a list would: the chart sorts them itself
    for (let i = 34; i >= 0; i--) many.push(entry('id' + String(100 + i), '2026-09-' + String(1 + (i % 30)).padStart(2, '0'), 50, 50, 30, 70));
    const charted = chartEntries(many);
    expect(charted).toHaveLength(CHART_SERVICES);
    for (let i = 1; i < charted.length; i++) {
      const a = charted[i - 1];
      const b = charted[i];
      expect(a.date < b.date || (a.date === b.date && a.service_id < b.service_id)).toBe(true);
    }
    expect(charted[charted.length - 1].date).toBe('2026-09-30');
    expect(chartSummary(charted, many.length)).toBe('The last 30 services: 30 of 30 scored services landed inside the estimated range.');
    expect(many[0].service_id).toBe('id134'); // the input was not reordered
  });

  it('counts a sold-out service as neither inside nor outside', () => {
    const three = [entry('a', '2026-10-01', 80, 50, 30, 70), entry('b', '2026-10-02', 50, 50, 30, 70), entry('c', '2026-10-03', 200, 50, 30, 70, true)];
    expect(chartSummary(three, 3)).toBe('1 of 2 scored services landed inside the estimated range.');
    expect(chartSummary(three.slice(1, 2), 1)).toBe('1 of 1 scored service landed inside the estimated range.');
    expect(chartSummary(three.slice(2), 1)).toBe('Nothing is scored yet: every service here sold out, so its count is a minimum.');
    expect(chartSummary([], 0)).toBe('No service to show yet.');
    // the edges of the range are inside, as in the report
    expect(chartSummary([entry('a', '2026-10-01', 30, 50, 30, 70), entry('b', '2026-10-02', 70, 50, 30, 70)], 2)).toBe('2 of 2 scored services landed inside the estimated range.');
  });

  it('picks a top for the orders axis that four whole steps reach', () => {
    expect(chartTop(0)).toBe(4);
    expect(chartTop(3)).toBe(4);
    expect(chartTop(4.2)).toBe(8);
    expect(chartTop(48)).toBe(60);
    expect(chartTop(96.13)).toBe(100);
    expect(chartTop(110.9)).toBe(120);
    expect(chartTop(121)).toBe(160);
    expect(chartTop(5000)).toBe(6000);
    for (const max of [1, 7, 23, 64.6, 99, 250, 777, 4999]) {
      const top = chartTop(max);
      expect(top).toBeGreaterThanOrEqual(max);
      expect(Math.floor(top / 4)).toBe(top / 4);
    }
  });

  it('places one column per service with its range, its estimate and its actual', () => {
    const charted = chartEntries(SEVEN);
    const layout = chartLayout(charted, NAMES);
    const C = ACCURACY_CHART;
    expect(layout.top).toBe(100); // the widest range of the seven ends at 96.13
    expect(layout.baseline).toBe(C.height - C.bottom);
    expect(layout.gridlines.map((g) => g.orders)).toEqual([25, 50, 75, 100]);
    expect(layout.gridlines[3].y).toBeCloseTo(C.top, 9);
    expect(layout.marks).toHaveLength(7);
    // seven services do not stretch over the whole width: each keeps the widest column
    expect(layout.marks[0].x).toBeCloseTo(C.left + C.slot / 2, 9);
    expect(layout.marks[6].x - layout.marks[5].x).toBeCloseTo(C.slot, 9);
    const innerH = C.height - C.top - C.bottom;
    const s1 = layout.marks[0];
    expect(s1.yActual).toBeCloseTo(layout.baseline - (52 / 100) * innerH, 9);
    expect(s1.yEstimate).toBeCloseTo(layout.baseline - (60 / 100) * innerH, 9);
    expect(s1.yHigh).toBeLessThan(s1.yEstimate);
    expect(s1.yLow).toBeGreaterThan(s1.yEstimate);
    expect(layout.marks.map((m) => m.soldOut)).toEqual([false, false, false, true, false, false, false]);
    expect(s1.title).toBe('Sat, Jun 6, Reston office park: 52 orders, inside the range');
    expect(layout.marks[3].title).toBe('Sat, Aug 22, Sterling taproom: 45 orders, sold out, counted as a minimum');
    expect(layout.dates.map((d) => d.text)).toEqual(['Jun 6', 'Jul 11', 'Aug 1', 'Aug 22', 'Sep 12', 'Sep 26', 'Oct 3']);
  });

  it('shares the width between thirty services and prints only the dates that fit', () => {
    const many: ServiceLogEntry[] = [];
    for (let i = 0; i < 30; i++) many.push(entry('id' + String(100 + i), '2026-09-' + String(i + 1).padStart(2, '0'), 50, 50, 30, 70));
    const layout = chartLayout(many, NAMES);
    const C = ACCURACY_CHART;
    const slot = (C.width - C.left - C.right) / 30;
    expect(layout.marks[29].x).toBeCloseTo(C.width - C.right - slot / 2, 9);
    expect(layout.marks[29].x + C.tick / 2).toBeLessThanOrEqual(C.width);
    expect(slot).toBeGreaterThan(C.tick); // the estimate ticks of two neighbours never touch
    expect(layout.dates[0].text).toBe('Sep 1');
    expect(layout.dates[layout.dates.length - 1].text).toBe('Sep 30');
    // the dates at the two ends stay inside the drawing, clear of the orders axis
    expect(layout.dates[0].x - (5 * C.text * 0.6) / 2).toBeGreaterThanOrEqual(C.left);
    expect(layout.dates[layout.dates.length - 1].x + (6 * C.text * 0.6) / 2).toBeLessThanOrEqual(C.width);
    expect(layout.dates.length).toBeLessThan(30);
    expect(layout.dates.length).toBeGreaterThan(4);
    // no two dates touch: each is as wide as its characters at 0.6 of the text size, with a gap of 6
    const half = (text: string) => (text.length * C.text * 0.6) / 2;
    for (let i = 1; i < layout.dates.length; i++) {
      const a = layout.dates[i - 1];
      const b = layout.dates[i];
      expect(b.x - half(b.text) - (a.x + half(a.text))).toBeGreaterThanOrEqual(6 - 1e-9);
    }
  });

  it('names a day with two services once, and one order in the singular', () => {
    const day = [entry('a', '2026-10-02', 1, 2, 0.4, 4.4), entry('b', '2026-10-02', 60, 50, 30, 70), entry('c', '2026-10-03', 20, 50, 30, 70)];
    const layout = chartLayout(day, NAMES);
    expect(layout.dates.map((d) => d.text)).toEqual(['Oct 2', 'Oct 3']);
    expect(layout.marks[0].title).toBe('Fri, Oct 2, Reston office park: 1 order, inside the range');
    expect(layout.marks[2].title).toBe('Sat, Oct 3, Reston office park: 20 orders, below the range');
    expect(chartLayout([], NAMES)).toMatchObject({ top: 4, marks: [], dates: [] });
  });

  it('names its four marks', () => {
    expect(CHART_LEGEND).toEqual({ range: 'Estimated range', estimate: 'Estimate', actual: 'Orders served', soldOut: 'Sold out: a minimum' });
  });
});

describe('factors and small words', () => {
  it('prints a factor with two decimals and counts services in words', () => {
    expect(factorText(0.963784)).toBe('x0.96');
    expect(factorText(1)).toBe('x1.00');
    expect(factorText(18.942197)).toBe('x18.94');
    expect(servicesText(0)).toBe('0 services');
    expect(servicesText(1)).toBe('1 service');
    expect(servicesText(1200)).toBe('1,200 services');
  });

  it('says so when nothing adjusts the estimates yet', () => {
    const none = calibrate(A, [], '2026-10-04');
    expect(truckFactorSentence(none)).toBe(ACCURACY_TEXT.noTruckFactor);
    expect(spotFactorLines(none, NAMES)).toEqual([]);
    // a spot that is no longer listed still has its line
    expect(spotFactorLines(cal, { A: 'Reston office park' }).map((l) => l.name)).toEqual(['A spot that is no longer listed', 'A spot that is no longer listed', 'Reston office park']);
    expect(spotAccuracyRows(report, none, NAMES).map((r) => [r.factor, r.factorText])).toEqual([[null, '—'], [null, '—'], [null, '—']]);
  });
});

describe('worked examples', () => {
  it('this file asserts 27 values the specification prints', () => {
    expect(specCount()).toBe(27);
  });
});
