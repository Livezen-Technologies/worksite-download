/**
 * SSR entry for prerendering public pages (§3.5, §5.5: "public pages are prerendered on publish").
 * Built with `vite build --ssr src/entry-server.tsx --outDir dist/server` and driven by scripts/prerender.mjs:
 *
 *   configureApi(origin)          → point the API client at the backend (or useMockApi() for fixtures)
 *   listRoutes()                  → every public route to prerender (from /site, /apps/{slug}/versions)
 *   loadRoute(url)                → the react-query entries that route needs (same keys as the client)
 *   render(url, queries)          → { html, head, state } — hydrated by the client bundle (src/main.tsx)
 */
import { renderToString } from 'react-dom/server';
import { StaticRouter } from 'react-router';
import { dehydrate, QueryClient, type QueryKey } from '@tanstack/react-query';
import { Providers } from '@/app/Providers';
import { AppRoutes } from '@/app/AppRoutes';
import { HeadProvider, type HeadCollector } from '@/core/head';
import { renderHeadHtml } from '@/core/seo';
import { setTransport } from '@/services/api';
import { publicApi, publicKeys } from '@/services/public';
import { primaryApp } from '@/pages/public/HomePage';

export interface PrerenderQuery {
  key: QueryKey;
  data: unknown;
}

/** Use the real backend: `origin` like http://localhost:8080 (paths already include /api/v1). */
export function configureApi(origin: string, headers: Record<string, string> = {}): void {
  const base = origin.replace(/\/+$/, '').replace(/\/api\/v1$/, '');
  setTransport((req) => fetch(`${base}${req.url}`, { method: req.method, headers: { ...req.headers, ...headers }, body: req.body, signal: req.signal }));
}

/** Use the in-memory fixtures (VITE_API_MOCK data) — handy to verify prerendering without a backend. */
export async function useMockApi(): Promise<void> {
  const mock = await import('@/services/mock');
  mock.installMock();
}

export async function listRoutes(): Promise<string[]> {
  const site = await publicApi.site();
  const routes = ['/', '/apps', '/changelog', '/channels'];
  for (const app of site.apps) {
    const base = `/apps/${app.slug}`;
    routes.push(base, `${base}/versions`, `${base}/changelog`, `${base}/requirements`);
    const versions = await publicApi.versions(app.slug).catch(() => ({ data: [] }));
    for (const v of versions.data) routes.push(`${base}/releases/${v.version}`);
  }
  return routes;
}

export async function loadRoute(url: string): Promise<PrerenderQuery[]> {
  const path = url.split('?')[0].replace(/\/+$/, '') || '/';
  const out: PrerenderQuery[] = [];
  const add = async (key: QueryKey, fn: () => Promise<unknown>) => out.push({ key, data: await fn() });
  let m: RegExpExecArray | null;
  if (path === '/') {
    const site = await publicApi.site();
    out.push({ key: publicKeys.site, data: site });
    const main = primaryApp(site.apps);
    if (main) await add(publicKeys.app(main.slug), () => publicApi.app(main.slug));
  } else if (path === '/apps') await add(publicKeys.apps, publicApi.apps);
  else if (path === '/changelog') await add(publicKeys.changelog(0), () => publicApi.changelog(0));
  else if (path === '/channels') await add(publicKeys.channels, publicApi.channels);
  else if ((m = /^\/apps\/([^/]+)\/releases\/([^/]+)$/.exec(path))) {
    const [slug, version] = [decodeURIComponent(m[1]), decodeURIComponent(m[2])];
    await add(publicKeys.release(slug, version), () => publicApi.release(slug, version));
  } else if ((m = /^\/apps\/([^/]+)(\/(versions|changelog|requirements))?$/.exec(path))) {
    const slug = decodeURIComponent(m[1]);
    await add(publicKeys.app(slug), () => publicApi.app(slug));
    if (m[3] === 'versions') await add(publicKeys.versions(slug), () => publicApi.versions(slug));
    if (m[3] === 'changelog') await add(publicKeys.appChangelog(slug, 0), () => publicApi.appChangelog(slug, 0));
  }
  return out;
}

export function render(url: string, queries: PrerenderQuery[]): { html: string; head: string; state: string } {
  const client = new QueryClient({ defaultOptions: { queries: { staleTime: Infinity, retry: false } } });
  for (const q of queries) client.setQueryData(q.key, q.data);
  const collector: HeadCollector = { head: null };
  const html = renderToString(
    <HeadProvider collector={collector}>
      <Providers client={client}>
        <StaticRouter location={url}>
          <AppRoutes />
        </StaticRouter>
      </Providers>
    </HeadProvider>,
  );
  const state = JSON.stringify(dehydrate(client)).replace(/</g, '\\u003c').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
  return { html, head: collector.head ? renderHeadHtml(collector.head) : '', state: `<script>window.__UC_STATE__=${state}</script>` };
}
