import { defineConfig, type Plugin } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// Development only (`apply: 'serve'`; a build never loads it).
//
// The app's routes are absolute from the origin root (`/truck/map`,
// `/dashboard`, `/login`) while `base` is `/app/`. In production Apache and
// nginx answer any path that is not a file with `app/index.html`. Vite's dev
// server does not: a hard load or a refresh of such a path gets its 404 help
// page. This middleware gives `vite dev` the same fallback: a browser
// navigation (GET, Accept: text/html) to a path outside `/app/`, `/api` and
// Vite's own `/@...` paths, with no file extension, is answered with
// `/app/index.html`. Module, asset and API requests are never touched.
function devSpaFallback(): Plugin {
  return {
    name: 'smappen-dev-spa-fallback',
    apply: 'serve',
    configureServer(server) {
      // Registered directly (not as a post hook), so it runs before Vite's
      // base middleware, which is the one that answers the 404 help page.
      server.middlewares.use((req, _res, next) => {
        const path = (req.url ?? '/').split('?')[0];
        const wantsHtml = String(req.headers.accept ?? '').includes('text/html');
        const insideBase = path === '/app' || path.startsWith('/app/');
        const isApi = path === '/api' || path.startsWith('/api/');
        const isViteInternal = path.startsWith('/@');
        const hasExtension = /\.[A-Za-z0-9]+$/.test(path);
        if (req.method === 'GET' && wantsHtml && !insideBase && !isApi && !isViteInternal && !hasExtension) {
          req.url = '/app/index.html';
        }
        next();
      });
    },
  };
}

// Where `vite dev` sends `/api` requests. Set the environment variable
// VITE_DEV_API_PROXY to point the dev server at another backend, e.g.
//   VITE_DEV_API_PROXY=http://127.0.0.1:8787 npx vite
const devApiProxy = process.env.VITE_DEV_API_PROXY || 'http://localhost:8080';

export default defineConfig({
  plugins: [react(), tailwindcss(), devSpaFallback()],
  // Assets live at /app/assets/ on the server (mirrors outDir), so HTML must
  // reference them with that prefix. The Apache SPA fallback still serves
  // /app/index.html at the root URL.
  base: '/app/',
  server: {
    proxy: {
      '/api': devApiProxy,
    },
  },
  build: {
    outDir: '../public/app',
    emptyOutDir: true,
    rollupOptions: {
      output: {
        // Split heavy vendor libs into their own chunks so they're cached
        // independently of app code AND shared across lazy advanced-panel
        // tabs that import them (e.g. recharts in CannibalizeTab AND in any
        // future tab that adds charts). Before this, each tab bundled its
        // own copy.
        manualChunks: {
          'gmaps': ['@react-google-maps/api', '@googlemaps/markerclusterer'],
          'charts': ['recharts', 'recharts-scale'],
          'react-vendor': ['react', 'react-dom', 'react-router-dom'],
          'state': ['zustand', '@tanstack/react-query'],
          // turf + parsers (papaparse/xlsx) chunks were generating empty
          // outputs — they're only imported from a few code-split routes
          // and Vite already gives them their own chunks via dynamic import.
        },
      },
    },
  },
});
