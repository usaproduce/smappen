// Truck Planner - from API payloads to model inputs (docs/truck-planner/05_FRONTEND.md 2.5 and 8.2).
//
// The fixtures are in the shapes of 04_BACKEND 4.1 (Plan, Spot, DayInfo, DriveLeg) and describe the
// worked day of 02_MODEL 4.12: Thursday 2026-10-08, the office park 11 AM to 2 PM, then the taproom
// 5 PM to 8 PM. Their numbers are taken from golden case g18-001, so what the assembly feeds the
// estimator here is exactly what the reference was fed there.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import type { AssumptionsInfo, DayInfo, DriveLeg, FuelInfo, Plan, PlanStop, Spot } from '../../../api/truck';
import {
  VersionMismatch,
  buildAssumptions,
  buildContext,
  coord6,
  degradedContext,
  drivePoints,
  hostKey,
  indexSpots,
  linkedHostKey,
  pointsKey,
  sameWithinTolerance,
  sortedJson,
  spotVectors,
  stopsEvaluable,
  toLegs,
  toPlanInput,
  toStopInput,
  trafficIsNeutral,
  typicalWithFuel,
  versionsMatch,
  visibilityKey,
  withinTolerance,
} from '../assemble';
import { MODEL_VERSION, SEEDS, SEEDS_REVISION, dayPlan, typicalContext } from '../model';
import type { Assumptions, DayContext, DayResult, LegInput, LocationVectors, StopInput, TruckProfile } from '../model';

// -------------------------------------------------------------------------------------------------
// The worked day, from the golden file
// -------------------------------------------------------------------------------------------------

interface GoldenDayPlan {
  id: string;
  args: {
    A: { overrides: Record<string, never>; region: Assumptions['region'] };
    cal: null;
    ctx: DayContext;
    ctx_next: DayContext;
    legs: Record<string, LegInput>;
    plan: { date: string; stops: StopInput[] };
    profile: TruckProfile;
  };
  expected: DayResult;
}

const GOLDEN_PATH = fileURLToPath(
  new URL('../../../../../tests/fixtures/truck-planner/golden_cases.json', import.meta.url),
);
const golden = JSON.parse(readFileSync(GOLDEN_PATH, 'utf8')) as { cases: GoldenDayPlan[] };
const worked = golden.cases.find((c) => c.id === 'g18-001') as GoldenDayPlan;
const [office, taproom] = worked.args.plan.stops;
const profile = worked.args.profile;

const info: AssumptionsInfo = {
  model_version: 'tps-0.1.0',
  seeds_revision: 1,
  overrides: {},
  region: worked.args.A.region,
};

/** A different, recognisable vector block: the same shape with every capture value scaled. */
function scaled(vectors: LocationVectors, visibility: 'hidden' | 'prominent', factor: number): LocationVectors {
  return {
    ...vectors,
    visibility,
    capture: {
      day: vectors.capture.day.map((x) => x * factor),
      eve: vectors.capture.eve.map((x) => x * factor),
    },
  };
}

function spotFixture(id: string, name: string, stop: StopInput): Spot {
  const normal = stop.vectors as LocationVectors;
  return {
    id,
    name,
    point: stop.point,
    address: '',
    county_fips: '51059',
    notes: null,
    terms: { ...(stop.terms as Spot['terms']), spot_id: id },
    host_details: null,
    vectors: { hidden: scaled(normal, 'hidden', 0.5), normal, prominent: scaled(normal, 'prominent', 1.25) },
    vectors_state: 'fresh',
    logs: { count: 0, last_date: null },
    maps_url: 'https://www.google.com/maps/search/?api=1&query=38.960000%2C-77.360000',
    archived: false,
    created_at: '2026-10-04 23:50:12',
    updated_at: '2026-10-04 23:50:12',
  };
}

const officeSpot = spotFixture('spot-office', 'Herndon office park', office);
const taproomSpot = spotFixture('spot-taproom', 'Taproom', taproom);
const spotsById = indexSpots([officeSpot, taproomSpot]);

function planStop(stop: StopInput, spotId: string): PlanStop {
  return {
    id: stop.id,
    kind: 'spot',
    spot_id: spotId,
    label: '',
    point: null, // the point of a spot stop is the spot's
    address: '',
    open_minute: stop.open_minute,
    close_minute: stop.close_minute,
    gap_before_unpaid: stop.gap_before_unpaid,
    setup_minutes: stop.setup_minutes,
    teardown_minutes: stop.teardown_minutes,
    fee_flat: 0,
    fee_pct: 0,
    fee_min: 0,
    event: null,
    catering: null,
  };
}

const plan: Plan = {
  id: 'plan-1',
  date: worked.args.plan.date,
  name: '',
  treat_as: null,
  notes: null,
  status: 'planned',
  stops: [planStop(office, officeSpot.id), planStop(taproom, taproomSpot.id)],
  result: null,
  context: null,
  result_state: 'none',
  evaluated_at: null,
  maps_route_url: null,
  created_at: '2026-10-04 23:50:12',
  updated_at: '2026-10-04 23:50:12',
};

function driveLeg(key: string, legInput: LegInput): DriveLeg {
  const [fromId, toId] = key.split('>');
  return {
    from_id: fromId,
    to_id: toId,
    source: 'google_routes',
    fetched_on: '2026-10-04',
    age_days: 0,
    distance_m: legInput.distance_m,
    duration_s: legInput.duration_s,
    toll_state: 'none',
    google_toll: null,
    toll_source: 'none',
    override:
      legInput.override_minutes === null
        ? null
        : { id: 'override-' + key, minutes: legInput.override_minutes, toll: null, note: '' },
    fallback_reason: null,
    leg_input: legInput,
  };
}

const driveLegs: DriveLeg[] = Object.keys(worked.args.legs).map((key) => driveLeg(key, worked.args.legs[key]));

const day: DayInfo = { date: '2026-10-08', holiday: null, context: worked.args.ctx };
const nextDay: DayInfo = { date: '2026-10-09', holiday: null, context: worked.args.ctx_next };

const fuel: FuelInfo = { price_per_gal: 4.195, source: 'eia', area: 'R1Z', product: 'EPMR', period: '2026-09-28' };

function assemble(): { A: Assumptions; stops: StopInput[] } {
  const A = buildAssumptions(info);
  const inputs = plan.stops.map((stop) => toStopInput(stop, spotsById));
  if (!stopsEvaluable(inputs)) throw new Error('the worked day must be evaluable');
  return { A, stops: inputs };
}

// -------------------------------------------------------------------------------------------------

describe('the worked day through the assembly', () => {
  it('reproduces the clock times of the day sheet', () => {
    const { A, stops } = assemble();
    const ctx = buildContext(A, day, null);
    const ctxNext = buildContext(A, nextDay, null);
    const result = dayPlan(A, profile, toPlanInput(plan.date, stops), ctx, ctxNext, toLegs(driveLegs), null);
    const minutes = result.timeline.events.filter((e) => e.kind !== 'setup_start').map((e) => e.minute);
    expect(minutes).toEqual([574, 619, 630, 660, 840, 860, 870, 1020, 1200, 1220, 1221, 1251]);
  });

  it('reproduces the take-home of 482.20 (42.35 to 1011.86)', () => {
    const { A, stops } = assemble();
    const result = dayPlan(
      A,
      profile,
      toPlanInput(plan.date, stops),
      buildContext(A, day, null),
      buildContext(A, nextDay, null),
      toLegs(driveLegs),
      null,
    );
    const takeHome = result.totals.take_home;
    expect(takeHome.confidence).toBe('rough');
    expect(withinTolerance(takeHome.value, worked.expected.totals.take_home.value)).toBe(true);
    expect(withinTolerance(takeHome.low, worked.expected.totals.take_home.low)).toBe(true);
    expect(withinTolerance(takeHome.high, worked.expected.totals.take_home.high)).toBe(true);
    expect(takeHome.value).toBeCloseTo(482.2, 2);
    expect(takeHome.low).toBeCloseTo(42.35, 2);
    expect(takeHome.high).toBeCloseTo(1011.86, 2);
  });

  it('gives the same day as the reference, stop by stop', () => {
    const { A, stops } = assemble();
    const result = dayPlan(
      A,
      profile,
      toPlanInput(plan.date, stops),
      buildContext(A, day, null),
      buildContext(A, nextDay, null),
      toLegs(driveLegs),
      null,
    );
    expect(sameWithinTolerance(result.totals, worked.expected.totals)).toBe(true);
    expect(sameWithinTolerance(result.timeline, worked.expected.timeline)).toBe(true);
    expect(result.stops.map((s) => s.id)).toEqual(['office', 'taproom']);
    for (let i = 0; i < result.stops.length; i++) {
      expect(sameWithinTolerance(result.stops[i].orders, worked.expected.stops[i].orders)).toBe(true);
      expect(sameWithinTolerance(result.stops[i].adds, worked.expected.stops[i].adds)).toBe(true);
    }
    // The second stop adds $135.02 (-$42.64 to $352.69) and needs 25.232 orders to pay for itself.
    expect(result.stops[1].adds.take_home.value).toBeCloseTo(135.02, 2);
    expect(result.stops[1].adds.take_home.low).toBeCloseTo(-42.64, 2);
    expect(result.stops[1].adds.break_even_orders).toBeCloseTo(25.232, 3);
    expect(result.warnings.map((w) => w.code)).not.toContain('stale_vectors');
  });
});

describe('buildAssumptions', () => {
  it('adds the seed file of this bundle', () => {
    const A = buildAssumptions(info);
    expect(A.seeds).toBe(SEEDS);
    expect(A.model_version).toBe(MODEL_VERSION);
    expect(A.seeds_revision).toBe(SEEDS_REVISION);
    expect(A.overrides).toBe(info.overrides);
    expect(A.region).toBe(info.region);
  });

  it('throws VersionMismatch on another model version or seeds revision', () => {
    expect(() => buildAssumptions({ ...info, model_version: 'tps-0.2.0' })).toThrow(VersionMismatch);
    expect(() => buildAssumptions({ ...info, seeds_revision: SEEDS_REVISION + 1 })).toThrow(VersionMismatch);
    try {
      buildAssumptions({ ...info, model_version: 'tps-9.9.9', seeds_revision: 7 });
      throw new Error('expected a VersionMismatch');
    } catch (e) {
      expect(e).toBeInstanceOf(VersionMismatch);
      expect((e as VersionMismatch).serverModelVersion).toBe('tps-9.9.9');
      expect((e as VersionMismatch).serverSeedsRevision).toBe(7);
    }
  });

  it('versionsMatch compares both parts', () => {
    expect(versionsMatch(MODEL_VERSION, SEEDS_REVISION)).toBe(true);
    expect(versionsMatch(MODEL_VERSION, SEEDS_REVISION + 1)).toBe(false);
    expect(versionsMatch('tp-0.1.0', SEEDS_REVISION)).toBe(false);
  });
});

describe('contexts', () => {
  const A = buildAssumptions(info);

  it('buildContext with a null override equals the server context', () => {
    expect(buildContext(A, day, null)).toEqual(day.context);
    expect(buildContext(A, nextDay, null)).toEqual(nextDay.context);
  });

  it('buildContext passes the forecast through untouched', () => {
    const forecast = [];
    for (let hour = 0; hour < 24; hour++) {
      forecast.push(hour === 3 ? null : { hour, temp_f: 58, precip_prob: hour === 12 ? null : 10, short_forecast: 'Rain', wind_mph: 5 });
    }
    const withForecast: DayInfo = { ...day, context: { ...day.context, forecast } };
    const ctx = buildContext(A, withForecast, null);
    expect(ctx.forecast).toBe(forecast);
    expect(ctx.forecast?.[12]?.precip_prob).toBeNull();
    expect(ctx.forecast?.[3]).toBeNull();
  });

  it('buildContext applies "Treat this day as" in the browser', () => {
    const asSaturday = buildContext(A, day, 'sat');
    expect(asSaturday.treat_as).toBe('sat');
    expect(asSaturday.dow).toBe(3);
    expect(asSaturday.eff_dow).toBe(5);
    expect(asSaturday.day_type[1]).toBe('saturday');
    expect(asSaturday.fuel_price_per_gal).toBe(day.context.fuel_price_per_gal);
    const asHoliday = buildContext(A, day, 'holiday');
    expect(asHoliday.holiday_class).toBe('major');
    expect(asHoliday.traffic_dow).toBe(6);
  });

  it('degradedContext has no forecast and the fuel price of the bootstrap answer', () => {
    const ctx = degradedContext(A, '2026-10-08', null, fuel);
    expect(ctx.forecast).toBeNull();
    expect(ctx.fuel_price_per_gal).toBe(4.195);
    expect(ctx.fuel_price_source).toBe('eia');
    expect(ctx.typical).toBe(false);
    expect(ctx.dow).toBe(3);
    expect(degradedContext(A, '2026-10-08', 'sun', fuel).eff_dow).toBe(6);
  });

  it('typicalWithFuel is a typical context with a fuel price', () => {
    const ctx = typicalWithFuel(A, 3, fuel);
    expect(ctx).toEqual({ ...typicalContext(A, 3), fuel_price_per_gal: 4.195, fuel_price_source: 'eia' });
    expect(ctx.typical).toBe(true);
    expect(ctx.date).toBeNull();
  });

  it('a one-stop day on typicalWithFuel evaluates and raises neither no_forecast nor holiday', () => {
    const { stops } = assemble();
    const result = dayPlan(
      A,
      profile,
      toPlanInput('2026-10-08', [stops[0]]),
      typicalWithFuel(A, 3, fuel),
      typicalWithFuel(A, 4, fuel),
      toLegs(driveLegs),
      null,
    );
    expect(result.stops).toHaveLength(1);
    expect(result.totals.take_home.value).toBeCloseTo(347.18, 2);
    expect(result.totals.fuel.value).toBeGreaterThan(0);
    const codes = result.warnings.map((w) => w.code);
    expect(codes).not.toContain('no_forecast');
    expect(codes).not.toContain('holiday');
    expect(result.date).toBe('2026-10-08');
  });
});

describe('trafficIsNeutral', () => {
  it('is false on the revision 1 seeds', () => {
    expect(trafficIsNeutral(buildAssumptions(info))).toBe(false);
    expect(trafficIsNeutral(buildAssumptions({ ...info, region: { ...info.region, traffic_matrix: 'us_mean' } }))).toBe(false);
  });

  it('is true when the matrix of the region and its typical value are all 1.0', () => {
    const seeds = JSON.parse(JSON.stringify(SEEDS)) as typeof SEEDS;
    seeds.traffic.dc.value = seeds.traffic.dc.value.map((row) => row.map(() => 1.0));
    seeds.traffic.dc_typical.value = 1.0;
    const A: Assumptions = { ...buildAssumptions(info), seeds };
    expect(trafficIsNeutral(A)).toBe(true);
    // The other matrix is untouched and plays no part for this region.
    expect(trafficIsNeutral({ ...A, region: { ...A.region, traffic_matrix: 'us_mean' } })).toBe(false);
  });

  it('is false when only the typical value differs, or a single hour', () => {
    const seeds = JSON.parse(JSON.stringify(SEEDS)) as typeof SEEDS;
    seeds.traffic.dc.value = seeds.traffic.dc.value.map((row) => row.map(() => 1.0));
    seeds.traffic.dc_typical.value = 1.265;
    expect(trafficIsNeutral({ ...buildAssumptions(info), seeds })).toBe(false);
    seeds.traffic.dc_typical.value = 1.0;
    seeds.traffic.dc.value[6][23] = 1.01;
    expect(trafficIsNeutral({ ...buildAssumptions(info), seeds })).toBe(false);
  });
});

describe('spotVectors', () => {
  it('yields the block of the visibility in the terms', () => {
    expect(spotVectors(officeSpot)).toBe(officeSpot.vectors?.normal);
    const prominent: Spot = { ...officeSpot, terms: { ...officeSpot.terms, visibility: 'prominent' } };
    expect(spotVectors(prominent)).toBe(officeSpot.vectors?.prominent);
  });

  it('yields the block that is asked for', () => {
    expect(spotVectors(officeSpot, 'hidden')).toBe(officeSpot.vectors?.hidden);
    expect(spotVectors(officeSpot, 'prominent')).toBe(officeSpot.vectors?.prominent);
    expect(spotVectors(officeSpot, 'hidden')).not.toEqual(spotVectors(officeSpot, 'normal'));
    expect(spotVectors(officeSpot, 'prominent')).not.toEqual(spotVectors(officeSpot, 'normal'));
  });

  it('is null when the spot has no stored vectors', () => {
    const bare: Spot = { ...officeSpot, vectors: null, vectors_state: 'none' };
    expect(spotVectors(bare)).toBeNull();
    expect(spotVectors(bare, 'hidden')).toBeNull();
  });
});

describe('toStopInput', () => {
  it('takes point, terms and vectors of a spot stop from the spot', () => {
    const stop = toStopInput(plan.stops[0], spotsById) as StopInput;
    expect(stop.id).toBe('office');
    expect(stop.kind).toBe('spot');
    expect(stop.spot_id).toBe('spot-office');
    expect(stop.point).toEqual(officeSpot.point);
    expect(stop.terms).toEqual({ ...officeSpot.terms, spot_id: 'spot-office' });
    expect(stop.vectors).toBe(officeSpot.vectors?.normal);
    expect(stop.open_minute).toBe(660);
    expect(stop.close_minute).toBe(840);
    expect(stop.event).toBeNull();
    expect(stop.catering).toBeNull();
  });

  it('still evaluates a stop whose spot is archived', () => {
    const archived = indexSpots([{ ...officeSpot, archived: true }]);
    const stop = toStopInput(plan.stops[0], archived) as StopInput;
    expect(stop.vectors).toBe(officeSpot.vectors?.normal);
    expect(stopsEvaluable([stop])).toBe(true);
  });

  it('keeps a temporary id of an unsaved stop', () => {
    const stop = toStopInput({ ...plan.stops[0], id: 'n1' }, spotsById) as StopInput;
    expect(stop.id).toBe('n1');
  });

  it('gives an event stop neutral terms that carry only the fee', () => {
    const event = { attendance: 2000, vendors: 6, event_type: 'general' as const };
    const stop = toStopInput(
      {
        ...plan.stops[0],
        id: 'e1',
        kind: 'event',
        spot_id: null,
        point: { lat: 38.9, lng: -77.03 },
        fee_flat: 50,
        fee_pct: 0.1,
        fee_min: 75,
        event,
      },
      spotsById,
    ) as StopInput;
    expect(stop.terms).toEqual({
      spot_id: null,
      visibility: 'normal',
      host: null,
      fee_flat: 50,
      fee_pct: 0.1,
      fee_min: 75,
      allowed: null,
    });
    expect(stop.event).toBe(event);
    expect(stop.vectors).toBeNull();
    expect(stop.point).toEqual({ lat: 38.9, lng: -77.03 });
    expect(stopsEvaluable([stop])).toBe(true);
  });

  it('gives a catering stop its catering terms and nothing else', () => {
    const catering = { headcount: 80, price_per_head: 18, guarantee: null, food_cost: null };
    const stop = toStopInput(
      { ...plan.stops[0], id: 'c1', kind: 'catering', spot_id: null, point: { lat: 38.9, lng: -77.03 }, catering },
      spotsById,
    ) as StopInput;
    expect(stop.terms).toBeNull();
    expect(stop.vectors).toBeNull();
    expect(stop.catering).toBe(catering);
    expect(stopsEvaluable([stop])).toBe(true);
  });

  it('is null for a stop without a place, and such a day is not evaluable', () => {
    expect(toStopInput({ ...plan.stops[0], spot_id: 'gone' }, spotsById)).toBeNull();
    expect(toStopInput({ ...plan.stops[0], kind: 'event', spot_id: null, point: null }, spotsById)).toBeNull();
    expect(stopsEvaluable([null])).toBe(false);
  });

  it('a spot without vectors, an event without terms and catering without terms are not evaluable', () => {
    const bare = indexSpots([{ ...officeSpot, vectors: null, vectors_state: 'none' as const }]);
    const noVectors = toStopInput(plan.stops[0], bare) as StopInput;
    expect(noVectors.vectors).toBeNull();
    expect(stopsEvaluable([noVectors])).toBe(false);
    const point = { lat: 38.9, lng: -77.03 };
    const event = toStopInput({ ...plan.stops[0], kind: 'event', spot_id: null, point, event: null }, spotsById);
    expect(stopsEvaluable([event])).toBe(false);
    const catering = toStopInput({ ...plan.stops[0], kind: 'catering', spot_id: null, point, catering: null }, spotsById);
    expect(stopsEvaluable([catering])).toBe(false);
    expect(stopsEvaluable([])).toBe(true);
  });

  it('toPlanInput keeps the order the owner chose', () => {
    const { stops } = assemble();
    const reversed = [stops[1], stops[0]];
    expect(toPlanInput('2026-10-08', reversed).stops.map((s) => s.id)).toEqual(['taproom', 'office']);
    expect(toPlanInput('2026-10-08', stops)).toEqual({ date: '2026-10-08', stops });
  });
});

describe('toLegs', () => {
  it('keys the leg inputs from_id>to_id', () => {
    const legs = toLegs(driveLegs);
    expect(Object.keys(legs).sort()).toEqual(Object.keys(worked.args.legs).sort());
    expect(legs['base>office']).toBe(worked.args.legs['base>office']);
    expect(legs['office>taproom'].override_minutes).toBe(10);
    expect(legs['taproom>base'].override_minutes).toBe(1);
  });

  it('leaves an absent pair absent, never zero-filled', () => {
    const only = toLegs(driveLegs.filter((leg) => leg.from_id === 'base' && leg.to_id === 'office'));
    expect(Object.keys(only)).toEqual(['base>office']);
    expect('office>base' in only).toBe(false);
    expect(only['office>base']).toBeUndefined();
    expect(toLegs([])).toEqual({});
  });

  it('a day with a missing leg is evaluated with a straight line and says so', () => {
    const { A, stops } = assemble();
    const legs = toLegs(driveLegs.filter((leg) => !(leg.from_id === 'office' && leg.to_id === 'taproom')));
    const result = dayPlan(
      A,
      profile,
      toPlanInput(plan.date, stops),
      buildContext(A, day, null),
      buildContext(A, nextDay, null),
      legs,
      null,
    );
    const warning = result.warnings.find((w) => w.code === 'fallback_drive_time');
    expect(warning).toBeDefined();
    expect(warning?.data).toEqual({ legs: ['office>taproom'] });
  });

  it('drivePoints lists the base first, then each stop under its own id', () => {
    const { stops } = assemble();
    expect(drivePoints(profile.base, stops)).toEqual([
      { id: 'base', lat: profile.base.lat, lng: profile.base.lng },
      { id: 'office', lat: office.point.lat, lng: office.point.lng },
      { id: 'taproom', lat: taproom.point.lat, lng: taproom.point.lng },
    ]);
    expect(drivePoints(profile.base, [])).toEqual([{ id: 'base', lat: profile.base.lat, lng: profile.base.lng }]);
  });
});

describe('cache keys', () => {
  it('coord6 writes exactly six decimals, half away from zero, never a negative zero', () => {
    expect(coord6(38.96)).toBe('38.960000');
    expect(coord6(-77.36)).toBe('-77.360000');
    expect(coord6(39.003)).toBe('39.003000');
    expect(coord6(0)).toBe('0.000000');
    expect(coord6(-0.0000001)).toBe('0.000000');
    expect(coord6(-0.0000005)).toBe('-0.000001');
    expect(coord6(12.3456785)).toBe('12.345679');
    expect(coord6(-180)).toBe('-180.000000');
    expect(coord6(0.000001)).toBe('0.000001');
  });

  it('hostKey is "-" without a host', () => {
    expect(hostKey(null)).toBe('-');
    expect(hostKey(undefined)).toBe('-');
  });

  it('hostKey ignores only_food and the size of a visitor host', () => {
    const base = { place_key: 'w264230766', segment: 'v_nightlife' as const, size: 120 };
    expect(hostKey(base)).toBe('w264230766|v_nightlife|');
    expect(hostKey({ ...base, size: 400 })).toBe(hostKey(base));
    const withFlag = { ...base, only_food: true } as typeof base;
    const withoutFlag = { ...base, only_food: false } as typeof base;
    expect(hostKey(withFlag)).toBe(hostKey(withoutFlag));
    expect(hostKey(withFlag)).toBe(hostKey(base));
  });

  it('hostKey keeps the size of a worker or resident host, the segment and the link', () => {
    const office600 = { segment: 'w_office' as const, size: 600 };
    expect(hostKey(office600)).toBe('|w_office|600');
    expect(hostKey({ ...office600, size: 1000 })).not.toBe(hostKey(office600));
    expect(hostKey({ segment: 'res' as const, size: 500 })).toBe('|res|500');
    expect(hostKey({ segment: 'w_health' as const, size: 600 })).not.toBe(hostKey(office600));
    expect(hostKey({ ...office600, place_key: 'n1' })).not.toBe(hostKey(office600));
  });

  it('hostKey keeps the size while the segment is still the server\'s to derive', () => {
    expect(hostKey({ place_key: 'n1' })).toBe('n1||');
    expect(hostKey({ place_key: 'n1', size: 80 })).toBe('n1||80');
  });

  it('linkedHostKey joins a model host with its place link', () => {
    expect(linkedHostKey(null, null)).toBe('-');
    expect(linkedHostKey(undefined, null)).toBe('-');
    expect(linkedHostKey('w1', null)).toBe('w1||');
    expect(linkedHostKey('w1', { segment: 'v_nightlife', size: 120 })).toBe('w1|v_nightlife|');
    expect(linkedHostKey(null, { segment: 'w_office', size: 600 })).toBe('|w_office|600');
    expect(linkedHostKey(null, taproom.terms?.host ?? null)).toBe('|v_nightlife|');
  });

  it('pointsKey is the sorted list of id@lat6,lng6', () => {
    const a = { id: 'base', lat: 39.003, lng: -77.405 };
    const b = { id: 's1', lat: 38.96, lng: -77.36 };
    expect(pointsKey([a, b])).toBe('base@39.003000,-77.405000|s1@38.960000,-77.360000');
    expect(pointsKey([b, a])).toBe(pointsKey([a, b]));
    expect(pointsKey([])).toBe('');
    expect(pointsKey([{ ...b, lat: 38.9600004 }])).toBe('s1@38.960000,-77.360000');
    expect(pointsKey([{ ...b, lat: 38.9600006 }])).toBe('s1@38.960001,-77.360000');
  });

  it('pointsKey sorts by code unit, not by locale', () => {
    const points = ['b', 'B', 'a', '10', '9'].map((id) => ({ id, lat: 0, lng: 0 }));
    expect(pointsKey(points).split('|').map((p) => p.split('@')[0])).toEqual(['10', '9', 'B', 'a', 'b']);
  });

  it('visibilityKey sorts the list', () => {
    expect(visibilityKey(['prominent', 'hidden', 'normal'])).toBe('hidden,normal,prominent');
    expect(visibilityKey(['normal'])).toBe('normal');
  });

  it('sortedJson orders keys at every level and drops undefined', () => {
    expect(sortedJson({ b: 1, a: { d: 2, c: [3, { z: 1, y: 2 }] } })).toBe('{"a":{"c":[3,{"y":2,"z":1}],"d":2},"b":1}');
    expect(sortedJson({ b: undefined, a: null })).toBe('{"a":null}');
    expect(sortedJson({ x: 1, y: 2 })).toBe(sortedJson({ y: 2, x: 1 }));
    expect(sortedJson({})).toBe('{}');
  });
});

describe('the tolerance of the drift checks', () => {
  it('follows the golden-case rule', () => {
    expect(withinTolerance(1, 1)).toBe(true);
    expect(withinTolerance(3, 4)).toBe(false);
    expect(withinTolerance(482.2030590342359, 482.2030590342359 * (1 + 1e-12))).toBe(true);
    expect(withinTolerance(482.2030590342359, 482.2030590342359 * (1 + 1e-6))).toBe(false);
    expect(withinTolerance(0.0, 5e-10)).toBe(true);
    expect(withinTolerance(0.0, 5e-9)).toBe(false);
  });

  it('compares whole values', () => {
    expect(sameWithinTolerance({ a: [1, 2.5, 'x', null], b: true }, { b: true, a: [1, 2.5 + 1e-12, 'x', null] })).toBe(true);
    expect(sameWithinTolerance({ a: 1 }, { a: 1, b: 2 })).toBe(false);
    expect(sameWithinTolerance([1, 2], [1, 2, 3])).toBe(false);
    expect(sameWithinTolerance({ a: null }, { a: 0 })).toBe(false);
    expect(sameWithinTolerance('a', 'a')).toBe(true);
    expect(sameWithinTolerance([1], { 0: 1 })).toBe(false);
  });
});
