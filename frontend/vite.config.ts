import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  plugins: [react(), tailwindcss()],
  // Assets live at /app/assets/ on the server (mirrors outDir), so HTML must
  // reference them with that prefix. The Apache SPA fallback still serves
  // /app/index.html at the root URL.
  base: '/app/',
  server: {
    proxy: {
      '/api': 'http://localhost:8080',
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
