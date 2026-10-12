/** Admin page registry (all part of the admin chunk). */
export { ApplicationsList, PlatformsPage, ChannelsAdmin } from './Applications';
export { default as NewAppWizard } from '@/modules/applications/NewAppWizard';
export { default as AppDetail } from '@/modules/applications/AppDetail';
export {
  DownloadsAnalytics,
  RolloutsOverview,
  StorageHealth,
  HealthPage,
  ChangelogsContent,
  ReleaseNotesContent,
  DownloadPagesContent,
  DeviceVersions,
  UpdateStatus,
  ManagedFleet,
} from './Operations';
export { default as Installations } from '@/modules/server/ServerFleet';
export { default as ServerReleases } from '@/modules/server/ServerReleases';
export { default as NewServerRelease } from '@/modules/server/NewServerRelease';
export { default as ServerRollout } from '@/modules/server/ServerRollout';
export { UsersPage, RolesPage, ApiClientsPage, SecurityPage, ActivityPage, SettingsPage, AccountSecurity } from './Administration';
export { IssuesPage, IssueDetailPage } from '@/modules/bugtrace/Issues';
export { ReportsInbox, ReleaseHealthPage, AlertsPage } from '@/modules/bugtrace/Reports';
