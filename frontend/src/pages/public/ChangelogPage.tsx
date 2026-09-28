import { useQuery } from '@tanstack/react-query';
import { Link, useParams, useSearchParams } from 'react-router';
import { Rss } from 'lucide-react';
import { PAGE_SIZE, publicApi, publicKeys } from '@/services/public';
import { useHead } from '@/core/head';
import { formatDateLong } from '@/core/format';
import { Badge } from '@/components/ui';
import { SupportStateBadge } from '@/components/StatusBadges';
import { ChangelogSectionsView } from '@/components/public/blocks';
import type { ChangelogEntry } from '@/types/api';
import { Breadcrumb, Container, Pager, QueryState } from './common';

function Entry({ e, showApp }: { e: ChangelogEntry; showApp?: boolean }) {
  return (
    <article className="card p-5" aria-labelledby={`cl-${e.id}`}>
      <div className="flex flex-wrap items-center gap-2">
        <h2 id={`cl-${e.id}`} className="text-[16px] font-bold">
          <Link to={e.notes_url} className="hover:text-brand">
            {showApp && e.app ? `${e.app.name} ` : ''}
            <span className="font-mono">{e.version}</span>
          </Link>
        </h2>
        {e.is_latest && <Badge tone="green">Latest</Badge>}
        {e.support_state !== 'SUPPORTED' && <SupportStateBadge state={e.support_state} />}
        <span className="text-[12px] text-subtle">{formatDateLong(e.release_date ?? e.published_at)}</span>
      </div>
      {e.changelog.summary && <p className="mt-2 text-[13px] text-muted">{e.changelog.summary}</p>}
      <div className="mt-3">
        <ChangelogSectionsView sections={e.changelog.sections} headingLevel={3} />
      </div>
      {!showApp && (
        <Link to={e.notes_url} className="link mt-3 inline-block text-[12px]">
          Downloads and checksums for {e.version}
        </Link>
      )}
      {showApp && e.app && (
        <Link to={`/apps/${e.app.slug}`} className="link mt-3 inline-block text-[12px]">
          {e.app.name} downloads
        </Link>
      )}
    </article>
  );
}

function usePage() {
  const [sp] = useSearchParams();
  return Math.max(1, Number(sp.get('page') ?? 1) || 1);
}

export function AppChangelogPage() {
  const { slug = '' } = useParams();
  const page = usePage();
  const offset = (page - 1) * PAGE_SIZE;
  const app = useQuery({ queryKey: publicKeys.app(slug), queryFn: () => publicApi.app(slug) });
  const q = useQuery({ queryKey: publicKeys.appChangelog(slug, offset), queryFn: () => publicApi.appChangelog(slug, offset) });
  const name = app.data?.name ?? slug;
  useHead(
    app.data
      ? {
          title: `${name} Changelog | WorkSuite Downloads`,
          description: `Release notes for every ${name} version: new features, improvements, bug fixes and security updates.`,
          path: `/apps/${slug}/changelog${page > 1 ? `?page=${page}` : ''}`,
          feeds: { rss: `/apps/${slug}/changelog/feed`, atom: `/apps/${slug}/changelog/atom`, title: `${name} releases` },
        }
      : null,
  );
  return (
    <Container className="py-8">
      <Breadcrumb items={[{ label: 'Applications', to: '/apps' }, { label: name, to: `/apps/${slug}` }, { label: 'Changelog' }]} />
      <div className="mt-1 mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-[24px] font-bold tracking-tight">{name} changelog</h1>
        <p className="flex items-center gap-2 text-[12.5px]">
          <Rss className="size-3.5 text-subtle" aria-hidden />
          <a className="link" href={`/apps/${slug}/changelog/feed`}>
            RSS
          </a>
          <a className="link" href={`/apps/${slug}/changelog/atom`}>
            Atom
          </a>
        </p>
      </div>
      {q.data ? (
        <div className="grid max-w-3xl gap-4">
          {q.data.data.map((e) => (
            <Entry key={e.id} e={e} />
          ))}
          <Pager basePath={`/apps/${slug}/changelog`} page={page} total={q.data.total} pageSize={PAGE_SIZE} />
        </div>
      ) : (
        <QueryState error={q.error} isLoading={q.isLoading} refetch={q.refetch} />
      )}
    </Container>
  );
}

export function ChangelogPage() {
  const page = usePage();
  const offset = (page - 1) * PAGE_SIZE;
  const q = useQuery({ queryKey: publicKeys.changelog(offset), queryFn: () => publicApi.changelog(offset) });
  useHead({
    title: 'WorkSuite Release Notes | WorkSuite Downloads',
    description: 'Release notes for all WorkSuite applications. Subscribe via RSS or Atom.',
    path: `/changelog${page > 1 ? `?page=${page}` : ''}`,
    feeds: { rss: '/changelog/feed', atom: '/changelog/atom', title: 'WorkSuite release notes' },
  });
  return (
    <Container className="py-8">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
        <h1 className="text-[24px] font-bold tracking-tight">Release notes</h1>
        <p className="flex items-center gap-2 text-[12.5px]">
          <Rss className="size-3.5 text-subtle" aria-hidden />
          <a className="link" href="/changelog/feed">
            RSS
          </a>
          <a className="link" href="/changelog/atom">
            Atom
          </a>
        </p>
      </div>
      {q.data ? (
        <div className="grid max-w-3xl gap-4">
          {q.data.data.map((e) => (
            <Entry key={e.id} e={e} showApp />
          ))}
          <Pager basePath="/changelog" page={page} total={q.data.total} pageSize={PAGE_SIZE} />
        </div>
      ) : (
        <QueryState error={q.error} isLoading={q.isLoading} refetch={q.refetch} />
      )}
    </Container>
  );
}
