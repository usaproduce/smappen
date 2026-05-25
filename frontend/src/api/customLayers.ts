import { api } from './client';

export interface CustomLayer {
  id: string;
  organization_id: string;
  project_id: string;
  name: string;
  kind: 'point' | 'heatmap';
  source_import_batch: string | null;
  metric_column: string | null;
  palette_id: string;
  radius_meters: number;
  visible: number; // 0 | 1 from MySQL TINYINT
  created_at: string;
}

export interface CustomLayerPoint {
  id: string;
  lat: number;
  lng: number;
  label: string | null;
  meta: Record<string, any>;
}

export interface ImportBatch {
  batch_id: string;
  point_count: number;
  first_imported_at: string;
  last_imported_at: string;
  sample_label: string | null;
}

export interface CreateLayerInput {
  name: string;
  kind?: 'point' | 'heatmap';
  source_import_batch?: string | null;
  metric_column?: string | null;
  palette_id?: string;
  radius_meters?: number;
}

export interface UpdateLayerInput {
  name?: string;
  kind?: 'point' | 'heatmap';
  metric_column?: string | null;
  palette_id?: string;
  radius_meters?: number;
  visible?: boolean;
}

export const customLayersApi = {
  async list(projectId: string): Promise<CustomLayer[]> {
    const { data } = await api.get(`/api/projects/${projectId}/custom-layers`);
    return data.data.layers ?? [];
  },
  async create(projectId: string, input: CreateLayerInput): Promise<{ id: string }> {
    const { data } = await api.post(`/api/projects/${projectId}/custom-layers`, input);
    return data.data;
  },
  async update(id: string, input: UpdateLayerInput): Promise<void> {
    await api.put(`/api/custom-layers/${id}`, input);
  },
  async remove(id: string): Promise<void> {
    await api.delete(`/api/custom-layers/${id}`);
  },
  async points(id: string): Promise<{ points: CustomLayerPoint[]; layer: CustomLayer }> {
    const { data } = await api.get(`/api/custom-layers/${id}/points`);
    return data.data;
  },
  async batches(projectId: string): Promise<ImportBatch[]> {
    const { data } = await api.get(`/api/projects/${projectId}/import/batches`);
    return data.data.batches ?? [];
  },
};
