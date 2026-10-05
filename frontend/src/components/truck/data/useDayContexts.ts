import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { truckApi, truckKeys, type FuelInfo } from '../../../api/truck';
import { addDays } from '../../../utils/truck/model';
import type { DayContext, TreatAs } from '../../../utils/truck/model';
import { buildContext, degradedContext, sortedJson } from '../../../utils/truck/assemble';
import { useTruck } from './TruckContext';
import { useNow } from './useNow';
import { GC, STALE, truckRetry } from './queryPolicy';

export interface ForecastState {
  state: 'fresh' | 'stale' | 'unavailable';
  generated_at: string | null;
  /** The server's reading of `generated_at` in the truck's time zone. */
  generated_local: { date: string; minute: number } | null;
}

export interface DayContexts {
  /**
   * `pending`: the answer is on its way and `contexts` is empty. `ready`: one context per date.
   * `degraded`: the request failed; every date still has a context, without a forecast and with the
   * fuel price of the bootstrap answer, so estimates render with no weather adjustment.
   */
  status: 'pending' | 'ready' | 'degraded';
  /** Key = date. */
  contexts: Record<string, DayContext>;
  forecast: ForecastState;
  fuel: FuelInfo;
  refetch: () => void;
}

const NO_FORECAST: ForecastState = { state: 'unavailable', generated_at: null, generated_local: null };

/**
 * Holiday, hourly forecast and fuel price for `days` civil dates from `from` (route 17), as model
 * contexts. `days` is 1 to 14. The forecast is for the area around the truck's base.
 *
 * Contexts are always rebuilt in the browser, so "Treat this day as" is instant: `treatAsByDate`
 * gives the override of each date that has one, every other date is automatic. The override belongs
 * to one civil date; a window that closes after midnight takes the next date's own context.
 */
export function useDayContexts(
  from: string,
  days: number,
  treatAsByDate?: Readonly<Record<string, TreatAs | null | undefined>>,
): DayContexts {
  const { A, fuel: bootstrapFuel } = useTruck();
  const today = useNow().date;
  const to = addDays(from, days - 1);
  // Forecasts change hourly and reach about six days ahead; beyond that there is none to refresh.
  const near = from <= addDays(today, 6) && to >= today;

  const query = useQuery({
    queryKey: truckKeys.dayContext(from, days),
    queryFn: () => truckApi.dayContext(from, days),
    staleTime: near ? STALE.dayContextNear : STALE.dayContextFar,
    gcTime: GC.dayContext,
    retry: truckRetry,
  });

  const treatKey = sortedJson(treatAsByDate ?? {});
  const data = query.data;
  const failed = data === undefined && query.isError;
  const refetch = query.refetch;

  return useMemo((): DayContexts => {
    const treat = JSON.parse(treatKey) as Record<string, TreatAs | null>;
    const treatOf = (date: string): TreatAs | null => treat[date] ?? null;
    const contexts: Record<string, DayContext> = {};
    const again = () => {
      void refetch();
    };
    if (data !== undefined) {
      for (const day of data.days) contexts[day.date] = buildContext(A, day, treatOf(day.date));
      return {
        status: 'ready',
        contexts,
        forecast: {
          state: data.forecast.state,
          generated_at: data.forecast.generated_at,
          generated_local: data.forecast.generated_local,
        },
        fuel: data.fuel,
        refetch: again,
      };
    }
    if (failed) {
      for (let i = 0; i < days; i++) {
        const date = addDays(from, i);
        contexts[date] = degradedContext(A, date, treatOf(date), bootstrapFuel);
      }
      return { status: 'degraded', contexts, forecast: NO_FORECAST, fuel: bootstrapFuel, refetch: again };
    }
    return { status: 'pending', contexts, forecast: NO_FORECAST, fuel: bootstrapFuel, refetch: again };
  }, [A, bootstrapFuel, data, failed, from, days, treatKey, refetch]);
}
