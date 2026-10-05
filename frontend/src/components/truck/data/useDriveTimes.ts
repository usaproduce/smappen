import { useMemo } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import {
  truckApi,
  truckKeys,
  type DriveLeg,
  type DrivePoint,
  type DriveTimesAnswer,
  type RoutingState,
} from '../../../api/truck';
import type { LegMap } from '../../../utils/truck/model';
import { pointsKey, toLegs } from '../../../utils/truck/assemble';
import { useTruck } from './TruckContext';
import { GC, STALE, truckRetry } from './queryPolicy';

/**
 * A drive-times answer as the cache keeps it: the legs, and the points they were asked for. The
 * points let a drive-time correction find its directed pair in every cached result.
 */
export interface DriveTimesData extends DriveTimesAnswer {
  points: DrivePoint[];
}

export interface DriveTimes {
  /**
   * `pending` while the first answer for these points is on its way; `ready` with legs (or with
   * nothing to ask); `error` when the request failed. A plan still evaluates after an error: the
   * model fills every missing pair with a straight-line estimate and says so.
   */
  status: 'pending' | 'ready' | 'error';
  legs: DriveLeg[];
  /** The legs keyed `<from_id>><to_id>`, as `dayPlan` takes them. A pair that is absent stays absent. */
  legInputs: LegMap;
  routingState: RoutingState;
  /** True while `legs` still belong to the previous set of points (a stop was just added or removed). */
  updating: boolean;
  error: unknown;
  refetch: () => void;
}

const NO_LEGS: DriveLeg[] = [];

/**
 * Drive legs between points (route 18). Points are `{ id, lat, lng }` with id `base` for the truck's
 * base and the stop id for a stop.
 *
 * Without `pairs` it asks for `mode: 'matrix'`: every ordered pair. `dayPlan` needs the leg that
 * skips a stop to work out what that stop adds, and the matrix also covers any reordering, so one
 * answer serves the planner, the week, today and the day sheet. With `pairs` it asks for exactly
 * those directed pairs (spot detail and compare: base to spot and back).
 *
 * The route is limited to 240 requests an hour, shared with plan saves, so the answer is kept for
 * 24 hours and nothing may call this per keystroke or per map click. Fewer than two points sends
 * nothing. Corrections update the cache directly (`useSaveLegOverride`).
 */
export function useDriveTimes(
  points: readonly DrivePoint[],
  pairs?: readonly (readonly [string, string])[],
): DriveTimes {
  const { routing } = useTruck();
  const key = pointsKey(points);
  const pairsKey = pairs === undefined ? null : pairs.map((p) => p[0] + '>' + p[1]).join(',');
  const enabled = points.length >= 2 && (pairs === undefined || pairs.length > 0);

  // The request is rebuilt only when the keys change, so a caller that passes a new array with the
  // same content on every render neither refetches nor invalidates anything.
  const request = useMemo(
    () => ({
      points: points.map((p) => ({ id: p.id, lat: p.lat, lng: p.lng })),
      pairs: pairs === undefined ? undefined : pairs.map((p) => [p[0], p[1]] as [string, string]),
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [key, pairsKey],
  );

  const query = useQuery({
    queryKey: truckKeys.driveTimes(pairsKey === null ? key : key + '#' + pairsKey),
    queryFn: async (): Promise<DriveTimesData> => {
      const answer =
        request.pairs === undefined
          ? await truckApi.driveTimes({ points: request.points, mode: 'matrix' })
          : await truckApi.driveTimes({ points: request.points, mode: 'pairs', pairs: request.pairs });
      return { ...answer, points: request.points };
    },
    enabled,
    staleTime: STALE.driveTimes,
    gcTime: GC.driveTimes,
    refetchOnWindowFocus: false,
    retry: truckRetry,
    placeholderData: keepPreviousData,
  });

  const data = enabled ? query.data : undefined;
  const legs = data !== undefined ? data.legs : NO_LEGS;
  const legInputs = useMemo(() => toLegs(legs), [legs]);
  const refetch = query.refetch;
  const isError = query.isError;
  const isPlaceholder = query.isPlaceholderData;
  const error = query.error;

  return useMemo(
    () => ({
      status: !enabled || data !== undefined ? ('ready' as const) : isError ? ('error' as const) : ('pending' as const),
      legs,
      legInputs,
      routingState: data !== undefined ? data.routing.state : routing.state,
      updating: enabled && isPlaceholder,
      error,
      refetch: () => {
        void refetch();
      },
    }),
    [enabled, data, isError, isPlaceholder, error, legs, legInputs, routing.state, refetch],
  );
}
