import React from 'react';
import ReactDOM from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Toaster } from 'react-hot-toast';
import App from './App';
import './styles.css';

// Register the service worker so the mobile field PWA can install and queue
// offline note submissions. Skipped during dev (Vite serves on a different
// origin and would 404 on /app/sw.js).
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/app/sw.js', { scope: '/app/' }).catch(() => {
      // Silent — SW is non-critical
    });
  });
}

// When a fresh deploy rewrites Vite chunk hashes, a tab opened against the
// previous build can have a *stale* runtime cache that no longer has the
// dynamic-import chunks the new shell asks for. Result: "Failed to fetch
// dynamically imported module" when the user opens any lazy tab. Recover
// by purging SW caches + unregistering the SW + reloading. The
// sessionStorage guard is permanent for the tab (no auto-clear) — running
// it twice in the same session means the recovery itself failed, and a
// second attempt would just produce another reload loop. After the tab is
// closed and reopened, the guard resets automatically.
window.addEventListener('unhandledrejection', (event) => {
  const msg = String(event.reason?.message || event.reason || '');
  const looksLikeStaleChunk =
    /Failed to fetch dynamically imported module/i.test(msg) ||
    /Importing a module script failed/i.test(msg) ||
    /error loading dynamically imported module/i.test(msg);
  if (!looksLikeStaleChunk) return;
  if (sessionStorage.getItem('sm_chunk_reload_done') === '1') {
    // Already attempted recovery in this tab. Don't loop — surface the
    // error to the error boundary instead.
    console.warn('[chunk-recovery] already attempted in this session, surfacing error');
    return;
  }
  sessionStorage.setItem('sm_chunk_reload_done', '1');
  console.warn('[chunk-recovery] stale chunk detected — clearing SW caches + reloading');
  // Nuclear: purge caches AND unregister all SWs so the next load is fully
  // fresh from the server. Without unregister, a stuck v4 SW can keep
  // serving stale assets even after caches are emptied.
  (async () => {
    try {
      if ('caches' in window) {
        const keys = await caches.keys();
        await Promise.all(keys.map((k) => caches.delete(k)));
      }
      if ('serviceWorker' in navigator) {
        const regs = await navigator.serviceWorker.getRegistrations();
        await Promise.all(regs.map((r) => r.unregister()));
      }
    } catch (e) {
      console.warn('[chunk-recovery] cleanup failed:', e);
    } finally {
      location.reload();
    }
  })();
});

const qc = new QueryClient({
  defaultOptions: { queries: { staleTime: 30_000, retry: 1 } },
});

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <QueryClientProvider client={qc}>
      <BrowserRouter>
        <App />
        {/* Tweak #25 — restyled toasts. Each toast picks up a colored
            left-stripe via the .sm-toast-* type class, slides in from the
            top with the same easing as panels, and shows white-card chrome
            (border + shadow) instead of the dark react-hot-toast default. */}
        <Toaster
          position="top-right"
          gutter={10}
          toastOptions={{
            className: 'sm-toast',
            duration: 4000,
            success: { className: 'sm-toast sm-toast-success', iconTheme: { primary: '#22c55e', secondary: '#fff' } },
            error:   { className: 'sm-toast sm-toast-error',   iconTheme: { primary: '#ef4444', secondary: '#fff' }, duration: 5500 },
            loading: { className: 'sm-toast sm-toast-info' },
          }}
        />
      </BrowserRouter>
    </QueryClientProvider>
  </React.StrictMode>
);
