import { create } from 'zustand';

/**
 * Carafe restaurant store. Mirrors projectStore.ts shape — the canonical
 * Smappen pattern for a per-tenant domain entity + its derived collections.
 */

export interface Restaurant {
  id: string;
  organization_id: string;
  name: string;
  address: string | null;
  lat: number | null;
  lng: number | null;
  timezone: string | null;
  region: string | null;
  is_sample: number;
  created_at: string;
}

export interface MenuItem {
  id: string;
  name: string;
  category: string | null;
  price_cents: number;
  recipe_id: string | null;
  is_active: number;
  pos_provider: string | null;
  pos_item_id: string | null;
  last_synced_at: string | null;
  true_cost_cents: number | null;
  coverage_pct: number | null;
  cost_computed_at: string | null;
  margin_cents: number | null;
  margin_pct: number | null;
}

export interface Recommendation {
  id: string;
  menu_item_id: string | null;
  kind: 'price_raise' | 'price_lower' | 'reposition' | 'reprice' | 'cut';
  payload: Record<string, any> | null;
  narrative: string | null;
  dollar_estimate_cents: number;
  status: 'suggested' | 'accepted' | 'dismissed' | 'measured';
  measured_impact_cents: number | null;
  created_at: string;
  decided_at: string | null;
  measured_at: string | null;
}

interface RestaurantState {
  currentRestaurant: Restaurant | null;
  restaurants: Restaurant[];
  menuItems: MenuItem[];
  recommendations: Recommendation[];
  setCurrentRestaurant: (r: Restaurant | null) => void;
  setRestaurants: (rs: Restaurant[]) => void;
  setMenuItems: (m: MenuItem[]) => void;
  setRecommendations: (r: Recommendation[]) => void;
  updateRecommendationStatus: (id: string, status: Recommendation['status']) => void;
}

export const useRestaurantStore = create<RestaurantState>((set) => ({
  currentRestaurant: null,
  restaurants: [],
  menuItems: [],
  recommendations: [],
  setCurrentRestaurant: (currentRestaurant) => set({ currentRestaurant }),
  setRestaurants: (restaurants) => set({ restaurants }),
  setMenuItems: (menuItems) => set({ menuItems }),
  setRecommendations: (recommendations) => set({ recommendations }),
  updateRecommendationStatus: (id, status) =>
    set((s) => ({
      recommendations: s.recommendations.map((r) =>
        r.id === id ? { ...r, status, decided_at: new Date().toISOString() } : r
      ),
    })),
}));
