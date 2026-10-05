// Truck Planner estimator - dispatch by canonical name (02_MODEL 8.1).
//
// The golden cases name a function of the model document in snake_case and carry its arguments by
// parameter name. Each entry below unpacks those names into the typed call. `A` arrives as
// { overrides, region } and gets this port's seed copy; validate_overrides gets the seed copy as its
// `seeds` argument unless the case carries a seed tree of its own. A tuple result is returned as an
// array in the written order.

import { hostCapture, hostExclusion, hostLinkPoint, captureAtPoint, rivalsAtOrigin } from './capture';
import { accuracyReport, calibrate } from './calibration';
import { qkey, roundHalfAway } from './core';
import { expandCurves, hourWeights } from './curves';
import {
  addDays,
  civilFromDays,
  dayContext,
  dayOfWeek,
  daysFromCivil,
  federalHolidays,
  formatDate,
  holidayOn,
  lastWeekday,
  makeContext,
  nthWeekday,
  parseDate,
  typicalContext,
} from './dates';
import { bestWindows, calibrationFactor, hourlyOrders, vectorsMatch, weekStrip, windowOrders } from './demand';
import { fallbackLeg, legMinutes, trafficFactor } from './driving';
import { estFixed, estLevels, estSum, weakest } from './estimate';
import { cateringMoney, eventOrders } from './events';
import { haversineM, walkWeight } from './geometry';
import { cellScores, mapWeightRows, scoreByte } from './mapRows';
import { breakEvenOrders, dayCosts, stopMoney, stopMoneyAt, unitMargins } from './money';
import { dayPlan, evaluate } from './plan';
import { evidenceFrom, interval, intervalCapped } from './ranges';
import { scoutEstimate, scoutRank, stripFromRows } from './scout';
import { SEEDS, makeAssumptions, seed, validateOverrides } from './seeds';
import { suggestDay, suggestWeek } from './suggest';
import { buildTimeline, requiredLegKeys } from './timeline';
import { weatherMultiplier } from './weather';
import type { Assumptions } from './types';

/* eslint-disable @typescript-eslint/no-explicit-any */

// The golden `A` argument is { overrides, region }; the seed file is implied.
function assume(a: any): Assumptions {
  return makeAssumptions(a.overrides, a.region);
}

/**
 * Every function of the model catalogue under its canonical snake_case name, taking the golden-case
 * `args` object (named arguments) and returning the result to compare with `expected`.
 */
export const GOLDEN_DISPATCH: Record<string, (args: any) => unknown> = {
  // 1.4 rounding, 2 seeds, 3 estimates
  round_half_away: (a) => roundHalfAway(a.x, a.decimals),
  qkey: (a) => qkey(a.x),
  seed: (a) => seed(assume(a.A), a.path),
  validate_overrides: (a) => validateOverrides(a.seeds !== undefined ? a.seeds : SEEDS, a.overrides),
  est_fixed: (a) => estFixed(a.x),
  est_levels: (a) => estLevels(a.v, a.l, a.h, a.c),
  weakest: (a) => weakest(a.labels),
  est_sum: (a) => estSum(a.estimates),
  // 4.1 dates
  days_from_civil: (a) => daysFromCivil(a.y, a.m, a.d),
  civil_from_days: (a) => civilFromDays(a.z),
  parse_date: (a) => parseDate(a.s),
  format_date: (a) => formatDate(a.y, a.m, a.d),
  day_of_week: (a) => dayOfWeek(a.date),
  add_days: (a) => addDays(a.date, a.n),
  nth_weekday: (a) => nthWeekday(a.year, a.month, a.dow, a.n),
  last_weekday: (a) => lastWeekday(a.year, a.month, a.dow),
  federal_holidays: (a) => federalHolidays(a.year, a.flags),
  holiday_on: (a) => holidayOn(a.date, a.flags),
  day_context: (a) =>
    dayContext(assume(a.A), a.date, a.treat_as, a.forecast, a.fuel_price_per_gal, a.fuel_price_source),
  typical_context: (a) => typicalContext(assume(a.A), a.dow),
  make_context: (a) =>
    makeContext(
      assume(a.A),
      a.date,
      a.dow,
      a.eff_dow,
      a.cls,
      a.hol,
      a.treat_as,
      a.forecast,
      a.fuel_price_per_gal,
      a.fuel_price_source,
      a.typical,
    ),
  // 4.2 curves, 4.3 geometry
  hour_weights: (a) => hourWeights(assume(a.A), a.ctx),
  expand_curves: (a) => expandCurves(assume(a.A)),
  haversine_m: (a) => haversineM(a.lat1, a.lng1, a.lat2, a.lng2),
  walk_weight: (a) => walkWeight(assume(a.A), a.d),
  // 4.4 capture, 4.5 host, 4.6 weather
  rivals_at_origin: (a) => rivalsAtOrigin(assume(a.A), a.lat, a.lng, a.outlets),
  host_exclusion: (a) => hostExclusion(assume(a.A), a.host),
  host_link_point: (a) => hostLinkPoint(assume(a.A), a.lat, a.lng, a.host, a.sources),
  capture_at_point: (a) =>
    captureAtPoint(assume(a.A), a.lat, a.lng, a.visibility, a.sources, a.outlets, a.exclusion),
  host_capture: (a) => hostCapture(assume(a.A), a.host, a.visibility, a.rivals_here),
  weather_multiplier: (a) => weatherMultiplier(assume(a.A), a.fc, a.setting),
  // 4.7 demand and orders
  calibration_factor: (a) => calibrationFactor(a.cal, a.spot_id),
  hourly_orders: (a) => hourlyOrders(assume(a.A), a.profile, a.terms, a.vectors, a.cal, a.ctx, a.hour),
  vectors_match: (a) => vectorsMatch(assume(a.A), a.terms, a.vectors),
  window_orders: (a) =>
    windowOrders(assume(a.A), a.profile, a.terms, a.vectors, a.cal, a.ctx, a.ctx_next, a.open, a.close),
  week_strip: (a) => weekStrip(assume(a.A), a.profile, a.terms, a.vectors, a.cal),
  best_windows: (a) => bestWindows(a.values, a.length, a.top_n, a.circular, a.allowed),
  // 4.8 ranges
  evidence_from: (a) => evidenceFrom(a.cal, a.spot_id),
  interval: (a) => interval(assume(a.A), a.mean, a.ev),
  interval_capped: (a) => intervalCapped(assume(a.A), a.d, a.c, a.ev),
  // 4.9 money
  stop_money_at: (a) => stopMoneyAt(a.profile, a.terms, a.orders),
  stop_money: (a) => stopMoney(a.profile, a.terms, a.orders),
  unit_margins: (a) => unitMargins(a.profile, a.terms),
  break_even_orders: (a) => breakEvenOrders(a.profile, a.terms, a.fixed_costs),
  day_costs: (a) => dayCosts(a.profile, a.timeline, a.fuel_price_per_gal),
  // 4.10 driving, 4.11 timeline, 4.12 day plan
  fallback_leg: (a) => fallbackLeg(assume(a.A), a.lat1, a.lng1, a.lat2, a.lng2),
  traffic_factor: (a) => trafficFactor(assume(a.A), a.ctx, a.minute),
  leg_minutes: (a) => legMinutes(assume(a.A), a.profile, a.leg, a.ctx, a.lookup_minute),
  required_leg_keys: (a) => requiredLegKeys(a.stops),
  build_timeline: (a) => buildTimeline(assume(a.A), a.profile, a.ctx, a.stops, a.legs),
  evaluate: (a) => evaluate(assume(a.A), a.profile, a.plan, a.ctx, a.ctx_next, a.legs, a.cal),
  day_plan: (a) => dayPlan(assume(a.A), a.profile, a.plan, a.ctx, a.ctx_next, a.legs, a.cal),
  // 4.13 calibration and accuracy, 4.14 events and catering
  calibrate: (a) => calibrate(assume(a.A), a.services, a.as_of),
  accuracy_report: (a) => accuracyReport(a.entries),
  event_orders: (a) => eventOrders(assume(a.A), a.profile, a.ev, a.cal, a.ctx, a.ctx_next, a.open, a.close),
  catering_money: (a) => cateringMoney(a.profile, a.ct),
  // 4.15 suggestions, 4.16 scouting
  suggest_day: (a) => suggestDay(assume(a.A), a.profile, a.ctx, a.ctx_next, a.spots, a.legs, a.cal, a.options),
  suggest_week: (a) =>
    suggestWeek(assume(a.A), a.profile, a.week_start, a.contexts, a.spots, a.legs, a.cal, a.options),
  scout_estimate: (a) => scoutEstimate(assume(a.A), a.profile, a.place, a.legs, a.cal, a.fuel_price_per_gal),
  strip_from_rows: (a) => stripFromRows(assume(a.A), a.profile, a.terms, a.vectors, a.rows),
  scout_rank: (a) => scoutRank(a.results),
  // 4.17 map fast path
  map_weight_rows: (a) => mapWeightRows(assume(a.A), a.profile, a.cal),
  cell_scores: (a) => cellScores(a.features, a.n, a.w_opp_row, a.w_people_row, a.regime, a.capacity),
  score_byte: (a) => scoreByte(a.x, a.hi),
};
