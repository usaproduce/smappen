import { api } from './client';
import type { Folder } from '../types';

export const foldersApi = {
  async tree(projectId: string) {
    const { data } = await api.get(`/api/projects/${projectId}/folders`);
    return data.data as Folder[];
  },
  async create(projectId: string, f: Partial<Folder>) {
    const { data } = await api.post(`/api/projects/${projectId}/folders`, f);
    return data.data as Folder;
  },
  async update(id: string, f: Partial<Folder>) {
    const { data } = await api.put(`/api/folders/${id}`, f);
    return data.data as Folder;
  },
  async delete(id: string) {
    const { data } = await api.delete(`/api/folders/${id}`);
    return data;
  },
};
