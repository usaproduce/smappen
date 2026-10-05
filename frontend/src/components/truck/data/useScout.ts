import { useQuery, type QueryClient } from '@tanstack/react-query';
import { truckApi, truckKeys, type LeadStatus, type ScoutAnswer } from '../../../api/truck';
import { GC, STALE, truckRetry } from './queryPolicy';

/** The key part for a list of hidden lead statuses: sorted, so the same choice in any order is one entry. */
function hideKey(hide: readonly LeadStatus[]): string {
  const sorted = hide.slice();
  sorted.sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));
  return sorted.join(',');
}

/**
 * Scout results (route 38): at most 50 places that could host the truck, in the server's rank order.
 * `hide` lists the lead statuses left out before ranking. The route is limited to 60 requests an
 * hour, so the answer is kept for ten minutes and never refetched by a timer or on focus; the
 * "Refresh" button calls `refreshScout`.
 */
export function useScout(hide: readonly LeadStatus[]) {
  const key = hideKey(hide);
  return useQuery({
    queryKey: truckKeys.scout(key),
    queryFn: () => truckApi.scout({ hide: key === '' ? [] : (key.split(',') as LeadStatus[]) }),
    staleTime: STALE.scout,
    gcTime: GC.scout,
    refetchOnWindowFocus: false,
    retry: truckRetry,
  });
}

/** "Refresh": ask the server to skip its result caches (`refresh=1`) and replace the cached answer. */
export function refreshScout(qc: QueryClient, hide: readonly LeadStatus[]): Promise<ScoutAnswer> {
  const key = hideKey(hide);
  return qc.fetchQuery({
    queryKey: truckKeys.scout(key),
    queryFn: () => truckApi.scout({ hide: key === '' ? [] : (key.split(',') as LeadStatus[]), refresh: true }),
    staleTime: 0,
    retry: false,
  });
}
