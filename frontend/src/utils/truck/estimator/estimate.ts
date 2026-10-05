// Truck Planner estimator - helpers on Estimate (02_MODEL section 3).

import { CONFIDENCE_LABELS, max2, min2 } from './core';
import type { Confidence, Estimate } from './types';

/** A contracted or arithmetic amount: no range, confidence "fixed". */
export function estFixed(x: number): Estimate {
  return { value: x, low: x, high: x, confidence: 'fixed' };
}

/** An Estimate from three levels that may arrive in any order (a cost line falls when orders rise). */
export function estLevels(v: number, l: number, h: number, c: Confidence): Estimate {
  return { value: v, low: min2(min2(v, l), h), high: max2(max2(v, l), h), confidence: c };
}

/** The label earliest in very_rough, rough, fair, good, fixed; "fixed" for an empty list. */
export function weakest(labels: readonly Confidence[]): Confidence {
  let best = CONFIDENCE_LABELS.length - 1;
  for (let k = 0; k < labels.length; k++) {
    const i = CONFIDENCE_LABELS.indexOf(labels[k]);
    if (i < 0) throw new Error('unknown confidence label: ' + String(labels[k]));
    if (i < best) best = i;
  }
  return CONFIDENCE_LABELS[best];
}

/** Lows add to lows and highs to highs, in list order: stops are treated as moving together. */
export function estSum(estimates: readonly Estimate[]): Estimate {
  let value = 0.0;
  let low = 0.0;
  let high = 0.0;
  const labels: Confidence[] = [];
  for (let k = 0; k < estimates.length; k++) {
    const e = estimates[k];
    value += e.value;
    low += e.low;
    high += e.high;
    labels.push(e.confidence);
  }
  return { value, low, high, confidence: weakest(labels) };
}
