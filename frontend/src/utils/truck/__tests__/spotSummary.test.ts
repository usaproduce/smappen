// Truck Planner - saved spots as the Spots pages show them (docs/truck-planner/05_FRONTEND.md 4.4 and 8.2).
//
// The fixtures are the two sanity anchors of 02_MODEL 8.3, taken from the golden file: the office
// park (A1) and the taproom of 120 (A2). What the list, the compare page and the spot form compute
// here is checked against the figures the model documents for them, and the spot form's request
// bodies against the rules of 04_BACKEND 4.8.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import type { HostHint, Spot, SpotBody } from '../../../api/truck';
import { patchChangesVectors } from '../../../components/truck/data/mutations';
import { fmtEstimate, fmtWeekday } from '../format';
import { SEEDS, bestWindows, dayPlan, makeAssumptions, weekStrip } from '../model';
import type { Assumptions, DayContext, Estimate, LocationVectors, SpotTerms, TruckProfile } from '../model';
import { typicalWithFuel } from '../assemble';
import {
  COMPARE_MAX,
  HOST_QUESTION_RADIUS_M,
  SPOT_LIMITS,
  allowedDaysText,
  asciiLower,
  bodyFromDraft,
  choiceHow,
  choiceOfBest,
  describeLinkedPlace,
  distanceText,
  draftFromBody,
  draftHostInput,
  draftHostSegment,
  draftHostSize,
  draftIsValid,
  draftModelTerms,
  draftPlaceKey,
  emptySpotDraft,
  feeText,
  formatCompareWindow,
  highestExpected,
  hostLine,
  hostQuestion,
  hostText,
  linkPlace,
  orderSpots,
  parseCompareIds,
  parseCompareWindow,
  patchFromDrafts,
  spotBodyOf,
  spotMatches,
  spotOneStopDay,
  spotSummaries,
  spotWindowFigures,
  typicalWeek,
  typicalWeekWithFuel,
  usableVectors,
  validateSpotDraft,
} from '../spotSummary';
import { HOST_SIZE_LABEL, numberRangeMessage, segmentGroup } from '../wording';

// -------------------------------------------------------------------------------------------------
// The anchors, from the golden file
// -------------------------------------------------------------------------------------------------

interface AnchorCase {
  id: string;
  args: {
    A: { overrides: Record<string, never>; region: Assumptions['region'] };
    cal: null;
    ctx: DayContext;
    open: number;
    close: number;
    profile: TruckProfile;
    terms: SpotTerms;
    vectors: LocationVectors;
  };
  expected: { orders: Estimate };
}

const GOLDEN_PATH = fileURLToPath(new URL('../../../../../tests/fixtures/truck-planner/golden_cases.json', import.meta.url));
const golden = JSON.parse(readFileSync(GOLDEN_PATH, 'utf8')) as {
  anchors: { id: string; case: string; min?: number; max?: number }[];
  cases: AnchorCase[];
};

function anchor(id: string): AnchorCase {
  const entry = golden.anchors.find((a) => a.id === id);
  if (!entry) throw new Error('no anchor ' + id);
  const found = golden.cases.find((c) => c.id === entry.case);
  if (!found) throw new Error('no case ' + entry.case);
  return found;
}

const a1 = anchor('A1');
const a2 = anchor('A2');
const A = makeAssumptions(a1.args.A.overrides, a1.args.A.region);
const profile = a1.args.profile;
const FUEL = { price_per_gal: 4.195, source: 'eia' as const, area: 'R1Z', product: 'EPMR' as const, period: '2026-09-28' };

function relabelled(vectors: LocationVectors, visibility: 'hidden' | 'prominent'): LocationVectors {
  return { ...vectors, visibility };
}

function spotFixture(id: string, name: string, source: AnchorCase, extra: Partial<Spot> = {}): Spot {
  const normal = source.args.vectors;
  return {
    id,
    name,
    point: { lat: 38.96, lng: -77.36 },
    address: '',
    county_fips: '51059',
    notes: null,
    terms: { ...source.args.terms, spot_id: id },
    host_details: null,
    vectors: { hidden: relabelled(normal, 'hidden'), normal, prominent: relabelled(normal, 'prominent') },
    vectors_state: 'fresh',
    logs: { count: 0, last_date: null },
    maps_url: 'https://www.google.com/maps/search/?api=1&query=38.960000%2C-77.360000',
    archived: false,
    created_at: '2026-10-04 23:50:12',
    updated_at: '2026-10-04 23:50:12',
    ...extra,
  };
}

const office = spotFixture('spot-office', 'Herndon office park', a1, { address: '1 Park Way, Herndon, VA' });
const taproom = spotFixture('spot-taproom', 'Lost Barrel taproom', a2);

// -------------------------------------------------------------------------------------------------
// The list
// -------------------------------------------------------------------------------------------------

describe('spotSummaries', () => {
  const [officeRow, taproomRow] = spotSummaries([office, taproom], A, profile, null, 3);

  it('finds the best window of the typical week for the office park (anchor A1)', () => {
    // 02_MODEL 4.7: office-park week strip, length 3: 35 (tue 11:00) 66.6553
    expect(officeRow.state).toBe('ready');
    expect(officeRow.best).toEqual({ dow: 1, open: 660, close: 840 });
    expect(officeRow.orders?.value).toBeCloseTo(66.6553, 4);
    expect(officeRow.orders?.low).toBeCloseTo(36.6, 2);
    expect(officeRow.orders?.high).toBeCloseTo(97.93, 2);
    expect(officeRow.orders?.confidence).toBe('rough');
    expect(fmtEstimate(officeRow.orders, 'orders')).toBe('67 orders (37 to 98)');
  });

  it('finds the best window for the taproom (anchor A2)', () => {
    // 02_MODEL 4.7: taproom-120 week strip, length 3: 137 (sat 17:00) 64.5480
    expect(taproomRow.best).toEqual({ dow: 5, open: 1020, close: 1200 });
    expect(taproomRow.orders?.value).toBeCloseTo(64.548, 4);
    expect(taproomRow.orders?.low).toBeCloseTo(35.37, 2);
    expect(taproomRow.orders?.high).toBeCloseTo(99.95, 2);
    expect(fmtEstimate(taproomRow.orders, 'orders')).toBe('65 orders (35 to 100)');
  });

  it('gives what the best window leaves after food and fees', () => {
    // No fee: every order leaves 15 x (1 - 0.30 - 0.85 x 0.026) - 0.50 - 0.85 x 0.15 = 9.541 dollars.
    expect(officeRow.money?.unit_margin.at_minimum).toBeCloseTo(9.541, 9);
    expect(officeRow.contribution?.value).toBeCloseTo(66.6553 * 9.541, 1);
    expect(officeRow.contribution?.low).toBeCloseTo((officeRow.orders as Estimate).low * 9.541, 6);
    expect(officeRow.contribution?.high).toBeCloseTo((officeRow.orders as Estimate).high * 9.541, 6);
    expect(officeRow.contribution?.confidence).toBe('rough');
    expect(fmtEstimate(officeRow.contribution, 'money')).toBe('$636 ($349 to $934)');
    expect(taproomRow.contribution?.value).toBeCloseTo(64.548 * 9.541, 2);
  });

  it('is what the estimator gives for the same inputs', () => {
    const week = weekStrip(A, profile, office.terms, a1.args.vectors, null);
    expect(officeRow.week).toEqual(week);
    expect(choiceOfBest(bestWindows(week, 3, 1, true)[0])).toEqual(officeRow.best);
  });

  it('follows the window length', () => {
    const [two] = spotSummaries([office], A, profile, null, 2);
    expect(two.best).toEqual({ dow: 1, open: 720, close: 840 });
    const [four] = spotSummaries([office], A, profile, null, 4);
    expect(four.best?.dow).toBe(1);
    expect((four.best?.close ?? 0) - (four.best?.open ?? 0)).toBe(240);
  });

  it('keeps the order of the spots it was given', () => {
    expect(spotSummaries([taproom, office], A, profile, null, 3).map((s) => s.spotId)).toEqual(['spot-taproom', 'spot-office']);
  });
});

describe('a chosen window', () => {
  const typical = typicalWeek(A);

  it('reproduces anchor A1 on a typical Thursday: 60 orders (33 to 93), rough', () => {
    const figures = spotWindowFigures(office, A, profile, null, typical, { dow: 3, open: 660, close: 840 });
    expect(figures).not.toBeNull();
    const orders = (figures as NonNullable<typeof figures>).window.orders;
    expect(orders.value).toBeCloseTo(a1.expected.orders.value, 9);
    expect(orders.low).toBeCloseTo(a1.expected.orders.low, 9);
    expect(orders.high).toBeCloseTo(a1.expected.orders.high, 9);
    expect(orders.value).toBeGreaterThanOrEqual(45);
    expect(orders.value).toBeLessThanOrEqual(90);
    expect(fmtEstimate(orders, 'orders')).toBe('60 orders (33 to 93)');
    expect(orders.confidence).toBe('rough');
    // 02_MODEL 4.9: the same window through stop_money, no fee
    const money = (figures as NonNullable<typeof figures>).money;
    expect(money.sales.value).toBeCloseTo(907.41, 2);
    expect(money.contribution.value).toBeCloseTo(577.17, 2);
    expect(money.contribution.low).toBeCloseTo(314.99, 2);
    expect(money.contribution.high).toBeCloseTo(889.17, 2);
  });

  it('reproduces anchor A2 on a typical Thursday: 39 orders (21 to 62), rough', () => {
    const figures = spotWindowFigures(taproom, A, profile, null, typical, { dow: 3, open: 1020, close: 1200 });
    const orders = (figures as NonNullable<typeof figures>).window.orders;
    expect(orders.value).toBeCloseTo(a2.expected.orders.value, 9);
    expect(orders.value).toBeGreaterThanOrEqual(30);
    expect(orders.value).toBeLessThanOrEqual(50);
    expect(fmtEstimate(orders, 'orders')).toBe('39 orders (21 to 62)');
    expect(orders.confidence).toBe('rough');
  });

  it('wraps the weekday and refuses a window the model would refuse', () => {
    const thursday = spotWindowFigures(office, A, profile, null, typical, { dow: 10, open: 660, close: 840 });
    expect(thursday?.choice.dow).toBe(3);
    expect(spotWindowFigures(office, A, profile, null, typical, { dow: 3, open: 840, close: 660 })).toBeNull();
    expect(spotWindowFigures(office, A, profile, null, typical, { dow: 3, open: 660, close: 2881 })).toBeNull();
    expect(spotWindowFigures(office, A, profile, null, typical, { dow: 3, open: Number.NaN, close: 840 })).toBeNull();
  });

  it('a window past midnight uses the next day of the typical week', () => {
    const figures = spotWindowFigures(taproom, A, profile, null, typical, { dow: 4, open: 1290, close: 1500 });
    // 02_MODEL 4.7: Friday 21:30-01:00 gives 5.1651 orders
    expect(figures?.window.orders.value).toBeCloseTo(5.1651, 4);
    expect(choiceHow({ dow: 4, open: 1290, close: 1500 })).toEqual({ how: 4 * 24 + 21, hours: 4 });
    expect(choiceHow({ dow: 1, open: 660, close: 840 })).toEqual({ how: 35, hours: 3 });
  });
});

describe('spots without usable vectors', () => {
  const bare: Spot = { ...office, id: 'spot-bare', name: 'No data yet', vectors: null, vectors_state: 'none' };
  // The host was saved, the vectors still belong to the spot without it: they must not be used.
  const stale: Spot = {
    ...office,
    id: 'spot-stale',
    name: 'Stale',
    vectors_state: 'stale',
    terms: { ...office.terms, host: { segment: 'w_office', size: 600, size_source: 'owner', only_food: true, point_id: null, place_type: null } },
  };

  it('a spot without stored vectors has no figure', () => {
    const [row] = spotSummaries([bare], A, profile, null, 3);
    expect(row).toEqual({ spotId: 'spot-bare', state: 'none', week: null, best: null, window: null, orders: null, money: null, contribution: null });
    expect(spotWindowFigures(bare, A, profile, null, typicalWeek(A), { dow: 3, open: 660, close: 840 })).toBeNull();
    expect(spotOneStopDay(bare, A, profile, null, typicalWeekWithFuel(A, FUEL), '2026-10-05', {}, { dow: 3, open: 660, close: 840 })).toBeNull();
  });

  it('vectors that do not match the terms are never fed to the estimator', () => {
    expect(usableVectors(stale, A)).toEqual({ state: 'mismatch', vectors: null });
    const [row] = spotSummaries([stale], A, profile, null, 3);
    expect(row.state).toBe('mismatch');
    expect(row.orders).toBeNull();
    expect(row.week).toBeNull();
  });

  it('a spot with no orders in any hour has a week but no best window', () => {
    const empty: Spot = { ...taproom, id: 'spot-empty', terms: { ...taproom.terms, host: null } };
    const [row] = spotSummaries([empty], A, profile, null, 3);
    expect(row.state).toBe('ready');
    expect(row.best).toBeNull();
    expect(row.orders).toBeNull();
    expect(row.week?.every((x) => x === 0)).toBe(true);
  });
});

describe('the one-stop day', () => {
  const typicalFuel = typicalWeekWithFuel(A, FUEL);

  it('is dayPlan with the stop, the contexts and the leg keys the spot card uses', () => {
    const choice = { dow: 3, open: 660, close: 840 };
    const legs = {
      'base>spot-office': { source: 'google' as const, distance_m: 15610, duration_s: 840, override_minutes: null, toll: 0 },
      'spot-office>base': { source: 'google' as const, distance_m: 15610, duration_s: 900, override_minutes: null, toll: 0 },
    };
    const day = spotOneStopDay(office, A, profile, null, typicalFuel, '2026-10-05', legs, choice);
    const direct = dayPlan(
      A,
      profile,
      {
        date: '2026-10-08',
        stops: [
          {
            id: 'spot-office',
            kind: 'spot',
            spot_id: 'spot-office',
            point: office.point,
            open_minute: 660,
            close_minute: 840,
            gap_before_unpaid: false,
            setup_minutes: null,
            teardown_minutes: null,
            terms: office.terms,
            vectors: a1.args.vectors,
            event: null,
            catering: null,
          },
        ],
      },
      typicalWithFuel(A, 3, FUEL),
      typicalWithFuel(A, 4, FUEL),
      legs,
      null,
    );
    expect(day).toEqual(direct);
    expect(day?.date).toBe('2026-10-08');
    expect(day?.stops[0].orders.value).toBeCloseTo(a1.expected.orders.value, 9);
    expect(day?.timeline.legs[0].source).toBe('google');
    expect(day?.warnings.some((w) => w.code === 'fallback_drive_time')).toBe(false);
    expect(day?.stops[0].adds.break_even_orders).toBeGreaterThan(0);
    expect((day?.totals.take_home.value ?? 0) < (day?.totals.contribution.value ?? 0)).toBe(true);
  });

  it('without drive legs the model falls back to a straight line and says so', () => {
    const day = spotOneStopDay(office, A, profile, null, typicalFuel, '2026-10-05', {}, { dow: 3, open: 660, close: 840 });
    expect(day?.timeline.legs[0].source).toBe('fallback');
    expect(day?.warnings.some((w) => w.code === 'fallback_drive_time')).toBe(true);
  });
});

describe('search and order', () => {
  const alpha: Spot = { ...office, id: 'b', name: 'alpha lot', address: '9 Elm St, Reston, VA' };
  const zulu: Spot = { ...taproom, id: 'a', name: 'Zulu Brewing', host_details: { place_type: 'taproom', name: 'Zulu Brewing Co', contact: null, phone: null, website: null, place_key: 'n42', google_place_id: null } };
  const bare: Spot = { ...office, id: 'c', name: 'Mid lot', vectors: null, vectors_state: 'none' };
  const spots = [zulu, bare, alpha];
  const summaries = spotSummaries(spots, A, profile, null, 3);

  it('best window first: most expected orders first, spots without a figure last', () => {
    // office 66.66 orders, taproom 64.55, the bare spot nothing
    expect(orderSpots(spots, summaries, 'best')).toEqual(['b', 'a', 'c']);
  });

  it('by name without a locale, ties by id', () => {
    expect(orderSpots(spots, summaries, 'name')).toEqual(['b', 'c', 'a']);
    const twins = [{ ...alpha, id: 'y' }, { ...alpha, id: 'x' }];
    expect(orderSpots(twins, [], 'name')).toEqual(['x', 'y']);
    expect(orderSpots(twins, [], 'best')).toEqual(['x', 'y']);
    expect(asciiLower('Zulu ÄB')).toBe('zulu Äb');
  });

  it('search looks in the name, the host line and the address', () => {
    expect(spotMatches(alpha, '')).toBe(true);
    expect(spotMatches(alpha, '  ')).toBe(true);
    expect(spotMatches(alpha, 'ALPHA')).toBe(true);
    expect(spotMatches(alpha, 'reston elm')).toBe(true);
    expect(spotMatches(alpha, 'reston oak')).toBe(false);
    expect(spotMatches(zulu, 'brewing co')).toBe(true);
    expect(spotMatches(taproom, 'taproom and bar patrons')).toBe(true); // the segment label of its host
  });

  it('the host line is the host name, else who its people are, else the kind of the linked place', () => {
    expect(hostLine(office)).toBeNull();
    expect(hostLine(taproom)).toBe(SEEDS.segments.v_nightlife.label);
    expect(hostLine(zulu)).toBe('Zulu Brewing Co');
    const linkedOnly: Spot = { ...office, host_details: { place_type: 'farmers_market', name: null, contact: null, phone: null, website: null, place_key: 'w7', google_place_id: null } };
    expect(hostLine(linkedOnly)).toBe('Farmers market');
    expect(hostText(office)).toBe('No host');
    expect(hostText(taproom)).toBe('Taproom and bar patrons, 120 people in its busiest hour');
    expect(hostText(linkedOnly)).toBe('Farmers market');
  });
});

describe('compare', () => {
  it('reads the ids of the address: each once, at most four', () => {
    expect(parseCompareIds(null)).toEqual([]);
    expect(parseCompareIds('')).toEqual([]);
    expect(parseCompareIds('a,b, c ,a,,d,e')).toEqual(['a', 'b', 'c', 'd']);
    expect(COMPARE_MAX).toBe(4);
  });

  it('reads the window of the address and falls back to the best window', () => {
    expect(parseCompareWindow(null)).toBe('best');
    expect(parseCompareWindow('best')).toBe('best');
    expect(parseCompareWindow('3-660-840')).toEqual({ dow: 3, open: 660, close: 840 });
    expect(parseCompareWindow('4-1290-1500')).toEqual({ dow: 4, open: 1290, close: 1500 });
    for (const bad of ['7-660-840', '3-840-660', '3-660-2881', '3-660', 'thu-660-840', '3-660-840-1', '-1-660-840']) {
      expect(parseCompareWindow(bad), bad).toBe('best');
    }
    expect(formatCompareWindow('best')).toBe('best');
    expect(formatCompareWindow({ dow: 3, open: 660, close: 840 })).toBe('3-660-840');
  });

  it('marks the highest expected take-home; a tie goes to the smallest id', () => {
    const e = (value: number): Estimate => ({ value, low: value - 1, high: value + 1, confidence: 'rough' });
    expect(highestExpected([])).toBeNull();
    expect(highestExpected([{ id: 'a', takeHome: null }])).toBeNull();
    expect(highestExpected([{ id: 'a', takeHome: e(100) }, { id: 'b', takeHome: e(250) }, { id: 'c', takeHome: null }])).toBe('b');
    expect(highestExpected([{ id: 'z', takeHome: e(250) }, { id: 'b', takeHome: e(250.0000001) }])).toBe('b'); // equal in whole millionths
    expect(highestExpected([{ id: 'z', takeHome: e(-5) }, { id: 'y', takeHome: e(-50) }])).toBe('z');
  });
});

describe('terms as text', () => {
  it('says the fee as a phrase', () => {
    expect(feeText({ fee_flat: 0, fee_pct: 0, fee_min: 0 })).toBe('No fee');
    expect(feeText({ fee_flat: 75, fee_pct: 0, fee_min: 0 })).toBe('$75 flat');
    expect(feeText({ fee_flat: 0, fee_pct: 0.1, fee_min: 75 })).toBe('10% of sales, $75 minimum');
    expect(feeText({ fee_flat: 0, fee_pct: 0.125, fee_min: 0 })).toBe('12.5% of sales');
    expect(feeText({ fee_flat: 50, fee_pct: 0.1, fee_min: 0 })).toBe('$50 flat plus 10% of sales');
    expect(feeText({ fee_flat: 50, fee_pct: 0.1, fee_min: 120 })).toBe('$50 flat plus 10% of sales, $120 minimum');
    expect(feeText({ fee_flat: 0, fee_pct: 0, fee_min: 40 })).toBe('$40 minimum');
    expect(feeText({ fee_flat: 12.5, fee_pct: 0, fee_min: 0 })).toBe('$12.50 flat');
    expect(feeText({ fee_flat: 1500, fee_pct: 0, fee_min: 0 })).toBe('$1,500 flat');
  });

  it('says a short distance in feet and a longer one in miles', () => {
    expect(distanceText(0)).toBe('under 10 ft');
    expect(distanceText(1.2)).toBe('under 10 ft');
    expect(distanceText(14)).toBe('50 ft'); // 45.9 ft
    expect(distanceText(50)).toBe('160 ft'); // 164.0 ft
    expect(distanceText(60)).toBe('200 ft'); // 196.9 ft, the limit of the question the form starts with
    expect(distanceText(200)).toBe('660 ft');
    expect(distanceText(250)).toBe('820 ft'); // the farthest place a request lists
    expect(distanceText(304)).toBe('0.2 mi'); // 997 ft rounds to 1,000 ft: miles from there on
    expect(distanceText(340)).toBe('0.2 mi');
    expect(distanceText(1609.344)).toBe('1.0 mi');
  });

  it('says the days of the allowed hours', () => {
    const day = (dow: number) => fmtWeekday(dow, 'short');
    expect(allowedDaysText([true, true, true, true, true, true, true], day)).toBe('Every day');
    expect(allowedDaysText([true, true, true, true, true, false, false], day)).toBe('Mon to Fri');
    expect(allowedDaysText([true, false, true, false, false, true, false], day)).toBe('Mon, Wed, Sat');
    expect(allowedDaysText([false, false, false, false, false, true, true], day)).toBe('Sat, Sun');
    expect(allowedDaysText([false, false, false, false, false, false, false], day)).toBe('No day');
  });
});

// -------------------------------------------------------------------------------------------------
// The spot form
// -------------------------------------------------------------------------------------------------

const TAPROOM_HINT: HostHint = {
  place_key: 'n4242',
  name: 'Lost Barrel Brewing',
  place_type: 'taproom',
  lat: 38.9601,
  lng: -77.3601,
  distance_m: 14,
  host_segment: 'v_nightlife',
  default_size: 40,
  kitchen: 'no',
  point_id: 'pn4242',
};
const OFFICE_PARK_HINT: HostHint = { ...TAPROOM_HINT, place_key: 'w77', name: 'Park Center', place_type: 'office_park', distance_m: 120, host_segment: 'w_office', default_size: 0, point_id: null };
const MARKET_HINT: HostHint = { ...TAPROOM_HINT, place_key: 'n9', name: 'Saturday market', place_type: 'farmers_market', distance_m: 200, host_segment: null, default_size: 0, point_id: null };

function validDraft() {
  return { ...emptySpotDraft(), name: 'Herndon office park', point: { lat: 38.96, lng: -77.36 } };
}

describe('the spot form: bodies', () => {
  it('a new spot without a host sends the body of route 11', () => {
    const draft = { ...validDraft(), address: ' 1 Park Way ', notes: '  ', visibility: 'prominent' as const, fee_pct: 0.1, fee_min: 75 };
    expect(validateSpotDraft(draft)).toEqual({});
    expect(bodyFromDraft(draft)).toEqual({
      name: 'Herndon office park',
      point: { lat: 38.96, lng: -77.36 },
      address: '1 Park Way',
      notes: null,
      terms: { visibility: 'prominent', fee_flat: 0, fee_pct: 0.1, fee_min: 75, allowed: null, host: null },
      host_details: { name: null, contact: null, phone: null, website: null },
    });
  });

  it('a linked host is sent as a place key, never as a point id', () => {
    const draft = linkPlace(validDraft(), TAPROOM_HINT);
    expect(draft.onlyFood).toBe(true); // no kitchen of its own
    expect(draftHostInput(draft)).toEqual({ place_key: 'n4242', segment: 'v_nightlife', size: 40, size_source: 'default', only_food: true });
    const typed = { ...draft, size: 120 };
    expect(draftHostInput(typed)).toEqual({ place_key: 'n4242', segment: 'v_nightlife', size: 120, size_source: 'owner', only_food: true });
    const body = bodyFromDraft(typed);
    expect(JSON.stringify(body)).not.toContain('point_id');
    expect(JSON.stringify(body)).not.toContain('pn4242');
    expect(JSON.stringify(body)).not.toContain('place_type');
    expect(body.terms?.host?.place_key).toBe('n4242');
    expect(draftPlaceKey(typed)).toBe('n4242');
    expect(draftPlaceKey(validDraft())).toBeNull();
  });

  it('a linked place gives the host its name until the owner types one', () => {
    const draft = linkPlace(validDraft(), TAPROOM_HINT);
    expect(draft.hostName).toBe('Lost Barrel Brewing');
    expect(bodyFromDraft(draft).host_details).toEqual({ name: 'Lost Barrel Brewing', contact: null, phone: null, website: null });
    // another place: the name follows the link
    expect(linkPlace(draft, OFFICE_PARK_HINT).hostName).toBe('Park Center');
    // a name the owner typed stays
    expect(linkPlace({ ...draft, hostName: 'The Barrel' }, OFFICE_PARK_HINT).hostName).toBe('The Barrel');
    // a place without a name leaves the field empty
    expect(linkPlace(validDraft(), { ...TAPROOM_HINT, name: '' }).hostName).toBe('');
  });

  it('without a host no host detail is saved', () => {
    const typed = { ...linkPlace(validDraft(), TAPROOM_HINT), hostContact: 'Sam', hostPhone: '+13017428261' };
    expect(bodyFromDraft(typed).host_details).toEqual({ name: 'Lost Barrel Brewing', contact: 'Sam', phone: '+13017428261', website: null });
    const none = bodyFromDraft({ ...typed, hostChoice: 'none' });
    expect(none.terms?.host).toBeNull();
    expect(none.host_details).toEqual({ name: null, contact: null, phone: null, website: null });
  });

  it('a linked place whose kind brings no host is sent as the link alone', () => {
    const draft = linkPlace(validDraft(), MARKET_HINT);
    expect(validateSpotDraft(draft)).toEqual({});
    expect(draftHostInput(draft)).toEqual({ place_key: 'n9' });
    expect(draftHostSegment(draft)).toBeNull();
    expect(draftModelTerms(draft, null).host).toBeNull();
  });

  it('a linked place without a typical size needs the real size', () => {
    const draft = linkPlace(validDraft(), OFFICE_PARK_HINT);
    expect(draftHostSize(draft)).toBeNull();
    expect(validateSpotDraft(draft)).toEqual({ size: 'Required' });
    expect(validateSpotDraft({ ...draft, size: 600 })).toEqual({});
    expect(draftHostInput({ ...draft, size: 600 })).toEqual({ place_key: 'w77', segment: 'w_office', size: 600, size_source: 'owner', only_food: true });
  });

  it('a described host needs who its people are and a size', () => {
    const draft = { ...validDraft(), hostChoice: 'describe' as const };
    expect(validateSpotDraft(draft)).toEqual({ segment: "Choose who the host's people are.", size: 'Required' });
    const described = { ...draft, segment: 'w_office' as const, size: 600, onlyFood: true };
    expect(validateSpotDraft(described)).toEqual({});
    expect(draftHostInput(described)).toEqual({ segment: 'w_office', size: 600, size_source: 'owner', only_food: true });
  });

  it('choosing a place without picking one stops the save', () => {
    expect(validateSpotDraft({ ...validDraft(), hostChoice: 'place' })).toEqual({ place: 'Choose a place from the list, or pick another answer.' });
  });

  it('the host size label follows the group of the segment', () => {
    const label = (draft: ReturnType<typeof validDraft>) => HOST_SIZE_LABEL[segmentGroup(draftHostSegment(draft) as NonNullable<ReturnType<typeof draftHostSegment>>)];
    expect(label(linkPlace(validDraft(), TAPROOM_HINT))).toBe('People there in its busiest hour');
    expect(label(linkPlace(validDraft(), OFFICE_PARK_HINT))).toBe('People who work there');
    expect(label({ ...validDraft(), hostChoice: 'describe', segment: 'res' })).toBe('People who live there');
    expect(label({ ...validDraft(), hostChoice: 'describe', segment: 'v_campus' })).toBe('People there in its busiest hour');
  });

  it('allowed days and hours travel whole', () => {
    const draft = { ...validDraft(), allowedOn: true, allowedDays: [true, true, true, true, true, false, false], allowedOpen: 660, allowedClose: 840 };
    expect(bodyFromDraft(draft).terms?.allowed).toEqual({ days: [true, true, true, true, true, false, false], open_minute: 660, close_minute: 840 });
    expect(validateSpotDraft({ ...draft, allowedClose: 600 })).toEqual({ allowedClose: 'The closing time must be after the opening time.' });
    expect(validateSpotDraft({ ...draft, allowedDays: [false, false, false, false, false, false, false] })).toEqual({ allowedDays: 'Tick at least one day.' });
    expect(validateSpotDraft({ ...draft, allowedOpen: null, allowedClose: null })).toEqual({ allowedOpen: 'Required', allowedClose: 'Required' });
  });
});

describe('the spot form: values out of range are refused, never clamped', () => {
  it('names the range of the fee, the share of sales and the host size', () => {
    expect(validateSpotDraft({ ...validDraft(), fee_flat: 100001 })).toEqual({ fee_flat: 'Enter a number from 0 to 100,000.' });
    expect(validateSpotDraft({ ...validDraft(), fee_min: -1 })).toEqual({ fee_min: numberRangeMessage(SPOT_LIMITS.feeMin, SPOT_LIMITS.feeMax) });
    expect(validateSpotDraft({ ...validDraft(), fee_pct: 1.2 })).toEqual({ fee_pct: 'Enter a number from 0 to 100.' });
    const hosted = { ...validDraft(), hostChoice: 'describe' as const, segment: 'w_office' as const };
    expect(validateSpotDraft({ ...hosted, size: 0 })).toEqual({ size: 'Enter a number from 1 to 200,000.' });
    expect(validateSpotDraft({ ...hosted, size: 200001 })).toEqual({ size: 'Enter a number from 1 to 200,000.' });
    expect(validateSpotDraft({ ...hosted, size: 200000 })).toEqual({});
  });

  it('leaves the refused value as it was typed', () => {
    const draft = { ...validDraft(), fee_flat: 100001 };
    expect(draftIsValid(draft)).toBe(false);
    validateSpotDraft(draft);
    expect(draft.fee_flat).toBe(100001);
    expect(draftModelTerms(draft, null).fee_flat).toBe(100001); // the preview shows what was typed, the save is held back
  });

  it('checks the texts against the lengths the server takes', () => {
    expect(validateSpotDraft(emptySpotDraft())).toEqual({
      name: 'Required',
      point: 'Pick a place first: search an address, enter coordinates or pick it on the map.',
    });
    expect(validateSpotDraft({ ...validDraft(), name: 'x'.repeat(121) })).toEqual({ name: 'Use at most 120 characters.' });
    expect(validateSpotDraft({ ...validDraft(), notes: 'x'.repeat(4001) })).toEqual({ notes: 'Use at most 4,000 characters.' });
    expect(validateSpotDraft({ ...validDraft(), hostPhone: '1'.repeat(41) })).toEqual({ hostPhone: 'Use at most 40 characters.' });
    expect(validateSpotDraft({ ...validDraft(), point: { lat: 91, lng: 0 } }).point).toBeDefined();
  });
});

describe('the spot form: editing a saved spot', () => {
  const linked: Spot = {
    ...taproom,
    terms: { ...taproom.terms, host: { segment: 'v_nightlife', size: 40, size_source: 'default', only_food: true, point_id: 'pn4242', place_type: 'taproom' } },
    host_details: { place_type: 'taproom', name: null, contact: 'Sam', phone: '+13017428261', website: null, place_key: 'n4242', google_place_id: null },
  };

  it('a saved spot round-trips: nothing changed means nothing to send', () => {
    for (const spot of [office, taproom, linked]) {
      const body = spotBodyOf(spot);
      const draft = draftFromBody(body);
      expect(patchFromDrafts(draft, draft), spot.id).toEqual({});
      expect(validateSpotDraft(draft), spot.id).toEqual({});
      const again = bodyFromDraft(draft);
      expect(again.terms, spot.id).toEqual(body.terms);
      expect(again.name).toBe(body.name);
      expect(again.point).toEqual(body.point);
    }
    expect(spotBodyOf(linked).terms?.host).toEqual({ place_key: 'n4242', segment: 'v_nightlife', size: 40, size_source: 'default', only_food: true });
    expect(JSON.stringify(spotBodyOf(linked))).not.toContain('point_id');
  });

  it('a spot saved as a link without a host starts as that link', () => {
    const market: Spot = { ...office, host_details: { place_type: 'farmers_market', name: null, contact: null, phone: null, website: null, place_key: 'n9', google_place_id: null } };
    const draft = draftFromBody(spotBodyOf(market));
    expect(draft.hostChoice).toBe('place');
    expect(draft.placeKey).toBe('n9');
    expect(draftHostInput(draft)).toEqual({ place_key: 'n9' });
  });

  it('only what changed travels', () => {
    const base = draftFromBody(spotBodyOf(office));
    expect(patchFromDrafts(base, { ...base, visibility: 'prominent' })).toEqual({ terms: { visibility: 'prominent' } });
    expect(patchFromDrafts(base, { ...base, fee_pct: 0.1, fee_min: 75 })).toEqual({ terms: { fee_pct: 0.1, fee_min: 75 } });
    expect(patchFromDrafts(base, { ...base, name: ' Herndon lot ' })).toEqual({ name: 'Herndon lot' });
    expect(patchFromDrafts(base, { ...base, notes: 'Gate code 4411' })).toEqual({ notes: 'Gate code 4411' });
    expect(patchFromDrafts(base, { ...base, point: { lat: 38.97, lng: -77.36 } })).toEqual({ point: { lat: 38.97, lng: -77.36 } });
    expect(patchFromDrafts(base, { ...base, allowedOn: true, allowedOpen: 660, allowedClose: 840 })).toEqual({
      terms: { allowed: { days: [true, true, true, true, true, true, true], open_minute: 660, close_minute: 840 } },
    });
    const hosted = draftFromBody(spotBodyOf(taproom));
    expect(patchFromDrafts(hosted, { ...hosted, hostContact: 'Sam' })).toEqual({ host_details: { contact: 'Sam' } });
    expect(patchFromDrafts(hosted, { ...hosted, hostChoice: 'none' })).toEqual({ terms: { host: null } });
    // a spot without a host has no host details to save: their fields are not on screen
    expect(patchFromDrafts(base, { ...base, hostContact: 'Sam' })).toEqual({});
  });

  it('removing the host clears the details it had, and only those', () => {
    const base = draftFromBody(spotBodyOf(linked));
    expect(patchFromDrafts(base, { ...base, hostChoice: 'none' })).toEqual({ terms: { host: null }, host_details: { contact: null, phone: null } });
    // another place: the link changes and its name comes along
    expect(patchFromDrafts(base, linkPlace(base, OFFICE_PARK_HINT))).toEqual({
      terms: { host: { place_key: 'w77', segment: 'w_office', only_food: true } },
      host_details: { name: 'Park Center' },
    });
  });

  it('an edit that changes vectors waits for the server, every other edit is instant', () => {
    const patchOf = (spot: Spot, change: (draft: ReturnType<typeof draftFromBody>) => ReturnType<typeof draftFromBody>) => {
      const base = draftFromBody(spotBodyOf(spot));
      return patchFromDrafts(base, change(base));
    };
    // instant: name, notes, fees, visibility, allowed hours, the food flag, the size of a visitor host
    expect(patchChangesVectors(office, patchOf(office, (d) => ({ ...d, visibility: 'hidden' })))).toBe(false);
    expect(patchChangesVectors(office, patchOf(office, (d) => ({ ...d, fee_flat: 50, name: 'New name' })))).toBe(false);
    expect(patchChangesVectors(taproom, patchOf(taproom, (d) => ({ ...d, onlyFood: false })))).toBe(false);
    expect(patchChangesVectors(taproom, patchOf(taproom, (d) => ({ ...d, size: 150 })))).toBe(false);
    expect(patchChangesVectors(linked, patchOf(linked, (d) => ({ ...d, size: 150 })))).toBe(false);
    expect(patchChangesVectors(linked, patchOf(linked, (d) => ({ ...d, onlyFood: false })))).toBe(false);
    // waits: the point, the link, the segment, the size of a worker or resident host
    expect(patchChangesVectors(office, patchOf(office, (d) => ({ ...d, point: { lat: 38.97, lng: -77.36 } })))).toBe(true);
    expect(patchChangesVectors(office, patchOf(office, (d) => linkPlace(d, TAPROOM_HINT)))).toBe(true);
    expect(patchChangesVectors(linked, patchOf(linked, (d) => ({ ...d, hostChoice: 'none' as const })))).toBe(true);
    expect(patchChangesVectors(taproom, patchOf(taproom, (d) => ({ ...d, segment: 'v_events' as const })))).toBe(true);
    const workers: Spot = { ...office, terms: { ...office.terms, host: { segment: 'w_office', size: 600, size_source: 'owner', only_food: true, point_id: null, place_type: null } } };
    expect(patchChangesVectors(workers, patchOf(workers, (d) => ({ ...d, size: 1000 })))).toBe(true);
    expect(patchChangesVectors(workers, patchOf(workers, (d) => ({ ...d, onlyFood: false })))).toBe(false);
  });

  it('the list of nearby places only adds the name and the typical size of the linked place', () => {
    const base = draftFromBody(spotBodyOf(linked));
    expect(base.placeName).toBe('');
    const described = describeLinkedPlace(base, [OFFICE_PARK_HINT, TAPROOM_HINT]);
    expect(described.placeName).toBe('Lost Barrel Brewing');
    expect(described.placeType).toBe('taproom');
    expect(described.placeDefaultSize).toBe(40);
    expect(patchFromDrafts(describeLinkedPlace(base, [TAPROOM_HINT]), described)).toEqual({});
    expect(describeLinkedPlace(base, null)).toBe(base);
    expect(describeLinkedPlace(base, [OFFICE_PARK_HINT])).toBe(base);
  });

  it('previews with the model terms of the draft', () => {
    const draft = { ...draftFromBody(spotBodyOf(taproom)), visibility: 'prominent' as const, fee_pct: 0.1 };
    expect(draftModelTerms(draft, 'spot-taproom')).toEqual({
      spot_id: 'spot-taproom',
      visibility: 'prominent',
      host: { segment: 'v_nightlife', size: 120, size_source: 'owner', only_food: true, point_id: null, place_type: null },
      fee_flat: 0,
      fee_pct: 0.1,
      fee_min: 0,
      allowed: null,
    });
    // a host without a size yet cannot be estimated: it counts as no host until the size is there
    expect(draftModelTerms({ ...draft, size: null }, null).host).toBeNull();
  });
});

describe('the question the form starts with', () => {
  it('asks about the nearest place within 60 m', () => {
    expect(HOST_QUESTION_RADIUS_M).toBe(60);
    expect(hostQuestion(null)).toBeNull();
    expect(hostQuestion([])).toBeNull();
    expect(hostQuestion([OFFICE_PARK_HINT, MARKET_HINT])).toBeNull();
    expect(hostQuestion([OFFICE_PARK_HINT, TAPROOM_HINT])?.place_key).toBe('n4242');
    expect(hostQuestion([{ ...OFFICE_PARK_HINT, distance_m: 60 }])?.place_key).toBe('w77');
    expect(hostQuestion([{ ...OFFICE_PARK_HINT, distance_m: 60.01 }])).toBeNull();
  });

  it('the body of a draft never names a place kind the owner did not pick', () => {
    const body: SpotBody = bodyFromDraft(validDraft());
    expect(Object.keys(body).sort()).toEqual(['address', 'host_details', 'name', 'notes', 'point', 'terms']);
    expect(Object.keys(body.terms ?? {}).sort()).toEqual(['allowed', 'fee_flat', 'fee_min', 'fee_pct', 'host', 'visibility']);
  });
});
