import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { publicApi, publicKeys } from '@/services/public';
import { useHead } from '@/core/head';
import { PreviousVersions } from '@/components/public/blocks';
import { Breadcrumb, Container, QueryState } from './common';

export function VersionsPage() {
  const { slug = '' } = useParams();
  const app = useQuery({ queryKey: publicKeys.app(slug), queryFn: () => publicApi.app(slug) });
  const v = useQuery({ queryKey: publicKeys.versions(slug), queryFn: () => publicApi.versions(slug) });
  const name = app.data?.name ?? slug;
  useHead(app.data ? { title: `${name} previous versions | WorkSuite Downloads`, description: `All ${name} versions with their support state (supported, deprecated, unsupported).`, path: `/apps/${slug}/versions` } : null);
  const latest = v.data?.data.find((x) => x.is_latest)?.version ?? app.data?.latest_stable?.version ?? null;
  return (
    <Container className="py-8">
      <Breadcrumb items={[{ label: 'Applications', to: '/apps' }, { label: name, to: `/apps/${slug}` }, { label: 'Versions' }]} />
      <h1 className="mt-1 mb-4 text-[24px] font-bold tracking-tight">{name} — previous versions</h1>
      {v.data ? (
        <div className="max-w-3xl">
          <PreviousVersions versions={v.data.data} latestVersion={latest} title="All versions" />
          <p className="mt-3 text-[12px] text-subtle">Supported versions can be downloaded. Deprecated versions still work but should be updated. Unsupported versions can no longer be downloaded.</p>
        </div>
      ) : (
        <QueryState error={v.error} isLoading={v.isLoading} refetch={v.refetch} />
      )}
    </Container>
  );
}
