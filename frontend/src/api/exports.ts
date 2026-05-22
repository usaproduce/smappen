import { api } from './client';

export const exportsApi = {
  async exportAreas(projectId: string, format: 'csv' | 'xlsx' | 'geojson' | 'kml') {
    const { data } = await api.get(`/api/projects/${projectId}/export/areas`, { params: { format } });
    return data.data as { download_url: string };
  },
  async exportPOIs(areaId: string, format: 'csv' | 'xlsx') {
    const { data } = await api.get(`/api/areas/${areaId}/export/pois`, { params: { format } });
    return data.data as { download_url: string };
  },
  async exportImportedPoints(projectId: string, format: 'csv' | 'xlsx', batchId?: string) {
    const { data } = await api.get(`/api/projects/${projectId}/export/points`, {
      params: { format, batch_id: batchId },
    });
    return data.data as { download_url: string };
  },
};
