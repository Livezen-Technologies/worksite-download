import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

export default defineConfig({
  resolve: { alias: { '@worksuite/bugtrace-web': fileURLToPath(new URL('../bugtrace-web/src/index.ts', import.meta.url)) } },
  test: { environment: 'node', include: ['test/**/*.test.ts'] },
});
