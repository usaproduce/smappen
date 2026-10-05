// Truck Planner estimator - curves (02_MODEL 4.2).

import { NSEG } from './core';
import { typicalContext } from './dates';
import { intentCurve, presenceCurve } from './seeds';
import type { Assumptions, DayContext, HourWeights } from './types';

/**
 * presence[s][h] and intent[s][h] for the 24 clock hours of one context. The Monday-Friday factor
 * multiplies presence only, never intent.
 */
export function hourWeights(A: Assumptions, ctx: DayContext): HourWeights {
  const presence: number[][] = [];
  const intent: number[][] = [];
  for (let s = 0; s < NSEG; s++) {
    const p = presenceCurve(A, s, ctx.day_type[s]); // seed "segments.<s>.presence.<day type>"
    const q = intentCurve(A, s, ctx.day_type[s]); // seed "segments.<s>.intent.<day type>"
    const factor = ctx.dow_factor[s];
    const pRow: number[] = [];
    const qRow: number[] = [];
    for (let h = 0; h < 24; h++) {
      pRow.push(p[h] * factor);
      qRow.push(q[h]);
    }
    presence.push(pRow);
    intent.push(qRow);
  }
  return { presence, intent };
}

/**
 * Column `hour` of hourWeights(A, ctx): presence[s] and intent[s] of every segment for one clock hour.
 * The same numbers, without building the other 23 hours.
 */
export function hourColumn(A: Assumptions, ctx: DayContext, hour: number): { presence: number[]; intent: number[] } {
  const presence: number[] = [];
  const intent: number[] = [];
  for (let s = 0; s < NSEG; s++) {
    presence.push(presenceCurve(A, s, ctx.day_type[s])[hour] * ctx.dow_factor[s]);
    intent.push(intentCurve(A, s, ctx.day_type[s])[hour]);
  }
  return { presence, intent };
}

/** A typical week: presence[s][how] and intent[s][how] for how = dow * 24 + hour. */
export function expandCurves(A: Assumptions): HourWeights {
  const presence: number[][] = [];
  const intent: number[][] = [];
  for (let s = 0; s < NSEG; s++) {
    presence.push(new Array<number>(168).fill(0.0));
    intent.push(new Array<number>(168).fill(0.0));
  }
  for (let dow = 0; dow < 7; dow++) {
    const w = hourWeights(A, typicalContext(A, dow));
    for (let s = 0; s < NSEG; s++) {
      for (let h = 0; h < 24; h++) {
        presence[s][dow * 24 + h] = w.presence[s][h];
        intent[s][dow * 24 + h] = w.intent[s][h];
      }
    }
  }
  return { presence, intent };
}
