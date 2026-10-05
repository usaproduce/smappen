// Truck Planner - query policy shared by every hook of this directory (docs/truck-planner/05_FRONTEND.md 2.2).
//
// The app-wide defaults stay (stale after 30 s, refetch on window focus). What the truck hooks change
// is listed here once, so the table of 2.2 can be read off this file.

import { apiErrorStatus } from '../../../api/truck';

export const SECOND = 1000;
export const MINUTE = 60 * SECOND;
export const HOUR = 60 * MINUTE;

/** Stale times of 2.2, in milliseconds. */
export const STALE = {
  bootstrap: 5 * MINUTE,
  pack: Infinity,
  simulate: 10 * MINUTE,
  spots: 60 * SECOND,
  dayContextNear: 10 * MINUTE,
  dayContextFar: 12 * HOUR,
  driveTimes: 24 * HOUR,
  plans: 30 * SECOND,
  suggest: 5 * MINUTE,
  services: 60 * SECOND,
  accuracy: 60 * SECOND,
  scout: 10 * MINUTE,
  sources: HOUR,
} as const;

/** Garbage-collection times of 2.2 that differ from the default, in milliseconds. */
export const GC = {
  pack: 10 * MINUTE,
  simulate: 10 * MINUTE,
  dayContext: 30 * MINUTE,
  driveTimes: 24 * HOUR,
  suggest: 10 * MINUTE,
  scout: 10 * MINUTE,
} as const;

/**
 * Retry rule of every truck query: once for a request that got no answer or a 5xx answer, never for
 * a 4xx answer or a 501. A 404, 409, 422 or 429 would only fail again, a second later, and the
 * screen that shows it (a missing spot, the first-run step, a rate limit) should not wait for that;
 * a 501 is a route that is not built yet.
 */
export function truckRetry(failureCount: number, error: unknown): boolean {
  const status = apiErrorStatus(error);
  if (status !== null && ((status >= 400 && status < 500) || status === 501)) return false;
  return failureCount < 1;
}
