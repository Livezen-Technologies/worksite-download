/** Portal › Licences: the customer's own WorkSuite Server licences (IMPL-LIC-01 P3). */
import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Copy, KeyRound } from 'lucide-react';
import { portalApi, portalKeys, type PortalLicence } from '@/services/portal';
import { errorMessage } from '@/services/api';
import { formatDateLong, formatDateTime, timeAgo } from '@/core/format';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, ErrorState, KeyValue, Select, Spinner, Table, Td, TextField, Th, type Tone } from '@/components/ui';
import { useToast } from '@/components/ui/Toast';

const STATE: Record<string, { label: string; tone: Tone }> = {
  ACTIVE: { label: 'Active', tone: 'green' },
  EXPIRED: { label: 'Expired', tone: 'amber' },
  SUSPENDED: { label: 'Suspended', tone: 'amber' },
  REVOKED: { label: 'Revoked', tone: 'red' },
  NOT_STARTED: { label: 'Not started', tone: 'grey' },
};
const EDITION: Record<string, string> = { FREE: 'Professional', BUSINESS: 'Business', ENTERPRISE: 'Enterprise' };
const TYPE: Record<string, string> = { PRODUCTION: 'Production', STAGING: 'Staging', DR: 'Disaster recovery' };

function KeyBox({ value }: { value: string }) {
  const toast = useToast();
  return (
    <div className="mt-2 flex items-center gap-2 rounded-lg border border-line bg-ground px-3 py-2.5">
      <code className="min-w-0 flex-1 font-mono text-[14px] font-semibold break-all select-all">{value}</code>
      <Button size="sm" icon={<Copy className="size-3.5" aria-hidden />} onClick={() => { void navigator.clipboard?.writeText(value); toast.success('Licence key copied'); }}>
        Copy
      </Button>
    </div>
  );
}

export function MyLicences() {
  const q = useQuery({ queryKey: portalKeys.licences, queryFn: portalApi.licences });
  const [trial, setTrial] = useState(false);
  if (q.isLoading) return <Spinner />;
  if (q.error) return <ErrorState error={q.error} retry={() => void q.refetch()} />;
  const rows = q.data?.data ?? [];
  return (
    <>
      {rows.length === 0 ? (
        <EmptyState title="No licences yet" icon={<KeyRound className="size-5" aria-hidden />}>
          Licences issued to your email address appear here.{q.data?.trial_available ? ' You can also start a 30-day trial.' : ''}
        </EmptyState>
      ) : (
        <Card>
          <Table label="My licences">
            <thead>
              <tr>
                <Th>Licence</Th>
                <Th>Edition</Th>
                <Th>Status</Th>
                <Th>Servers</Th>
                <Th>Expires</Th>
                <Th>Updates until</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((l) => (
                <tr key={l.id}>
                  <Td>
                    <Link className="link font-semibold" to={`/portal/licenses/${l.id}`}>{l.organization}</Link>
                    <div className="font-mono text-[11px] text-subtle">{l.license_key_masked}</div>
                  </Td>
                  <Td>{EDITION[l.edition] ?? l.edition}{l.is_trial ? ' · trial' : ''}</Td>
                  <Td><Badge tone={STATE[l.state]?.tone ?? 'grey'}>{STATE[l.state]?.label ?? l.state}</Badge></Td>
                  <Td>{l.active_activations} / {l.max_activations}</Td>
                  <Td>{formatDateLong(l.expires_at)}</Td>
                  <Td>{formatDateLong(l.updates_until)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}
      <div className="mt-4 flex flex-wrap gap-2">
        {q.data?.trial_available && <Button variant="primary" onClick={() => setTrial(true)}>Start a 30-day trial</Button>}
        <Link className="link self-center text-[13px]" to="/apps/server">How to install WorkSuite Server</Link>
      </div>
      {trial && <TrialCard onDone={() => setTrial(false)} />}
    </>
  );
}

function TrialCard({ onDone }: { onDone: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [edition, setEdition] = useState('BUSINESS');
  const [org, setOrg] = useState('');
  const m = useMutation({
    mutationFn: () => portalApi.startTrial(edition, org),
    onSuccess: () => void qc.invalidateQueries({ queryKey: portalKeys.licences }),
    onError: (e) => toast.error(errorMessage(e)),
  });
  return (
    <Card className="mt-4 p-4">
      {m.data ? (
        <>
          <Alert tone="ok" title="Your trial licence">Valid until {formatDateLong(m.data.expires_at)} for one server. You can show the key again from the licence.</Alert>
          <KeyBox value={m.data.license_key ?? ''} />
          <div className="mt-3"><Button onClick={onDone}>Done</Button></div>
        </>
      ) : (
        <div className="grid gap-3 sm:grid-cols-3">
          <Select label="Edition" value={edition} onChange={(e) => setEdition(e.target.value)} options={[{ value: 'BUSINESS', label: 'Business' }, { value: 'ENTERPRISE', label: 'Enterprise' }]} />
          <TextField label="Organisation" value={org} onChange={(e) => setOrg(e.target.value)} />
          <div className="flex items-end gap-2">
            <Button variant="primary" loading={m.isPending} onClick={() => m.mutate()}>Start trial</Button>
            <Button onClick={onDone}>Cancel</Button>
          </div>
        </div>
      )}
    </Card>
  );
}

export function LicenceDetail() {
  const { id = '' } = useParams();
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: portalKeys.licence(id), queryFn: () => portalApi.licence(id) });
  const [shown, setShown] = useState<string | null>(null);
  const [preview, setPreview] = useState('');
  const pv = useQuery({ queryKey: [...portalKeys.licence(id), 'preview', preview], queryFn: () => portalApi.editionPreview(id, preview), enabled: preview !== '' });
  const refresh = (l?: PortalLicence) => (l ? qc.setQueryData(portalKeys.licence(id), l) : void qc.invalidateQueries({ queryKey: portalKeys.licence(id) }));
  const reveal = useMutation({ mutationFn: () => portalApi.reveal(id), onSuccess: (r) => setShown(r.license_key), onError: (e) => toast.error(errorMessage(e)) });
  const replace = useMutation({
    mutationFn: () => portalApi.replaceKey(id),
    onSuccess: (r) => { setShown(r.license_key ?? null); refresh(r); toast.success('New key issued — the old one activates no more servers'); },
    onError: (e) => toast.error(errorMessage(e)),
  });
  const deactivate = useMutation({
    mutationFn: (activationId: number) => portalApi.deactivate(id, activationId, 'Deactivated in the portal'),
    onSuccess: (r) => { refresh(r); toast.success('Server deactivated — its slot is free'); },
    onError: (e) => toast.error(errorMessage(e)),
  });
  const offline = useMutation({
    mutationFn: async (f: File) => portalApi.offline(id, JSON.parse(await f.text()) as unknown),
    onSuccess: (wsl) => {
      const url = URL.createObjectURL(new Blob([JSON.stringify(wsl, null, 2)], { type: 'application/json' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = `worksuite-licence-${String((wsl as { installation_id?: string }).installation_id ?? 'server')}.wsl`;
      a.click();
      URL.revokeObjectURL(url);
      refresh();
      toast.success('Licence file downloaded — import it on that server');
    },
    onError: (e) => toast.error(e instanceof SyntaxError ? 'That is not a request file (.wsr).' : errorMessage(e)),
  });

  if (q.isLoading) return <Spinner />;
  if (q.error || !q.data) return <ErrorState error={q.error} retry={() => void q.refetch()} />;
  const l = q.data;
  const active = (l.activations ?? []).filter((a) => a.active);
  return (
    <div className="flex flex-col gap-4">
      <Link className="link text-[13px]" to="/portal/licenses">‹ My licences</Link>
      <Card className="p-4">
        <div className="flex flex-wrap items-center gap-2">
          <h2 className="m-0 text-[18px] font-semibold">{l.organization}</h2>
          <Badge tone={STATE[l.state]?.tone ?? 'grey'}>{STATE[l.state]?.label ?? l.state}</Badge>
          <Badge tone="indigo">{EDITION[l.edition] ?? l.edition}</Badge>
          {l.is_trial && <Badge tone="purple">Trial</Badge>}
        </div>
        <KeyValue
          className="mt-3"
          rows={[
            ['Licence key', <span className="font-mono">{l.license_key_masked}</span>],
            ['Expires', formatDateLong(l.expires_at)],
            ['Support and updates until', formatDateLong(l.updates_until)],
            ['Servers', `${l.active_activations} of ${l.max_activations} in use`],
          ]}
        />
        {shown && <><Alert tone="warn" className="mt-3" title="Keep this key private">Anyone with it can activate a server on this licence.</Alert><KeyBox value={shown} /></>}
        <div className="mt-4 flex flex-wrap gap-2">
          {l.key_revealable && !shown && <Button loading={reveal.isPending} onClick={() => reveal.mutate()}>Show key</Button>}
          <Button loading={replace.isPending} onClick={() => { if (window.confirm('Issue a new key? Servers already activated keep working; the current key will activate no more servers.')) replace.mutate(); }}>Replace key</Button>
          <a className="link self-center text-[13px]" href="mailto:sales@worksuite.lk?subject=Renew%20WorkSuite%20licence">Renew or upgrade — contact sales</a>
        </div>
      </Card>

      <Card>
        <CardHeader title="Servers" sub="Deactivate a server you no longer use, or one that was lost, to free its slot. Its access ends at once." />
        {(l.activations ?? []).length === 0 ? (
          <p className="px-4 pb-4 text-[13px] text-subtle">Not activated on any server yet.</p>
        ) : (
          <Table label="Servers">
            <thead><tr><Th>Server</Th><Th>Type</Th><Th>Activated</Th><Th>Last check</Th><Th /></tr></thead>
            <tbody>
              {(l.activations ?? []).map((a) => (
                <tr key={a.id} className={a.active ? '' : 'opacity-60'}>
                  <Td><div className="font-semibold">{a.hostname || a.installation_id}</div><div className="font-mono text-[11px] text-subtle">{a.installation_id}</div></Td>
                  <Td>{TYPE[a.installation_type ?? 'PRODUCTION'] ?? a.installation_type}</Td>
                  <Td className="text-[12px]">{formatDateTime(a.activated_at)}</Td>
                  <Td className="text-[12px]">{a.active ? timeAgo(a.last_validated_at) : `Deactivated ${formatDateLong(a.deactivated_at)}`}</Td>
                  <Td>{a.active && <Button size="sm" loading={deactivate.isPending} onClick={() => { if (window.confirm(`Deactivate ${a.hostname || a.installation_id}? It stops working at once.`)) deactivate.mutate(a.id); }}>Deactivate</Button>}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
        <p className="px-4 pb-4 text-[12px] text-subtle">{active.length} active.</p>
      </Card>

      <Card className="p-4">
        <h3 className="m-0 text-[14px] font-semibold">Offline activation</h3>
        <p className="mt-1 text-[13px] text-subtle">For a server without internet access: upload the request file (.wsr) it made in Admin Center › System › License, and import the licence file you get back on that server.{l.entitlements?.['offline.license.enabled'] !== true && ' Your edition does not include offline activation.'}</p>
        <label className="mt-2 inline-flex cursor-pointer items-center gap-2 text-[13px] font-semibold">
          <input type="file" accept=".wsr,application/json" disabled={l.entitlements?.['offline.license.enabled'] !== true} onChange={(e) => { const f = e.target.files?.[0]; if (f) offline.mutate(f); e.target.value = ''; }} />
        </label>
      </Card>

      <Card className="p-4">
        <h3 className="m-0 text-[14px] font-semibold">Compare editions</h3>
        <div className="mt-2 max-w-xs">
          <Select label="Another edition" value={preview} onChange={(e) => setPreview(e.target.value)} options={[{ value: '', label: 'Choose…' }, ...['FREE', 'BUSINESS', 'ENTERPRISE'].filter((e) => e !== l.edition).map((e) => ({ value: e, label: EDITION[e] ?? e }))]} />
        </div>
        {pv.data && (
          <div className="mt-3 grid gap-3 sm:grid-cols-2 text-[13px]">
            <div><strong>Adds</strong><ul className="mt-1 list-disc pl-5">{pv.data.adds.map((a) => <li key={a.key}>{a.label}</li>)}{pv.data.adds.length === 0 && <li>Nothing</li>}</ul></div>
            <div><strong>Takes away</strong><ul className="mt-1 list-disc pl-5">{pv.data.removes.map((a) => <li key={a.key}>{a.label}</li>)}{pv.data.removes.length === 0 && <li>Nothing</li>}</ul></div>
            <p className="m-0 text-subtle sm:col-span-2">To change edition, contact <a className="link" href="mailto:sales@worksuite.lk?subject=Change%20WorkSuite%20edition">WorkSuite sales</a>; the change reaches your servers at their next licence check.</p>
          </div>
        )}
      </Card>

      <Card className="p-4">
        <h3 className="m-0 text-[14px] font-semibold">History</h3>
        <ul className="mt-2 list-none p-0 text-[13px]">
          {(l.history ?? []).map((h, i) => <li key={i} className="flex justify-between border-b border-line py-1.5"><span>{h.event}</span><span className="text-subtle">{formatDateTime(h.at)}</span></li>)}
        </ul>
      </Card>
    </div>
  );
}
