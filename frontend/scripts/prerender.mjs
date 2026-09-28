#!/usr/bin/env node
/**
 * Prerender the public site to static HTML (§5.5): crawlers and slow clients get full HTML without JS;
 * the client bundle hydrates it.
 *
 *   npm run build                                   # client (dist/) + SSR bundle (dist/server/)
 *   npm run prerender -- --api http://localhost:8080/api/v1
 *   npm run prerender -- --mock                     # use the built-in fixtures (no backend needed)
 *
 * Options / env:
 *   --api <url>        API base URL (UC_API_BASE), default http://localhost:8080/api/v1
 *   --out <dir>        output directory, default dist/prerender
 *   --route <path>     only this route (repeatable); default = every public route from /site
 *   --mock             use the in-memory mock API
 *
 * Writes dist/prerender/<route>/index.html ('/' → dist/prerender/index.html). Serve these ahead of the SPA
 * fallback (e.g. nginx: try_files /prerender$uri/index.html $uri /index.html).
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const opt = (name, fallback) => {
  const i = args.indexOf(`--${name}`);
  return i >= 0 ? args[i + 1] : fallback;
};
const routesOnly = args.flatMap((a, i) => (a === '--route' ? [args[i + 1]] : []));
const apiBase = opt('api', process.env.UC_API_BASE || 'http://localhost:8080/api/v1');
const outDir = path.resolve(root, opt('out', 'dist/prerender'));
const mock = args.includes('--mock');

const templatePath = path.join(root, 'dist', 'index.html');
const serverEntry = path.join(root, 'dist', 'server', 'entry-server.js');

async function main() {
  const template = await fs.readFile(templatePath, 'utf8').catch(() => {
    throw new Error(`Missing ${templatePath}. Run "npm run build" first.`);
  });
  const ssr = await import(pathToFileURL(serverEntry).href).catch((e) => {
    throw new Error(`Missing SSR bundle ${serverEntry} (npm run build). ${e.message}`);
  });
  if (mock) await ssr.useMockApi();
  else ssr.configureApi(apiBase);

  const routes = routesOnly.length ? routesOnly : await ssr.listRoutes();
  let ok = 0;
  const failed = [];
  for (const route of routes) {
    try {
      const queries = await ssr.loadRoute(route);
      const { html, head, state } = ssr.render(route, queries);
      const page = template
        .replace(/<title>[^<]*<\/title>/, '')
        .replace('<!--app-head-->', head)
        .replace('<!--app-html-->', html)
        .replace('<!--app-state-->', state);
      const file = route === '/' ? path.join(outDir, 'index.html') : path.join(outDir, ...route.split('/').filter(Boolean).map(decodeURIComponent), 'index.html');
      await fs.mkdir(path.dirname(file), { recursive: true });
      await fs.writeFile(file, page);
      ok++;
      console.log(`  ✓ ${route}`);
    } catch (e) {
      failed.push(route);
      console.error(`  ✗ ${route}: ${e.message}`);
    }
  }
  console.log(`\nPrerendered ${ok}/${routes.length} routes into ${path.relative(root, outDir)}/ (${mock ? 'mock API' : apiBase})`);
  if (failed.length) process.exitCode = 1;
}

main().catch((e) => {
  console.error(e.message ?? e);
  process.exit(1);
});
