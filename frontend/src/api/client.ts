import axios from 'axios';
import toast from 'react-hot-toast';
import { useAuthStore } from '../stores/authStore';

const baseURL = (import.meta.env.VITE_API_URL || '').replace(/\/$/, '');

export const api = axios.create({
  baseURL,
  headers: { 'Content-Type': 'application/json' },
});

api.interceptors.request.use((cfg) => {
  const token = useAuthStore.getState().token;
  if (token) cfg.headers.Authorization = `Bearer ${token}`;
  if (import.meta.env.DEV) {
    console.log(`[API] ${cfg.method?.toUpperCase()} ${cfg.url}`);
  }
  return cfg;
});

api.interceptors.response.use(
  (r) => r,
  (err) => {
    if (err.response?.status === 401) {
      // logout() now hits POST /api/auth/logout to revoke the JWT. The 401 we
      // just received means the token is already invalid, so a server call
      // would 401 again — fire and forget; don't await it. Clear local state
      // immediately and redirect.
      try { useAuthStore.getState().logout(); } catch {}
      const onAuthPage = ['/login', '/register', '/forgot-password', '/reset-password', '/verify-email']
        .some((p) => location.pathname.startsWith(p));
      if (!onAuthPage) location.href = '/login';
    }
    if (!err.response) {
      toast.error('Connection lost. Please check your network.');
    }
    return Promise.reject(err);
  }
);

export type ApiResponse<T> = { success: true; data: T; message?: string };
export type ApiError = { success: false; error: string; details?: any };
