import { useState, type ReactNode } from 'react';
import { Link, NavLink, Outlet } from 'react-router';
import { Menu, Rss, X } from 'lucide-react';
import { LogoMark } from '@/components/Logo';
import { cx } from '@/core/format';

const NAV = [
  { to: '/apps', label: 'Applications' },
  { to: '/changelog', label: 'Release Notes' },
  { to: '/apps/desktop/changelog', label: 'Changelog' },
  { to: '/apps/desktop/requirements', label: 'System Requirements' },
  { to: '/channels', label: 'Channels' },
];

export function PublicHeader() {
  const [open, setOpen] = useState(false);
  return (
    <header className="bg-navy text-white">
      <a href="#main" className="sr-only-focusable absolute top-2 left-2 z-50 rounded bg-white px-3 py-2 text-ink">
        Skip to content
      </a>
      <div className="mx-auto flex h-14 max-w-6xl items-center justify-between px-4 sm:px-6">
        <Link to="/" className="flex items-center gap-2 text-[14px] font-bold">
          <LogoMark />
          WorkSuite Downloads
        </Link>
        <nav aria-label="Main" className="hidden items-center gap-6 text-[13px] text-[#c9d0ea] md:flex">
          {NAV.map((n) => (
            <NavLink key={n.to} to={n.to} className={({ isActive }) => cx('hover:text-white', isActive && 'text-white')} end>
              {n.label}
            </NavLink>
          ))}
          <a href="/changelog/feed" className="inline-flex items-center gap-1 hover:text-white">
            <Rss className="size-3.5" aria-hidden /> RSS
          </a>
          <Link to="/portal" className="hover:text-white">
            Sign in
          </Link>
        </nav>
        <button type="button" className="grid size-9 place-items-center rounded-lg hover:bg-white/10 md:hidden" aria-expanded={open} aria-controls="mobile-nav" aria-label={open ? 'Close menu' : 'Open menu'} onClick={() => setOpen((o) => !o)}>
          {open ? <X className="size-5" aria-hidden /> : <Menu className="size-5" aria-hidden />}
        </button>
      </div>
      {open && (
        <nav id="mobile-nav" aria-label="Main" className="border-t border-white/10 px-4 pb-3 md:hidden">
          <ul className="flex flex-col text-[14px]">
            {[...NAV, { to: '/report-a-problem', label: 'Report a problem' }, { to: '/portal', label: 'Sign in' }].map((n) => (
              <li key={n.to}>
                <Link to={n.to} className="block py-2 text-[#dfe4f5] hover:text-white" onClick={() => setOpen(false)}>
                  {n.label}
                </Link>
              </li>
            ))}
            <li>
              <a href="/changelog/feed" className="block py-2 text-[#dfe4f5]">
                RSS feed
              </a>
            </li>
          </ul>
        </nav>
      )}
    </header>
  );
}

export function PublicFooter({ extra }: { extra?: ReactNode }) {
  return (
    <footer className="mt-12 border-t border-line bg-white">
      <div className="mx-auto grid max-w-6xl gap-6 px-4 py-8 text-[12.5px] text-muted sm:grid-cols-3 sm:px-6">
        <div>
          <div className="mb-2 flex items-center gap-2 font-bold text-ink">
            <LogoMark /> WorkSuite Downloads
          </div>
          <p>Official downloads for WorkSuite. Every installer is code-signed by Livezen Technologies (Pvt) Ltd.</p>
        </div>
        <nav aria-label="Footer">
          <ul className="space-y-1.5">
            <li>
              <Link className="hover:text-ink" to="/apps">
                All applications
              </Link>
            </li>
            <li>
              <Link className="hover:text-ink" to="/changelog">
                Release notes
              </Link>
            </li>
            <li>
              <Link className="hover:text-ink" to="/channels">
                Release channels (Stable, Beta, Dev)
              </Link>
            </li>
            <li>
              <Link className="hover:text-ink" to="/report-a-problem">
                Report a problem
              </Link>
            </li>
            <li>
              <a className="hover:text-ink" href="/changelog/feed">
                RSS
              </a>{' '}
              ·{' '}
              <a className="hover:text-ink" href="/changelog/atom">
                Atom
              </a>
            </li>
          </ul>
        </nav>
        <div>{extra}</div>
      </div>
      <div className="border-t border-line py-3 text-center text-[11.5px] text-subtle">© 2026 Livezen Technologies (Pvt) Ltd · updates.worksuitecloud.com</div>
    </footer>
  );
}

export function PublicLayout() {
  return (
    <div className="flex min-h-screen flex-col">
      <PublicHeader />
      <main id="main" className="flex-1">
        <Outlet />
      </main>
      <PublicFooter />
    </div>
  );
}
