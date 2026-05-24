import { api } from './client';

/**
 * Analog Finder API — given an area ID, finds the N closest matching census
 * tracts across the loaded geography by similarity of demographic + segment +
 * competition + accessibility profile. Backed by /api/areas/{id}/analogs.
 *
 * The endpoint is rate-limited at 30 calls/hour per user (analog_finder bucket).
 */

export interface RadarData {
  axes: string[];
  source: (number | null)[];
  candidate: (number | null)[];
}

export interface AnalogDemographics {
  population: number | null;
  density_per_sqkm: number | null;
  median_income: number | null;
  median_home_value: number | null;
  dominant_segment: string | null;
}

export interface AnalogCandidate {
  geoid: string;
  name: string;
  state_fips: string;
  county_fips: string;
  lat: number;
  lng: number;
  similarity: number; // 0..1
  demographics: AnalogDemographics;
  radar: RadarData;
}

export interface AnalogResponse {
  source_area_id: string;
  source_area_name: string;
  source_vector: RadarData;
  total_candidates: number;
  results: AnalogCandidate[];
}

export interface AnalogRequest {
  max_results?: number;          // 1..50, default 25
  search_radius_km?: number | null;
  weights?: number[] | null;     // length 18 if provided
}

export const analogsApi = {
  async find(areaId: string, params: AnalogRequest = {}): Promise<AnalogResponse> {
    const { data } = await api.post(`/api/areas/${areaId}/analogs`, params);
    // Server wraps success payloads as { success: true, data: ... } via Response::success.
    return (data?.data ?? data) as AnalogResponse;
  },
};
