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
  },
});
