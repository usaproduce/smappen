// The logic of the UI kit (docs/truck-planner/05_FRONTEND.md section 3, 8.2).
//
// Vitest runs in Node without a DOM, so the components of components/truck/ui keep their decisions
// in pure functions: what a field commits, how a table sorts, where a key moves, what a chip says.

import { describe, expect, it } from 'vitest';
import { buildTimeline, dayContext, makeAssumptions, typicalContext } from '../model';
import type { Estimate, HourForecast } from '../model';
import {
  CONFIDENCE_GLYPH,
  barShare,
  commitNumber,
  commitTime,
  estimateAriaLabel,
  holidayChipText,
  keepLabels,
  nextSort,
  numberFieldRangeMessage,
  numberFieldText,
  sortRows,
  stepNumber,
  stepTime,
  stripByte,
  stripKeyTarget,
  stripRuns,
  tabDomIds,
  tabKeyTarget,
  timelineBarX,
  timelineLabelKeep,
  unitWords,
  weatherSummary,
  weatherTitle,
  yTicks,
} from '../../../components/truck/ui/kit';
import { fmtClockShort } from '../format';
import { timelineMarks } from '../timelineView';
import { assume, goldenCase, sourceText, spec, specCount } from './_kitFixtures';

const A = makeAssumptions({}, { id: 'dc', traffic_matrix: 'dc', flags: { inauguration_day: true } });

const est = (value: number, low: number, high: number, confidence: Estimate['confidence'] = 'rough'): Estimate => ({ value, low, high, confidence });

describe('RangeValue: the spoken label', () => {
  it('is the estimate as a sentence plus the label and its sentence', () => {
    expect(spec(estimateAriaLabel(est(60.4938, 33.0139, 93.1942), 'orders'))).toBe('60 orders, likely between 33 and 93. Rough: not yet checked against your own sales.');
    expect(spec(estimateAriaLabel(est(482.2, 42.35, 1011.86), 'money', 'TAKE-HOME'))).toBe('Take-home: $482, likely between $42 and $1,012. Rough: not yet checked against your own sales.');
    expect(estimateAriaLabel(est(80, 80, 80, 'fixed'), 'orders')).toBe('80 orders. Fixed: set by your terms, not estimated.');
    expect(estimateAriaLabel(est(42.74, 3.75, 89.68), 'money_per_hour', 'PER HOUR OF YOUR DAY')).toBe(
      'Per hour of your day: $43 an hour, likely between $4 and $90. Rough: not yet checked against your own sales.',
    );
  });

  it('gives every confidence label its own glyph', () => {
    expect(spec(CONFIDENCE_GLYPH)).toEqual({ very_rough: 'signal-low', rough: 'signal-medium', fair: 'signal-high', good: 'signal', fixed: 'lock' });
    expect(new Set(Object.values(CONFIDENCE_GLYPH)).size).toBe(5);
  });

  it('is the only component that formats the parts of an estimate, and it cannot hide the chip', () => {
    const source = sourceText('components/truck/ui/RangeValue.tsx');
    expect(source.includes('estimate: Estimate;')).toBe(true);
    expect(/<ConfidenceChip /.test(source)).toBe(true);
    expect(/hideChip|showChip|noChip|withoutChip/i.test(source)).toBe(false);
    expect(/AnimatedNumber/.test(source)).toBe(false);
  });
});

describe('NumberField: what it commits', () => {
  it('commits a plain number as typed', () => {
    expect(commitNumber('15')).toEqual({ ok: true, value: 15, error: null });
    expect(commitNumber(' $1,500.50 ')).toEqual({ ok: true, value: 1500.5, error: null });
    expect(commitNumber('15.005', { decimals: 2 })).toEqual({ ok: true, value: 15.005, error: null });
    expect(commitNumber('0', { min: 0, max: 5 })).toEqual({ ok: true, value: 0, error: null });
  });

  it('commits null for an empty field, with "Required" when it is required', () => {
    expect(commitNumber('')).toEqual({ ok: true, value: null, error: null });
    expect(commitNumber('   ')).toEqual({ ok: true, value: null, error: null });
    expect(spec(commitNumber('', { required: true }))).toEqual({ ok: true, value: null, error: 'Required' });
  });

  it('does not commit a value outside the range and never clamps it', () => {
    expect(spec(commitNumber('250', { min: 1, max: 200 }))).toEqual({ ok: false, error: 'Enter a number from 1 to 200.' });
    expect(commitNumber('0.5', { min: 1, max: 200 })).toEqual({ ok: false, error: 'Enter a number from 1 to 200.' });
    expect(commitNumber('200', { min: 1, max: 200 })).toEqual({ ok: true, value: 200, error: null });
    expect(commitNumber('1', { min: 1, max: 200 })).toEqual({ ok: true, value: 1, error: null });
    expect(commitNumber('-1', { min: 0 })).toEqual({ ok: false, error: 'Enter a number of 0 or more.' });
    expect(commitNumber('25', { min: 0.5, max: 20 })).toEqual({ ok: false, error: 'Enter a number from 0.5 to 20.' });
  });

  it('does not commit text that is not a number', () => {
    expect(commitNumber('abc', { min: 1, max: 200 })).toEqual({ ok: false, error: 'Enter a number from 1 to 200.' });
    expect(commitNumber('abc')).toEqual({ ok: false, error: 'Enter a number.' });
    expect(commitNumber('1e3')).toEqual({ ok: false, error: 'Enter a number.' });
    expect(commitNumber('12 orders', { integer: true })).toEqual({ ok: false, error: 'Enter a whole number.' });
  });

  it('wants a whole number in an integer field', () => {
    expect(commitNumber('2.5', { integer: true, min: 0, max: 12 })).toEqual({ ok: false, error: 'Enter a whole number from 0 to 12.' });
    expect(commitNumber('2', { integer: true, min: 0, max: 12 })).toEqual({ ok: true, value: 2, error: null });
    expect(commitNumber('2.0', { integer: true })).toEqual({ ok: true, value: 2, error: null });
  });

  it('percent: the field shows 30 for 0.30 and commits the fraction exactly', () => {
    expect(spec(numberFieldText(0.3, { format: 'percent' }))).toBe('30');
    expect(commitNumber('30', { format: 'percent' })).toEqual({ ok: true, value: 0.3, error: null });
    expect(commitNumber('30%', { format: 'percent' })).toEqual({ ok: true, value: 0.3, error: null });
    expect(commitNumber('2.6', { format: 'percent' })).toEqual({ ok: true, value: 0.026, error: null });
    expect(commitNumber('7', { format: 'percent' })).toEqual({ ok: true, value: 0.07, error: null });
    expect(commitNumber('.5', { format: 'percent' })).toEqual({ ok: true, value: 0.005, error: null });
    expect(commitNumber('100', { format: 'percent', max: 1 })).toEqual({ ok: true, value: 1, error: null });
    expect(commitNumber('-5', { format: 'percent' })).toEqual({ ok: true, value: -0.05, error: null });
    // the bounds are fractions; the message prints them as the field shows them
    expect(spec(commitNumber('96', { format: 'percent', min: 0, max: 0.95 }))).toEqual({ ok: false, error: 'Enter a number from 0 to 95.' });
    expect(commitNumber('95', { format: 'percent', min: 0, max: 0.95 })).toEqual({ ok: true, value: 0.95, error: null });
    expect(numberFieldRangeMessage({ format: 'percent', min: 0, max: 0.2 })).toBe('Enter a number from 0 to 20.');
    // every whole and tenth percent goes out and comes back unchanged
    for (let tenths = 0; tenths <= 1000; tenths++) {
      const text = String(tenths / 10);
      const r = commitNumber(text, { format: 'percent' });
      expect(r.ok && r.value).toBe(Number((tenths / 1000).toFixed(3)));
      if (r.ok && r.value !== null) expect(numberFieldText(r.value, { format: 'percent' })).toBe(text);
    }
  });

  it('prints the value at rest with the decimals asked for', () => {
    expect(numberFieldText(15, { decimals: 2 })).toBe('15.00');
    expect(numberFieldText(1500, { decimals: 2 })).toBe('1,500.00');
    expect(numberFieldText(4.195, { decimals: 3 })).toBe('4.195');
    expect(numberFieldText(45, { integer: true })).toBe('45');
    expect(numberFieldText(200000, { integer: true })).toBe('200,000');
    expect(numberFieldText(9.541)).toBe('9.541');
    expect(numberFieldText(0.026, { format: 'percent', decimals: 1 })).toBe('2.6');
    expect(numberFieldText(1.1, { format: 'percent' })).toBe('110');
    expect(numberFieldText(null)).toBe('');
    expect(numberFieldText(undefined)).toBe('');
    // what the field shows is something it can read back
    for (const v of [0, 15, 1500, 0.5, 4.195, 200000, 9.541]) {
      const r = commitNumber(numberFieldText(v));
      expect(r.ok && r.value).toBe(v);
    }
  });

  it('Arrow Up and Down move by one step and stop at the range', () => {
    expect(stepNumber(15, 1)).toBe(16);
    expect(stepNumber(15, -1)).toBe(14);
    expect(stepNumber(0.3, 1, { format: 'percent' })).toBe(0.31);
    expect(stepNumber(0.3, -1, { format: 'percent' })).toBe(0.29);
    expect(stepNumber(4.195, 1, { step: 0.005 })).toBe(4.2);
    expect(stepNumber(200, 1, { min: 1, max: 200 })).toBeNull();
    expect(stepNumber(1, -1, { min: 1, max: 200 })).toBeNull();
    expect(stepNumber(null, 1, { min: 5, max: 60 })).toBe(5);
    expect(stepNumber(null, 1)).toBe(1);
    expect(stepNumber(null, -1, { min: 0 })).toBeNull();
  });

  it('says the unit in words for a screen reader', () => {
    expect(unitWords('$')).toBe('dollars');
    expect(unitWords(undefined, '%')).toBe('percent');
    expect(unitWords(undefined, 'mi')).toBe('miles');
    expect(unitWords(undefined, 'min')).toBe('minutes');
    expect(unitWords(undefined, 'orders an hour')).toBe('orders an hour');
    expect(unitWords('$', 'per order')).toBe('per order');
    expect(unitWords()).toBe('');
  });
});

describe('TimeField: what it commits', () => {
  it('reads a typed time', () => {
    expect(commitTime('11')).toEqual({ ok: true, value: 660 });
    expect(commitTime('2:30 pm')).toEqual({ ok: true, value: 870 });
    expect(commitTime('')).toEqual({ ok: true, value: null });
    expect(commitTime('  ')).toEqual({ ok: true, value: null });
  });

  it('reads back what it shows, also after midnight', () => {
    expect(commitTime('9:30 PM')).toEqual({ ok: true, value: 1290 });
    expect(commitTime('1:00 AM (next day)', { after: 1290, allowNextDay: true })).toEqual({ ok: true, value: 1500 });
    // the marker is honoured even where the reading alone would stay on the first day
    expect(commitTime('11:30 AM (next day)', { after: 600, allowNextDay: true })).toEqual({ ok: true, value: 2130 });
    expect(commitTime('12:00 AM (next day)', { after: 1290, allowNextDay: true })).toEqual({ ok: true, value: 1440 });
  });

  it('takes the next day for a closing time that is not later than the opening', () => {
    expect(spec(commitTime('1am', { after: 1320, allowNextDay: true }))).toEqual({ ok: true, value: 1500 });
    expect(commitTime('1', { after: 1320, allowNextDay: true })).toEqual({ ok: true, value: 1500 });
    expect(commitTime('2', { after: 660, allowNextDay: true })).toEqual({ ok: true, value: 840 });
  });

  it('does not commit a time outside min..max and says the range', () => {
    expect(commitTime('5', { min: 600, max: 960 })).toEqual({ ok: false, error: 'Enter a time from 10:00 AM to 4:00 PM.' });
    expect(commitTime('1 pm', { min: 600, max: 960 })).toEqual({ ok: true, value: 780 });
    expect(commitTime('1am', { after: 600, allowNextDay: true, max: 1440 })).toEqual({ ok: false, error: 'Enter a time from 12:00 AM to 12:00 AM (next day).' });
    // without the next day a field ends at midnight
    expect(commitTime('24')).toEqual({ ok: true, value: 1440 });
  });

  it('does not commit text that is not a time', () => {
    expect(spec(commitTime('25'))).toEqual({ ok: false, error: 'That is not a time. Type one like 11 or 2:30 pm.' });
    expect(commitTime('lunch')).toEqual({ ok: false, error: 'That is not a time. Type one like 11 or 2:30 pm.' });
  });

  it('moves by 15 minutes, or by the step given, and stops at the range', () => {
    expect(stepTime(660, 1)).toBe(675);
    expect(stepTime(660, -1)).toBe(645);
    expect(stepTime(660, 1, { step: 5 })).toBe(665);
    expect(stepTime(0, -1)).toBeNull();
    expect(stepTime(1440, 1)).toBeNull();
    expect(stepTime(1440, 1, { allowNextDay: true })).toBe(1455);
    expect(stepTime(2880, 1, { allowNextDay: true })).toBeNull();
    expect(stepTime(600, -1, { min: 600 })).toBeNull();
    // an empty field starts just after the time before it, or at 11 AM
    expect(stepTime(null, 1)).toBe(660);
    expect(stepTime(null, -1)).toBe(660);
    expect(stepTime(null, 1, { after: 840 })).toBe(855);
  });
});

describe('DataTable: sorting', () => {
  interface Row {
    id: string;
    name: string;
    orders: number;
  }
  const rows: Row[] = [
    { id: 'c', name: 'Reston', orders: 60 },
    { id: 'a', name: 'Sterling', orders: 39 },
    { id: 'b', name: 'ashburn', orders: 60 },
    { id: 'd', name: 'Herndon', orders: 7 },
  ];
  const ids = (list: Row[]) => list.map((r) => r.id).join('');

  it('sorts numbers numerically and breaks ties by the row key', () => {
    expect(ids(sortRows(rows, (r) => r.orders, 'asc', (r) => r.id))).toBe('dabc');
    expect(ids(sortRows(rows, (r) => r.orders, 'desc', (r) => r.id))).toBe('bcad');
    // ties keep the ascending key order whichever way the column is turned
    const desc = sortRows(rows, (r) => r.orders, 'desc', (r) => r.id);
    expect(desc.slice(0, 2).map((r) => r.id)).toEqual(['b', 'c']);
    expect(ids(sortRows([{ id: 'x', name: '', orders: 100 }, { id: 'y', name: '', orders: 9 }], (r) => r.orders, 'asc', (r) => r.id))).toBe('yx');
  });

  it('sorts strings byte-wise, not by a locale', () => {
    // capitals come before lower case in byte order
    expect(sortRows(rows, (r) => r.name, 'asc', (r) => r.id).map((r) => r.name)).toEqual(['Herndon', 'Reston', 'Sterling', 'ashburn']);
    expect(sortRows(rows, (r) => r.name, 'desc', (r) => r.id).map((r) => r.name)).toEqual(['ashburn', 'Sterling', 'Reston', 'Herndon']);
  });

  it('puts a missing number last in either direction and does not change its input', () => {
    const withGap = [...rows, { id: 'e', name: 'No estimate', orders: NaN }];
    expect(ids(sortRows(withGap, (r) => r.orders, 'asc', (r) => r.id))).toBe('dabce');
    expect(ids(sortRows(withGap, (r) => r.orders, 'desc', (r) => r.id))).toBe('bcade');
    expect(ids(rows)).toBe('cabd');
    expect(sortRows([], (r: Row) => r.orders, 'asc', (r) => r.id)).toEqual([]);
  });

  it('a header click starts ascending and turns round on the same column', () => {
    expect(nextSort(undefined, 'orders')).toEqual({ key: 'orders', dir: 'asc' });
    expect(nextSort({ key: 'orders', dir: 'asc' }, 'orders')).toEqual({ key: 'orders', dir: 'desc' });
    expect(nextSort({ key: 'orders', dir: 'desc' }, 'orders')).toEqual({ key: 'orders', dir: 'asc' });
    expect(nextSort({ key: 'orders', dir: 'desc' }, 'name')).toEqual({ key: 'name', dir: 'asc' });
  });
});

describe('Tabs: ids and keys', () => {
  it('derives the ids of a tab and of its panel', () => {
    expect(tabDomIds('Map layer', 'opportunity')).toEqual({ tab: 'tp-map-layer-opportunity-tab', panel: 'tp-map-layer-opportunity-panel' });
    expect(tabDomIds('Log', 'accuracy').panel).toBe('tp-log-accuracy-panel');
    expect(tabDomIds('Settings', 'truck').tab).not.toBe(tabDomIds('Log', 'truck').tab);
  });

  it('Left and Right move and wrap, Home and End jump', () => {
    expect(tabKeyTarget('ArrowRight', 0, 3)).toBe(1);
    expect(tabKeyTarget('ArrowRight', 2, 3)).toBe(0);
    expect(tabKeyTarget('ArrowLeft', 0, 3)).toBe(2);
    expect(tabKeyTarget('Home', 2, 3)).toBe(0);
    expect(tabKeyTarget('End', 0, 3)).toBe(2);
    expect(tabKeyTarget('Enter', 0, 3)).toBeNull();
    expect(tabKeyTarget('ArrowRight', 0, 0)).toBeNull();
  });
});

describe('charts', () => {
  it('HourBars: gridlines and bar heights', () => {
    expect(yTicks(45)).toEqual([15, 30, 45]);
    expect(yTicks(40)).toEqual([20, 40]);
    expect(yTicks(7)).toEqual([7]);
    expect(yTicks(0)).toEqual([]);
    for (const yMax of [45, 40, 7, 1, 120]) expect(yTicks(yMax).length).toBeLessThanOrEqual(3); // with the base line: at most four
    expect(barShare(22.5, 45)).toBe(0.5);
    expect(barShare(90, 45)).toBe(1);
    expect(barShare(-3, 45)).toBe(0);
    expect(barShare(10, 0)).toBe(0);
  });

  it('WeekStrip: a cell takes the byte of the map scale', () => {
    expect(stripByte(45, 45)).toBe(255);
    expect(stripByte(0, 45)).toBe(0);
    expect(stripByte(29.436015, 45)).toBe(206); // the cell of 02_MODEL 4.17 at Thursday 12 PM
    expect(stripByte(10, 0)).toBe(0);
  });

  it('WeekStrip: a window is drawn as runs per day row and wraps the week', () => {
    // Thursday 11 AM for three hours
    expect(stripRuns(83, 3)).toEqual([{ dow: 3, hour: 11, hours: 3, first: true }]);
    // Friday 9 PM for five hours passes midnight
    expect(stripRuns(4 * 24 + 21, 5)).toEqual([
      { dow: 4, hour: 21, hours: 3, first: true },
      { dow: 5, hour: 0, hours: 2, first: false },
    ]);
    // Sunday 11 PM for three hours wraps to Monday
    expect(stripRuns(167, 3)).toEqual([
      { dow: 6, hour: 23, hours: 1, first: true },
      { dow: 0, hour: 0, hours: 2, first: false },
    ]);
    expect(stripRuns(0, 0)).toEqual([]);
    expect(stripRuns(0, 500).reduce((n, r) => n + r.hours, 0)).toBe(168);
  });

  it('WeekStrip: arrows move the cursor and stay in the grid', () => {
    expect(stripKeyTarget('ArrowRight', 84)).toBe(85);
    expect(stripKeyTarget('ArrowLeft', 84)).toBe(83);
    expect(stripKeyTarget('ArrowDown', 84)).toBe(108);
    expect(stripKeyTarget('ArrowUp', 84)).toBe(60);
    expect(stripKeyTarget('ArrowRight', 23)).toBe(0); // stays in Monday's row
    expect(stripKeyTarget('ArrowLeft', 0)).toBe(23);
    expect(stripKeyTarget('ArrowDown', 167)).toBe(23); // Sunday wraps to Monday
    expect(stripKeyTarget('ArrowUp', 5)).toBe(149);
    expect(stripKeyTarget('Home', 84)).toBe(72);
    expect(stripKeyTarget('End', 84)).toBe(95);
    expect(stripKeyTarget('Enter', 84)).toBeNull();
    for (let how = 0; how < 168; how++) {
      for (const key of ['ArrowRight', 'ArrowLeft', 'ArrowDown', 'ArrowUp', 'Home', 'End']) {
        const to = stripKeyTarget(key, how) as number;
        expect(to >= 0 && to < 168 && Math.floor(to) === to).toBe(true);
      }
    }
  });

  it('Timeline: a clock label that would run into one already placed is dropped', () => {
    const box = (from: number, to: number) => ({ from, to });
    // left to right when nothing is preferred
    expect(keepLabels([box(10, 50), box(30, 60), box(670, 710)], 8)).toEqual([true, false, true]);
    expect(keepLabels([box(10, 50), box(100, 130), box(134, 164), box(670, 710)], 8)).toEqual([true, true, false, true]);
    expect(keepLabels([box(10, 50), box(100, 130), box(139, 169), box(670, 710)], 8)).toEqual([true, true, true, true]);
    // opening and closing times are placed before the start and the end of the day
    const day = [box(10, 50), box(100, 130), box(400, 430), box(640, 670), box(665, 710)];
    expect(keepLabels(day, 8, [1, 2, 3])).toEqual([true, true, true, true, false]);
    expect(keepLabels(day, 8)).toEqual([true, true, true, true, false]);
    expect(keepLabels(day, 8, [4])).toEqual([true, true, true, false, true]);
    expect(keepLabels([box(10, 50)], 8)).toEqual([true]);
    expect(keepLabels([], 8)).toEqual([]);
    expect(keepLabels([box(0, 10)], 8, [5, -1])).toEqual([true]);
  });

  it('Timeline: the blueprint day keeps its opening and closing times and drops the end of the day', () => {
    const a = goldenCase('g17-001').args;
    const t = buildTimeline(assume(a.A), a.profile, a.ctx, a.stops, a.legs);
    const marks = timelineMarks(t);
    const keep = timelineLabelKeep(marks, timelineBarX(t.start_prep as number, t.done as number));
    expect(keep).toEqual([true, true, true, true, true, false]);
    expect(spec(marks.filter((_, i) => keep[i]).map((m) => fmtClockShort(m.minute)))).toEqual(['9:34 AM', '11 AM', '2 PM', '5 PM', '8 PM']);
    // the bar is 720 units wide with 10 units of room at each end
    const x = timelineBarX(574, 1251);
    expect(x(574)).toBe(10);
    expect(x(1251)).toBe(710);
    expect(timelineBarX(600, 600)(600)).toBe(10);
    // a day with one stop has room for every label
    const one = goldenCase('g17-002').args;
    const t1 = buildTimeline(assume(one.A), one.profile, one.ctx, one.stops, one.legs);
    expect(timelineLabelKeep(timelineMarks(t1), timelineBarX(t1.start_prep as number, t1.done as number))).toEqual([true, true, true, true]);
  });
});

describe('WeatherChip: what it says', () => {
  const hours = (list: Partial<HourForecast>[], from = 11): (HourForecast | null)[] => {
    const out: (HourForecast | null)[] = new Array(24).fill(null);
    list.forEach((h, i) => {
      out[from + i] = { hour: from + i, temp_f: null, precip_prob: null, short_forecast: null, wind_mph: null, ...h };
    });
    return out;
  };

  it('prints the temperature range, then the worst class with the highest chance given for it', () => {
    const rain = hours([
      { temp_f: 55, precip_prob: 40, short_forecast: 'Chance Rain Showers', wind_mph: 8 },
      { temp_f: 58, precip_prob: 30, short_forecast: 'Chance Rain Showers', wind_mph: 8 },
      { temp_f: 62, precip_prob: 0, short_forecast: 'Cloudy', wind_mph: 2 },
    ]);
    const s = weatherSummary(A, rain, 11, 14);
    expect(spec(s.temperature)).toBe('55 to 62°F');
    expect(spec(s.word + ' ' + s.chance)).toBe('Rain 40%');
    expect(spec(s.text)).toBe('55 to 62°F · Rain 40%');
    expect(s.icon).toBe('rain');
    expect(s.noChance).toBe(false);
    expect(spec(weatherTitle(s, null))).toBe('Forecast for the area around your base');
  });

  it('names storms and snow, and prints one figure when the temperature does not move', () => {
    const storm = weatherSummary(A, hours([{ temp_f: 88, precip_prob: 60, short_forecast: 'Showers And Thunderstorms Likely', wind_mph: 12 }]), 11, 12);
    expect(spec(storm.word + ' ' + storm.chance)).toBe('Storms 60%');
    expect(storm.temperature).toBe('88°F');
    expect(storm.text).toBe('88°F · Storms 60%');
    const snow = weatherSummary(A, hours([{ temp_f: 30, precip_prob: 70, short_forecast: 'Snow', wind_mph: 5 }]), 11, 12);
    expect(spec(snow.word + ' ' + snow.chance)).toBe('Snow 70%');
  });

  it('takes the worst class of the stretch, not the first or the most likely', () => {
    const mixed = hours([
      { temp_f: 50, precip_prob: 90, short_forecast: 'Light Rain', wind_mph: 5 },
      { temp_f: 50, precip_prob: 20, short_forecast: 'Thunderstorms', wind_mph: 5 },
      { temp_f: 50, precip_prob: 55, short_forecast: 'Thunderstorms', wind_mph: 5 },
    ]);
    const s = weatherSummary(A, mixed, 11, 14);
    expect(s.precipClass).toBe('storm');
    expect(s.text).toBe('50°F · Storms 55%');
    // only the hours asked for count
    expect(weatherSummary(A, mixed, 11, 12).text).toBe('50°F · Light rain 90%');
    // an owner who made rain worse than storms sees rain named
    const B = makeAssumptions({ 'weather.precip_classes.rows.light_rain.open': 0.1 }, A.region);
    expect(weatherSummary(B, mixed, 11, 14).precipClass).toBe('light_rain');
  });

  it('"Dry" carries no percentage; the icon follows wind and temperature', () => {
    const dry = weatherSummary(A, hours([{ temp_f: 70, precip_prob: 10, short_forecast: 'Sunny', wind_mph: 5 }]), 11, 12);
    expect(spec(dry.word)).toBe('Dry');
    expect(dry.chance).toBeNull();
    expect(dry.text).toBe('70°F · Dry');
    expect(dry.icon).toBe('sun');
    expect(weatherSummary(A, hours([{ temp_f: 70, short_forecast: 'Sunny', wind_mph: 25 }]), 11, 12).icon).toBe('wind');
    expect(weatherSummary(A, hours([{ temp_f: 97, short_forecast: 'Sunny', wind_mph: 5 }]), 11, 12).icon).toBe('thermometer');
    expect(weatherSummary(A, hours([{ temp_f: 30, short_forecast: 'Mostly Cloudy', wind_mph: 5 }]), 11, 12).icon).toBe('thermometer');
  });

  it('prints the class alone when the forecast gives no chance for it, and says so in the title', () => {
    const s = weatherSummary(A, hours([{ temp_f: 50, precip_prob: null, short_forecast: 'Light Rain Likely', wind_mph: null }, { temp_f: 52, precip_prob: null, short_forecast: 'Rain', wind_mph: null }]), 11, 13);
    expect(s.text).toBe('50 to 52°F · Rain');
    expect(s.chance).toBeNull();
    expect(s.noChance).toBe(true);
    expect(spec(weatherTitle(s, null))).toBe('Forecast for the area around your base. The forecast gives no chance for these hours.');
    // one hour of the class with a chance is enough
    const some = weatherSummary(A, hours([{ temp_f: 50, precip_prob: null, short_forecast: 'Rain' }, { temp_f: 52, precip_prob: 35, short_forecast: 'Rain' }]), 11, 13);
    expect(some.text).toBe('50 to 52°F · Rain 35%');
  });

  it('says when a forecast is stale', () => {
    const s = weatherSummary(A, hours([{ temp_f: 62, precip_prob: 0, short_forecast: 'Cloudy', wind_mph: 2 }]), 11, 12);
    expect(spec(weatherTitle(s, { minute: 375 }))).toBe('Forecast for the area around your base from 6:15 AM, may be out of date');
  });

  it('has no forecast to show for a missing day, missing hours or empty records', () => {
    expect(weatherSummary(A, null, 11, 20)).toMatchObject({ usable: false, text: 'No forecast yet' });
    expect(weatherSummary(A, new Array(24).fill(null), 11, 20).usable).toBe(false);
    expect(weatherSummary(A, hours([{}]), 11, 12).usable).toBe(false);
    // hours outside the stretch do not count
    expect(weatherSummary(A, hours([{ temp_f: 62, short_forecast: 'Sunny' }], 8), 11, 20).usable).toBe(false);
    // an empty stretch is the single hour it starts at
    expect(weatherSummary(A, hours([{ temp_f: 62, short_forecast: 'Sunny' }]), 11, 11).text).toBe('62°F · Dry');
    expect(weatherSummary(A, hours([{ short_forecast: 'Rain', precip_prob: 80 }]), 11, 12).text).toBe('Rain 80%');
  });
});

describe('HolidayChip: what it says', () => {
  const ctx = (date: string, treatAs: Parameters<typeof dayContext>[2]) => dayContext(A, date, treatAs, null, null, null);

  it('names the holiday, or nothing on an ordinary day', () => {
    expect(holidayChipText(ctx('2026-11-26', null))).toBe('Thanksgiving Day');
    expect(holidayChipText(ctx('2026-10-08', null))).toBeNull();
    expect(holidayChipText(typicalContext(A, 3))).toBeNull();
  });

  it('says how the owner asked to treat the day', () => {
    expect(spec(holidayChipText(ctx('2026-10-08', 'sat')))).toBe('Treated as a Saturday');
    expect(holidayChipText(ctx('2026-11-26', 'mon'))).toBe('Treated as a Monday');
    expect(spec(holidayChipText(ctx('2026-11-26', 'normal')))).toBe('Holiday ignored');
    expect(holidayChipText(ctx('2026-10-08', 'normal'))).toBeNull();
    expect(spec(holidayChipText(ctx('2026-10-08', 'holiday')))).toBe('Treated as a holiday');
    expect(holidayChipText(ctx('2026-11-26', 'holiday'))).toBe('Thanksgiving Day');
  });
});

describe('worked examples', () => {
  it('this file asserts 22 values the specification prints', () => {
    expect(specCount()).toBe(22);
  });
});
