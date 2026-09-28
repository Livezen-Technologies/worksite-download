import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router';
import { Rss } from 'lucide-react';
import { publicApi, publicKeys } from '@/services/public';
import { useHead } from '@/core/head';
import { appTitle, softwareJsonLd } from '@/core/seo';
import { Alert } from '@/components/ui';
import {
  ChecksumPanel,
  FaqList,
  InstallationHelp,
  LatestVersion,
  PlatformDownloads,
  PreviousVersions,
  ReleaseHighlights,
  SubscribeForm,
  SystemRequirements,
} from '@/components/public/blocks';
import { Breadcrumb, Container, QueryState } from './common';

export function AppPage() {
  const { slug = '' } = useParams();
  const q = useQuery({ queryKey: publicKeys.app(slug), queryFn: () => publicApi.app(slug) });
  const app = q.data;
  const latest = app?.latest ?? null;
  useHead(
    app
      ? {
          title: appTitle(app),
          description: `${app.description}${latest ? ` Latest stable: ${latest.version}.` : ''}`,
          path: `/apps/${app.slug}`,
          jsonLd: softwareJsonLd(app, latest),
          feeds: { rss: `/apps/${app.slug}/changelog/feed`, atom: `/apps/${app.slug}/changelog/atom`, title: `${app.name} releases` },
        }
      : null,
  );
  if (!app) return <QueryState error={q.error} isLoading={q.isLoading} refetch={q.refetch} />;
  const page = app.page;
  return (
    <Container className="py-8">
      <LatestVersion app={app} release={latest} breadcrumb={<Breadcrumb items={[{ label: 'Applications', to: '/apps' }, { label: app.name.replace(/^WorkSuite\s+/, '') }]} />} />
      {page.description_html && <div className="prose-uc mt-3 max-w-3xl text-muted" dangerouslySetInnerHTML={{ __html: page.description_html }} />}

      <div className="mt-6">
        {latest ? (
          latest.packages.length ? (
            <PlatformDownloads slug={app.slug} packages={latest.packages} requirements={latest.requirements} />
          ) : (
            <Alert tone="warn">{latest.unavailable_message ?? 'Downloads for this version are not available.'}</Alert>
          )
        ) : (
          <Alert>There is no stable release of {app.name} yet.</Alert>
        )}
      </div>

      {latest && (
        <div className="mt-4 grid gap-4 lg:grid-cols-[1.6fr_1fr]">
          <ReleaseHighlights release={latest} slug={app.slug} />
          <section className="card p-5" aria-labelledby="more-h">
            <h2 id="more-h" className="mb-2 text-[14px] font-bold">
              More
            </h2>
            <ul className="space-y-1.5 text-[12.5px]">
              <li>
                <Link className="link" to={`/apps/${app.slug}/versions`}>
                  Previous versions
                </Link>
              </li>
              <li>
                <Link className="link" to={`/apps/${app.slug}/requirements`}>
                  System requirements
                </Link>
              </li>
              <li>
                <a className="link" href="#install">
                  Installation help
                </a>
              </li>
              <li>
                <a className="link" href="#verify">
                  Verify your download (SHA-256)
                </a>
              </li>
              <li>
                <Link className="link" to={`/apps/${app.slug}/changelog`}>
                  Changelog
                </Link>{' '}
                ·{' '}
                <a className="link inline-flex items-center gap-1" href={`/apps/${app.slug}/changelog/feed`}>
                  <Rss className="size-3" aria-hidden /> RSS
                </a>
              </li>
              {page.support_url && (
                <li>
                  <a className="link" href={page.support_url} rel="noopener">
                    Support
                  </a>
                </li>
              )}
            </ul>
            <p className="mt-3 text-[11.5px] text-subtle">Every installer is code-signed by Livezen Technologies (Pvt) Ltd.</p>
            {app.channels.some((c) => c.code === 'BETA') && (
              <p className="mt-2 text-[11.5px] text-subtle">
                Want early access?{' '}
                <Link className="link" to="/channels">
                  Join the Beta channel
                </Link>
                .
              </p>
            )}
          </section>
        </div>
      )}

      <div className="mt-4 grid gap-4">
        <SystemRequirements requirements={latest?.requirements && Object.keys(latest.requirements).length ? latest.requirements : page.requirements} />
        <InstallationHelp help={page.install_help} />
        {latest && latest.packages.length > 0 && <ChecksumPanel packages={latest.packages} checksumsUrl={latest.checksums_url} manifestUrl={latest.manifest_url} />}
        {latest && latest.previous_versions.length > 1 && <PreviousVersions versions={latest.previous_versions} latestVersion={latest.version} limit={5} slug={app.slug} />}
        <FaqList faq={page.faq} />
        <div className="max-w-md">
          <SubscribeForm app={app.slug} appName={app.name} />
        </div>
      </div>
    </Container>
  );
}
