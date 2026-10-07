// Truck Planner - the planner's draft of a day (docs/truck-planner/05_FRONTEND.md 4.5 and 8.2).
//
// The day used throughout is the worked day of 02_MODEL 4.12, which is also the blueprint's day
// sheet: Thursday 2026-10-08, the office park 11 AM to 2 PM, then the taproom 5 PM to 8 PM, with
// the three drives corrected to 11, 10 and 1 minutes. Its inputs come from golden case g18-001 and
// reach the estimator through the same assembly the planner uses (shapes of 04_BACKEND 4.1), so
// every figure asserted here is the one the screen prints.

import { describe, expect, it } from 'vitest';
import type { AssumptionsInfo, DayInfo, DriveLeg, Plan, PlanStop, Spot } from '../../../api/truck';
import {
  draftFromPlan,
  emptyPlanDraft,
  isTemporaryStopId,
  newDraftStopId,
  type DraftStop,
  type PlanDraft,
} from '../../../stores/truckPlanDraftStore';
import { buildAssumptions, buildContext, indexSpots, stopsEvaluable, toLegs, toPlanInput, toStopInput } from '../assemble';
import { fmtClock, fmtEstimate } from '../format';
import { dayPlan } from '../model';
import type { Assumptions, DayContext, DayResult, LegInput, LocationVectors, StopInput, TruckProfile } from '../model';
import {
  DEFAULT_WINDOW,
  KIND_LABELS,
  SAVE_HINTS,
  SAVE_STATE_TEXT,
  TREAT_AS_OPTIONS,
  addRequest,
  addStop,
  addedMessage,
  addsView,
  arriveLine,
  calendarStops,
  costLines,
  dayFacts,
  defaultSpotWindow,
  draftChanged,
  freeWindow,
  insertIndex,
  isStopNotice,
  legEstimateLine,
  legViews,
  markWaitsUnpaid,
  moveStop,
  moveStopTo,
  movedMessage,
  newCateringStop,
  newEventStop,
  newSpotStop,
  patchStop,
  planBody,
  removeStop,
  removedMessage,
  replaceStops,
  routePoints,
  saveBlocker,
  saveStateOf,
  setTreatAs,
  stopLimitText,
  stopName,
  stopNames,
  stopProblem,
  stopWarningCodes,
  tollLine,
  treatAsOf,
  wagesSaved,
  waitToggleLabel,
  weatherHours,
  workedLine,
} from '../planDraft';
import { timelineRows } from '../timelineView';
import { warningRows } from '../warnings';
import { goldenCase } from './_kitFixtures';

// -------------------------------------------------------------------------------------------------
// The worked day as the planner receives it
// -------------------------------------------------------------------------------------------------

interface GoldenDayPlan {
  args: {
    A: { overrides: Record<string, never>; region: Assumptions['region'] };
    ctx: DayContext;
    ctx_next: DayContext;
    legs: Record<string, LegInput>;
    plan: { date: string; stops: StopInput[] };
    profile: TruckProfile;
  };
  expected: DayResult;
}

const worked = goldenCase('g18-001') as unknown as GoldenDayPlan;
const [office, taproom] = worked.args.plan.stops;
const profile = worked.args.profile;
const DATE = worked.args.plan.date;

const info: AssumptionsInfo = { model_version: 'tps-0.1.0', seeds_revision: 1, overrides: {}, region: worked.args.A.region };
const A = buildAssumptions(info);

function spotFixture(id: string, name: string, stop: StopInput, patch: Partial<Spot> = {}): Spot {
  const normal = stop.vectors as LocationVectors;
  return {
    id,
    name,
    point: stop.point,
    address: name + ' address',
    county_fips: '51059',
    notes: null,
    terms: { ...(stop.terms as Spot['terms']), spot_id: id },
    host_details: null,
    vectors: { hidden: { ...normal, visibility: 'hidden' }, normal, prominent: { ...normal, visibility: 'prominent' } },
    vectors_state: 'fresh',
    logs: { count: 0, last_date: null },
    maps_url: 'https://www.google.com/maps/search/?api=1&query=38.960000%2C-77.360000',
    archived: false,
    created_at: '2026-10-04 23:50:12',
    updated_at: '2026-10-04 23:50:12',
    ...patch,
  };
}

const OFFICE_ID = '11111111-1111-4111-8111-111111111111';
const TAPROOM_ID = '22222222-2222-4222-8222-222222222222';
const STOP_1 = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const STOP_2 = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

const officeSpot = spotFixture(OFFICE_ID, 'Herndon office park', office);
const taproomSpot = spotFixture(TAPROOM_ID, 'Sterling taproom', taproom);
const spotsById = indexSpots([officeSpot, taproomSpot]);

function planStop(id: string, stop: StopInput, spotId: string): PlanStop {
  return {
    id,
    kind: 'spot',
    spot_id: spotId,
    label: '',
    point: null,
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
  id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
  date: DATE,
  name: 'Herndon lunch, then the taproom',
  treat_as: null,
  notes: null,
  status: 'planned',
  stops: [planStop(STOP_1, office, OFFICE_ID), planStop(STOP_2, taproom, TAPROOM_ID)],
  result: null,
  context: null,
  result_state: 'none',
  evaluated_at: null,
  maps_route_url: null,
  created_at: '2026-10-04 23:50:12',
  updated_at: '2026-10-04 23:50:12',
};

/** The golden legs under the ids the saved stops carry, as route 18 answers them. */
function driveLegs(): DriveLeg[] {
  const idOf: Record<string, string> = { base: 'base', office: STOP_1, taproom: STOP_2 };
  return Object.keys(worked.args.legs).map((key) => {
    const [from, to] = key.split('>');
    const input = worked.args.legs[key];
    return {
      from_id: idOf[from],
      to_id: idOf[to],
      source: 'google_routes',
      fetched_on: '2026-10-04',
      age_days: 0,
      distance_m: input.distance_m,
      duration_s: input.duration_s,
      toll_state: 'none',
      google_toll: null,
      toll_source: 'none',
      override: { id: 'override-' + key, minutes: input.override_minutes, toll: null, note: '' },
      fallback_reason: null,
      leg_input: input,
    };
  });
}

const day: DayInfo = { date: DATE, holiday: null, context: worked.args.ctx };
const nextDay: DayInfo = { date: '2026-10-09', holiday: null, context: worked.args.ctx_next };
const ctx = buildContext(A, day, null);
const ctxNext = buildContext(A, nextDay, null);

/** A draft evaluated exactly as `usePlanEvaluation` does it. */
function evaluate(draft: PlanDraft, legs: DriveLeg[] = driveLegs()): DayResult {
  const inputs = draft.stops.map((stop) => toStopInput(stop, spotsById));
  if (!stopsEvaluable(inputs)) throw new Error('the day must be evaluable');
  return dayPlan(A, profile, toPlanInput(draft.date, inputs), ctx, ctxNext, toLegs(legs), null);
}

const saved = (): PlanDraft => draftFromPlan(plan);
const ids = (draft: PlanDraft): string[] => draft.stops.map((stop) => stop.id);

// -------------------------------------------------------------------------------------------------

describe('the worked day of 02_MODEL 4.12 as the planner prints it', () => {
  const result = evaluate(saved());

  it('is the day the reference computed', () => {
    expect(result.stops).toHaveLength(2);
    expect(result.totals.take_home.value).toBeCloseTo(worked.expected.totals.take_home.value, 9);
    expect(result.totals.take_home.low).toBeCloseTo(worked.expected.totals.take_home.low, 9);
    expect(result.totals.take_home.high).toBeCloseTo(worked.expected.totals.take_home.high, 9);
  });

  it('take-home is $482 ($42 to $1,012), rough', () => {
    expect(fmtEstimate(result.totals.take_home, 'money')).toBe('$482 ($42 to $1,012)');
    expect(result.totals.take_home.confidence).toBe('rough');
    expect(fmtEstimate(result.totals.take_home_per_hour, 'money_per_hour')).toBe('$43 an hour ($4 to $90)');
    expect(workedLine(result.totals)).toBe('11.3 hours worked');
  });

  it('the second stop adds $135 (-$43 to $353) for 5.8 hours more and needs 26 orders', () => {
    const view = addsView(result.stops[1].adds, 'spot');
    expect(fmtEstimate(view.takeHome, 'money')).toBe('$135 (-$43 to $353)');
    expect(view.hoursLead).toBe('for 5.8 hours more:');
    expect(fmtEstimate(view.perHour, 'money_per_hour')).toBe('$23 an hour (-$7 to $60)');
    expect(view.breakEven).toBe('Needs 26 orders to pay for itself.');
    expect(view.fallback).toBeNull();
  });

  it('a loss is said in words, by the warnings the model raised about the stop', () => {
    expect(stopWarningCodes(result, 0)).toEqual([]);
    expect(stopWarningCodes(result, 1)).toEqual(['long_gap', 'weak_day_loss']);
    expect(addsView(result.stops[1].adds, 'spot', stopWarningCodes(result, 1)).loss).toBe('On a weak day this stop loses money.');
    expect(addsView(result.stops[0].adds, 'spot', stopWarningCodes(result, 0)).loss).toBeNull();
    expect(addsView(result.stops[1].adds, 'spot', ['below_break_even', 'weak_day_loss']).loss).toBe('This stop is expected to lose money.');
    // A catering job is contracted: it is not sold by the order, so no break-even line.
    expect(addsView({ ...result.stops[1].adds, break_even_orders: null }, 'catering').breakEven).toBeNull();
    expect(addsView({ ...result.stops[1].adds, break_even_orders: null }, 'spot').breakEven).toBe('It cannot pay for itself at these terms.');
    expect(addsView({ ...result.stops[1].adds, break_even_orders: 0.4 }, 'spot').breakEven).toBe('Needs 1 order to pay for itself.');
    expect(addsView({ ...result.stops[1].adds, per_hour: null, hours: 0 }, 'spot').hoursLead).toBeNull();
  });

  it('a stop card repeats only the warnings about its own times', () => {
    // The late example of 02_MODEL 4.11: planned 2:45 PM, setup would start at 2:15 PM, the truck arrives at 2:30 PM.
    const late = evaluate(patchStop(saved(), STOP_2, { open_minute: 885 }));
    const rows = warningRows(late, stopNames(saved().stops, spotsById), ctx);
    const shown = rows.filter((row) => isStopNotice(row, 1));
    expect(shown.map((row) => row.code)).toEqual(['late_arrival']);
    expect(shown[0].text).toBe('Sterling taproom: you would open 15 min late, at 3:00 PM.');
    expect(rows.filter((row) => isStopNotice(row, 0))).toEqual([]);
    // Problems of the day itself belong to "Things to check" alone.
    expect(isStopNotice({ level: 'warn', code: 'long_day', stopIndex: null }, 0)).toBe(false);
    expect(isStopNotice({ level: 'error', code: 'stops_overlap', stopIndex: 1 }, 1)).toBe(true);
    expect(isStopNotice({ level: 'warn', code: 'outside_allowed_hours', stopIndex: 1 }, 1)).toBe(true);
    expect(isStopNotice({ level: 'warn', code: 'long_gap', stopIndex: 1 }, 1)).toBe(false);
    expect(isStopNotice({ level: 'info', code: 'weak_day_loss', stopIndex: 1 }, 1)).toBe(false);
  });

  it('the first stop adds $319 ($57 to $631)', () => {
    const view = addsView(result.stops[0].adds, 'spot');
    expect(fmtEstimate(view.takeHome, 'money')).toBe('$319 ($57 to $631)');
    expect(view.breakEven).toMatch(/^Needs \d+ orders to pay for itself\.$/);
  });

  it('the unpaid alternative is $561 ($122 to $1,091) and saves $79 in wages', () => {
    const alternative = result.unpaid_gap_alternative;
    expect(alternative).not.toBeNull();
    if (alternative === null) return;
    expect(fmtEstimate(alternative.take_home, 'money')).toBe('$561 ($122 to $1,091)');
    expect(fmtEstimate(alternative.take_home_per_hour, 'money_per_hour')).toBe('$60 an hour ($13 to $118)');
    const saves = wagesSaved(alternative);
    expect(saves.lead + saves.figure + saves.tail).toBe('Saves $79 in wages.');
  });

  it('"Mark the wait as unpaid" turns the day into that alternative', () => {
    const marked = markWaitsUnpaid(saved(), result.timeline);
    expect(marked.stops.map((stop) => stop.gap_before_unpaid)).toEqual([false, true]);
    const after = evaluate(marked);
    expect(fmtEstimate(after.totals.take_home, 'money')).toBe('$561 ($122 to $1,091)');
    expect(after.unpaid_gap_alternative).toBeNull();
    expect(dayFacts(after.timeline).map((line) => line.label + ' ' + line.value)).toContain('Unpaid break 2 h');
    // Nothing left to mark: the same draft comes back.
    expect(markWaitsUnpaid(marked, after.timeline)).toBe(marked);
  });

  it('the cost lines are labour, fuel, tolls and the fixed cost', () => {
    const lines = costLines(result.totals, profile, ctx);
    expect(lines.map((line) => line.label)).toEqual(['Labour', 'Fuel', 'Tolls', 'Fixed cost for the day']);
    expect(lines[0].value).toBe('$447');
    expect(lines[0].sub).toBe('11.3 paid hours x 2 crew x $18.00 + 10%');
    expect(lines[1].value).toBe('$23.91');
    expect(lines[1].sub).toBe('1.100 gal driving + 4.600 gal generator at $4.195/gal (default price)');
    expect(lines[2].value).toBe('$0.00');
    expect(lines[3].value).toBe('$0');
    const facts = dayFacts(result.timeline);
    expect(facts.map((line) => line.label + ': ' + line.value)).toEqual(['Day length: 11 h 17 min', 'Driving: 22 min, 9.9 mi']);
  });
});

describe('the blueprint day sheet: twelve clock times', () => {
  const result = evaluate(saved());
  const names = stopNames(saved().stops, spotsById);

  it('the timeline rows read 9:34 AM to 8:51 PM in order', () => {
    const rows = timelineRows(result.timeline, names).map((row) => fmtClock(row.minute) + ' ' + row.label);
    expect(rows).toEqual([
      '9:34 AM Start prep',
      '10:19 AM Leave base',
      '10:30 AM Arrive at Herndon office park',
      '11:00 AM Open',
      '2:00 PM Close',
      '2:20 PM Leave Herndon office park',
      '2:30 PM Arrive at Sterling taproom',
      '4:30 PM Start setting up',
      '5:00 PM Open',
      '8:00 PM Close',
      '8:20 PM Leave Sterling taproom',
      '8:21 PM Back at base',
      '8:51 PM Done',
    ]);
  });

  it('the drive rows and the stop cards carry the same times', () => {
    const legs = legViews(result.timeline, driveLegs(), false);
    expect(legs.map((leg) => leg.title)).toEqual([
      'Drive from base: 11 min, 4.9 mi',
      'Drive: 10 min, 4.9 mi',
      'Drive back to base: 1 min, 0.2 mi',
    ]);
    expect(legs.map((leg) => leg.times)).toEqual([
      'Start prep at 9:34 AM. Leave base by 10:19 AM',
      'Leave at 2:20 PM',
      'Leave at 8:20 PM. Back at base about 8:21 PM, done by 8:51 PM',
    ]);
    expect(legs.map((leg) => leg.position)).toEqual(['first', 'between', 'last']);
    expect(legs.map((leg) => [leg.fromStop, leg.toStop])).toEqual([[null, 0], [0, 1], [1, null]]);
    expect(legs.map((leg) => leg.key)).toEqual(['base>' + STOP_1, STOP_1 + '>' + STOP_2, STOP_2 + '>base']);
    expect(result.timeline.stops.map(arriveLine)).toEqual([
      'Arrive 10:30 AM, set up from 10:30 AM',
      'Arrive 2:30 PM, set up from 4:30 PM',
    ]);
    expect(waitToggleLabel(result.timeline.stops[1].gap_before_minutes)).toBe('The 2 h wait before this stop is an unpaid break');
  });

  it("a corrected drive reads \"Your time\"", () => {
    const legs = legViews(result.timeline, driveLegs(), false);
    for (const leg of legs) {
      expect(leg.source).toBe('Your time');
      expect(leg.straight).toBe(false);
      expect(leg.reason).toBeNull();
      expect(leg.sent).not.toBeNull();
    }
  });
});

describe('drive rows: source, reason and toll', () => {
  /** The day with the three drives of the timeline replaced. */
  function withLegs(change: (leg: DriveLeg) => DriveLeg): { result: DayResult; sent: DriveLeg[] } {
    const sent = driveLegs().map(change);
    return { result: evaluate(saved(), sent), sent };
  }

  it('a Google time names the traffic it was adjusted for', () => {
    const { result, sent } = withLegs((leg) => ({
      ...leg,
      duration_s: 600,
      override: null,
      leg_input: { ...leg.leg_input, duration_s: 600, override_minutes: null },
    }));
    const legs = legViews(result.timeline, sent, false);
    // 10 minutes at 10:19 AM: 1.31 / 1.265 x 1.10 = 11.39, so the truck still leaves at 10:19.
    expect(legs[0].title).toBe('Drive from base: 11 min, 4.9 mi');
    expect(legs[0].source).toBe('Google drive time, adjusted for 10:19 AM traffic');
    expect(legs[1].source).toBe('Google drive time, adjusted for 2:20 PM traffic');
    expect(legs[1].straight).toBe(false);
    // The region's traffic table changes nothing: the adjustment is not mentioned.
    expect(legViews(result.timeline, sent, true)[1].source).toBe('Google drive time');
    expect(legEstimateLine(sent[0], false)).toBe('Google: 10 min, 4.9 mi, before the time-of-day adjustment.');
    expect(legEstimateLine(sent[0], true)).toBe('Google: 10 min, 4.9 mi.');
  });

  it('a straight line says so, with the reason the server gave', () => {
    const { result, sent } = withLegs((leg) => ({
      ...leg,
      source: 'straight_line',
      fetched_on: null,
      age_days: null,
      duration_s: 526.315,
      distance_m: 8012.82,
      toll_state: 'not_asked',
      override: null,
      fallback_reason: 'no_key',
      leg_input: { source: 'fallback', distance_m: 8012.82, duration_s: 526.315, override_minutes: null, toll: 0 },
    }));
    const legs = legViews(result.timeline, sent, false);
    expect(legs).toHaveLength(3);
    for (const leg of legs) {
      expect(leg.source).toBe('Straight-line estimate');
      expect(leg.straight).toBe(true);
      expect(leg.reason).toBe('Google drive times are not switched on for this server.');
    }
    expect(result.warnings.map((w) => w.code)).toContain('fallback_drive_time');
    expect(addsView(result.stops[1].adds, 'spot').fallback).toBe('Uses a straight-line drive estimate.');
    expect(legEstimateLine(sent[0], false)).toBe('Straight-line estimate: 9 min, 5.0 mi, before the time-of-day adjustment.');
    // A hop of a few seconds is never printed as "0 min".
    const hop: DriveLeg = { ...sent[2], duration_s: 21.0, distance_m: 161.0 };
    expect(legEstimateLine(hop, false)).toBe('Straight-line estimate: under 1 min, 0.1 mi, before the time-of-day adjustment.');
    expect(legEstimateLine({ ...hop, duration_s: 30.0 }, true)).toBe('Straight-line estimate: 1 min, 0.1 mi.');
    const reasons: [DriveLeg['fallback_reason'], string | null][] = [
      ['refused', 'Google drive times are not switched on for this server.'],
      ['quota', 'The Google drive-time allowance is used up for now.'],
      ['budget', 'The Google drive-time allowance is used up for now.'],
      ['rate', 'The Google drive-time allowance is used up for now.'],
      ['timeout', 'Google did not answer in time.'],
      ['upstream', 'Google did not answer in time.'],
      ['route_not_found', 'Google found no route.'],
      ['cache_only', null],
    ];
    for (const [reason, text] of reasons) {
      const one = sent.map((leg) => ({ ...leg, fallback_reason: reason }));
      expect(legViews(result.timeline, one, false)[0].reason).toBe(text);
    }
  });

  it('a pair the server did not send is a straight line without a reason', () => {
    const result = evaluate(saved(), []);
    const legs = legViews(result.timeline, [], false);
    expect(legs.map((leg) => leg.source)).toEqual(['Straight-line estimate', 'Straight-line estimate', 'Straight-line estimate']);
    expect(legs.map((leg) => leg.reason)).toEqual([null, null, null]);
    expect(legs.map((leg) => leg.sent)).toEqual([null, null, null]);
    expect(legEstimateLine(null, false)).toBe('Straight-line estimate.');
  });

  it('the same place is no drive', () => {
    const { result, sent } = withLegs((leg) => ({
      ...leg,
      source: 'same_point',
      distance_m: 0,
      duration_s: 0,
      override: null,
      leg_input: { source: 'google', distance_m: 0, duration_s: 0, override_minutes: null, toll: 0 },
    }));
    const legs = legViews(result.timeline, sent, false);
    expect(legs[0].source).toBe('Same place');
    expect(legs[0].title).toBe('Drive from base: 0 min, under 0.1 mi');
    expect(legEstimateLine(sent[0], false)).toBe('Same place: no drive.');
  });

  it('toll lines: the owner\'s figure, Google\'s estimate, an unknown amount, nothing', () => {
    const [leg] = driveLegs();
    expect(tollLine(null)).toBeNull();
    expect(tollLine(leg)).toBeNull();
    expect(tollLine({ ...leg, toll_state: 'estimate', google_toll: 3.75 })).toBe("Toll $3.75, Google's estimate");
    expect(tollLine({ ...leg, toll_state: 'unknown' })).toBe('Tolls on this route, amount unknown');
    expect(tollLine({ ...leg, toll_state: 'not_asked' })).toBeNull();
    const own = { ...leg, toll_state: 'estimate' as const, google_toll: 3.75, override: { id: 'o1', minutes: null, toll: 2.5, note: '' } };
    expect(tollLine(own)).toBe('Toll $2.50, your figure');
    // "No toll on my way" is a correction too.
    expect(tollLine({ ...own, override: { id: 'o1', minutes: null, toll: 0, note: '' } })).toBe('Toll $0.00, your figure');
  });

  it('a toll the owner entered is counted in the day', () => {
    const { result } = withLegs((leg) =>
      leg.from_id === 'base' ? { ...leg, override: { id: 'o', minutes: 11, toll: 4.25, note: '' }, leg_input: { ...leg.leg_input, toll: 4.25 } } : leg,
    );
    expect(costLines(result.totals, profile, ctx)[2].value).toBe('$4.25');
  });
});

describe('add, move, remove', () => {
  it('a new stop goes where its window falls in the day; the others keep their order', () => {
    const base = saved(); // 11:00 to 14:00, then 17:00 to 20:00
    expect(insertIndex(base.stops, { open: 480, close: 660 })).toBe(0);
    expect(insertIndex(base.stops, { open: 840, close: 1020 })).toBe(1);
    expect(insertIndex(base.stops, { open: 1200, close: 1380 })).toBe(2);
    expect(insertIndex([], DEFAULT_WINDOW)).toBe(0);

    const early = newSpotStop(TAPROOM_ID, { open: 480, close: 660 });
    expect(ids(addStop(base, early))).toEqual([early.id, STOP_1, STOP_2]);
    const middle = newSpotStop(TAPROOM_ID, { open: 840, close: 1020 });
    expect(ids(addStop(base, middle))).toEqual([STOP_1, middle.id, STOP_2]);
    const late = newSpotStop(OFFICE_ID, { open: 1200, close: 1380 });
    expect(ids(addStop(base, late))).toEqual([STOP_1, STOP_2, late.id]);
    // A given position wins over the window.
    expect(ids(addStop(base, late, 0))).toEqual([late.id, STOP_1, STOP_2]);
    expect(ids(addStop(base, late, 99))).toEqual([STOP_1, STOP_2, late.id]);
    // The draft handed in is never changed.
    expect(ids(base)).toEqual([STOP_1, STOP_2]);
  });

  it('a day built in that way evaluates without an overlap', () => {
    const lunch = newSpotStop(OFFICE_ID, { open: 660, close: 840 });
    const dinner = newSpotStop(TAPROOM_ID, { open: 1020, close: 1200 });
    // Dinner first, then lunch: lunch still lands before dinner.
    const draft = addStop(addStop(emptyPlanDraft(DATE), dinner), lunch);
    expect(ids(draft)).toEqual([lunch.id, dinner.id]);
    const result = evaluate(draft, []);
    expect(result.warnings.map((w) => w.code)).not.toContain('stops_overlap');
    expect(result.stops).toHaveLength(2);
  });

  it('moves a stop one place, and nothing at the ends of the day', () => {
    const base = saved();
    expect(ids(moveStop(base, STOP_1, 1))).toEqual([STOP_2, STOP_1]);
    expect(ids(moveStop(base, STOP_2, -1))).toEqual([STOP_2, STOP_1]);
    expect(moveStop(base, STOP_1, -1)).toBe(base);
    expect(moveStop(base, STOP_2, 1)).toBe(base);
    expect(moveStop(base, 'nobody', 1)).toBe(base);
  });

  it('drops a stop at any position, keeping the order of the rest', () => {
    const third = newSpotStop(OFFICE_ID, { open: 1260, close: 1380 });
    const three = addStop(saved(), third);
    expect(ids(moveStopTo(three, third.id, 0))).toEqual([third.id, STOP_1, STOP_2]);
    expect(ids(moveStopTo(three, STOP_1, 2))).toEqual([STOP_2, third.id, STOP_1]);
    expect(ids(moveStopTo(three, STOP_1, 99))).toEqual([STOP_2, third.id, STOP_1]);
    expect(ids(moveStopTo(three, STOP_2, -5))).toEqual([STOP_2, STOP_1, third.id]);
    expect(moveStopTo(three, STOP_2, 1)).toBe(three);
  });

  it('a stop the owner moved stays where it was put, also when the day no longer evaluates', () => {
    const swapped = moveStop(saved(), STOP_1, 1); // the taproom at 5 PM now comes before lunch at 11 AM
    const result = evaluate(swapped);
    expect(result.warnings.map((w) => w.code)).toEqual(['stops_overlap']);
    expect(result.stops).toEqual([]);
    expect(ids(swapped)).toEqual([STOP_2, STOP_1]);
    expect(planBody(swapped).stops.map((stop) => stop.id)).toEqual([STOP_2, STOP_1]);
  });

  it('removes a stop', () => {
    const base = saved();
    expect(ids(removeStop(base, STOP_1))).toEqual([STOP_2]);
    expect(removeStop(base, 'nobody')).toBe(base);
    expect(ids(base)).toEqual([STOP_1, STOP_2]);
  });

  it('edits a stop without touching its id or its kind', () => {
    const edited = patchStop(saved(), STOP_2, { open_minute: 1050, id: 'other', kind: 'event' } as Partial<DraftStop>);
    expect(edited.stops[1].open_minute).toBe(1050);
    expect(edited.stops[1].id).toBe(STOP_2);
    expect(edited.stops[1].kind).toBe('spot');
    const base = saved();
    // The other stops are the same objects, and a stop that is not there changes nothing.
    expect(patchStop(base, STOP_2, { open_minute: 1050 }).stops[0]).toBe(base.stops[0]);
    expect(patchStop(base, 'nobody', { open_minute: 1 })).toBe(base);
  });

  it('"Treat this day as" and a suggested day', () => {
    const base = saved();
    expect(setTreatAs(base, null)).toBe(base);
    expect(setTreatAs(base, 'sat').treat_as).toBe('sat');
    const replaced = replaceStops(base, [{ spot_id: TAPROOM_ID, open_minute: 1020, close_minute: 1200 }]);
    expect(replaced.stops).toHaveLength(1);
    expect(replaced.stops[0].spot_id).toBe(TAPROOM_ID);
    expect(isTemporaryStopId(replaced.stops[0].id)).toBe(true);
    expect(replaced.planId).toBe(plan.id);
  });

  it('says what happened, for the live region', () => {
    expect(movedMessage('Sterling taproom', 1, 2)).toBe('Moved Sterling taproom to position 1 of 2.');
    expect(addedMessage('Herndon office park', 1, 3)).toBe('Added Herndon office park as stop 1 of 3.');
    expect(removedMessage('Sterling taproom')).toBe('Removed Sterling taproom.');
    expect(stopLimitText(8)).toBe('A day holds at most 8 stops.');
  });
});

describe('temporary ids', () => {
  it('come from the counter, are never "base" and never look like a saved id', () => {
    const first = newDraftStopId();
    const made = [newSpotStop(OFFICE_ID, DEFAULT_WINDOW), newEventStop(A, DEFAULT_WINDOW), newCateringStop(DEFAULT_WINDOW)];
    const numbers = made.map((stop) => Number(stop.id.slice(1)));
    const start = Number(first.slice(1));
    expect(numbers).toEqual([start + 1, start + 2, start + 3]);
    for (const stop of made) {
      expect(stop.id).toMatch(/^n[0-9]+$/);
      expect(stop.id).not.toBe('base');
      expect(isTemporaryStopId(stop.id)).toBe(true);
    }
    expect(isTemporaryStopId(STOP_1)).toBe(false);
    expect(isTemporaryStopId('base')).toBe(false);
  });

  it('are absent from the save body, while saved stops keep theirs', () => {
    const added = newSpotStop(TAPROOM_ID, { open: 1260, close: 1380 });
    const body = planBody(addStop(saved(), added));
    expect(body.stops).toHaveLength(3);
    expect(body.stops.map((stop) => stop.id)).toEqual([STOP_1, STOP_2, undefined]);
    expect('id' in body.stops[2]).toBe(false);
    expect(JSON.stringify(body)).not.toContain(added.id);
  });

  it('a new event stop starts from the typical terms of the seed file', () => {
    const event = newEventStop(A, { open: 600, close: 780 });
    expect(event.kind).toBe('event');
    expect([event.fee_flat, event.fee_pct, event.fee_min]).toEqual([0, 0.1, 75]);
    expect(event.event).toBeNull();
    expect(event.point).toBeNull();
    const catering = newCateringStop({ open: 600, close: 780 });
    expect([catering.kind, catering.fee_pct, catering.fee_min, catering.catering]).toEqual(['catering', 0, 0, null]);
  });
});

describe('the window a new stop starts with', () => {
  it('is the best three hours of the date at that spot', () => {
    expect(defaultSpotWindow(A, profile, null, ctx, officeSpot, [])).toEqual({ open: 660, close: 840 });
    expect(defaultSpotWindow(A, profile, null, ctx, taproomSpot, [])).toEqual({ open: 1020, close: 1200 });
  });

  it('avoids the stops already in the day', () => {
    const lunch = [{ open_minute: 660, close_minute: 840 }];
    const again = defaultSpotWindow(A, profile, null, ctx, officeSpot, lunch);
    expect(again).not.toEqual({ open: 660, close: 840 });
    expect(again.close - again.open).toBe(180);
    expect(again.close <= 660 || again.open >= 840).toBe(true);

    const dinner = [{ open_minute: 1020, close_minute: 1200 }];
    const taproomAgain = defaultSpotWindow(A, profile, null, ctx, taproomSpot, dinner);
    expect(taproomAgain.close <= 1020 || taproomAgain.open >= 1200).toBe(true);

    // Half an hour of another stop inside an hour takes that hour out.
    const halfHour = defaultSpotWindow(A, profile, null, ctx, officeSpot, [{ open_minute: 690, close_minute: 720 }]);
    expect(halfHour.close <= 660 || halfHour.open >= 720).toBe(true);

    // The day built from two defaults evaluates without an overlap.
    const first = newSpotStop(OFFICE_ID, defaultSpotWindow(A, profile, null, ctx, officeSpot, []));
    let draft = addStop(emptyPlanDraft(DATE), first);
    const second = newSpotStop(TAPROOM_ID, defaultSpotWindow(A, profile, null, ctx, taproomSpot, draft.stops));
    draft = addStop(draft, second);
    expect(ids(draft)).toEqual([first.id, second.id]);
    expect(evaluate(draft, []).warnings.map((w) => w.code)).not.toContain('stops_overlap');
  });

  it('keeps to the days and hours the owner set for the spot when a window fits', () => {
    const evenings: Spot = {
      ...officeSpot,
      terms: { ...officeSpot.terms, allowed: { days: [true, true, true, true, true, true, true], open_minute: 960, close_minute: 1320 } },
    };
    const window = defaultSpotWindow(A, profile, null, ctx, evenings, []);
    expect(window.open >= 960 && window.close <= 1320).toBe(true);
    // Thursday is not one of its days: the best free window is used and the model says so.
    const notThursday: Spot = {
      ...officeSpot,
      terms: { ...officeSpot.terms, allowed: { days: [true, true, true, false, true, true, true], open_minute: 0, close_minute: 1440 } },
    };
    expect(defaultSpotWindow(A, profile, null, ctx, notThursday, [])).toEqual({ open: 660, close: 840 });
  });

  it('falls back to 11 AM to 2 PM', () => {
    expect(DEFAULT_WINDOW).toEqual({ open: 660, close: 840 });
    // No context yet.
    expect(defaultSpotWindow(A, profile, null, null, taproomSpot, [])).toEqual({ open: 660, close: 840 });
    // No stored vectors.
    expect(defaultSpotWindow(A, profile, null, ctx, { ...taproomSpot, vectors: null, vectors_state: 'none' }, [])).toEqual({ open: 660, close: 840 });
    // Vectors that belong to other terms are never used.
    const mismatched: Spot = { ...taproomSpot, terms: { ...taproomSpot.terms, visibility: 'prominent' }, vectors: { ...taproomSpot.vectors!, prominent: taproomSpot.vectors!.normal } };
    expect(defaultSpotWindow(A, profile, null, ctx, mismatched, [])).toEqual({ open: 660, close: 840 });
    // The whole day is taken.
    expect(defaultSpotWindow(A, profile, null, ctx, officeSpot, [{ open_minute: 0, close_minute: 1440 }])).toEqual({ open: 660, close: 840 });
  });

  it('an event or a catering job starts at 11 AM, or after the last stop, or in the last gap before it', () => {
    expect(freeWindow([])).toEqual({ open: 660, close: 840 });
    expect(freeWindow([{ open_minute: 1020, close_minute: 1200 }])).toEqual({ open: 660, close: 840 });
    expect(freeWindow([{ open_minute: 660, close_minute: 840 }])).toEqual({ open: 840, close: 1020 });
    expect(freeWindow([{ open_minute: 660, close_minute: 850 }])).toEqual({ open: 900, close: 1080 });
    expect(freeWindow([{ open_minute: 660, close_minute: 840 }, { open_minute: 900, close_minute: 1080 }])).toEqual({ open: 1080, close: 1260 });
    // Nothing fits after an evening that runs to 11 PM: the gap between lunch and the evening.
    expect(
      freeWindow([
        { open_minute: 660, close_minute: 840 },
        { open_minute: 1020, close_minute: 1200 },
        { open_minute: 1200, close_minute: 1380 },
      ]),
    ).toEqual({ open: 840, close: 1020 });
    // One long stop from 10 AM to 11 PM: the three hours before it.
    expect(freeWindow([{ open_minute: 600, close_minute: 1380 }])).toEqual({ open: 420, close: 600 });
    // A day with no three free hours between 6 AM and midnight: 11 AM to 2 PM all the same.
    expect(freeWindow([{ open_minute: 420, close_minute: 1380 }])).toEqual({ open: 660, close: 840 });
    expect(freeWindow([{ open_minute: 0, close_minute: 1440 }])).toEqual({ open: 660, close: 840 });
  });

  it('reads the prefill of a link', () => {
    const q = (text: string) => new URLSearchParams(text);
    expect(addRequest(q(''))).toBeNull();
    expect(addRequest(q('add='))).toBeNull();
    expect(addRequest(q('add=' + OFFICE_ID))).toEqual({ spotId: OFFICE_ID, window: null });
    expect(addRequest(q('add=' + OFFICE_ID + '&open=660&close=840'))).toEqual({ spotId: OFFICE_ID, window: { open: 660, close: 840 } });
    expect(addRequest(q('add=x&open=1290&close=1500'))).toEqual({ spotId: 'x', window: { open: 1290, close: 1500 } });
    // A value that is not valid counts as absent.
    for (const bad of ['open=840&close=660', 'open=660', 'close=840', 'open=11am&close=840', 'open=-60&close=840', 'open=660&close=2881', 'open=6.5&close=840', 'open=660&close=660']) {
      expect(addRequest(q('add=x&' + bad)), bad).toEqual({ spotId: 'x', window: null });
    }
  });
});

describe('the save body (04_BACKEND 4.11)', () => {
  it('carries date, treat_as, notes, status and the stops in order, and no state', () => {
    const body = planBody(saved());
    expect(Object.keys(body).sort()).toEqual(['date', 'notes', 'status', 'stops', 'treat_as']);
    expect(body.status).toBe('planned');
    expect('state' in body).toBe(false);
    expect('name' in body).toBe(false);
    expect(body.date).toBe(DATE);
    expect(body.treat_as).toBeNull();
    expect(body.notes).toBeNull();
    expect(body.stops).toEqual([
      { id: STOP_1, kind: 'spot', spot_id: OFFICE_ID, open_minute: 660, close_minute: 840, gap_before_unpaid: false, setup_minutes: null, teardown_minutes: null },
      { id: STOP_2, kind: 'spot', spot_id: TAPROOM_ID, open_minute: 1020, close_minute: 1200, gap_before_unpaid: false, setup_minutes: null, teardown_minutes: null },
    ]);
  });

  it('keeps the notes and the override of the day', () => {
    const body = planBody({ ...saved(), treat_as: 'holiday', notes: 'Bring the second fryer' });
    expect(body.treat_as).toBe('holiday');
    expect(body.notes).toBe('Bring the second fryer');
  });

  it('sends what an event and a catering job need, and no fee for a spot', () => {
    const event: DraftStop = {
      ...newEventStop(A, { open: 600, close: 840 }),
      label: 'Fall festival',
      point: { lat: 38.95, lng: -77.35 },
      address: 'Lake Anne Plaza',
      event: { attendance: 4000, vendors: 12, event_type: 'general' },
    };
    const catering: DraftStop = {
      ...newCateringStop({ open: 1020, close: 1140 }),
      label: 'Office lunch',
      point: { lat: 38.96, lng: -77.36 },
      catering: { headcount: 80, price_per_head: 14, guarantee: null, food_cost: null },
    };
    const body = planBody(addStop(addStop(emptyPlanDraft(DATE), event), catering));
    expect(body.stops[0]).toEqual({
      kind: 'event',
      open_minute: 600,
      close_minute: 840,
      gap_before_unpaid: false,
      setup_minutes: null,
      teardown_minutes: null,
      point: { lat: 38.95, lng: -77.35 },
      label: 'Fall festival',
      address: 'Lake Anne Plaza',
      fee_flat: 0,
      fee_pct: 0.1,
      fee_min: 75,
      event: { attendance: 4000, vendors: 12, event_type: 'general' },
    });
    expect(body.stops[1]).toEqual({
      kind: 'catering',
      open_minute: 1020,
      close_minute: 1140,
      gap_before_unpaid: false,
      setup_minutes: null,
      teardown_minutes: null,
      point: { lat: 38.96, lng: -77.36 },
      label: 'Office lunch',
      address: '',
      catering: { headcount: 80, price_per_head: 14, guarantee: null, food_cost: null },
    });
    for (const key of ['fee_flat', 'fee_pct', 'fee_min', 'point', 'label', 'event', 'catering']) {
      expect(key in planBody(saved()).stops[0], key).toBe(false);
    }
  });

  it('an empty day saves as a day without stops', () => {
    expect(planBody(emptyPlanDraft(DATE))).toEqual({ date: DATE, treat_as: null, notes: null, status: 'planned', stops: [] });
  });
});

describe('dirty detection', () => {
  it('a day as saved has nothing to save', () => {
    expect(draftChanged(saved(), plan)).toBe(false);
    expect(draftChanged({ ...saved(), dirty: true }, plan)).toBe(false);
  });

  it('every edit is a change, and undoing it by hand is none', () => {
    const base = saved();
    expect(draftChanged(moveStop(base, STOP_1, 1), plan)).toBe(true);
    expect(draftChanged(moveStop(moveStop(base, STOP_1, 1), STOP_1, -1), plan)).toBe(false);
    expect(draftChanged(patchStop(base, STOP_2, { open_minute: 1035 }), plan)).toBe(true);
    expect(draftChanged(patchStop(patchStop(base, STOP_2, { open_minute: 1035 }), STOP_2, { open_minute: 1020 }), plan)).toBe(false);
    expect(draftChanged(patchStop(base, STOP_2, { gap_before_unpaid: true }), plan)).toBe(true);
    expect(draftChanged(patchStop(base, STOP_1, { setup_minutes: 20 }), plan)).toBe(true);
    expect(draftChanged(patchStop(base, STOP_1, { spot_id: TAPROOM_ID }), plan)).toBe(true);
    expect(draftChanged(removeStop(base, STOP_2), plan)).toBe(true);
    expect(draftChanged(setTreatAs(base, 'sat'), plan)).toBe(true);
    expect(draftChanged(setTreatAs(setTreatAs(base, 'sat'), null), plan)).toBe(false);
  });

  it('a stop removed and added again is a change: the server would store a new stop', () => {
    const again = addStop(removeStop(saved(), STOP_2), newSpotStop(TAPROOM_ID, { open: 1020, close: 1200 }));
    expect(again.stops.map((stop) => [stop.spot_id, stop.open_minute, stop.close_minute])).toEqual(
      saved().stops.map((stop) => [stop.spot_id, stop.open_minute, stop.close_minute]),
    );
    expect(draftChanged(again, plan)).toBe(true);
  });

  it('a date without a saved day is changed as soon as it holds something', () => {
    const empty = emptyPlanDraft(DATE);
    expect(draftChanged(empty, null)).toBe(false);
    expect(draftChanged(addStop(empty, newSpotStop(OFFICE_ID, DEFAULT_WINDOW)), null)).toBe(true);
    expect(draftChanged(setTreatAs(empty, 'holiday'), null)).toBe(true);
    const cleared = removeStop(saved(), STOP_1);
    expect(draftChanged(removeStop(cleared, STOP_2), plan)).toBe(true);
  });

  it('names the save state', () => {
    expect(saveStateOf(null, false)).toBe('none');
    expect(saveStateOf(null, true)).toBe('new');
    expect(saveStateOf(plan, true)).toBe('unsaved');
    expect(saveStateOf(plan, false)).toBe('saved');
    expect(saveStateOf({ ...plan, status: 'draft' }, false)).toBe('draft');
    expect(saveStateOf({ ...plan, status: 'draft' }, true)).toBe('unsaved');
    expect(SAVE_STATE_TEXT.new).toBe('Not saved yet');
    expect(SAVE_STATE_TEXT.unsaved).toBe('Unsaved changes');
    expect(SAVE_STATE_TEXT.saved).toBe('All changes saved');
  });

  it('holds the save back while a stop closes before it opens', () => {
    const base = saved();
    expect(saveBlocker(base, evaluate(base))).toBeNull();
    expect(saveBlocker(base, null)).toBeNull();
    const backwards = patchStop(base, STOP_1, { close_minute: 600 });
    const result = evaluate(backwards);
    expect(result.warnings.map((w) => w.code)).toContain('invalid_window');
    expect(saveBlocker(backwards, result)).toBe('Fix the times first.');
    expect(saveBlocker(backwards, null)).toBe(SAVE_HINTS.times);
    expect(saveBlocker(patchStop(base, STOP_1, { close_minute: 660 }), null)).toBe(SAVE_HINTS.times);
    expect(saveBlocker(patchStop(base, STOP_2, { close_minute: 2890 }), null)).toBe(SAVE_HINTS.times);
    // Stops that overlap are the owner's to sort out: the server takes them and the model says so.
    const overlapping = patchStop(base, STOP_2, { open_minute: 780 });
    expect(evaluate(overlapping).warnings.map((w) => w.code)).toEqual(['stops_overlap']);
    expect(saveBlocker(overlapping, evaluate(overlapping))).toBeNull();
  });

  it('holds the save back while an event or a catering job is unfinished', () => {
    const event = newEventStop(A, { open: 600, close: 780 });
    const draft = addStop(emptyPlanDraft(DATE), event);
    expect(saveBlocker(draft, null)).toBe('Finish the event or catering details first.');
    const placed = patchStop(draft, event.id, { point: { lat: 38.9, lng: -77.3 } });
    expect(saveBlocker(placed, null)).toBe(SAVE_HINTS.details);
    const done = patchStop(placed, event.id, { event: { attendance: 500, vendors: 4, event_type: 'general' } });
    expect(saveBlocker(done, null)).toBeNull();

    const catering = newCateringStop({ open: 600, close: 780 });
    const job = patchStop(addStop(emptyPlanDraft(DATE), catering), catering.id, { point: { lat: 38.9, lng: -77.3 } });
    expect(saveBlocker(job, null)).toBe(SAVE_HINTS.details);
    const priced = patchStop(job, catering.id, { catering: { headcount: 50, price_per_head: null, guarantee: null, food_cost: null } });
    expect(saveBlocker(priced, null)).toBe(SAVE_HINTS.details);
    expect(saveBlocker(patchStop(job, catering.id, { catering: { headcount: 50, price_per_head: null, guarantee: 900, food_cost: null } }), null)).toBeNull();

    const noSpot = patchStop(saved(), STOP_1, { spot_id: null });
    expect(saveBlocker(noSpot, null)).toBe(SAVE_HINTS.spot);
  });
});

describe('names, places and the header', () => {
  it('names a stop by its spot, an event by its own name, and nothing by "Stop n"', () => {
    const event = { ...newEventStop(A, DEFAULT_WINDOW), label: '  Fall festival ' };
    const unnamed = newCateringStop(DEFAULT_WINDOW);
    const gone = newSpotStop('not-in-the-list', DEFAULT_WINDOW);
    expect(stopName(saved().stops[0], spotsById)).toBe('Herndon office park');
    expect(stopName(event, spotsById)).toBe('Fall festival');
    expect(stopName(unnamed, spotsById)).toBe('');
    expect(stopNames([...saved().stops, event, unnamed, gone], spotsById)).toEqual([
      'Herndon office park',
      'Sterling taproom',
      'Fall festival',
      'Stop 4',
      'Stop 5',
    ]);
    expect(KIND_LABELS).toEqual({ spot: 'Spot', event: 'Event', catering: 'Catering' });
  });

  it('says why a stop cannot be worked out yet', () => {
    expect(stopProblem(saved().stops[0], spotsById)).toBeNull();
    expect(stopProblem(newSpotStop('not-in-the-list', DEFAULT_WINDOW), spotsById)).toBe('spot_missing');
    const bare = indexSpots([{ ...officeSpot, vectors: null, vectors_state: 'none' }]);
    expect(stopProblem(saved().stops[0], bare)).toBe('no_estimate');
    const event = newEventStop(A, DEFAULT_WINDOW);
    expect(stopProblem(event, spotsById)).toBe('details_missing');
    expect(stopProblem({ ...event, point: { lat: 1, lng: 2 } }, spotsById)).toBe('details_missing');
    expect(stopProblem({ ...event, point: { lat: 1, lng: 2 }, event: { attendance: 10, vendors: 1, event_type: 'general' } }, spotsById)).toBeNull();
    const catering = { ...newCateringStop(DEFAULT_WINDOW), point: { lat: 1, lng: 2 } };
    expect(stopProblem(catering, spotsById)).toBe('details_missing');
    expect(stopProblem({ ...catering, catering: { headcount: 5, price_per_head: 20, guarantee: null, food_cost: null } }, spotsById)).toBeNull();
    // A deleted spot still evaluates.
    const deleted = indexSpots([{ ...officeSpot, archived: true }]);
    expect(stopProblem(saved().stops[0], deleted)).toBeNull();
  });

  it('gives the route and the calendar the stops that have a place', () => {
    const event = { ...newEventStop(A, { open: 1260, close: 1380 }), label: 'Fall festival', address: 'Lake Anne Plaza' };
    const stops = [...saved().stops, event, { ...event, id: 'n999', point: { lat: 38.95, lng: -77.35 } }];
    expect(routePoints(stops, spotsById)).toEqual([office.point, taproom.point, { lat: 38.95, lng: -77.35 }]);
    expect(calendarStops(stops, spotsById)).toEqual([
      { id: STOP_1, name: 'Herndon office park', address: 'Herndon office park address', point: office.point },
      { id: STOP_2, name: 'Sterling taproom', address: 'Sterling taproom address', point: taproom.point },
      { id: 'n999', name: 'Fall festival', address: 'Lake Anne Plaza', point: { lat: 38.95, lng: -77.35 } },
    ]);
  });

  it('the weather chip covers the first opening to the last closing', () => {
    expect(weatherHours([])).toEqual({ fromHour: 10, toHour: 20 });
    expect(weatherHours(saved().stops)).toEqual({ fromHour: 11, toHour: 20 });
    expect(weatherHours([{ open_minute: 690, close_minute: 815 }])).toEqual({ fromHour: 11, toHour: 14 });
    expect(weatherHours([{ open_minute: 1290, close_minute: 1500 }])).toEqual({ fromHour: 21, toHour: 24 });
    expect(weatherHours([{ open_minute: 1470, close_minute: 1560 }])).toEqual({ fromHour: 23, toHour: 24 });
    expect(weatherHours([{ open_minute: 1020, close_minute: 1200 }, { open_minute: 420, close_minute: 600 }])).toEqual({ fromHour: 7, toHour: 20 });
  });

  it('"Treat this day as" lists Automatic, a normal day, a holiday and the seven days', () => {
    expect(TREAT_AS_OPTIONS.map((option) => option.label)).toEqual([
      'Automatic',
      'A normal day (ignore the holiday)',
      'A holiday',
      'A Monday',
      'A Tuesday',
      'A Wednesday',
      'A Thursday',
      'A Friday',
      'A Saturday',
      'A Sunday',
    ]);
    expect(TREAT_AS_OPTIONS.map((option) => option.value)).toEqual([null, 'normal', 'holiday', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
    expect(treatAsOf('')).toBeNull();
    expect(treatAsOf('sat')).toBe('sat');
    expect(treatAsOf('holiday')).toBe('holiday');
    expect(treatAsOf('someday')).toBeNull();
  });

  it('"Treat this day as" changes the numbers at once', () => {
    const friday = buildContext(A, day, 'fri');
    const inputs = saved().stops.map((stop) => toStopInput(stop, spotsById));
    if (!stopsEvaluable(inputs)) throw new Error('the day must be evaluable');
    const asFriday = dayPlan(A, profile, toPlanInput(DATE, inputs), friday, ctxNext, toLegs(driveLegs()), null);
    // 02_MODEL 4.12: on a Friday the same taproom is worth 59 orders instead of 39.
    expect(fmtEstimate(evaluate(saved()).stops[1].orders, 'orders')).toBe('39 orders (21 to 62)');
    expect(asFriday.stops[1].orders.value).toBeCloseTo(59.08, 2);
  });
});
