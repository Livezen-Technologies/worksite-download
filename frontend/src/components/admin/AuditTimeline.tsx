import type { AuditEntry } from '@/types/api';
import { formatDateTime, timeAgo } from '@/core/format';

export function describeAudit(a: AuditEntry): string {
  const after = a.after && typeof a.after === 'object' ? (a.after as Record<string, unknown>) : null;
  const before = a.before && typeof a.before === 'object' ? (a.before as Record<string, unknown>) : null;
  if (before && after) {
    const keys = Object.keys(after).filter((k) => JSON.stringify(before[k]) !== JSON.stringify(after[k]));
    if (keys.length) return keys.map((k) => `${k}: ${String(before[k] ?? '—')} → ${String(after[k] ?? '—')}`).join(', ');
  }
  return '';
}

/** Audit timeline (append-only, hash-chained): actor, action, entity, reason, before/after. */
export function AuditTimeline({ entries, compact }: { entries: AuditEntry[]; compact?: boolean }) {
  if (!entries.length) return <p className="px-1 py-4 text-[12.5px] text-subtle">No activity yet.</p>;
  return (
    <ol className="relative space-y-3 border-l border-line pl-4">
      {entries.map((a) => {
        const change = describeAudit(a);
        return (
          <li key={a.id} className="relative text-[12.5px]">
            <span aria-hidden className="absolute top-1.5 -left-[21px] size-2.5 rounded-full border-2 border-white bg-brand" />
            <div>
              <strong>{a.actor_label ?? a.actor_type}</strong> <span className="font-mono text-[11.5px] text-indigo">{a.action}</span>
              {a.entity_id && !compact && <span className="text-subtle"> · {a.entity_id}</span>}
            </div>
            {change && <div className="font-mono text-[11px] text-muted">{change}</div>}
            {a.reason && <div className="text-muted">“{a.reason}”</div>}
            <div className="text-[11px] text-subtle">
              <time dateTime={a.created_at} title={formatDateTime(a.created_at)}>
                {timeAgo(a.created_at)}
              </time>
              {!compact && a.ip && <> · {a.ip}</>}
              {!compact && <span className="font-mono"> · #{a.hash.slice(0, 8)}</span>}
            </div>
          </li>
        );
      })}
    </ol>
  );
}
