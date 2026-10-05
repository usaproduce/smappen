import { useCallback, useMemo } from 'react';
import type { DriveLeg, DrivePoint } from '../../../api/truck';
import { addDays, dayPlan } from '../../../utils/truck/model';
import type { DayResult, TreatAs } from '../../../utils/truck/model';
import {
  drivePoints,
  indexSpots,
  stopsEvaluable,
  toPlanInput,
  toStopInput,
  type StopLike,
} from '../../../utils/truck/assemble';
import { useTruck } from './TruckContext';
import { useDayContexts } from './useDayContexts';
import { useDriveTimes } from './useDriveTimes';
import { useSpots } from './useSpots';

export interface PlanEvaluation {
  /**
   * `pending`: an input is still on its way (spots, the day's context, or the first drive legs).
   * `ready`: `result` is the day as the model evaluates it. `unavailable`: a stop cannot be
   * evaluated yet (its spot has no stored vectors, or an event or catering stop has no place or
   * terms): no result. `error`: the spot list could not be loaded.
   */
  status: 'pending' | 'ready' | 'unavailable' | 'error';
  result: DayResult | null;
  /** The drive legs the server sent for this day's points (every ordered pair). */
  legs: DriveLeg[];
  notes: {
    /** A leg of the day is a straight-line estimate, or Google drive times are not available. */
    driveFallback: boolean;
    /** The day's context could not be loaded: no forecast, the fuel price of the bootstrap answer. */
    contextDegraded: boolean;
  };
  /**
   * The day's points just changed (a stop was added or removed) and the new drive legs are on
   * their way: `result` was computed with the legs already known, and a pair they lack counts as a
   * straight line for the moment. Render estimates with `RangeValue dim` and hold back the
   * straight-line warning until this is false.
   */
  updating: boolean;
  error: unknown;
  refetch: () => void;
}

const NO_POINTS: DrivePoint[] = [];

/**
 * A day evaluated in the browser, on every edit (docs/truck-planner/05_FRONTEND.md 2.5).
 *
 * `stops` are saved `PlanStop`s or the planner's draft stops, in the owner's order; they are never
 * reordered. The hook gathers what `dayPlan` needs: the contexts of `date` and of the next date
 * (a window that closes after midnight uses the next date's own context, built without the
 * override), the spots with their stored vectors (archived ones included), every ordered drive leg
 * between the base and the stops, and the calibration.
 *
 * A failed forecast or failed drive times never block the result: the context is then degraded and
 * the model fills missing legs with straight-line estimates, and `notes` says which happened.
 */
export function usePlanEvaluation(date: string, stops: readonly StopLike[], treatAs: TreatAs | null): PlanEvaluation {
  const { A, profile, cal } = useTruck();
  const spotsQuery = useSpots({ archived: true });
  const spots = spotsQuery.data;
  const spotsById = useMemo(() => (spots === undefined ? null : indexSpots(spots)), [spots]);

  const treatByDate = useMemo(() => ({ [date]: treatAs }), [date, treatAs]);
  const day = useDayContexts(date, 2, treatByDate);
  const ctx = day.contexts[date];
  const ctxNext = day.contexts[addDays(date, 1)];

  const inputs = useMemo(
    () => (spotsById === null ? null : stops.map((stop) => toStopInput(stop, spotsById))),
    [stops, spotsById],
  );
  const evaluable = inputs !== null && stopsEvaluable(inputs) ? inputs : null;

  const baseLat = profile.base.lat;
  const baseLng = profile.base.lng;
  const points = useMemo(
    () => (evaluable === null || evaluable.length === 0 ? NO_POINTS : drivePoints({ lat: baseLat, lng: baseLng }, evaluable)),
    [evaluable, baseLat, baseLng],
  );
  const drive = useDriveTimes(points);
  const legInputs = drive.legInputs;
  const driveSettled = drive.status !== 'pending';

  const result = useMemo((): DayResult | null => {
    if (evaluable === null || ctx === undefined || ctxNext === undefined || !driveSettled) return null;
    return dayPlan(A, profile, toPlanInput(date, evaluable), ctx, ctxNext, legInputs, cal);
  }, [A, profile, cal, date, evaluable, ctx, ctxNext, legInputs, driveSettled]);

  let status: PlanEvaluation['status'];
  if (spotsQuery.isError && spots === undefined) status = 'error';
  else if (inputs === null || day.status === 'pending') status = 'pending';
  else if (evaluable === null) status = 'unavailable';
  else if (result === null) status = 'pending';
  else status = 'ready';

  // Right after a stop is added or removed the legs still belong to the previous set of points:
  // the day is evaluated at once with those (a pair they lack is a straight line for the moment)
  // and again when the new legs arrive. Until then nothing is said about straight lines.
  const updating = drive.updating;
  // The state of the answer itself; before there is one, the state the bootstrap answer reported.
  const routingBlocked = drive.routingState !== 'ok';
  const fallbackLeg = result !== null && result.timeline.legs.some((leg) => leg.source === 'fallback');
  const driveFallback = points.length >= 2 && !updating && (fallbackLeg || drive.status === 'error' || routingBlocked);

  const refetchSpots = spotsQuery.refetch;
  const refetchDay = day.refetch;
  const refetchDrive = drive.refetch;
  const refetch = useCallback(() => {
    void refetchSpots();
    refetchDay();
    refetchDrive();
  }, [refetchSpots, refetchDay, refetchDrive]);

  return {
    status,
    result,
    legs: drive.legs,
    notes: { driveFallback, contextDegraded: day.status === 'degraded' },
    updating,
    error: spotsQuery.error,
    refetch,
  };
}
