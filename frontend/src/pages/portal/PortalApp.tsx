/** User portal (/portal/*): My Downloads (incl. beta/private), history, notifications, bug reports, profile & security. */
import { useEffect, useState, type ReactNode } from 'react';
import { Link, Navigate, NavLink, Outlet, Route, Routes, useLocation, useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { portalApi, portalKeys } from '@/services/portal';
import { authApi } from '@/services/auth';
import { ApiError, errorMessage } from '@/services/api';
import type { ChannelCode, PortalSubscription } from '@/types/api';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { cx, formatDateLong, formatDateTime, titleCase } from '@/core/format';
import { PublicFooter, PublicHeader } from '@/layouts/PublicLayout';
import { MfaPromptProvider } from '@/components/MfaPrompt';
import { Alert, Badge, Button, Card, CardHeader, Checkbox, EmptyState, ErrorState, PageHeader, Spinner, Table, Td, TextField, Th } from '@/components/ui';
import { useToast } from '@/components/ui/Toast';
import { ChannelBadge } from '@/components/StatusBadges';
import { PlatformDownloads } from '@/components/public/blocks';
import { AuthShell, ForgotPage, LoginPage, ResetPage, SecuritySettings } from '@/pages/auth/AuthPages';
import { LicenceDetail, MyLicences } from './Licenses';

const NAV = [
  { to: '/portal', label: 'My Downloads', end: true },
  { to: '/portal/licenses', label: 'My licences' },
  { to: '/portal/history', label: 'Download history' },
  { to: '/portal/notifications', label: 'Release notifications' },
  { to: '/portal/bug-reports', label: 'My bug reports' },
  { to: '/portal/profile', label: 'Profile' },
  { to: '/portal/security', label: 'Security' },
];

function RequireUser({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const loc = useLocation();
  if (loading) return <Spinner />;
  if (!user) return <Navigate to={`/portal/login?next=${encodeURIComponent(loc.pathname)}`} replace />;
  return <>{children}</>;
}

function PortalLayout() {
  const { user, logout } = useAuth();
  return (
    <div className="flex min-h-screen flex-col">
      <PublicHeader />
      <div className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 sm:px-6">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
          <p className="text-[13px] text-muted">
            Signed in as <strong className="text-ink">{user?.name}</strong> ({user?.email})
            {user?.beta_opt_in && <Badge tone="purple" className="ml-2">Beta</Badge>}
          </p>
          <div className="flex gap-2">
            {user?.kind === 'STAFF' && (
              <Link className="link text-[12.5px]" to="/admin">
                Admin portal
              </Link>
            )}
            <Button size="sm" onClick={() => void logout()}>
              Sign out
            </Button>
          </div>
        </div>
        <nav aria-label="Portal" className="mb-5 flex gap-1 overflow-x-auto border-b border-line">
          {NAV.map((n) => (
            <NavLink key={n.to} to={n.to} end={n.end} className={({ isActive }) => cx('-mb-px shrink-0 border-b-2 px-3 py-2 text-[13px] whitespace-nowrap', isActive ? 'border-indigo font-semibold text-indigo' : 'border-transparent text-muted hover:text-ink')}>
              {n.label}
            </NavLink>
          ))}
        </nav>
        <main id="main">
          <Outlet />
        </main>
      </div>
      <PublicFooter />
    </div>
  );
}

function MyDownloads() {
  useDocumentTitle('My Downloads · WorkSuite');
  const q = useQuery({ queryKey: portalKeys.downloads, queryFn: portalApi.downloads });
  const [channel, setChannel] = useState<Record<string, ChannelCode>>({});
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  return (
    <div className="grid gap-6">
      {q.data.apps.map((app) => {
        const ch = channel[app.id] ?? (app.channels_available.includes('STABLE') ? 'STABLE' : app.channels_available[0]);
        const rel = ch ? app.latest_by_channel[ch] : undefined;
        return (
          <section key={app.id} aria-labelledby={`app-${app.id}`}>
            <div className="mb-2 flex flex-wrap items-center gap-2">
              <h2 id={`app-${app.id}`} className="text-[16px] font-bold">
                {app.name}
              </h2>
              {app.private && <Badge tone="purple">Private</Badge>}
              {rel && (
                <span className="text-[12.5px] text-subtle">
                  <span className="font-mono">{rel.version}</span> · {formatDateLong(rel.release_date ?? rel.published_at)}
                </span>
              )}
              <div className="ml-auto flex gap-1" role="group" aria-label={`${app.name} channel`}>
                {app.channels_available.map((c) => (
                  <Button key={c} size="sm" variant={c === ch ? 'primary' : 'secondary'} aria-pressed={c === ch} onClick={() => setChannel({ ...channel, [app.id]: c })}>
                    {titleCase(c)}
                  </Button>
                ))}
              </div>
            </div>
            {rel ? (
              <>
                {ch !== 'STABLE' && <Alert tone="info" className="mb-2">{titleCase(ch)} builds are previews. They update automatically to newer {titleCase(ch)} builds.</Alert>}
                <PlatformDownloads slug={app.slug} packages={rel.packages} useLatestResolver={false} idPrefix={`p-${app.id}`} />
              </>
            ) : (
              <p className="text-[12.5px] text-subtle">No release available on this channel.</p>
            )}
          </section>
        );
      })}
    </div>
  );
}

function History() {
  useDocumentTitle('Download history · WorkSuite');
  const q = useQuery({ queryKey: portalKeys.history, queryFn: portalApi.history });
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  return (
    <Card>
      {q.data.data.length === 0 ? (
        <EmptyState title="No downloads yet" />
      ) : (
        <Table label="Download history">
          <thead>
            <tr>
              <Th>Application</Th>
              <Th>Version</Th>
              <Th>Package</Th>
              <Th>When</Th>
            </tr>
          </thead>
          <tbody>
            {q.data.data.map((h, i) => (
              <tr key={i}>
                <Td>{h.app}</Td>
                <Td className="font-mono">{h.version}</Td>
                <Td>{h.package}</Td>
                <Td>{formatDateTime(h.created_at)}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Card>
  );
}

function Notifications() {
  useDocumentTitle('Release notifications · WorkSuite');
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: portalKeys.subscriptions, queryFn: portalApi.subscriptions });
  const apps = useQuery({ queryKey: portalKeys.downloads, queryFn: portalApi.downloads });
  const [items, setItems] = useState<PortalSubscription[] | null>(null);
  useEffect(() => {
    if (q.data && !items) setItems(q.data.data ?? q.data.items ?? []);
  }, [q.data, items]);
  const save = useMutation({ mutationFn: () => portalApi.saveSubscriptions(items ?? []), onSuccess: () => (toast.success('Notification preferences saved'), void qc.invalidateQueries({ queryKey: portalKeys.subscriptions })), onError: (e) => toast.error(errorMessage(e)) });
  if (!items || !apps.data) return q.error ? <ErrorState error={q.error} /> : <Spinner />;
  const get = (app: string, channel: ChannelCode) => items.find((i) => i.app === app && i.channel === channel)?.enabled ?? false;
  const set = (app: string, channel: ChannelCode, enabled: boolean) => setItems([...items.filter((i) => !(i.app === app && i.channel === channel)), { app, channel, enabled }]);
  return (
    <Card>
      <CardHeader title="Email me when a new release is published" sub="You can also subscribe via RSS/Atom on any changelog page." />
      <Table label="Release notifications">
        <thead>
          <tr>
            <Th>Application</Th>
            {(['STABLE', 'BETA'] as ChannelCode[]).map((c) => (
              <Th key={c}>{titleCase(c)}</Th>
            ))}
          </tr>
        </thead>
        <tbody>
          {apps.data.apps.map((a) => (
            <tr key={a.id}>
              <Td>{a.name}</Td>
              {(['STABLE', 'BETA'] as ChannelCode[]).map((c) => (
                <Td key={c}>
                  {c === 'STABLE' || a.channels_available.includes(c) ? (
                    <input type="checkbox" className="size-4 accent-brand" aria-label={`${a.name} ${c}`} checked={get(a.slug, c)} onChange={(e) => set(a.slug, c, e.target.checked)} />
                  ) : (
                    <span className="text-[11.5px] text-subtle">opt in to beta</span>
                  )}
                </Td>
              ))}
            </tr>
          ))}
        </tbody>
      </Table>
      <div className="p-4">
        <Button variant="primary" size="sm" loading={save.isPending} onClick={() => save.mutate()}>
          Save preferences
        </Button>
      </div>
    </Card>
  );
}

function BugReports() {
  useDocumentTitle('My bug reports · WorkSuite');
  const q = useQuery({ queryKey: portalKeys.bugReports, queryFn: portalApi.bugReports });
  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={q.refetch} />;
  return (
    <Card>
      {q.data.data.length === 0 ? (
        <EmptyState title="You haven’t reported any bugs">Use Help › Report a bug inside any WorkSuite app.</EmptyState>
      ) : (
        <Table label="My bug reports">
          <thead>
            <tr>
              <Th>Reference</Th>
              <Th>Title</Th>
              <Th>Status</Th>
              <Th>Fixed in</Th>
              <Th>Updated</Th>
            </tr>
          </thead>
          <tbody>
            {q.data.data.map((r) => (
              <tr key={r.reference}>
                <Td className="font-mono">{r.reference}</Td>
                <Td>{r.title}</Td>
                <Td>
                  <Badge tone={r.status === 'CLOSED' ? 'grey' : r.status === 'LINKED' ? 'blue' : 'amber'}>{r.status === 'LINKED' ? 'Being fixed' : titleCase(r.status)}</Badge>
                </Td>
                <Td className="font-mono">{r.fixed_in ?? '—'}</Td>
                <Td>{formatDateTime(r.updated_at)}</Td>
              </tr>
            ))}
          </tbody>
        </Table>
      )}
    </Card>
  );
}

function Profile() {
  useDocumentTitle('Profile · WorkSuite');
  const { user, refresh } = useAuth();
  const toast = useToast();
  const [name, setName] = useState(user?.name ?? '');
  const save = useMutation({ mutationFn: () => portalApi.profile(name), onSuccess: () => (toast.success('Profile saved'), void refresh()), onError: (e) => toast.error(errorMessage(e)) });
  const beta = useMutation({ mutationFn: (v: boolean) => portalApi.beta(v), onSuccess: () => (toast.success('Beta preference saved'), void refresh()), onError: (e) => toast.error(errorMessage(e)) });
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card className="p-5">
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate();
          }}
        >
          <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} />
          <TextField label="Email" value={user?.email ?? ''} disabled hint="Contact support to change your email address." />
          <Button type="submit" variant="primary" size="sm" loading={save.isPending}>
            Save
          </Button>
        </form>
      </Card>
      <Card className="p-5">
        <h2 className="mb-2 text-[14px] font-bold">Beta releases</h2>
        <Checkbox
          label="Opt in to the Beta channel"
          hint="See Beta downloads in My Downloads. Beta builds preview upcoming features and may contain bugs."
          checked={!!user?.beta_opt_in}
          disabled={beta.isPending}
          onChange={(e) => beta.mutate(e.target.checked)}
        />
        <p className="mt-3 text-[12px] text-subtle">
          <ChannelBadge channel="BETA" /> Your organisation’s administrator may also control which channel your installed apps follow.
        </p>
      </Card>
    </div>
  );
}

function SignUp() {
  useDocumentTitle('Create an account · WorkSuite');
  const [f, setF] = useState({ name: '', email: '', password: '' });
  const m = useMutation({ mutationFn: () => authApi.register(f.email, f.password, f.name) });
  const errs = m.error instanceof ApiError ? m.error.fieldErrors : {};
  return (
    <AuthShell area="portal" title="Create your account" sub="For beta access, private downloads and release notifications.">
      {m.isSuccess ? (
        <Alert tone="ok">We sent a verification link to {f.email}. Open it to activate your account.</Alert>
      ) : (
        <form
          className="space-y-3"
          onSubmit={(e) => {
            e.preventDefault();
            m.mutate();
          }}
        >
          {m.error && !Object.keys(errs).length && <Alert tone="bad">{errorMessage(m.error)}</Alert>}
          <TextField label="Name" required autoComplete="name" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} error={errs.name} />
          <TextField label="Email" type="email" required autoComplete="email" value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} error={errs.email} />
          <TextField label="Password" type="password" required autoComplete="new-password" minLength={12} hint="At least 12 characters." value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} error={errs.password} />
          <Button type="submit" variant="primary" className="w-full" loading={m.isPending}>
            Create account
          </Button>
          <Link className="link text-[12px]" to="/portal/login">
            I already have an account
          </Link>
        </form>
      )}
    </AuthShell>
  );
}

function VerifyEmail() {
  const [sp] = useSearchParams();
  const token = sp.get('token') ?? '';
  const q = useQuery({ queryKey: ['verify-email', token], queryFn: () => authApi.verifyEmail(token), enabled: !!token, retry: false });
  return (
    <AuthShell area="portal" title="Verify your email">
      {!token ? <Alert tone="bad">This link is missing its token.</Alert> : q.isLoading ? <Spinner /> : q.error ? <Alert tone="bad">{errorMessage(q.error)}</Alert> : <Alert tone="ok">Your email is verified. You can now sign in.</Alert>}
      <Link className="link mt-4 inline-block text-[12px]" to="/portal/login">
        Sign in
      </Link>
    </AuthShell>
  );
}

export default function PortalApp() {
  return (
    <>
      <MfaPromptProvider />
      <Routes>
        <Route path="login" element={<LoginPage area="portal" />} />
        <Route path="signup" element={<SignUp />} />
        <Route path="verify-email" element={<VerifyEmail />} />
        <Route path="forgot" element={<ForgotPage area="portal" />} />
        <Route path="reset" element={<ResetPage area="portal" />} />
        <Route
          element={
            <RequireUser>
              <PortalLayout />
            </RequireUser>
          }
        >
          <Route index element={<><PageHeader title="My Downloads" sub="Stable, and Beta if you opted in — plus private applications you have access to." /><MyDownloads /></>} />
          <Route path="licenses" element={<><PageHeader title="My licences" sub="Your WorkSuite Server licences: keys, servers, offline activation and trials." /><MyLicences /></>} />
          <Route path="licenses/:id" element={<><PageHeader title="Licence" /><LicenceDetail /></>} />
          <Route path="history" element={<><PageHeader title="Download history" /><History /></>} />
          <Route path="notifications" element={<><PageHeader title="Release notifications" /><Notifications /></>} />
          <Route path="bug-reports" element={<><PageHeader title="My bug reports" sub="Updates from WorkSuite support on the problems you reported." /><BugReports /></>} />
          <Route path="profile" element={<><PageHeader title="Profile" /><Profile /></>} />
          <Route path="security" element={<><PageHeader title="Security" sub="Two-step verification and passkeys." /><SecuritySettings /></>} />
          <Route path="*" element={<Navigate to="/portal" replace />} />
        </Route>
      </Routes>
    </>
  );
}
