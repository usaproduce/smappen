import { api } from './client';
import type { Area, Demographics, Place } from '../types';

export const areasApi = {
  async listForProject(projectId: string, folderId?: string) {
    const { data } = await api.get(`/api/projects/${projectId}/areas`, {
      params: folderId ? { folder_id: folderId } : undefined,
    });
    return data.data as { type: 'FeatureCollection'; features: any[] };
  },
  async get(id: string) {
    const { data } = await api.get(`/api/areas/${id}`);
    return data.data as Area;
  },
  async create(projectId: string, payload: Partial<Area>) {
    const { data } = await api.post(`/api/projects/${projectId}/areas`, payload);
    return data.data as Area;
  },
  async update(id: string, payload: Partial<Area>) {
    const { data } = await api.put(`/api/areas/${id}`, payload);
    return data.data as Area;
  },
  async delete(id: string) {
    const { data } = await api.delete(`/api/areas/${id}`);
    return data;
  },
  async demographics(id: string) {
    const { data } = await api.get(`/api/areas/${id}/demographics`);
    return data.data as Demographics;
  },
  async pois(id: string) {
    const { data } = await api.get(`/api/areas/${id}/pois`);
    return data.data as { places: Place[]; cached_at?: string };
  },
  async compareDemographics(area_ids: string[]) {
    const { data } = await api.post('/api/demographics/compare', { area_ids });
    return data.data as Array<{ area_id: string; area_name: string; demographics: Demographics }>;
  },
};
