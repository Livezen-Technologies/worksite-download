import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';
import { reportProblemPath } from '@/core/bugreport';
import { Rss } from 'lucide-react';
import { publicApi, publicKeys } from '@/services/public';
import { useHead } from '@/core/head';
import { releaseTitle, softwareJsonLd } from '@/core/seo';
import { formatDateLong, titleCase } from '@/core/format';
import { Alert, Badge } from '@/components/ui';
import { ChannelBadge, SupportStateBadge } from '@/components/StatusBadges';
import { ChangelogSectionsView, DownloadList, PreviousVersions } from '@/components/public/blocks';
import { Breadcrumb, Container, QueryState } from './common';

export function ReleasePage() {
  const { slug = '', version = '' } = useParams();
  const q = useQuery({ queryKey: publicKeys.release(slug, version), queryFn: () => publicApi.release(slug, version) });
  const r = q.data;
  useHead(
    r
      ? {
          title: releaseTitle(r.app.name, r.version),
          description: r.summary ?? r.changelog.summary ?? `What’s new in ${r.app.name} ${r.version}: features, improvements, bug fixes, security and known issues.`,
          path: `/apps/${slug}/releases/${r.version}`,
          noindex: r.channel !== 'STABLE',
          ogType: 'article',
          jsonLd: softwareJsonLd(r.app, r),
          feeds: { rss: `/apps/${slug}/changelog/feed`, atom: `/apps/${slug}/changelog/atom`, title: `${r.app.name} releases` },
        }
      : null,
  );
  if (!r) return <QueryState error={q.error} isLoading={q.isLoading} refetch={q.refetch} />;
  const latestStable = r.previous_versions.find((v) => v.is_latest)?.version ?? r.app.latest_stable?.version ?? null;
  return (
    <Container className="py-8">
      <div className="grid items-start gap-4 lg:grid-cols-[1.6fr_1fr]">
        <article className="card p-6" aria-labelledby="rel-h">
          <Breadcrumb items={[{ label: r.app.name.replace(/^WorkSuite\s+/, ''), to: `/apps/${slug}` }, { label: 'Releases', to: `/apps/${slug}/changelog` }, { label: r.version }]} />
          <div className="mt-1 flex flex-wrap items-center gap-2">
            <h1 id="rel-h" className="text-[24px] font-bold tracking-tight">
              {r.app.name} <span className="font-mono">{r.version}</span>
            </h1>
            {r.is_latest && <Badge tone="green">Latest</Badge>}
            <ChannelBadge channel={r.channel} />
            {r.support_state !== 'SUPPORTED' && <SupportStateBadge state={r.support_state} />}
          </div>
          <p className="mt-1 text-[12.5px] text-subtle">
            Released {formatDateLong(r.release_date ?? r.published_at)} · build {r.build} · {titleCase(r.release_type)} update
          </p>
          {r.support_state === 'DEPRECATED' && (
            <Alert tone="warn" className="mt-3">
              This version is deprecated. It still works, but please install {latestStable ?? 'the latest version'}.
            </Alert>
          )}
          {r.changelog.summary && <p className="mt-4 text-[13.5px]">{r.changelog.summary}</p>}
          <div className="mt-4">
            <ChangelogSectionsView sections={r.changelog.sections} />
          </div>
          <p className="mt-6 flex items-center gap-2 text-[12px] text-subtle">
            <Rss className="size-3.5" aria-hidden />
            <a className="link" href={`/apps/${slug}/changelog/feed`}>
              RSS
            </a>
            ·
            <a className="link" href={`/apps/${slug}/changelog/atom`}>
              Atom
            </a>
            ·
            <Link className="link" to={`/apps/${slug}/changelog`}>
              All release notes
            </Link>
            ·
            <Link className="link" to={reportProblemPath({ app: slug, version: r.version })}>
              Report a problem with {r.version}
            </Link>
          </p>
        </article>
        <div className="grid gap-4">
          {r.download_available && r.packages.length > 0 ? (
            <DownloadList packages={r.packages} checksumsUrl={r.checksums_url} manifestUrl={r.manifest_url} />
          ) : (
            <section className="card p-5" aria-labelledby="dl-h">
              <h2 id="dl-h" className="mb-1 text-[14px] font-bold">
                Downloads
              </h2>
              <p className="text-[12.5px] text-muted">{r.unavailable_message ?? `Download unavailable. Please install ${latestStable ?? 'the latest version'}.`}</p>
              {latestStable && (
                <Link to={`/apps/${slug}`} className="link mt-2 inline-block text-[12.5px]">
                  Get {r.app.name} {latestStable}
                </Link>
              )}
            </section>
          )}
          <PreviousVersions versions={r.previous_versions} latestVersion={latestStable} limit={6} slug={slug} />
        </div>
      </div>
    </Container>
  );
}
