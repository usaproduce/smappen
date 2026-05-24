import { api } from './client';
import type { User } from '../types';

export const authApi = {
  async login(email: string, password: string) {
    const { data } = await api.post('/api/auth/login', { email, password });
    return data.data as { user: User; token: string };
  },
  async register(payload: { email: string; password: string; name: string; organization_name?: string }) {
    const { data } = await api.post('/api/auth/register', payload);
    return data.data as { user: User; token: string };
  },
  async me() {
    const { data } = await api.get('/api/auth/me');
    return data.data.user as User;
  },
  async refresh() {
    const { data } = await api.post('/api/auth/refresh', {});
    return data.data.token as string;
  },
  async logout() {
    const { data } = await api.post('/api/auth/logout', {});
    return data.data;
  },
  async requestReset(email: string) {
    const { data } = await api.post('/api/auth/request-reset', { email });
    return data.data;
  },
  async resetPassword(token: string, password: string) {
    const { data } = await api.post('/api/auth/reset', { token, password });
    return data.data;
  },
  async verifyEmail(token: string) {
    const { data } = await api.get('/api/auth/verify-email', { params: { token } });
    return data.data;
  },
};
