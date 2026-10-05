// One byte per cell for an hour of the week (docs/truck-planner/05_FRONTEND.md 5.4, 5.5, 8.2;
// docs/truck-planner/02_MODEL.md 4.17).
//
// The packs are written by _packFixture.ts and read back through the real decoder, so the frames are
// computed from what a browser would hold: 16-bit codes decoded to 32-bit floats.

import { describe, expect, it } from 'vitest';
import { MAP_DOMAIN, cellScores, dayContext, mapWeightRows, scoreByte, seed, typicalContext } from '../model';
import type { Assumptions, CalibrationState, MapLayer, Regime, TruckProfile } from '../model';
import { minByte } from '../palette';
import { createFrameSource, meanByte } from '../map/frames';
import { decodePack } from '../map/pack';
import { PACK_K, diskIds, encodePack, syntheticValues } from './_packFixture';
import { assume, clone, goldenCase, spec } from './_kitFixtures';

/* eslint-disable @typescript-eslint/no-explicit-any */

const WEEK = goldenCase('g23-001').args as any; // map_weight_rows: the reference truck, no calibration
const A: Assumptions = assume(WEEK.A);
const PROFILE: TruckProfile = WEEK.profile;
// The cell of 02_MODEL 4.17: the truck position of 4.4, 50 numbers.
const CELL: number[] = (goldenCase('g23-003').args as any).features;
const ONE_ID = ['892aaab3043ffff'];
const LAYERS: MapLayer[] = ['opportunity', 'people', 'competition'];
const REGIME_OF_HOUR = seed<Regime[]>(A, 'hours.regime_of_hour');

function calibration(truckFactor: number): CalibrationState {
  return {
    model_version: 'tps-0.1.0',
    seeds_revision: A.seeds_revision,
    as_of: '2026-10-04',
    truck_factor: truckFactor,
    truck_log_factor: Math.log(truckFactor),
    bias_log: 0.0,
    truck_n: 9,
    truck_weight: 7.5,
    spots: {},
    resid_sd: null,
    resid_n: 0,
    resid_weight: 0.0,
  };
}

function onePack(values: ArrayLike<number>) {
  return decodePack(encodePack({ ids: ONE_ID, values }).buffer);
}

function frame(source: ReturnType<typeof createFrameSource>, layer: MapLayer, how: number, date: string | null = null): Uint8Array {
  const out = new Uint8Array(source.n);
  source.fill(layer, how, date, out);
  return out;
}

describe('the worked cell of the model', () => {
  const source = createFrameSource(onePack(CELL), { A, profile: PROFILE, cal: null });

  it('gives bytes 206, 38 and 23 on Thursday at noon', () => {
    expect(source.n).toBe(1);
    expect(Math.abs(spec(frame(source, 'opportunity', 84)[0]) - 206)).toBeLessThanOrEqual(1);
    expect(Math.abs(spec(frame(source, 'people', 84)[0]) - 38)).toBeLessThanOrEqual(1);
    expect(Math.abs(spec(frame(source, 'competition', 84)[0]) - 23)).toBeLessThanOrEqual(1);
  });

  it('uses the fixed domain of the week: 45 orders, 20,000 people, rival pull 100', () => {
    expect(MAP_DOMAIN).toEqual({ opportunity: 45, people: 20000, competition: 100 });
    expect(spec(scoreByte(29.436015, 45))).toBe(206);
    expect(spec(scoreByte(437.11, 20000))).toBe(38);
    expect(spec(scoreByte(0.833985, 100))).toBe(23);
  });

  it('clamps opportunity at the capacity of the truck', () => {
    const small = createFrameSource(onePack(CELL), { A, profile: { ...PROFILE, capacity_orders_per_hour: 10 }, cal: null });
    // 29.4 orders an hour of demand, 10 an hour of capacity.
    expect(frame(small, 'opportunity', 84)[0]).toBe(scoreByte(10, 45));
    expect(scoreByte(10, 45)).toBe(120);
    // Capacity caps opportunity only.
    expect(frame(small, 'people', 84)[0]).toBe(frame(source, 'people', 84)[0]);
    expect(frame(small, 'competition', 84)[0]).toBe(frame(source, 'competition', 84)[0]);
    const none = createFrameSource(onePack(CELL), { A, profile: { ...PROFILE, capacity_orders_per_hour: 0 }, cal: null });
    expect(frame(none, 'opportunity', 84)[0]).toBe(0);
  });
});

describe('the floor of each layer', () => {
  it('is byte 12, 13 and 13', () => {
    expect(spec(minByte('opportunity'))).toBe(12);
    expect(spec(minByte('people'))).toBe(13);
    expect(spec(minByte('competition'))).toBe(13);
  });

  it('zeroes a byte under the floor and keeps the floor itself', () => {
    // Scale the worked cell so that its scores land just under and just over each floor.
    const scores = { opportunity: 29.43601508396633, people: 437.11003234162956, competition: 0.8339845916891228 };
    const floors = { opportunity: 0.1, people: 50, competition: 0.25 };
    for (const layer of LAYERS) {
      const under = CELL.map((v) => (v * floors[layer] * 0.8) / scores[layer]);
      const over = CELL.map((v) => (v * floors[layer] * 1.2) / scores[layer]);
      const low = frame(createFrameSource(onePack(under), { A, profile: PROFILE, cal: null }), layer, 84)[0];
      const high = frame(createFrameSource(onePack(over), { A, profile: PROFILE, cal: null }), layer, 84)[0];
      expect(scoreByte(floors[layer] * 0.8, MAP_DOMAIN[layer])).toBeGreaterThan(0); // it would have had a colour
      expect(low, layer).toBe(0);
      expect(high, layer).toBeGreaterThanOrEqual(minByte(layer));
      expect(high, layer).toBe(scoreByte(floors[layer] * 1.2, MAP_DOMAIN[layer]));
    }
  });
});

describe('a region of 500 cells', () => {
  const n = 500;
  const pack = decodePack(encodePack({ ids: diskIds(n), values: syntheticValues(n, 4242) }).buffer);
  const source = createFrameSource(pack, { A, profile: PROFILE, cal: null });
  const rows = mapWeightRows(A, PROFILE, null);
  const decoded = Array.from(pack.features);

  function definition(layer: MapLayer, how: number, capacity = PROFILE.capacity_orders_per_hour): number[] {
    const scores = cellScores(decoded, n, rows.w_opp[how], rows.w_people[how], REGIME_OF_HOUR[how % 24], capacity)[layer];
    return scores.map((x) => {
      const b = scoreByte(x, MAP_DOMAIN[layer]);
      return b < minByte(layer) ? 0 : b;
    });
  }

  it('matches the definition of the model to one byte at every hour of the week', () => {
    let coloured = 0;
    for (let how = 0; how < 168; how++) {
      for (const layer of LAYERS) {
        const got = frame(source, layer, how);
        const want = definition(layer, how);
        for (let c = 0; c < n; c++) {
          // One byte of slack for the 32-bit sums; a cell right at the floor may fall either side of it.
          const ok = Math.abs(got[c] - want[c]) <= 1 || (Math.max(got[c], want[c]) <= minByte(layer) && Math.min(got[c], want[c]) === 0);
          if (!ok) throw new Error(layer + ' at hour ' + how + ', cell ' + c + ': frame ' + got[c] + ', definition ' + want[c]);
          if (got[c] > 0) coloured++;
        }
      }
    }
    expect(coloured).toBeGreaterThan(50000);
  });

  it('wraps the hour of the week and refuses what is not a number', () => {
    expect(Array.from(frame(source, 'people', 168 + 84))).toEqual(Array.from(frame(source, 'people', 84)));
    expect(Array.from(frame(source, 'people', -84))).toEqual(Array.from(frame(source, 'people', 84)));
    expect(() => frame(source, 'people', Number.NaN)).toThrow(RangeError);
    expect(() => source.fill('people', 84, null, new Uint8Array(n - 1))).toThrow(RangeError);
  });

  it('writes into a buffer the caller owns, and only its first n bytes', () => {
    const out = new Uint8Array(n + 8).fill(7);
    source.fill('opportunity', 84, null, out);
    expect(Array.from(out.subarray(n))).toEqual(new Array(8).fill(7));
    expect(Array.from(out.subarray(0, n))).toEqual(Array.from(frame(source, 'opportunity', 84)));
    source.fill('competition', 84, null, out);
    expect(Array.from(out.subarray(n))).toEqual(new Array(8).fill(7));
  });

  it('date rows equal week rows for a typical context', () => {
    // 2026-10-08 is an ordinary Thursday: its context is the typical Thursday.
    const ctx = dayContext(A, '2026-10-08', null, null, null, null);
    expect(ctx.dow).toBe(3);
    expect(ctx.holiday).toBeNull();
    expect(ctx.day_types).toEqual(typicalContext(A, 3).day_types);
    for (let hour = 0; hour < 24; hour++) {
      for (const layer of LAYERS) {
        expect(Array.from(frame(source, layer, 72 + hour, '2026-10-08'))).toEqual(Array.from(frame(source, layer, 72 + hour)));
      }
    }
  });

  it('a holiday changes the frames of its day', () => {
    // Thanksgiving 2026 is a Thursday.
    const ctx = dayContext(A, '2026-11-26', null, null, null, null);
    expect(ctx.dow).toBe(3);
    expect(ctx.holiday_class).toBe('major');
    let different = 0;
    for (let hour = 0; hour < 24; hour++) {
      const typical = frame(source, 'opportunity', 72 + hour);
      const holiday = frame(source, 'opportunity', 72 + hour, '2026-11-26');
      for (let c = 0; c < n; c++) if (typical[c] !== holiday[c]) different++;
    }
    expect(different).toBeGreaterThan(1000);
  });

  it('with a date the hour of day decides, whatever weekday the hour of the week names', () => {
    // Monday 12:00 asked for while Thanksgiving is selected: the rows of Thanksgiving at 12:00.
    const typical = Array.from(frame(source, 'opportunity', 84));
    const holiday = Array.from(frame(source, 'opportunity', 84, '2026-11-26'));
    expect(holiday).not.toEqual(typical);
    expect(Array.from(frame(source, 'opportunity', 12, '2026-11-26'))).toEqual(holiday);
    expect(Array.from(frame(source, 'opportunity', 6 * 24 + 12, '2026-11-26'))).toEqual(holiday);
    // Another date replaces the first; going back to the typical week gives the typical frame again.
    expect(Array.from(frame(source, 'opportunity', 84, '2026-10-08'))).toEqual(typical);
    expect(Array.from(frame(source, 'opportunity', 84, null))).toEqual(typical);
  });

  it('an impossible date is refused by the model', () => {
    expect(() => frame(source, 'opportunity', 84, '2026-02-30')).toThrow();
    // ... and the source still works afterwards.
    expect(frame(source, 'opportunity', 84).length).toBe(n);
  });

  it('competition has two frames: day and evening', () => {
    const seen = new Map<string, number[]>();
    for (let how = 0; how < 168; how++) {
      const key = Array.from(frame(source, 'competition', how)).join(',');
      const hours = seen.get(key) ?? [];
      hours.push(how);
      seen.set(key, hours);
    }
    expect(seen.size).toBe(2);
    const byRegime = { day: [] as number[], eve: [] as number[] };
    for (let how = 0; how < 168; how++) byRegime[REGIME_OF_HOUR[how % 24]].push(how);
    const groups = [...seen.values()].map((hours) => hours.join(','));
    expect(groups).toContain(byRegime.day.join(','));
    expect(groups).toContain(byRegime.eve.join(','));
    // The day frame is the rival pull by day, the evening frame the pull in the evening.
    const day = frame(source, 'competition', 84);
    const eve = frame(source, 'competition', 90);
    for (let c = 0; c < n; c++) {
      const d = scoreByte(pack.features[c * PACK_K + 48], 100);
      const e = scoreByte(pack.features[c * PACK_K + 49], 100);
      expect(day[c]).toBe(d < 13 ? 0 : d);
      expect(eve[c]).toBe(e < 13 ? 0 : e);
    }
  });
});

describe('setInputs', () => {
  const n = 200;
  const pack = decodePack(encodePack({ ids: diskIds(n), values: syntheticValues(n, 99) }).buffer);

  it('says whether frames may have changed', () => {
    const source = createFrameSource(pack, { A, profile: PROFILE, cal: null });
    expect(source.setInputs({ A, profile: PROFILE, cal: null })).toBe(false);
    // A new profile object with the same figures changes nothing.
    expect(source.setInputs({ A, profile: clone(PROFILE), cal: null })).toBe(false);
    // Things the map does not read.
    expect(source.setInputs({ A, profile: { ...PROFILE, avg_ticket: 22, mpg: 7 }, cal: null })).toBe(false);
    expect(source.setInputs({ A, profile: PROFILE, cal: calibration(1.0) })).toBe(false);
    // Things it does.
    expect(source.setInputs({ A, profile: { ...PROFILE, capacity_orders_per_hour: 20 }, cal: null })).toBe(true);
    expect(source.setInputs({ A, profile: PROFILE, cal: null })).toBe(true);
    expect(source.setInputs({ A, profile: { ...PROFILE, daypart_fit: { ...PROFILE.daypart_fit, lunch: 0.5 } }, cal: null })).toBe(true);
    expect(source.setInputs({ A, profile: PROFILE, cal: calibration(1.3) })).toBe(true);
    expect(source.setInputs({ A: assume(WEEK.A), profile: PROFILE, cal: calibration(1.3) })).toBe(true);
  });

  it('new inputs give the frames of a source built with them', () => {
    const source = createFrameSource(pack, { A, profile: PROFILE, cal: null });
    const before = Array.from(frame(source, 'opportunity', 84));
    const changes: { profile: TruckProfile; cal: CalibrationState | null }[] = [
      { profile: { ...PROFILE, daypart_fit: { ...PROFILE.daypart_fit, lunch: 0.4 } }, cal: null },
      { profile: PROFILE, cal: calibration(1.6) },
      { profile: { ...PROFILE, capacity_orders_per_hour: 12 }, cal: calibration(0.7) },
    ];
    for (const change of changes) {
      source.setInputs({ A, ...change });
      const fresh = createFrameSource(pack, { A, ...change });
      for (const how of [84, 90, 30]) {
        for (const layer of LAYERS) {
          expect(Array.from(frame(source, layer, how))).toEqual(Array.from(frame(fresh, layer, how)));
          expect(Array.from(frame(source, layer, how, '2026-11-26'))).toEqual(Array.from(frame(fresh, layer, how, '2026-11-26')));
        }
      }
      expect(Array.from(frame(source, 'opportunity', 84))).not.toEqual(before);
    }
    // People nearby does not depend on the menu, the truck factor or the capacity.
    const people = Array.from(frame(createFrameSource(pack, { A, profile: PROFILE, cal: null }), 'people', 84));
    expect(Array.from(frame(source, 'people', 84))).toEqual(people);
  });
});

describe('meanByte', () => {
  it('averages a subset to the nearest whole byte', () => {
    const values = Uint8Array.from([10, 20, 30, 41, 250]);
    expect(meanByte(values, null, 5)).toBe(70);
    expect(meanByte(values, null, 2)).toBe(15);
    expect(meanByte(values, Uint32Array.from([3, 4]), 2)).toBe(146); // 145.5 rounds up
    expect(meanByte(values, Uint32Array.from([0, 3]), 2)).toBe(26); // 25.5 rounds up
    expect(meanByte(values, Uint32Array.from([4, 0, 0, 0]), 1)).toBe(250);
    expect(meanByte(values, null, 0)).toBe(0);
    expect(meanByte(new Uint8Array(0), null, 0)).toBe(0);
  });
});
