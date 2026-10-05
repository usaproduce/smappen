import { useQuery } from '@tanstack/react-query';
import { truckApi, truckKeys } from '../../../api/truck';
import { STALE, truckRetry } from './queryPolicy';

/**
 * How the estimates did against the logged services (route 37): the accuracy report, the entries it
 * was computed from and the number of services that carry no estimate. Leave an end out for an open
 * range; without both, every logged service is read (the Log's estimate line needs them all).
 */
export function useAccuracy(from?: string, to?: string) {
  const start = from ?? '';
  const end = to ?? '';
  return useQuery({
    queryKey: truckKeys.accuracy(start, end),
    queryFn: () =>
      truckApi.accuracy({
        ...(start !== '' ? { from: start } : {}),
        ...(end !== '' ? { to: end } : {}),
      }),
    staleTime: STALE.accuracy,
    retry: truckRetry,
  });
}
