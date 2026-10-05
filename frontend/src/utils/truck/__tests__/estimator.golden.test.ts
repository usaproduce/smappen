// Truck Planner estimator - the golden cases (docs/truck-planner/02_MODEL.md section 8).
//
// Every case of tests/fixtures/truck-planner/golden_cases.json is run through GOLDEN_DISPATCH and
// compared with `expected` under the rule of 02_MODEL 1.4, using the tolerance the file states:
// numbers as doubles (two integral values must be equal, otherwise within the tolerance), strings,
// booleans and null exactly, arrays by length and order, objects by key set. The same file is run by
// the Python reference and by the PHP port; a port is never fixed by editing it.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { afterAll, describe, expect, it } from 'vitest';
import { GOLDEN_DISPATCH, MODEL_ERRORS, MODEL_VERSION, ModelError, SEEDS_REVISION } from '../estimator/index';

interface GoldenCase {
  id: string;
  family: string;
  function: string;
  args: Record<string, unknown>;
  expected: unknown;
}

interface GoldenAnchor {
  id: string;
  case: string;
  path: string;
  min?: number;
  max?: number;
  greater_than_case?: string;
}

interface GoldenFile {
  model_version: string;
  seeds_revision: number;
  tolerance: { rel: number; abs: number };
  anchors: GoldenAnchor[];
  cases: GoldenCase[];
}

const GOLDEN_PATH = fileURLToPath(
  new URL('../../../../../tests/fixtures/truck-planner/golden_cases.json', import.meta.url),
);
const golden: GoldenFile = JSON.parse(readFileSync(GOLDEN_PATH, 'utf8'));
const TOLERANCE = golden.tolerance;

// The catalogue of 02_MODEL section 4 plus the helpers of 8.1: 64 names, every one with golden cases
// of its own (the two internal ones, make_context and evaluate, included).
const CATALOGUE = [
  'round_half_away', 'qkey', 'seed', 'validate_overrides', 'est_fixed', 'est_levels', 'weakest', 'est_sum',
  'days_from_civil', 'civil_from_days', 'parse_date', 'format_date', 'day_of_week', 'add_days', 'nth_weekday',
  'last_weekday', 'federal_holidays', 'holiday_on', 'day_context', 'typical_context', 'make_context',
  'hour_weights', 'expand_curves', 'haversine_m', 'walk_weight', 'rivals_at_origin', 'host_exclusion',
  'host_link_point', 'capture_at_point', 'host_capture', 'weather_multiplier', 'calibration_factor',
  'hourly_orders', 'vectors_match', 'window_orders', 'week_strip', 'best_windows', 'evidence_from', 'interval',
  'interval_capped', 'stop_money_at', 'stop_money', 'unit_margins', 'break_even_orders', 'day_costs',
  'fallback_leg', 'traffic_factor', 'leg_minutes', 'required_leg_keys', 'build_timeline', 'evaluate', 'day_plan',
  'calibrate', 'accuracy_report', 'event_orders', 'catering_money', 'suggest_day', 'suggest_week',
  'scout_estimate', 'strip_from_rows', 'scout_rank', 'map_weight_rows', 'cell_scores', 'score_byte',
];
const NO_DIRECT_CASES: string[] = [];

// Functions that use only +, -, *, /, sqrt and floor on their arguments and on the seeds. IEEE-754
// rounds those exactly, so for these a port that adds and multiplies in the order of the reference
// reproduces every golden number to the last bit, on any machine (02_MODEL section 7). Everything else
// calls exp, ln, sin, cos or asin somewhere and is held to the tolerance only.
const EXACT_FUNCTIONS = new Set([
  'round_half_away', 'qkey', 'seed', 'est_fixed', 'est_levels', 'est_sum', 'days_from_civil', 'civil_from_days',
  'parse_date', 'day_of_week', 'day_context', 'typical_context', 'make_context', 'hour_weights', 'expand_curves', 'host_exclusion',
  'host_capture', 'weather_multiplier', 'calibration_factor', 'hourly_orders', 'week_strip', 'best_windows',
  'evidence_from', 'stop_money_at', 'stop_money', 'unit_margins', 'break_even_orders', 'day_costs',
  'traffic_factor', 'leg_minutes', 'accuracy_report', 'catering_money', 'strip_from_rows', 'scout_rank',
  'map_weight_rows', 'cell_scores', 'score_byte',
]);

function isPlainObject(x: unknown): x is Record<string, unknown> {
  return typeof x === 'object' && x !== null && !Array.isArray(x);
}

// 02_MODEL 1.4, with the tolerance of the golden file.
function numbersMatch(want: number, got: number): boolean {
  if (want === got) return true;
  if (!Number.isFinite(want) || !Number.isFinite(got)) return false;
  if (Number.isInteger(want) && Number.isInteger(got)) return false;
  return Math.abs(want - got) <= Math.max(TOLERANCE.abs, TOLERANCE.rel * Math.max(Math.abs(want), Math.abs(got)));
}

function show(x: unknown): string {
  const text = x === undefined ? 'undefined' : JSON.stringify(x);
  return text.length > 120 ? text.slice(0, 117) + '...' : text;
}

interface Tally {
  numbers: number;
  identical: number;
}

// Append to `out` every place where `got` differs from `want` under the golden-case rule. With
// exact = true two numbers must be the same double (the tolerance is not used). `tally`, when given,
// counts the numbers compared and how many of them were the same double.
function differences(
  want: unknown,
  got: unknown,
  path: string,
  out: string[],
  exact = false,
  tally: Tally | null = null,
): void {
  if (out.length >= 12) return;
  if (typeof want === 'number' && typeof got === 'number') {
    if (tally !== null) {
      tally.numbers += 1;
      if (want === got) tally.identical += 1;
    }
    if (exact ? want !== got : !numbersMatch(want, got)) {
      out.push(`${path}: expected ${want}, got ${got}${exact ? ' (must be the same double)' : ''}`);
    }
  } else if (Array.isArray(want) && Array.isArray(got)) {
    if (want.length !== got.length) {
      out.push(`${path}: expected ${want.length} items, got ${got.length}`);
    } else {
      for (let i = 0; i < want.length; i++) differences(want[i], got[i], `${path}.${i}`, out, exact, tally);
    }
  } else if (isPlainObject(want) && isPlainObject(got)) {
    const wantKeys = Object.keys(want).sort();
    const gotKeys = Object.keys(got).sort();
    if (wantKeys.join('|') !== gotKeys.join('|')) {
      out.push(`${path}: expected keys ${wantKeys.join(',')}, got ${gotKeys.join(',')}`);
    } else {
      for (const key of wantKeys) differences(want[key], got[key], `${path}.${key}`, out, exact, tally);
    }
  } else if (want !== got) {
    // strings, booleans and null must be identical; so must the kind of value
    out.push(`${path}: expected ${show(want)}, got ${show(got)}`);
  }
}

function at(value: unknown, path: string): unknown {
  let node = value;
  if (path === '') return node;
  for (const key of path.split('.')) node = (node as Record<string, unknown>)[key];
  return node;
}

function expectedError(c: GoldenCase): string | null {
  if (!isPlainObject(c.expected)) return null;
  const keys = Object.keys(c.expected);
  if (keys.length !== 1 || keys[0] !== 'error') return null;
  return String(c.expected.error);
}

// Run one case the way a port does: the function by its name, the arguments by name. A model error
// becomes { error: code }; anything else that is thrown fails the case.
function run(c: GoldenCase): unknown {
  const fn = GOLDEN_DISPATCH[c.function];
  if (typeof fn !== 'function') throw new Error(`no dispatch entry for ${c.function}`);
  try {
    return fn(c.args);
  } catch (error) {
    if (error instanceof ModelError) return { error: error.code };
    throw error;
  }
}

const results = new Map<string, unknown>();
let passed = 0;
let errorCases = 0;
let exactCases = 0;
const tally: Tally = { numbers: 0, identical: 0 };

describe('golden file', () => {
  it('is for this model version and seeds revision', () => {
    expect(golden.model_version).toBe(MODEL_VERSION);
    expect(golden.seeds_revision).toBe(SEEDS_REVISION);
    expect(TOLERANCE.rel).toBeGreaterThan(0);
    expect(TOLERANCE.rel).toBeLessThanOrEqual(1e-9);
    expect(TOLERANCE.abs).toBeLessThanOrEqual(1e-9);
  });

  it('has unique case ids and only functions of the catalogue', () => {
    const ids = new Set(golden.cases.map((c) => c.id));
    expect(ids.size).toBe(golden.cases.length);
    const unknown = golden.cases.filter((c) => !(c.function in GOLDEN_DISPATCH)).map((c) => `${c.id} ${c.function}`);
    expect(unknown).toEqual([]);
  });

  it('GOLDEN_DISPATCH holds exactly the catalogue, and every function has a case', () => {
    expect(Object.keys(GOLDEN_DISPATCH).sort()).toEqual(CATALOGUE.slice().sort());
    const used = new Set(golden.cases.map((c) => c.function));
    const unused = CATALOGUE.filter((name) => !used.has(name));
    expect(unused.sort()).toEqual(NO_DIRECT_CASES.slice().sort());
  });

  it('exercises every model error', () => {
    const seen = new Set(golden.cases.map(expectedError).filter((code) => code !== null));
    expect([...seen].sort()).toEqual([...MODEL_ERRORS].sort());
  });

  it('is compared strictly: the comparer of this file sees every kind of difference', () => {
    const diff = (want: unknown, got: unknown): number => {
      const out: string[] = [];
      differences(want, got, 'x', out);
      return out.length;
    };
    // what must match
    expect(diff({ a: 1, b: [0.1, 'x', null, true] }, { b: [0.1, 'x', null, true], a: 1 })).toBe(0);
    expect(diff(66, 66.0)).toBe(0);
    expect(diff(60.4938, 60.4938 * (1 + 5e-10))).toBe(0);
    expect(diff(0.0, 5e-10)).toBe(0);
    expect(diff(0, -0)).toBe(0);
    // what must not
    expect(diff(60.4938, 60.4938 * (1 + 5e-9))).toBe(1);
    expect(diff(0.0, 5e-9)).toBe(1);
    expect(diff(66, 67)).toBe(1); // two integral values must be equal
    expect(diff(1e15, 1e15 + 2)).toBe(1);
    expect(diff(1, NaN)).toBe(1);
    expect(diff(1e300, Infinity)).toBe(1);
    expect(diff(1, '1')).toBe(1);
    expect(diff(0, null)).toBe(1);
    expect(diff(0, false)).toBe(1);
    expect(diff(null, undefined)).toBe(1);
    expect(diff('rough', 'fair')).toBe(1);
    expect(diff([1, 2], [1, 2, 3])).toBe(1);
    expect(diff([1, 2], [2, 1])).toBe(2);
    expect(diff({ a: 1 }, { a: 1, b: 2 })).toBe(1);
    expect(diff({ a: 1, b: null }, { a: 1 })).toBe(1);
    expect(diff({ a: 1, b: null }, { a: 1, b: undefined })).toBe(1);
    expect(diff([1], { 0: 1 })).toBe(1);
    expect(diff([1, 2], Float64Array.from([1, 2]))).toBe(1);
    // the exact mode sees a difference of one unit in the last place
    const strict: string[] = [];
    differences({ a: [0.1 + 0.2] }, { a: [0.3] }, 'x', strict, true);
    expect(strict.length).toBe(1);
    expect(diff({ a: [0.1 + 0.2] }, { a: [0.3] })).toBe(0);
    for (const name of EXACT_FUNCTIONS) expect(CATALOGUE, name).toContain(name);
  });
});

const families: string[] = [];
for (const c of golden.cases) {
  if (families.indexOf(c.family) < 0) families.push(c.family);
}

describe('golden cases', () => {
  for (const family of families) {
    describe(family, () => {
      for (const c of golden.cases) {
        if (c.family !== family) continue;
        it(`${c.id} ${c.function}`, () => {
          const before = JSON.stringify(c.args);
          const got = run(c);
          results.set(c.id, got);

          // The arguments come back untouched, and a second run gives the identical result.
          expect(JSON.stringify(c.args), 'the arguments were modified').toBe(before);
          const again = run(c);
          expect(JSON.stringify(again), 'a second run gave a different result').toBe(JSON.stringify(got));

          const code = expectedError(c);
          if (code !== null) {
            expect(got).toEqual({ error: code });
            errorCases += 1;
          } else {
            const exact = EXACT_FUNCTIONS.has(c.function);
            const found: string[] = [];
            differences(c.expected, got, c.function, found, exact, tally);
            expect(found).toEqual([]);
            if (exact) exactCases += 1;
          }
          passed += 1;
        });
      }
    });
  }

  afterAll(() => {
    // eslint-disable-next-line no-console
    console.log(
      `golden cases: ${passed} of ${golden.cases.length} passed ` +
        `(${errorCases} of them expected model errors; model ${golden.model_version}, ` +
        `seeds revision ${golden.seeds_revision}, tolerance rel ${TOLERANCE.rel} abs ${TOLERANCE.abs}).\n` +
        `  ${exactCases} cases of the ${EXACT_FUNCTIONS.size} exactly rounded functions were held to the last bit; ` +
        `over all cases ${tally.identical} of ${tally.numbers} numbers are the same double as the reference's.`,
    );
  });
});

describe('sanity anchors (02_MODEL 8.3)', () => {
  const caseById = new Map(golden.cases.map((c) => [c.id, c]));
  const valueOf = (caseId: string, path: string): number => {
    const c = caseById.get(caseId);
    if (c === undefined) throw new Error(`anchor points at a missing case ${caseId}`);
    const got = results.has(caseId) ? results.get(caseId) : run(c);
    return at(got, path) as number;
  };

  it('the golden file carries A1, A2 and A2-friday', () => {
    expect(golden.anchors.map((a) => a.id)).toEqual(['A1', 'A2', 'A2-friday']);
  });

  for (const anchor of golden.anchors) {
    it(`${anchor.id} holds`, () => {
      const value = valueOf(anchor.case, anchor.path);
      expect(Number.isFinite(value)).toBe(true);
      if (anchor.min !== undefined) expect(value).toBeGreaterThanOrEqual(anchor.min);
      if (anchor.max !== undefined) expect(value).toBeLessThanOrEqual(anchor.max);
      if (anchor.greater_than_case !== undefined) {
        expect(value).toBeGreaterThan(valueOf(anchor.greater_than_case, anchor.path));
      }
    });
  }
});

describe('the two internal functions against the cases of their callers', () => {
  it('make_context reproduces what day_context and typical_context return', () => {
    let checked = 0;
    for (const c of golden.cases) {
      if (c.function !== 'day_context' && c.function !== 'typical_context') continue;
      const want = c.expected as Record<string, unknown>;
      if (expectedError(c) !== null) continue;
      const got = GOLDEN_DISPATCH.make_context({
        A: c.args.A,
        date: want.date,
        dow: want.dow,
        eff_dow: want.eff_dow,
        cls: want.holiday_class,
        hol: want.holiday,
        treat_as: want.treat_as,
        forecast: want.forecast,
        fuel_price_per_gal: want.fuel_price_per_gal,
        fuel_price_source: want.fuel_price_source,
        typical: want.typical,
      });
      const found: string[] = [];
      differences(want, got, `${c.id} make_context`, found);
      expect(found).toEqual([]);
      checked += 1;
    }
    expect(checked).toBeGreaterThan(10);
  });

  it('evaluate gives the timeline and the totals that day_plan reports', () => {
    let checked = 0;
    for (const c of golden.cases) {
      if (c.function !== 'day_plan') continue;
      const want = c.expected as Record<string, any>;
      if (expectedError(c) !== null) continue; // a day that fails as a whole has nothing to compare
      const blocked = want.warnings.some(
        (w: { code: string }) => w.code === 'invalid_window' || w.code === 'stops_overlap',
      );
      if (blocked) continue; // a plan that is not evaluated
      const got = GOLDEN_DISPATCH.evaluate(c.args) as Record<string, any>;
      const found: string[] = [];
      differences(want.timeline, got.timeline, `${c.id} evaluate.timeline`, found);
      differences(want.totals, got.totals, `${c.id} evaluate.totals`, found);
      differences(want.totals.take_home, got.take_home, `${c.id} evaluate.take_home`, found);
      differences(want.totals.take_home_per_hour, got.take_home_per_hour, `${c.id} evaluate.take_home_per_hour`, found);
      differences(want.totals.work_hours, got.work_hours, `${c.id} evaluate.work_hours`, found);
      expect(got.stops.length).toBe(want.stops.length);
      for (let i = 0; i < want.stops.length; i++) {
        differences(want.stops[i].orders, got.stops[i].orders, `${c.id} evaluate.stops.${i}.orders`, found);
        differences(want.stops[i].money, got.stops[i].money, `${c.id} evaluate.stops.${i}.money`, found);
      }
      expect(found).toEqual([]);
      checked += 1;
    }
    expect(checked).toBeGreaterThan(10);
  });
});
