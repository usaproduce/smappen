// Truck Planner estimator - money (02_MODEL 4.9).

import { max2, nonZero } from './core';
import { estLevels } from './estimate';
import type {
  DayCosts,
  Estimate,
  MoneyLines,
  SpotTerms,
  StopMoney,
  Timeline,
  TruckProfile,
  UnitMargins,
} from './types';

/** The money lines of a stop, in the order they are computed and shown. */
export const MONEY_LINES: readonly (keyof MoneyLines)[] = [
  'orders',
  'sales',
  'food_cost',
  'packaging',
  'card_fees',
  'spot_fee',
  'tips',
  'contribution',
];

/**
 * The money lines of one stop at one number of orders. The spot fee is fee_flat + fee_pct * sales with
 * fee_min as a floor on the total. contribution is what the stop leaves before the day's own costs.
 */
export function stopMoneyAt(profile: TruckProfile, terms: SpotTerms, orders: number): MoneyLines {
  const sales = orders * profile.avg_ticket;
  const foodCost = sales * profile.food_cost_pct;
  const packaging = orders * profile.packaging_per_order;
  const cardFees =
    sales * profile.card_share * profile.card_fee_pct + orders * profile.card_share * profile.card_fee_fixed;
  const spotFee = max2(terms.fee_min, terms.fee_flat + terms.fee_pct * sales);
  const tips = profile.tips_include ? sales * profile.card_share * profile.tips_pct_of_card_sales : 0.0;
  const contribution = sales - foodCost - packaging - cardFees - spotFee + tips;
  return {
    orders,
    sales,
    food_cost: foodCost,
    packaging,
    card_fees: cardFees,
    spot_fee: spotFee,
    tips,
    contribution,
  };
}

/** Contribution per extra order: while the minimum fee is what is paid, and once flat + percentage is. */
export function unitMargins(profile: TruckProfile, terms: SpotTerms): UnitMargins {
  const tip = profile.tips_include ? profile.card_share * profile.tips_pct_of_card_sales : 0.0;
  const base =
    profile.avg_ticket * (1.0 - profile.food_cost_pct - profile.card_share * profile.card_fee_pct + tip) -
    profile.packaging_per_order -
    profile.card_share * profile.card_fee_fixed;
  return { at_minimum: base, at_percentage: base - profile.avg_ticket * terms.fee_pct };
}

/** StopMoney for an orders Estimate: every line at value, low and high. */
export function stopMoney(profile: TruckProfile, terms: SpotTerms, orders: Estimate): StopMoney {
  const v = stopMoneyAt(profile, terms, orders.value);
  const l = stopMoneyAt(profile, terms, orders.low);
  const h = stopMoneyAt(profile, terms, orders.high);
  const c = orders.confidence;
  return {
    orders: estLevels(v.orders, l.orders, h.orders, c),
    sales: estLevels(v.sales, l.sales, h.sales, c),
    food_cost: estLevels(v.food_cost, l.food_cost, h.food_cost, c),
    packaging: estLevels(v.packaging, l.packaging, h.packaging, c),
    card_fees: estLevels(v.card_fees, l.card_fees, h.card_fees, c),
    spot_fee: estLevels(v.spot_fee, l.spot_fee, h.spot_fee, c),
    tips: estLevels(v.tips, l.tips, h.tips, c),
    contribution: estLevels(v.contribution, l.contribution, h.contribution, c),
    unit_margin: unitMargins(profile, terms),
  };
}

/**
 * Orders at which a stop's contribution equals fixedCosts (a real; show it rounded up), or null when no
 * number of orders gets there.
 */
export function breakEvenOrders(profile: TruckProfile, terms: SpotTerms, fixedCosts: number): number | null {
  const m = unitMargins(profile, terms);
  if (m.at_minimum <= 0) return null;
  const x = (fixedCosts + terms.fee_min) / m.at_minimum; // the minimum fee is what is paid
  if (terms.fee_flat + terms.fee_pct * profile.avg_ticket * x <= terms.fee_min) return max2(x, 0.0);
  if (m.at_percentage <= 0) return null;
  return max2((fixedCosts + terms.fee_flat) / m.at_percentage, 0.0); // flat + percentage is what is paid
}

/**
 * The day's own costs. Paid crew are paid from the start of prep to "done", except gaps marked unpaid.
 * The owner's own time is not a cost. One fuel price covers truck and generator; it must be a number.
 */
export function dayCosts(profile: TruckProfile, timeline: Timeline, fuelPricePerGal: number): DayCosts {
  if (typeof fuelPricePerGal !== 'number') {
    throw new TypeError('dayCosts: fuel_price_per_gal must be a number');
  }
  const paidHours = timeline.paid_minutes / 60.0;
  const labour = paidHours * profile.paid_crew * profile.wage_per_hour * (1.0 + profile.payroll_burden_pct);
  const driveGallons = timeline.miles / nonZero(profile.mpg, 'profile.mpg');
  const generatorGallons = (timeline.generator_minutes / 60.0) * profile.generator_gal_per_hour;
  const fuel = (driveGallons + generatorGallons) * fuelPricePerGal;
  const tolls = timeline.tolls;
  const fixed = timeline.stops.length > 0 ? profile.fixed_cost_per_service_day : 0.0;
  const total = labour + fuel + tolls + fixed;
  return {
    labour,
    fuel,
    tolls,
    fixed,
    total,
    paid_hours: paidHours,
    drive_gallons: driveGallons,
    generator_gallons: generatorGallons,
  };
}
