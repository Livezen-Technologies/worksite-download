import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { adminApi, adminKeys } from '@/services/admin';
import { errorMessage } from '@/services/api';
import { useToast } from '@/components/ui/Toast';
import type { AdminReleaseDetail } from '@/types/api';

export function hasProcessing(r: AdminReleaseDetail | undefined): boolean {
  return !!r?.packages.some((p) => p.status === 'PROCESSING' || p.revisions.some((rv) => rv.status === 'PROCESSING' || rv.status === 'UPLOADING'));
}

export function useRelease(id: string) {
  return useQuery({
    queryKey: adminKeys.release(id),
    queryFn: () => adminApi.release(id),
    refetchInterval: (q) => (hasProcessing(q.state.data) ? 2000 : false),
  });
}

/** Mutation that refreshes the release (and lists/dashboard) and toasts the outcome. */
export function useReleaseMutation<TVars, TResult>(releaseId: string, fn: (vars: TVars) => Promise<TResult>, success?: string | ((r: TResult) => string)) {
  const qc = useQueryClient();
  const toast = useToast();
  return useMutation({
    mutationFn: fn,
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: adminKeys.release(releaseId) });
      void qc.invalidateQueries({ queryKey: ['admin', 'releases'] });
      void qc.invalidateQueries({ queryKey: adminKeys.dashboard });
      if (success) toast.success(typeof success === 'function' ? success(r) : success);
    },
    onError: (e) => {
      if ((e as { code?: string }).code !== 'MFA_CANCELLED') toast.error(errorMessage(e));
    },
  });
}
