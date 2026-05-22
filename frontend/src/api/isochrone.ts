import { api } from './client';
import type { IsochroneResult, TravelMode } from '../types';

export const isochroneApi = {
  async calculate(payload: {
    lat: number;
    lng: number;
    time_minutes?: number;
    travel_mode?: TravelMode;
    type?: 'isochrone' | 'radius';
    radius_km?: number;
  }) {
    const { data } = await api.post('/api/isochrone/calculate', payload);
    return data.data as IsochroneResult;
  },
};
