// Truck Planner estimator - what is particular to the TypeScript port.
//
// The golden cases pin the port to the reference. These tests cover the places where the port is built
// differently from the reference for speed, or where JavaScript would let through what Python stops:
// the direct seed readers, the one-hour column of the curves, arithmetic on a missing fuel price,
// lookups on plain objects, and inputs handed back untouched.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { hourColumn } from '../estimator/curves';
import { dowFactors, holidayDayType, intentCurve, presenceCurve } from '../estimator/seeds';
import {
  DAY_TYPES,
  GOLDEN_DISPATCH,
  ModelError,
  REGION_NONE,
  SEEDS,
  SEGMENTS,
  addDays,
  bestWindows,
  calibrate,
  calibrationFactor,
  captureAtPoint,
  civilFromDays,
  dayContext,
  dayCosts,
  dayPlan,
  daysFromCivil,
  emptyTimeline,
  evidenceFrom,
  federalHolidays,
  formatDate,
  hourWeights,
  hourlyOrders,
  legMinutes,
  makeAssumptions,
  mapWeightRows,
  requiredLegKeys,
  roundHalfAway,
  scoreByte,
  scoresToBytes,
  scoutEstimate,
  seed,
  stripFromRows,
  trafficFactor,
  typicalContext,
  validateOverrides,
  walkWeight,
  weekStrip,
} from '../estimator/index';
import type {
  Assumptions,
  CalibrationState,
  DayContext,
  LocationVectors,
  OverrideMap,
  PlaceInput,
  SeedFile,
  ServiceLogEntry,
  SpotTerms,
  TruckProfile,
} from '../estimator/types';

const REGION_DC = { id: 'dc', traffic_matrix: 'dc' as const, flags: { inauguration_day: true } };
const A_DC = makeAssumptions({}, REGION_DC);

interface GoldenCase {
  id: string;
  function: string;
  args: any;
  expected: any;
}
const golden = JSON.parse(
  readFileSync(
    fileURLToPath(new URL('../../../../../tests/fixtures/truck-planner/golden_cases.json', import.meta.url)),
    'utf8',
  ),
) as { cases: GoldenCase[] };
const casesOf = (name: string): GoldenCase[] => golden.cases.filter((c) => c.function === name);
const assume = (a: { overrides: OverrideMap; region: Assumptions['region'] }): Assumptions =>
  makeAssumptions(a.overrides, a.region);

describe('direct seed readers', () => {
  const overrides: OverrideMap = {
    'segments.w_office.presence.weekday': new Array<number>(24).fill(0.25),
    'segments.v_nightlife.intent.saturday': new Array<number>(24).fill(0.125),
    'segments.res.dow_factor': [1.5, 1.4, 1.3, 1.2, 1.1],
    'segments.w_edu.holiday_day_type.minor': 'saturday',
    'segments.v_events.holiday_day_type.major': 'sunday',
  };

  it('return exactly what seed() returns for their path, with and without overrides', () => {
    for (const A of [A_DC, makeAssumptions(overrides, REGION_NONE)]) {
      for (let s = 0; s < SEGMENTS.length; s++) {
        const name = SEGMENTS[s];
        for (const dayType of DAY_TYPES) {
          expect(presenceCurve(A, s, dayType)).toBe(seed(A, `segments.${name}.presence.${dayType}`));
          expect(intentCurve(A, s, dayType)).toBe(seed(A, `segments.${name}.intent.${dayType}`));
        }
        expect(dowFactors(A, s)).toBe(seed(A, `segments.${name}.dow_factor`));
        expect(holidayDayType(A, s, 'major')).toBe(seed(A, `segments.${name}.holiday_day_type.major`));
        expect(holidayDayType(A, s, 'minor')).toBe(seed(A, `segments.${name}.holiday_day_type.minor`));
      }
    }
    const A = makeAssumptions(overrides, REGION_NONE);
    expect(presenceCurve(A, 1, 'weekday')).toBe(overrides['segments.w_office.presence.weekday']);
    expect(intentCurve(A, 8, 'saturday')[5]).toBe(0.125);
    expect(dowFactors(A, 0)).toEqual([1.5, 1.4, 1.3, 1.2, 1.1]);
    expect(holidayDayType(A, 3, 'minor')).toBe('saturday');
  });

  it('the overrides used here are ones the owner could save', () => {
    expect(validateOverrides(SEEDS, overrides)).toEqual([]);
  });

  it('read the seeds of the Assumptions they are given, not a module constant', () => {
    const other = JSON.parse(JSON.stringify(SEEDS)) as SeedFile;
    other.segments.w_office.presence.weekday[12] = 0.999;
    other.segments.w_office.dow_factor.value[3] = 2.0;
    other.segments.res.holiday_day_type.major = 'saturday';
    other.kernel.walk_decay_m.value = 800.0;
    for (const id of ['dc', 'us_mean'] as const) {
      other.traffic[id].value = other.traffic[id].value.map((row) => row.map(() => 1.0));
    }
    other.traffic.dc_typical.value = 1.0;
    const B: Assumptions = { ...A_DC, seeds: other };
    expect(presenceCurve(B, 1, 'weekday')[12]).toBe(0.999);
    expect(presenceCurve(A_DC, 1, 'weekday')[12]).toBe(0.363);
    const thursday = typicalContext(B, 3);
    expect(thursday.dow_factor[1]).toBe(2.0);
    expect(hourWeights(B, thursday).presence[1][12]).toBe(0.999 * 2.0);
    expect(dayContext(B, '2026-11-26', null, null, null, null).day_type[0]).toBe('saturday');
    expect(dayContext(A_DC, '2026-11-26', null, null, null, null).day_type[0]).toBe('sunday');
    expect(walkWeight(B, 800.0)).toBe(Math.exp(-1));
    // neutral traffic: every factor 1, and a Google leg is not rescaled
    const ctx = dayContext(B, '2026-10-08', null, null, null, null);
    expect(trafficFactor(B, ctx, 1030)).toEqual([1.0, 3, 17]);
    const profile = casesOf('leg_minutes')[0].args.profile as TruckProfile;
    const leg = legMinutes(B, profile, { source: 'google', distance_m: 7805, duration_s: 600, override_minutes: null, toll: 0 }, ctx, 1030);
    expect(leg.time_factor).toBe(1.0);
    expect(leg.minutes).toBe(11); // 10 minutes x truck time factor 1.10
  });
});

describe('curves', () => {
  it('hourColumn is column `hour` of hourWeights, to the last bit', () => {
    const contexts: DayContext[] = [
      typicalContext(A_DC, 1),
      typicalContext(A_DC, 5),
      dayContext(A_DC, '2026-11-26', null, null, null, null),
      dayContext(A_DC, '2026-10-12', null, null, null, null),
      dayContext(A_DC, '2026-10-08', 'sun', null, null, null),
    ];
    for (const ctx of contexts) {
      const w = hourWeights(A_DC, ctx);
      for (let hour = 0; hour < 24; hour++) {
        const column = hourColumn(A_DC, ctx, hour);
        expect(column.presence).toEqual(w.presence.map((row) => row[hour]));
        expect(column.intent).toEqual(w.intent.map((row) => row[hour]));
      }
    }
  });

  it('weekStrip is 168 calls of hourlyOrders on typical contexts, to the last bit', () => {
    let strips = 0;
    for (const c of casesOf('week_strip').concat(casesOf('hourly_orders'), casesOf('window_orders').slice(0, 12))) {
      const A = assume(c.args.A);
      const { profile, terms, vectors, cal } = c.args as {
        profile: TruckProfile;
        terms: SpotTerms;
        vectors: LocationVectors;
        cal: CalibrationState | null;
      };
      const strip = weekStrip(A, profile, terms, vectors, cal);
      expect(strip.length).toBe(168);
      for (let dow = 0; dow < 7; dow++) {
        const ctx = typicalContext(A, dow);
        for (let hour = 0; hour < 24; hour++) {
          expect(strip[dow * 24 + hour]).toBe(hourlyOrders(A, profile, terms, vectors, cal, ctx, hour).orders);
        }
      }
      strips += 1;
    }
    expect(strips).toBeGreaterThan(30);
  });

  it('stripFromRows equals weekStrip to the model tolerance when there is no spot factor', () => {
    let worst = 0;
    for (const c of casesOf('week_strip').concat(casesOf('hourly_orders'))) {
      const A = assume(c.args.A);
      const profile = c.args.profile as TruckProfile;
      const terms: SpotTerms = { ...(c.args.terms as SpotTerms), spot_id: null };
      const vectors = c.args.vectors as LocationVectors;
      const cal = c.args.cal as CalibrationState | null;
      const slow = weekStrip(A, profile, terms, vectors, cal);
      const fast = stripFromRows(A, profile, terms, vectors, mapWeightRows(A, profile, cal));
      for (let how = 0; how < 168; how++) {
        const scale = Math.max(1, Math.abs(slow[how]), Math.abs(fast[how]));
        worst = Math.max(worst, Math.abs(slow[how] - fast[how]) / scale);
      }
    }
    expect(worst).toBeLessThanOrEqual(1e-9);
  });
});

describe('what JavaScript would let through', () => {
  const profile = casesOf('day_plan')[0].args.profile as TruckProfile;

  it('a missing fuel price stops the day costs instead of counting as 0', () => {
    const timeline = emptyTimeline();
    expect(dayCosts(profile, timeline, 4.195).fuel).toBe(0);
    expect(() => dayCosts(profile, timeline, null as unknown as number)).toThrow(TypeError);
    expect(() => dayCosts(profile, timeline, undefined as unknown as number)).toThrow(TypeError);
    const c = casesOf('day_plan')[0];
    const A = assume(c.args.A);
    const noFuel: DayContext = { ...(c.args.ctx as DayContext), fuel_price_per_gal: null, fuel_price_source: null };
    expect(() => dayPlan(A, c.args.profile, c.args.plan, noFuel, c.args.ctx_next, c.args.legs, c.args.cal)).toThrow(TypeError);
    expect(() => dayPlan(A, c.args.profile, { date: '2026-10-08', stops: [] }, noFuel, null, {}, null)).toThrow(TypeError);
  });

  it('scouting refuses a fuel price that is not a number, whether or not the place has a drive to cost', () => {
    const withWindow = casesOf('scout_estimate').find((x) => x.expected !== null && x.expected.best_window !== null) as GoldenCase;
    const noWindow = casesOf('scout_estimate').find((x) => x.expected !== null && x.expected.best_window === null) as GoldenCase;
    const notAHost = casesOf('scout_estimate').find((x) => x.expected === null) as GoldenCase;
    for (const c of [withWindow, noWindow, notAHost]) {
      const A = assume(c.args.A);
      for (const price of [null, undefined, true, '4.195']) {
        expect(
          () => scoutEstimate(A, c.args.profile, c.args.place as PlaceInput, c.args.legs, c.args.cal, price as unknown as number),
          `${c.id} with a fuel price of ${String(price)}`,
        ).toThrow(TypeError);
      }
      // with a number (zero is a price) the case is the golden one again
      expect(scoutEstimate(A, c.args.profile, c.args.place as PlaceInput, c.args.legs, c.args.cal, 0)).not.toBeUndefined();
    }
  });

  it('a word outside the vocabulary that names another key of a seed table stops, it does not become NaN', () => {
    const event = casesOf('event_orders')[0];
    const A = assume(event.args.A);
    for (const eventType of ['unit', 'tag', 'scope', 'source']) {
      expect(
        () => GOLDEN_DISPATCH.event_orders({ ...event.args, ev: { ...event.args.ev, event_type: eventType } }),
        eventType,
      ).toThrow(TypeError);
    }
    expect(() => GOLDEN_DISPATCH.event_orders({ ...event.args, ev: { ...event.args.ev, event_type: 'street_fair' } })).toThrow();
    const capture = casesOf('capture_at_point')[0];
    const host = casesOf('host_capture').find((c) => c.expected.mode === 'open') as GoldenCase;
    for (const visibility of ['unit', 'tag', 'scope', 'source', 'Normal', '']) {
      expect(() => GOLDEN_DISPATCH.capture_at_point({ ...capture.args, visibility }), visibility).toThrow();
      expect(() => GOLDEN_DISPATCH.host_capture({ ...host.args, visibility }), visibility).toThrow();
    }
    const weather = casesOf('weather_multiplier').find((c) => c.expected.missing === false && c.expected.temp_band !== null) as GoldenCase;
    for (const setting of ['tag', 'source', 'upper_f', 'match']) {
      expect(() => GOLDEN_DISPATCH.weather_multiplier({ ...weather.args, setting }), setting).toThrow();
    }
    const rivals = casesOf('rivals_at_origin').find((c) => c.args.outlets.length > 0 && c.expected.day > 0) as GoldenCase;
    for (const kind of ['unit', 'scope']) {
      const outlets = rivals.args.outlets.map((o: { kind: string }) => ({ ...o, kind }));
      expect(() => GOLDEN_DISPATCH.rivals_at_origin({ ...rivals.args, outlets }), kind).toThrow(TypeError);
    }
    // a host segment that names a member of Object.prototype is no segment either
    const excluded = casesOf('host_exclusion').find((c) => c.args.host !== null) as GoldenCase;
    const hosted = casesOf('host_capture').find((c) => c.args.host !== null && c.args.host.size > 0) as GoldenCase;
    for (const segment of ['toString', '__proto__', 'constructor', 'valueOf', 'office']) {
      expect(() => GOLDEN_DISPATCH.host_exclusion({ ...excluded.args, host: { ...excluded.args.host, segment } }), segment).toThrow();
      expect(() => GOLDEN_DISPATCH.host_capture({ ...hosted.args, host: { ...hosted.args.host, segment } }), segment).toThrow();
    }
    // the vocabulary itself still answers
    expect(walkWeight(A, 0)).toBe(1);
  });

  it('a model error raised inside a day is the error of the day', () => {
    const c = casesOf('day_plan')[0];
    const A = assume(c.args.A);
    const stop = { ...c.args.plan.stops[1], open_minute: 1380, close_minute: 1500 }; // 23:00 to 01:00
    const plan = { date: c.args.plan.date, stops: [stop] };
    let code = 'no error';
    try {
      dayPlan(A, c.args.profile, plan, c.args.ctx, null, c.args.legs, c.args.cal);
    } catch (error) {
      code = error instanceof ModelError ? error.code : String(error);
    }
    expect(code).toBe('missing_context');
    expect(() => GOLDEN_DISPATCH.evaluate({ ...c.args, plan, ctx_next: null })).toThrow(ModelError);
    expect(dayPlan(A, c.args.profile, plan, c.args.ctx, c.args.ctx_next, c.args.legs, c.args.cal).date).toBe(c.args.plan.date);
  });

  it('a division by zero stops instead of putting Infinity or NaN on screen', () => {
    const c = casesOf('day_costs')[0];
    const profile0: TruckProfile = { ...(c.args.profile as TruckProfile), mpg: 0 };
    expect(() => dayCosts(profile0, c.args.timeline, c.args.fuel_price_per_gal)).toThrow(RangeError);
    expect(() => dayCosts(profile0, emptyTimeline(), 4.195)).toThrow(RangeError); // also 0 miles / 0 mpg
    expect(() => scoreByte(1.0, 0)).toThrow(RangeError);
    expect(() => scoresToBytes(new Float32Array(2), 2, 0, new Uint8Array(2))).toThrow(RangeError);
    expect(scoreByte(1.0, 45.0)).toBe(38);
  });

  it('roundHalfAway takes 0 to 9 decimals and nothing else', () => {
    expect(roundHalfAway(2.675, 2)).toBe(2.68);
    expect(roundHalfAway(1e-9, 9)).toBe(1e-9);
    expect(Object.is(roundHalfAway(-0.004, 2), 0)).toBe(true); // never negative zero
    expect(Object.is(roundHalfAway(-0, 0), 0)).toBe(true);
    for (const decimals of [-1, 10, 1.5, NaN]) expect(() => roundHalfAway(1.5, decimals)).toThrow(RangeError);
  });

  it('an unknown seed path is an error, also where a prototype has that name', () => {
    for (const path of ['no.such.path', 'constructor', 'segments.toString', 'segments.res.presence.weekday.3', '']) {
      expect(() => seed(A_DC, path), path).toThrow();
    }
    expect(validateOverrides(SEEDS, { constructor: 1, 'segments.hasOwnProperty': 1, __proto__x: 1 })).toEqual([
      { path: '__proto__x', error: 'unknown_path' },
      { path: 'constructor', error: 'unknown_path' },
      { path: 'segments.hasOwnProperty', error: 'unknown_path' },
    ]);
  });

  it('spot ids are looked up as own keys only', () => {
    const cal = casesOf('calibration_factor').find((c) => c.args.cal !== null)!.args.cal as CalibrationState;
    for (const id of ['constructor', 'toString', 'hasOwnProperty', '__proto__', 'valueOf']) {
      expect(calibrationFactor(cal, id)).toEqual([cal.truck_factor, 1.0]);
      expect(evidenceFrom(cal, id).spot_weight).toBe(0.0);
    }
  });

  it('a spot called like a prototype member calibrates like any other', () => {
    const log = casesOf('calibrate')[0].args.services as ServiceLogEntry[];
    const plain = calibrate(A_DC, log, '2026-10-04');
    for (const odd of ['constructor', '__proto__', 'toString']) {
      const renamed = log.map((sv) => (sv.spot_id === 'A' ? { ...sv, spot_id: odd } : sv));
      const cal = calibrate(A_DC, renamed, '2026-10-04');
      expect(Object.keys(cal.spots).sort()).toEqual([odd, 'B', 'C'].sort());
      expect(Object.prototype.hasOwnProperty.call(cal.spots, odd)).toBe(true);
      expect(cal.spots[odd]).toEqual(plain.spots.A);
      expect(cal.truck_factor).toBe(plain.truck_factor);
      expect(calibrationFactor(cal, odd)).toEqual([plain.truck_factor, plain.spots.A.factor]);
    }
  });
});

describe('edges outside the golden file, with the values the reference gives', () => {
  it('dates far outside the supported range still format and count like the reference', () => {
    expect(formatDate(0, 1, 1)).toBe('0000-01-01');
    expect(formatDate(5, 5, 5)).toBe('0005-05-05');
    expect(formatDate(-1, 12, 31)).toBe('-001-12-31'); // the sign counts in the four characters
    expect(formatDate(-123, 5, 6)).toBe('-123-05-06');
    expect(formatDate(99999, 1, 1)).toBe('99999-01-01');
    expect(daysFromCivil(-123, 5, 6)).toBe(-764327);
    expect(daysFromCivil(99999, 1, 1)).toBe(35804357);
    expect(civilFromDays(-1000000)).toEqual([-768, 2, 4]);
    expect(civilFromDays(3000000)).toEqual([10183, 9, 21]);
    expect(addDays('1970-01-01', -719162)).toBe('0001-01-01');
    expect(addDays('1970-01-01', -719163)).toBe('0000-12-31');
    expect(addDays('1970-01-01', -719600)).toBe('-001-10-21');
    expect(addDays('2199-12-31', 3000000)).toBe('10413-09-20');
  });

  it('holidays of years the date parser does not accept', () => {
    const on = { inauguration_day: true };
    expect(federalHolidays(1969, on).slice(0, 3).map((h) => `${h.id}:${h.date}`)).toEqual([
      'new_year:1969-01-01', 'mlk:1969-01-20', 'inauguration:1969-01-20',
    ]);
    expect(federalHolidays(1968, on).some((h) => h.id === 'inauguration')).toBe(false);
    expect(federalHolidays(2200, on).some((h) => h.id === 'juneteenth')).toBe(true);
    const ancient = federalHolidays(-400, {});
    expect(ancient[0]).toEqual({
      id: 'new_year', name: "New Year's Day", class: 'major', date: '-400-01-01', observed: '-401-12-31',
    });
    expect(ancient.find((h) => h.id === 'veterans')?.observed).toBe('-400-11-10');
  });

  it('an unknown "treat this day as" value changes nothing', () => {
    const ctx = dayContext(A_DC, '2026-10-08', 'bogus' as never, null, null, null);
    expect([ctx.dow, ctx.eff_dow, ctx.holiday_class, ctx.treat_as]).toEqual([3, 3, null, 'bogus']);
  });

  it('traffic lookups days before and after a holiday use the real day of the week', () => {
    const thanksgiving = dayContext(A_DC, '2026-11-26', null, null, null, null); // a Thursday; traffic row Sunday
    const want: [number, [number, number, number]][] = [
      [-2881, [1.11, 0, 23]], [-2880, [1.09, 1, 0]], [-1441, [1.12, 1, 23]], [-1440, [1.1, 2, 0]], [-1, [1.12, 2, 23]],
      [0, [1.13, 6, 0]], [59, [1.13, 6, 0]], [60, [1.1, 6, 1]], [1439, [1.11, 6, 23]], [1440, [1.09, 4, 0]],
      [2879, [1.17, 4, 23]], [2880, [1.13, 5, 0]], [10079, [1.12, 2, 23]], [10080, [1.1, 3, 0]], [-10080, [1.1, 3, 0]],
    ];
    for (const [minute, expected] of want) expect(trafficFactor(A_DC, thanksgiving, minute), String(minute)).toEqual(expected);
  });

  it('bestWindows on empty, tiny and odd inputs', () => {
    expect(bestWindows([], 1, 1, false)).toEqual([]);
    expect(bestWindows([], 1, 1, true)).toEqual([]);
    expect(bestWindows([1], 1, 1, true)).toEqual([{ start: 0, length: 1, total: 1 }]);
    expect(bestWindows([1, 2], 0, 2, false)).toEqual([]);
    expect(bestWindows([1, 2], 2, -1, true)).toEqual([{ start: 0, length: 2, total: 3 }]);
    expect(bestWindows([3, 3, 3], 1, 5, true).map((w) => w.start)).toEqual([0, 1, 2]);
    expect(bestWindows([1, 2, 3], 3, 2, true)).toEqual([{ start: 0, length: 3, total: 6 }]);
    expect(bestWindows([0.0000004, 0.0000004], 2, 1, false)).toEqual([{ start: 0, length: 2, total: 0.0000008 }]);
    expect(bestWindows([0.0000002, 0.0000002], 2, 1, false)).toEqual([]); // rounds to zero millionths
    expect(bestWindows([-1, 5, -1], 2, 2, true)).toEqual([{ start: 0, length: 2, total: 4 }]);
  });
});

describe('inputs', () => {
  it('lists handed in unsorted come back in the order they were given', () => {
    const c = casesOf('capture_at_point').find((x) => x.args.sources.length >= 4) as GoldenCase;
    const sources = c.args.sources.slice().reverse();
    const outlets = c.args.outlets.slice().reverse();
    const order = sources.map((s: { id: string }) => s.id);
    const got = captureAtPoint(assume(c.args.A), c.args.lat, c.args.lng, c.args.visibility, sources, outlets, c.args.exclusion);
    expect(sources.map((s: { id: string }) => s.id)).toEqual(order);
    // processed in ascending id whatever the order handed in: the same sums as the golden case
    expect(got.capture).toEqual(c.expected.capture);
    expect(got.nearby).toEqual(c.expected.nearby);
  });

  it('frozen arguments are accepted: nothing is written to them', () => {
    const freeze = <T>(x: T): T => {
      if (typeof x === 'object' && x !== null && !Object.isFrozen(x)) {
        Object.freeze(x);
        for (const key of Object.keys(x)) freeze((x as Record<string, unknown>)[key]);
      }
      return x;
    };
    // Every golden case again, this time with deeply frozen arguments: a write to an input (a sort in
    // place, a field set on a shared record) throws here.
    let ran = 0;
    for (const c of golden.cases) {
      const args = freeze(JSON.parse(JSON.stringify(c.args)));
      try {
        GOLDEN_DISPATCH[c.function](args);
      } catch (error) {
        if (!(error instanceof ModelError)) throw new Error(`${c.id} ${c.function}: ${String(error)}`);
      }
      ran += 1;
    }
    expect(ran).toBe(golden.cases.length);
  });

  it('small helpers behave at their edges', () => {
    expect(requiredLegKeys([])).toEqual([]);
    expect(requiredLegKeys([{ id: 'a' }])).toEqual(['base>a', 'a>base']);
    expect(requiredLegKeys([{ id: 'office' }, { id: 'taproom' }])).toEqual([
      'base>office', 'office>taproom', 'taproom>base', 'base>taproom', 'office>base',
    ]);
    expect(bestWindows([1, 5, 5, 1, 5, 5, 1], 2, 2, false)).toEqual([
      { start: 1, length: 2, total: 10 },
      { start: 4, length: 2, total: 10 },
    ]);
    expect(bestWindows([0, 0, 0], 2, 2, false, null)).toEqual([]);
  });
});
