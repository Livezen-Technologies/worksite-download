/** Release screen (§7.3): Applications › App › Version with tabs and workflow actions from allowed_actions. */
import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useQuery } from '@tanstack/react-query';
import { adminApi, adminKeys } from '@/services/admin';
import { ApiError } from '@/services/api';
import type { AdminReleaseDetail } from '@/types/api';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { Button, ErrorState, Spinner } from '@/components/ui';
import { Tabs } from '@/components/ui/Tabs';
import { ChannelBadge, ReleaseStatusBadge, ReleaseTypeBadge } from '@/components/StatusBadges';
import { useToast } from '@/components/ui/Toast';
import { useRelease, useReleaseMutation } from './hooks';
import { FilesTab } from './FilesTab';
import { ChangelogEditor } from './ChangelogEditor';
import { ManifestTab } from './ManifestTab';
import { ActivityTab, DevicesTab, DownloadsTab, OverviewTab, RolloutTab, SettingsTab } from './tabs';
import { PreviewDialog, QaDialog, ReasonDialog } from './dialogs';
import { PublishModal } from './PublishModal';
import { WithdrawModal } from './WithdrawModal';

const TABS = ['overview', 'files', 'changelog', 'manifest', 'rollout', 'downloads', 'devices', 'activity', 'settings'] as const;
type Tab = (typeof TABS)[number];

function ReleaseActions({ release }: { release: AdminReleaseDetail }) {
  const a = release.allowed_actions;
  const toast = useToast();
  const [dialog, setDialog] = useState<null | 'publish' | 'withdraw' | 'pause' | 'supersede' | 'qa' | 'approve' | 'preview'>(null);
  const close = () => setDialog(null);
  const onChecklistError = (e: unknown) => {
    if (e instanceof ApiError && e.code === 'CHECKLIST_FAILED') {
      const items = (e.details?.items as string[] | undefined) ?? [];
      toast.error(
        <span>
          <strong>Checklist failed.</strong> {items.join(' · ')}
        </span>,
      );
    }
  };
  const submitTest = useReleaseMutation(release.id, () => adminApi.submitTest(release.id), 'Submitted for test');
  const startTesting = useReleaseMutation(release.id, () => adminApi.startTesting(release.id), 'Testing started');
  const qa = useReleaseMutation(release.id, (v: Parameters<typeof adminApi.qa>[1]) => adminApi.qa(release.id, v), (r) => (r.status === 'DRAFT' ? 'QA failed — release returned to draft' : 'QA pass recorded'));
  const approve = useReleaseMutation(release.id, (comment: string) => adminApi.approve(release.id, comment || undefined), 'Release approved');
  const pause = useReleaseMutation(release.id, (reason: string) => adminApi.pause(release.id, reason), 'Release paused — public stable links fall back to the previous release');
  const resume = useReleaseMutation(release.id, () => adminApi.resume(release.id), 'Release resumed');
  const supersede = useReleaseMutation(release.id, (reason: string) => adminApi.supersede(release.id, reason), 'Release superseded');

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Button size="sm" onClick={() => setDialog('preview')}>
        Preview public page
      </Button>
      {a.includes('submit_test') && (
        <Button size="sm" loading={submitTest.isPending} onClick={() => submitTest.mutate(undefined, { onError: onChecklistError })}>
          Submit for test
        </Button>
      )}
      {a.includes('start_testing') && (
        <Button size="sm" loading={startTesting.isPending} onClick={() => startTesting.mutate(undefined)}>
          Start testing
        </Button>
      )}
      {a.includes('qa') && (
        <Button size="sm" onClick={() => setDialog('qa')}>
          QA pass / fail…
        </Button>
      )}
      {a.includes('approve') && (
        <Button size="sm" variant="primary" onClick={() => setDialog('approve')}>
          Approve…
        </Button>
      )}
      {a.includes('resume') && (
        <Button size="sm" variant="primary" loading={resume.isPending} onClick={() => resume.mutate(undefined)}>
          Resume
        </Button>
      )}
      {a.includes('pause') && (
        <Button size="sm" onClick={() => setDialog('pause')}>
          Pause
        </Button>
      )}
      {a.includes('supersede') && (
        <Button size="sm" onClick={() => setDialog('supersede')}>
          Supersede…
        </Button>
      )}
      {a.includes('withdraw') && (
        <Button size="sm" variant="danger" onClick={() => setDialog('withdraw')}>
          Withdraw…
        </Button>
      )}
      {a.includes('publish') && (
        <Button size="sm" variant="primary" onClick={() => setDialog('publish')}>
          {release.app.kind === 'SERVER' ? 'Publish & notify…' : 'Publish…'}
        </Button>
      )}

      {dialog === 'publish' && <PublishModal release={release} open onClose={close} />}
      {dialog === 'withdraw' && <WithdrawModal release={release} open onClose={close} />}
      <PreviewDialog open={dialog === 'preview'} onClose={close} releaseId={release.id} />
      {dialog === 'qa' && <QaDialog open release={release} onClose={close} busy={qa.isPending} onSubmit={(v) => qa.mutate(v, { onSuccess: close })} />}
      <ReasonDialog open={dialog === 'approve'} onClose={close} title={`Approve ${release.version}`} description="Release Manager approval. On Stable, the publisher cannot be the uploader (separation of duties)." confirmLabel="Approve" required={false} busy={approve.isPending} onConfirm={(c) => approve.mutate(c, { onSuccess: close })} />
      <ReasonDialog
        open={dialog === 'pause'}
        onClose={close}
        title={`Pause ${release.version}?`}
        description="Stops new offers immediately. The public stable link falls back to the previous published release. MFA required."
        confirmLabel="Pause release"
        busy={pause.isPending}
        onConfirm={(r) => pause.mutate(r, { onSuccess: close })}
      />
      <ReasonDialog open={dialog === 'supersede'} onClose={close} title={`Supersede ${release.version}?`} description="Marks this release SUPERSEDED; the channel manifest points to the newest release. The version page remains. MFA required." confirmLabel="Supersede" busy={supersede.isPending} onConfirm={(r) => supersede.mutate(r, { onSuccess: close })} />
    </div>
  );
}

export default function ReleaseScreen() {
  const { id = '', tab = 'files' } = useParams();
  const q = useRelease(id);
  const r = q.data;
  const apps = useQuery({ queryKey: adminKeys.apps, queryFn: adminApi.apps, enabled: !!r });
  const app = apps.data?.data.find((a) => a.id === r?.app.id);
  useDocumentTitle(r ? `${r.app.name} ${r.version} · Update Center` : 'Release · Update Center');
  if (q.isLoading) return <Spinner />;
  if (q.error || !r) return <ErrorState error={q.error} retry={q.refetch} />;
  const active: Tab = (TABS as readonly string[]).includes(tab) ? (tab as Tab) : 'files';
  const isServer = r.app.kind === 'SERVER';
  const label = (t: Tab) => (t === 'files' && isServer ? 'Edition packages' : t.charAt(0).toUpperCase() + t.slice(1));
  const migrations = r.server_meta?.migrations?.count;
  return (
    <>
      <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <nav aria-label="Breadcrumb" className="text-[12px] text-subtle">
            <Link className="hover:text-ink" to={`/admin/applications/${r.app.id}`}>
              {r.app.name}
            </Link>{' '}
            /{' '}
            <Link className="hover:text-ink" to="/admin/releases">
              Releases
            </Link>
          </nav>
          <div className="mt-1 flex flex-wrap items-center gap-2">
            <h1 className="text-[22px] font-bold tracking-tight">
              {isServer ? 'WorkSuite ' : ''}
              <span className="font-mono">{r.version}</span>
            </h1>
            <ReleaseStatusBadge status={r.status} />
            <ChannelBadge channel={r.channel} />
            <ReleaseTypeBadge type={r.release_type} />
            <span className="text-[12px] text-subtle">
              build {r.build}
              {r.minimum_supported_version && <> · {isServer ? `from ≥ ${r.server_meta?.min_from_version ?? r.minimum_supported_version}` : `min ${r.minimum_supported_version}`}</>}
              {migrations ? ` · ${migrations} migrations` : ''}
            </span>
          </div>
        </div>
        <ReleaseActions release={r} />
      </div>
      <Tabs label="Release sections" active={active} panelId="release-panel" items={TABS.map((t) => ({ id: t, label: label(t), to: `/admin/releases/${r.id}/${t}` }))} className="mb-4" />
      <div id="release-panel" role="tabpanel" aria-labelledby={`tab-${active}`}>
        {active === 'overview' && <OverviewTab release={r} />}
        {active === 'files' && <FilesTab release={r} app={app} />}
        {active === 'changelog' && <ChangelogEditor key={r.changelog.version} release={r} />}
        {active === 'manifest' && <ManifestTab release={r} />}
        {active === 'rollout' && <RolloutTab release={r} />}
        {active === 'downloads' && <DownloadsTab release={r} />}
        {active === 'devices' && <DevicesTab release={r} />}
        {active === 'activity' && <ActivityTab release={r} />}
        {active === 'settings' && <SettingsTab key={r.updated_at} release={r} app={app} />}
      </div>
    </>
  );
}
