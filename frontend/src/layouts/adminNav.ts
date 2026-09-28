/** Admin navigation per §7.1 (+ Server §17 and Bug Trace §18). Items are hidden without the permission. */
export interface NavItem {
  label: string;
  to: string;
  /** any-of permissions; undefined = every staff user */
  perm?: string[];
  /** For /admin/releases?status=… items: the status group, used for active-state matching. */
  status?: string;
  badgeKey?: 'untriaged';
}
export interface NavSection {
  label: string | null;
  items: NavItem[];
}

export const RELEASE_GROUPS: Record<string, { label: string; statuses: string }> = {
  draft: { label: 'Draft', statuses: 'DRAFT,UPLOADING,READY_FOR_TEST' },
  testing: { label: 'Testing', statuses: 'TESTING,APPROVED' },
  published: { label: 'Published', statuses: 'PUBLISHED,SUPERSEDED' },
  paused: { label: 'Paused', statuses: 'PAUSED' },
  withdrawn: { label: 'Withdrawn', statuses: 'WITHDRAWN' },
};

export const ADMIN_NAV: NavSection[] = [
  { label: null, items: [{ label: 'Dashboard', to: '/admin' }] },
  {
    label: 'Applications',
    items: [
      { label: 'All Applications', to: '/admin/applications', perm: ['application.view'] },
      { label: 'Platforms', to: '/admin/platforms', perm: ['application.view'] },
      { label: 'Channels', to: '/admin/channels', perm: ['application.view'] },
    ],
  },
  {
    label: 'Releases',
    items: Object.entries(RELEASE_GROUPS).map(([key, g]) => ({ label: g.label, to: `/admin/releases?group=${key}`, perm: ['release.view'], status: key })),
  },
  {
    label: 'Distribution',
    items: [
      { label: 'Downloads', to: '/admin/distribution/downloads', perm: ['downloads.view'] },
      { label: 'Rollouts', to: '/admin/distribution/rollouts', perm: ['rollout.view'] },
      { label: 'Mirrors / CDN', to: '/admin/distribution/mirrors', perm: ['storage.manage'] },
    ],
  },
  {
    label: 'Content',
    items: [
      { label: 'Changelogs', to: '/admin/content/changelogs', perm: ['changelog.view'] },
      { label: 'Release Notes', to: '/admin/content/release-notes', perm: ['changelog.view'] },
      { label: 'Download Pages', to: '/admin/content/download-pages', perm: ['content.manage'] },
    ],
  },
  {
    label: 'Devices',
    items: [
      { label: 'Versions', to: '/admin/devices/versions', perm: ['devices.view'] },
      { label: 'Update Status', to: '/admin/devices/update-status', perm: ['devices.view'] },
    ],
  },
  {
    label: 'Server',
    items: [
      { label: 'Installations', to: '/admin/server/installations', perm: ['installations.view'] },
      { label: 'Managed Fleet', to: '/admin/server/fleet', perm: ['fleet.manage', 'installations.view'] },
    ],
  },
  {
    label: 'Bug Trace',
    items: [
      { label: 'Issues', to: '/admin/bugtrace/issues', perm: ['bugtrace.view'] },
      { label: 'User Reports', to: '/admin/bugtrace/reports', perm: ['bugtrace.view'], badgeKey: 'untriaged' },
      { label: 'Release Health', to: '/admin/bugtrace/health', perm: ['bugtrace.view'] },
      { label: 'Alerts', to: '/admin/bugtrace/alerts', perm: ['bugtrace.manage'] },
    ],
  },
  {
    label: 'Administration',
    items: [
      { label: 'Users', to: '/admin/users', perm: ['user.manage'] },
      { label: 'Roles', to: '/admin/roles', perm: ['user.manage'] },
      { label: 'API Clients', to: '/admin/api-clients', perm: ['api_client.manage'] },
      { label: 'Storage', to: '/admin/storage', perm: ['storage.manage'] },
      { label: 'Security', to: '/admin/security', perm: ['security.manage', 'signing_key.rotate'] },
      { label: 'Activity', to: '/admin/activity', perm: ['audit.view'] },
      { label: 'Health', to: '/admin/health', perm: ['storage.manage', 'audit.view', 'security.manage'] },
      { label: 'Settings', to: '/admin/settings', perm: ['security.manage'] },
    ],
  },
];
