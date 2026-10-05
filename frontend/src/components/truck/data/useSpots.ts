import { useEffect, useRef } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { truckApi, truckKeys, type Spot } from '../../../api/truck';
import { regionHasData, regionRebuilding, useTruck } from './TruckContext';
import { useRefreshStaleSpots } from './mutations';
import { STALE, truckRetry } from './queryPolicy';

/**
 * The spot list (route 10), ordered by name. `archived: true` includes the spots the owner deleted:
 * planned days and logged services still point at them, so everything that evaluates a plan reads
 * the list that way.
 *
 * When a spot's stored vectors are not fresh the hook asks the server, once per page visit, to
 * recompute them (`useRefreshStaleSpots`); the list is read again when that changed something.
 * Until then a stale spot is still evaluated from what it has (rule 5 of 2.5). Nothing is sent
 * while the region data is being rebuilt, or when the truck's region has no data to compute from.
 */
export function useSpots(options: { archived?: boolean } = {}) {
  const archived = options.archived === true;
  const { region } = useTruck();
  const query = useQuery({
    queryKey: truckKeys.spots(archived),
    queryFn: () => truckApi.listSpots({ archived }),
    staleTime: STALE.spots,
    retry: truckRetry,
  });

  const refresh = useRefreshStaleSpots();
  const mutate = refresh.mutate;
  const asked = useRef(false);
  const spots = query.data;
  useEffect(() => {
    if (asked.current || spots === undefined) return;
    if (!regionHasData(region) || regionRebuilding(region)) return;
    if (!spots.some((spot) => !spot.archived && spot.vectors_state !== 'fresh')) return;
    asked.current = true;
    mutate();
  }, [spots, region, mutate]);

  return query;
}

/**
 * One spot (route 13). Starts from the copy in a cached list when there is one, so opening a spot
 * from the list shows it at once. A 404 means the spot no longer exists: test the error with
 * `apiErrorStatus(error) === 404` and show the not-found state.
 */
export function useSpot(id: string | null | undefined) {
  const qc = useQueryClient();
  const known = typeof id === 'string' && id !== '';
  return useQuery({
    queryKey: truckKeys.spot(known ? id : ''),
    queryFn: () => truckApi.getSpot(id as string),
    enabled: known,
    staleTime: STALE.spots,
    retry: truckRetry,
    initialData: () => {
      if (!known) return undefined;
      for (const [, list] of qc.getQueriesData<Spot[]>({ queryKey: ['truck', 'spots', 'list'] })) {
        const found = list?.find((spot) => spot.id === id);
        if (found !== undefined) return found;
      }
      return undefined;
    },
    initialDataUpdatedAt: () => {
      let latest: number | undefined;
      for (const archived of [false, true]) {
        const at = qc.getQueryState(truckKeys.spots(archived))?.dataUpdatedAt;
        if (at !== undefined && at > 0 && (latest === undefined || at > latest)) latest = at;
      }
      return latest;
    },
  });
}
