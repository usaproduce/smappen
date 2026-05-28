import { api } from './client';

export interface DashboardBriefing {
  generated_at: string;
  bullets: string[];
  source: 'claude' | 'template';
  cached?: boolean;
}

export interface DashboardAlert {
  id: string;
  kind: string;
  config: { metric: string; op: '>' | '<' | '>=' | '<='; value: number; label?: string } | null;
  active: 0 | 1;
  last_fired_at: string | null;
  fire_count: number;
  created_at: string;
}

export interface DashboardAlertInput {
  metric: 'food_cost_pct' | 'labor_cost_pct' | 'prime_cost_pct' | 'revenue_today_cents' | 'open_recs' | 'margin_pct';
  op: '>' | '<' | '>=' | '<=';
  value: number;
  label?: string;
}

export const dashboardApi = {
  async briefing(): Promise<DashboardBriefing> {
    const { data } = await api.get('/api/dashboard/briefing');
    return data.data as DashboardBriefing;
  },
  async alerts(): Promise<DashboardAlert[]> {
    const { data } = await api.get('/api/dashboard/alerts');
    return data.data.alerts ?? [];
  },
  async createAlert(input: DashboardAlertInput): Promise<{ id: string }> {
    const { data } = await api.post('/api/dashboard/alerts', input);
    return data.data;
  },
  async deleteAlert(id: string): Promise<void> {
    await api.delete(`/api/dashboard/alerts/${id}`);
  },
};
