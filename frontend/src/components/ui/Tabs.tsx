import { useRef, type KeyboardEvent, type ReactNode } from 'react';
import { Link } from 'react-router';
import { cx } from '@/core/format';

export interface TabItem {
  id: string;
  label: ReactNode;
  to?: string;
  count?: number;
}

/**
 * ARIA tablist. With `to`, tabs are router links (each tab is a URL); otherwise controlled via onSelect.
 * Arrow keys move focus between tabs (roving tabindex); Enter/Space activates.
 */
export function Tabs({ items, active, onSelect, label, panelId, className }: { items: TabItem[]; active: string; onSelect?: (id: string) => void; label: string; panelId?: string; className?: string }) {
  const refs = useRef<(HTMLElement | null)[]>([]);
  const onKey = (e: KeyboardEvent, i: number) => {
    let next = -1;
    if (e.key === 'ArrowRight') next = (i + 1) % items.length;
    if (e.key === 'ArrowLeft') next = (i - 1 + items.length) % items.length;
    if (e.key === 'Home') next = 0;
    if (e.key === 'End') next = items.length - 1;
    if (next >= 0) {
      e.preventDefault();
      refs.current[next]?.focus();
    }
  };
  return (
    <div role="tablist" aria-label={label} className={cx('flex gap-1 overflow-x-auto border-b border-line', className)}>
      {items.map((t, i) => {
        const selected = t.id === active;
        const cls = cx(
          '-mb-px inline-flex shrink-0 items-center gap-1.5 border-b-2 px-2.5 py-2 text-[13px] whitespace-nowrap transition-colors',
          selected ? 'border-indigo font-semibold text-indigo' : 'border-transparent text-muted hover:text-ink',
        );
        const common = {
          role: 'tab',
          id: `tab-${t.id}`,
          'aria-selected': selected,
          'aria-controls': panelId,
          tabIndex: selected ? 0 : -1,
          className: cls,
          onKeyDown: (e: KeyboardEvent) => onKey(e, i),
        } as const;
        const content = (
          <>
            {t.label}
            {t.count !== undefined && <span className={cx('rounded-full px-1.5 text-[10.5px] font-bold', selected ? 'bg-brand-50 text-indigo' : 'bg-[#eef0f5] text-muted')}>{t.count}</span>}
          </>
        );
        return t.to ? (
          <Link key={t.id} to={t.to} ref={(el) => void (refs.current[i] = el)} {...common}>
            {content}
          </Link>
        ) : (
          <button key={t.id} type="button" ref={(el) => void (refs.current[i] = el)} onClick={() => onSelect?.(t.id)} {...common}>
            {content}
          </button>
        );
      })}
    </div>
  );
}
