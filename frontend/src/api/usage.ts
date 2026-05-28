import { api } from './client';

export interface UsageToday {
  date: string;
  total_usd: number;
  call_count: number;
  breakdown: { api_name: string; calls: number; cost_usd: number }[];
}

export interface UsageDay {
  day: string;        // YYYY-MM-DD
  cost_usd: number;
  calls: number;
}

export const usageApi = {
  async today(): Promise<UsageToday> {
    const { data } = await api.get('/api/usage/today');
    return data.data;
  },
  async days(): Promise<UsageDay[]> {
    const { data } = await api.get('/api/usage/days');
    return (data.data?.days ?? []) as UsageDay[];
  },
  async pricing(): Promise<Record<string, number>> {
    const { data } = await api.get('/api/usage/pricing');
    return data.data.prices;
  },
  // Fire once per Maps JS session (top-level <GoogleMap> mount).
  async logMapLoad(): Promise<void> {
    try { await api.post('/api/usage/log-map-load', {}); } catch { /* non-critical */ }
  },
};

/**
 * Format USD for tight spaces. Sub-cent → "<$0.01" so the badge isn't misleading.
 */
export function formatUsd(n: number): string {
  if (n <= 0) return '$0.00';
  if (n < 0.01) return '<$0.01';
  if (n < 100) return '$' + n.toFixed(2);
  return '$' + Math.round(n).toLocaleString();
}
