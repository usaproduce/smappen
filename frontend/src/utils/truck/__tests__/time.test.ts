// Hours of the week and week dates (docs/truck-planner/05_FRONTEND.md 3.1, 8.2).

import { describe, expect, it } from 'vitest';
import { dayOfWeek } from '../model';
import { howOf, howParts, mondayOf, nextDateWithDow, weekDates } from '../time';
import { spec, specCount } from './_kitFixtures';

describe('howOf and howParts', () => {
  it('round-trip for every hour of the week', () => {
    for (let how = 0; how < 168; how++) {
      const p = howParts(how);
      expect(p.dow).toBe(Math.floor(how / 24));
      expect(p.hour).toBe(how % 24);
      expect(howOf(p.dow, p.hour)).toBe(how);
    }
  });

  it('how = dow * 24 + hour with Monday first', () => {
    expect(spec(howOf(3, 12))).toBe(84); // Thursday 12 PM, the example of 3.1
    expect(howOf(0, 0)).toBe(0);
    expect(howOf(6, 23)).toBe(167);
    expect(howParts(84)).toEqual({ dow: 3, hour: 12 });
  });

  it('wraps outside 0..167', () => {
    expect(howParts(168)).toEqual({ dow: 0, hour: 0 });
    expect(howParts(-1)).toEqual({ dow: 6, hour: 23 });
    expect(howParts(167 + 168 * 3)).toEqual({ dow: 6, hour: 23 });
    expect(howOf(7, 0)).toBe(0);
    expect(howOf(-1, 24)).toBe(144);
  });
});

describe('mondayOf and weekDates', () => {
  it('finds the Monday of a week', () => {
    expect(mondayOf('2026-10-08')).toBe('2026-10-05'); // a Thursday
    expect(mondayOf('2026-10-05')).toBe('2026-10-05'); // a Monday stays
    expect(mondayOf('2026-10-11')).toBe('2026-10-05'); // a Sunday belongs to the week before it ends
  });

  it('crosses a year boundary', () => {
    expect(mondayOf('2027-01-01')).toBe('2026-12-28'); // a Friday
    expect(mondayOf('2026-01-01')).toBe('2025-12-29'); // a Thursday
    expect(mondayOf('2025-12-31')).toBe('2025-12-29');
    expect(dayOfWeek(mondayOf('2027-01-03'))).toBe(0);
  });

  it('lists the seven dates of a week, across a month end', () => {
    expect(weekDates('2026-09-28')).toEqual(['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']);
    expect(weekDates('2026-12-28')[6]).toBe('2027-01-03');
    for (const d of weekDates('2026-10-05')) expect(mondayOf(d)).toBe('2026-10-05');
  });
});

describe('nextDateWithDow', () => {
  it('returns today when today is that weekday', () => {
    expect(nextDateWithDow('2026-10-08', 3)).toBe('2026-10-08');
    expect(nextDateWithDow('2026-10-05', 0)).toBe('2026-10-05');
  });

  it('returns the next date with that weekday otherwise', () => {
    expect(nextDateWithDow('2026-10-08', 4)).toBe('2026-10-09');
    expect(nextDateWithDow('2026-10-08', 2)).toBe('2026-10-14');
    expect(nextDateWithDow('2026-10-08', 6)).toBe('2026-10-11');
    expect(nextDateWithDow('2026-12-30', 4)).toBe('2027-01-01');
    for (let dow = 0; dow < 7; dow++) expect(dayOfWeek(nextDateWithDow('2026-10-08', dow))).toBe(dow);
  });
});

describe('worked examples', () => {
  it('this file asserts 1 value the specification prints', () => {
    expect(specCount()).toBe(1);
  });
});
