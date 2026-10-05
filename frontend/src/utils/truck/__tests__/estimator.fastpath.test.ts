// Truck Planner estimator - the map's bulk scorer against the definition (02_MODEL 4.17).
//
// fastPath.ts scores cells out of a Float32Array into caller-owned buffers. For the same feature
// values it must match cellScores (the slow path the golden cases pin down) to a relative 1e-5, and
// colour bytes to plus or minus 1. The cells here are synthetic and deterministic: a fixed linear
// congruential generator, never the runtime's random source.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import {
  COL_CAPTURE_DAY,
  COL_CAPTURE_EVE,
  COL_NEARBY,
  COL_RIVALS_DAY,
  COL_RIVALS_EVE,
  FEATURES_PER_CELL,
  FEATURE_COLUMNS,
  HOURS_PER_WEEK,
  MAP_DOMAIN,
  MAP_LAYERS,
  precomputeMapWeights,
  scoreCells,
  scoreCellsWithRows,
  scoreLayer,
  scoresToBytes,
} from '../estimator/fastPath';
import type { MapWeightTable } from '../estimator/fastPath';
import {
  SEEDS,
  SEGMENTS,
  cellScores,
  dayContext,
  dayWeightRows,
  makeAssumptions,
  mapWeightRows,
  scoreByte,
  seed,
  typicalContext,
} from '../estimator/index';
import type {
  Assumptions,
  CalibrationState,
  CellScores,
  MapLayer,
  MapWeightRows,
  OverrideMap,
  Regime,
  TruckProfile,
} from '../estimator/types';

const REL = 1e-5;

// --- fixtures ---------------------------------------------------------------------------------------

const REGION_DC = { id: 'dc', traffic_matrix: 'dc' as const, flags: { inauguration_day: true } };

function assumptions(overrides: OverrideMap = {}): Assumptions {
  return makeAssumptions(overrides, REGION_DC);
}

function defaultProfile(changes: Partial<TruckProfile> = {}): TruckProfile {
  const d = SEEDS.profile_defaults;
  const base: TruckProfile = {
    name: 'Reference truck',
    region_id: 'dc',
    base: { lat: 39.003, lng: -77.405, address: 'Sterling, VA' },
    avg_ticket: d.avg_ticket.value,
    capacity_orders_per_hour: d.capacity_orders_per_hour.value,
    paid_crew: d.paid_crew.value,
    wage_per_hour: d.wage_per_hour.value,
    payroll_burden_pct: d.payroll_burden_pct.value,
    food_cost_pct: d.food_cost_pct.value,
    packaging_per_order: d.packaging_per_order.value,
    card_fee_pct: d.card_fee_pct.value,
    card_fee_fixed: d.card_fee_fixed.value,
    card_share: d.card_share.value,
    tips_include: d.tips_include.value,
    tips_pct_of_card_sales: d.tips_pct_of_card_sales.value,
    mpg: d.mpg.value,
    fuel_type: d.fuel_type.value,
    fuel_price_override: null,
    generator_gal_per_hour: d.generator_gal_per_hour.value,
    prep_minutes: d.prep_minutes.value,
    setup_minutes: d.setup_minutes.value,
    teardown_minutes: d.teardown_minutes.value,
    closeout_minutes: d.closeout_minutes.value,
    fixed_cost_per_service_day: d.fixed_cost_per_service_day.value,
    daypart_fit: {
      breakfast: d.daypart_fit.breakfast,
      lunch: d.daypart_fit.lunch,
      dinner: d.daypart_fit.dinner,
      late: d.daypart_fit.late,
    },
    avoid_tolls: d.avoid_tolls.value,
    avoid_highways: d.avoid_highways.value,
    truck_time_factor: d.truck_time_factor.value,
    licence_counties: [],
    scout_drive_minutes_limit: d.scout_drive_minutes_limit.value,
  };
  return { ...base, ...changes };
}

function calibration(truckFactor: number): CalibrationState {
  return {
    model_version: 'tps-0.1.0',
    seeds_revision: SEEDS.seeds_revision,
    as_of: '2026-10-04',
    truck_factor: truckFactor,
    truck_log_factor: 0.0,
    bias_log: 0.0,
    truck_n: 9,
    truck_weight: 7.5,
    spots: {},
    resid_sd: null,
    resid_n: 0,
    resid_weight: 0.0,
  };
}

// A fixed linear congruential generator (the constants of Numerical Recipes), 32-bit state.
function lcg(seedValue: number): () => number {
  let state = seedValue >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 4294967296;
  };
}

// n synthetic cells in pack layout. About a third of the capture and nearby entries are zero, as in
// real data; magnitudes follow the region build (captures up to a few hundred, nearby up to tens of
// thousands, rivals up to about a hundred).
function syntheticCells(n: number, seedValue: number): Float32Array {
  const next = lcg(seedValue);
  const features = new Float32Array(n * FEATURES_PER_CELL);
  for (let c = 0; c < n; c++) {
    const b = c * FEATURES_PER_CELL;
    const scale = 0.05 + 2.0 * next() * next();
    for (let j = 0; j < 32; j++) features[b + j] = next() < 0.33 ? 0.0 : 400.0 * next() * next() * scale;
    for (let j = 32; j < 48; j++) features[b + j] = next() < 0.33 ? 0.0 : 30000.0 * next() * next() * scale;
    features[b + 48] = 110.0 * next() * next();
    features[b + 49] = 110.0 * next() * next();
  }
  return features;
}

const A = assumptions();
const PROFILE = defaultProfile();
const WEEK_ROWS = mapWeightRows(A, PROFILE, null);
const WEEK_TABLE = precomputeMapWeights(A, PROFILE, null);
const CAPACITY = PROFILE.capacity_orders_per_hour;
const REGIME_OF_HOUR = seed<Regime[]>(A, 'hours.regime_of_hour');
const CELLS = syntheticCells(2000, 20261005);
const CELLS_AS_DOUBLES: number[] = Array.from(CELLS);

function close(want: number, got: number): boolean {
  if (want === got) return true;
  return Math.abs(want - got) <= REL * Math.max(Math.abs(want), Math.abs(got));
}

// Compare the three planes of a fast-path buffer with a slow-path result; returns the worst relative
// difference seen and fails on the first value outside the tolerance.
function expectPlanes(out: Float32Array, n: number, slow: CellScores): number {
  let worst = 0.0;
  const planes: [MapLayer, number[]][] = [
    ['opportunity', slow.opportunity],
    ['people', slow.people],
    ['competition', slow.competition],
  ];
  for (let p = 0; p < 3; p++) {
    const want = planes[p][1];
    expect(want.length).toBe(n);
    for (let c = 0; c < n; c++) {
      const got = out[p * n + c];
      if (!close(want[c], got)) {
        throw new Error(`${planes[p][0]} of cell ${c}: slow path ${want[c]}, fast path ${got}`);
      }
      const scale = Math.max(Math.abs(want[c]), Math.abs(got));
      if (scale > 0) worst = Math.max(worst, Math.abs(want[c] - got) / scale);
    }
  }
  return worst;
}

function slowHour(rows: MapWeightRows, how: number, capacity: number, n: number, cells: number[]): CellScores {
  return cellScores(cells, n, rows.w_opp[how], rows.w_people[how], REGIME_OF_HOUR[how % 24], capacity);
}

function checkHour(table: MapWeightTable, rows: MapWeightRows, how: number, n = 2000, capacity = CAPACITY): number {
  const out = new Float32Array(3 * n);
  scoreCells(CELLS, n, table, how, capacity, out);
  return expectPlanes(out, n, slowHour(rows, how, capacity, n, CELLS_AS_DOUBLES));
}

// --- the checks ---------------------------------------------------------------------------------------

describe('fast path: layout and domain', () => {
  it('feature columns are the 50 pack columns in order', () => {
    expect(FEATURES_PER_CELL).toBe(50);
    expect(FEATURE_COLUMNS.length).toBe(50);
    expect(FEATURE_COLUMNS[COL_CAPTURE_DAY]).toBe('c_day_res');
    expect(FEATURE_COLUMNS[COL_CAPTURE_DAY + 1]).toBe('c_day_w_office');
    expect(FEATURE_COLUMNS[COL_CAPTURE_EVE]).toBe('c_eve_res');
    expect(FEATURE_COLUMNS[COL_CAPTURE_EVE + 15]).toBe('c_eve_v_lodging');
    expect(FEATURE_COLUMNS[COL_NEARBY]).toBe('n_res');
    expect(FEATURE_COLUMNS[COL_NEARBY + 8]).toBe('n_v_nightlife');
    expect(FEATURE_COLUMNS[COL_RIVALS_DAY]).toBe('r_day');
    expect(FEATURE_COLUMNS[COL_RIVALS_EVE]).toBe('r_eve');
    const expected = [
      ...SEGMENTS.map((s) => 'c_day_' + s),
      ...SEGMENTS.map((s) => 'c_eve_' + s),
      ...SEGMENTS.map((s) => 'n_' + s),
      'r_day',
      'r_eve',
    ];
    expect([...FEATURE_COLUMNS]).toEqual(expected);
  });

  it('the colour domain is fixed and comes from the seeds', () => {
    expect(MAP_DOMAIN).toEqual({ opportunity: 45, people: 20000, competition: 100 });
    expect(MAP_DOMAIN.opportunity).toBe(SEEDS.map.opportunity_hi.value);
    expect(MAP_DOMAIN.people).toBe(SEEDS.map.people_hi.value);
    expect(MAP_DOMAIN.competition).toBe(SEEDS.map.competition_hi.value);
    expect(Object.isFrozen(MAP_DOMAIN)).toBe(true);
    expect([...MAP_LAYERS]).toEqual(['opportunity', 'people', 'competition']);
  });
});

describe('fast path: the weight table', () => {
  it('holds exactly the numbers of mapWeightRows for a typical week', () => {
    expect(HOURS_PER_WEEK).toBe(168);
    expect(WEEK_TABLE.wOpp.length).toBe(168 * 16);
    expect(WEEK_TABLE.wPeople.length).toBe(168 * 16);
    expect(WEEK_TABLE.eve.length).toBe(168);
    expect(Object.keys(WEEK_TABLE).sort()).toEqual(['eve', 'wOpp', 'wPeople']);
    let nonZero = 0;
    for (let how = 0; how < 168; how++) {
      expect(WEEK_TABLE.eve[how]).toBe(REGIME_OF_HOUR[how % 24] === 'eve' ? 1 : 0);
      for (let s = 0; s < 16; s++) {
        expect(WEEK_TABLE.wOpp[how * 16 + s]).toBe(WEEK_ROWS.w_opp[how][s]);
        expect(WEEK_TABLE.wPeople[how * 16 + s]).toBe(WEEK_ROWS.w_people[how][s]);
        if (WEEK_ROWS.w_opp[how][s] !== 0) nonZero += 1;
      }
    }
    expect(nonZero).toBeGreaterThan(1000);
  });

  it('dayWeightRows of a typical context equals the rows of that day in mapWeightRows', () => {
    for (let dow = 0; dow < 7; dow++) {
      const day = dayWeightRows(A, PROFILE, null, typicalContext(A, dow));
      expect(day.w_opp.length).toBe(24);
      for (let hour = 0; hour < 24; hour++) {
        expect(day.w_opp[hour]).toEqual(WEEK_ROWS.w_opp[dow * 24 + hour]);
        expect(day.w_people[hour]).toEqual(WEEK_ROWS.w_people[dow * 24 + hour]);
      }
    }
  });

  it('with a date context replaces the 24 rows of that day and leaves the rest of the week alone', () => {
    const thanksgiving = dayContext(A, '2026-11-26', null, null, null, null); // a Thursday, major holiday
    expect(thanksgiving.dow).toBe(3);
    expect(thanksgiving.holiday_class).toBe('major');
    const table = precomputeMapWeights(A, PROFILE, null, thanksgiving);
    const day = dayWeightRows(A, PROFILE, null, thanksgiving);
    let changed = 0;
    for (let how = 0; how < 168; how++) {
      const inDay = how >= 72 && how < 96;
      for (let s = 0; s < 16; s++) {
        const wantOpp = inDay ? day.w_opp[how - 72][s] : WEEK_ROWS.w_opp[how][s];
        const wantPeople = inDay ? day.w_people[how - 72][s] : WEEK_ROWS.w_people[how][s];
        expect(table.wOpp[how * 16 + s]).toBe(wantOpp);
        expect(table.wPeople[how * 16 + s]).toBe(wantPeople);
        if (inDay && wantPeople !== WEEK_ROWS.w_people[how][s]) changed += 1;
      }
    }
    expect(changed).toBeGreaterThan(100); // a holiday really does change the day
  });
});

describe('fast path equals the slow path within 1e-5 relative (2,000 synthetic cells)', () => {
  it('Monday 08:00: day regime, breakfast', () => {
    expect(REGIME_OF_HOUR[8]).toBe('day');
    expect(checkHour(WEEK_TABLE, WEEK_ROWS, 8)).toBeLessThanOrEqual(REL);
  });

  it('Thursday 12:00: day regime, lunch', () => {
    expect(checkHour(WEEK_TABLE, WEEK_ROWS, 84)).toBeLessThanOrEqual(REL);
  });

  it('Thursday 18:00: evening regime, dinner', () => {
    expect(REGIME_OF_HOUR[18]).toBe('eve');
    expect(checkHour(WEEK_TABLE, WEEK_ROWS, 90)).toBeLessThanOrEqual(REL);
  });

  it('Saturday 23:00: evening regime, late', () => {
    expect(checkHour(WEEK_TABLE, WEEK_ROWS, 5 * 24 + 23)).toBeLessThanOrEqual(REL);
  });

  it('Sunday 03:00: the small hours', () => {
    expect(checkHour(WEEK_TABLE, WEEK_ROWS, 6 * 24 + 3)).toBeLessThanOrEqual(REL);
  });

  it('every one of the 168 hours (200 cells each)', () => {
    let worst = 0.0;
    for (let how = 0; how < 168; how++) worst = Math.max(worst, checkHour(WEEK_TABLE, WEEK_ROWS, how, 200));
    expect(worst).toBeLessThanOrEqual(REL);
    expect(worst).toBeGreaterThan(0); // the Float32Array really rounds; the two paths are not one code path
  });

  it('a capacity of 10 orders an hour clamps opportunity in both paths', () => {
    const profile = defaultProfile({ capacity_orders_per_hour: 10.0 });
    const table = precomputeMapWeights(A, profile, null);
    const n = 2000;
    const out = new Float32Array(3 * n);
    scoreCells(CELLS, n, table, 84, profile.capacity_orders_per_hour, out);
    const slow = slowHour(mapWeightRows(A, profile, null), 84, 10.0, n, CELLS_AS_DOUBLES);
    expectPlanes(out, n, slow);
    let capped = 0;
    for (let c = 0; c < n; c++) {
      expect(out[c]).toBeLessThanOrEqual(10.0);
      if (out[c] === 10.0) capped += 1;
    }
    expect(capped).toBeGreaterThan(50);
    expect(capped).toBeLessThan(n);
  });

  it('a capacity of 0 gives no opportunity anywhere', () => {
    const profile = defaultProfile({ capacity_orders_per_hour: 0.0 });
    const table = precomputeMapWeights(A, profile, null);
    const n = 500;
    const out = new Float32Array(3 * n);
    scoreCells(CELLS, n, table, 84, profile.capacity_orders_per_hour, out);
    expectPlanes(out, n, slowHour(mapWeightRows(A, profile, null), 84, 0.0, n, CELLS_AS_DOUBLES));
    for (let c = 0; c < n; c++) expect(out[c]).toBe(0);
  });

  it('the capacity is an argument of the scorer, not part of the weight table', () => {
    // the table depends on A, the menu fit, the truck factor and the date only: one table serves any capacity
    const other = precomputeMapWeights(A, defaultProfile({ capacity_orders_per_hour: 7.0, wage_per_hour: 99.0 }), null);
    expect(Array.from(other.wOpp)).toEqual(Array.from(WEEK_TABLE.wOpp));
    expect(Array.from(other.wPeople)).toEqual(Array.from(WEEK_TABLE.wPeople));
    const n = 800;
    const out = new Float32Array(3 * n);
    const one = new Float32Array(n);
    for (const capacity of [0.5, 7.0, 45.0, 1e9]) {
      scoreCells(CELLS, n, WEEK_TABLE, 84, capacity, out);
      expectPlanes(out, n, slowHour(WEEK_ROWS, 84, capacity, n, CELLS_AS_DOUBLES));
      scoreLayer('opportunity', CELLS, n, WEEK_TABLE, 84, capacity, one);
      for (let c = 0; c < n; c++) expect(one[c]).toBe(out[c]);
    }
  });

  it('a truck factor and another menu fit', () => {
    const profile = defaultProfile({
      capacity_orders_per_hour: 60.0,
      daypart_fit: { breakfast: 1.0, lunch: 0.65, dinner: 0.5, late: 0.0 },
    });
    const cal = calibration(1.37);
    const table = precomputeMapWeights(A, profile, cal);
    const rows = mapWeightRows(A, profile, cal);
    for (const how of [8, 36, 84, 114, 143, 150]) {
      expect(checkHour(table, rows, how, 1000, profile.capacity_orders_per_hour)).toBeLessThanOrEqual(REL);
    }
    // late fit 0: Saturday 23:00 has no opportunity, people are unaffected
    const out = new Float32Array(3 * 100);
    scoreCells(CELLS, 100, table, 143, profile.capacity_orders_per_hour, out);
    for (let c = 0; c < 100; c++) expect(out[c]).toBe(0);
  });

  it('owner overrides of curves and Monday-Friday factors', () => {
    const intent = new Array<number>(24).fill(0.05);
    const over = assumptions({
      'segments.w_office.dow_factor': [1.0, 1.0, 1.0, 1.0, 1.0],
      'segments.res.intent.weekday': intent,
      'segments.v_nightlife.dow_factor': [2.0, 0.1, 0.1, 0.1, 3.0],
    });
    const table = precomputeMapWeights(over, PROFILE, null);
    const rows = mapWeightRows(over, PROFILE, null);
    expect(rows.w_opp[84]).not.toEqual(WEEK_ROWS.w_opp[84]);
    for (const how of [10, 84, 90, 113]) expect(checkHour(table, rows, how, 1000)).toBeLessThanOrEqual(REL);
  });

  it('a holiday date: Thanksgiving uses the date rows of 4.17', () => {
    const ctx = dayContext(A, '2026-11-26', null, null, null, null);
    const table = precomputeMapWeights(A, PROFILE, null, ctx);
    const day = dayWeightRows(A, PROFILE, null, ctx);
    const n = 1000;
    const out = new Float32Array(3 * n);
    for (const hour of [9, 12, 18, 22]) {
      scoreCells(CELLS, n, table, ctx.dow * 24 + hour, CAPACITY, out);
      const slow = cellScores(
        CELLS_AS_DOUBLES,
        n,
        day.w_opp[hour],
        day.w_people[hour],
        REGIME_OF_HOUR[hour],
        PROFILE.capacity_orders_per_hour,
      );
      expectPlanes(out, n, slow);
    }
    // the day before is an ordinary Wednesday
    expect(checkHour(table, WEEK_ROWS, 2 * 24 + 12, 500)).toBeLessThanOrEqual(REL);
  });

  it('"treat this day as a Saturday" on a Thursday', () => {
    const ctx = dayContext(A, '2026-10-08', 'sat', null, null, null);
    expect(ctx.dow).toBe(3);
    expect(ctx.eff_dow).toBe(5);
    const table = precomputeMapWeights(A, PROFILE, null, ctx);
    const n = 1000;
    const out = new Float32Array(3 * n);
    scoreCells(CELLS, n, table, 3 * 24 + 13, CAPACITY, out);
    // behaves like Saturday 13:00 of the typical week
    expectPlanes(out, n, slowHour(WEEK_ROWS, 5 * 24 + 13, PROFILE.capacity_orders_per_hour, n, CELLS_AS_DOUBLES));
  });

  it('scoreLayer writes the same numbers as the matching plane of scoreCells', () => {
    const n = 1500;
    const all = new Float32Array(3 * n);
    const one = new Float32Array(n);
    for (const how of [84, 90]) {
      scoreCells(CELLS, n, WEEK_TABLE, how, CAPACITY, all);
      for (let p = 0; p < 3; p++) {
        one.fill(-1);
        scoreLayer(MAP_LAYERS[p], CELLS, n, WEEK_TABLE, how, CAPACITY, one);
        for (let c = 0; c < n; c++) expect(one[c]).toBe(all[p * n + c]);
      }
    }
  });

  it('scoreCellsWithRows equals scoreCells for the rows of the hour', () => {
    const n = 1500;
    const a = new Float32Array(3 * n);
    const b = new Float32Array(3 * n);
    for (const how of [84, 90]) {
      scoreCells(CELLS, n, WEEK_TABLE, how, CAPACITY, a);
      scoreCellsWithRows(
        CELLS,
        n,
        WEEK_ROWS.w_opp[how],
        WEEK_ROWS.w_people[how],
        REGIME_OF_HOUR[how % 24],
        PROFILE.capacity_orders_per_hour,
        b,
      );
      expect(Array.from(b)).toEqual(Array.from(a));
    }
  });
});

describe('fast path: the golden cell_scores cases through a Float32Array', () => {
  const goldenPath = fileURLToPath(
    new URL('../../../../../tests/fixtures/truck-planner/golden_cases.json', import.meta.url),
  );
  const golden = JSON.parse(readFileSync(goldenPath, 'utf8')) as {
    cases: { id: string; function: string; args: any; expected: any }[];
  };
  const cases = golden.cases.filter((c) => c.function === 'cell_scores');

  it('every golden cell_scores case, the 1,000-cell block among them', () => {
    expect(cases.length).toBeGreaterThanOrEqual(8);
    let cells = 0;
    let largest = 0;
    for (const c of cases) {
      const n: number = c.args.n;
      const features = Float32Array.from(c.args.features as number[]);
      const out = new Float32Array(3 * n);
      scoreCellsWithRows(features, n, c.args.w_opp_row, c.args.w_people_row, c.args.regime, c.args.capacity, out);
      // The golden features are doubles; the looser tolerance of 4.17 covers their rounding to float32.
      expectPlanes(out, n, c.expected as CellScores);
      cells += n;
      largest = Math.max(largest, n);
    }
    expect(largest).toBe(1000);
    expect(cells).toBeGreaterThan(1000);
  });

  it('the cell of 4.17 (Thursday 12:00): scores 29.436015, 437.1100, 0.833985 and bytes 206, 38, 23', () => {
    const c = cases[0];
    expect(c.args.n).toBe(1);
    const features = Float32Array.from(c.args.features as number[]);
    const out = new Float32Array(3);
    scoreCells(features, 1, WEEK_TABLE, 84, CAPACITY, out);
    expect(Math.abs(out[0] - 29.436015)).toBeLessThan(29.436015 * REL);
    expect(Math.abs(out[1] - 437.11)).toBeLessThan(437.11 * REL);
    expect(Math.abs(out[2] - 0.833985)).toBeLessThan(0.833985 * REL);
    const bytes = new Uint8Array(1);
    scoresToBytes(out, 1, MAP_DOMAIN.opportunity, bytes, 0, 0);
    expect(bytes[0]).toBe(206);
    scoresToBytes(out, 1, MAP_DOMAIN.people, bytes, 0, 1);
    expect(bytes[0]).toBe(38);
    scoresToBytes(out, 1, MAP_DOMAIN.competition, bytes, 0, 2);
    expect(bytes[0]).toBe(23);
  });
});

describe('fast path: colour bytes', () => {
  it('scoresToBytes is scoreByte of each stored score, and within 1 of the slow path byte', () => {
    const n = 2000;
    const out = new Float32Array(3 * n);
    const bytes = new Uint8Array(n);
    const domain = [MAP_DOMAIN.opportunity, MAP_DOMAIN.people, MAP_DOMAIN.competition];
    let coloured = 0;
    for (const how of [84, 90, 138]) {
      scoreCells(CELLS, n, WEEK_TABLE, how, CAPACITY, out);
      const slow = slowHour(WEEK_ROWS, how, CAPACITY, n, CELLS_AS_DOUBLES);
      const planes = [slow.opportunity, slow.people, slow.competition];
      for (let p = 0; p < 3; p++) {
        scoresToBytes(out, n, domain[p], bytes, 0, p * n);
        for (let c = 0; c < n; c++) {
          expect(bytes[c]).toBe(scoreByte(out[p * n + c], domain[p]));
          expect(Math.abs(bytes[c] - scoreByte(planes[p][c], domain[p]))).toBeLessThanOrEqual(1);
          if (bytes[c] > 0) coloured += 1;
        }
      }
    }
    expect(coloured).toBeGreaterThan(5000);
  });

  it('bytes below the floor become 0 and the rest are kept', () => {
    const scores = Float32Array.from([0, 0.02, 0.1, 1, 5, 45, 60, -3]);
    const plain = new Uint8Array(scores.length);
    const floored = new Uint8Array(scores.length);
    scoresToBytes(scores, scores.length, 45.0, plain);
    scoresToBytes(scores, scores.length, 45.0, floored, 12);
    expect(Array.from(plain)).toEqual([0, 5, 12, 38, 85, 255, 255, 0]);
    expect(Array.from(floored)).toEqual([0, 0, 12, 38, 85, 255, 255, 0]);
  });
});

describe('fast path: buffers', () => {
  it('writes nothing beyond the n cells it is asked for', () => {
    const out = new Float32Array(3 * 10 + 4).fill(-7);
    scoreCells(CELLS, 10, WEEK_TABLE, 84, CAPACITY, out);
    for (let i = 30; i < out.length; i++) expect(out[i]).toBe(-7);
    const untouched = new Float32Array(6).fill(-7);
    scoreCells(CELLS, 0, WEEK_TABLE, 84, CAPACITY, untouched);
    expect(Array.from(untouched)).toEqual([-7, -7, -7, -7, -7, -7]);
    scoreLayer('people', CELLS, 0, WEEK_TABLE, 84, CAPACITY, untouched);
    expect(Array.from(untouched)).toEqual([-7, -7, -7, -7, -7, -7]);
  });

  it('refuses a buffer that is too short and an hour outside the week', () => {
    const out = new Float32Array(3 * 10);
    expect(() => scoreCells(CELLS, 11, WEEK_TABLE, 84, CAPACITY, out)).toThrow(RangeError);
    expect(() => scoreCells(new Float32Array(499), 10, WEEK_TABLE, 84, CAPACITY, out)).toThrow(RangeError);
    expect(() => scoreCells(CELLS, 10, WEEK_TABLE, 168, CAPACITY, out)).toThrow(RangeError);
    expect(() => scoreCells(CELLS, 10, WEEK_TABLE, -1, CAPACITY, out)).toThrow(RangeError);
    expect(() => scoreCells(CELLS, 10, WEEK_TABLE, 84.5, CAPACITY, out)).toThrow(RangeError);
    expect(() => scoreLayer('opportunity', CELLS, 31, WEEK_TABLE, 84, CAPACITY, out)).toThrow(RangeError);
    expect(() => scoresToBytes(out, 31, 45.0, new Uint8Array(31))).toThrow(RangeError);
  });
});

describe('fast path: timing', () => {
  function median(samples: number[]): number {
    const sorted = samples.slice().sort((x, y) => x - y);
    return sorted[Math.floor(sorted.length / 2)];
  }

  function timeIt(runs: number, work: (i: number) => void): { median: number; min: number } {
    for (let i = 0; i < 60; i++) work(i); // warm up
    const samples: number[] = [];
    for (let i = 0; i < runs; i++) {
      const t0 = performance.now();
      work(i);
      samples.push(performance.now() - t0);
    }
    return { median: median(samples), min: Math.min(...samples) };
  }

  it('scores 10,000 cells for one hour far inside a frame', () => {
    const n = 10000;
    const cells = syntheticCells(n, 7);
    const out = new Float32Array(3 * n);
    const one = new Float32Array(n);
    const bytes = new Uint8Array(n);
    const hours = [84, 90, 12, 138, 66, 161];

    const three = timeIt(300, (i) => scoreCells(cells, n, WEEK_TABLE, hours[i % hours.length], CAPACITY, out));
    const layer = timeIt(300, (i) => scoreLayer('opportunity', cells, n, WEEK_TABLE, hours[i % hours.length], CAPACITY, one));
    const tick = timeIt(300, (i) => {
      scoreLayer('opportunity', cells, n, WEEK_TABLE, hours[i % hours.length], CAPACITY, one);
      scoresToBytes(one, n, MAP_DOMAIN.opportunity, bytes, 12);
    });
    const doubles = Array.from(cells);
    const slow = timeIt(40, (i) => {
      const how = hours[i % hours.length];
      cellScores(doubles, n, WEEK_ROWS.w_opp[how], WEEK_ROWS.w_people[how], REGIME_OF_HOUR[how % 24], 45.0);
    });

    const big = 60000;
    const bigCells = syntheticCells(big, 11);
    const bigOut = new Float32Array(3 * big);
    const metro = timeIt(100, (i) => scoreCells(bigCells, big, WEEK_TABLE, hours[i % hours.length], CAPACITY, bigOut));

    const ms = (x: number): string => (Math.floor(x * 1000 + 0.5) / 1000).toString();
    // eslint-disable-next-line no-console
    console.log(
      [
        `fast path timing, 10,000 cells, one hour of the week:`,
        `  scoreCells (three layers):          median ${ms(three.median)} ms, best ${ms(three.min)} ms`,
        `  scoreLayer (opportunity only):      median ${ms(layer.median)} ms, best ${ms(layer.min)} ms`,
        `  scoreLayer + scoresToBytes (a tick): median ${ms(tick.median)} ms, best ${ms(tick.min)} ms`,
        `  slow path cellScores, for scale:    median ${ms(slow.median)} ms`,
        `  scoreCells, 60,000 cells (a metro): median ${ms(metro.median)} ms, best ${ms(metro.min)} ms`,
      ].join('\n'),
    );

    // Loose bounds that hold on any machine; the measured figures are in the log line above.
    expect(three.median).toBeLessThan(10);
    expect(metro.median).toBeLessThan(50);
    // the last run left real scores behind
    expect(out.some((x) => x > 0)).toBe(true);
    expect(bytes.some((x) => x > 0)).toBe(true);
  });
});
