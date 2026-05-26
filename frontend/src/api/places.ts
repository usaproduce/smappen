import { api } from './client';
import type { Place } from '../types';

export const placesApi = {
  async nearby(payload: {
    lat: number;
    lng: number;
    radius_meters?: number;
    type?: string;
    keyword?: string;
    area_id?: string;
  }) {
    const { data } = await api.post('/api/places/nearby', payload);
    return data.data as {
      places: Place[];
      count: number;
      // Concentration metadata — populated when area_id resolves to an
      // area with geometry. Lets the panel show "16 cafes · Dense · 1.4
      // per km²" instead of just a raw count.
      area_sq_km?: number | null;
      area_population?: number | null;
      density_per_sq_km?: number | null;
      density_per_1k_people?: number | null;
      density_label?: 'Sparse' | 'Moderate' | 'Dense' | 'Very dense' | null;
    };
  },
  async search(query: string, lat?: number, lng?: number, radius_meters?: number) {
    const { data } = await api.post('/api/places/search', { query, lat, lng, radius_meters });
    return data.data as { places: Place[]; count: number };
  },
  async details(placeId: string) {
    const { data } = await api.get(`/api/places/${placeId}`);
    return data.data as Place;
  },
};
