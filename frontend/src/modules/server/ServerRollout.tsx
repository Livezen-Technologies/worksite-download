import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { adminApi, adminKeys } from '@/services/admin';
import { errorMessage } from '@/services/api';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { can } from '@/core/permissions';
import { cx, formatNumber } from '@/core/format';
import type { GateSignal, RingState, ServerRollout as Rollout } from '@/types/api';
import { Alert, Badge, Button, Card, CardHeader, Checkbox, KeyValue, PageHeader, ProgressBar, Select, Table, Td, TextField, Th, type Tone } from '@/components/ui';
import { ReleaseStatusBadge } from '@/components/StatusBadges';
import { TypeToConfirm, isConfirmed } from '@/components/TypeToConfirm';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { CampaignPanel } from './CampaignPanel';
import { QueryState, SeverityBadge } from './serverUi';

const RING_TONE: Record<RingState, Tone> = { DONE: 'green', OPEN: 'blue', PAUSED: 'amber', WAITING: 'grey' };
const RING_LABEL: Record<RingState, string> = { DONE: 'Done', OPEN: 'Open', PAUSED: 'Paused', WAITING: 'Waiting' };
const SIGNAL_TONE: Record<GateSignal['state'], Tone> = { OK: 'green', WATCH: 'amber', FAIL: 'red' };
const GATE_TONE: Record<Rollout['gate']['state'], Tone> = { PASSING: 'green', WATCH: 'amber', FAILING: 'red' };

type Dialog = null | 'pause' | 'resume' | 'extend' | 'withdraw';

function daysSince(iso: string | null): number | null {
  if (!iso) return null;
  return Math.max(1, Math.ceil((Date.now() - new Date(iso).getTime()) / 86_400_000));
}

/** DL-3 Release rollout: rings, the health gate, what installations will do, campaign; extend, pause and withdraw. */
export default function ServerRollout() {
  const { id = '' } = useParams();
  const { user } = useAuth();
  const q = useQuery({ queryKey: adminKeys.serverRollout(id), queryFn: () => adminApi.serverRollout(id), refetchInterval: 60_000 });
  const detail = useQuery({ queryKey: adminKeys.release(id), queryFn: () => adminApi.release(id) });
  const [dialog, setDialog] = useState<Dialog>(null);
  const r = q.data;
  useDocumentTitle(r ? `Rollout ${r.release.version} · Update Center` : 'Rollout · Update Center');
  if (!r) return <QueryState q={q} />;

  const canRollout = can(user, 'release.publish');
  const live = r.release.status === 'PUBLISHED';
  const day = daysSince(r.release.published_at);
  const o = r.outcomes;
  return (
    <>
      <PageHeader
        breadcrumb={
          <>
            <Link to="/admin/server/releases" className="link">
              Server Releases
            </Link>{' '}
            › {r.release.version}
          </>
        }
        title={
          <>
            Rollout · WorkSuite Server <span className="font-mono">{r.release.version}</span>
          </>
        }
        badges={
          <>
            <ReleaseStatusBadge status={r.release.status} />
            <SeverityBadge severity={r.release.severity} />
            {r.compressed && <Badge tone="red">Rings 0 → 2</Badge>}
          </>
        }
        sub={day ? `Day ${day} after publishing. The download page lists it only once every ring is open.` : 'Not published yet.'}
        actions={
          canRollout &&
          live && (
            <>
              {r.pause === null ? (
                <>
                  <Button size="sm" onClick={() => setDialog('extend')} disabled={r.rings.every((x) => x.opened_at)}>
                    Extend ring
                  </Button>
                  <Button size="sm" onClick={() => setDialog('pause')}>
                    Pause rollout
                  </Button>
                </>
              ) : (
                <>
                  <Button size="sm" onClick={() => setDialog('extend')}>
                    Extend ring
                  </Button>
                  <Button size="sm" variant="primary" onClick={() => setDialog('resume')}>
                    Resume
                  </Button>
                </>
              )}
              {can(user, 'release.withdraw') && (
                <Button size="sm" variant="danger" onClick={() => setDialog('withdraw')}>
                  Withdraw…
                </Button>
              )}
            </>
          )
        }
      />
      {r.pause && (
        <Alert tone={r.pause.kind === 'GATE' ? 'bad' : 'warn'} className="mb-4" title={r.pause.kind === 'GATE' ? `Health gate paused ring ${r.pause.ring ?? ''}:` : 'Rollout paused:'}>
          {r.pause.reason || 'No reason given'} <span className="text-subtle">· {r.pause.by}</span>
        </Alert>
      )}
      {r.release.status === 'WITHDRAWN' && (
        <Alert tone="bad" className="mb-4" title="Withdrawn.">
          New grants stopped and scheduled automatic runs were cancelled.
        </Alert>
      )}

      <section aria-label="Rings" className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {r.rings.map((ring) => {
          const pct = ring.installations ? (ring.updated / ring.installations) * 100 : 0;
          return (
            <article key={ring.ring} className={cx('card px-4 py-3', ring.state === 'OPEN' && 'border-2 border-brand')} aria-label={ring.name}>
              <div className="flex items-center justify-between gap-2">
                <h2 className="text-[13px] font-bold">{ring.name}</h2>
                <Badge tone={RING_TONE[ring.state]}>{RING_LABEL[ring.state]}</Badge>
              </div>
              <div className="mt-1 font-mono text-[20px] font-bold">
                {formatNumber(ring.updated)} / {formatNumber(ring.installations)}
              </div>
              <ProgressBar value={pct} label={`${ring.name}: ${ring.updated} of ${ring.installations} updated`} tone={ring.state === 'PAUSED' ? 'bg-warn' : 'bg-ok'} className="my-1.5" />
              <p className="text-[11.5px] text-subtle">{ring.detail}</p>
            </article>
          );
        })}
      </section>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]">
        <Card>
          <CardHeader title="Health gate" sub="Any red signal pauses the next ring automatically" actions={<Badge tone={GATE_TONE[r.gate.state]}>{r.gate.state}</Badge>} />
          <Table label="Health gate signals">
            <thead>
              <tr>
                <Th>Signal</Th>
                <Th>Now</Th>
                <Th>Pauses at</Th>
                <Th>State</Th>
              </tr>
            </thead>
            <tbody>
              {r.gate.signals.map((s) => (
                <tr key={s.key}>
                  <Td className="font-semibold">{s.label}</Td>
                  <Td className="font-mono text-[12px]">{s.display}</Td>
                  <Td className="text-[12px] text-subtle">{s.threshold}</Td>
                  <Td>
                    <Badge tone={SIGNAL_TONE[s.state]}>{s.state}</Badge>
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
          {r.gate.reasons.length > 0 && (
            <ul className="list-disc px-8 pt-2 pb-4 text-[12px] text-bad">
              {r.gate.reasons.map((x) => (
                <li key={x}>{x}</li>
              ))}
            </ul>
          )}
        </Card>
        <Card>
          <CardHeader title="What installations will do" />
          <div className="px-4 pb-4">
            <KeyValue
              rows={[
                ['Updated', formatNumber(o.updated)],
                ['Auto run in their next window', formatNumber(o.auto_scheduled)],
                ['Waiting for an admin', formatNumber(o.waiting_for_admin)],
                ['Postponed by an admin', formatNumber(o.postponed)],
                ['Held (paused ring or rolled back)', formatNumber(o.held)],
                ['Offline mode, told by email', formatNumber(o.offline)],
              ]}
            />
          </div>
        </Card>
      </div>

      <div className="mt-4">
        {r.campaign && (
          <p className="mb-2 text-[12.5px] text-subtle" role="status">
            In-app notice delivered to {formatNumber(r.campaign.delivered.in_app)} installations · email sent to {formatNumber(r.campaign.delivered.email)} admin contacts
          </p>
        )}
        {detail.data ? <CampaignPanel release={detail.data} canEdit={can(user, 'campaign.manage')} /> : null}
      </div>

      <RolloutDialog rollout={r} dialog={dialog} onClose={() => setDialog(null)} />
    </>
  );
}

function RolloutDialog({ rollout, dialog, onClose }: { rollout: Rollout; dialog: Dialog; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [reason, setReason] = useState('');
  const [hours, setHours] = useState('24');
  const [confirm, setConfirm] = useState('');
  const [rollback, setRollback] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const id = rollout.release.id;
  const version = rollout.release.version;
  const close = () => {
    setReason('');
    setConfirm('');
    setRollback(false);
    setError(null);
    onClose();
  };
  const run = async () => {
    setBusy(true);
    setError(null);
    try {
      if (dialog === 'withdraw') {
        await adminApi.withdrawServerRelease(id, { reason: reason.trim(), confirm_version: confirm.trim(), request_rollback: rollback });
        toast.success(`${version} withdrawn — scheduled automatic runs were cancelled`);
      } else if (dialog) {
        await adminApi.serverRolloutAction(id, dialog, { reason: reason.trim() || undefined, hours: dialog === 'extend' ? Number(hours) : undefined });
        toast.success(dialog === 'extend' ? `Ring held ${hours} h longer` : dialog === 'pause' ? 'Rollout paused' : 'Rollout resumed');
      }
      void qc.invalidateQueries({ queryKey: adminKeys.serverRollout(id) });
      void qc.invalidateQueries({ queryKey: adminKeys.release(id) });
      void qc.invalidateQueries({ queryKey: ['admin', 'server-releases'] });
      close();
    } catch (e) {
      if ((e as { code?: string }).code !== 'MFA_CANCELLED') setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  if (!dialog) return null;
  const needsReason = dialog !== 'resume';
  const ok = (!needsReason || reason.trim().length > 0) && (dialog !== 'withdraw' || isConfirmed(version, confirm));
  const title = { pause: 'Pause rollout', resume: 'Resume rollout', extend: 'Extend the open ring', withdraw: `Withdraw ${version}?` }[dialog];
  return (
    <Modal
      open
      onClose={close}
      dismissible={!busy}
      size="sm"
      tone={dialog === 'withdraw' ? 'danger' : 'default'}
      title={title}
      footer={
        <>
          <span className="text-[11.5px] text-subtle">Step-up MFA · audited</span>
          <div className="flex gap-2">
            <Button onClick={close} disabled={busy}>
              Cancel
            </Button>
            <Button variant={dialog === 'withdraw' ? 'danger' : 'primary'} loading={busy} disabled={!ok} onClick={() => void run()}>
              {dialog === 'withdraw' ? 'Withdraw release' : title}
            </Button>
          </div>
        </>
      }
    >
      <div className="space-y-3 text-[12.5px]">
        {dialog === 'pause' && <p>No further ring opens and installations that have not run yet are held. Rings that already updated keep the release.</p>}
        {dialog === 'resume' && <p>The signals failing now are acknowledged; the gate pauses again only if a new signal fails. The next ring opens when its hold time has passed.</p>}
        {dialog === 'extend' && (
          <Select
            label="Hold the open ring longer by"
            value={hours}
            onChange={(e) => setHours(e.target.value)}
            options={[
              { value: '24', label: '24 hours' },
              { value: '48', label: '48 hours' },
              { value: '72', label: '72 hours' },
              { value: '168', label: '7 days' },
            ]}
          />
        )}
        {dialog === 'withdraw' && (
          <>
            <p>New download grants stop, every scheduled automatic run is cancelled, and tenant catalogs show the release as withdrawn within 15 minutes.</p>
            <Checkbox label="Ask installations already on this release to roll back" checked={rollback} onChange={(e) => setRollback(e.target.checked)} />
          </>
        )}
        <TextField label={needsReason ? 'Reason (required, audited)' : 'Reason (optional)'} value={reason} onChange={(e) => setReason(e.target.value)} required={needsReason} />
        {dialog === 'withdraw' && <TypeToConfirm expected={version} value={confirm} onChange={setConfirm} />}
        {error && <Alert tone="bad">{error}</Alert>}
      </div>
    </Modal>
  );
}
