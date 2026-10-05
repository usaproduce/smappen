// Truck Planner estimator - events and catering (02_MODEL 4.14).

import { ModelError, max2, min2 } from './core';
import { clockHours } from './demand';
import { estFixed } from './estimate';
import { evidenceFrom, intervalCapped } from './ranges';
import { seed } from './seeds';
import { weatherMultiplier } from './weather';
import type {
  Assumptions,
  CalibrationState,
  CateringTerms,
  DayContext,
  EventHour,
  EventResult,
  EventTerms,
  StopMoney,
  TruckProfile,
} from './types';

/**
 * An attendance-based estimate. It replaces the map-based one entirely: the crowd is the organiser's,
 * not the neighbourhood's. Demand is spread evenly over the window and capped hour by hour; menu fit
 * is not applied; vendors counts every food vendor including this truck. Raises invalid_window and
 * missing_context like windowOrders.
 */
export function eventOrders(
  A: Assumptions,
  profile: TruckProfile,
  ev: EventTerms,
  cal: CalibrationState | null,
  ctx: DayContext,
  ctxNext: DayContext | null,
  open: number,
  close: number,
): EventResult {
  if (!(0 <= open && open <= close && close <= 2880)) throw new ModelError('invalid_window');
  const buyers =
    ev.attendance * seed<number>(A, 'events.attendance_haircut') * seed<number>(A, 'events.p_buy.' + ev.event_type);
  const demand = (buyers / max2(1, ev.vendors)) * (cal != null ? cal.truck_factor : 1.0);
  const minutes = close - open;
  const hours: EventHour[] = [];
  const d: number[] = [];
  const c: number[] = [];
  const loop = clockHours(open, close);
  for (let k = 0; k < loop.length; k++) {
    const h = loop[k];
    const cx = h.day_index === 0 ? ctx : ctxNext;
    if (cx == null) throw new ModelError('missing_context');
    let wx = 1.0;
    if (!cx.typical) {
      const record = cx.forecast != null ? cx.forecast[h.hour] : null;
      wx = weatherMultiplier(A, record == null ? null : record, 'open').multiplier;
    }
    const dH = ((demand * (h.end - h.start)) / minutes) * wx;
    const capH = profile.capacity_orders_per_hour * h.fraction;
    d.push(dH);
    c.push(capH);
    hours.push({
      day_index: h.day_index as 0 | 1, // the window is within two days
      hour: h.hour,
      fraction: h.fraction,
      demand: dH,
      capacity: capH,
      weather: wx,
      orders: min2(dH, capH),
    });
  }
  const evidence = evidenceFrom(cal, null); // events use the truck factor only
  evidence.event = true;
  const pair = intervalCapped(A, d, c, evidence);
  return { orders: pair[0], buyers, demand, hours, spread: pair[1] };
}

/**
 * A guaranteed-fee stop: revenue is contracted, so every line is fixed and adds nothing to the width of
 * the day's range. It still takes time, fuel and labour through the timeline.
 */
export function cateringMoney(profile: TruckProfile, ct: CateringTerms): StopMoney {
  const price = ct.price_per_head != null ? ct.price_per_head : 0.0;
  const guarantee = ct.guarantee != null ? ct.guarantee : 0.0;
  const sales = max2(ct.headcount * price, guarantee);
  const orders = ct.headcount * 1.0;
  const foodCost = ct.food_cost != null ? ct.food_cost : sales * profile.food_cost_pct;
  const packaging = ct.headcount * profile.packaging_per_order;
  const contribution = sales - foodCost - packaging;
  return {
    orders: estFixed(orders),
    sales: estFixed(sales),
    food_cost: estFixed(foodCost),
    packaging: estFixed(packaging),
    card_fees: estFixed(0.0),
    spot_fee: estFixed(0.0),
    tips: estFixed(0.0),
    contribution: estFixed(contribution),
    unit_margin: { at_minimum: 0.0, at_percentage: 0.0 }, // not used for a contracted stop
  };
}
