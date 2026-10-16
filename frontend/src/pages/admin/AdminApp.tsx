import { lazy, Suspense, type ReactNode } from 'react';
import { Navigate, Route, Routes, useLocation } from 'react-router';
import { useAuth } from '@/hooks/useAuth';
import { AdminLayout } from '@/layouts/AdminLayout';
import { MfaPromptProvider } from '@/components/MfaPrompt';
import { Alert, Spinner } from '@/components/ui';
import { ForgotPage, LoginPage, ResetPage } from '@/pages/auth/AuthPages';
import { can } from '@/core/permissions';
import Dashboard from './Dashboard';
import { NewRelease, ReleasesList } from './Releases';
import * as P from './pages';

const ReleaseScreen = lazy(() => import('@/modules/releases/ReleaseScreen'));

function RequireStaff({ children }: { children: ReactNode }) {
  const { user, loading, environment } = useAuth();
  const loc = useLocation();
  if (loading) return <Spinner label="Checking your session…" />;
  if (!user) return <Navigate to={`/admin/login?next=${encodeURIComponent(loc.pathname + loc.search)}`} replace />;
  if (user.kind !== 'STAFF')
    return (
      <div className="mx-auto max-w-md p-10">
        <Alert tone="warn">This is a customer account. The admin portal is for Livezen staff. Go to <a className="link" href="/portal">your portal</a>.</Alert>
      </div>
    );
  // MFA is mandatory for staff in production (FR-UC-AUTH-02); the API enforces it too
  // (403 MFA_ENROLLMENT_REQUIRED), so other environments only nudge via the header link.
  const mfaMissing = !user.mfa_enabled && user.passkeys === 0;
  if (mfaMissing && environment === 'PRODUCTION' && !loc.pathname.startsWith('/admin/account/security')) return <Navigate to="/admin/account/security?required=1" replace />;
  return <>{children}</>;
}

function Guard({ perm, children }: { perm: string | string[]; children: ReactNode }) {
  const { user } = useAuth();
  if (!can(user, perm)) return <Alert tone="warn">You don’t have permission to view this page.</Alert>;
  return <>{children}</>;
}

export default function AdminApp() {
  return (
    <>
      <MfaPromptProvider />
      <Routes>
        <Route path="login" element={<LoginPage area="admin" />} />
        <Route path="forgot" element={<ForgotPage area="admin" />} />
        <Route path="reset" element={<ResetPage area="admin" />} />
        <Route
          element={
            <RequireStaff>
              <AdminLayout />
            </RequireStaff>
          }
        >
          <Route index element={<Dashboard />} />
          <Route path="account/security" element={<P.AccountSecurity />} />
          <Route path="applications" element={<Guard perm="application.view"><P.ApplicationsList /></Guard>} />
          <Route path="applications/new" element={<Guard perm="application.manage"><P.NewAppWizard /></Guard>} />
          <Route path="applications/:id/:tab?" element={<Guard perm="application.view"><P.AppDetail /></Guard>} />
          <Route path="platforms" element={<Guard perm="application.view"><P.PlatformsPage /></Guard>} />
          <Route path="channels" element={<Guard perm="application.view"><P.ChannelsAdmin /></Guard>} />
          <Route path="releases" element={<Guard perm="release.view"><ReleasesList /></Guard>} />
          <Route path="releases/new" element={<Guard perm="release.create"><NewRelease /></Guard>} />
          <Route
            path="releases/:id/:tab?"
            element={
              <Guard perm="release.view">
                <Suspense fallback={<Spinner />}>
                  <ReleaseScreen />
                </Suspense>
              </Guard>
            }
          />
          <Route path="distribution/downloads" element={<Guard perm="downloads.view"><P.DownloadsAnalytics /></Guard>} />
          <Route path="distribution/rollouts" element={<Guard perm="rollout.view"><P.RolloutsOverview /></Guard>} />
          <Route path="distribution/mirrors" element={<Guard perm="storage.manage"><P.StorageHealth /></Guard>} />
          <Route path="content/changelogs" element={<Guard perm="changelog.view"><P.ChangelogsContent /></Guard>} />
          <Route path="content/release-notes" element={<Guard perm="changelog.view"><P.ReleaseNotesContent /></Guard>} />
          <Route path="content/download-pages" element={<Guard perm="content.manage"><P.DownloadPagesContent /></Guard>} />
          <Route path="devices/versions" element={<Guard perm="devices.view"><P.DeviceVersions /></Guard>} />
          <Route path="devices/update-status" element={<Guard perm="devices.view"><P.UpdateStatus /></Guard>} />
          <Route path="server/releases" element={<Guard perm="release.view"><P.ServerReleases /></Guard>} />
          <Route path="server/releases/new" element={<Guard perm="release.create"><P.NewServerRelease /></Guard>} />
          <Route path="server/releases/:id" element={<Guard perm="release.view"><P.NewServerRelease /></Guard>} />
          <Route path="server/releases/:id/rollout" element={<Guard perm={['rollout.view', 'release.view']}><P.ServerRollout /></Guard>} />
          <Route path="server/installations" element={<Guard perm="installations.view"><P.Installations /></Guard>} />
          <Route path="server/licenses" element={<Guard perm="license.view"><P.Licenses /></Guard>} />
          <Route path="server/entitlements" element={<Guard perm="license.view"><P.Entitlements /></Guard>} />
          <Route path="server/license-risk" element={<Guard perm="license.view"><P.LicenseRisk /></Guard>} />
          <Route path="server/fleet" element={<Guard perm={['fleet.manage', 'installations.view']}><P.ManagedFleet /></Guard>} />
          <Route path="bugtrace/issues" element={<Guard perm="bugtrace.view"><P.IssuesPage /></Guard>} />
          <Route path="bugtrace/issues/:id" element={<Guard perm="bugtrace.view"><P.IssueDetailPage /></Guard>} />
          <Route path="bugtrace/reports/:ref?" element={<Guard perm="bugtrace.view"><P.ReportsInbox /></Guard>} />
          <Route path="bugtrace/health" element={<Guard perm="bugtrace.view"><P.ReleaseHealthPage /></Guard>} />
          <Route path="bugtrace/alerts" element={<Guard perm="bugtrace.manage"><P.AlertsPage /></Guard>} />
          <Route path="users" element={<Guard perm="user.manage"><P.UsersPage /></Guard>} />
          <Route path="roles" element={<Guard perm="user.manage"><P.RolesPage /></Guard>} />
          <Route path="api-clients" element={<Guard perm="api_client.manage"><P.ApiClientsPage /></Guard>} />
          <Route path="storage" element={<Guard perm="storage.manage"><P.StorageHealth /></Guard>} />
          <Route path="security" element={<Guard perm={['security.manage', 'signing_key.rotate']}><P.SecurityPage /></Guard>} />
          <Route path="activity" element={<Guard perm="audit.view"><P.ActivityPage /></Guard>} />
          <Route path="health" element={<Guard perm={['storage.manage', 'audit.view', 'security.manage']}><P.HealthPage /></Guard>} />
          <Route path="settings" element={<Guard perm="security.manage"><P.SettingsPage /></Guard>} />
          <Route path="*" element={<Alert>Page not found.</Alert>} />
        </Route>
      </Routes>
    </>
  );
}

