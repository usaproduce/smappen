// Truck Planner estimator - day plan (02_MODEL 4.12).

import { MODEL_VERSION, max2 } from './core';
import { clockHours, vectorsMatch, windowOrders } from './demand';
import { estFixed, estLevels, estSum } from './estimate';
import { cateringMoney, eventOrders } from './events';
import { breakEvenOrders, dayCosts, stopMoney } from './money';
import { seed } from './seeds';
import { buildTimeline, emptyTimeline } from './timeline';
import { weatherMultiplier } from './weather';
import type {
  Assumptions,
  CalibrationState,
  CateringTerms,
  DayContext,
  DayResult,
  DayStop,
  DayStopEvent,
  DayTotals,
  Estimate,
  EvaluatedStop,
  EvaluateResult,
  EventTerms,
  LegMap,
  LocationVectors,
  PlanInput,
  SpotTerms,
  StopAdds,
  StopInput,
  StopMoney,
  TruckProfile,
  UnpaidGapAlternative,
  Warning,
  WindowResult,
} from './types';

/**
 * Internal: one whole day as planned. Timeline, each stop's orders and money, the day's costs and
 * totals. dayPlan calls it for the plan, for the plan without each stop and for the unpaid-gap
 * alternative; suggestDay calls it for every candidate plan. ctx.fuel_price_per_gal must be a number.
 */
export function evaluate(
  A: Assumptions,
  profile: TruckProfile,
  plan: PlanInput,
  ctx: DayContext,
  ctxNext: DayContext | null,
  legs: LegMap,
  cal: CalibrationState | null,
): EvaluateResult {
  const T = buildTimeline(A, profile, ctx, plan.stops, legs);
  const stops: EvaluatedStop[] = [];
  for (let i = 0; i < plan.stops.length; i++) {
    const s = plan.stops[i];
    const effectiveOpen = T.stops[i].effective_open;
    let served: WindowResult | null = null;
    let event: DayStopEvent | null = null;
    let orders: Estimate;
    let money: StopMoney;
    if (s.kind === 'spot') {
      served = windowOrders(
        A,
        profile,
        s.terms as SpotTerms,
        s.vectors as LocationVectors,
        cal,
        ctx,
        ctxNext,
        effectiveOpen,
        s.close_minute,
      );
      orders = served.orders;
      money = stopMoney(profile, s.terms as SpotTerms, orders);
    } else if (s.kind === 'event') {
      const E = eventOrders(A, profile, s.event as EventTerms, cal, ctx, ctxNext, effectiveOpen, s.close_minute);
      orders = E.orders;
      money = stopMoney(profile, s.terms as SpotTerms, orders);
      event = { buyers: E.buyers, demand: E.demand, hours: E.hours, spread: E.spread };
    } else {
      // catering
      money = cateringMoney(profile, s.catering as CateringTerms);
      orders = money.orders;
    }
    stops.push({
      stop_index: i,
      id: s.id,
      kind: s.kind,
      spot_id: s.spot_id == null ? null : s.spot_id,
      window: served,
      event,
      orders,
      money,
    });
  }

  const C = dayCosts(profile, T, ctx.fuel_price_per_gal as number);
  const ordersList: Estimate[] = [];
  const salesList: Estimate[] = [];
  const foodList: Estimate[] = [];
  const packagingList: Estimate[] = [];
  const cardList: Estimate[] = [];
  const feeList: Estimate[] = [];
  const tipsList: Estimate[] = [];
  const contributionList: Estimate[] = [];
  for (let i = 0; i < stops.length; i++) {
    ordersList.push(stops[i].orders);
    salesList.push(stops[i].money.sales);
    foodList.push(stops[i].money.food_cost);
    packagingList.push(stops[i].money.packaging);
    cardList.push(stops[i].money.card_fees);
    feeList.push(stops[i].money.spot_fee);
    tipsList.push(stops[i].money.tips);
    contributionList.push(stops[i].money.contribution);
  }
  const contribution = estSum(contributionList);
  const takeHome: Estimate = {
    value: contribution.value - C.total,
    low: contribution.low - C.total,
    high: contribution.high - C.total,
    confidence: contribution.confidence,
  };
  const workHours = (T.day_minutes - T.unpaid_gap_minutes) / 60.0;
  const perHour: Estimate = {
    value: workHours > 0 ? takeHome.value / workHours : 0.0,
    low: workHours > 0 ? takeHome.low / workHours : 0.0,
    high: workHours > 0 ? takeHome.high / workHours : 0.0,
    confidence: takeHome.confidence,
  };
  const totals: DayTotals = {
    orders: estSum(ordersList),
    sales: estSum(salesList),
    food_cost: estSum(foodList),
    packaging: estSum(packagingList),
    card_fees: estSum(cardList),
    spot_fees: estSum(feeList),
    tips: estSum(tipsList),
    contribution,
    labour: estFixed(C.labour),
    fuel: estFixed(C.fuel),
    tolls: estFixed(C.tolls),
    fixed_cost: estFixed(C.fixed),
    take_home: takeHome,
    take_home_per_hour: perHour,
    day_hours: T.day_minutes / 60.0,
    paid_hours: C.paid_hours,
    work_hours: workHours,
    unpaid_gap_hours: T.unpaid_gap_minutes / 60.0,
    drive_minutes: T.drive_minutes,
    miles: T.miles,
    drive_gallons: C.drive_gallons,
    generator_gallons: C.generator_gallons,
  };
  return {
    timeline: T,
    stops,
    costs: C,
    totals,
    take_home: takeHome,
    take_home_per_hour: perHour,
    work_hours: workHours,
  };
}

// How many clock hours of [open, close) have no usable forecast, each in the context of its own civil
// date. An hour whose context is typical is not counted.
function missingForecastHours(
  A: Assumptions,
  ctx: DayContext,
  ctxNext: DayContext | null,
  open: number,
  close: number,
): number {
  let count = 0;
  const loop = clockHours(open, close);
  for (let k = 0; k < loop.length; k++) {
    const cx = loop[k].day_index === 0 ? ctx : ctxNext;
    if (cx == null || cx.typical) continue;
    const record = cx.forecast != null ? cx.forecast[loop[k].hour] : null;
    if (weatherMultiplier(A, record == null ? null : record, 'open').missing) count += 1;
  }
  return count;
}

function zeroTotals(): DayTotals {
  return {
    orders: estFixed(0.0),
    sales: estFixed(0.0),
    food_cost: estFixed(0.0),
    packaging: estFixed(0.0),
    card_fees: estFixed(0.0),
    spot_fees: estFixed(0.0),
    tips: estFixed(0.0),
    contribution: estFixed(0.0),
    labour: estFixed(0.0),
    fuel: estFixed(0.0),
    tolls: estFixed(0.0),
    fixed_cost: estFixed(0.0),
    take_home: estFixed(0.0),
    take_home_per_hour: estFixed(0.0),
    day_hours: 0.0,
    paid_hours: 0.0,
    work_hours: 0.0,
    unpaid_gap_hours: 0.0,
    drive_minutes: 0,
    miles: 0.0,
    drive_gallons: 0.0,
    generator_gallons: 0.0,
  };
}

/**
 * Evaluate a day as the owner ordered it: timeline, each stop's orders and money, what each stop adds,
 * the day's costs and take-home, the unpaid-gap alternative and the warnings (in the fixed order of
 * 02_MODEL 4.12, stops in index order within a code). A plan with an invalid window or overlapping
 * stops is not evaluated: empty timeline, no stops, zero totals and only those warnings.
 */
export function dayPlan(
  A: Assumptions,
  profile: TruckProfile,
  plan: PlanInput,
  ctx: DayContext,
  ctxNext: DayContext | null,
  legs: LegMap,
  cal: CalibrationState | null,
): DayResult {
  const stopsIn = plan.stops;
  const n = stopsIn.length;

  // A plan with an invalid window or overlapping stops is not evaluated.
  const blocking: Warning[] = [];
  for (let i = 0; i < n; i++) {
    const s = stopsIn[i];
    if (s.open_minute < 0 || s.close_minute > 2880 || s.close_minute <= s.open_minute) {
      blocking.push({
        code: 'invalid_window',
        level: 'error',
        stop_index: i,
        data: { open_minute: s.open_minute, close_minute: s.close_minute },
      });
    }
  }
  for (let i = 1; i < n; i++) {
    const s = stopsIn[i];
    if (s.open_minute < stopsIn[i - 1].close_minute) {
      blocking.push({
        code: 'stops_overlap',
        level: 'error',
        stop_index: i,
        data: { open_minute: s.open_minute, previous_close_minute: stopsIn[i - 1].close_minute },
      });
    }
  }
  if (blocking.length > 0) {
    return {
      model_version: MODEL_VERSION,
      seeds_revision: A.seeds_revision,
      date: plan.date,
      timeline: emptyTimeline(),
      stops: [],
      totals: zeroTotals(),
      unpaid_gap_alternative: null,
      warnings: blocking,
    };
  }

  const R = evaluate(A, profile, plan, ctx, ctxNext, legs, cal);
  const T = R.timeline;

  // What each stop adds: the whole day with the stop minus the whole day without it.
  const dayStops: DayStop[] = [];
  for (let i = 0; i < n; i++) {
    const s = stopsIn[i];
    const without: PlanInput = { date: plan.date, stops: stopsIn.slice(0, i).concat(stopsIn.slice(i + 1)) };
    const Ri = evaluate(A, profile, without, ctx, ctxNext, legs, cal);
    const addTakeHome = estLevels(
      R.take_home.value - Ri.take_home.value,
      R.take_home.low - Ri.take_home.low,
      R.take_home.high - Ri.take_home.high,
      R.stops[i].orders.confidence,
    );
    const addHours = R.work_hours - Ri.work_hours;
    let addPerHour: Estimate | null = null;
    if (addHours > 0) {
      addPerHour = estLevels(
        addTakeHome.value / addHours,
        addTakeHome.low / addHours,
        addTakeHome.high / addHours,
        addTakeHome.confidence,
      );
    }
    const addedCosts = R.costs.total - Ri.costs.total;
    let breakEven: number | null = null;
    if (s.kind === 'spot' || s.kind === 'event') {
      breakEven = breakEvenOrders(profile, s.terms as SpotTerms, addedCosts);
    }
    let usesFallback = false;
    for (let k = 0; k < Ri.timeline.legs.length; k++) {
      if (Ri.timeline.legs[k].source === 'fallback') usesFallback = true;
    }
    const adds: StopAdds = {
      take_home: addTakeHome,
      hours: addHours,
      per_hour: addPerHour,
      added_costs: addedCosts,
      break_even_orders: breakEven,
      uses_fallback_leg: usesFallback,
    };
    const st = R.stops[i];
    dayStops.push({
      stop_index: st.stop_index,
      id: st.id,
      kind: st.kind,
      spot_id: st.spot_id,
      window: st.window,
      event: st.event,
      orders: st.orders,
      money: st.money,
      adds,
    });
  }

  // What the day would clear if every paid gap were an unpaid break.
  let alternative: UnpaidGapAlternative | null = null;
  let hasPaidGap = false;
  for (let k = 0; k < T.stops.length; k++) {
    const ts = T.stops[k];
    if (ts.stop_index >= 1 && ts.gap_before_minutes > 0 && !ts.gap_unpaid) hasPaidGap = true;
  }
  if (hasPaidGap) {
    const unpaidStops: StopInput[] = [];
    for (let i = 0; i < n; i++) unpaidStops.push({ ...stopsIn[i], gap_before_unpaid: true });
    const Ru = evaluate(A, profile, { date: plan.date, stops: unpaidStops }, ctx, ctxNext, legs, cal);
    alternative = {
      take_home: Ru.take_home,
      take_home_per_hour: Ru.take_home_per_hour,
      work_hours: Ru.work_hours,
      labour_saved: R.costs.labour - Ru.costs.labour,
    };
  }

  // Warnings, in the order of the table in 4.12; stops in index order within a code.
  const warnings: Warning[] = [];
  if (n > 0) {
    for (let i = 0; i < n; i++) {
      const ts = T.stops[i];
      if (ts.effective_open >= ts.close) {
        warnings.push({
          code: 'stop_unreachable',
          level: 'error',
          stop_index: i,
          data: { arrive: ts.arrive, effective_open: ts.effective_open, close_minute: ts.close },
        });
      }
    }
    for (let i = 0; i < n; i++) {
      const ts = T.stops[i];
      if (ts.late_minutes > 0 && !(ts.effective_open >= ts.close)) {
        warnings.push({
          code: 'late_arrival',
          level: 'warn',
          stop_index: i,
          data: { late_minutes: ts.late_minutes, effective_open: ts.effective_open },
        });
      }
    }
    for (let i = 0; i < n; i++) {
      const s = stopsIn[i];
      if (s.kind === 'spot' && !(s.vectors as LocationVectors).in_region) {
        warnings.push({ code: 'outside_region', level: 'warn', stop_index: i, data: {} });
      }
    }
    for (let i = 0; i < n; i++) {
      const s = stopsIn[i];
      if (s.kind === 'spot' && !vectorsMatch(A, s.terms as SpotTerms, s.vectors as LocationVectors)) {
        warnings.push({ code: 'stale_vectors', level: 'error', stop_index: i, data: {} });
      }
    }
    for (let i = 0; i < n; i++) {
      const s = stopsIn[i];
      if (s.kind === 'spot' && (s.terms as SpotTerms).allowed != null) {
        const allowed = (s.terms as SpotTerms).allowed!;
        if (!allowed.days[ctx.dow] || s.open_minute < allowed.open_minute || s.close_minute > allowed.close_minute) {
          warnings.push({
            code: 'outside_allowed_hours',
            level: 'warn',
            stop_index: i,
            data: { dow: ctx.dow, open_minute: s.open_minute, close_minute: s.close_minute },
          });
        }
      }
    }
    const fallbackKeys: string[] = [];
    for (let k = 0; k < T.legs.length; k++) {
      const leg = T.legs[k];
      if (leg.source === 'fallback') fallbackKeys.push(leg.from_id + '>' + leg.to_id);
    }
    if (fallbackKeys.length > 0) {
      warnings.push({ code: 'fallback_drive_time', level: 'warn', stop_index: null, data: { legs: fallbackKeys } });
    }
    for (let i = 0; i < n; i++) {
      const ts = T.stops[i];
      if (ts.gap_before_minutes >= seed<number>(A, 'timeline.long_gap_minutes') && !ts.gap_unpaid) {
        warnings.push({
          code: 'long_gap',
          level: 'warn',
          stop_index: i,
          data: { gap_before_minutes: ts.gap_before_minutes },
        });
      }
    }
    if (T.day_minutes > seed<number>(A, 'timeline.long_day_minutes')) {
      warnings.push({ code: 'long_day', level: 'warn', stop_index: null, data: { day_minutes: T.day_minutes } });
    }
    for (let i = 0; i < n; i++) {
      const money = dayStops[i].money;
      if (
        money.sales.value > 0 &&
        money.spot_fee.value > seed<number>(A, 'money.fee_warn_share') * money.sales.value
      ) {
        warnings.push({
          code: 'fee_high',
          level: 'warn',
          stop_index: i,
          data: { spot_fee: money.spot_fee.value, sales: money.sales.value },
        });
      }
    }
    for (let i = 0; i < n; i++) {
      const add = dayStops[i].adds.take_home;
      if (add.value < 0) {
        warnings.push({ code: 'below_break_even', level: 'warn', stop_index: i, data: { take_home: add.value } });
      }
    }
    for (let i = 0; i < n; i++) {
      const s = stopsIn[i];
      if (s.kind === 'event') {
        const ev = s.event as EventTerms;
        const perVendor = (ev.attendance * seed<number>(A, 'events.attendance_haircut')) / max2(1, ev.vendors);
        if (perVendor < seed<number>(A, 'events.min_attendees_per_vendor')) {
          warnings.push({
            code: 'event_thin_crowd',
            level: 'warn',
            stop_index: i,
            data: { attendees_per_vendor: perVendor },
          });
        }
      }
    }
    for (let i = 0; i < n; i++) {
      const add = dayStops[i].adds.take_home;
      if (add.value >= 0 && add.low < 0) {
        warnings.push({ code: 'weak_day_loss', level: 'info', stop_index: i, data: { take_home_low: add.low } });
      }
    }
    for (let i = 0; i < n; i++) {
      const st = dayStops[i];
      let cappedHours = 0;
      if (st.kind === 'spot') {
        cappedHours = (st.window as WindowResult).capped_hours;
      } else if (st.kind === 'event') {
        const hours = (st.event as DayStopEvent).hours;
        for (let k = 0; k < hours.length; k++) {
          if (hours[k].demand > hours[k].capacity) cappedHours += 1;
        }
      }
      if (cappedHours > 0) {
        warnings.push({ code: 'capacity_bound', level: 'info', stop_index: i, data: { capped_hours: cappedHours } });
      }
    }
    const startPrep = T.start_prep as number;
    if (startPrep < seed<number>(A, 'timeline.early_start_minute')) {
      warnings.push({ code: 'early_start', level: 'info', stop_index: null, data: { start_prep: startPrep } });
    }
    const done = T.done as number;
    if (done > 1440) {
      warnings.push({ code: 'ends_after_midnight', level: 'info', stop_index: null, data: { done } });
    }
    if (!ctx.typical) {
      let missing = 0;
      for (let i = 0; i < n; i++) {
        const s = stopsIn[i];
        if (s.kind === 'spot' || s.kind === 'event') {
          missing += missingForecastHours(A, ctx, ctxNext, T.stops[i].effective_open, s.close_minute);
        }
      }
      if (missing > 0) {
        warnings.push({ code: 'no_forecast', level: 'info', stop_index: null, data: { hours: missing } });
      }
    }
    if (ctx.holiday_class != null) {
      warnings.push({
        code: 'holiday',
        level: 'info',
        stop_index: null,
        data: { holiday_id: ctx.holiday != null ? ctx.holiday.id : null, holiday_class: ctx.holiday_class },
      });
    }
    for (let i = 0; i < n; i++) {
      const st = dayStops[i];
      if (st.kind === 'spot' && (st.window as WindowResult).evidence.weak_share >= 0.5) {
        warnings.push({
          code: 'weak_seed',
          level: 'info',
          stop_index: i,
          data: { weak_share: (st.window as WindowResult).evidence.weak_share },
        });
      }
    }
    for (let i = 0; i < n; i++) {
      const s = stopsIn[i];
      const st = dayStops[i];
      if (s.kind === 'spot') {
        const host = (s.terms as SpotTerms).host;
        if (host != null && host.size_source === 'default' && (st.window as WindowResult).host_orders > 0) {
          warnings.push({ code: 'default_host_size', level: 'info', stop_index: i, data: { size: host.size } });
        }
      }
    }
  }

  return {
    model_version: MODEL_VERSION,
    seeds_revision: A.seeds_revision,
    date: plan.date,
    timeline: T,
    stops: dayStops,
    totals: R.totals,
    unpaid_gap_alternative: alternative,
    warnings,
  };
}
