import { api } from './client';
import { trackSave } from '../stores/saveStatusStore';
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
    const { data } = await trackSave(api.post(`/api/projects/${projectId}/areas`, payload));
    return data.data as Area;
  },
  async update(id: string, payload: Partial<Area>) {
    const { data } = await trackSave(api.put(`/api/areas/${id}`, payload));
    return data.data as Area;
  },
  async delete(id: string) {
    const { data } = await trackSave(api.delete(`/api/areas/${id}`));
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
  // Alias for AreaCard's optimistic refetch after rebuild — same shape as get()
  // but uses a name that reads more naturally at call sites.
  async findById(id: string) {
    const { data } = await api.get(`/api/areas/${id}`);
    return data.data as Area;
  },
  // Replace the area's convex-hull geometry with ST_Union over its source
  // tracts. Server-side this is iterative pairwise union — slow (~8s for
  // 50 tracts) but produces a real geographic boundary instead of a
  // stretched diagonal hull.
  async rebuildBoundary(id: string) {
    const { data } = await api.post(`/api/areas/${id}/rebuild-boundary`);
    return data.data;
  },
  // BF7 — persist drag-reorder. Send the desired area_ids in order; server
  // stamps sort_order = index. Idempotent; safe to retry on flaky networks.
  async reorder(projectId: string, areaIds: string[]) {
    const { data } = await trackSave(api.post(`/api/projects/${projectId}/areas/reorder`, { area_ids: areaIds }));
    return data.data as { count: number };
  },
};
