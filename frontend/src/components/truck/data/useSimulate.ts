import { useQuery, type Query } from '@tanstack/react-query';
import { truckApi, truckKeys, type HostInput, type SimulateAnswer } from '../../../api/truck';
import { weekStrip } from '../../../utils/truck/model';
import type { LatLng, SpotTerms, Visibility } from '../../../utils/truck/model';
import { coord6, hostKey, visibilityKey, withinTolerance } from '../../../utils/truck/assemble';
import { regionRebuilding, useTruck } from './TruckContext';
import { GC, STALE, truckRetry } from './queryPolicy';

export interface SimulateRequest {
  point: LatLng;
  /** The host as a request describes it (linked place, segment, size, only food), or null. */
  host: HostInput | null;
  /** The visibility levels to compute vectors for, one to three. */
  visibilities: Visibility[];
  /** A saved spot: its calibration factor then enters the server's own estimate (the drift check). */
  spotId?: string | null;
}

/**
 * Exact capture at one point (route 9): the location vectors per requested visibility, the host
 * after the server's link rule and defaults, the food outlets within walking distance and the places
 * that could be the host. Nothing is stored on the server.
 *
 * Pass null when no point is selected. Nothing is sent while the region data is being rebuilt
 * (route 9 would answer 409). The key holds what changes the vectors: the data version, the point at
 * six decimals, the host's link, segment and, unless it is a visitor host, size, and the
 * visibilities. While a new answer for the same point is on its way (the host changed) the
 * previous answer stays available as placeholder data; for another point it does not.
 *
 * The server's own `estimate` is never printed. Development builds compare its week strip with the
 * browser's and warn in the console on a difference.
 */
export function useSimulate(request: SimulateRequest | null) {
  const { A, profile, cal, region } = useTruck();
  const version = region?.dataset_version ?? 'none';
  const lat6 = request === null ? '' : coord6(request.point.lat);
  const lng6 = request === null ? '' : coord6(request.point.lng);
  const host = request === null ? '-' : hostKey(request.host);
  const vis = request === null ? '' : visibilityKey(request.visibilities);

  return useQuery({
    queryKey: truckKeys.simulate(version, lat6, lng6, host, vis),
    queryFn: async (): Promise<SimulateAnswer> => {
      if (request === null) throw new Error('useSimulate: no point');
      const first = request.visibilities[0];
      const answer = await truckApi.simulate({
        point: { lat: request.point.lat, lng: request.point.lng },
        visibilities: request.visibilities,
        terms: { visibility: first, host: request.host },
        ...(request.spotId ? { spot_id: request.spotId } : {}),
      });
      if (import.meta.env.DEV) {
        try {
          const vectors = answer.vectors[first];
          if (vectors !== undefined) {
            const terms: SpotTerms = {
              spot_id: request.spotId ?? null,
              visibility: first,
              host: answer.host,
              fee_flat: 0,
              fee_pct: 0,
              fee_min: 0,
              allowed: null,
            };
            const local = weekStrip(A, profile, terms, vectors, cal);
            const server = answer.estimate.week_strip;
            const same = local.length === server.length && local.every((x, i) => withinTolerance(x, server[i]));
            if (!same) console.warn('[truck] estimator drift', 'week strip at ' + lat6 + ',' + lng6, { local, server });
          }
        } catch (e) {
          console.warn('[truck] estimator drift', 'check failed', e);
        }
      }
      return answer;
    },
    enabled: request !== null && !regionRebuilding(region),
    staleTime: STALE.simulate,
    gcTime: GC.simulate,
    refetchOnWindowFocus: false,
    retry: truckRetry,
    placeholderData: (previous: SimulateAnswer | undefined, previousQuery: Query<SimulateAnswer> | undefined) => {
      if (previous === undefined || previousQuery === undefined) return undefined;
      const key = previousQuery.queryKey;
      return key[2] === version && key[3] === lat6 && key[4] === lng6 ? previous : undefined;
    },
  });
}
