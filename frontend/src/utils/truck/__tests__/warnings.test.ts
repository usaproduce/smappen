// The sentences of the planner warnings (docs/truck-planner/05_FRONTEND.md 6.5, 8.2).
//
// Every warning here is raised by the model: the day plans are the inputs of golden family g18
// (02_MODEL 4.12), evaluated with dayPlan, so each of the 21 codes is worded from the data the model
// really attaches to it.

import { afterEach, describe, expect, it, vi } from 'vitest';
import { WARNING_CODES, dayPlan } from '../model';
import type { DayContext, DayResult } from '../model';
import { warningRows, warningStopName, warningText } from '../warnings';
import type { WarningLike } from '../warnings';
import { assume, goldenCase, goldenFamily, spec, specCount } from './_kitFixtures';

interface Day {
  result: DayResult;
  names: string[];
  ctx: DayContext;
}

function day(id: string): Day {
  const a = goldenCase(id).args;
  return {
    result: dayPlan(assume(a.A), a.profile, a.plan, a.ctx, a.ctx_next, a.legs, a.cal),
    names: a.plan.stops.map((s: { id: string }) => 'The ' + s.id),
    ctx: a.ctx,
  };
}

/** The sentences of one code in a day, in the model's order. */
function sentences(id: string, code: string): string[] {
  const d = day(id);
  return warningRows(d.result, d.names, d.ctx)
    .filter((r) => r.code === code)
    .map((r) => r.text);
}

afterEach(() => {
  vi.restoreAllMocks();
});

describe('warningText', () => {
  it('words every warning of every golden day plan, and all 21 codes appear', () => {
    const seen: Record<string, number> = {};
    for (const c of goldenFamily('day_plan')) {
      const d = day(c.id);
      const rows = warningRows(d.result, d.names, d.ctx);
      expect(rows.length).toBe(d.result.warnings.length);
      expect(rows.map((r) => r.code)).toEqual(c.expected.warnings.map((w: { code: string }) => w.code));
      for (const row of rows) {
        seen[row.code] = (seen[row.code] || 0) + 1;
        expect(row.text.length).toBeGreaterThan(10);
        expect(row.text.endsWith('.')).toBe(true);
        expect(/[{}]/.test(row.text), row.text).toBe(false);
        expect(row.text.includes('—'), row.text).toBe(false);
        expect(row.text.includes('undefined')).toBe(false);
        expect(row.text).not.toBe('Check this stop.');
      }
    }
    expect(Object.keys(seen).sort()).toEqual(WARNING_CODES.slice().sort());
    expect(Object.keys(seen).length).toBe(21);
  });

  it('errors: invalid_window, stops_overlap, stop_unreachable, stale_vectors', () => {
    expect(spec(sentences('g18-010', 'invalid_window'))).toEqual([
      'The oops: the closing time must be after the opening time.',
      'The late: the closing time must be after the opening time.',
    ]);
    expect(spec(sentences('g18-010', 'stops_overlap'))).toEqual(['The taproom opens before the stop before it closes.']);
    expect(spec(sentences('g18-013', 'stop_unreachable'))).toEqual(['The gone: you cannot arrive and set up before it closes.']);
    expect(spec(sentences('g18-011', 'stale_vectors'))).toEqual([
      "The office: this estimate is out of date. It updates when the spot's details finish saving.",
    ]);
  });

  it('warnings about one stop', () => {
    expect(spec(sentences('g18-013', 'late_arrival'))).toEqual(['The far: you would open 33 min late, at 3:18 PM.']);
    expect(spec(sentences('g18-011', 'outside_region'))).toEqual([
      'The office is outside the loaded counties. People around it are missing or only partly counted.',
    ]);
    expect(spec(sentences('g18-011', 'outside_allowed_hours'))).toEqual(['The office falls outside the days or hours you set for this spot.']);
    expect(spec(sentences('g18-001', 'long_gap'))).toEqual(['2 h of paid waiting before The taproom.']);
    expect(sentences('g18-014', 'long_gap')).toEqual(['11 h 30 min of paid waiting before The taproom.']);
    expect(spec(sentences('g18-006', 'fee_high'))).toEqual(['The fair: the fee is 12% of expected sales.']);
    expect(sentences('g18-015', 'fee_high')).toEqual(['The office: the fee is 113% of expected sales.']);
    expect(spec(sentences('g18-013', 'below_break_even'))).toEqual([
      'The far is expected to lose money once its added costs are counted.',
      'The gone is expected to lose money once its added costs are counted.',
    ]);
    expect(spec(sentences('g18-006', 'event_thin_crowd'))).toEqual(['The fair: a thin crowd for the number of food vendors.']);
  });

  it('warnings about the day', () => {
    expect(spec(sentences('g18-013', 'fallback_drive_time'))).toEqual(['Some drive times are straight-line estimates, not Google drive times.']);
    expect(spec(sentences('g18-014', 'long_day'))).toEqual(['This is a 21 h 47 min day, prep to done.']);
  });

  it('notes', () => {
    expect(spec(sentences('g18-001', 'weak_day_loss'))).toEqual(['The taproom loses money on a weak day.']);
    expect(spec(sentences('g18-016', 'capacity_bound'))).toEqual(['The taproom: demand is above what the truck can serve for part of the time.']);
    expect(spec(sentences('g18-014', 'early_start'))).toEqual(['Prep starts at 4:04 AM.']);
    expect(spec(sentences('g18-014', 'ends_after_midnight'))).toEqual(['The day ends after midnight, at 1:51 AM (next day).']);
    expect(spec(sentences('g18-001', 'no_forecast'))).toEqual(['No forecast for some of these hours, so no weather adjustment there.']);
    expect(spec(sentences('g18-018', 'holiday'))).toEqual(['Thanksgiving Day is a federal holiday. Patterns follow the holiday settings.']);
    expect(spec(sentences('g18-017', 'weak_seed'))).toEqual([
      'The campus: most of this estimate rests on hospital, campus or transit figures, the weakest in the model.',
    ]);
    expect(spec(sentences('g18-016', 'default_host_size'))).toEqual([
      'The taproom: the host size is a typical figure for this kind of place. Enter the real size to tighten the range.',
    ]);
  });

  it('a day that is a holiday only by the owner\'s choice has no holiday name to print', () => {
    const d = day('g18-019');
    expect(d.ctx.treat_as).toBe('holiday');
    expect(d.ctx.holiday).toBeNull();
    expect(sentences('g18-019', 'holiday')).toEqual(['This day is treated as a holiday. Patterns follow the holiday settings.']);
  });

  it('carries the level as a visible word', () => {
    const d = day('g18-013');
    const rows = warningRows(d.result, d.names, d.ctx);
    expect(rows.map((r) => [r.code, r.level, r.prefix])).toEqual([
      ['stop_unreachable', 'error', 'Problem:'],
      ['late_arrival', 'warn', 'Check:'],
      ['fallback_drive_time', 'warn', 'Check:'],
      ['below_break_even', 'warn', 'Check:'],
      ['below_break_even', 'warn', 'Check:'],
      ['no_forecast', 'info', 'Note:'],
    ]);
    expect(rows.map((r) => r.stopIndex)).toEqual([2, 1, null, 1, 2, null]);
    expect(rows.map((r) => r.index)).toEqual([0, 1, 2, 3, 4, 5]);
  });

  it('keeps the model\'s order and gives nothing for a day without warnings', () => {
    for (const id of ['g18-009', 'g18-020']) {
      const d = day(id);
      expect(warningRows(d.result, d.names, d.ctx)).toEqual([]);
    }
  });

  it('names a stop that has no name', () => {
    const d = day('g18-001');
    const gap = d.result.warnings.filter((w) => w.code === 'long_gap')[0];
    expect(warningText(gap, d.result, [], d.ctx)).toBe('2 h of paid waiting before Stop 2.');
    expect(warningText(gap, d.result, ['Office', ''], d.ctx)).toBe('2 h of paid waiting before Stop 2.');
    expect(warningStopName(gap, ['Office', 'Lost Rhino'])).toBe('Lost Rhino');
    expect(warningStopName({ code: 'long_day', level: 'warn', stop_index: null, data: {} }, ['Office'])).toBe('');
  });

  it('reads the day from the result when a day warning arrives without its data', () => {
    const d = day('g18-014');
    const bare: WarningLike = { code: 'long_day', level: 'warn', stop_index: null, data: {} };
    expect(warningText(bare, d.result, d.names, d.ctx)).toBe('This is a 21 h 47 min day, prep to done.');
    expect(warningText({ code: 'early_start', level: 'info', stop_index: null, data: {} }, d.result, d.names, d.ctx)).toBe('Prep starts at 4:04 AM.');
  });
});

describe('an unknown code', () => {
  it('prints the fallback text and is logged once', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const d = day('g18-001');
    const strange: WarningLike = { code: 'a_code_from_a_later_model', level: 'warn', stop_index: 0, data: { anything: 1 } };
    expect(spec(warningText(strange, d.result, d.names, d.ctx))).toBe('Check this stop.');
    expect(warningText(strange, d.result, d.names, d.ctx)).toBe('Check this stop.');
    expect(warningText(strange, null, [], null)).toBe('Check this stop.');
    expect(warn).toHaveBeenCalledTimes(1);
    expect(String(warn.mock.calls[0][1])).toBe('a_code_from_a_later_model');

    // it never crashes a list, whatever the level
    const result: DayResult = { ...d.result, warnings: [...d.result.warnings, strange as never, { code: 'another_one', level: 'loud', stop_index: null, data: {} } as never] };
    const rows = warningRows(result, d.names, d.ctx);
    expect(rows[rows.length - 2]).toMatchObject({ code: 'a_code_from_a_later_model', level: 'warn', prefix: 'Check:', text: 'Check this stop.' });
    expect(rows[rows.length - 1]).toMatchObject({ code: 'another_one', level: 'info', prefix: 'Note:', text: 'Check this stop.' });
    expect(warn).toHaveBeenCalledTimes(2);
  });

  it('a code that is a property of every object is still unknown', () => {
    vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    for (const code of ['constructor', 'toString', '__proto__', 'hasOwnProperty']) {
      expect(warningText({ code, level: 'info', stop_index: null, data: {} }, null, [], null)).toBe('Check this stop.');
    }
  });
});

describe('worked examples', () => {
  it('this file asserts 22 values the specification prints', () => {
    expect(specCount()).toBe(22);
  });
});
