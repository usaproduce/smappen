import { api } from './client';
import type { Restaurant, MenuItem, Recommendation } from '../stores/restaurantStore';

export interface ImportBatch { batch_id: string; }

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
  async addIngredient(recipeId: string, ingredient: { ingredient_key: string; qty: number; unit: string; notes?: string }): Promise<{ id: string }> {
    const { data } = await api.post(`/api/recipes/${recipeId}/ingredients`, ingredient);
    return data.data;
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
