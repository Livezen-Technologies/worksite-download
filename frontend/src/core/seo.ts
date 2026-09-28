/**
 * Document head for public pages (§5.5): title, meta description, canonical, OpenGraph/Twitter,
 * robots (non-public channels are noindex) and JSON-LD SoftwareApplication. Rendered to HTML by the SSR
 * entry (prerender) and applied to the live DOM on client navigation.
 */
import { config } from './config';
import { formatBytes, PLATFORM_LABEL } from './format';
import type { AppSummary, PublicPackage, PublicReleaseDetail } from '@/types/api';

export interface HeadData {
  title: string;
  description: string;
  path: string;
  noindex?: boolean;
  ogType?: 'website' | 'article';
  jsonLd?: Record<string, unknown> | null;
  feeds?: { rss: string; atom: string; title: string } | null;
}

export function absoluteUrl(path: string): string {
  return path.startsWith('http') ? path : `${config.publicOrigin}${path}`;
}

export function platformList(app: Pick<AppSummary, 'platforms'>): string {
  const names = Array.from(new Set(app.platforms.map((p) => PLATFORM_LABEL[p.platform])));
  if (names.length <= 1) return names.join('');
  return `${names.slice(0, -1).join(', ')} & ${names[names.length - 1]}`;
}

/** "WorkSuite Desktop Download | Windows, macOS & Linux" */
export function appTitle(app: Pick<AppSummary, 'name' | 'platforms'>): string {
  const list = platformList(app);
  return list ? `${app.name} Download | ${list}` : `${app.name} Download`;
}

/** "WorkSuite Desktop 2.7.0 Release Notes" */
export function releaseTitle(appName: string, version: string): string {
  return `${appName} ${version} Release Notes`;
}

export function softwareJsonLd(app: AppSummary, release: PublicReleaseDetail | null, pkg?: PublicPackage | null): Record<string, unknown> {
  const os = Array.from(new Set(app.platforms.map((p) => PLATFORM_LABEL[p.platform]))).join(', ');
  const main = pkg ?? release?.packages.find((p) => p.recommended) ?? release?.packages[0] ?? null;
  return {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: app.name,
    applicationCategory: 'BusinessApplication',
    operatingSystem: os,
    description: app.description,
    url: absoluteUrl(`/apps/${app.slug}`),
    ...(release
      ? {
          softwareVersion: release.version,
          datePublished: release.release_date ?? release.published_at?.slice(0, 10),
          releaseNotes: absoluteUrl(release.notes_url),
        }
      : {}),
    ...(main ? { downloadUrl: absoluteUrl(main.download_url), fileSize: formatBytes(main.size) } : {}),
    offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
    publisher: { '@type': 'Organization', name: 'Livezen Technologies (Pvt) Ltd' },
  };
}

function esc(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** Serialised <head> fragment for SSR/prerender. */
export function renderHeadHtml(h: HeadData): string {
  const url = absoluteUrl(h.path);
  const tags = [
    `<title>${esc(h.title)}</title>`,
    `<meta name="description" content="${esc(h.description)}" />`,
    `<link rel="canonical" href="${esc(url)}" />`,
    h.noindex ? `<meta name="robots" content="noindex" />` : '',
    `<meta property="og:site_name" content="${esc(config.siteName)}" />`,
    `<meta property="og:type" content="${h.ogType ?? 'website'}" />`,
    `<meta property="og:title" content="${esc(h.title)}" />`,
    `<meta property="og:description" content="${esc(h.description)}" />`,
    `<meta property="og:url" content="${esc(url)}" />`,
    `<meta name="twitter:card" content="summary" />`,
    `<meta name="twitter:title" content="${esc(h.title)}" />`,
    `<meta name="twitter:description" content="${esc(h.description)}" />`,
    h.feeds ? `<link rel="alternate" type="application/rss+xml" title="${esc(h.feeds.title)} (RSS)" href="${esc(h.feeds.rss)}" data-uc-head />` : '',
    h.feeds ? `<link rel="alternate" type="application/atom+xml" title="${esc(h.feeds.title)} (Atom)" href="${esc(h.feeds.atom)}" data-uc-head />` : '',
    h.jsonLd ? `<script type="application/ld+json" id="uc-jsonld">${JSON.stringify(h.jsonLd).replace(/</g, '\\u003c')}</script>` : '',
  ];
  return tags.filter(Boolean).join('\n    ');
}

function upsertMeta(attr: 'name' | 'property', key: string, content: string | null) {
  let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`);
  if (content == null) {
    el?.remove();
    return;
  }
  if (!el) {
    el = document.createElement('meta');
    el.setAttribute(attr, key);
    document.head.appendChild(el);
  }
  el.content = content;
}

/** Apply head data to the live document (client-side navigation). */
export function applyHead(h: HeadData): void {
  if (typeof document === 'undefined') return;
  document.title = h.title;
  const url = absoluteUrl(h.path);
  upsertMeta('name', 'description', h.description);
  upsertMeta('name', 'robots', h.noindex ? 'noindex' : null);
  upsertMeta('property', 'og:title', h.title);
  upsertMeta('property', 'og:description', h.description);
  upsertMeta('property', 'og:url', url);
  upsertMeta('property', 'og:type', h.ogType ?? 'website');
  upsertMeta('name', 'twitter:title', h.title);
  upsertMeta('name', 'twitter:description', h.description);
  let canonical = document.head.querySelector<HTMLLinkElement>('link[rel="canonical"]');
  if (!canonical) {
    canonical = document.createElement('link');
    canonical.rel = 'canonical';
    document.head.appendChild(canonical);
  }
  canonical.href = url;
  document.head.querySelectorAll('link[data-uc-head]').forEach((n) => n.remove());
  if (h.feeds) {
    for (const [type, href] of [
      ['application/rss+xml', h.feeds.rss],
      ['application/atom+xml', h.feeds.atom],
    ]) {
      const l = document.createElement('link');
      l.rel = 'alternate';
      l.type = type;
      l.href = href;
      l.title = h.feeds.title;
      l.setAttribute('data-uc-head', '');
      document.head.appendChild(l);
    }
  }
  let ld = document.getElementById('uc-jsonld');
  if (h.jsonLd) {
    if (!ld) {
      ld = document.createElement('script');
      ld.id = 'uc-jsonld';
      ld.setAttribute('type', 'application/ld+json');
      document.head.appendChild(ld);
    }
    ld.textContent = JSON.stringify(h.jsonLd);
  } else ld?.remove();
}
