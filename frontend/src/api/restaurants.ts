import { api } from './client';
import type { Restaurant, MenuItem, Recommendation } from '../stores/restaurantStore';

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

export const restaurantsApi = {
  async list(): Promise<Restaurant[]> {
    const { data } = await api.get('/api/restaurants');
    return data.data.restaurants ?? [];
  },
  async show(id: string): Promise<Restaurant> {
    const { data } = await api.get(`/api/restaurants/${id}`);
    return data.data.restaurant;
  },
  async create(input: { name: string; address?: string; lat?: number; lng?: number; timezone?: string; region?: string }): Promise<{ id: string }> {
    const { data } = await api.post('/api/restaurants', input);
    return data.data;
  },
  async archive(id: string): Promise<void> {
    await api.delete(`/api/restaurants/${id}`);
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

export interface FoodCostTheoretical {
  period_start: string;
  period_end: string;
  theoretical_cost_cents: number;
  revenue_cents: number;
  theoretical_pct: number;
  coverage_pct: number;
  top_contributors: Array<{ menu_item_id: string; name: string; qty_sold: number; cost_cents: number; revenue_cents: number }>;
  note: string;
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
