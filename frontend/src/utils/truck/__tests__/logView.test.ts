// Pure helpers of the Log (docs/truck-planner/05_FRONTEND.md 4.7, 8.2).

import { describe, expect, it } from 'vitest';
import { calibrate } from '../model';
import type { ServiceLogEntry } from '../model';
import { calibrationBefore, resultText, unloggedStops, verdictOf, verdictWords } from '../logView';
import type { PlanLike } from '../logView';
import { assume, goldenCase, spec, specCount } from './_kitFixtures';

const NOW = { date: '2026-10-08', minute: 15 * 60 }; // Thursday 3:00 PM in the truck's zone

const stop = (id: string, kind: 'spot' | 'event' | 'catering', open: number, close: number, spotId: string | null = null, label = '') => ({
  id,
  kind,
  spot_id: spotId,
  label,
  open_minute: open,
  close_minute: close,
});

const PLANS: PlanLike[] = [
  { id: 'p-wed', date: '2026-10-07', status: 'planned', stops: [stop('w1', 'spot', 660, 840, 'spot-a'), stop('w2', 'spot', 1020, 1200, 'spot-b')] },
  { id: 'p-thu', date: '2026-10-08', status: 'planned', stops: [stop('t1', 'spot', 660, 840, 'spot-a'), stop('t2', 'spot', 1020, 1200, 'spot-b')] },
  { id: 'p-tue', date: '2026-10-06', status: 'cancelled', stops: [stop('c1', 'spot', 660, 840, 'spot-a')] },
  { id: 'p-mon', date: '2026-10-05', status: 'done', stops: [stop('m1', 'event', 600, 900, null, 'Fall fair'), stop('m2', 'catering', 1080, 1200, null, 'Wedding')] },
  { id: 'p-sun', date: '2026-10-04', status: 'draft', stops: [stop('s1', 'spot', 1290, 1500, 'spot-b')] },
];

const SPOT_NAMES = { 'spot-a': 'Reston office park', 'spot-b': 'Lost Rhino taproom' };

describe('unloggedStops', () => {
  it('lists stops whose closing time has passed and that no service refers to, newest first', () => {
    const list = unloggedStops(PLANS, [], NOW, { spotNames: SPOT_NAMES });
    expect(list.map((s) => s.stopId)).toEqual(['t1', 'w2', 'w1', 'm2', 'm1', 's1']);
    expect(list[0]).toEqual({
      planId: 'p-thu',
      date: '2026-10-08',
      stopId: 't1',
      kind: 'spot',
      spotId: 'spot-a',
      name: 'Reston office park',
      openMinute: 660,
      closeMinute: 840,
      stopIndex: 0,
    });
  });

  it('waits until the closing time has passed', () => {
    // today's second stop closes at 8 PM: not yet at 3 PM, due at 8 PM sharp
    expect(unloggedStops(PLANS, [], NOW).some((s) => s.stopId === 't2')).toBe(false);
    expect(unloggedStops(PLANS, [], { date: '2026-10-08', minute: 1199 }).some((s) => s.stopId === 't2')).toBe(false);
    expect(unloggedStops(PLANS, [], { date: '2026-10-08', minute: 1200 }).some((s) => s.stopId === 't2')).toBe(true);
    expect(unloggedStops(PLANS, [], { date: '2026-10-08', minute: 839 }).some((s) => s.stopId === 't1')).toBe(false);
  });

  it('counts a close after midnight on the next civil date', () => {
    // Sunday's stop runs 9:30 PM to 1:00 AM: at 12:30 AM on Monday it is still open
    expect(unloggedStops(PLANS, [], { date: '2026-10-05', minute: 30 }).map((s) => s.stopId)).toEqual([]);
    expect(unloggedStops(PLANS, [], { date: '2026-10-05', minute: 60 }).map((s) => s.stopId)).toEqual(['s1']);
    expect(unloggedStops(PLANS, [], { date: '2026-10-04', minute: 1439 }).map((s) => s.stopId)).toEqual([]);
  });

  it('leaves out a stop that a logged service carries as plan_stop_id', () => {
    const services = [{ plan_stop_id: 'w1' }, { plan_stop_id: null }, { plan_stop_id: 'm1' }];
    expect(unloggedStops(PLANS, services, NOW).map((s) => s.stopId)).toEqual(['t1', 'w2', 'm2', 's1']);
  });

  it('ignores cancelled plans, keeps drafts and finished days', () => {
    const list = unloggedStops(PLANS, [], NOW);
    expect(list.some((s) => s.planId === 'p-tue')).toBe(false);
    expect(list.some((s) => s.planId === 'p-sun')).toBe(true);
    expect(list.some((s) => s.planId === 'p-mon')).toBe(true);
  });

  it('includes event and catering stops, under their own names', () => {
    const list = unloggedStops(PLANS, [], NOW, { spotNames: SPOT_NAMES });
    expect(list.filter((s) => s.kind !== 'spot').map((s) => [s.kind, s.name, s.spotId])).toEqual([
      ['catering', 'Wedding', null],
      ['event', 'Fall fair', null],
    ]);
  });

  it('names a stop it has no name for, honours since and limit', () => {
    expect(unloggedStops(PLANS, [], NOW)[0].name).toBe('Stop 1');
    expect(unloggedStops(PLANS, [], NOW)[1].name).toBe('Stop 2');
    expect(unloggedStops(PLANS, [], NOW, { since: '2026-10-07' }).map((s) => s.stopId)).toEqual(['t1', 'w2', 'w1']);
    expect(unloggedStops(PLANS, [], NOW, { limit: 2 }).map((s) => s.stopId)).toEqual(['t1', 'w2']);
    expect(unloggedStops([], [], NOW)).toEqual([]);
  });
});

describe('verdictOf', () => {
  const range = { low: 33.0139, high: 93.1942 };

  it('gives the four verdicts of the specification', () => {
    expect(spec(verdictWords({ actual: 52, ...range, sold_out: false }))).toBe('inside the range');
    expect(spec(verdictWords({ actual: 120, ...range, sold_out: false }))).toBe('above the range');
    expect(spec(verdictWords({ actual: 20, ...range, sold_out: false }))).toBe('below the range');
    expect(spec(verdictWords({ actual: 45, ...range, sold_out: true }))).toBe('sold out, counted as a minimum');
  });

  it('counts the edges of the range as inside', () => {
    expect(verdictOf({ actual: 30, low: 30, high: 50, sold_out: false })).toBe('inside');
    expect(verdictOf({ actual: 50, low: 30, high: 50, sold_out: false })).toBe('inside');
    expect(verdictOf({ actual: 29, low: 30, high: 50, sold_out: false })).toBe('below');
    expect(verdictOf({ actual: 51, low: 30, high: 50, sold_out: false })).toBe('above');
    // the range is compared as stored, the way the accuracy report counts coverage
    expect(verdictOf({ actual: 33, ...range, sold_out: false })).toBe('below');
    expect(verdictOf({ actual: 34, ...range, sold_out: false })).toBe('inside');
    expect(verdictOf({ actual: 93, ...range, sold_out: false })).toBe('inside');
    expect(verdictOf({ actual: 94, ...range, sold_out: false })).toBe('above');
  });

  it('treats a sold-out service as a minimum wherever it lands', () => {
    for (const actual of [5, 33, 60, 93, 200]) expect(verdictOf({ actual, ...range, sold_out: true })).toBe('sold_out');
  });

  it('writes the result cell with the signed difference from the estimate', () => {
    expect(spec(resultText({ actual: 65, predicted: 60.4938, ...range, sold_out: false }))).toBe('+5, inside the range');
    expect(resultText({ actual: 52, predicted: 60.4938, ...range, sold_out: false })).toBe('-8, inside the range');
    expect(resultText({ actual: 60, predicted: 60.4938, ...range, sold_out: false })).toBe('0, inside the range');
    expect(resultText({ actual: 120, predicted: 60.4938, ...range, sold_out: false })).toBe('+60, above the range');
    expect(resultText({ actual: 45, predicted: 39.4, low: 20.76, high: 62.2, sold_out: true })).toBe('+6, sold out, counted as a minimum');
  });
});

describe('calibrationBefore', () => {
  const c = goldenCase('g19-001'); // the seven services of 02_MODEL 4.13
  const A = assume(c.args.A);
  const services: ServiceLogEntry[] = c.args.services;
  const byId = (ids: string[]) => services.filter((s) => ids.includes(s.service_id));

  it('uses only the services logged before the date: s1, s2 and s3 for 2026-08-10', () => {
    const cal = calibrationBefore(A, services, '2026-08-10');
    expect(cal).toEqual(calibrate(A, byId(['s1', 's2', 's3']), '2026-08-10'));
    expect(cal.as_of).toBe('2026-08-10');
    expect(spec(cal.truck_n)).toBe(3);
    expect(Object.keys(cal.spots).sort()).toEqual(['A', 'B']);
    expect(cal.spots.A.n).toBe(2);
    expect(cal.spots.B.n).toBe(1);
  });

  it('does not count a service logged on the date itself', () => {
    expect(calibrationBefore(A, services, '2026-08-01')).toEqual(calibrate(A, byId(['s1', 's2']), '2026-08-01'));
    expect(calibrationBefore(A, services, '2026-08-02')).toEqual(calibrate(A, byId(['s1', 's2', 's3']), '2026-08-02'));
  });

  it('is the empty calibration before the first service and the full one after the last', () => {
    const none = calibrationBefore(A, services, '2026-06-06');
    expect(none.truck_factor).toBe(1);
    expect(none.truck_n).toBe(0);
    expect(none.spots).toEqual({});
    const all = calibrationBefore(A, services, '2026-10-04');
    expect(all).toEqual(calibrate(A, services, '2026-10-04'));
    expect(spec(all.truck_n)).toBe(6);
    expect(spec(all.truck_factor)).toBeCloseTo(0.963784, 6);
  });

  it('does not depend on the order of the entries', () => {
    const reversed = services.slice().reverse();
    expect(calibrationBefore(A, reversed, '2026-08-10')).toEqual(calibrationBefore(A, services, '2026-08-10'));
  });
});

describe('worked examples', () => {
  it('this file asserts 8 values the specification prints', () => {
    expect(specCount()).toBe(8);
  });
});
