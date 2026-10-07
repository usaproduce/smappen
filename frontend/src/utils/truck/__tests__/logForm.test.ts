// The Log's services tab (docs/truck-planner/05_FRONTEND.md 4.7 and 8.2).
//
// The fixtures come from the golden file, so what the quick entry shows here is checked against
// figures the model documents: the worked day of 02_MODEL 4.12 (g18-001) for a service logged from a
// planned stop, the taproom anchor A2 (g24-027) as a saved spot, and the seven services of
// 02_MODEL 4.13 (g19-001) for what the owner's own results change.

import { describe, expect, it } from 'vitest';
import type { Plan, ServiceLog, Spot } from '../../../api/truck';
import { fmtEstimate } from '../format';
import { calibrate, makeAssumptions, windowOrders } from '../model';
import type { DayResult, LocationVectors, ServiceLogEntry, SpotTerms, TruckProfile, DayContext, CalibrationState } from '../model';
import {
  CHOICE_CATERING,
  CHOICE_EVENT,
  ENTRY_TEXT,
  ESTIMATE_TEXT,
  HISTORY_TEXT,
  LOG_ENTRY_PARAMS,
  RESULT_TEXT,
  SERVICE_LIMITS,
  applyChoice,
  choiceOf,
  defaultHistoryFilter,
  draftFromPrefill,
  draftFromService,
  emptyServiceDraft,
  estimateLine,
  estimateRebuilt,
  fillFromStop,
  findPlannedStop,
  historyDate,
  historyRange,
  isUnsavedService,
  linkHolds,
  logHref,
  loggedEstimate,
  plannedStopEstimate,
  readLogParams,
  resultCardText,
  serviceBody,
  serviceName,
  servicePatch,
  serviceResult,
  serviceWhen,
  serviceWhere,
  spotChoices,
  spotNamesOf,
  storedEstimate,
  validateServiceDraft,
} from '../logForm';
import type { EstimateLineInput, ServiceDraft } from '../logForm';
import { confidenceLabel, numberRangeMessage } from '../wording';
import { clone, goldenCase, spec, specCount } from './_kitFixtures';

const TODAY = '2026-10-08';

// -------------------------------------------------------------------------------------------------
// Fixtures
// -------------------------------------------------------------------------------------------------

const a2 = goldenCase('g24-027'); // anchor A2: the taproom of 120, Thursday 5 PM to 8 PM
const A = makeAssumptions(a2.args.A.overrides, a2.args.A.region);
const profile: TruckProfile = a2.args.profile;
const taproomCtx: DayContext = a2.args.ctx;
const SEVEN: ServiceLogEntry[] = goldenCase('g19-001').args.services; // s1..s7 of 02_MODEL 4.13

function spotFixture(id: string, name: string, terms: SpotTerms, vectors: LocationVectors | null, extra: Partial<Spot> = {}): Spot {
  return {
    id,
    name,
    point: { lat: 39.0, lng: -77.4 },
    address: '',
    county_fips: '51107',
    notes: null,
    terms: { ...terms, spot_id: id },
    host_details: null,
    vectors: vectors === null ? null : { hidden: { ...vectors, visibility: 'hidden' }, normal: vectors, prominent: { ...vectors, visibility: 'prominent' } },
    vectors_state: vectors === null ? 'none' : 'fresh',
    logs: { count: 0, last_date: null },
    maps_url: 'https://www.google.com/maps/search/?api=1&query=39.000000%2C-77.400000',
    archived: false,
    created_at: '2026-10-04 23:50:12',
    updated_at: '2026-10-04 23:50:12',
    ...extra,
  };
}

// The taproom of the anchor, saved as spot B of the seven services.
const spotB = spotFixture('B', 'Sterling taproom', a2.args.terms, a2.args.vectors);
const spotWithout = spotFixture('N', 'New lot', a2.args.terms, null);

// The worked day as a saved plan: the office park 11 AM to 2 PM, then the taproom 5 PM to 8 PM.
const worked = goldenCase('g18-001');
const workedResult: DayResult = (() => {
  const result: DayResult = clone(worked.expected);
  result.stops[0].spot_id = 'spot-office';
  result.stops[1].spot_id = 'spot-taproom';
  return result;
})();

function planFixture(result: DayResult | null, extra: Partial<Plan> = {}): Plan {
  return {
    id: 'plan-1',
    date: '2026-10-08',
    name: '',
    treat_as: null,
    notes: null,
    status: 'planned',
    stops: [
      { id: 'office', kind: 'spot', spot_id: 'spot-office', label: '', point: null, address: '', open_minute: 660, close_minute: 840, gap_before_unpaid: false, setup_minutes: null, teardown_minutes: null, fee_flat: 0, fee_pct: 0, fee_min: 0, event: null, catering: null },
      { id: 'taproom', kind: 'spot', spot_id: 'spot-taproom', label: '', point: null, address: '', open_minute: 1020, close_minute: 1200, gap_before_unpaid: false, setup_minutes: null, teardown_minutes: null, fee_flat: 0, fee_pct: 0, fee_min: 0, event: null, catering: null },
      { id: 'fair', kind: 'event', spot_id: null, label: 'Fall fair', point: { lat: 39, lng: -77.4 }, address: '', open_minute: 600, close_minute: 900, gap_before_unpaid: false, setup_minutes: null, teardown_minutes: null, fee_flat: 0, fee_pct: 0.1, fee_min: 150, event: { attendance: 3000, vendors: 6, event_type: 'general' }, catering: null },
    ],
    result,
    context: null,
    result_state: result === null ? 'none' : 'fresh',
    evaluated_at: result === null ? null : '2026-10-07 21:10:00',
    maps_route_url: null,
    created_at: '2026-10-07 21:10:00',
    updated_at: '2026-10-07 21:10:00',
    ...extra,
  };
}

const PLANS = [planFixture(null)];

function serviceFixture(extra: Partial<ServiceLog> = {}): ServiceLog {
  return {
    id: '77aa0000-0000-4000-8000-000000000001',
    kind: 'spot',
    spot_id: 'B',
    plan_id: null,
    plan_stop_id: null,
    date: '2026-10-01',
    open_minute: 660,
    close_minute: 840,
    actual: 52,
    sales: 801.5,
    sold_out: false,
    notes: null,
    source: 'manual',
    external_key: null,
    treat_as: null,
    // the prediction of the example of 04_BACKEND 4.13
    prediction: { predicted_raw: 60.49, predicted: 60.49, low: 33.01, high: 93.19, confidence: 'rough', basis: 'log', model_version: 'tps-0.1.0', seeds_revision: 1, dataset_version: 'dc-20261003-3fa9c2d1', detail: {} },
    created_at: '2026-10-01 14:12:00',
    updated_at: '2026-10-01 14:12:00',
    ...extra,
  };
}

function params(query: string): (key: string) => string | null {
  const search = new URLSearchParams(query);
  return (key) => search.get(key);
}

function filled(extra: Partial<ServiceDraft> = {}): ServiceDraft {
  return { ...emptyServiceDraft(TODAY), kind: 'spot', spotId: 'B', open: 1020, close: 1200, actual: 41, ...extra };
}

// -------------------------------------------------------------------------------------------------
// 1. The address of the page
// -------------------------------------------------------------------------------------------------

describe('the address of the Log', () => {
  it('reads every parameter of 1.2', () => {
    const read = readLogParams(params('new=1&stop=taproom&spot=spot-taproom&date=2026-10-07&open=1020&close=1200'), TODAY);
    expect(read).toEqual({
      tab: 'services',
      wantsNew: true,
      prefill: { spotId: 'spot-taproom', date: '2026-10-07', open: 1020, close: 1200, stopId: 'taproom' },
    });
    expect(readLogParams(params('tab=accuracy'), TODAY)).toEqual({ tab: 'accuracy', wantsNew: false, prefill: null });
    expect(readLogParams(params(''), TODAY)).toEqual({ tab: 'services', wantsNew: false, prefill: null });
    expect(readLogParams(params('tab=nonsense'), TODAY).tab).toBe('services');
    expect(LOG_ENTRY_PARAMS).toEqual(['new', 'spot', 'date', 'open', 'close', 'stop']);
  });

  it('shows the services tab whenever a link starts an entry', () => {
    expect(readLogParams(params('tab=accuracy&new=1'), TODAY).tab).toBe('services');
    expect(readLogParams(params('tab=accuracy&spot=B'), TODAY).tab).toBe('services');
    // "Log a service" of a spot page and of the command palette
    expect(readLogParams(params('new=1&spot=B'), TODAY).prefill).toEqual({ spotId: 'B', date: null, open: null, close: null, stopId: null });
    expect(readLogParams(params('new=1'), TODAY)).toEqual({ tab: 'services', wantsNew: true, prefill: null });
  });

  it('counts a value that is not valid as absent', () => {
    const none = { spotId: null, date: null, open: null, close: null, stopId: null };
    // a date after today, a date that does not exist, a date in another spelling
    expect(readLogParams(params('date=2026-10-09'), TODAY).prefill).toBeNull();
    expect(readLogParams(params('date=2026-02-30'), TODAY).prefill).toBeNull();
    expect(readLogParams(params('date=10/08/2026'), TODAY).prefill).toBeNull();
    expect(readLogParams(params('date=2026-10-08'), TODAY).prefill).toEqual({ ...none, date: '2026-10-08' });
    // minutes outside the day and the day after it, and anything that is not a whole number
    expect(readLogParams(params('open=2881'), TODAY).prefill).toBeNull();
    expect(readLogParams(params('open=-5'), TODAY).prefill).toBeNull();
    expect(readLogParams(params('open=11am'), TODAY).prefill).toBeNull();
    expect(readLogParams(params('open=660.5'), TODAY).prefill).toBeNull();
    expect(readLogParams(params('open=0&close=2880'), TODAY).prefill).toEqual({ ...none, open: 0, close: 2880 });
    // a closing time that is not after the opening time
    expect(readLogParams(params('open=840&close=660'), TODAY).prefill).toEqual({ ...none, open: 840 });
    expect(readLogParams(params('open=840&close=840'), TODAY).prefill).toEqual({ ...none, open: 840 });
    // an id longer than the server takes, or empty
    expect(readLogParams(params('spot=' + 'x'.repeat(37)), TODAY).prefill).toBeNull();
    expect(readLogParams(params('spot=&stop=%20'), TODAY).prefill).toBeNull();
  });

  it('writes the link "Log it" of a planned stop so that it reads back', () => {
    const href = logHref({ stopId: 'taproom', spotId: 'spot-taproom', date: '2026-10-07', openMinute: 1290, closeMinute: 1500 });
    expect(href).toBe('/truck/log?new=1&stop=taproom&spot=spot-taproom&date=2026-10-07&open=1290&close=1500');
    expect(readLogParams(params(href.split('?')[1]), TODAY)).toEqual({
      tab: 'services',
      wantsNew: true,
      prefill: { spotId: 'spot-taproom', date: '2026-10-07', open: 1290, close: 1500, stopId: 'taproom' },
    });
    // an event or a catering job has no spot: the stop itself says what it is
    const event = logHref({ stopId: 'fair', spotId: null, date: '2026-10-08', openMinute: 600, closeMinute: 900 });
    expect(event).toBe('/truck/log?new=1&stop=fair&date=2026-10-08&open=600&close=900');
    expect(readLogParams(params(event.split('?')[1]), TODAY).prefill).toEqual({ spotId: null, date: '2026-10-08', open: 600, close: 900, stopId: 'fair' });
    expect(logHref({ stopId: 'a b&c', spotId: 'x/y', date: '2026-10-08', openMinute: 0, closeMinute: 60 })).toContain('stop=a%20b%26c&spot=x%2Fy');
  });
});

// -------------------------------------------------------------------------------------------------
// 2. The quick entry
// -------------------------------------------------------------------------------------------------

describe('the quick entry: its draft', () => {
  it('starts empty, on the given date', () => {
    expect(emptyServiceDraft(TODAY)).toEqual({
      kind: null,
      spotId: null,
      date: TODAY,
      open: null,
      close: null,
      actual: null,
      sales: null,
      soldOut: false,
      notes: '',
      planStopId: null,
    });
  });

  it('takes a saved spot, "An event" or "A catering job" from the Spot list', () => {
    const empty = emptyServiceDraft(TODAY);
    expect(choiceOf(empty)).toBe('');
    const atSpot = applyChoice(empty, 'B');
    expect([atSpot.kind, atSpot.spotId, choiceOf(atSpot)]).toEqual(['spot', 'B', 'B']);
    const event = applyChoice(atSpot, CHOICE_EVENT);
    expect([event.kind, event.spotId, choiceOf(event)]).toEqual(['event', null, CHOICE_EVENT]);
    const catering = applyChoice(event, CHOICE_CATERING);
    expect([catering.kind, catering.spotId, choiceOf(catering)]).toEqual(['catering', null, CHOICE_CATERING]);
    const cleared = applyChoice(catering, '');
    expect([cleared.kind, cleared.spotId]).toEqual([null, null]);
    // a spot kind without a spot is not a choice yet
    expect(choiceOf({ ...empty, kind: 'spot' })).toBe('');
  });

  it('offers the saved spots, and a deleted spot only to the entry that is already there', () => {
    const gone = spotFixture('G', 'Old market', a2.args.terms, a2.args.vectors, { archived: true });
    const list = [spotB, gone, spotWithout];
    expect(spotChoices(list, null)).toEqual([
      { id: 'B', label: 'Sterling taproom' },
      { id: 'N', label: 'New lot' },
    ]);
    expect(spotChoices(list, 'B')).toHaveLength(2);
    // a planned stop at a spot that was deleted since, or a service logged there, keeps its spot
    expect(spotChoices(list, 'G')).toEqual([
      { id: 'B', label: 'Sterling taproom' },
      { id: 'G', label: 'Old market (deleted)' },
      { id: 'N', label: 'New lot' },
    ]);
    expect(spotChoices([], 'G')).toEqual([]);
  });

  it('starts from what a link names', () => {
    const fromToday = draftFromPrefill({ spotId: 'spot-taproom', date: '2026-10-07', open: 1020, close: 1200, stopId: 'taproom' }, TODAY);
    expect(fromToday.draft).toEqual({ ...emptyServiceDraft('2026-10-07'), kind: 'spot', spotId: 'spot-taproom', open: 1020, close: 1200, planStopId: 'taproom' });
    expect(fromToday.fill).toBeNull(); // the link named everything
    const fromSpot = draftFromPrefill({ spotId: 'B', date: null, open: null, close: null, stopId: null }, TODAY);
    expect(fromSpot.draft).toEqual({ ...emptyServiceDraft(TODAY), kind: 'spot', spotId: 'B' });
    expect(fromSpot.fill).toBeNull();
    expect(draftFromPrefill(null, TODAY)).toEqual({ draft: emptyServiceDraft(TODAY), fill: null });
  });

  it('lets the planned stop fill what its link left open', () => {
    // "Log it" of a planned event: the link has no spot, so the kind comes with the plans
    const event = draftFromPrefill({ spotId: null, date: '2026-10-08', open: 600, close: 900, stopId: 'fair' }, TODAY);
    expect(event.draft.kind).toBeNull();
    expect(event.fill).toEqual({ stopId: 'fair', kind: true, date: false, open: false, close: false });
    const stop = findPlannedStop(PLANS, 'fair');
    expect(stop).toEqual({ planId: 'plan-1', date: '2026-10-08', treatAs: null, stopId: 'fair', kind: 'event', spotId: null, label: 'Fall fair', open: 600, close: 900 });
    if (stop === null || event.fill === null) throw new Error('fixture');
    // a logged service keeps no name: the event's own name starts the notes
    expect(fillFromStop(event.draft, event.fill, stop)).toEqual({ ...event.draft, kind: 'event', spotId: null, notes: 'Fall fair' });
    expect(fillFromStop({ ...event.draft, notes: 'Rained' }, event.fill, stop).notes).toBe('Rained');

    // a link that names the stop alone
    const bare = draftFromPrefill({ spotId: null, date: null, open: null, close: null, stopId: 'taproom' }, '2026-10-10');
    const taproom = findPlannedStop(PLANS, 'taproom');
    if (taproom === null || bare.fill === null) throw new Error('fixture');
    expect(fillFromStop(bare.draft, bare.fill, taproom)).toEqual({
      ...emptyServiceDraft('2026-10-08'),
      kind: 'spot',
      spotId: 'spot-taproom',
      open: 1020,
      close: 1200,
      planStopId: 'taproom',
    });
    // what the owner entered in the meantime stays
    const typed = { ...bare.draft, kind: 'spot' as const, spotId: 'B', open: 1035 };
    const kept = fillFromStop(typed, bare.fill, taproom);
    expect([kept.kind, kept.spotId, kept.open, kept.close]).toEqual(['spot', 'B', 1035, 1200]);
    // another stop, or an entry that has moved on, is left alone
    expect(fillFromStop({ ...bare.draft, planStopId: null }, bare.fill, taproom)).toEqual({ ...bare.draft, planStopId: null });
    expect(fillFromStop(bare.draft, bare.fill, stop)).toEqual(bare.draft);
  });

  it('finds a planned stop by its id', () => {
    expect(findPlannedStop(PLANS, 'office')).toMatchObject({ planId: 'plan-1', kind: 'spot', spotId: 'spot-office', open: 660, close: 840 });
    expect(findPlannedStop(PLANS, 'nowhere')).toBeNull();
    expect(findPlannedStop(PLANS, null)).toBeNull();
    expect(findPlannedStop([], 'office')).toBeNull();
    expect(findPlannedStop([planFixture(null, { treat_as: 'sat' })], 'office')?.treatAs).toBe('sat');
  });

  it('keeps the entry with its planned stop while it still describes that stop', () => {
    const stop = findPlannedStop(PLANS, 'taproom');
    const draft = filled({ spotId: 'spot-taproom', planStopId: 'taproom' });
    expect(linkHolds(draft, stop)).toBe(true);
    // the truck opened late and closed early: still that stop
    expect(linkHolds({ ...draft, open: 1050, close: 1170 }, stop)).toBe(true);
    // another spot, another date or another kind is a service of its own
    expect(linkHolds({ ...draft, spotId: 'B' }, stop)).toBe(false);
    expect(linkHolds({ ...draft, date: '2026-10-07' }, stop)).toBe(false);
    expect(linkHolds({ ...draft, kind: 'event', spotId: null }, stop)).toBe(false);
    // changed back: the link is there again
    expect(linkHolds({ ...{ ...draft, spotId: 'B' }, spotId: 'spot-taproom' }, stop)).toBe(true);
    // no stop, or the stop of another entry
    expect(linkHolds(draft, null)).toBe(false);
    expect(linkHolds({ ...draft, planStopId: null }, stop)).toBe(false);
    expect(linkHolds({ ...draft, planStopId: 'office' }, stop)).toBe(false);
    // an event stop has no spot to compare
    expect(linkHolds({ ...emptyServiceDraft(TODAY), kind: 'event', planStopId: 'fair' }, findPlannedStop(PLANS, 'fair'))).toBe(true);
  });
});

describe('the quick entry: its checks', () => {
  it('passes a complete entry', () => {
    expect(validateServiceDraft(filled(), TODAY)).toEqual({});
    expect(validateServiceDraft(filled({ actual: 0, sales: 0 }), TODAY)).toEqual({});
    expect(validateServiceDraft(filled({ actual: 5000, sales: 1000000, close: 2880 }), TODAY)).toEqual({});
    expect(validateServiceDraft({ ...filled(), kind: 'event', spotId: null }, TODAY)).toEqual({});
  });

  it('asks for what is missing', () => {
    expect(validateServiceDraft(emptyServiceDraft(TODAY), TODAY)).toEqual({
      spot: ENTRY_TEXT.chooseSpot,
      open: 'Required',
      close: 'Required',
      actual: 'Required',
    });
    expect(validateServiceDraft(filled({ spotId: null }), TODAY)).toEqual({ spot: ENTRY_TEXT.chooseSpot });
    // a spot the list does not offer (a link to a spot that was deleted) is not a choice
    expect(validateServiceDraft(filled(), TODAY, new Set(['A']))).toEqual({ spot: ENTRY_TEXT.chooseSpot });
    expect(validateServiceDraft(filled(), TODAY, new Set(['A', 'B']))).toEqual({});
  });

  it('refuses a value outside its range with the range, and never moves it', () => {
    const draft = filled({ actual: 5001, sales: 1000000.01 });
    expect(validateServiceDraft(draft, TODAY)).toEqual({
      actual: numberRangeMessage(0, 5000, true),
      sales: numberRangeMessage(0, 1000000),
    });
    expect(validateServiceDraft(draft, TODAY).actual).toBe('Enter a whole number from 0 to 5,000.');
    expect(draft.actual).toBe(5001);
    expect(validateServiceDraft(filled({ actual: -1 }), TODAY).actual).toBe('Enter a whole number from 0 to 5,000.');
    expect(validateServiceDraft(filled({ actual: 41.5 }), TODAY).actual).toBe('Enter a whole number from 0 to 5,000.');
    expect(validateServiceDraft(filled({ sales: -0.01 }), TODAY).sales).toBe('Enter a number from 0 to 1,000,000.');
    expect(validateServiceDraft(filled({ notes: 'x'.repeat(2001) }), TODAY)).toEqual({ notes: 'Use at most 2,000 characters.' });
    expect(validateServiceDraft(filled({ notes: ' ' + 'x'.repeat(2000) + ' ' }), TODAY)).toEqual({});
  });

  it('wants a date that is not after today and a closing time after the opening time', () => {
    expect(validateServiceDraft(filled({ date: '2026-10-09' }), TODAY)).toEqual({ date: ENTRY_TEXT.futureDate });
    expect(validateServiceDraft(filled({ date: 'yesterday' }), TODAY)).toEqual({ date: ENTRY_TEXT.pickDate });
    expect(validateServiceDraft(filled({ open: 1200, close: 1200 }), TODAY)).toEqual({ close: ENTRY_TEXT.closeAfterOpen });
    expect(validateServiceDraft(filled({ open: 1200, close: 1020 }), TODAY)).toEqual({ close: ENTRY_TEXT.closeAfterOpen });
    expect(validateServiceDraft(filled({ open: 1290, close: 1500 }), TODAY)).toEqual({}); // 9:30 PM to 1 AM
    expect(validateServiceDraft(filled({ close: 2881 }), TODAY).close).toBeDefined();
    expect(validateServiceDraft(filled({ open: -15 }), TODAY).open).toBeDefined();
  });
});

describe('the quick entry: what it sends', () => {
  it('builds the body of route 32', () => {
    // the example of 04_BACKEND 4.13
    const draft = filled({ spotId: 'b2f0', date: '2026-10-01', open: 660, close: 840, actual: 52, sales: 801.5 });
    expect(serviceBody(draft, false)).toEqual({
      kind: 'spot',
      spot_id: 'b2f0',
      date: '2026-10-01',
      open_minute: 660,
      close_minute: 840,
      actual: 52,
      sales: 801.5,
      sold_out: false,
    });
  });

  it('sends the plan stop id of a stop logged from a plan, and only while the entry describes it', () => {
    const stop = findPlannedStop(PLANS, 'taproom');
    const draft = filled({ spotId: 'spot-taproom', planStopId: 'taproom' });
    expect(serviceBody(draft, linkHolds(draft, stop)).plan_stop_id).toBe('taproom');
    const moved = { ...draft, spotId: 'B' };
    expect('plan_stop_id' in serviceBody(moved, linkHolds(moved, stop))).toBe(false);
    expect('plan_stop_id' in serviceBody(filled(), false)).toBe(false);
    // the server takes "treat this day as" from the plan: the entry never sends one
    expect('treat_as' in serviceBody(draft, true)).toBe(false);
  });

  it('stores the sold-out switch, whole cents and trimmed notes; an event carries no spot', () => {
    const body = serviceBody(filled({ soldOut: true, sales: 612.345, notes: '  Ran out of brisket at 7.  ' }), false);
    expect(body.sold_out).toBe(true);
    expect(body.sales).toBe(612.35);
    expect(body.notes).toBe('Ran out of brisket at 7.');
    const plain = serviceBody(filled({ notes: '   ' }), false);
    expect('sales' in plain).toBe(false);
    expect('notes' in plain).toBe(false);
    const event = serviceBody({ ...filled(), kind: 'event', spotId: null, planStopId: 'fair' }, true);
    expect(event.kind).toBe('event');
    expect('spot_id' in event).toBe(false);
    expect(event.plan_stop_id).toBe('fair');
    expect(serviceBody({ ...filled(), kind: 'catering', spotId: null }, false).kind).toBe('catering');
  });

  it('sends only what an edit changed', () => {
    const service = serviceFixture({ notes: 'Slow start' });
    const draft = draftFromService(service);
    expect(draft).toEqual({ kind: 'spot', spotId: 'B', date: '2026-10-01', open: 660, close: 840, actual: 52, sales: 801.5, soldOut: false, notes: 'Slow start', planStopId: null });
    expect(servicePatch(service, draft, false)).toEqual({});
    expect(servicePatch(service, { ...draft, actual: 54 }, false)).toEqual({ actual: 54 });
    expect(servicePatch(service, { ...draft, soldOut: true, notes: '' }, false)).toEqual({ sold_out: true, notes: null });
    expect(servicePatch(service, { ...draft, sales: null }, false)).toEqual({ sales: null });
    expect(servicePatch(service, { ...draft, sales: 801.499999 }, false)).toEqual({}); // the same whole cents
    expect(servicePatch(service, { ...draft, open: 675, close: 855, date: '2026-10-02' }, false)).toEqual({ open_minute: 675, close_minute: 855, date: '2026-10-02' });
    expect(servicePatch(service, { ...draft, spotId: 'A' }, false)).toEqual({ spot_id: 'A' });
    expect(servicePatch(service, { ...draft, kind: 'event', spotId: null }, false)).toEqual({ kind: 'event' });
    expect(servicePatch(serviceFixture({ kind: 'event', spot_id: null, prediction: null }), { ...draft }, false)).toEqual({ kind: 'spot', spot_id: 'B', notes: 'Slow start' });
  });

  it('takes the plan link away when an edit moves the service off its planned stop', () => {
    const service = serviceFixture({ plan_id: 'plan-1', plan_stop_id: 'taproom', spot_id: 'spot-taproom', date: '2026-10-08', open_minute: 1020, close_minute: 1200 });
    const draft = draftFromService(service);
    const stop = findPlannedStop(PLANS, 'taproom');
    expect(linkHolds(draft, stop)).toBe(true);
    expect(servicePatch(service, draft, false)).toEqual({});
    const moved = { ...draft, spotId: 'B' };
    expect(servicePatch(service, moved, !linkHolds(moved, stop))).toEqual({ spot_id: 'B', plan_stop_id: null });
    // a service that was never linked has no link to take away
    expect(servicePatch(serviceFixture(), draftFromService(serviceFixture()), true)).toEqual({});
  });

  it('knows which edits make the server work the estimate out again', () => {
    const service = serviceFixture();
    const draft = draftFromService(service);
    expect(estimateRebuilt(service, draft, false)).toBe(false);
    expect(estimateRebuilt(service, { ...draft, actual: 60, sales: 900, soldOut: true, notes: 'x' }, false)).toBe(false);
    expect(estimateRebuilt(service, { ...draft, open: 675 }, false)).toBe(true);
    expect(estimateRebuilt(service, { ...draft, close: 855 }, false)).toBe(true);
    expect(estimateRebuilt(service, { ...draft, date: '2026-09-30' }, false)).toBe(true);
    expect(estimateRebuilt(service, { ...draft, spotId: 'A' }, false)).toBe(true);
    expect(estimateRebuilt(service, { ...draft, kind: 'catering', spotId: null }, false)).toBe(true);
    expect(estimateRebuilt(serviceFixture({ plan_stop_id: 'taproom' }), draft, true)).toBe(true);
  });
});

// -------------------------------------------------------------------------------------------------
// 3. The estimate line
// -------------------------------------------------------------------------------------------------

describe('the estimate line: a stop logged from a plan (rule 1)', () => {
  const plan = planFixture(workedResult);
  const office = { stopId: 'office', kind: 'spot' as const, spotId: 'spot-office', date: '2026-10-08', open: 660, close: 840, treatAs: null };
  const taproom = { stopId: 'taproom', kind: 'spot' as const, spotId: 'spot-taproom', date: '2026-10-08', open: 1020, close: 1200, treatAs: null };

  it('shows the figure the saved plan showed when the hours are the planned ones', () => {
    const first = plannedStopEstimate(plan, office);
    const second = plannedStopEstimate(plan, taproom);
    expect(first).toEqual(workedResult.stops[0].orders);
    expect(spec(fmtEstimate(first, 'orders'))).toBe('60 orders (33 to 93)');
    expect(spec(fmtEstimate(second, 'orders'))).toBe('39 orders (21 to 62)');
    expect(spec(confidenceLabel(first === null ? 'fixed' : first.confidence))).toBe('Rough');
  });

  it('gives way to rule 2 when the service is not what the plan evaluated', () => {
    expect(plannedStopEstimate(plan, { ...office, open: 675 })).toBeNull(); // opened late
    expect(plannedStopEstimate(plan, { ...office, close: 825 })).toBeNull(); // closed early
    expect(plannedStopEstimate(plan, { ...office, spotId: 'spot-taproom' })).toBeNull(); // another spot
    expect(plannedStopEstimate(plan, { ...office, date: '2026-10-07' })).toBeNull(); // another date
    expect(plannedStopEstimate(plan, { ...office, treatAs: 'sat' })).toBeNull(); // another "treat this day as"
    expect(plannedStopEstimate(plan, { ...office, kind: 'event' })).toBeNull(); // another kind
    expect(plannedStopEstimate(plan, { ...office, stopId: 'added-later' })).toBeNull(); // not in the stored result
    expect(plannedStopEstimate(planFixture(null), office)).toBeNull(); // no stored result
    expect(plannedStopEstimate(null, office)).toBeNull();
    expect(plannedStopEstimate(undefined, office)).toBeNull();
    expect(plannedStopEstimate(planFixture(workedResult, { treat_as: 'sat' }), { ...office, treatAs: 'sat' })).toEqual(workedResult.stops[0].orders);
  });

  it('counts the hours from the time the truck could open', () => {
    const late: DayResult = clone(workedResult);
    late.timeline.stops[0].effective_open = 675; // arrived late: the stop opened at 11:15
    const latePlan = planFixture(late);
    expect(plannedStopEstimate(latePlan, { ...office, open: 675 })).toEqual(late.stops[0].orders);
    expect(plannedStopEstimate(latePlan, office)).toBeNull();
  });

  it('gives a planned event or catering job the figure of its stop, whatever its hours', () => {
    const withEvent: DayResult = clone(workedResult);
    withEvent.stops.push({ ...clone(workedResult.stops[1]), stop_index: 2, id: 'fair', kind: 'event', spot_id: null, orders: { value: 112, low: 40.6, high: 206.6, confidence: 'very_rough' } });
    const eventPlan = planFixture(withEvent);
    const entry = { stopId: 'fair', kind: 'event' as const, spotId: null, date: '2026-10-08', open: 615, close: 885, treatAs: null };
    expect(plannedStopEstimate(eventPlan, entry)).toEqual({ value: 112, low: 40.6, high: 206.6, confidence: 'very_rough' });
    expect(plannedStopEstimate(eventPlan, { ...entry, kind: 'catering' })).toBeNull();
  });
});

describe('the estimate line: every other service at a spot (rule 2)', () => {
  const base = { A, profile, spot: spotB, entries: SEVEN, date: '2026-10-04', open: 1020, close: 1200, ctx: taproomCtx, ctxNext: null };

  it('is the window estimate with the calibration of the services logged before the date', () => {
    const est = loggedEstimate(base);
    if (est === null) throw new Error('no estimate');
    // 02_MODEL 4.13: anchor A2 saved as spot B is 39.384 x 0.903705 = 35.592 orders (20.60 to 53.53), fair
    expect(spec(est.window.orders.value)).toBeCloseTo(35.592, 3);
    expect(spec(est.window.orders.low)).toBeCloseTo(20.6, 2);
    expect(spec(est.window.orders.high)).toBeCloseTo(53.53, 2);
    expect(spec(est.window.orders.confidence)).toBe('fair');
    expect(fmtEstimate(est.window.orders, 'orders')).toBe('36 orders (21 to 54)');
    expect(est.cal).toEqual(calibrate(A, SEVEN, '2026-10-04'));
    expect(est.terms).toEqual({ ...spotB.terms, spot_id: 'B' });
    expect(est.window).toEqual(windowOrders(A, profile, est.terms, a2.args.vectors, est.cal, taproomCtx, null, 1020, 1200));
  });

  it('is the anchor itself before any service is logged: the label moves only through logged services', () => {
    const before = loggedEstimate({ ...base, entries: [] });
    if (before === null) throw new Error('no estimate');
    expect(spec(fmtEstimate(before.window.orders, 'orders'))).toBe('39 orders (21 to 62)');
    expect(spec(confidenceLabel(before.window.orders.confidence))).toBe('Rough');
    const after = loggedEstimate(base);
    expect(confidenceLabel(after === null ? 'fixed' : after.window.orders.confidence)).toBe('Fair');
  });

  it('uses only the services logged before the date', () => {
    // on 2026-08-10 only s1, s2 and s3 are known
    const early = loggedEstimate({ ...base, date: '2026-08-10' });
    expect(early?.cal).toEqual(calibrate(A, SEVEN.slice(0, 3), '2026-08-10'));
    // a service of the date itself, or of a later date, changes nothing
    const more: ServiceLogEntry[] = [...SEVEN, { ...SEVEN[6], service_id: 's8', date: '2026-10-04', actual: 90 }, { ...SEVEN[6], service_id: 's9', date: '2026-10-05', actual: 5 }];
    expect(loggedEstimate({ ...base, entries: more })?.window.orders).toEqual(loggedEstimate(base)?.window.orders);
    // an earlier one does
    const earlier: ServiceLogEntry[] = [...SEVEN, { ...SEVEN[6], service_id: 's8', date: '2026-10-02', actual: 90 }];
    expect(loggedEstimate({ ...base, entries: earlier })?.window.orders.value).not.toBe(loggedEstimate(base)?.window.orders.value);
  });

  it('never counts the service that is being edited among its own entries', () => {
    // s7 is being moved from 2026-10-03 to 2026-10-04: its old entry would otherwise calibrate its new estimate
    const edited = loggedEstimate({ ...base, exceptServiceId: 's7' });
    expect(edited?.cal).toEqual(calibrate(A, SEVEN.slice(0, 6), '2026-10-04'));
    expect(edited?.window.orders.value).not.toBe(loggedEstimate(base)?.window.orders.value);
    expect(loggedEstimate({ ...base, exceptServiceId: 'not-one-of-them' })?.cal).toEqual(loggedEstimate(base)?.cal);
  });

  it('has no figure without stored vectors, for hours the model would refuse, or past midnight without the next day', () => {
    expect(loggedEstimate({ ...base, spot: spotWithout })).toBeNull();
    expect(loggedEstimate({ ...base, open: 1200, close: 1200 })).toBeNull();
    expect(loggedEstimate({ ...base, open: 1200, close: 1020 })).toBeNull();
    expect(loggedEstimate({ ...base, open: -30 })).toBeNull();
    expect(loggedEstimate({ ...base, close: 2895 })).toBeNull();
    expect(loggedEstimate({ ...base, date: 'tomorrow' })).toBeNull();
    expect(loggedEstimate({ ...base, open: 1290, close: 1500 })).toBeNull();
    expect(loggedEstimate({ ...base, open: 1290, close: 1500, ctxNext: taproomCtx })).not.toBeNull();
  });
});

describe('the estimate line: which rule applies', () => {
  const draft: ServiceDraft = filled({ date: '2026-10-04' });
  const input: EstimateLineInput = {
    A,
    profile,
    draft,
    original: null,
    link: 'none',
    plan: undefined,
    planFailed: false,
    treatAs: null,
    spot: spotB,
    entries: SEVEN,
    entriesFailed: false,
    ctx: taproomCtx,
    ctxNext: taproomCtx,
  };

  it('asks for the spot and the hours first', () => {
    const hint = { kind: 'hint', text: ESTIMATE_TEXT.hint };
    expect(estimateLine({ ...input, draft: emptyServiceDraft(TODAY) })).toEqual(hint);
    expect(estimateLine({ ...input, draft: { ...draft, close: null } })).toEqual(hint);
    expect(estimateLine({ ...input, draft: { ...draft, open: null } })).toEqual(hint);
    expect(estimateLine({ ...input, draft: { ...draft, spotId: null } })).toEqual(hint);
  });

  it('works a spot entry out in the browser and can explain it', () => {
    const line = estimateLine(input);
    if (line.kind !== 'estimate' || line.detail === null) throw new Error('no estimate');
    expect(fmtEstimate(line.estimate, 'orders')).toBe('36 orders (21 to 54)');
    expect(spec(line.help)).toBe('Uses only the services logged before this date.');
    expect(line.detail.window.orders).toBe(line.estimate);
    expect(line.detail.cal).toEqual(calibrate(A, SEVEN, '2026-10-04'));
  });

  it('says when there is none', () => {
    const none = { kind: 'none', text: spec(ESTIMATE_TEXT.none), help: null };
    expect(none.text).toBe('No estimate for this spot and time.');
    expect(estimateLine({ ...input, draft: { ...draft, open: 1200, close: 1020 } })).toEqual(none);
    expect(estimateLine({ ...input, spot: null })).toEqual(none);
    expect(estimateLine({ ...input, spot: spotWithout })).toEqual({ kind: 'none', text: ESTIMATE_TEXT.none, help: ESTIMATE_TEXT.noVectors });
    expect(estimateLine({ ...input, draft: { ...draft, kind: 'event', spotId: null } })).toEqual({ kind: 'none', text: ESTIMATE_TEXT.noneForService, help: ESTIMATE_TEXT.notPlanned });
    expect(estimateLine({ ...input, draft: { ...draft, kind: 'catering', spotId: null } })).toEqual({ kind: 'none', text: ESTIMATE_TEXT.noneForService, help: ESTIMATE_TEXT.notPlanned });
  });

  it('waits for what it needs, and says so when the logged services did not load', () => {
    expect(estimateLine({ ...input, spot: undefined })).toEqual({ kind: 'wait' });
    expect(estimateLine({ ...input, entries: undefined })).toEqual({ kind: 'wait' });
    expect(estimateLine({ ...input, entries: undefined, entriesFailed: true })).toEqual({ kind: 'failed', text: ESTIMATE_TEXT.failed });
    expect(estimateLine({ ...input, ctx: null })).toEqual({ kind: 'wait' });
    expect(estimateLine({ ...input, draft: { ...draft, open: 1290, close: 1500 }, ctxNext: null })).toEqual({ kind: 'wait' });
    expect(estimateLine({ ...input, ctxNext: null }).kind).toBe('estimate'); // the next day is not needed before midnight
    expect(estimateLine({ ...input, link: 'wait' })).toEqual({ kind: 'wait' });
  });

  it('takes the figure of the plan for a planned stop, and rule 2 when the plan does not apply', () => {
    const planned: ServiceDraft = filled({ spotId: 'spot-taproom', date: '2026-10-08', planStopId: 'taproom' });
    const taproomSpot = spotFixture('spot-taproom', 'Sterling taproom', a2.args.terms, a2.args.vectors);
    const linked: EstimateLineInput = { ...input, draft: planned, link: 'holds', plan: planFixture(workedResult), spot: taproomSpot, entries: [] };
    expect(estimateLine({ ...linked, plan: undefined })).toEqual({ kind: 'wait' });
    const line = estimateLine(linked);
    expect(line).toEqual({ kind: 'estimate', estimate: workedResult.stops[1].orders, help: ESTIMATE_TEXT.fromPlan, detail: null });
    // opened 15 minutes late: not what the plan evaluated, so the browser works it out
    const late = estimateLine({ ...linked, draft: { ...planned, open: 1035 } });
    if (late.kind !== 'estimate') throw new Error('no estimate');
    expect(late.help).toBe(ESTIMATE_TEXT.fromLog);
    expect(late.detail).not.toBeNull();
    // the plan has no stored result, or could not be loaded
    expect(estimateLine({ ...linked, plan: planFixture(null) })).toMatchObject({ kind: 'estimate', help: ESTIMATE_TEXT.fromLog });
    expect(estimateLine({ ...linked, plan: undefined, planFailed: true })).toMatchObject({ kind: 'estimate', help: ESTIMATE_TEXT.fromLog });
    // the same entry without its link is rule 2 as well, and gives the anchor
    const unlinked = estimateLine({ ...linked, link: 'none' });
    if (unlinked.kind !== 'estimate') throw new Error('no estimate');
    expect(fmtEstimate(unlinked.estimate, 'orders')).toBe('39 orders (21 to 62)');
  });

  it('keeps the stored estimate of a service whose spot, date and hours an edit leaves alone', () => {
    const service = serviceFixture();
    const same = draftFromService(service);
    expect(estimateLine({ ...input, original: service, draft: { ...same, actual: 70, soldOut: true } })).toEqual({
      kind: 'estimate',
      estimate: { value: 60.49, low: 33.01, high: 93.19, confidence: 'rough' },
      help: ESTIMATE_TEXT.kept,
      detail: null,
    });
    expect(estimateLine({ ...input, original: serviceFixture({ prediction: null }), draft: same })).toEqual({ kind: 'none', text: ESTIMATE_TEXT.noneKept, help: null });
    // new hours: the server will work it out again, so the line shows what it will keep
    const moved = estimateLine({ ...input, original: service, draft: { ...same, open: 1020, close: 1200 } });
    expect(moved).toMatchObject({ kind: 'estimate', help: ESTIMATE_TEXT.fromLog });
  });
});

describe('the stored estimate of a logged service', () => {
  it('is its prediction as the estimate it was', () => {
    expect(storedEstimate(serviceFixture())).toEqual({ value: 60.49, low: 33.01, high: 93.19, confidence: 'rough' });
    expect(storedEstimate(serviceFixture({ prediction: null }))).toBeNull();
  });
});

// -------------------------------------------------------------------------------------------------
// 4. Names and the result card
// -------------------------------------------------------------------------------------------------

describe('what a service is called', () => {
  const names = spotNamesOf([spotB, spotWithout]);

  it('is the name of its spot, or what kind of service it was', () => {
    expect(serviceName({ kind: 'spot', spot_id: 'B' }, names)).toBe('Sterling taproom');
    expect(serviceName({ kind: 'event', spot_id: null }, names)).toBe('Event');
    expect(serviceName({ kind: 'catering', spot_id: null }, names)).toBe('Catering job');
    expect(serviceName({ kind: 'spot', spot_id: 'gone' }, names)).toBe('A spot that is no longer listed');
    expect(serviceName({ kind: 'spot', spot_id: 'constructor' }, names)).toBe('A spot that is no longer listed');
    expect(serviceWhere({ kind: 'spot', spot_id: 'B' }, names)).toBe('Sterling taproom');
    expect(serviceWhere({ kind: 'event', spot_id: null }, names)).toBe('an event');
    expect(serviceWhere({ kind: 'event', spot_id: null }, names, 'Fall fair')).toBe('Fall fair');
    expect(serviceWhere({ kind: 'catering', spot_id: null }, names, '  ')).toBe('a catering job');
    expect(serviceWhere({ kind: 'spot', spot_id: null }, names)).toBe('a spot that is no longer listed');
  });
});

describe('the result card', () => {
  const calNow = { truck_factor: 0.97, spots: { B: { factor: 0.97, log_factor: -0.03, n: 1, weight: 0.98 } } } as unknown as CalibrationState;
  const calBefore = { truck_factor: 1, spots: {} } as unknown as CalibrationState;

  it('says how the day compared and what it changed', () => {
    // the answer of the example of 04_BACKEND 4.13: 52 orders against 60.49 (33.01 to 93.19)
    const text = resultCardText(serviceFixture(), calNow, calBefore, 'Sterling taproom');
    expect(text.headline).toBe('Logged 52 orders at Sterling taproom.');
    expect(fmtEstimate(text.estimate, 'orders')).toBe('60 orders (33 to 93)');
    expect(text.verdict).toBe('inside');
    expect(spec(text.verdictWords)).toBe('inside the range');
    expect(text.compared).toBe('8 fewer than the estimate.');
    expect(spec(text.factors)).toBe('Your results now adjust estimates: truck x0.97, this spot x0.97.');
    expect(text.before).toBe('Before this service: truck x1.00, this spot x1.00.');
  });

  it('words every side of the estimate', () => {
    const of = (actual: number) => resultCardText(serviceFixture({ actual }), calNow, null, 'B');
    expect([of(65).compared, of(65).verdictWords]).toEqual(['5 more than the estimate.', 'inside the range']);
    expect([of(60).compared, of(60).verdictWords]).toEqual([RESULT_TEXT.onTheEstimate, 'inside the range']);
    expect([of(120).compared, spec(of(120).verdictWords)]).toEqual(['60 more than the estimate.', 'above the range']);
    expect([of(20).compared, spec(of(20).verdictWords)]).toEqual(['40 fewer than the estimate.', 'below the range']);
    expect(of(1).headline).toBe('Logged 1 order at B.');
    expect(of(0).headline).toBe('Logged 0 orders at B.');
    expect(of(1250).headline).toBe('Logged 1,250 orders at B.');
    expect(of(65).before).toBeNull(); // nothing known from before
  });

  it('calls a sold-out count a minimum, never a measurement', () => {
    const text = resultCardText(serviceFixture({ actual: 45, sold_out: true }), calNow, calBefore, 'Sterling taproom');
    expect(text.verdict).toBe('sold_out');
    expect(spec(text.verdictWords)).toBe('sold out, counted as a minimum');
    expect(text.compared).toBe(RESULT_TEXT.soldOut);
    expect(text.compared).toContain('a minimum, not a measurement');
    // a sold-out count above the range is still a minimum, not "above the range"
    expect(resultCardText(serviceFixture({ actual: 200, sold_out: true }), calNow, null, 'B').verdictWords).toBe('sold out, counted as a minimum');
    expect(resultCardText(serviceFixture({ actual: 45, sold_out: true, prediction: null }), calNow, null, 'B').compared).toBe(RESULT_TEXT.soldOutNoEstimate);
  });

  it('says so when the factors did not move, and words an edit as a change', () => {
    expect(resultCardText(serviceFixture(), calNow, calNow, 'B').before).toBe(RESULT_TEXT.unchanged);
    expect(resultCardText(serviceFixture(), calNow, calBefore, 'B', true).before).toBe('Before this change: truck x1.00, this spot x1.00.');
    // a spot without a factor of its own is multiplied by 1
    expect(resultCardText(serviceFixture({ spot_id: 'N' }), calNow, null, 'New lot').factors).toBe('Your results now adjust estimates: truck x0.97, this spot x1.00.');
  });

  it('does not claim a change for a service that changes nothing', () => {
    const event = resultCardText(serviceFixture({ kind: 'event', spot_id: null, prediction: null }), calNow, calBefore, 'an event');
    expect(event.headline).toBe('Logged 52 orders at an event.');
    expect([event.estimate, event.verdict, event.compared, event.before]).toEqual([null, null, null, null]);
    expect(event.factors).toBe(RESULT_TEXT.notForRecords);
    const noEstimate = resultCardText(serviceFixture({ prediction: null }), calNow, calBefore, 'New lot');
    expect(noEstimate.factors).toBe(RESULT_TEXT.noEstimateKept);
    expect(noEstimate.before).toBeNull();
  });
});

// -------------------------------------------------------------------------------------------------
// 5. The history
// -------------------------------------------------------------------------------------------------

describe('the history', () => {
  it('writes the result of a service against its stored estimate', () => {
    expect(serviceResult(serviceFixture())).toBe('-8, inside the range');
    expect(spec(serviceResult(serviceFixture({ actual: 65 })))).toBe('+5, inside the range');
    expect(serviceResult(serviceFixture({ actual: 45, sold_out: true }))).toBe('-15, sold out, counted as a minimum');
    expect(serviceResult(serviceFixture({ prediction: null }))).toBe('—');
    expect(serviceResult(serviceFixture({ prediction: null, sold_out: true }))).toBe('sold out, counted as a minimum');
  });

  it('knows a row that is still on its way to the server', () => {
    expect(isUnsavedService({ id: 'new-1' })).toBe(true);
    expect(isUnsavedService({ id: 'new-27' })).toBe(true);
    expect(isUnsavedService({ id: '77aa0000-0000-4000-8000-000000000001' })).toBe(false);
  });

  it('orders by date and then by opening time', () => {
    const lunch = serviceWhen({ date: '2026-10-02', open_minute: 660 });
    const dinner = serviceWhen({ date: '2026-10-02', open_minute: 1020 });
    const nextDay = serviceWhen({ date: '2026-10-03', open_minute: 0 });
    const lateNight = serviceWhen({ date: '2026-10-02', open_minute: 2880 });
    expect(lunch < dinner && dinner < lateNight && lateNight < nextDay).toBe(true);
    expect(serviceWhen({ date: 'not a date', open_minute: 660 })).toBe(660);
  });

  it('prints the year of a date only when it is not this year', () => {
    expect(historyDate('2026-10-02', TODAY)).toBe('Fri, Oct 2');
    expect(historyDate('2025-12-31', TODAY)).toBe('Wed, Dec 31, 2025');
  });

  it('starts on the last 90 days, which is the request the server already defaults to', () => {
    const start = defaultHistoryFilter(TODAY);
    expect(start).toEqual({ spotId: '', from: '2026-07-10', to: '2026-10-08' });
    expect(historyRange(start, TODAY)).toEqual({ ok: true, range: { args: {}, isDefault: true } });
    expect(SERVICE_LIMITS.defaultDaysBack).toBe(90);
  });

  it('sends the dates and the spot of any other filter', () => {
    expect(historyRange({ spotId: 'B', from: '2026-07-10', to: '2026-10-08' }, TODAY)).toEqual({
      ok: true,
      range: { args: { from: '2026-07-10', to: '2026-10-08', spot_id: 'B' }, isDefault: false },
    });
    expect(historyRange({ spotId: '', from: '2026-01-01', to: '2026-03-31' }, TODAY)).toEqual({
      ok: true,
      range: { args: { from: '2026-01-01', to: '2026-03-31' }, isDefault: false },
    });
    expect(historyRange({ spotId: '', from: '2026-10-08', to: '2026-10-08' }, TODAY).ok).toBe(true);
  });

  it('holds at most 730 dates, both ends counted, and refuses a range the server would refuse', () => {
    // 2024-10-09 to 2026-10-08 is 730 dates; one day earlier is 731
    expect(historyRange({ spotId: '', from: '2024-10-09', to: '2026-10-08' }, TODAY).ok).toBe(true);
    expect(historyRange({ spotId: '', from: '2024-10-08', to: '2026-10-08' }, TODAY)).toEqual({ ok: false, errors: { from: HISTORY_TEXT.tooLong } });
    expect(historyRange({ spotId: '', from: '2026-10-08', to: '2026-10-07' }, TODAY)).toEqual({ ok: false, errors: { to: HISTORY_TEXT.order } });
    expect(historyRange({ spotId: '', from: '', to: '2026-10-07' }, TODAY)).toEqual({ ok: false, errors: { from: HISTORY_TEXT.pickDate } });
    expect(historyRange({ spotId: '', from: '2026-10-01', to: '' }, TODAY)).toEqual({ ok: false, errors: { to: HISTORY_TEXT.pickDate } });
    expect(historyRange({ spotId: '', from: '2026-10-01', to: '2026-10-09' }, TODAY)).toEqual({ ok: false, errors: { to: HISTORY_TEXT.futureDate } });
    expect(historyRange({ spotId: '', from: '2026-10-09', to: '2026-10-09' }, TODAY)).toEqual({
      ok: false,
      errors: { from: HISTORY_TEXT.futureDate, to: HISTORY_TEXT.futureDate },
    });
  });
});

describe('worked examples', () => {
  it('this file asserts 17 values the specification prints', () => {
    expect(specCount()).toBe(17);
  });
});
