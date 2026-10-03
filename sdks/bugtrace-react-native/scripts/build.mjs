// Bundles the React Native SDK (Hermes). `react-native` is injected by the host, never bundled.
import { build } from 'esbuild';

await build({
  entryPoints: ['src/index.ts'],
  bundle: true,
  platform: 'neutral',
  format: 'esm',
  target: ['es2020'],
  mainFields: ['module', 'main'],
  external: ['react-native'],
  sourcemap: 'external',
  outfile: 'dist/index.js',
});
