// Truck Planner estimator - geometry (02_MODEL 4.3).

import { clamp } from './core';
import { seed } from './seeds';
import type { Assumptions } from './types';

/** Metres on a sphere of radius 6371008.8. The only distance function in the model. */
export function haversineM(lat1: number, lng1: number, lat2: number, lng2: number): number {
  const PI = 3.141592653589793;
  const R = 6371008.8;
  const p1 = (lat1 * PI) / 180.0;
  const p2 = (lat2 * PI) / 180.0;
  const dp = ((lat2 - lat1) * PI) / 180.0;
  const dl = ((lng2 - lng1) * PI) / 180.0;
  const sp = Math.sin(dp / 2.0);
  const sl = Math.sin(dl / 2.0);
  let a = sp * sp + Math.cos(p1) * Math.cos(p2) * sl * sl;
  a = clamp(a, 0.0, 1.0);
  return 2.0 * R * Math.asin(Math.sqrt(a));
}

/** f(d): how much a person d metres away counts. exp(-d / 400), zero beyond 1,200 m (the cutoff is inclusive). */
export function walkWeight(A: Assumptions, d: number): number {
  if (d < 0 || d > seed<number>(A, 'kernel.walk_cutoff_m')) return 0.0;
  return Math.exp(-d / seed<number>(A, 'kernel.walk_decay_m'));
}
