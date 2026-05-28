import { api } from './client';

// ── Map dashboard (now demoted to a single card) ────────────────────────
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
  id: string; name: string; project_name: string;
  population: number; area_sq_km: number;
}
export interface DashboardRecentArea {
  id: string; name: string; area_type: string; updated_at: string;
  project_id: string; project_name: string;
}
export interface DashboardStats {
  totals: DashboardTotals;
  averages: DashboardAverages;
  areas_by_type: Record<string, number>;
  travel_mode: Record<string, number>;
  top_areas: DashboardTopArea[];
  recent_areas: DashboardRecentArea[];
}

// ── Restaurant-first org overview ───────────────────────────────────────
export type RangeKey = 'mtd' | 'wtd' | 'ytd' | '7d' | '30d' | 'today';

export interface RestaurantsOverviewTotals {
  restaurants_total: number;
  restaurants_active: number;
  pos_connected_restaurants: number;
  menu_items_active: number;
  menu_items_with_plate_cost: number;
  menu_coverage_pct: number | null;
  open_recommendations: number;
  open_recommendations_cents: number;
  recommendations_mtd: number;
}
export interface RestaurantsOverviewToday {
  revenue_cents: number;
  covers: number;
  avg_ticket_cents: number | null;
  sale_lines: number;
  last_sale_at: string | null;
}
export interface RestaurantsOverviewMtd {
  revenue_cents: number;
  covers: number;
  food_cost_pct: number | null;
  food_cost_cents: number;
  labor_cost_cents: number;
  labor_cost_pct: number | null;
  prime_cost_pct: number | null;
  carafe_found_cents: number;
  carafe_measured_cents: number;
  carafe_accepted_cents: number;
}
export interface RestaurantsOverviewRecRow {
  id: string;
  restaurant_id: string;
  restaurant_name: string;
  menu_item_id: string | null;
  menu_item_name: string | null;
  kind: string;
  narrative: string | null;
  dollar_estimate_cents: number;
  created_at: string;
}
export interface RestaurantsOverviewRow {
  id: string;
  name: string;
  address: string | null;
  pos_connected: boolean;
  last_sale_at: string | null;
  revenue_today_cents: number;
  revenue_mtd_cents: number;
  covers_mtd: number;
  open_recs: number;
  food_cost_cents: number;
  labor_cost_cents: number;
  food_cost_pct: number | null;
  labor_cost_pct: number | null;
  prime_cost_pct: number | null;
  margin_pct: number | null;
}
export interface RestaurantsOverviewTopItem {
  id: string; name: string; category: string | null; restaurant_name: string;
  revenue_cents: number; units_sold: number;
}
export interface RestaurantsOverviewAttention {
  id: string; name: string; reason: string; last_sale_at: string | null;
}
export interface RestaurantsOverviewAnomaly {
  kind: string; label: string; detail: string; magnitude: number;
}
export interface RestaurantsOverviewVelocity {
  id: string; name: string; restaurant_name: string;
  cur_rev_cents: number; prev_rev_cents: number;
  cur_units: number; prev_units: number;
  delta_pct: number | null;
}
export interface RestaurantsOverviewLeaderboardRow {
  id: string; name: string;
  margin_pct: number | null;
  food_pct: number | null;
  labor_pct: number | null;
  revenue_cents: number;
}
export interface RestaurantsOverviewForecast {
  eod_revenue_cents: number | null;
  eom_revenue_cents: number | null;
  eom_food_cost_cents: number | null;
  eom_labor_cost_cents: number | null;
  next_week: { day: string; dow_label: string; projected_cents: number }[];
  next_week_total_cents: number;
  scheduled_labor_next7_cents: number;
  scheduled_labor_pct_of_forecast: number | null;
}
export interface RestaurantsOverviewGoal {
  metric: 'food_cost_pct' | 'avg_check_cents' | 'margin_pct' | 'weekly_revenue_cents';
  target_value: number;
  actual_value: number | null;
  restaurant_count: number;
}
export interface RestaurantsOverviewPreShiftItem {
  kind: string; label: string; priority: 'high' | 'medium' | 'low';
}
export interface RestaurantsOverview {
  range: RangeKey;
  totals: RestaurantsOverviewTotals;
  today: RestaurantsOverviewToday;
  mtd: RestaurantsOverviewMtd;
  last_7d: { revenue_cents: number; covers: number };
  previous: { revenue_cents: number; food_cost_pct: number | null; labor_cost_pct: number | null; prime_cost_pct: number | null };
  baseline_28d: {
    mean_revenue_cents: number; stddev_cents: number;
    today_revenue_cents: number; today_z: number | null; today_pct_deviation: number | null;
  };
  anomalies: RestaurantsOverviewAnomaly[];
  recommendation_funnel: Record<'suggested' | 'accepted' | 'dismissed' | 'measured', number>;
  top_recommendations: RestaurantsOverviewRecRow[];
  restaurants: RestaurantsOverviewRow[];
  top_menu_items: RestaurantsOverviewTopItem[];
  sales_by_category: { category: string; revenue_cents: number }[];
  sales_by_daypart: { daypart: string; revenue_cents: number; sale_lines: number }[];
  daily_revenue_14d: { day: string; revenue_cents: number; covers: number }[];
  carafe_roi_6mo: { month_start: string; found_cents: number }[];
  needs_attention: RestaurantsOverviewAttention[];
  item_velocity: RestaurantsOverviewVelocity[];
  cost_drivers: { ingredient_key: string; total_units: number; cost_cents: number }[];
  recipe_drift: { id: string; name: string; restaurant_name: string; true_cost_cents: number; computed_at: string; coverage_pct: number }[];
  leaderboard: RestaurantsOverviewLeaderboardRow[];
  variance_decomposition: {
    prime_delta_pct: number | null;
    food_delta_pct: number | null;
    labor_delta_pct: number | null;
  };
  forecast: RestaurantsOverviewForecast;
  goals: RestaurantsOverviewGoal[];
  industry_benchmarks: {
    food_cost_pct: number; labor_cost_pct: number; prime_cost_pct: number;
    margin_pct: number; source: string;
  };
  pre_shift: RestaurantsOverviewPreShiftItem[];
  active_alerts: number;
}

export const statsApi = {
  async dashboard(): Promise<DashboardStats> {
    const { data } = await api.get('/api/stats/dashboard');
    return data.data as DashboardStats;
  },
  async restaurantsOverview(range: RangeKey = 'mtd'): Promise<RestaurantsOverview> {
    const { data } = await api.get('/api/stats/restaurants-overview', { params: { range } });
    return data.data as RestaurantsOverview;
  },
};
