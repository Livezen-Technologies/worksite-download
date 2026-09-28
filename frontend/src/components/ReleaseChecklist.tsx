import { Check, Circle, Minus, X } from 'lucide-react';
import type { Checklist, ChecklistItem } from '@/types/api';
import { cx } from '@/core/format';

const ICON = {
  pass: { icon: Check, cls: 'text-ok', label: 'Passed' },
  fail: { icon: X, cls: 'text-bad', label: 'Failed' },
  pending: { icon: Circle, cls: 'text-warn fill-warn', label: 'Pending' },
  skipped: { icon: Minus, cls: 'text-subtle', label: 'Skipped' },
} as const;

export function checklistBlockers(c: Checklist | null | undefined): ChecklistItem[] {
  return (c?.items ?? []).filter((i) => i.blocking && i.status !== 'pass' && i.status !== 'skipped');
}

/** Release validation checklist (§7.4). Blocking items that aren't passing block publish. */
export function ReleaseChecklist({ checklist, compact, columns = 1 }: { checklist: Checklist; compact?: boolean; columns?: 1 | 2 }) {
  const blockers = checklistBlockers(checklist);
  return (
    <div>
      <ul className={cx('text-[12.5px]', columns === 2 ? 'grid gap-x-6 gap-y-1.5 sm:grid-cols-2' : 'space-y-1.5')} aria-label="Release checklist">
        {checklist.items.map((item) => {
          const s = ICON[item.status] ?? ICON.pending;
          const Icon = s.icon;
          return (
            <li key={item.key} className="flex items-start gap-2" data-status={item.status}>
              <Icon className={cx('mt-0.5 size-3.5 shrink-0', s.cls, item.status === 'pending' && 'size-2.5 mt-1')} aria-hidden strokeWidth={3} />
              <span className="min-w-0">
                <span className="sr-only">{s.label}: </span>
                <span className={cx(item.status === 'fail' && 'font-semibold text-bad')}>{item.label}</span>
                {!item.blocking && <span className="ml-1 text-[11px] text-subtle">(advisory)</span>}
                {item.detail && !compact && <span className="block text-[11.5px] text-subtle">{item.detail}</span>}
              </span>
            </li>
          );
        })}
      </ul>
      <p className={cx('mt-3 rounded-md px-2.5 py-1.5 text-[11.5px] font-medium', blockers.length ? 'bg-warn-bg text-warn' : 'bg-ok-bg text-ok')} role="status">
        {blockers.length ? `${blockers.length} blocking item${blockers.length > 1 ? 's' : ''} — publish is blocked until all pass.` : 'All checks passed.'}
      </p>
    </div>
  );
}
