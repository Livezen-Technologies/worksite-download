// Bundles the main-process SDK (Node/Electron target). `electron` is injected by the host, never bundled.
import { build } from 'esbuild';

await build({
  entryPoints: ['src/index.ts'],
  bundle: true,
  platform: 'node',
  format: 'esm',
  target: ['node20'],
  external: ['electron'],
  sourcemap: 'external',
  outfile: 'dist/index.js',
});
