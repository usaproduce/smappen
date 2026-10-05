// Truck Planner estimator - ranges and confidence (02_MODEL 4.8).
//
// Demand has a log-normal predictive distribution whose mean is the expected demand; low and high are
// its 10th and 90th percentiles (an 80 % interval). Where capacity binds, the two percentiles are
// carried through the hourly cap and value is the orders at expected demand.

import { Z80, hasOwn, max2, min2 } from './core';
import { seed } from './seeds';
import type { Assumptions, CalibrationState, Confidence, Estimate, Evidence, Spread } from './types';

/** What the logged services say about how far to trust an estimate at this spot. */
export function evidenceFrom(cal: CalibrationState | null, spotId: string | null): Evidence {
  const base: Evidence = {
    truck_weight: 0.0,
    spot_weight: 0.0,
    resid_sd: null,
    resid_weight: 0.0,
    weak_share: 0.0,
    default_size_share: 0.0,
    event: false,
    fixed: false,
  };
  if (cal == null) return base;
  base.truck_weight = cal.truck_weight;
  base.resid_sd = cal.resid_sd;
  base.resid_weight = cal.resid_weight;
  if (spotId != null && hasOwn(cal.spots, spotId)) base.spot_weight = cal.spots[spotId].weight;
  return base;
}

/**
 * An 80 % interval around an expected demand: [Estimate, spread].
 *
 * The spread combines model uncertainty (truck, spot, day, weak seeds, default host size, event), which
 * shrinks with the owner's logged services, and the counting noise of a finite number of orders. The
 * label depends on the model part only. value is the mean as given (0.0 when mean <= 0).
 */
export function interval(A: Assumptions, mean: number, ev: Evidence): [Estimate, Spread] {
  if (ev.fixed) {
    return [
      { value: mean, low: mean, high: mean, confidence: 'fixed' },
      {
        sigma_model: 0.0,
        sigma: 0.0,
        v_truck: 0.0,
        v_spot: 0.0,
        v_day: 0.0,
        v_weak: 0.0,
        v_size: 0.0,
        v_event: 0.0,
        v_count: 0.0,
      },
    ];
  }
  const kt = seed<number>(A, 'calibration.k_truck');
  const ks = seed<number>(A, 'calibration.k_spot');
  const n0 = seed<number>(A, 'uncertainty.resid_prior_weight');
  const sdTruck = seed<number>(A, 'uncertainty.sd_truck');
  const sdSpot = seed<number>(A, 'uncertainty.sd_spot');
  const sdDay = seed<number>(A, 'uncertainty.sd_day');
  const sdWeak = seed<number>(A, 'uncertainty.sd_weak');
  const sdDefaultSize = seed<number>(A, 'uncertainty.sd_default_size');
  const sdEvent = seed<number>(A, 'uncertainty.sd_event');

  const shrink = ks / (ks + ev.spot_weight);
  const vTruck = ((sdTruck * sdTruck) * kt) / (kt + ev.truck_weight);
  const vSpot = (sdSpot * sdSpot) * shrink;
  let vDay: number;
  if (ev.resid_sd == null) {
    vDay = sdDay * sdDay;
  } else {
    vDay = (n0 * (sdDay * sdDay) + ev.resid_weight * (ev.resid_sd * ev.resid_sd)) / (n0 + ev.resid_weight);
  }
  const weakSd = ev.weak_share * sdWeak;
  const vWeak = (weakSd * weakSd) * shrink;
  const sizeSd = ev.default_size_share * sdDefaultSize;
  const vSize = (sizeSd * sizeSd) * shrink;
  const vEvent = ev.event ? sdEvent * sdEvent : 0.0;
  const vModel = vTruck + vSpot + vDay + vWeak + vSize + vEvent; // added in this order
  const sigmaModel = Math.sqrt(vModel);

  let confidence: Confidence;
  if (sigmaModel < seed<number>(A, 'uncertainty.label_good_below')) {
    confidence = 'good';
  } else if (sigmaModel < seed<number>(A, 'uncertainty.label_fair_below')) {
    confidence = 'fair';
  } else if (sigmaModel < seed<number>(A, 'uncertainty.label_rough_below')) {
    confidence = 'rough';
  } else {
    confidence = 'very_rough';
  }

  const spread: Spread = {
    sigma_model: sigmaModel,
    sigma: sigmaModel,
    v_truck: vTruck,
    v_spot: vSpot,
    v_day: vDay,
    v_weak: vWeak,
    v_size: vSize,
    v_event: vEvent,
    v_count: 0.0,
  };
  if (mean <= 0) return [{ value: 0.0, low: 0.0, high: 0.0, confidence }, spread];
  const vCount = Math.log(1.0 + seed<number>(A, 'uncertainty.count_dispersion') / mean);
  const sigma = Math.sqrt(vModel + vCount);
  const low = mean * Math.exp(-0.5 * sigma * sigma - Z80 * sigma);
  let high = mean * Math.exp(-0.5 * sigma * sigma + Z80 * sigma);
  if (high < mean) high = mean;
  spread.sigma = sigma;
  spread.v_count = vCount;
  return [{ value: mean, low, high, confidence }, spread];
}

/**
 * The interval of a window whose hours have capacities: [Estimate, spread].
 *
 * d[k], c[k]: demand and capacity of loop hour k, each already multiplied by that hour's fraction. The
 * demand of every hour moves by one factor (k_low on a weak day, k_high on a strong one) and each
 * hour's cap is applied again. value is the orders at expected demand.
 */
export function intervalCapped(
  A: Assumptions,
  d: readonly number[],
  c: readonly number[],
  ev: Evidence,
): [Estimate, Spread] {
  let D = 0.0;
  let value = 0.0;
  for (let k = 0; k < d.length; k++) {
    D += d[k];
    value += min2(d[k], c[k]);
  }
  const pair = interval(A, D, ev); // log-normal on demand, before the cap
  const e = pair[0];
  const spread = pair[1];
  if (D <= 0) return [{ value: 0.0, low: 0.0, high: 0.0, confidence: e.confidence }, spread];
  const kLow = e.low / D;
  const kHigh = e.high / D;
  let low = 0.0;
  let high = 0.0;
  for (let k = 0; k < d.length; k++) {
    low += min2(kLow * d[k], c[k]);
    high += min2(kHigh * d[k], c[k]);
  }
  return [{ value, low: min2(low, value), high: max2(high, value), confidence: e.confidence }, spread];
}
