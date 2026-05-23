import { api } from './client';
import type { GeoJSONPolygon } from '../types';

export interface ReachResult {
  geometry: GeoJSONPolygon;
  center: { lat: number; lng: number };
  radius_km: number;
  radius_mi: number;
  area_sq_km: number;
  population: number;
  target_population: number;
}

export interface DemoPreview {
  population: number;
  median_household_income: number | null;
  tracts_intersected: number;
  area_sq_km: number;
  density_per_sq_km: number;
}

export const reachApi = {
  async calculate(lat: number, lng: number, target_population: number) {
    const { data } = await api.post('/api/areas/reach', { lat, lng, target_population });
    return data.data as ReachResult;
  },
  async previewGeometry(geometry: GeoJSONPolygon) {
    const { data } = await api.post('/api/demographics/preview', { geometry });
    return data.data as DemoPreview;
  },
};
