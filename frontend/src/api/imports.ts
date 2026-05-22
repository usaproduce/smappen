import { api } from './client';
import type { ImportPreview, ImportResult } from '../types';

export const importsApi = {
  async upload(projectId: string, file: File) {
    const fd = new FormData();
    fd.append('file', file);
    const { data } = await api.post(`/api/projects/${projectId}/import/upload`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });
    return data.data as ImportPreview;
  },
  async configure(projectId: string, payload: { import_token: string; column_mapping: any }) {
    const { data } = await api.post(`/api/projects/${projectId}/import/configure`, payload);
    return data.data as ImportResult;
  },
  async status(batchId: string) {
    const { data } = await api.get(`/api/imports/${batchId}/status`);
    return data.data as { batch_id: string; point_count: number };
  },
  async delete(batchId: string) {
    const { data } = await api.delete(`/api/imports/${batchId}`);
    return data;
  },
};
