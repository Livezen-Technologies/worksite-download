import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router';
import { publicApi, publicKeys } from '@/services/public';
import { useHead } from '@/core/head';
import { Badge } from '@/components/ui';
import { Container, QueryState } from './common';

const HOW: Record<string, string> = {
  STABLE: 'Everyone gets Stable by default. Direct downloads and automatic updates are available without signing in.',
  BETA: 'Sign in to the WorkSuite portal and turn on “Beta releases” in Profile. Organisations can also enable Beta for their users in Admin Center.',
  DEV: 'Nightly builds for authorised Livezen developers and QA only.',
  INTERNAL: 'Used by Livezen staff and the internal tenant before a public release.',
  CUSTOM: 'Private channels for named organisations by agreement with Livezen.',
};

export function ChannelsPage() {
  const q = useQuery({ queryKey: publicKeys.channels, queryFn: publicApi.channels });
  useHead({ title: 'Release channels — Stable, Beta and Dev | WorkSuite Downloads', description: 'How WorkSuite release channels work and how to opt in to Beta.', path: '/channels' });
  return (
    <Container className="py-8">
      <h1 className="mb-1 text-[24px] font-bold tracking-tight">Release channels</h1>
      <p className="mb-6 max-w-2xl text-[13px] text-muted">WorkSuite ships through channels. Stable is for everyone; Beta lets you try upcoming features early; Dev and Internal are for Livezen teams.</p>
      {q.data ? (
        <ul className="grid gap-4 md:grid-cols-2">
          {q.data.data.map((c) => (
            <li key={c.code} className="card p-5">
              <div className="flex items-center gap-2">
                <h2 className="text-[15px] font-bold">{c.name}</h2>
                {c.is_public ? <Badge tone="green">Public</Badge> : <Badge tone="purple">Opt-in</Badge>}
              </div>
              <p className="mt-1 text-[13px] text-muted">{c.description}</p>
              <p className="mt-2 text-[12.5px]">
                <strong>How to join: </strong>
                {HOW[c.code] ?? 'Contact support.'}
              </p>
              {c.code === 'BETA' && (
                <Link to="/portal/profile" className="link mt-2 inline-block text-[12.5px]">
                  Opt in to Beta
                </Link>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <QueryState error={q.error} isLoading={q.isLoading} refetch={q.refetch} />
      )}
    </Container>
  );
}
