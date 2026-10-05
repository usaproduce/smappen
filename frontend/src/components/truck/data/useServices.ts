import { useQuery } from '@tanstack/react-query';
import { truckApi, truckKeys } from '../../../api/truck';
import { STALE, truckRetry } from './queryPolicy';

/**
 * Logged services, newest first (route 31), each with the prediction it is judged against.
 * Leave `from` and `to` out for the server's default, the last 90 days; a range holds at most 730
 * dates, both ends counted. `spot_id` narrows the list to one spot.
 */
export function useServices(options: { from?: string; to?: string; spot_id?: string } = {}) {
  const from = options.from ?? '';
  const to = options.to ?? '';
  const spotId = options.spot_id ?? '';
  return useQuery({
    queryKey: truckKeys.services(from, to, spotId),
    queryFn: () =>
      truckApi.listServices({
        ...(from !== '' ? { from } : {}),
        ...(to !== '' ? { to } : {}),
        ...(spotId !== '' ? { spot_id: spotId } : {}),
      }),
    staleTime: STALE.services,
    retry: truckRetry,
  });
}
