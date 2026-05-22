import { useQuery } from '@tanstack/react-query';
import { billingApi } from '../api/billing';

export function usePlanLimits() {
  const { data, isLoading } = useQuery({
    queryKey: ['subscription'],
    queryFn: () => billingApi.subscription(),
    staleTime: 60_000,
  });

  return {
    isLoading,
    plan: data?.plan ?? 'free',
    limits: data?.limits ?? {},
    usage: data?.usage,
    canUseFeature(name: string) {
      const v = (data?.limits ?? {})[name];
      return v === true || (typeof v === 'number' && v !== 0);
    },
    isAtLimit(name: 'max_isochrones_per_day' | 'max_poi_searches_per_day') {
      const key = name === 'max_isochrones_per_day' ? 'isochrones_remaining_today' : 'poi_searches_remaining_today';
      const remaining = (data?.usage as any)?.[key] ?? -1;
      return remaining === 0;
    },
  };
}
