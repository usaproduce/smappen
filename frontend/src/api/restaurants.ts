import { api } from './client';
import type { Restaurant, MenuItem, Recommendation } from '../stores/restaurantStore';

export type { MenuItem } from '../stores/restaurantStore';

export interface ImportBatch { batch_id: string; }

export interface Recipe {
  id: string;
  name: string;
  notes: string | null;
  created_at: string;
  updated_at: string;
  ingredient_count: number;
  linked_menu_items: number;
}

export interface RecipeIngredient {
  id: string;
  ingredient_key: string;
  qty: number;
  unit: string;
  notes: string | null;
}

export interface RecipeWithIngredients {
  id: string;
  organization_id: string;
  restaurant_id: string;
  name: string;
  notes: string | null;
  ingredients: RecipeIngredient[];
}

export interface IngredientCatalogItem {
  ingredient_key: string;
  region: string | null;
  market_price_cents: number;
  unit: string;
  source: string;
  as_of: string;
}

export interface PastePreviewRow {
  ingredient_key: string;
  qty: number;
  unit: string;
  status: 'ok' | 'warning' | 'error';
  message: string | null;
  raw_ingredient: string;
  line: number;
}

export interface PastePreviewGroup {
  item_name: string;
  normalized_name: string;
  rows: PastePreviewRow[];
  row_count: number;
  ok_count: number;
  warning_count: number;
  error_count: number;
}

export interface PastePreviewResult {
  groups: PastePreviewGroup[];
  summary: {
    total_rows: number;
    ok: number;
    warnings: number;
    errors: number;
    recipes: number;
  };
}

export interface PasteCommitResult {
  created: Array<{
    recipe_id: string;
    name: string;
    ingredient_count: number;
    linked_menu_item_id: string | null;
  }>;
  created_count: number;
  linked_count: number;
  skipped: Array<{ item_name: string; reason: string }>;
  plate_costs_recomputed: number | null;
}

export interface SuggestedRecipeIngredient {
  ingredient_key: string;
  qty: number;
  unit: string;
  benchmark: {
    market_price_cents: number;
    unit: string;
    source: string;
  } | null;
}

export interface SuggestedRecipe {
  name: string;
  category: string | null;
  ingredients: SuggestedRecipeIngredient[];
  matched: boolean;
  source_key: string | null;
}

export interface IngredientSuggestion {
  key: string;
  has_benchmark: boolean;
  market_price_cents: number | null;
  unit: string | null;
  own_freq: number;
  org_freq: number;
  match_score: number;
}

export const restaurantsApi = {
  async list(): Promise<Restaurant[]> {
    const { data } = await api.get('/api/restaurants');
    return data.data.restaurants ?? [];
  },
  async show(id: string): Promise<Restaurant> {
    const { data } = await api.get(`/api/restaurants/${id}`);
    return data.data.restaurant;
  },
  async create(input: {
    name: string;
    address?: string;
    lat?: number;
    lng?: number;
    timezone?: string;
    region?: string;
    google_place_id?: string;
    phone?: string;
    website?: string;
  }): Promise<{ id: string; already_exists?: boolean }> {
    const { data } = await api.post('/api/restaurants', input);
    return data.data;
  },
  async archive(id: string): Promise<void> {
    await api.delete(`/api/restaurants/${id}`);
  },
  async cloneSample(): Promise<{ id: string }> {
    const { data } = await api.post('/api/onboarding/clone-sample-restaurant');
    return data.data;
  },
};

export const posApi = {
  async listIntegrations(restaurantId: string): Promise<Array<{ provider: string; connected_at: string; last_synced_at: string | null }>> {
    const { data } = await api.get(`/api/restaurants/${restaurantId}/pos`);
    return data.data.integrations ?? [];
  },
  async connect(restaurantId: string, provider: 'square'): Promise<{ auth_url: string }> {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/pos/${provider}/connect`);
    return data.data;
  },
  async sync(restaurantId: string, provider: 'square'): Promise<{ job_id: string }> {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/pos/${provider}/sync`);
    return data.data;
  },
};

export const menuApi = {
  async listItems(restaurantId: string): Promise<MenuItem[]> {
    const { data } = await api.get(`/api/restaurants/${restaurantId}/menu`);
    return data.data.items ?? [];
  },
  async createItem(restaurantId: string, input: { name: string; category?: string; price_cents: number; recipe_id?: string | null }): Promise<{ id: string }> {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/menu`, input);
    return data.data;
  },
  async setPrice(itemId: string, priceCents: number): Promise<void> {
    await api.put(`/api/menu-items/${itemId}/price`, { price_cents: priceCents });
  },
  async setRecipe(itemId: string, recipeId: string | null): Promise<void> {
    await api.put(`/api/menu-items/${itemId}/recipe`, { recipe_id: recipeId });
  },
  async createRecipe(restaurantId: string, name: string, notes?: string): Promise<{ id: string }> {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/recipes`, { name, notes });
    return data.data;
  },
  async listRecipes(restaurantId: string): Promise<Recipe[]> {
    const { data } = await api.get(`/api/restaurants/${restaurantId}/recipes`);
    return data.data.recipes ?? [];
  },
  async showRecipe(id: string): Promise<RecipeWithIngredients> {
    const { data } = await api.get(`/api/recipes/${id}`);
    return data.data.recipe;
  },
  async addIngredient(recipeId: string, ingredient: { ingredient_key: string; qty: number; unit: string; notes?: string }): Promise<{ id: string }> {
    const { data } = await api.post(`/api/recipes/${recipeId}/ingredients`, ingredient);
    return data.data;
  },
  async removeIngredient(ingredientId: string): Promise<void> {
    await api.delete(`/api/recipe-ingredients/${ingredientId}`);
  },
  async ingredientCatalog(region?: string): Promise<IngredientCatalogItem[]> {
    const q = region ? { region } : undefined;
    const { data } = await api.get('/api/ingredient-catalog', { params: q });
    return data.data.ingredients ?? [];
  },
  async recomputePlateCosts(restaurantId: string): Promise<{ recomputed: number }> {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/plate-costs/recompute`);
    return data.data;
  },
  async previewPaste(restaurantId: string, text: string): Promise<PastePreviewResult> {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/recipes/paste/preview`, { text });
    return data.data;
  },
  async commitPaste(restaurantId: string, text: string, includeWarnings = true): Promise<PasteCommitResult> {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/recipes/paste/commit`, {
      text,
      include_warnings: includeWarnings,
    });
    return data.data;
  },
  async suggestRecipe(restaurantId: string, name: string, category?: string | null): Promise<SuggestedRecipe> {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/recipes/suggest`, {
      name,
      category: category ?? undefined,
    });
    return data.data.draft;
  },
  async ingredientAutocomplete(restaurantId: string, q: string, limit = 30): Promise<IngredientSuggestion[]> {
    const { data } = await api.get(`/api/restaurants/${restaurantId}/ingredient-autocomplete`, {
      params: { q, limit },
    });
    return data.data.suggestions ?? [];
  },
};

export interface RoiMonthly {
  month_start: string;
  measured_cents: number;
  pending_cents: number;
  accepted_count: number;
  found_cents: number;
}

export const roiApi = {
  async monthly(restaurantId: string, monthIso?: string): Promise<RoiMonthly> {
    const q = monthIso ? `?month=${encodeURIComponent(monthIso)}` : '';
    const { data } = await api.get(`/api/restaurants/${restaurantId}/roi/monthly${q}`);
    return data.data;
  },
};

// War-room aggregate — bundles ROI, today's service, POS, freshness,
// the highest-dollar Top Move, and the recent-digest callout into a
// single round-trip so RestaurantOverviewPage can hit Lighthouse ≥ 90.
export interface OverviewRoi extends RoiMonthly {
  last_updated_at: string | null;
}

export interface OverviewTrendPoint {
  month_start: string;
  found_cents: number;
}

export interface OverviewTodayService {
  date: string;
  covers: number;
  revenue_cents: number;
  revenue_per_cover_cents: number | null;
  food_cost_pct: number | null;
  last_sale_at: string | null;
  note: string | null;
}

export interface OverviewPos {
  connected: boolean;
  provider: string | null;
  connected_at: string | null;
  last_synced_at: string | null;
  last_sale_at: string | null;
  integrations: Array<{ provider: string; connected_at?: string; last_synced_at: string | null }>;
}

export interface OverviewUsdaPrices {
  as_of: string;
  updated_at: string | null;
}

export interface OverviewDigest {
  sent_at: string;
  week_start: string;
  rec_count: number;
  total_cents: number;
  rec_ids: string[];
}

export interface OverviewTopMove {
  id: string;
  menu_item_id: string | null;
  menu_item_name: string | null;
  kind: Recommendation['kind'];
  payload: Record<string, any> | null;
  narrative: string | null;
  dollar_estimate_cents: number;
  created_at: string;
  menu_item_price_cents: number | null;
  plate_cost_cents: number | null;
}

export interface OverviewGoals {
  food_cost_pct_target: number | null;
  food_cost_pct_warn: number;
  food_cost_pct_good: number;
}

export interface OverviewPayload {
  roi: OverviewRoi;
  roi_trend: OverviewTrendPoint[];
  today_service: OverviewTodayService | null;
  pos: OverviewPos;
  usda_prices: OverviewUsdaPrices | null;
  digest: OverviewDigest | null;
  top_move: OverviewTopMove | null;
  next_moves: OverviewTopMove[];
  open_recs_count: number;
  goals: OverviewGoals;
}

export const overviewApi = {
  async get(restaurantId: string): Promise<OverviewPayload> {
    const { data } = await api.get(`/api/restaurants/${restaurantId}/overview`);
    return data.data;
  },
};

export type GoalMetric = 'food_cost_pct' | 'avg_check_cents' | 'margin_pct' | 'weekly_revenue_cents';
export type GoalCadence = 'weekly' | 'monthly' | 'quarterly';

export interface Goal {
  id: string;
  metric: GoalMetric;
  target_value: number;
  cadence: GoalCadence;
  label: string | null;
  is_active: number;
  created_at: string;
  recent_snapshots: Array<{ period_start: string; period_end: string; actual_value: number }>;
}

export const goalsApi = {
  async list(restaurantId: string): Promise<Goal[]> {
    const { data } = await api.get(`/api/restaurants/${restaurantId}/goals`);
    return data.data.goals ?? [];
  },
  async create(restaurantId: string, input: { metric: GoalMetric; target_value: number; cadence: GoalCadence; label?: string }) {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/goals`, input);
    return data.data;
  },
  async snapshot(goalId: string) {
    const { data } = await api.post(`/api/goals/${goalId}/snapshot`);
    return data.data;
  },
  async destroy(goalId: string) {
    await api.delete(`/api/goals/${goalId}`);
  },
};

export interface CogsBenchmarkFreshness {
  source: string;
  region: string | null;
  as_of: string;
  last_ingested_at: string;
  rows: number;
}

export interface FoodCostTheoretical {
  period_start: string;
  period_end: string;
  theoretical_cost_cents: number;
  revenue_cents: number;
  theoretical_pct: number;
  coverage_pct: number;
  top_contributors: Array<{ menu_item_id: string; name: string; qty_sold: number; cost_cents: number; revenue_cents: number }>;
  note: string;
  benchmark_freshness?: CogsBenchmarkFreshness[];
  benchmark_is_live?: boolean;
}

export const foodCostApi = {
  async theoretical(restaurantId: string, opts?: { start?: string; end?: string }): Promise<FoodCostTheoretical> {
    const { data } = await api.get(`/api/restaurants/${restaurantId}/food-cost/theoretical`, { params: opts });
    return data.data;
  },
};

export interface LaborAnalysisHour {
  date: string;
  hour: number;
  revenue_cents: number;
  units: number;
  orders: number;
  covers: number;
  labor_cost_cents: number;
  revenue_per_cover: number;
}

export interface LaborAnalysis {
  window: { start: string; end: string };
  median_rpc: number;
  hours: LaborAnalysisHour[];
  understaffed: Array<LaborAnalysisHour & { note: string }>;
  overstaffed: Array<LaborAnalysisHour & { note: string }>;
  slow_windows: Array<{ date: string; hour: number; revenue_cents: number; suggestion: string }>;
}

export const laborApi = {
  async analysis(restaurantId: string, opts?: { start?: string; end?: string }): Promise<LaborAnalysis> {
    const { data } = await api.get(`/api/restaurants/${restaurantId}/labor/analysis`, { params: opts });
    return data.data;
  },
  async createShift(restaurantId: string, input: { starts_at: string; ends_at?: string; employee_label?: string; role?: string; hourly_wage_cents?: number }) {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/labor/shifts`, input);
    return data.data;
  },
};

export const recommendationsApi = {
  async list(restaurantId: string, status?: Recommendation['status']): Promise<Recommendation[]> {
    const q = status ? `?status=${encodeURIComponent(status)}` : '';
    const { data } = await api.get(`/api/restaurants/${restaurantId}/recommendations${q}`);
    return data.data.recommendations ?? [];
  },
  async runForItem(itemId: string): Promise<{ created: boolean; recommendation_id?: string }> {
    const { data } = await api.post(`/api/menu-items/${itemId}/recommend`);
    return data.data;
  },
  async runForRestaurant(restaurantId: string): Promise<{ created_count: number }> {
    const { data } = await api.post(`/api/restaurants/${restaurantId}/recommendations/run`);
    return data.data;
  },
  async accept(id: string): Promise<void> {
    await api.post(`/api/recommendations/${id}/accept`);
  },
  async dismiss(id: string): Promise<void> {
    await api.post(`/api/recommendations/${id}/dismiss`);
  },
};
