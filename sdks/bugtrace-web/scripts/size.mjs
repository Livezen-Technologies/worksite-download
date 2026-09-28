import { readFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';
const raw = readFileSync('dist/index.js');
console.log(JSON.stringify({ min_bytes: raw.length, gzip_bytes: gzipSync(raw, { level: 9 }).length, budget_bytes: 25 * 1024 }));
