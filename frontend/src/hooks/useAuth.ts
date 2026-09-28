import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback } from 'react';
import { ApiError } from '@/services/api';
import { authApi } from '@/services/auth';
import type { MeResponse } from '@/types/api';

export const meKey = ['auth', 'me'] as const;

export function useAuth() {
  const qc = useQueryClient();
  const q = useQuery<MeResponse | null>({
    queryKey: meKey,
    queryFn: async () => {
      try {
        return await authApi.me();
      } catch (e) {
        if (e instanceof ApiError && (e.status === 401 || e.status === 403)) return null;
        throw e;
      }
    },
    staleTime: 60_000,
    retry: false,
  });
  const logout = useCallback(async () => {
    try {
      await authApi.logout();
    } finally {
      qc.setQueryData(meKey, null);
      qc.removeQueries({ predicate: (query) => query.queryKey[0] === 'admin' || query.queryKey[0] === 'bt' || query.queryKey[0] === 'portal' });
    }
  }, [qc]);
  const refresh = useCallback(() => qc.invalidateQueries({ queryKey: meKey }), [qc]);
  return {
    user: q.data?.user ?? null,
    environment: q.data?.environment ?? null,
    stepUpValidUntil: q.data?.step_up_valid_until ?? null,
    loading: q.isLoading,
    error: q.error,
    logout,
    refresh,
  };
}
