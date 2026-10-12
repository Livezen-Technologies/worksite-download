import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ReactElement } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router';
import { setCsrfToken, setTransport, type TransportRequest } from '@/services/api';
import { ToastProvider } from '@/components/ui/Toast';
import ServerReleases, { serverReleaseHref } from '@/modules/server/ServerReleases';
import NewServerRelease, { PublishServerDialog, packageState } from '@/modules/server/NewServerRelease';
import ServerRollout from '@/modules/server/ServerRollout';
import ServerFleet, { installationKind } from '@/modules/server/ServerFleet';
import { editionLabel, policyLabel } from '@/modules/server/serverUi';
import { visibleNav } from '@/layouts/AdminLayout';
import type { AdminPackage, AdminReleaseDetail, Installation, Me, Revision, ServerReleasesResponse, ServerRollout as Rollout } from '@/types/api';

// ---------------------------------------------------------------- fake API

type Handler = unknown | ((req: TransportRequest, body: Record<string, unknown> | null) => unknown);
let calls: { method: string; path: string; query: URLSearchParams; body: Record<string, unknown> | null }[] = [];

const ALL_PERMS = ['release.view', 'release.create', 'release.edit', 'release.upload', 'release.approve.qa', 'release.publish', 'release.withdraw', 'rollout.view', 'installations.view', 'campaign.manage', 'minimum_version.manage', 'application.view'];

function me(permissions: string[] = ALL_PERMS): { user: Me; csrf: string; environment: 'DEV'; step_up_valid_until: null } {
  return { user: { id: 'usr_rm', email: 'rm@livezen.lk', name: 'Release Manager', kind: 'STAFF', mfa_enabled: true, passkeys: 0, roles: ['release_manager'], permissions, beta_opt_in: false }, csrf: 'csrf-1', environment: 'DEV', step_up_valid_until: null };
}

function serve(routes: Record<string, Handler>, permissions?: string[]) {
  setTransport(async (req) => {
    const u = new URL(req.url, 'http://test.local');
    const path = u.pathname.replace(/^\/api\/v1/, '');
    const body = typeof req.body === 'string' ? (JSON.parse(req.body) as Record<string, unknown>) : null;
    calls.push({ method: req.method, path, query: u.searchParams, body });
    const key = `${req.method} ${path}`;
    let h: Handler = key === 'GET /auth/me' ? me(permissions) : routes[key];
    if (h === undefined) return new Response(JSON.stringify({ error: { code: 'NOT_FOUND', message: `No fake for ${key}` } }), { status: 404 });
    if (typeof h === 'function') h = (h as (r: TransportRequest, b: Record<string, unknown> | null) => unknown)(req, body);
    if (h instanceof Response) return h;
    return new Response(JSON.stringify(h), { status: 200, headers: { 'Content-Type': 'application/json' } });
  });
}

function renderAt(path: string, routePath: string, el: ReactElement) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <ToastProvider>
        <MemoryRouter initialEntries={[path]}>
          <Routes>
            <Route path={routePath} element={el} />
            <Route path="*" element={<div>Navigated elsewhere</div>} />
          </Routes>
        </MemoryRouter>
      </ToastProvider>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  calls = [];
  setCsrfToken('csrf-1');
});
afterEach(() => setTransport(null));

// ---------------------------------------------------------------- fixtures

const baseRelease = {
  app: { id: 'app_server', slug: 'server', name: 'WorkSuite Server (Web)', kind: 'SERVER' as const },
  build_revision: 0,
  release_type: 'RECOMMENDED' as const,
  support_state: 'SUPPORTED' as const,
  visibility: 'PUBLIC' as const,
  minimum_supported_version: null,
  minimum_no_change: true,
  mandatory: false,
  mandatory_after: null,
  grace_minutes: null,
  rollback_supported: true,
  release_date: null,
  scheduled_publish_at: null,
  published_at: null,
  published_by: null,
  created_by: 'Dev',
  withdrawn_at: null,
  withdraw_reason: null,
  superseded_by: null,
  notes: null,
  known_issues: null,
  auto_pause_on_gate: true,
  last_error: null,
  created_at: '2026-10-10T08:00:00Z',
  updated_at: '2026-10-10T08:00:00Z',
  packages_ready: 0,
  packages_total: 0,
};

const releases: ServerReleasesResponse = {
  kpis: { linked: 148, online: 136, offline: 12, latest_stable: '3.0.0', on_latest_stable: 121, on_latest_pct: 81.8, below_minimum: 4, minimum_stable: '2.9.0', rollbacks_30d: 3, rollback_versions: ['2.9.3'] },
  data: [
    { ...baseRelease, id: 'rel_310', version: '3.1.0', build: 1574, channel: 'STABLE', status: 'TESTING', severity: 'MINOR', editions: ['FREE', 'BUSINESS', 'ENTERPRISE'], editions_label: 'Professional, Business, Enterprise', ring_summary: 'Waiting for QA', adoption_pct: 0, installations_on_version: 0, commit: '07ebd36c' },
    { ...baseRelease, id: 'rel_300', version: '3.0.0', build: 1482, channel: 'STABLE', status: 'PUBLISHED', severity: 'MAJOR', editions: ['FREE', 'BUSINESS', 'ENTERPRISE'], editions_label: 'Professional, Business, Enterprise', ring_summary: 'Ring 3 · complete', adoption_pct: 82, installations_on_version: 121, commit: null },
    { ...baseRelease, id: 'rel_293', version: '2.9.3', build: 1376, channel: 'STABLE', status: 'WITHDRAWN', severity: 'PATCH', editions: ['ALL'], editions_label: 'All editions (core patch)', ring_summary: 'Stopped at ring 1', adoption_pct: 0, installations_on_version: 0, commit: null },
  ],
  minimums: [
    { channel: 'STABLE', minimum_version: '2.9.0', enforce_from: null },
    { channel: 'BETA', minimum_version: null, enforce_from: null },
  ],
};

function rev(over: Partial<Revision>): Revision {
  return { id: 'rev_1', revision_number: 1, file_name: 'worksuite-server-business-3.1.0+1574.zip', size: 412_000_000, sha256: 'a'.repeat(64), status: 'READY', reject_code: null, signature_status: 'NOT_REQUIRED', signature_detail: null, scan_status: 'CLEAN', scan_detail: null, attestation_match: 'MATCH' as unknown as boolean, metadata: {}, pipeline: [], replace_reason: null, uploaded_by: 'CI', uploaded_at: '2026-10-10T08:00:00Z', ...over };
}
function pkg(over: Partial<AdminPackage>): AdminPackage {
  return { id: 'pkg_1', platform: 'server', architecture: 'any', package_type: 'zip', edition: 'BUSINESS', scope: 'full', status: 'READY', health: 'OK', minimum_os_version: null, active_revision: null, revisions: [], ...over };
}

function detail(over: Partial<AdminReleaseDetail> = {}): AdminReleaseDetail {
  return {
    ...baseRelease,
    id: 'rel_310',
    version: '3.1.0',
    build: 1574,
    channel: 'STABLE',
    status: 'TESTING',
    packages: [pkg({ edition: 'BUSINESS', active_revision: rev({}), revisions: [rev({})] })],
    changelog: { locale: 'en', summary: 'WorkSuite Server 3.1.0', highlights: [], sections: { new_features: [], improvements: [], bug_fixes: [], performance: [], security: [], known_issues: [], deprecated: [], removed: [] }, markdown: '## New Features\n- Rental Management', html: '', version: 1, updated_at: null },
    checklist: { passed: true, items: [{ key: 'packages', label: 'Every selected edition uploaded and valid (Business)', status: 'pass', blocking: true, detail: null }] },
    approvals: [{ kind: 'QA', decision: 'PASS', platform: null, user: 'QA Tester', comment: 'ok', created_at: '2026-10-11T08:00:00Z' }],
    rollout: null,
    manifest: null,
    server_meta: { min_from_version: '3.0.0', requires: null, migrations: { count: 3, one_way: [] }, desktop_compat: '2.6.0 - 2.8.x', downtime_estimate_s: 90, staging_test: { status: 'PASSED' }, admin_notice: 'Brief downtime', advisory: null, severity: 'MINOR', commit: '07ebd36c', upgrade_notes: null, migrations_reversible: true, editions: ['BUSINESS'] },
    campaign: { audience: { editions: ['FREE', 'BUSINESS', 'ENTERPRISE'], channels: ['STABLE'], version_range: '', modes: ['MANAGED', 'SELF_HOSTED', 'AIR_GAPPED'] }, urgency: 'RECOMMENDED', deadline: null, rollout_pct: 100, channels: ['webhook', 'email'], fleet_window: null, fleet_stages: [100], status: 'SENT', sent_count: 136, eligible_count: 148, fleet_status: 'NONE', fleet_stage_no: 0 },
    allowed_actions: ['edit', 'upload', 'qa'],
    ...over,
  };
}

const rollout: Rollout = {
  release: { id: 'rel_310', version: '3.1.0', build: 1574, status: 'PUBLISHED', channel: 'STABLE', severity: 'MINOR', published_at: new Date(Date.now() - 36 * 3_600_000).toISOString(), withdrawn_at: null },
  status: 'PAUSED',
  mode: 'RINGS',
  compressed: false,
  pause: { kind: 'GATE', reason: 'Rollback rate 3 % (3 of 100 installations) is over 2 %', at: '2026-10-12T08:00:00Z', by: 'health gate', ring: 2 },
  rings: [
    { ring: 0, key: 'canary', name: 'Ring 0 · Canary', state: 'DONE', opened_at: '2026-10-09T08:00:00Z', opens_at: '2026-10-09T08:00:00Z', hold_hours: 24, extended_hours: 0, hold_ends_at: '2026-10-10T08:00:00Z', installations: 12, updated: 12, detail: 'Livezen-hosted and internal · held 24 h' },
    { ring: 1, key: 'early', name: 'Ring 1 · Early', state: 'DONE', opened_at: '2026-10-10T08:00:00Z', opens_at: '2026-10-10T08:00:00Z', hold_hours: 48, extended_hours: 0, hold_ends_at: '2026-10-12T08:00:00Z', installations: 100, updated: 97, detail: 'held 48 h' },
    { ring: 2, key: 'broad', name: 'Ring 2 · Broad', state: 'PAUSED', opened_at: null, opens_at: null, hold_hours: 72, extended_hours: 0, hold_ends_at: null, installations: 76, updated: 0, detail: 'Paused: Rollback rate 3 %' },
    { ring: 3, key: 'conservative', name: 'Ring 3 · Conservative', state: 'WAITING', opened_at: null, opens_at: null, hold_hours: 336, extended_hours: 0, hold_ends_at: null, installations: 30, updated: 0, detail: 'Opens after 72 h of ring 2' },
  ],
  gate: {
    state: 'FAILING',
    signals: [
      { key: 'rollback_rate', label: 'Rollback rate', value: 3, display: '3 of 100 · 3 %', threshold: 'over 2 %', state: 'FAIL' },
      { key: 'failed_runs', label: 'Failed runs', value: 0, display: '0 of 100 · 0 %', threshold: 'over 5 %', state: 'OK' },
      { key: 'crash_free', label: 'Crash-free sessions', value: 99.6, display: '99.6 %', threshold: 'under 99.0 %, or down 0.5 pt', state: 'OK' },
      { key: 'new_p0p1', label: 'New P0/P1 on 3+ installations', value: 0, display: '0', threshold: '1 or more', state: 'OK' },
    ],
    reasons: ['Rollback rate 3 % (3 of 100 installations) is over 2 %'],
    runs: 100,
    evaluated_at: '2026-10-12T08:00:00Z',
  },
  outcomes: { updated: 109, auto_scheduled: 27, waiting_for_admin: 74, postponed: 5, offline: 12, held: 3, skipped: 0 },
  campaign: null,
};

const installations: Installation[] = [
  { installation_id: 'inst_demo', name: 'demo.worksuite.lk', edition: 'BUSINESS', version: '3.1.0', build: 1574, channel: 'STABLE', mode: 'MANAGED', support_state: 'SUPPORTED', last_report_at: new Date(Date.now() - 120_000).toISOString(), last_run_status: 'SUCCEEDED', php_version: '8.3', db_version: 'MariaDB 10.11', policy_mode: 'AUTO_MINOR', ring: 0, ring_name: 'canary', offline_mode: false, health: 'OK', below_minimum: false },
  { installation_id: 'inst_d', name: 'ws.customer-d.example', edition: 'FREE', version: '2.8.6', build: 1300, channel: 'STABLE', mode: 'SELF_HOSTED', support_state: 'UNSUPPORTED', last_report_at: new Date(Date.now() - 3 * 86_400_000).toISOString(), last_run_status: 'FAILED', php_version: '8.2', db_version: 'MariaDB 10.6', policy_mode: 'NOTIFY', ring: 2, ring_name: 'broad', offline_mode: false, health: 'DEGRADED', below_minimum: true },
  { installation_id: 'inst_e', name: 'intranet.customer-e.example', edition: 'ENTERPRISE', version: '3.0.0', build: 1482, channel: 'STABLE', mode: 'SELF_HOSTED', support_state: 'SUPPORTED', last_report_at: null, last_run_status: 'ROLLED_BACK', php_version: '8.3', db_version: 'MariaDB 10.11', policy_mode: 'OFF', ring: 3, ring_name: 'conservative', offline_mode: true, health: null, below_minimum: false },
];

// ---------------------------------------------------------------- DL-1

describe('DL-1 Server releases', () => {
  it('shows the fleet KPIs and the releases with severity, editions, rollout and adoption', async () => {
    serve({ 'GET /admin/server/releases': releases });
    renderAt('/admin/server/releases', '/admin/server/releases', <ServerReleases />);
    expect(await screen.findByRole('heading', { name: 'Server Releases', level: 1 })).toBeInTheDocument();
    expect(await screen.findByText('136 online, 12 offline mode')).toBeInTheDocument();
    expect(screen.getByText('148')).toBeInTheDocument();
    expect(screen.getByText('Minimum stable is 2.9.0')).toBeInTheDocument();
    const table = screen.getByRole('table', { name: 'Server releases' });
    const rows = within(table).getAllByRole('row');
    expect(rows).toHaveLength(4);
    expect(within(rows[1]).getByRole('link', { name: '3.1.0' })).toHaveAttribute('href', '/admin/server/releases/rel_310');
    expect(within(rows[2]).getByRole('link', { name: '3.0.0' })).toHaveAttribute('href', '/admin/server/releases/rel_300/rollout');
    expect(within(rows[3]).getByText('All editions (core patch)')).toBeInTheDocument();
    expect(within(rows[2]).getByRole('progressbar', { name: 'Adoption of 3.0.0' })).toHaveAttribute('aria-valuenow', '82');
    expect(screen.getByRole('link', { name: /New server release/ })).toHaveAttribute('href', '/admin/server/releases/new');
  });

  it('filters by channel and status through the API query', async () => {
    const user = userEvent.setup();
    serve({ 'GET /admin/server/releases': releases });
    renderAt('/admin/server/releases', '/admin/server/releases', <ServerReleases />);
    await screen.findByRole('table', { name: 'Server releases' });
    await user.selectOptions(screen.getByLabelText('Channel'), 'LTS');
    await user.selectOptions(screen.getByLabelText('Status'), 'withdrawn');
    await waitFor(() => {
      const last = calls.filter((c) => c.path === '/admin/server/releases').at(-1)!;
      expect([last.query.get('channel'), last.query.get('status')]).toEqual(['LTS', 'withdrawn']);
    });
  });

  it('hides the create button without release.create', async () => {
    serve({ 'GET /admin/server/releases': releases }, ['release.view']);
    renderAt('/admin/server/releases', '/admin/server/releases', <ServerReleases />);
    await screen.findByRole('table', { name: 'Server releases' });
    expect(screen.queryByRole('link', { name: /New server release/ })).not.toBeInTheDocument();
  });

  it('routes published releases to the rollout and drafts to the form', () => {
    expect(serverReleaseHref({ id: 'r', status: 'PAUSED' })).toBe('/admin/server/releases/r/rollout');
    expect(serverReleaseHref({ id: 'r', status: 'APPROVED' })).toBe('/admin/server/releases/r');
  });
});

// ---------------------------------------------------------------- DL-2

describe('DL-2 New server release', () => {
  it('validates and creates the draft with every server field', async () => {
    const user = userEvent.setup();
    serve({ 'POST /admin/server/releases': (_r: TransportRequest, b: Record<string, unknown> | null) => ({ ...baseRelease, id: 'rel_new', version: b?.version, build: b?.build, channel: 'STABLE', status: 'DRAFT' }) });
    renderAt('/admin/server/releases/new', '/admin/server/releases/new', <NewServerRelease />);
    await user.type(screen.getByLabelText(/^Version/), '3.1');
    await user.click(screen.getAllByRole('button', { name: 'Save draft' })[0]);
    expect(await screen.findByText('Use SemVer, e.g. 3.1.0')).toBeInTheDocument();
    expect(calls.some((c) => c.method === 'POST')).toBe(false);

    await user.clear(screen.getByLabelText(/^Version/));
    await user.type(screen.getByLabelText(/^Version/), '3.1.0');
    await user.type(screen.getByLabelText(/^Build/), '1574');
    await user.type(screen.getByLabelText('Commit'), '07ebd36c');
    await user.click(screen.getByRole('radio', { name: 'Security' }));
    expect(screen.getByText(/Rings compress to 0 → 2/)).toBeInTheDocument();
    await user.type(screen.getByLabelText('Update from at least'), '3.0.0');
    await user.click(screen.getByLabelText('Every migration is reversible (leave off if any is one-way)'));
    await user.click(screen.getByLabelText('Professional'));
    await user.type(screen.getByLabelText('Release notes'), '## Security{enter}- Fix');
    await user.click(screen.getAllByRole('button', { name: 'Save draft' })[0]);
    await screen.findByText('Navigated elsewhere');
    const post = calls.find((c) => c.method === 'POST' && c.path === '/admin/server/releases')!;
    expect(post.body).toMatchObject({ version: '3.1.0', build: 1574, commit: '07ebd36c', channel: 'STABLE', severity: 'SECURITY', min_from_version: '3.0.0', migrations_reversible: true, editions: ['BUSINESS', 'ENTERPRISE'] });
    expect(String(post.body?.notes)).toContain('## Security');
  });

  it('shows each edition package with its validation state and the checklist', async () => {
    const processing = rev({ id: 'rev_2', status: 'PROCESSING', file_name: 'worksuite-server-enterprise-3.1.0+1574.zip', pipeline: [{ step: 'upload', status: 'done', detail: null, at: null }, { step: 'scan', status: 'done', detail: null, at: null }, { step: 'metadata', status: 'running', detail: 'Checking files.sha256', at: null }, { step: 'ready', status: 'pending', detail: null, at: null }] });
    const d = detail({
      server_meta: { ...detail().server_meta!, editions: ['FREE', 'BUSINESS', 'ENTERPRISE'] },
      packages: [pkg({ edition: 'BUSINESS', active_revision: rev({}), revisions: [rev({})] }), pkg({ id: 'pkg_2', edition: 'ENTERPRISE', status: 'PROCESSING', revisions: [processing], processing_revision: processing })],
    });
    serve({ 'GET /admin/releases/rel_310': d, 'GET /admin/applications/server': { id: 'app_server', platforms: [] } });
    renderAt('/admin/server/releases/rel_310', '/admin/server/releases/:id', <NewServerRelease />);
    const list = await screen.findByRole('list', { name: 'Edition packages' });
    const items = within(list).getAllByRole('listitem');
    expect(items.map((i) => i.getAttribute('data-state'))).toEqual(['Missing', 'Valid', 'Validating']);
    expect(within(items[1]).getByText(/412 MB · attestation ok · scan clean/)).toBeInTheDocument();
    expect(within(items[2]).getByText('Checking files.sha256')).toBeInTheDocument();
    expect(within(items[2]).getByRole('progressbar')).toHaveAttribute('aria-valuenow', '50');
    expect(screen.getByRole('list', { name: 'Release checklist' })).toBeInTheDocument();
    expect(screen.getByText('QA approves, then a Release Manager publishes with MFA. The same person cannot do both.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^Version/)).toBeDisabled();
  });

  it('derives package states', () => {
    expect(packageState(undefined).state).toBe('Missing');
    expect(packageState(pkg({ status: 'REJECTED', revisions: [rev({ status: 'REJECTED', reject_code: 'EDITION_CONTENT_VIOLATION' })] }))).toMatchObject({ state: 'Rejected', detail: 'Rejected: EDITION_CONTENT_VIOLATION' });
  });

  it('publishes only after the version is typed, and explains the two-person rule', async () => {
    const user = userEvent.setup();
    serve({
      'POST /admin/server/releases/rel_310/publish': () => new Response(JSON.stringify({ error: { code: 'SOD_VIOLATION', message: 'The QA approver cannot also publish this server release' } }), { status: 403 }),
    });
    const qc = new QueryClient();
    render(
      <QueryClientProvider client={qc}>
        <ToastProvider>
          <PublishServerDialog release={detail()} open onClose={() => {}} onDone={() => {}} />
        </ToastProvider>
      </QueryClientProvider>,
    );
    const publish = screen.getByRole('button', { name: 'Publish release' });
    expect(publish).toBeDisabled();
    await user.type(screen.getByLabelText(/Type-to-confirm/), '3.1.0');
    expect(publish).toBeEnabled();
    await user.click(publish);
    expect(await screen.findByText('You gave the QA approval for this release, so another Release Manager has to publish it.')).toBeInTheDocument();
    expect(calls.find((c) => c.path.endsWith('/publish'))?.body).toEqual({ confirm_version: '3.1.0' });
  });
});

// ---------------------------------------------------------------- DL-3

describe('DL-3 Release rollout', () => {
  it('shows rings, the failing health gate, outcomes and the pause reason', async () => {
    serve({ 'GET /admin/server/releases/rel_310/rollout': rollout, 'GET /admin/releases/rel_310': detail({ status: 'PUBLISHED' }) });
    renderAt('/admin/server/releases/rel_310/rollout', '/admin/server/releases/:id/rollout', <ServerRollout />);
    expect(await screen.findByRole('heading', { name: /Rollout · WorkSuite Server 3.1.0/ })).toBeInTheDocument();
    const rings = screen.getByRole('region', { name: 'Rings' });
    expect(within(rings).getByRole('article', { name: 'Ring 2 · Broad' })).toHaveTextContent('Paused');
    expect(within(rings).getByRole('article', { name: 'Ring 1 · Early' })).toHaveTextContent('97 / 100');
    expect(screen.getByText(/Health gate paused ring 2/)).toBeInTheDocument();
    const gate = screen.getByRole('table', { name: 'Health gate signals' });
    const rollback = within(gate).getByText('Rollback rate').closest('tr')!;
    expect(within(rollback).getByText('3 of 100 · 3 %')).toBeInTheDocument();
    expect(within(rollback).getByText('FAIL')).toBeInTheDocument();
    expect(screen.getByText('Auto run in their next window').nextSibling).toHaveTextContent('27');
    expect(screen.getByRole('button', { name: 'Resume' })).toBeInTheDocument();
    expect(await screen.findByText('Admin notification on publish')).toBeInTheDocument(); // existing CampaignPanel
  });

  it('withdraws only with a reason and the typed version, optionally asking for rollback', async () => {
    const user = userEvent.setup();
    serve({
      'GET /admin/server/releases/rel_310/rollout': { ...rollout, pause: null, status: 'ACTIVE' },
      'GET /admin/releases/rel_310': detail({ status: 'PUBLISHED' }),
      'POST /admin/releases/rel_310/withdraw': { ...baseRelease, id: 'rel_310', version: '3.1.0', status: 'WITHDRAWN' },
    });
    renderAt('/admin/server/releases/rel_310/rollout', '/admin/server/releases/:id/rollout', <ServerRollout />);
    await user.click(await screen.findByRole('button', { name: 'Withdraw…' }));
    const dialog = screen.getByRole('dialog');
    const confirm = within(dialog).getByRole('button', { name: 'Withdraw release' });
    expect(confirm).toBeDisabled();
    await user.type(within(dialog).getByLabelText(/Reason/), 'Data migration bug');
    await user.type(within(dialog).getByLabelText(/Type-to-confirm/), '3.1.0');
    await user.click(within(dialog).getByLabelText('Ask installations already on this release to roll back'));
    expect(confirm).toBeEnabled();
    await user.click(confirm);
    await waitFor(() => expect(calls.find((c) => c.path === '/admin/releases/rel_310/withdraw')?.body).toEqual({ reason: 'Data migration bug', confirm_version: '3.1.0', request_rollback: true }));
  });

  it('extends the open ring with a reason', async () => {
    const user = userEvent.setup();
    serve({
      'GET /admin/server/releases/rel_310/rollout': { ...rollout, pause: null, status: 'ACTIVE' },
      'GET /admin/releases/rel_310': detail({ status: 'PUBLISHED' }),
      'POST /admin/server/releases/rel_310/rollout/extend': rollout,
    });
    renderAt('/admin/server/releases/rel_310/rollout', '/admin/server/releases/:id/rollout', <ServerRollout />);
    await user.click(await screen.findByRole('button', { name: 'Extend ring' }));
    const dialog = screen.getByRole('dialog');
    await user.selectOptions(within(dialog).getByLabelText('Hold the open ring longer by'), '48');
    await user.type(within(dialog).getByLabelText(/Reason/), 'Watch rollbacks');
    await user.click(within(dialog).getByRole('button', { name: 'Extend the open ring' }));
    await waitFor(() => expect(calls.find((c) => c.path.endsWith('/rollout/extend'))?.body).toEqual({ reason: 'Watch rollbacks', hours: 48 }));
  });
});

// ---------------------------------------------------------------- DL-4

describe('DL-4 Installations fleet', () => {
  const stats = { by_edition: { BUSINESS: [{ version: '3.1.0', count: 1 }], FREE: [{ version: '2.8.6', count: 1 }] }, pending_updates: 2, failed_runs: [], rolled_back: [], unsupported: 1, segments: { all: 148, on_latest: 121, latest_stable: '3.0.0', below_minimum: 4, on_hold: 2, stale: 6 }, adoption: [{ release_id: 'rel_300', version: '3.0.0', status: 'PUBLISHED', channel: 'STABLE', installations: 121, pct: 81.8 }] };

  it('lists installations with policy, ring, heartbeat, last run and health', async () => {
    serve({ 'GET /admin/installations/stats': stats, 'GET /admin/installations': { data: installations, total: 148 } });
    renderAt('/admin/server/installations', '/admin/server/installations', <ServerFleet />);
    const table = await screen.findByRole('table', { name: 'Installations' });
    const demo = within(table).getByText('demo.worksuite.lk').closest('tr')!;
    expect(within(demo).getByText('Livezen-hosted')).toBeInTheDocument();
    expect(within(demo).getByText('Patches and minors')).toBeInTheDocument();
    expect(within(demo).getByText('Canary')).toBeInTheDocument();
    expect(within(demo).getByText('Succeeded')).toBeInTheDocument();
    const old = within(table).getByText('ws.customer-d.example').closest('tr')!;
    expect(within(old).getByText('(below minimum)')).toBeInTheDocument();
    expect(within(old).getByText('Professional')).toBeInTheDocument();
    expect(within(old).getByText('Failed')).toBeInTheDocument();
    const offline = within(table).getByText('intranet.customer-e.example').closest('tr')!;
    expect(within(offline).getByText('Self-hosted · offline mode')).toBeInTheDocument();
    expect(within(offline).getByText('offline')).toBeInTheDocument();
    expect(screen.getByText('3 of 148 · sorted by last heartbeat')).toBeInTheDocument();
    expect(screen.getByRole('list', { name: 'Adoption per release' })).toHaveTextContent('3.0.0');
  });

  it('filters by segment chips, edition and search', async () => {
    const user = userEvent.setup();
    serve({ 'GET /admin/installations/stats': stats, 'GET /admin/installations': { data: installations, total: 3 } });
    renderAt('/admin/server/installations', '/admin/server/installations', <ServerFleet />);
    await screen.findByRole('table', { name: 'Installations' });
    const chip = screen.getByRole('button', { name: 'Below minimum · 4' });
    await user.click(chip);
    expect(chip).toHaveAttribute('aria-pressed', 'true');
    await waitFor(() => expect(calls.filter((c) => c.path === '/admin/installations').at(-1)!.query.get('segment')).toBe('below_minimum'));
    await user.click(screen.getByRole('button', { name: 'On 3.0.0 · 121' }));
    await waitFor(() => expect(calls.filter((c) => c.path === '/admin/installations').at(-1)!.query.get('version')).toBe('3.0.0'));
    await user.selectOptions(screen.getByLabelText('Edition'), 'ENTERPRISE');
    await user.type(screen.getByRole('searchbox', { name: 'Search installations' }), 'customer{enter}');
    await waitFor(() => {
      const last = calls.filter((c) => c.path === '/admin/installations').at(-1)!;
      expect([last.query.get('edition'), last.query.get('q')]).toEqual(['ENTERPRISE', 'customer']);
    });
  });
});

// ---------------------------------------------------------------- helpers & navigation

describe('server console helpers and navigation', () => {
  it('labels editions, policies and installation kinds', () => {
    expect([editionLabel('FREE'), editionLabel('ALL'), editionLabel('ENTERPRISE')]).toEqual(['Professional', 'All editions (core patch)', 'Enterprise']);
    expect([policyLabel('AUTO_SECURITY'), policyLabel(null)]).toEqual(['Security only', '—']);
    expect(installationKind({ mode: 'AIR_GAPPED', offline_mode: false })).toBe('Self-hosted · offline mode');
  });

  it('adds Server Releases to the Server section of the admin navigation', () => {
    const server = visibleNav(me().user).find((s) => s.label === 'Server')!;
    expect(server.items.map((i) => i.label)).toEqual(['Server Releases', 'Installations', 'Managed Fleet']);
    expect(server.items[0].to).toBe('/admin/server/releases');
    expect(visibleNav(me(['application.view']).user).find((s) => s.label === 'Server')).toBeUndefined();
  });
});
