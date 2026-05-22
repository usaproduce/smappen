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
    return data.data as { places: Place[]; count: number };
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
