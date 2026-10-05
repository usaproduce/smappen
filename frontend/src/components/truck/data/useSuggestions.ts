import { useQuery } from '@tanstack/react-query';
import { truckApi, truckKeys, type SuggestDayBody, type SuggestWeekBody } from '../../../api/truck';
import { sortedJson } from '../../../utils/truck/assemble';
import { GC, STALE, truckRetry } from './queryPolicy';

/**
 * Suggested days for one date (route 29), shown as returned. The two suggestion routes share a limit
 * of 30 requests an hour, so pass `enabled` only while the panel is open; the answer is kept for
 * five minutes and never refetched on focus.
 */
export function useSuggestDay(body: SuggestDayBody, enabled: boolean) {
  const { date, ...rest } = body;
  const optionsKey = sortedJson(rest);
  return useQuery({
    queryKey: truckKeys.suggestDay(date, optionsKey),
    queryFn: () => truckApi.suggestDay({ date, ...(JSON.parse(optionsKey) as Omit<SuggestDayBody, 'date'>) }),
    enabled,
    staleTime: STALE.suggest,
    gcTime: GC.suggest,
    refetchOnWindowFocus: false,
    retry: truckRetry,
  });
}

/** A suggested week (route 30), shown as returned. `week_start` must be a Monday. Same limits as `useSuggestDay`. */
export function useSuggestWeek(body: SuggestWeekBody, enabled: boolean) {
  const { week_start: weekStart, ...rest } = body;
  const optionsKey = sortedJson(rest);
  return useQuery({
    queryKey: truckKeys.suggestWeek(weekStart, optionsKey),
    queryFn: () =>
      truckApi.suggestWeek({
        week_start: weekStart,
        ...(JSON.parse(optionsKey) as Omit<SuggestWeekBody, 'week_start'>),
      }),
    enabled,
    staleTime: STALE.suggest,
    gcTime: GC.suggest,
    refetchOnWindowFocus: false,
    retry: truckRetry,
  });
}
