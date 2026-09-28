// Bundles the SDK with esbuild (ESM + IIFE for <script> tags) and enforces the
// 25 KB gzip budget (NFR-C19).
import { build } from 'esbuild';
import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

const common = { entryPoints: ['src/index.ts'], bundle: true, minify: true, target: ['es2020'], legalComments: 'none', sourcemap: 'external' };
await build({ ...common, format: 'esm', outfile: 'dist/index.js' });
await build({ ...common, format: 'iife', globalName: 'WorkSuiteBugTrace', outfile: 'dist/bugtrace.iife.js' });

const BUDGET = 25 * 1024;
for (const f of ['dist/index.js', 'dist/bugtrace.iife.js']) {
  const raw = readFileSync(f);
  const gz = gzipSync(raw, { level: 9 }).length;
  console.log(`${f}: ${(raw.length / 1024).toFixed(1)} KB min, ${(gz / 1024).toFixed(1)} KB gzip (budget 25 KB)`);
  if (gz > BUDGET) {
    console.error(`Bundle ${f} exceeds the 25 KB gzip budget (NFR-C19)`);
    process.exit(1);
  }
}
