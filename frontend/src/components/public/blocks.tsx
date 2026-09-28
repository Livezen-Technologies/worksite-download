/**
 * Data-driven public page blocks (§3.7). No app-specific code: everything comes from the API.
 * Download buttons are plain <a href="/download/…"> links so they work without JavaScript.
 */
import { useId, useState, type FormEvent, type ReactNode } from 'react';
import { Link } from 'react-router';
import { AppWindow, ChevronDown, Download, Monitor, Server, ShieldCheck, Smartphone, Terminal } from 'lucide-react';
import type { AppSummary, ChangelogSections, ChannelCode, DownloadPage, Platform, PreviousVersion, PublicPackage, PublicReleaseDetail } from '@/types/api';
import { cx, formatBytes, formatDateLong, PLATFORM_LABEL, shortHash } from '@/core/format';
import { ARCH_GUIDANCE, groupByPlatform, latestResolverUrl, packageButtonLabel, packageLongLabel } from '@/core/packages';
import { Badge } from '@/components/ui';
import { ChannelBadge, SupportStateBadge } from '@/components/StatusBadges';
import { errorMessage, ApiError } from '@/services/api';
import { publicApi } from '@/services/public';

export const PLATFORM_ICON: Record<Platform, typeof Monitor> = {
  windows: AppWindow,
  macos: Monitor,
  linux: Terminal,
  android: Smartphone,
  ios: Smartphone,
  server: Server,
};

export function PlatformIcon({ platform, className }: { platform: Platform; className?: string }) {
  const Icon = PLATFORM_ICON[platform];
  return (
    <span aria-hidden className={cx('grid size-8 place-items-center rounded-lg bg-brand-50 text-indigo', className)}>
      <Icon className="size-4" />
    </span>
  );
}

export function AppIcon({ app, size = 'lg' }: { app: Pick<AppSummary, 'name' | 'icon'>; size?: 'md' | 'lg' }) {
  const cls = size === 'lg' ? 'size-12 rounded-xl text-[22px]' : 'size-9 rounded-lg text-[16px]';
  if (app.icon) return <img src={app.icon} alt="" className={cx(cls, 'object-cover')} width={48} height={48} />;
  return (
    <span aria-hidden className={cx('grid shrink-0 place-items-center bg-indigo font-extrabold text-white', cls)}>
      {app.name.replace(/^WorkSuite\s+/, '').charAt(0) === 'D' ? 'W' : app.name.replace(/^WorkSuite\s+/, '').charAt(0)}
    </span>
  );
}

// ---------------------------------------------------------------- AppHero

/** Illustration of the desktop app window (pure CSS, decorative). */
function HeroArt() {
  return (
    <div aria-hidden className="hidden rounded-2xl border border-white/10 bg-navy-700 p-3 shadow-2xl lg:block">
      <div className="mb-2 flex gap-1.5">
        <span className="size-2 rounded-full bg-white/25" />
        <span className="size-2 rounded-full bg-white/25" />
        <span className="size-2 rounded-full bg-white/25" />
      </div>
      <div className="flex h-44 gap-2">
        <div className="w-8 rounded-md bg-navy" />
        <div className="w-24 rounded-md bg-navy-600" />
        <div className="flex-1 rounded-md bg-[#dfe3ef]" />
      </div>
    </div>
  );
}

export function AppHero({ eyebrow, title, subtitle, children, art = true }: { eyebrow?: ReactNode; title: ReactNode; subtitle?: ReactNode; children?: ReactNode; art?: boolean }) {
  return (
    <section className="bg-navy text-white">
      <div className="mx-auto grid max-w-6xl items-center gap-10 px-4 py-10 sm:px-6 sm:py-14 lg:grid-cols-[1.25fr_1fr]">
        <div>
          {eyebrow && <p className="mb-3 text-[11px] font-bold tracking-[0.12em] text-[#aab4d8] uppercase">{eyebrow}</p>}
          <h1 className="text-[30px] leading-[1.1] font-extrabold tracking-tight sm:text-[40px]">{title}</h1>
          {subtitle && <p className="mt-3 max-w-lg text-[14px] text-[#c9d0ea]">{subtitle}</p>}
          {children && <div className="mt-6">{children}</div>}
        </div>
        {art && <HeroArt />}
      </div>
    </section>
  );
}

// ---------------------------------------------------------------- LatestVersion

export function LatestVersion({ app, release, breadcrumb }: { app: AppSummary; release: PublicReleaseDetail | null; breadcrumb?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-end justify-between gap-4">
      <div className="flex items-center gap-4">
        <AppIcon app={app} />
        <div>
          {breadcrumb && <div className="text-[12px] text-subtle">{breadcrumb}</div>}
          <h1 className="text-[26px] font-bold tracking-tight">{app.name}</h1>
        </div>
      </div>
      {release && (
        <dl className="flex gap-8">
          <div>
            <dt className="label-caps">Latest stable</dt>
            <dd className="font-mono text-[18px] font-semibold">{release.version}</dd>
          </div>
          <div>
            <dt className="label-caps">Released</dt>
            <dd className="text-[15px] font-semibold">{formatDateLong(release.release_date ?? release.published_at)}</dd>
          </div>
        </dl>
      )}
    </div>
  );
}

// ---------------------------------------------------------------- PlatformDownloads

function DownloadButton({ pkg, href, primary }: { pkg: PublicPackage; href: string; primary: boolean }) {
  return (
    <a
      href={href}
      className={cx(
        'flex items-center justify-between gap-3 rounded-lg border px-3 py-2.5 text-[12.5px] font-semibold transition-colors',
        primary ? 'border-indigo bg-indigo text-white hover:bg-indigo-700' : 'border-line bg-white text-ink hover:border-brand hover:bg-brand-50',
      )}
      aria-label={`Download ${packageLongLabel(pkg)}, ${formatBytes(pkg.size)}`}
      rel="nofollow"
    >
      <span className="flex min-w-0 items-center gap-2">
        {primary && <Download className="size-3.5 shrink-0" aria-hidden />}
        <span className="truncate">
          {primary && pkg.platform === 'windows' ? 'Download ' : ''}
          {packageButtonLabel(pkg)}
        </span>
      </span>
      <span className={cx('font-mono text-[11px] font-medium', primary ? 'text-white/85' : 'text-subtle')}>{formatBytes(pkg.size)}</span>
    </a>
  );
}

/** Platform cards: every platform × arch × package type with sizes and arch guidance (P2). */
export function PlatformDownloads({ slug, packages, useLatestResolver = true, requirements, idPrefix = 'platforms' }: { slug: string; packages: PublicPackage[]; useLatestResolver?: boolean; requirements?: Record<string, string[]>; idPrefix?: string }) {
  const groups = groupByPlatform(packages);
  if (!groups.length) return <p className="text-[13px] text-subtle">No downloads are available for this version.</p>;
  return (
    <div id={idPrefix} className={cx('grid gap-4', groups.length >= 3 ? 'md:grid-cols-3' : groups.length === 2 ? 'md:grid-cols-2' : '')}>
      {groups.map((g) => {
        const minOs = g.packages.find((p) => p.min_os)?.min_os ?? requirements?.[g.platform]?.[0];
        return (
          <section key={g.platform} className="card flex flex-col p-4" aria-labelledby={`${idPrefix}-${g.platform}`}>
            <div className="mb-3 flex items-baseline gap-2">
              <h2 id={`${idPrefix}-${g.platform}`} className="text-[15px] font-bold">
                {PLATFORM_LABEL[g.platform]}
              </h2>
              {minOs && <span className="text-[11.5px] text-subtle">{minOs}</span>}
            </div>
            <ul className="flex flex-col gap-2">
              {g.packages.map((p, i) => (
                <li key={p.id}>
                  <DownloadButton pkg={p} primary={i === 0 || (g.platform === 'macos' && p.recommended && g.packages[i - 1]?.architecture !== p.architecture)} href={useLatestResolver ? latestResolverUrl(slug, p) : p.download_url} />
                </li>
              ))}
            </ul>
            {ARCH_GUIDANCE[g.platform] && <p className="mt-3 text-[11.5px] leading-snug text-subtle">{ARCH_GUIDANCE[g.platform]}</p>}
          </section>
        );
      })}
    </div>
  );
}

// ---------------------------------------------------------------- ReleaseHighlights

export function ReleaseHighlights({ release, slug }: { release: PublicReleaseDetail; slug: string }) {
  const items = release.changelog.highlights.length
    ? release.changelog.highlights
    : [...release.changelog.sections.new_features, ...release.changelog.sections.improvements].slice(0, 5);
  return (
    <section className="card p-5" aria-labelledby="highlights-h">
      <div className="mb-2 flex items-baseline justify-between gap-3">
        <h2 id="highlights-h" className="text-[14px] font-bold">
          What’s new in {release.version}
        </h2>
        <Link to={`/apps/${slug}/releases/${release.version}`} className="link text-[12px]">
          Full release notes
        </Link>
      </div>
      {release.changelog.summary && <p className="mb-2 text-[13px] text-muted">{release.changelog.summary}</p>}
      {items.length > 0 && (
        <ul className="list-disc space-y-1 pl-5 text-[13px]">
          {items.map((h, i) => (
            <li key={i}>{h}</li>
          ))}
        </ul>
      )}
    </section>
  );
}

// ---------------------------------------------------------------- Changelog sections

export const SECTION_TITLES: [keyof ChangelogSections, string][] = [
  ['new_features', 'New Features'],
  ['improvements', 'Improvements'],
  ['bug_fixes', 'Bug Fixes'],
  ['performance', 'Performance'],
  ['security', 'Security'],
  ['known_issues', 'Known Issues'],
  ['deprecated', 'Deprecated'],
  ['removed', 'Removed'],
];

export function ChangelogSectionsView({ sections, headingLevel = 2 }: { sections: ChangelogSections; headingLevel?: 2 | 3 }) {
  const H = headingLevel === 2 ? 'h2' : 'h3';
  const present = SECTION_TITLES.filter(([k]) => sections[k]?.length);
  if (!present.length) return <p className="text-[13px] text-subtle">No release notes were published for this version.</p>;
  return (
    <div className="space-y-4">
      {present.map(([k, title]) => (
        <section key={k}>
          <H className="mb-1 text-[13.5px] font-bold">{title}</H>
          <ul className="list-disc space-y-0.5 pl-5 text-[13px]">
            {sections[k].map((item, i) => (
              <li key={i}>{item}</li>
            ))}
          </ul>
        </section>
      ))}
    </div>
  );
}

// ---------------------------------------------------------------- SystemRequirements

export function SystemRequirements({ requirements, title = 'System requirements' }: { requirements: Record<string, string[]>; title?: string }) {
  const entries = Object.entries(requirements).filter(([, v]) => v?.length);
  if (!entries.length) return null;
  return (
    <section className="card p-5" aria-labelledby="req-h">
      <h2 id="req-h" className="mb-3 text-[14px] font-bold">
        {title}
      </h2>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {entries.map(([platform, lines]) => (
          <div key={platform}>
            <h3 className="mb-1 text-[13px] font-semibold">{PLATFORM_LABEL[platform as Platform] ?? platform}</h3>
            <ul className="list-disc space-y-0.5 pl-5 text-[12.5px] text-muted">
              {lines.map((l, i) => (
                <li key={i}>{l}</li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </section>
  );
}

// ---------------------------------------------------------------- PreviousVersions

export function PreviousVersions({ versions, latestVersion, limit, title = 'Previous versions', slug }: { versions: PreviousVersion[]; latestVersion?: string | null; limit?: number; title?: string; slug?: string }) {
  const rows = limit ? versions.slice(0, limit) : versions;
  return (
    <section className="card p-5" aria-labelledby="prev-h">
      <div className="mb-2 flex items-baseline justify-between">
        <h2 id="prev-h" className="text-[14px] font-bold">
          {title}
        </h2>
        {slug && limit && versions.length > limit && (
          <Link to={`/apps/${slug}/versions`} className="link text-[12px]">
            All versions
          </Link>
        )}
      </div>
      <ul className="divide-y divide-line">
        {rows.map((v) => (
          <li key={`${v.channel}-${v.version}`} className="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5 text-[12.5px]">
            <span className="w-24 font-mono font-medium">{v.version}</span>
            {v.is_latest ? <Badge tone="green">Latest</Badge> : <SupportStateBadge state={v.support_state} />}
            {v.channel !== 'STABLE' && <ChannelBadge channel={v.channel} />}
            <span className="ml-auto text-right">
              {v.support_state === 'UNSUPPORTED' || !v.download_available ? (
                <span className="text-[11.5px] text-subtle">Download unavailable. Please install {latestVersion ?? 'the latest version'}.</span>
              ) : v.is_latest ? null : (
                <Link to={v.notes_url} className="link text-[12px]">
                  Notes<span className="sr-only"> for {v.version}</span>
                </Link>
              )}
            </span>
            {v.support_state === 'DEPRECATED' && <span className="w-full text-[11px] text-warn">Deprecated — still downloadable, but please update to {latestVersion ?? 'the latest version'}.</span>}
          </li>
        ))}
      </ul>
    </section>
  );
}

// ---------------------------------------------------------------- InstallationHelp

export function InstallationHelp({ help }: { help: DownloadPage['install_help'] }) {
  const entries = Object.entries(help ?? {});
  if (!entries.length) return null;
  return (
    <section className="card p-5" aria-labelledby="install-h" id="install">
      <h2 id="install-h" className="mb-2 text-[14px] font-bold">
        Installation help
      </h2>
      <div className="divide-y divide-line">
        {entries.map(([platform, h]) => (
          <details key={platform} className="group py-2">
            <summary className="flex cursor-pointer list-none items-center justify-between text-[13px] font-semibold">
              {PLATFORM_LABEL[platform as Platform] ?? platform}
              <ChevronDown className="size-4 transition-transform group-open:rotate-180" aria-hidden />
            </summary>
            <div className="prose-uc mt-1" dangerouslySetInnerHTML={{ __html: h.html }} />
          </details>
        ))}
      </div>
    </section>
  );
}

// ---------------------------------------------------------------- ChecksumPanel

export function ChecksumPanel({ packages, checksumsUrl, manifestUrl }: { packages: PublicPackage[]; checksumsUrl: string; manifestUrl: string }) {
  return (
    <section className="card p-5" aria-labelledby="sha-h" id="verify">
      <h2 id="sha-h" className="mb-1 text-[14px] font-bold">
        Verify your download (SHA-256)
      </h2>
      <p className="mb-3 text-[12px] text-subtle">
        Compare the SHA-256 of your file with the value below, e.g. <code className="font-mono">shasum -a 256 &lt;file&gt;</code> or <code className="font-mono">Get-FileHash &lt;file&gt;</code>.
      </p>
      <ul className="divide-y divide-line">
        {packages.map((p) => (
          <li key={p.id} className="py-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2 text-[12px]">
              <span className="font-mono font-medium break-all">{p.file_name}</span>
              <span className="font-mono text-subtle">{formatBytes(p.size)}</span>
            </div>
            <code className="block font-mono text-[11px] break-all text-muted" title={p.sha256}>
              {p.sha256}
            </code>
          </li>
        ))}
      </ul>
      <p className="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 text-[12px]">
        <ShieldCheck className="size-4 text-ok" aria-hidden />
        <a className="link" href={checksumsUrl}>
          checksums.txt
        </a>
        <a className="link" href={manifestUrl}>
          Signed release manifest
        </a>
      </p>
    </section>
  );
}

// ---------------------------------------------------------------- FaqList

export function FaqList({ faq }: { faq: DownloadPage['faq'] }) {
  if (!faq?.length) return null;
  return (
    <section className="card p-5" aria-labelledby="faq-h">
      <h2 id="faq-h" className="mb-2 text-[14px] font-bold">
        Frequently asked questions
      </h2>
      <div className="divide-y divide-line">
        {faq.map((f, i) => (
          <details key={i} className="group py-2">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-[13px] font-semibold">
              {f.q}
              <ChevronDown className="size-4 shrink-0 transition-transform group-open:rotate-180" aria-hidden />
            </summary>
            <div className="prose-uc mt-1" dangerouslySetInnerHTML={{ __html: f.a_html }} />
          </details>
        ))}
      </div>
    </section>
  );
}

// ---------------------------------------------------------------- Downloads list (P3 sidebar)

export function DownloadList({ packages, checksumsUrl, manifestUrl }: { packages: PublicPackage[]; checksumsUrl: string; manifestUrl: string }) {
  return (
    <section className="card p-5" aria-labelledby="dl-h">
      <h2 id="dl-h" className="mb-2 text-[14px] font-bold">
        Downloads
      </h2>
      <ul className="divide-y divide-line">
        {groupByPlatform(packages)
          .flatMap((g) => g.packages)
          .map((p) => (
            <li key={p.id} className="flex items-center justify-between gap-3 py-2 text-[12.5px]">
              <a href={p.download_url} className="link" rel="nofollow">
                {packageLongLabel(p)}
                {p.hint && <span className="text-subtle no-underline"> ({p.hint})</span>}
              </a>
              <span className="shrink-0 font-mono text-[11.5px] text-muted">{formatBytes(p.size)}</span>
            </li>
          ))}
      </ul>
      <p className="mt-2 text-[11.5px] text-subtle">
        SHA-256 checksums:{' '}
        <a className="link" href={checksumsUrl}>
          checksums.txt
        </a>{' '}
        ·{' '}
        <a className="link" href={manifestUrl}>
          signed manifest
        </a>
      </p>
      <details className="mt-2 text-[11.5px]">
        <summary className="cursor-pointer text-subtle">Show SHA-256 per file</summary>
        <ul className="mt-1 space-y-1">
          {packages.map((p) => (
            <li key={p.id}>
              <span className="font-mono">{p.file_name}</span>
              <code className="block font-mono break-all text-muted">{p.sha256}</code>
            </li>
          ))}
        </ul>
      </details>
    </section>
  );
}

// ---------------------------------------------------------------- Subscribe

export function SubscribeForm({ app, appName, channel = 'STABLE', compact }: { app: string; appName: string; channel?: ChannelCode; compact?: boolean }) {
  const id = useId();
  const [email, setEmail] = useState('');
  const [state, setState] = useState<'idle' | 'busy' | 'done'>('idle');
  const [error, setError] = useState<string | null>(null);
  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setState('busy');
    setError(null);
    try {
      await publicApi.subscribe(email, app, channel);
      setState('done');
    } catch (err) {
      setError(err instanceof ApiError && err.fieldErrors.email ? err.fieldErrors.email : errorMessage(err));
      setState('idle');
    }
  };
  if (state === 'done')
    return (
      <p role="status" className="text-[12.5px] text-ok">
        Check your inbox to confirm the subscription. You can unsubscribe with one click from any email.
      </p>
    );
  return (
    <form onSubmit={submit} className={cx(compact ? '' : 'card p-4')} aria-labelledby={`${id}-t`}>
      <p id={`${id}-t`} className="mb-2 text-[13px] font-semibold text-ink">
        Notify me about {appName} releases
      </p>
      <div className="flex gap-2">
        <label htmlFor={id} className="sr-only">
          Email address
        </label>
        <input
          id={id}
          type="email"
          required
          autoComplete="email"
          placeholder="you@company.com"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          aria-invalid={!!error || undefined}
          aria-describedby={error ? `${id}-e` : undefined}
          className="h-9 min-w-0 flex-1 rounded-lg border border-line px-3 text-[13px] focus:border-brand focus:ring-2 focus:ring-brand/25 focus:outline-none"
        />
        <button type="submit" disabled={state === 'busy'} className="h-9 shrink-0 rounded-lg bg-indigo px-3 text-[12.5px] font-semibold text-white hover:bg-indigo-700 disabled:opacity-60">
          Subscribe
        </button>
      </div>
      {error && (
        <p id={`${id}-e`} role="alert" className="mt-1 text-[11.5px] text-bad">
          {error}
        </p>
      )}
      <p className="mt-1.5 text-[11px] text-subtle">Double opt-in. We only store your email and preferences.</p>
    </form>
  );
}

export function ShaInline({ sha }: { sha: string }) {
  return (
    <code className="font-mono text-[11px] text-muted" title={sha}>
      {shortHash(sha, 6)}
    </code>
  );
}
