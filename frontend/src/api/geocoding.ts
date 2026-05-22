import { api } from './client';

export const geocodingApi = {
  async geocode(address: string) {
    const { data } = await api.post('/api/geocode', { address });
    return data.data as {
      lat: number; lng: number; formatted_address: string;
      place_id?: string; components: Record<string, string | null>;
    };
  },
  async batchGeocode(addresses: string[]) {
    const { data } = await api.post('/api/geocode/batch', { addresses });
    return data.data as {
      results: any[];
      success_count: number;
      failure_count: number;
      failures: any[];
    };
  },
};
