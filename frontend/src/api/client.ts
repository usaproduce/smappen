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

// Batch cost toasts: a batch geocode (200 addresses) would otherwise pop 200
// toasts in a row. Buffer calls for a 2s rolling window and emit one summary
// toast per burst. Single calls still feel immediate because the toast fires
// at the end of the burst window, not after a fixed delay.
let costBuffer: { count: number; total: number; lastApiName: string | null; timer: number | null } = {
  count: 0, total: 0, lastApiName: null, timer: null,
};
function queueCostToast(apiName: string, cost: number) {
  costBuffer.count += 1;
  costBuffer.total += cost;
  costBuffer.lastApiName = apiName;
  if (costBuffer.timer) window.clearTimeout(costBuffer.timer);
  costBuffer.timer = window.setTimeout(() => {
    const { count, total, lastApiName } = costBuffer;
    costBuffer = { count: 0, total: 0, lastApiName: null, timer: null };
    if (count === 0) return;
    const display = total < 0.01 ? '<$0.01' : '$' + total.toFixed(total < 1 ? 3 : 2);
    const label = count === 1
      ? `${lastApiName} · ${display}`
      : `${count} API calls · ${display} total`;
    toast(label, {
      duration: 1800,
      icon: '💸',
      position: 'bottom-right',
      style: { fontSize: '12px' },
    });
  }, 600);
}

api.interceptors.response.use(
  (r) => {
    // Backend tags Google-API-fronting responses with _meta.estimated_cost_usd
    // (see UsageController + per-controller decorators). When we see one,
    // bump the cost store + queue a (de-duplicated) toast so users can FEEL
    // the spend without getting 200-toast bursts on batch geocode.
    const meta = (r.data?.data?._meta) ?? (r.data?._meta);
    const cost = typeof meta?.estimated_cost_usd === 'number' ? meta.estimated_cost_usd : 0;
    if (cost > 0) {
      useCostStore.getState().trackCall(cost);
      queueCostToast(meta?.api_name ?? 'Google API', cost);
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
