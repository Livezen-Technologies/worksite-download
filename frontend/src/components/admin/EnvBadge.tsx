import type { Environment } from '@/types/api';
import { cx } from '@/core/format';

/** PRODUCTION / STAGING / DEV badge (NFR-C14). */
export function EnvBadge({ env, className }: { env: Environment | null | undefined; className?: string }) {
  if (!env) return null;
  const cls = env === 'PRODUCTION' ? 'bg-bad-bg text-bad ring-bad/30' : env === 'STAGING' ? 'bg-warn-bg text-warn ring-warn/30' : 'bg-ok-bg text-ok ring-ok/30';
  return (
    <span className={cx('inline-flex items-center rounded-md px-1.5 py-0.5 text-[10.5px] font-bold tracking-[0.06em] ring-1 ring-inset', cls, className)} title={`Environment: ${env}`}>
      {env}
    </span>
  );
}
