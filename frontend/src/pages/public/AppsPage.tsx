import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { publicApi, publicKeys } from '@/services/public';
import { useHead } from '@/core/head';
import { formatDateLong, PLATFORM_LABEL } from '@/core/format';
import { AppIcon } from '@/components/public/blocks';
import { Badge } from '@/components/ui';
import { Container, QueryState } from './common';

export function AppsPage() {
  const q = useQuery({ queryKey: publicKeys.apps, queryFn: publicApi.apps });
  useHead({ title: 'All WorkSuite applications | WorkSuite Downloads', description: 'Download WorkSuite Desktop, Installer, Quick Support, Sync Agent and WorkSuite Server.', path: '/apps' });
  return (
    <Container className="py-8">
      <h1 className="mb-1 text-[24px] font-bold tracking-tight">Applications</h1>
      <p className="mb-6 text-[13px] text-subtle">Every public WorkSuite application with its latest stable version.</p>
      {q.data ? (
        <ul className="grid gap-4 md:grid-cols-2">
          {q.data.data.map((a) => (
            <li key={a.id} className="card flex gap-4 p-5">
              <AppIcon app={a} size="md" />
              <div className="min-w-0 flex-1">
                <h2 className="text-[15px] font-bold">
                  <Link to={`/apps/${a.slug}`} className="hover:text-brand">
                    {a.name}
                  </Link>
                </h2>
                <p className="mt-0.5 text-[12.5px] text-muted">{a.description}</p>
                <div className="mt-2 flex flex-wrap items-center gap-2 text-[12px]">
                  {a.latest_stable ? (
                    <>
                      <Badge tone="green">Stable {a.latest_stable.version}</Badge>
                      <span className="text-subtle">{formatDateLong(a.latest_stable.release_date ?? a.latest_stable.published_at)}</span>
                    </>
                  ) : (
                    <Badge>No stable release yet</Badge>
                  )}
                  {a.latest_beta && <Badge tone="purple">Beta {a.latest_beta.version}</Badge>}
                </div>
                <p className="mt-2 text-[11.5px] text-subtle">{Array.from(new Set(a.platforms.map((p) => PLATFORM_LABEL[p.platform]))).join(' · ')}</p>
              </div>
            </li>
          ))}
        </ul>
      ) : (
        <QueryState error={q.error} isLoading={q.isLoading} refetch={q.refetch} />
      )}
    </Container>
  );
}
