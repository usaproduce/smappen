// Truck Planner - Today, Week and the map's date switch (docs/truck-planner/05_FRONTEND.md 4.1, 4.6
// and 8.2).
//
// The day used throughout is the worked day of 02_MODEL 4.12, which is also the blueprint's day
// sheet: Thursday 2026-10-08, the office park 11 AM to 2 PM, then the taproom 5 PM to 8 PM. Its
// inputs come from golden case g18-001 and go through the model's own `dayPlan`, so every sentence
// asserted here is worded from a timeline and from figures the model produced.

import { describe, expect, it } from 'vitest';
import type { FuelInfo } from '../../../api/truck';
import { holidayChipText } from '../../../components/truck/ui/kit';
import { fmtEstimate } from '../format';
import { addDays, dayContext, dayOfWeek, dayPlan, estSum, holidayOn } from '../model';
import type { Assumptions, DayContext, DayResult, HourForecast, LegInput, StopInput, Timeline, TruckProfile } from '../model';
import {
  NEXT_DONE,
  NEXT_NOTE,
  NEXT_TEXT,
  WEATHER_TEXT,
  chipHours,
  dateForHow,
  dateModeLine,
  dayIsDone,
  dayLengthLine,
  fuelLine,
  isEvaluated,
  isPlanned,
  latestLine,
  latestService,
  loggedLine,
  loggedOrders,
  mayStartTonight,
  mayStillRun,
  nextAction,
  nextNoteFor,
  nextUp,
  planOn,
  plannedCountText,
  plannedDates,
  stopLine,
  thingsToCheck,
  weatherEffect,
  weekMissingLine,
  weekSum,
  weekTitle,
  whenWord,
  type ServiceDay,
} from '../nextAction';
import { weekDates } from '../time';
import { assume, clone, goldenCase } from './_kitFixtures';

// -------------------------------------------------------------------------------------------------
// The worked day, and ways to bend it
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
const A = assume(worked.args.A);
const profile = worked.args.profile;
const legs = worked.args.legs;
const DATE = worked.args.plan.date;
const FUEL = 4.195;
const NAMES = ['Herndon office park', 'Sterling taproom'];

/** The context of a date as the server builds it: the model's own, with a fuel price and a forecast. */
function contextOf(date: string, forecast: (HourForecast | null)[] | null = null): DayContext {
  return dayContext(A, date, null, forecast, FUEL, 'eia');
}

/** Twenty-four forecast hours from one record per hour; `null` leaves an hour without a forecast. */
function forecastOf(at: (hour: number) => Omit<HourForecast, 'hour'> | null): (HourForecast | null)[] {
  const out: (HourForecast | null)[] = [];
  for (let hour = 0; hour < 24; hour++) {
    const record = at(hour);
    out.push(record === null ? null : { hour, ...record });
  }
  return out;
}

const MILD = { temp_f: 62, precip_prob: 0, short_forecast: 'Cloudy', wind_mph: 2 };
const COOL = { temp_f: 55, precip_prob: 0, short_forecast: 'Sunny', wind_mph: 5 };
const SHOWERS = { temp_f: 55, precip_prob: 40, short_forecast: 'Chance Rain Showers', wind_mph: 8 };

/** The worked day's stops on a date, optionally with other hours. */
function stopsOf(change?: (stops: StopInput[]) => void): StopInput[] {
  const stops = clone(worked.args.plan.stops);
  if (change !== undefined) change(stops);
  return stops;
}

function evaluate(date: string, stops: StopInput[], forecast: (HourForecast | null)[] | null = null, nextForecast: (HourForecast | null)[] | null = null): DayResult {
  return dayPlan(A, profile, { date, stops }, contextOf(date, forecast), contextOf(addDays(date, 1), nextForecast), legs, null);
}

const day = evaluate(DATE, stopsOf());
const timeline = day.timeline;

/** The same two stops with the taproom open from 10 PM to 1 AM: the day ends after midnight. */
const lateDay = evaluate(DATE, stopsOf((stops) => {
  stops[1].open_minute = 1320;
  stops[1].close_minute = 1500;
}));

/** A single stop that opens at 12:30 AM: prep starts on the evening before. */
const earlyDay = evaluate(DATE, [{ ...stopsOf()[0], open_minute: 30, close_minute: 210 }]);

const text = (t: Timeline, minute: number, names: readonly string[] = NAMES): string | null => {
  const action = nextAction(t, minute, names);
  return action === null ? null : action.text;
};

// -------------------------------------------------------------------------------------------------

describe('the next thing to do', () => {
  it('the worked day has the twelve clock times of the blueprint', () => {
    expect(timeline.events.map((e) => e.minute)).toEqual([574, 619, 630, 630, 660, 840, 860, 870, 990, 1020, 1200, 1220, 1221, 1251]);
  });

  it('names each kind of event, in the order of the day', () => {
    expect(text(timeline, 573)).toBe('Start prep at 9:34 AM');
    expect(text(timeline, 574)).toBe('Leave base by 10:19 AM');
    expect(text(timeline, 619)).toBe('Arrive at Herndon office park by 10:30 AM');
    expect(text(timeline, 630)).toBe('Open at 11:00 AM');
    expect(text(timeline, 660)).toBe('Serving until 2:00 PM');
    expect(text(timeline, 840)).toBe('Pack up and leave by 2:20 PM');
    expect(text(timeline, 860)).toBe('Arrive at Sterling taproom by 2:30 PM');
    expect(text(timeline, 870)).toBe('Start setting up at 4:30 PM');
    expect(text(timeline, 990)).toBe('Open at 5:00 PM');
    expect(text(timeline, 1020)).toBe('Serving until 8:00 PM');
    expect(text(timeline, 1200)).toBe('Pack up and leave by 8:20 PM');
    expect(text(timeline, 1220)).toBe('Back at base about 8:21 PM');
    expect(text(timeline, 1221)).toBe('Finish close-out by 8:51 PM');
  });

  it('every event kind of the model has its sentence, and each was reached above', () => {
    const kinds = new Set<string>();
    for (let minute = 500; minute < 1260; minute++) {
      const action = nextAction(timeline, minute, NAMES);
      if (action !== null) kinds.add(action.kind);
    }
    expect([...kinds].sort()).toEqual([...Object.keys(NEXT_TEXT), 'finished'].sort());
  });

  it('before the first event it is the start of prep, whenever the page is opened', () => {
    expect(text(timeline, 0)).toBe('Start prep at 9:34 AM');
    const first = nextAction(timeline, 0, NAMES);
    expect(first).toEqual({ kind: 'start_prep', minute: 574, stopIndex: null, text: 'Start prep at 9:34 AM' });
  });

  it('an event is the next one until its own minute, and not at it', () => {
    expect(nextAction(timeline, 659, NAMES)?.kind).toBe('open');
    expect(nextAction(timeline, 660, NAMES)?.kind).toBe('close');
  });

  it('events at one minute are one moment: arriving just in time is not also "Start setting up"', () => {
    // Stop 1 is reached at 10:30 AM and set up from 10:30 AM; stop 2 is reached at 2:30 PM and set up from 4:30 PM.
    expect(nextAction(timeline, 629, NAMES)).toMatchObject({ kind: 'arrive', stopIndex: 0 });
    expect(nextAction(timeline, 630, NAMES)).toMatchObject({ kind: 'open', stopIndex: 0 });
    expect(nextAction(timeline, 870, NAMES)).toMatchObject({ kind: 'setup_start', stopIndex: 1, minute: 990 });
  });

  it('after the last event the day is done', () => {
    expect(nextAction(timeline, 1251, NAMES)).toEqual({ kind: 'finished', minute: null, stopIndex: null, text: NEXT_DONE });
    expect(text(timeline, 1439)).toBe('Done for today.');
    expect(text(timeline, 2000)).toBe('Done for today.');
  });

  it('a day that ends after midnight keeps going past it', () => {
    expect(lateDay.timeline.done).toBeGreaterThan(1440);
    expect(lateDay.warnings.some((w) => w.code === 'ends_after_midnight')).toBe(true);
    // At 11 PM the closing time lies on the next civil day, and the sentence says so.
    expect(text(lateDay.timeline, 1380)).toBe('Serving until 1:00 AM (next day)');
    // At 12:10 AM "today" is that next day: the plan's minute is 1450 and the time needs no marker.
    expect(text(lateDay.timeline, 1450)).toBe('Serving until 1:00 AM');
    expect(text(lateDay.timeline, 1500)).toBe('Pack up and leave by 1:20 AM');
    const home = nextAction(lateDay.timeline, 1520, NAMES);
    expect(home?.kind).toBe('back_at_base');
    expect(text(lateDay.timeline, (lateDay.timeline.done as number) - 1)).toMatch(/^Finish close-out by 1:\d\d AM$/);
    expect(text(lateDay.timeline, lateDay.timeline.done as number)).toBe('Done for today.');
  });

  it('a day whose prep starts before midnight is read from the evening before', () => {
    const start = earlyDay.timeline.start_prep as number;
    expect(start).toBeLessThan(0);
    // 10 PM on the evening before is minute -120 of the plan's date: the time is this evening's.
    expect(text(earlyDay.timeline, -120, ['Night market'])).toMatch(/^Start prep at 11:\d\d PM$/);
    expect(text(earlyDay.timeline, start, ['Night market'])).toMatch(/^Leave base by 11:\d\d PM$/);
    // Once the date itself has begun the same events read as today's.
    expect(text(earlyDay.timeline, 5, ['Night market'])).toBe('Open at 12:30 AM');
  });

  it('a stop without a name reads "Stop 2", and a day without a timeline has no sentence', () => {
    expect(text(timeline, 860, ['Herndon office park'])).toBe('Arrive at Stop 2 by 2:30 PM');
    expect(text(timeline, 619, [])).toBe('Arrive at Stop 1 by 10:30 AM');
    const empty = evaluate(DATE, []);
    expect(empty.timeline.events).toEqual([]);
    expect(nextAction(empty.timeline, 600, [])).toBeNull();
  });

  it('says where the sentence comes from and what the app does not know', () => {
    expect(NEXT_NOTE).toBe('From your plan and the clock. Truck Planner does not know where the truck is.');
    expect(nextNoteFor('2026-10-07')).toBe('From your plan for Wed, Oct 7 and the clock. Truck Planner does not know where the truck is.');
  });
});

describe('the next thing to do across midnight', () => {
  const today: ServiceDay = { offset: 0, date: DATE, timeline, stopNames: NAMES };
  const lateYesterday: ServiceDay = { offset: -1, date: addDays(DATE, -1), timeline: lateDay.timeline, stopNames: NAMES };
  const earlyTomorrow: ServiceDay = { offset: 1, date: addDays(DATE, 1), timeline: earlyDay.timeline, stopNames: ['Night market'] };
  const plainTomorrow: ServiceDay = { offset: 1, date: addDays(DATE, 1), timeline, stopNames: NAMES };

  it('with only today it is the sentence of today', () => {
    expect(nextUp([today], 600)?.action.text).toBe('Leave base by 10:19 AM');
    expect(nextUp([today], 600)?.day.offset).toBe(0);
  });

  it("yesterday's late service is still the thing to do after midnight", () => {
    // 12:10 AM: yesterday's taproom serves until 1 AM; today's prep is at 9:34 AM.
    const up = nextUp([lateYesterday, today], 10);
    expect(up?.day.offset).toBe(-1);
    expect(up?.day.date).toBe('2026-10-07');
    expect(up?.action.text).toBe('Serving until 1:00 AM');
    // The order the days are given in does not matter.
    expect(nextUp([today, lateYesterday], 10)?.action.text).toBe('Serving until 1:00 AM');
  });

  it("once yesterday's day is done, today takes over", () => {
    const done = (lateDay.timeline.done as number) - 1440;
    expect(nextUp([lateYesterday, today], done - 1)?.day.offset).toBe(-1);
    const up = nextUp([lateYesterday, today], done);
    expect(up?.day.offset).toBe(0);
    expect(up?.action.text).toBe('Start prep at 9:34 AM');
  });

  it("with nothing today, yesterday's late service ends in no sentence at all", () => {
    expect(nextUp([lateYesterday], 10)?.action.text).toBe('Serving until 1:00 AM');
    expect(nextUp([lateYesterday], 600)).toBeNull();
  });

  it("tomorrow counts only when it starts tonight, and then in tonight's clock", () => {
    // An ordinary tomorrow never shows today: after today is done the page says so.
    expect(nextUp([today, plainTomorrow], 1300)?.action.text).toBe('Done for today.');
    expect(nextUp([plainTomorrow], 1300)).toBeNull();
    // Tomorrow's stop opens at 12:30 AM: its prep is tonight.
    const up = nextUp([today, earlyTomorrow], 1300);
    expect(up?.day.offset).toBe(1);
    expect(up?.action.text).toMatch(/^Start prep at 11:\d\d PM$/);
    // While today still has something earlier to do, that comes first.
    expect(nextUp([today, earlyTomorrow], 1210)?.action.text).toBe('Pack up and leave by 8:20 PM');
  });

  it('a day that has begun stays the thing to do when its next event is past midnight', () => {
    // Tomorrow's prep and departure are behind at 11:58 PM; arriving is at 12:00 AM or later.
    const leave = (earlyDay.timeline.leave_base as number) + 1440;
    const up = nextUp([earlyTomorrow], leave);
    expect(up?.day.offset).toBe(1);
    expect(up?.action.kind).toBe('arrive');
  });

  it('"Done for today." needs a day that had a timeline', () => {
    const none: ServiceDay = { offset: 0, date: DATE, timeline: evaluate(DATE, []).timeline, stopNames: [] };
    expect(nextUp([none], 600)).toBeNull();
    expect(nextUp([], 600)).toBeNull();
    expect(nextUp([today], 1300)).toEqual({ day: today, action: { kind: 'finished', minute: null, stopIndex: null, text: 'Done for today.' } });
  });

  it('only days that can matter are worked out', () => {
    // Yesterday closed at 8 PM: by 4 AM nothing of it can be left. A 1 AM close can run on until 9 AM.
    expect(mayStillRun([{ close_minute: 840 }, { close_minute: 1200 }], 0)).toBe(true);
    expect(mayStillRun([{ close_minute: 840 }, { close_minute: 1200 }], 240)).toBe(false);
    expect(mayStillRun([{ close_minute: 1500 }], 500)).toBe(true);
    expect(mayStillRun([{ close_minute: 1500 }], 540)).toBe(false);
    expect(mayStillRun([], 0)).toBe(false);
    // Tomorrow opens at 11 AM: nothing of it happens tonight. A 12:30 AM opening does.
    expect(mayStartTonight([{ open_minute: 660 }, { open_minute: 1020 }])).toBe(false);
    expect(mayStartTonight([{ open_minute: 30 }])).toBe(true);
    expect(mayStartTonight([{ open_minute: 240 }])).toBe(false);
    expect(mayStartTonight([])).toBe(false);
  });
});

describe('planned days', () => {
  const stop = { open_minute: 660, close_minute: 840 };
  const plans = [
    { date: '2026-10-05', status: 'planned' as const, stops: [stop] },
    { date: '2026-10-06', status: 'draft' as const, stops: [stop, stop] },
    { date: '2026-10-07', status: 'cancelled' as const, stops: [stop] },
    { date: '2026-10-08', status: 'planned' as const, stops: [] },
    { date: '2026-10-10', status: 'done' as const, stops: [stop] },
  ];

  it('a day is planned when its plan has a stop and was not cancelled', () => {
    expect(plans.map((p) => isPlanned(p as never))).toEqual([true, true, false, false, true]);
    expect(isPlanned(null)).toBe(false);
    expect(isPlanned(undefined)).toBe(false);
  });

  it('finds the plan of a date and counts the planned days of a week', () => {
    expect(planOn(plans, '2026-10-06')?.status).toBe('draft');
    expect(planOn(plans, '2026-10-09')).toBeNull();
    expect(planOn(undefined, '2026-10-06')).toBeNull();
    const week = weekDates('2026-10-05');
    expect(plannedDates(plans as never, week)).toEqual(['2026-10-05', '2026-10-06', '2026-10-10']);
    expect(plannedDates(undefined, week)).toEqual([]);
    expect(plannedCountText(3)).toBe('3 of 7 days planned');
    expect(plannedCountText(0)).toBe('0 of 7 days planned');
  });

  it('a day counts as evaluated only when the model gave every stop its figures', () => {
    expect(isEvaluated(day, 2)).toBe(true);
    expect(isEvaluated(null, 2)).toBe(false);
    // A day whose second stop opens before the first closes is not evaluated at all.
    const clash = evaluate(DATE, stopsOf((stops) => {
      stops[1].open_minute = 800;
    }));
    expect(clash.warnings.map((w) => w.code)).toEqual(['stops_overlap']);
    expect(isEvaluated(clash, 2)).toBe(false);
    expect(isEvaluated(evaluate(DATE, []), 0)).toBe(false);
  });
});

describe("today's plan in words", () => {
  it('the weather chip covers the plan, or 11 AM to 8 PM with nothing planned', () => {
    expect(chipHours([])).toEqual({ fromHour: 11, toHour: 20 });
    expect(chipHours([{ open_minute: 660, close_minute: 840 }, { open_minute: 1020, close_minute: 1200 }])).toEqual({ fromHour: 11, toHour: 20 });
    expect(chipHours([{ open_minute: 1020, close_minute: 1200 }])).toEqual({ fromHour: 17, toHour: 20 });
    expect(chipHours([{ open_minute: 1320, close_minute: 1500 }])).toEqual({ fromHour: 22, toHour: 24 });
  });

  it('a stop in one line, the length of the day, and what is left to check', () => {
    expect(stopLine('Herndon office park', { open_minute: 660, close_minute: 840 })).toBe('Herndon office park, 11 AM to 2 PM');
    expect(stopLine('Sterling taproom', { open_minute: 1320, close_minute: 1500 })).toBe('Sterling taproom, 10 PM to 1 AM (next day)');
    expect(dayLengthLine(timeline)).toBe('11 h 17 min, prep to done');
    expect(thingsToCheck(3)).toBe('3 things to check');
    expect(thingsToCheck(1)).toBe('1 thing to check');
    expect(thingsToCheck(0)).toBeNull();
  });

  it('the route is the thing to press until close-out is behind', () => {
    expect(dayIsDone(timeline, 1250)).toBe(false);
    expect(dayIsDone(timeline, 1251)).toBe(true);
    expect(dayIsDone(evaluate(DATE, []).timeline, 1300)).toBe(false);
  });

  it('the day is the one the planner shows', () => {
    expect(fmtEstimate(day.totals.take_home, 'money')).toBe('$482 ($42 to $1,012)');
    expect(fmtEstimate(day.stops[0].orders, 'orders')).toBe('60 orders (33 to 93)');
    expect(fmtEstimate(day.stops[1].orders, 'orders')).toBe('39 orders (21 to 62)');
  });
});

describe('what the forecast does to a stop', () => {
  it('without a forecast there is no adjustment, and the line says so', () => {
    expect(weatherEffect(day.stops[0])).toEqual({ kind: 'missing', text: WEATHER_TEXT.missing });
    expect(WEATHER_TEXT.missing).toBe('No forecast for these hours, so no weather adjustment.');
  });

  it('a mild, dry day lowers nothing', () => {
    const mild = evaluate(DATE, stopsOf(), forecastOf(() => MILD));
    expect(weatherEffect(mild.stops[0])).toEqual({ kind: 'none', text: 'Nothing in this forecast lowers the estimate.' });
    expect(weatherEffect(mild.stops[1]).kind).toBe('none');
    expect(mild.stops[0].orders.value).toBeCloseTo(day.stops[0].orders.value, 9);
  });

  it('a cool day takes the model\'s 10% off walk-up orders, and the orders follow', () => {
    const cool = evaluate(DATE, stopsOf(), forecastOf(() => COOL));
    expect(weatherEffect(cool.stops[0])).toEqual({ kind: 'lower', text: 'This forecast takes 10% off walk-up orders.' });
    expect(cool.stops[0].orders.value).toBeLessThan(day.stops[0].orders.value);
  });

  it('showers at 40% are the 26% of 02_MODEL 4.6', () => {
    const wet = evaluate(DATE, stopsOf(), forecastOf(() => SHOWERS));
    expect(wet.stops[0].window?.hours[0].result.factors.weather_open).toBeCloseTo(0.738, 9);
    expect(weatherEffect(wet.stops[0]).text).toBe('This forecast takes 26% off walk-up orders.');
  });

  it('hours that differ are given as the smallest and the largest cut', () => {
    const turning = evaluate(DATE, stopsOf(), forecastOf((hour) => (hour < 12 ? COOL : SHOWERS)));
    expect(weatherEffect(turning.stops[0]).text).toBe('This forecast takes 10% to 26% off walk-up orders, depending on the hour.');
    const clearing = evaluate(DATE, stopsOf(), forecastOf((hour) => (hour < 12 ? SHOWERS : MILD)));
    expect(weatherEffect(clearing.stops[0]).text).toBe('This forecast takes up to 26% off walk-up orders, depending on the hour.');
  });

  it('hours the forecast does not reach are counted, and the rest still speak', () => {
    // Today's forecast starts at the current hour: the hours before it carry none.
    const partly = evaluate(DATE, stopsOf(), forecastOf((hour) => (hour < 12 ? null : COOL)));
    expect(weatherEffect(partly.stops[0]).text).toBe('This forecast takes 10% off walk-up orders. No forecast for 1 of the 3 hours.');
    expect(partly.warnings.some((w) => w.code === 'no_forecast')).toBe(true);
    const none = evaluate(DATE, stopsOf(), forecastOf((hour) => (hour < 14 ? null : COOL)));
    expect(weatherEffect(none.stops[0])).toEqual({ kind: 'missing', text: WEATHER_TEXT.missing });
  });

  it('people inside a venue feel the weather less, and the line says so', () => {
    const wet = evaluate(DATE, stopsOf(), forecastOf(() => SHOWERS));
    const hour = wet.stops[1].window?.hours[0].result;
    expect(hour?.host?.mode).toBe('captive');
    expect(hour?.factors.weather_captive).toBeGreaterThan(hour?.factors.weather_open as number);
    expect(weatherEffect(wet.stops[1]).text).toBe(
      "This forecast takes 26% off walk-up orders. The host's people are inside the venue, so the weather counts less for them.",
    );
    // The office park has no host: nothing is said about one.
    expect(weatherEffect(wet.stops[0]).text).not.toContain('host');
  });

  it('an owner who raised a weather figure above 1 is told that it adds', () => {
    const warm = evaluate(DATE, stopsOf(), forecastOf(() => MILD));
    const stop = clone(warm.stops[0]);
    for (const h of stop.window?.hours ?? []) h.result.factors.weather_open = 1.05;
    expect(weatherEffect(stop)).toEqual({ kind: 'higher', text: 'This forecast adds 5% to walk-up orders.' });
    const hours = stop.window?.hours ?? [];
    hours[0].result.factors.weather_open = 0.9;
    expect(weatherEffect(stop).kind).toBe('mixed');
    expect(weatherEffect(stop).text).toBe('This forecast takes up to 10% off walk-up orders in some hours and adds up to 5% in others.');
  });

  it('an event is worded from its own hours, and a catering job not at all', () => {
    const base = stopsOf()[0];
    const event: StopInput = {
      ...base,
      id: 'fair',
      kind: 'event',
      spot_id: null,
      vectors: null,
      terms: { spot_id: null, visibility: 'normal', host: null, fee_flat: 0, fee_pct: 0.1, fee_min: 50, allowed: null },
      event: { attendance: 4000, vendors: 6, event_type: 'general' },
      catering: null,
    };
    const catering: StopInput = {
      ...base,
      id: 'lunch',
      kind: 'catering',
      spot_id: null,
      vectors: null,
      terms: null,
      event: null,
      catering: { headcount: 80, price_per_head: 14, guarantee: null, food_cost: null },
      open_minute: 1020,
      close_minute: 1140,
    };
    const wet = evaluate(DATE, [event, catering], forecastOf(() => SHOWERS));
    expect(wet.stops).toHaveLength(2);
    expect(wet.stops[0].event?.hours[0].weather).toBeCloseTo(0.738, 9);
    expect(weatherEffect(wet.stops[0])).toEqual({ kind: 'lower', text: "This forecast takes 26% off this event's orders." });
    expect(weatherEffect(wet.stops[1])).toEqual({ kind: 'contract', text: 'A catering job is contracted. The weather does not change it.' });
    expect(wet.stops[1].orders.confidence).toBe('fixed');
    const dry = evaluate(DATE, [event], forecastOf(() => MILD));
    expect(weatherEffect(dry.stops[0]).kind).toBe('none');
  });

  it('names its source as the page prints it', () => {
    expect(WEATHER_TEXT.source).toBe('Forecast for the area around your base. National Weather Service (weather.gov).');
  });
});

describe('log what happened', () => {
  const services = [
    { id: 'b', date: '2026-10-02', open_minute: 1020, actual: 79 },
    { id: 'a', date: '2026-10-05', open_minute: 660, actual: 61 },
    { id: 'c', date: '2026-10-05', open_minute: 1020, actual: 1 },
    { id: 'd', date: '2026-09-28', open_minute: 660, actual: 40 },
  ];

  it('the latest service is the one served last: by date, then by opening time', () => {
    expect(latestService(services)?.id).toBe('c');
    expect(latestService(services.slice(0, 2))?.id).toBe('a');
    expect(latestService([])).toBeNull();
    expect(latestService(undefined)).toBeNull();
    // Two entries for one moment: the smaller id, so the answer never depends on the order of the list.
    const twins = [
      { id: 'z', date: '2026-10-05', open_minute: 660, actual: 1 },
      { id: 'y', date: '2026-10-05', open_minute: 660, actual: 2 },
    ];
    expect(latestService(twins)?.id).toBe('y');
    expect(latestService([twins[1], twins[0]])?.id).toBe('y');
  });

  it('says when it was the way a person would', () => {
    const today = '2026-10-05';
    expect(whenWord('2026-10-05', today)).toBe('today');
    expect(whenWord('2026-10-04', today)).toBe('yesterday');
    expect(whenWord('2026-10-02', today)).toBe('Friday');
    expect(whenWord('2026-09-29', today)).toBe('Tuesday');
    // Seven days ago is the same weekday as today: the date is given instead.
    expect(whenWord('2026-09-28', today)).toBe('Mon, Sep 28');
    expect(whenWord('2026-08-01', today)).toBe('Sat, Aug 1');
  });

  it('prints the count plainly', () => {
    expect(latestLine('Sterling taproom', services[0], '2026-10-05')).toBe('Sterling taproom, Friday: 79 orders.');
    expect(latestLine('Herndon office park', services[1], '2026-10-05')).toBe('Herndon office park, today: 61 orders.');
    expect(latestLine('Event', services[2], '2026-10-06')).toBe('Event, yesterday: 1 order.');
  });
});

describe('the fuel price in use', () => {
  const fuel: FuelInfo = { price_per_gal: 4.195, source: 'eia', area: 'R1Z', product: 'EPMR', period: '2026-09-28' };

  it('names the product, the price to three decimals and the source', () => {
    expect(fuelLine(fuel)).toEqual({ label: 'Regular gasoline', value: '$4.195/gal', sub: 'EIA weekly average, week of Sep 28' });
    expect(fuelLine({ ...fuel, source: 'owner', price_per_gal: 3.9 })).toEqual({ label: 'Regular gasoline', value: '$3.900/gal', sub: 'Your price' });
    expect(fuelLine({ ...fuel, source: 'seed' }).sub).toBe('Default price from the week of Sep 28. No current figure.');
    expect(fuelLine({ ...fuel, product: 'EPD2D' }).label).toBe('Diesel');
  });

  it('a price without a week still says where it comes from', () => {
    expect(fuelLine({ ...fuel, period: null }).sub).toBe('EIA weekly average');
    expect(fuelLine({ ...fuel, source: 'seed', period: null }).sub).toBe('Default price. No current figure.');
  });
});

describe('the week', () => {
  // Monday 2026-10-05 to Sunday 2026-10-11, with the worked day on three dates.
  const thursday = day;
  const friday = evaluate('2026-10-09', stopsOf());
  const saturday = evaluate('2026-10-10', stopsOf());

  it('its title names the Monday', () => {
    expect(weekTitle('2026-10-05')).toBe('Week of Oct 5');
    expect(weekTitle('2026-12-28')).toBe('Week of Dec 28');
  });

  it("the total is the model's own sum of the day results, in date order", () => {
    const days = weekDates('2026-10-05').map((date) => {
      const result = date === '2026-10-08' ? thursday : date === '2026-10-09' ? friday : date === '2026-10-10' ? saturday : null;
      return { planned: result !== null, result };
    });
    const sum = weekSum(days);
    const expected = estSum([thursday.totals.take_home, friday.totals.take_home, saturday.totals.take_home]);
    expect(sum).toEqual({ total: expected, planned: 3, counted: 3 });
    // Lows add to lows and highs to highs: the week's range is as wide as its days' together.
    expect(sum.total?.low).toBe(thursday.totals.take_home.low + friday.totals.take_home.low + saturday.totals.take_home.low);
    expect(sum.total?.high).toBe(thursday.totals.take_home.high + friday.totals.take_home.high + saturday.totals.take_home.high);
    expect(sum.total?.confidence).toBe('rough');
    expect(weekMissingLine(sum)).toBeNull();
    // The Friday taproom is worth half as much again (02_MODEL 4.12), so the days are not three copies.
    expect(friday.stops[1].orders.value).toBeGreaterThan(thursday.stops[1].orders.value * 1.4);
  });

  it('one planned day is its own total', () => {
    const sum = weekSum([{ planned: true, result: thursday }, { planned: false, result: null }]);
    expect(fmtEstimate(sum.total, 'money')).toBe('$482 ($42 to $1,012)');
    expect(sum.total).toEqual(estSum([thursday.totals.take_home]));
  });

  it('a planned day without a figure is left out and counted as missing', () => {
    const sum = weekSum([
      { planned: true, result: thursday },
      { planned: true, result: null },
      { planned: true, result: null },
      { planned: false, result: null },
    ]);
    expect(sum.planned).toBe(3);
    expect(sum.counted).toBe(1);
    expect(weekMissingLine(sum)).toBe('2 planned days have no figures yet and are not in the total.');
    expect(weekMissingLine({ ...sum, counted: 2 })).toBe('1 planned day has no figures yet and is not in the total.');
  });

  it('a week with nothing planned has no total: it is never printed as $0', () => {
    expect(weekSum([{ planned: false, result: null }])).toEqual({ total: null, planned: 0, counted: 0 });
    expect(weekSum([])).toEqual({ total: null, planned: 0, counted: 0 });
    // A result handed in for a day that is not planned is not counted either.
    expect(weekSum([{ planned: false, result: thursday }]).total).toBeNull();
  });

  it('adds up what was logged on a day', () => {
    const services = [
      { date: '2026-10-05', actual: 61 },
      { date: '2026-10-05', actual: 70 },
      { date: '2026-10-02', actual: 1 },
    ];
    expect(loggedOrders(services, '2026-10-05')).toBe(131);
    expect(loggedOrders(services, '2026-10-06')).toBeNull();
    expect(loggedOrders(undefined, '2026-10-05')).toBeNull();
    expect(loggedLine(131)).toBe('Logged: 131 orders');
    expect(loggedLine(1)).toBe('Logged: 1 order');
    // A service with no orders was still logged.
    expect(loggedOrders([{ date: '2026-10-05', actual: 0 }], '2026-10-05')).toBe(0);
    expect(loggedLine(0)).toBe('Logged: 0 orders');
  });

  it('the holiday week of Columbus Day has its chip on the Monday alone', () => {
    const week = weekDates('2026-10-12');
    const chips = week.map((date) => holidayChipText(contextOf(date)));
    expect(chips).toEqual(['Columbus Day', null, null, null, null, null, null]);
    expect(holidayOn('2026-10-12', A.region.flags)?.id).toBe('columbus');
    // A minor holiday moves some of the model's sixteen groups to weekend patterns (02_MODEL 4.1):
    // the context of the Monday is not the context of the Monday after it, and the day says so.
    expect(contextOf('2026-10-12').day_type).not.toEqual(contextOf('2026-10-19').day_type);
    expect(evaluate('2026-10-12', stopsOf()).warnings.some((w) => w.code === 'holiday')).toBe(true);
    expect(evaluate('2026-10-19', stopsOf()).warnings.some((w) => w.code === 'holiday')).toBe(false);
    // A major holiday empties the offices: Thanksgiving is worth far less than the Thursday before it.
    const thanksgiving = evaluate('2026-11-26', stopsOf());
    const ordinary = evaluate('2026-11-19', stopsOf());
    expect(holidayChipText(contextOf('2026-11-26'))).toBe('Thanksgiving Day');
    expect(thanksgiving.stops[0].orders.value).toBeLessThan(ordinary.stops[0].orders.value * 0.5);
  });
});

describe('a picked date on the map', () => {
  it('each day of the week is one date of today and the six days after it', () => {
    const today = '2026-10-08'; // a Thursday
    const dates: string[] = [];
    for (let dow = 0; dow < 7; dow++) dates.push(dateForHow(today, dow * 24 + 12));
    expect(dates).toEqual(['2026-10-12', '2026-10-13', '2026-10-14', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11']);
    for (let dow = 0; dow < 7; dow++) expect(dayOfWeek(dates[dow])).toBe(dow);
    // The hour of the day plays no part, and any hour of the week wraps.
    expect(dateForHow(today, 3 * 24)).toBe(today);
    expect(dateForHow(today, 3 * 24 + 23)).toBe(today);
    expect(dateForHow(today, 168 + 3 * 24)).toBe(today);
  });

  it('says what the date changes: a holiday its pattern, any other day nothing, and never the weather', () => {
    expect(dateModeLine(A, '2026-10-12')).toBe('Columbus Day: the map follows the holiday pattern. No weather.');
    expect(dateModeLine(A, '2026-10-13')).toBe('An ordinary Tuesday: the same as the typical week. No weather.');
    expect(dateModeLine(A, '2026-11-26')).toBe('Thanksgiving Day: the map follows the holiday pattern. No weather.');
    // Independence Day 2026 falls on a Saturday and is observed on the Friday before.
    expect(dateModeLine(A, '2026-07-03')).toBe('Independence Day: the map follows the holiday pattern. No weather.');
    expect(dateModeLine(A, '2026-07-04')).toBe('An ordinary Saturday: the same as the typical week. No weather.');
  });
});
