import { useQuery } from '@tanstack/react-query';
import { truckApi, truckKeys, type BootstrapAnswer } from '../../../api/truck';
import { clockSkewMinutes, nowEpochMs } from '../../../utils/truck/clock';
import { STALE, truckRetry } from './queryPolicy';

/**
 * The bootstrap answer as it is kept in the query cache: the server's answer plus how far the
 * device's clock is from the server's (docs/truck-planner/05_FRONTEND.md 2.7).
 */
export interface BootstrapData extends BootstrapAnswer {
  /**
   * Whole minutes to add to the device's clock to get the server's. Measured once, at the moment
   * the answer arrived, so a cached answer is never measured again against a later clock reading.
   */
  clock_skew_minutes: number;
}

/** Route 1, plus the clock skew. This is the query function of the bootstrap key everywhere. */
export async function fetchBootstrap(): Promise<BootstrapData> {
  const answer = await truckApi.bootstrap();
  let skew = 0;
  if (answer.timezone !== null && answer.today !== null && answer.now_minute !== null) {
    try {
      skew = clockSkewMinutes(answer.today, answer.now_minute, answer.timezone, nowEpochMs());
    } catch {
      skew = 0; // a time zone this browser does not know: fall back to the device's reading
    }
  }
  return { ...answer, clock_skew_minutes: skew };
}

/**
 * The bootstrap query. Structural sharing keeps the parts that did not change (truck, assumptions,
 * calibration, region) referentially stable across refetches, which keeps `TruckContext` stable.
 */
export function useBootstrap<T = BootstrapData>(select?: (data: BootstrapData) => T) {
  return useQuery({
    queryKey: truckKeys.bootstrap(),
    queryFn: fetchBootstrap,
    staleTime: STALE.bootstrap,
    retry: truckRetry,
    select,
  });
}
