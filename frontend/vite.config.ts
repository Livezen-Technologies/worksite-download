/// <reference types="vitest/config" />
import { defineConfig, loadEnv } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '');
  const target = env.VITE_DEV_PROXY_TARGET || 'http://localhost:8080';
  const proxied = ['/api', '/download', '/_dev-cdn', '/changelog/feed', '/changelog/atom', '/sitemap.xml', '/robots.txt'];
  const proxy = Object.fromEntries(proxied.map((p) => [p, { target, changeOrigin: false }]));
  // Per-app feeds and checksums live under /apps/… (the SPA owns the rest of /apps).
  proxy['^/apps/[^/]+/(changelog/(feed|atom)|releases/[^/]+/checksums\\.txt)$'] = { target, changeOrigin: false };

  return {
    plugins: [react(), tailwindcss()],
    resolve: { alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) } },
    server: { port: 5173, proxy },
    preview: { port: 4173, proxy },
    build: {
      sourcemap: 'hidden',
      rollupOptions: {
        output: {
          manualChunks(id) {
            if (id.includes('node_modules/tus-js-client') || id.includes('node_modules/qrcode')) return 'vendor-admin';
            if (id.includes('node_modules/react-dom') || id.includes('node_modules/react/') || id.includes('node_modules/scheduler')) return 'vendor-react';
            return undefined;
          },
        },
      },
    },
    ssr: { noExternal: ['lucide-react'] },
    test: {
      globals: true,
      environment: 'jsdom',
      setupFiles: ['./src/test/setup.ts'],
      css: false,
    },
  };
});
