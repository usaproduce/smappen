import { api } from './client';

export interface DashboardTotals {
  projects: number;
  areas: number;
  population: number;
  area_sq_km: number;
  folders: number;
  reports: number;
  shared_projects: number;
  saved_comparisons: number;
  saved_searches: number;
}

export interface DashboardAverages {
  median_income: number | null;
  density_per_sq_km: number | null;
  unemployment_rate: number | null;
}

export interface DashboardTopArea {
  id: string;
  name: string;
  project_name: string;
  population: number;
  area_sq_km: number;
}

export interface DashboardRecentArea {
  id: string;
  name: string;
  area_type: string;
  updated_at: string;
  project_id: string;
  project_name: string;
}

export interface DashboardStats {
  totals: DashboardTotals;
  averages: DashboardAverages;
  areas_by_type: Record<string, number>;
  travel_mode: Record<string, number>;
  top_areas: DashboardTopArea[];
  recent_areas: DashboardRecentArea[];
}

export const statsApi = {
  async dashboard(): Promise<DashboardStats> {
    const { data } = await api.get('/api/stats/dashboard');
    return data.data as DashboardStats;
  },
};
