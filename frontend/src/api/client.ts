import axios from 'axios';
import toast from 'react-hot-toast';
import { useAuthStore } from '../stores/authStore';
import { useCostStore } from '../stores/costStore';

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
  (r) => {
    // Backend tags Google-API-fronting responses with _meta.estimated_cost_usd
    // (see UsageController + per-controller decorators). When we see one,
    // bump the cost store + fire a small toast so users can FEEL the spend.
    const meta = (r.data?.data?._meta) ?? (r.data?._meta);
    const cost = typeof meta?.estimated_cost_usd === 'number' ? meta.estimated_cost_usd : 0;
    if (cost > 0) {
      useCostStore.getState().trackCall(cost);
      const label = meta.api_name ? `${meta.api_name}` : 'Google API';
      // Sub-cent prints as "<$0.01" to avoid the misleading "$0.00".
      const display = cost < 0.01 ? '<$0.01' : '$' + cost.toFixed(cost < 1 ? 3 : 2);
      toast(`${label}  ·  ${display}`, {
        duration: 1800,
        icon: '💸',
        position: 'bottom-right',
        style: { fontSize: '12px' },
      });
    }
    return r;
  },
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
