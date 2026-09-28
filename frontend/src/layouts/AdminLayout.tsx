import { useEffect, useState } from 'react';
import { Link, Outlet, useLocation } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { LogOut, Menu, ShieldCheck, X } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { can } from '@/core/permissions';
import { cx } from '@/core/format';
import { LogoMark } from '@/components/Logo';
import { EnvBadge } from '@/components/admin/EnvBadge';
import { NotificationBell } from '@/components/admin/NotificationBell';
import { bugtraceApi, bugKeys } from '@/services/bugtrace';
import { ADMIN_NAV, type NavItem } from './adminNav';
import type { Me } from '@/types/api';

function isActive(item: NavItem, pathname: string, search: string): boolean {
  const [path, query] = item.to.split('?');
  if (item.status) {
    if (pathname !== '/admin/releases') return false;
    return new URLSearchParams(search).get('group') === new URLSearchParams(query).get('group');
  }
  if (path === '/admin') return pathname === '/admin' || pathname === '/admin/';
  return pathname === path || pathname.startsWith(`${path}/`);
}

export function visibleNav(user: Me | null) {
  return ADMIN_NAV.map((s) => ({ ...s, items: s.items.filter((i) => can(user, i.perm)) })).filter((s) => s.items.length > 0);
}

function Sidebar({ user, onNavigate }: { user: Me; onNavigate?: () => void }) {
  const { pathname, search } = useLocation();
  const untriaged = useQuery({
    queryKey: bugKeys.reports({ status: 'UNTRIAGED' }),
    queryFn: () => bugtraceApi.reports({ status: 'UNTRIAGED' }),
    enabled: can(user, 'bugtrace.view'),
    staleTime: 120_000,
  });
  const badge = untriaged.data?.counts?.UNTRIAGED;
  return (
    <nav aria-label="Admin" className="flex h-full flex-col overflow-y-auto px-2 pb-6">
      {visibleNav(user).map((section, si) => (
        <div key={si} className="mt-3 first:mt-1">
          {section.label && <div className="px-2 pb-1 text-[10px] font-bold tracking-[0.12em] text-[#8e97bd] uppercase">{section.label}</div>}
          <ul className="space-y-0.5">
            {section.items.map((item) => {
              const active = isActive(item, pathname, search);
              return (
                <li key={item.to}>
                  <Link
                    to={item.to}
                    onClick={onNavigate}
                    aria-current={active ? 'page' : undefined}
                    className={cx(
                      'flex items-center justify-between rounded-md px-2 py-1.5 text-[12.5px] transition-colors',
                      active ? 'bg-brand font-semibold text-white' : 'text-[#cdd3ea] hover:bg-white/8 hover:text-white',
                    )}
                  >
                    {item.label}
                    {item.badgeKey === 'untriaged' && badge ? <span className="rounded-full bg-bad px-1.5 text-[10px] font-bold text-white">{badge}</span> : null}
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}

export function AdminLayout() {
  const { user, environment, logout } = useAuth();
  const [drawer, setDrawer] = useState(false);
  const { pathname } = useLocation();
  useEffect(() => setDrawer(false), [pathname]);
  if (!user) return null;
  return (
    <div className="min-h-screen bg-ground lg:grid lg:grid-cols-[216px_1fr]">
      <a href="#admin-main" className="sr-only-focusable absolute top-2 left-2 z-50 rounded bg-white px-3 py-2 text-ink">
        Skip to content
      </a>
      <aside className="sticky top-0 hidden h-screen bg-navy lg:block">
        <Link to="/admin" className="flex items-center gap-2 px-4 py-4 text-[13.5px] font-bold text-white">
          <LogoMark /> Update Center
        </Link>
        <div className="h-[calc(100vh-60px)]">
          <Sidebar user={user} />
        </div>
      </aside>
      {drawer && (
        <div className="fixed inset-0 z-40 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation">
          <div className="absolute inset-0 bg-black/40" onClick={() => setDrawer(false)} />
          <aside className="absolute inset-y-0 left-0 w-64 bg-navy">
            <div className="flex items-center justify-between px-4 py-4">
              <span className="flex items-center gap-2 text-[13.5px] font-bold text-white">
                <LogoMark /> Update Center
              </span>
              <button type="button" onClick={() => setDrawer(false)} aria-label="Close navigation" className="grid size-8 place-items-center rounded text-white hover:bg-white/10">
                <X className="size-4" aria-hidden />
              </button>
            </div>
            <div className="h-[calc(100vh-64px)]">
              <Sidebar user={user} onNavigate={() => setDrawer(false)} />
            </div>
          </aside>
        </div>
      )}
      <div className="min-w-0">
        <header className="flex items-center justify-between gap-3 border-b border-line bg-white/70 px-4 py-2 backdrop-blur sm:px-6">
          <div className="flex items-center gap-2">
            <button type="button" className="grid size-9 place-items-center rounded-lg border border-line bg-white lg:hidden" aria-label="Open navigation" onClick={() => setDrawer(true)}>
              <Menu className="size-4" aria-hidden />
            </button>
            <EnvBadge env={environment} />
            <span className="hidden text-[12px] text-subtle sm:inline">updates.worksuitecloud.com</span>
          </div>
          <div className="flex items-center gap-2">
            <NotificationBell />
            <Link to="/admin/account/security" className="hidden items-center gap-2 rounded-lg border border-line bg-white px-2.5 py-1.5 text-[12px] hover:bg-ground sm:flex" title="Account security (MFA, passkeys)">
              <ShieldCheck className="size-3.5 text-ok" aria-hidden />
              <span className="max-w-40 truncate font-semibold">{user.name}</span>
            </Link>
            <button type="button" onClick={() => void logout()} className="grid size-9 place-items-center rounded-lg border border-line bg-white hover:bg-ground" aria-label="Sign out" title="Sign out">
              <LogOut className="size-4" aria-hidden />
            </button>
          </div>
        </header>
        <main id="admin-main" className="px-4 py-5 sm:px-6">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
