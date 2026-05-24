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
