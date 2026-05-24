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
// previous build can have the *new* index.html (served network-first by
// the service worker) but a *stale* runtime cache that no longer has the
// dynamic-import chunks the new shell asks for — and the old chunks were
// removed from /public/app/assets/ by the build. Result: "Failed to fetch
// dynamically imported module" when the user opens any lazy tab (Analogs,
// Analytics, etc.). React's error boundary catches it and shows the inline
// "tab crashed" card.
//
// Catch those rejections and recover by clearing the SW caches + reloading
// once. The sessionStorage guard prevents an infinite reload loop if the
// underlying chunk really is missing (vs just stale).
window.addEventListener('unhandledrejection', (event) => {
  const msg = String(event.reason?.message || event.reason || '');
  const looksLikeStaleChunk =
    /Failed to fetch dynamically imported module/i.test(msg) ||
    /Importing a module script failed/i.test(msg) ||
    /error loading dynamically imported module/i.test(msg);
  if (!looksLikeStaleChunk) return;
  if (sessionStorage.getItem('sm_chunk_reload_done') === '1') return;
  sessionStorage.setItem('sm_chunk_reload_done', '1');
  // Best-effort cache purge so the next load fetches fresh chunks.
  if ('caches' in window) {
    caches.keys().then((keys) => Promise.all(keys.map((k) => caches.delete(k))))
      .finally(() => location.reload());
  } else {
    location.reload();
  }
});
// Clear the reload guard on a successful navigation that completed without
// chunk failures, so a future stale-cache incident can recover again.
window.addEventListener('load', () => {
  setTimeout(() => sessionStorage.removeItem('sm_chunk_reload_done'), 5000);
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
