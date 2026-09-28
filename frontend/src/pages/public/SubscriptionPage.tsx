import { useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router';
import { publicApi } from '@/services/public';
import { Container, QueryState } from './common';

export function SubscriptionPage({ mode }: { mode: 'confirm' | 'unsubscribe' }) {
  const [sp] = useSearchParams();
  const token = sp.get('token') ?? '';
  const q = useQuery({ queryKey: ['subscription', mode, token], queryFn: () => (mode === 'confirm' ? publicApi.confirmSubscription(token) : publicApi.unsubscribe(token)), enabled: !!token, retry: false });
  return (
    <Container className="py-12">
      <div className="card mx-auto max-w-lg p-8 text-center" role="status">
        {!token ? (
          <p>This link is missing its token.</p>
        ) : q.data ? (
          <>
            <h1 className="text-[20px] font-bold">{mode === 'confirm' ? 'Subscription confirmed' : 'You are unsubscribed'}</h1>
            <p className="mt-2 text-[13px] text-subtle">{mode === 'confirm' ? 'We’ll email you when a new release is published.' : 'You will no longer receive release emails.'}</p>
          </>
        ) : (
          <QueryState error={q.error} isLoading={q.isLoading} />
        )}
      </div>
    </Container>
  );
}
