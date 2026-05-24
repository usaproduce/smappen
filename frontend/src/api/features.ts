import { api } from './client';

/**
 * Type-safe API client for the five new features (NF1 Drive-time matrix,
 * NF2 Rebalancer, NF3 Forecast). NF4 (PWA) and NF5 (3D) are purely
 * frontend so don't need API wrappers.
 */

export interface DriveTimeMatrixRequest {
  origins: { lat: number; lng: number; label?: string }[];
  destinations: { lat: number; lng: number; label?: string }[];
  mode?: 'driving-car' | 'cycling-regular' | 'foot-walking';
}

export interface DriveTimeMatrixResponse {
  origins: any[];
  destinations: any[];
  mode: string;
  durations: (number | null)[][];  // seconds
  distances: (number | null)[][];  // meters
  per_origin: Array<{ origin: any; best: number | null; worst: number | null; avg: number | null }>;
}

export interface RebalanceRequest {
  customers: { lat: number; lng: number; revenue: number; name?: string }[];
  target_per_territory?: number;
}

export interface RebalanceResponse {
  target_per_territory: number;
  total_revenue: number;
  imbalance_pct: number;
  territories: Array<{
    id: string; name: string; color: string;
    revenue: number; count: number;
    delta_vs_target: number; delta_pct: number;
  }>;
  unassigned: any[];
  suggestions: Array<{
    customer: { name: string; lat: number; lng: number };
    revenue: number;
    from_territory: string;
    to_territory: string;
    distance_to_target_km: number;
  }>;
}

export interface ForecastRequest {
  training_data: { area_id: string; revenue: number }[];
}

export interface ForecastResponse {
  candidate: { id: string; name: string };
  predicted_revenue: number;
  confidence_low: number;
  confidence_high: number;
  stddev: number;
  k_neighbors: Array<{ area_id: string; name: string; similarity: number; revenue: number }>;
  training_size: number;
}

export const featuresApi = {
  async driveTimeMatrix(body: DriveTimeMatrixRequest): Promise<DriveTimeMatrixResponse> {
    const { data } = await api.post('/api/drive-time-matrix', body);
    return data.data as DriveTimeMatrixResponse;
  },
  async rebalance(projectId: string, body: RebalanceRequest): Promise<RebalanceResponse> {
    const { data } = await api.post(`/api/projects/${projectId}/rebalance`, body);
    return data.data as RebalanceResponse;
  },
  async forecast(areaId: string, body: ForecastRequest): Promise<ForecastResponse> {
    const { data } = await api.post(`/api/areas/${areaId}/forecast`, body);
    return data.data as ForecastResponse;
  },
};
