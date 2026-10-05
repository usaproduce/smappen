import { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { truckApi, truckKeys, type Plan, type PlanWithStops } from '../../../api/truck';
import { STALE, truckRetry } from './queryPolicy';

/**
 * A row of the plan list read with its stops, as a `Plan`. The list does not carry the server's
 * snapshot, so `result` and `context` are null here whatever the plan's `result_state` says: the
 * screens evaluate plans in the browser, and the two places that need the snapshot read `usePlan`.
 */
export function planFromRow(row: PlanWithStops): Plan {
  return {
    id: row.id,
    date: row.date,
    name: row.name,
    treat_as: row.treat_as,
    notes: row.notes,
    status: row.status,
    stops: row.stops,
    result: null,
    context: null,
    result_state: row.result_state,
    evaluated_at: row.evaluated_at,
    maps_route_url: row.maps_route_url,
    created_at: row.created_at,
    updated_at: row.updated_at,
  };
}

/**
 * The plans of a date range with their stops (route 22 with `stops=1`), in date order. There is at
 * most one plan per date and the range holds at most 92 dates, both ends counted. Today, Week, Log,
 * the planner and the day sheet build their drafts, evaluations and unlogged-stop lists from these.
 */
export function usePlans(from: string, to: string) {
  return useQuery({
    queryKey: truckKeys.plans(from, to, true),
    queryFn: async (): Promise<Plan[]> => {
      const rows = await truckApi.listPlans(from, to, { stops: true });
      return rows.map(planFromRow);
    },
    staleTime: STALE.plans,
    retry: truckRetry,
  });
}

export interface PlanForDate {
  status: 'pending' | 'ready' | 'error';
  /** The plan of that date, or null when the date has none (or while the answer is on its way). */
  plan: Plan | null;
  error: unknown;
  refetch: () => void;
}

/** The plan of one date: the row of that date in a one-day list, or null. */
export function usePlanForDate(date: string): PlanForDate {
  const query = usePlans(date, date);
  const data = query.data;
  const isError = query.isError;
  const error = query.error;
  const refetch = query.refetch;
  return useMemo(
    () => ({
      status: data !== undefined ? ('ready' as const) : isError ? ('error' as const) : ('pending' as const),
      plan: data?.find((plan) => plan.date === date) ?? null,
      error,
      refetch: () => {
        void refetch();
      },
    }),
    [data, isError, error, refetch, date],
  );
}

/**
 * One plan with the server's own snapshot (route 25). Used only where `result` is needed: the
 * development drift check and the Log's estimate for a planned stop, which has to equal the figure
 * the server stores with the service. `result` and `context` are null unless `result_state` is
 * `fresh` or `stale`. A 404 means the plan no longer exists.
 */
export function usePlan(id: string | null | undefined) {
  const known = typeof id === 'string' && id !== '';
  return useQuery({
    queryKey: truckKeys.plan(known ? id : ''),
    queryFn: () => truckApi.getPlan(id as string),
    enabled: known,
    staleTime: STALE.plans,
    retry: truckRetry,
  });
}
