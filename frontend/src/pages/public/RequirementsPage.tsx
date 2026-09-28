import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router';
import { publicApi, publicKeys } from '@/services/public';
import { useHead } from '@/core/head';
import { SystemRequirements } from '@/components/public/blocks';
import { Breadcrumb, Container, QueryState } from './common';

export function RequirementsPage() {
  const { slug = '' } = useParams();
  const q = useQuery({ queryKey: publicKeys.app(slug), queryFn: () => publicApi.app(slug) });
  const app = q.data;
  useHead(app ? { title: `${app.name} System Requirements | WorkSuite Downloads`, description: `Operating system, processor, memory and disk requirements for ${app.name}.`, path: `/apps/${slug}/requirements` } : null);
  if (!app) return <QueryState error={q.error} isLoading={q.isLoading} refetch={q.refetch} />;
  const overrides = app.latest?.requirements ?? {};
  const merged = { ...app.page.requirements, ...overrides };
  return (
    <Container className="py-8">
      <Breadcrumb items={[{ label: 'Applications', to: '/apps' }, { label: app.name, to: `/apps/${slug}` }, { label: 'System requirements' }]} />
      <h1 className="mt-1 mb-4 text-[24px] font-bold tracking-tight">{app.name} system requirements</h1>
      <SystemRequirements requirements={merged} title={app.latest ? `Requirements for ${app.latest.version}` : 'Requirements'} />
      <p className="mt-3 text-[12px] text-subtle">Application defaults, with any overrides declared by the latest stable version.</p>
    </Container>
  );
}
