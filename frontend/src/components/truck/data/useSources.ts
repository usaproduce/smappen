import { useQuery } from '@tanstack/react-query';
import { truckApi, truckKeys } from '../../../api/truck';
import { STALE, truckRetry } from './queryPolicy';

/**
 * Where the data comes from (route 44): the dataset behind the truck's region, the fuel price in
 * use and the attribution strings, placeholders already filled by the server. The Data page prints
 * the strings as sent.
 */
export function useSources() {
  return useQuery({
    queryKey: truckKeys.sources(),
    queryFn: () => truckApi.sources(),
    staleTime: STALE.sources,
    refetchOnWindowFocus: false,
    retry: truckRetry,
  });
}
