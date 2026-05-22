import { api } from './client';

export const reportsApi = {
  async generate(areaId: string, payload: { report_type?: string; title?: string } = {}) {
    const { data } = await api.post(`/api/areas/${areaId}/report`, payload);
    return data.data as { report_id: string; download_url: string };
  },
  async list(projectId: string) {
    const { data } = await api.get('/api/reports', { params: { project_id: projectId } });
    return data.data as any[];
  },
};
