import { lazy, Suspense } from 'react';
import { Route, Routes } from 'react-router';
import { PublicLayout } from '@/layouts/PublicLayout';
import { Spinner } from '@/components/ui';
import { HomePage } from '@/pages/public/HomePage';
import { AppsPage } from '@/pages/public/AppsPage';
import { AppPage } from '@/pages/public/AppPage';
import { ReleasePage } from '@/pages/public/ReleasePage';
import { VersionsPage } from '@/pages/public/VersionsPage';
import { AppChangelogPage, ChangelogPage } from '@/pages/public/ChangelogPage';
import { RequirementsPage } from '@/pages/public/RequirementsPage';
import { ChannelsPage } from '@/pages/public/ChannelsPage';
import { SubscriptionPage } from '@/pages/public/SubscriptionPage';
import { BugReportPage, BugReportStatusPage } from '@/pages/public/BugReportPage';
import { OfflineActivationPage } from '@/pages/public/OfflineActivationPage';
import { NotFoundBlock } from '@/pages/public/common';

// Admin and portal are separate chunks: public pages stay light (LCP < 2 s).
const AdminApp = lazy(() => import('@/pages/admin/AdminApp'));
const PortalApp = lazy(() => import('@/pages/portal/PortalApp'));

export function PublicRoutes() {
  return (
    <Route element={<PublicLayout />}>
      <Route index element={<HomePage />} />
      <Route path="apps" element={<AppsPage />} />
      <Route path="apps/:slug" element={<AppPage />} />
      <Route path="apps/:slug/releases/:version" element={<ReleasePage />} />
      <Route path="apps/:slug/versions" element={<VersionsPage />} />
      <Route path="apps/:slug/changelog" element={<AppChangelogPage />} />
      <Route path="apps/:slug/requirements" element={<RequirementsPage />} />
      <Route path="changelog" element={<ChangelogPage />} />
      <Route path="channels" element={<ChannelsPage />} />
      <Route path="subscriptions/confirm" element={<SubscriptionPage mode="confirm" />} />
      <Route path="subscriptions/unsubscribe" element={<SubscriptionPage mode="unsubscribe" />} />
      <Route path="report-a-problem" element={<BugReportPage />} />
      <Route path="offline-activation" element={<OfflineActivationPage />} />
      <Route path="report-a-problem/status" element={<BugReportStatusPage />} />
      <Route path="*" element={<NotFoundBlock />} />
    </Route>
  );
}

export function AppRoutes() {
  return (
    <Routes>
      {PublicRoutes()}
      <Route
        path="admin/*"
        element={
          <Suspense fallback={<Spinner label="Loading admin portal…" />}>
            <AdminApp />
          </Suspense>
        }
      />
      <Route
        path="portal/*"
        element={
          <Suspense fallback={<Spinner label="Loading portal…" />}>
            <PortalApp />
          </Suspense>
        }
      />
    </Routes>
  );
}
