import { api } from './client';

export type HeatmapMetric =
  | 'population'
  | 'population_density'
  | 'median_income'
  | 'median_home_value'
  | 'unemployment_rate'
  | 'housing_units';

export interface HeatmapFeature {
  type: 'Feature';
  id: string;
  geometry: any;
  properties: { geoid: string; name: string | null; value: number | null };
}

export interface HeatmapResponse {
  type: 'FeatureCollection';
  features: HeatmapFeature[];
  meta: {
    metric: HeatmapMetric;
    count: number;
    min: number;
    max: number;
    breaks?: number[];
    unit: string;
    note?: string;
  };
}

export const heatmapApi = {
  async tracts(bbox: [number, number, number, number], metric: HeatmapMetric, limit = 1000) {
    const { data } = await api.get('/api/heatmap/tracts', {
      params: { bbox: bbox.join(','), metric, limit },
    });
    return data.data as HeatmapResponse;
  },
};
