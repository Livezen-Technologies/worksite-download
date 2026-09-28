import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { Download } from 'lucide-react';
import { publicApi, publicKeys } from '@/services/public';
import type { AppSummary, Platform, PublicPackage } from '@/types/api';
import type { DetectedPlatform } from '@/core/platform';
import { usePlatform } from '@/hooks/usePlatform';
import { useHead } from '@/core/head';
import { archLabel, formatBytes, PACKAGE_LABEL, PLATFORM_LABEL } from '@/core/format';
import { latestResolverUrl, pickForPlatform, sortPackages } from '@/core/packages';
import { softwareJsonLd } from '@/core/seo';
import { AppHero, PlatformIcon, SubscribeForm } from '@/components/public/blocks';
import { Container, QueryState } from './common';

export function primaryApp(apps: AppSummary[]): AppSummary | undefined {
  return apps.find((a) => a.slug === 'desktop') ?? apps.find((a) => a.kind === 'DESKTOP' && a.latest_stable) ?? apps[0];
}

const DESKTOP_PLATFORMS: Platform[] = ['windows', 'macos', 'linux'];

function summaryFor(platform: Platform, pkgs: PublicPackage[]): string {
  const list = pkgs.filter((p) => p.platform === platform);
  const archs = Array.from(new Set(list.map((p) => archLabel(platform, p.architecture))));
  const types = Array.from(new Set(list.map((p) => PACKAGE_LABEL[p.package_type])));
  return platform === 'linux' ? `${types.join(' · ')} · ${archs.join(' · ')}` : `${archs.join(' · ')} · ${types.join(' / ')}`;
}

/** Primary CTA for the detected platform (§5.2): one button, "not right?" link, never blocks. */
export function DetectedCta({ slug, version, packages, detected }: { slug: string; version: string; packages: PublicPackage[]; detected: DetectedPlatform }) {
  const desktopDetected = detected.platform && DESKTOP_PLATFORMS.includes(detected.platform) ? detected.platform : null;
  const mobile = detected.platform === 'android' || detected.platform === 'ios';
  const platform: Platform = desktopDetected ?? 'windows';
  const arch = desktopDetected ? detected.arch : 'x64';
  const main = pickForPlatform(packages, platform, platform === 'macos' && !detected.archConfirmed ? 'arm64' : arch);
  if (!main) {
    return (
      <a href="#other-platforms" className="inline-flex h-11 items-center gap-2 rounded-lg bg-brand px-5 text-[14px] font-semibold text-white hover:bg-indigo">
        <Download className="size-4" aria-hidden /> Choose your platform
      </a>
    );
  }
  const macIntel = platform === 'macos' && !detected.archConfirmed ? sortPackages(packages).find((p) => p.platform === 'macos' && p.architecture === 'x64' && p.recommended) : null;
  const winArm = platform === 'windows' && main.architecture !== 'arm64' ? packages.find((p) => p.platform === 'windows' && p.architecture === 'arm64' && p.recommended) : null;
  const linuxAlt = platform === 'linux' ? sortPackages(packages).filter((p) => p.platform === 'linux' && p.id !== main.id) : [];
  const label = platform === 'macos' ? (macIntel ? 'Download for Apple Silicon' : `Download for Mac (${archLabel('macos', main.architecture)})`) : `Download for ${PLATFORM_LABEL[platform]}`;
  return (
    <div>
      {mobile && <p className="mb-3 text-[13px] text-[#c9d0ea]">WorkSuite Desktop runs on computers. Pick the platform of the computer you want to install on.</p>}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
        <a href={latestResolverUrl(slug, main)} className="inline-flex h-11 items-center gap-2 rounded-lg bg-brand px-5 text-[14px] font-semibold text-white shadow-lg shadow-black/20 hover:bg-[#4b69e3]" rel="nofollow">
          <Download className="size-4" aria-hidden /> {label}
        </a>
        {macIntel && (
          <a href={latestResolverUrl(slug, macIntel)} className="inline-flex h-11 items-center rounded-lg border border-white/30 px-4 text-[13px] font-semibold text-white hover:bg-white/10" rel="nofollow">
            Intel Mac
          </a>
        )}
        <div className="text-[12px] leading-5 text-[#c9d0ea]">
          <div>
            Version <span className="font-mono">{version}</span> · {archLabel(main.platform, main.architecture)} · {PACKAGE_LABEL[main.package_type]} · {formatBytes(main.size)}
          </div>
          <div>
            {desktopDetected ? `Detected ${PLATFORM_LABEL[desktopDetected]}` : `Not on ${PLATFORM_LABEL[platform]}?`} —{' '}
            <a href="#other-platforms" className="text-white underline underline-offset-2">
              {desktopDetected ? 'not right? Choose another platform' : 'choose another platform'}
            </a>
          </div>
        </div>
      </div>
      {winArm && (
        <p className="mt-3 text-[12px] text-[#aab4d8]">
          Snapdragon / Surface Pro X?{' '}
          <a href={latestResolverUrl(slug, winArm)} className="text-white underline underline-offset-2" rel="nofollow">
            Download for ARM64
          </a>{' '}
          · How to check: Settings › System › About › System type.
        </p>
      )}
      {linuxAlt.length > 0 && (
        <p className="mt-3 text-[12px] text-[#aab4d8]">
          Also available:{' '}
          {linuxAlt.map((p, i) => (
            <span key={p.id}>
              {i > 0 && ' · '}
              <a href={latestResolverUrl(slug, p)} className="text-white underline underline-offset-2" rel="nofollow">
                {PACKAGE_LABEL[p.package_type]}
              </a>
            </span>
          ))}
        </p>
      )}
    </div>
  );
}

export function HomePage() {
  const site = useQuery({ queryKey: publicKeys.site, queryFn: publicApi.site });
  const main = site.data ? primaryApp(site.data.apps) : undefined;
  const app = useQuery({ queryKey: publicKeys.app(main?.slug ?? ''), queryFn: () => publicApi.app(main!.slug), enabled: !!main });
  const detected = usePlatform();
  const latest = app.data?.latest ?? null;

  useHead(
    app.data
      ? {
          title: 'WorkSuite Downloads — Download WorkSuite for Windows, macOS & Linux',
          description: `Download the latest ${app.data.name}${latest ? ` ${latest.version}` : ''} for Windows, macOS and Linux, plus WorkSuite Installer, Quick Support and Sync Agent.`,
          path: '/',
          jsonLd: softwareJsonLd(app.data, latest),
        }
      : null,
  );

  if (!site.data) return <QueryState error={site.error} isLoading={site.isLoading} refetch={site.refetch} />;
  const apps = site.data!.apps;
  const pkgs = latest?.packages ?? [];
  return (
    <>
      <AppHero
        eyebrow={main ? `${main.name} · Stable` : undefined}
        title={app.data?.page.hero.title ?? 'Download the latest WorkSuite applications'}
        subtitle={app.data?.page.hero.subtitle ?? main?.description}
      >
        {main && latest ? <DetectedCta slug={main.slug} version={latest.version} packages={pkgs} detected={detected} /> : app.isLoading ? <div className="h-11" /> : null}
      </AppHero>

      <Container className="py-8">
        <section id="other-platforms" aria-labelledby="other-h" className="scroll-mt-4">
          <div className="mb-3 flex flex-wrap items-baseline gap-2">
            <h2 id="other-h" className="text-[16px] font-bold">
              Other platforms
            </h2>
            <span className="text-[12px] text-subtle">Browser detection isn’t perfect — pick yours.</span>
          </div>
          <ul className="grid gap-3 sm:grid-cols-3">
            {DESKTOP_PLATFORMS.filter((p) => pkgs.some((x) => x.platform === p)).map((p) => (
              <li key={p}>
                <Link to={`/apps/${main!.slug}#platforms`} className="card flex items-center gap-3 px-4 py-3 hover:border-brand">
                  <PlatformIcon platform={p} />
                  <span>
                    <span className="block text-[13.5px] font-semibold">{PLATFORM_LABEL[p]}</span>
                    <span className="block text-[11.5px] text-subtle">{summaryFor(p, pkgs)}</span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </section>

        <section aria-labelledby="apps-h" className="mt-8">
          <h2 id="apps-h" className="mb-3 text-[16px] font-bold">
            WorkSuite applications
          </h2>
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {apps.map((a) => (
              <li key={a.id}>
                <Link to={`/apps/${a.slug}`} className="card block px-4 py-3 hover:border-brand">
                  <span className="block text-[13.5px] font-semibold">{a.name}</span>
                  <span className="block text-[11.5px] text-subtle">
                    {a.latest_stable ? (
                      <>
                        Stable <span className="font-mono">{a.latest_stable.version}</span>
                      </>
                    ) : (
                      'Coming soon'
                    )}
                    {a.latest_beta && (
                      <>
                        {' '}
                        · Beta <span className="font-mono">{a.latest_beta.version}</span>
                      </>
                    )}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </section>

        {main && (
          <div className="mt-8 max-w-md">
            <SubscribeForm app={main.slug} appName={main.name} />
          </div>
        )}
      </Container>
    </>
  );
}
