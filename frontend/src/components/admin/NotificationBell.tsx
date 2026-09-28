import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { cx, timeAgo } from '@/core/format';

/** In-app notifications (FR-UC-NTF): bell with unread count and a dropdown panel. */
export function NotificationBell() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const q = useQuery({ queryKey: adminKeys.notifications, queryFn: adminApi.notifications, refetchInterval: 60_000 });
  const readAll = useMutation({ mutationFn: adminApi.readAllNotifications, onSuccess: () => qc.invalidateQueries({ queryKey: adminKeys.notifications }) });
  const readOne = useMutation({ mutationFn: adminApi.readNotification, onSuccess: () => qc.invalidateQueries({ queryKey: adminKeys.notifications }) });
  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => !ref.current?.contains(e.target as Node) && setOpen(false);
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);
  const unread = q.data?.unread ?? 0;
  return (
    <div className="relative" ref={ref}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        aria-expanded={open}
        aria-haspopup="true"
        aria-label={`Notifications${unread ? `, ${unread} unread` : ''}`}
        className="relative grid size-9 place-items-center rounded-lg border border-line bg-white hover:bg-ground"
      >
        <Bell className="size-4" aria-hidden />
        {unread > 0 && <span className="absolute -top-1 -right-1 grid h-4 min-w-4 place-items-center rounded-full bg-bad px-1 text-[10px] font-bold text-white">{unread}</span>}
      </button>
      {open && (
        <div role="region" aria-label="Notifications" className="absolute right-0 z-40 mt-2 w-[min(92vw,360px)] rounded-xl border border-line bg-white shadow-xl">
          <div className="flex items-center justify-between border-b border-line px-3 py-2">
            <span className="text-[13px] font-bold">Notifications</span>
            <button type="button" className="link text-[12px]" onClick={() => readAll.mutate()} disabled={!unread}>
              Mark all read
            </button>
          </div>
          <ul className="max-h-96 divide-y divide-line overflow-y-auto">
            {(q.data?.data ?? []).map((n) => {
              const body = (
                <>
                  <span className={cx('block text-[12.5px]', !n.read_at && 'font-semibold')}>{n.payload.title}</span>
                  <span className="block text-[12px] text-muted">{n.payload.body}</span>
                  <span className="block text-[11px] text-subtle">{timeAgo(n.created_at)}</span>
                </>
              );
              return (
                <li key={n.id} className={cx('px-3 py-2', !n.read_at && 'bg-brand-50/60')}>
                  {n.payload.link ? (
                    <Link to={n.payload.link} className="block hover:underline" onClick={() => (setOpen(false), !n.read_at && readOne.mutate(n.id))}>
                      {body}
                    </Link>
                  ) : (
                    <button type="button" className="block w-full text-left" onClick={() => !n.read_at && readOne.mutate(n.id)}>
                      {body}
                    </button>
                  )}
                </li>
              );
            })}
            {q.data && q.data.data.length === 0 && <li className="px-3 py-6 text-center text-[12.5px] text-subtle">You’re all caught up.</li>}
          </ul>
        </div>
      )}
    </div>
  );
}
