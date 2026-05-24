import { api } from './client';
import { trackSave } from '../stores/saveStatusStore';
import type { Project } from '../types';

export const projectsApi = {
  async list(params: { search?: string; page?: number; per_page?: number } = {}) {
    const { data } = await api.get('/api/projects', { params });
    return data as { success: true; data: Project[]; meta: { total: number; page: number; per_page: number; pages: number } };
  },
  async get(id: string) {
    const { data } = await api.get(`/api/projects/${id}`);
    return data.data as Project;
  },
  async create(p: Partial<Project>) {
    const { data } = await trackSave(api.post('/api/projects', p));
    return data.data as Project;
  },
  async update(id: string, p: Partial<Project>) {
    const { data } = await trackSave(api.put(`/api/projects/${id}`, p));
    return data.data as Project;
  },
  async delete(id: string) {
    const { data } = await trackSave(api.delete(`/api/projects/${id}`));
    return data;
  },
  async shared(token: string) {
    const { data } = await api.get(`/api/shared/${token}`);
    return data.data as Project;
  },
};
