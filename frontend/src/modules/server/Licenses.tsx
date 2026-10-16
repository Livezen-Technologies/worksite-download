import { useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Copy, KeyRound, Plus, Search } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { ApiError, errorMessage } from '@/services/api';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { useAuth } from '@/hooks/useAuth';
import { can } from '@/core/permissions';
import { cx, formatDate, formatNumber, timeAgo } from '@/core/format';
import type { EntitlementValue, License, LicenseBody, LicenseState } from '@/types/api';
import { Cell } from './Entitlements';
import { Alert, Badge, Button, Card, Checkbox, EmptyState, FilterSelect, KeyValue, PageHeader, Select, Table, Td, TextArea, TextField, Th, type Tone } from '@/components/ui';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { QueryState, editionLabel } from './serverUi';

const STATE_TONE: Record<LicenseState, Tone> = { ACTIVE: 'green', EXPIRED: 'amber', SUSPENDED: 'amber', REVOKED: 'red', NOT_STARTED: 'grey', DEACTIVATED: 'grey' };
const STATE_LABEL: Record<LicenseState, string> = { ACTIVE: 'Active', EXPIRED: 'Expired', SUSPENDED: 'Suspended', REVOKED: 'Revoked', NOT_STARTED: 'Not started', DEACTIVATED: 'Deactivated' };

export function LicenseStateBadge({ state }: { state: LicenseState }) {
  return <Badge tone={STATE_TONE[state] ?? 'grey'}>{STATE_LABEL[state] ?? state}</Badge>;
}

const EDITIONS = ['FREE', 'BUSINESS', 'ENTERPRISE'] as const;

/** WorkSuite Server licences: the Download Center generates and manages every key. */
export default function Licenses() {
  useDocumentTitle('Licences · Update Center');
  const { user } = useAuth();
  const manage = can(user, 'license.manage');
  const [sp, setSp] = useSearchParams();
  const status = sp.get('status') ?? '';
  const edition = sp.get('edition') ?? '';
  const qText = sp.get('q') ?? '';
  const [search, setSearch] = useState(qText);
  const [creating, setCreating] = useState(false);
  const [openId, setOpenId] = useState<string | null>(null);
  const filters = { status: status || undefined, edition: edition || undefined, q: qText || undefined, limit: 100 };
  const list = useQuery({ queryKey: adminKeys.licenses(filters), queryFn: () => adminApi.licenses(filters) });
  const set = (k: string, v: string) => {
    const n = new URLSearchParams(sp);
    if (v) n.set(k, v);
    else n.delete(k);
    setSp(n, { replace: true });
  };
  const onSearch = (e: FormEvent) => {
    e.preventDefault();
    set('q', search.trim());
  };
  const rows = list.data?.data ?? [];
  return (
    <>
      <PageHeader
        breadcrumb={
          <>
            Server › <span className="font-mono">lk.worksuite.server</span>
          </>
        }
        title="Licences"
        sub={`${formatNumber(list.data?.total ?? 0)} WorkSuite Server licence keys. Servers activate a key and revalidate it; every answer is signed.`}
        actions={
          manage && (
            <Button variant="primary" icon={<Plus className="size-4" aria-hidden />} onClick={() => setCreating(true)}>
              New licence
            </Button>
          )
        }
      />
      <Card>
        <div className="flex flex-wrap items-center gap-2 px-4 py-3">
          <form role="search" onSubmit={onSearch} className="flex min-w-0 flex-1 items-center gap-2 sm:max-w-xs">
            <label htmlFor="lic-search" className="sr-only">
              Search licences
            </label>
            <input
              id="lic-search"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Organisation, email or last 4"
              className="h-8 w-full min-w-0 rounded-lg border border-line bg-white px-2.5 text-[12.5px] focus:border-brand focus:ring-2 focus:ring-brand/25 focus:outline-none"
            />
            <Button size="sm" type="submit" icon={<Search className="size-3.5" aria-hidden />} aria-label="Search">
              <span className="sr-only sm:not-sr-only">Search</span>
            </Button>
          </form>
          <FilterSelect label="Status" value={status} onChange={(v) => set('status', v)} options={[{ value: '', label: 'All' }, { value: 'ACTIVE', label: 'Active' }, { value: 'SUSPENDED', label: 'Suspended' }, { value: 'REVOKED', label: 'Revoked' }]} />
          <FilterSelect label="Edition" value={edition} onChange={(v) => set('edition', v)} options={[{ value: '', label: 'All' }, ...EDITIONS.map((e) => ({ value: e, label: editionLabel(e) }))]} />
        </div>
        {!list.data ? (
          <QueryState q={list} />
        ) : rows.length === 0 ? (
          <EmptyState title="No licences" icon={<KeyRound className="size-5" aria-hidden />}>
            {manage ? 'Generate the first key with New licence.' : 'No licence matches.'}
          </EmptyState>
        ) : (
          <Table label="Licences">
            <thead>
              <tr>
                <Th>Organisation</Th>
                <Th>Key</Th>
                <Th>Edition</Th>
                <Th>State</Th>
                <Th>Servers</Th>
                <Th>Expires</Th>
                <Th>Updates until</Th>
              </tr>
            </thead>
            <tbody>
              {rows.map((l) => (
                <tr key={l.id}>
                  <Td>
                    <button type="button" className="link text-left font-semibold" onClick={() => setOpenId(l.id)}>
                      {l.organization}
                    </button>
                    {l.contact_email && <div className="text-[11px] text-subtle">{l.contact_email}</div>}
                  </Td>
                  <Td className="font-mono text-[12px]">{l.license_key_masked}</Td>
                  <Td>{editionLabel(l.edition)}</Td>
                  <Td>
                    <LicenseStateBadge state={l.state} />
                  </Td>
                  <Td className="tabular-nums">
                    {l.active_activations} / {l.max_activations}
                  </Td>
                  <Td className={cx('text-[12.5px]', l.state === 'EXPIRED' && 'font-semibold text-warn')}>{formatDate(l.expires_at)}</Td>
                  <Td className="text-[12.5px]">{formatDate(l.updates_until)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      {creating && <NewLicence onClose={() => setCreating(false)} />}
      {openId && <LicenceDetail id={openId} manage={manage} onClose={() => setOpenId(null)} />}
    </>
  );
}

function inYears(n: number): string {
  const d = new Date();
  d.setUTCFullYear(d.getUTCFullYear() + n);
  return d.toISOString().slice(0, 10);
}

function NewLicence({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [body, setBody] = useState<LicenseBody>({ organization: '', contact_email: '', edition: 'BUSINESS', max_activations: 1, expires_at: inYears(1), updates_until: '', server_bound: true, notes: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [created, setCreated] = useState<License | null>(null);
  const m = useMutation({
    mutationFn: () => adminApi.createLicense({ ...body, updates_until: body.updates_until || undefined }),
    onSuccess: (l) => {
      setCreated(l);
      void qc.invalidateQueries({ queryKey: ['admin', 'licenses'] });
    },
    onError: (err) => {
      if (err instanceof ApiError && Object.keys(err.fieldErrors).length) setErrors(err.fieldErrors);
      else toast.error(errorMessage(err));
    },
  });
  const field = <K extends keyof LicenseBody>(k: K, v: LicenseBody[K]) => setBody((b) => ({ ...b, [k]: v }));
  if (created) {
    return (
      <Modal open onClose={onClose} title="Licence created" subtitle={created.organization} footer={<Button variant="primary" onClick={onClose}>Done</Button>}>
        <Alert tone="warn" title="Copy the key now">
          This is the only time the full key is shown. The Download Center keeps only a hash of it.
        </Alert>
        <div className="mt-3 flex items-center gap-2 rounded-lg border border-line bg-ground px-3 py-2.5">
          <code className="min-w-0 flex-1 font-mono text-[14px] font-semibold break-all select-all">{created.license_key}</code>
          <Button
            size="sm"
            icon={<Copy className="size-3.5" aria-hidden />}
            onClick={() => {
              void navigator.clipboard?.writeText(created.license_key ?? '');
              toast.success('Licence key copied');
            }}
          >
            Copy
          </Button>
        </div>
        <KeyValue
          className="mt-3"
          rows={[
            ['Edition', editionLabel(created.edition)],
            ['Servers', String(created.max_activations)],
            ['Expires', formatDate(created.expires_at)],
            ['Updates until', formatDate(created.updates_until)],
          ]}
        />
      </Modal>
    );
  }
  return (
    <Modal
      open
      onClose={onClose}
      title="New licence"
      subtitle="Generates a WorkSuite Server licence key"
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={m.isPending} onClick={() => m.mutate()}>
            Generate key
          </Button>
        </>
      }
    >
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label="Licensed organisation" required className="sm:col-span-2" value={body.organization} error={errors.organization} onChange={(e) => field('organization', e.target.value)} />
        <TextField label="Contact email" type="email" value={body.contact_email ?? ''} error={errors.contact_email} onChange={(e) => field('contact_email', e.target.value)} />
        <Select label="Edition" value={body.edition} error={errors.edition} onChange={(e) => field('edition', e.target.value as LicenseBody['edition'])} options={EDITIONS.map((e) => ({ value: e, label: editionLabel(e) }))} />
        <TextField label="Expires" type="date" required value={body.expires_at} error={errors.expires_at} onChange={(e) => field('expires_at', e.target.value)} />
        <TextField label="Updates until" type="date" hint="Blank: same as the expiry" value={body.updates_until ?? ''} error={errors.updates_until} onChange={(e) => field('updates_until', e.target.value)} />
        <TextField label="Servers allowed" type="number" min={1} max={1000} value={String(body.max_activations)} error={errors.max_activations} onChange={(e) => field('max_activations', Number(e.target.value))} />
        <div className="flex items-end pb-1">
          <Checkbox label="Bind to the activated server" checked={!!body.server_bound} onChange={(e) => field('server_bound', e.target.checked)} />
        </div>
        <TextArea label="Notes" className="sm:col-span-2" rows={2} value={body.notes ?? ''} onChange={(e) => field('notes', e.target.value)} />
      </div>
    </Modal>
  );
}

type Dialog = null | 'renew' | 'suspend' | 'revoke';

function LicenceDetail({ id, manage, onClose }: { id: string; manage: boolean; onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: adminKeys.license(id), queryFn: () => adminApi.license(id) });
  const [dialog, setDialog] = useState<Dialog>(null);
  const [reason, setReason] = useState('');
  const [expires, setExpires] = useState(inYears(1));
  const refresh = () => void qc.invalidateQueries({ queryKey: ['admin', 'licenses'] }).then(() => qc.invalidateQueries({ queryKey: adminKeys.license(id) }));
  const act = useMutation({
    mutationFn: (kind: 'renew' | 'suspend' | 'resume' | 'revoke' | { deactivate: number }) => {
      if (kind === 'renew') return adminApi.renewLicense(id, { expires_at: expires });
      if (typeof kind === 'object') return adminApi.deactivateLicense(id, kind.deactivate, { reason: 'Deactivated in the Download Center' });
      return adminApi.licenseStatus(id, kind, { reason });
    },
    onSuccess: (_l, kind) => {
      toast.success(typeof kind === 'object' ? 'Server deactivated — its slot is free' : kind === 'renew' ? 'Licence renewed' : kind === 'resume' ? 'Licence resumed' : kind === 'suspend' ? 'Licence suspended' : 'Licence revoked');
      setDialog(null);
      setReason('');
      refresh();
    },
    onError: (e) => {
      if ((e as { code?: string }).code !== 'MFA_CANCELLED') toast.error(errorMessage(e));
    },
  });
  const l = q.data;
  return (
    <Modal open onClose={onClose} size="lg" title={l?.organization ?? 'Licence'} subtitle={l?.license_key_masked}>
      {!l ? (
        <QueryState q={q} />
      ) : (
        <>
          <div className="flex flex-wrap items-center gap-2">
            <LicenseStateBadge state={l.state} />
            <Badge tone="indigo">{editionLabel(l.edition)}</Badge>
            {l.server_bound && <Badge tone="grey">Server-bound</Badge>}
          </div>
          {l.status_reason && <p className="mt-2 text-[12.5px] text-subtle">Reason: {l.status_reason}</p>}
          <KeyValue
            className="mt-3"
            rows={[
              ['Licence', <span className="font-mono text-[12px]">{l.id}</span>],
              ['Contact', l.contact_email ?? '—'],
              ['Expires', formatDate(l.expires_at)],
              ['Updates until', formatDate(l.updates_until)],
              ['Servers', `${l.active_activations} of ${l.max_activations} in use`],
              ['Created', `${formatDate(l.created_at)}${l.created_by ? ` by ${l.created_by}` : ''}`],
              ...(l.notes ? ([['Notes', l.notes]] as [string, string][]) : []),
            ]}
          />
          {manage && l.status !== 'REVOKED' && (
            <div className="mt-4 flex flex-wrap gap-2">
              <Button variant="primary" onClick={() => setDialog('renew')}>
                Renew
              </Button>
              {l.status === 'SUSPENDED' ? (
                <Button loading={act.isPending} onClick={() => act.mutate('resume')}>
                  Resume
                </Button>
              ) : (
                <Button onClick={() => setDialog('suspend')}>Suspend</Button>
              )}
              <Button variant="danger" onClick={() => setDialog('revoke')}>
                Revoke
              </Button>
            </div>
          )}
          {dialog && (
            <div className="mt-4 rounded-lg border border-line bg-ground p-3">
              {dialog === 'renew' ? (
                <TextField label="New expiry (also the update eligibility date)" type="date" value={expires} onChange={(e) => setExpires(e.target.value)} />
              ) : (
                <TextArea label={dialog === 'suspend' ? 'Why suspend it? Servers show Renew License and paid modules go read-only.' : 'Why revoke it? This cannot be undone.'} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} required />
              )}
              <div className="mt-2 flex gap-2">
                <Button onClick={() => setDialog(null)}>Cancel</Button>
                <Button variant={dialog === 'revoke' ? 'danger' : 'primary'} loading={act.isPending} disabled={dialog !== 'renew' && !reason.trim()} onClick={() => act.mutate(dialog)}>
                  {dialog === 'renew' ? 'Renew licence' : dialog === 'suspend' ? 'Suspend licence' : 'Revoke licence'}
                </Button>
              </div>
            </div>
          )}
          <AddOns id={l.id} manage={manage && l.status !== 'REVOKED'} />
          <h3 className="mt-5 mb-2 text-[13px] font-semibold">Activated servers</h3>
          {(l.activations ?? []).length === 0 ? (
            <p className="text-[12.5px] text-subtle">Not activated on any server yet.</p>
          ) : (
            <Table label="Activated servers">
              <thead>
                <tr>
                  <Th>Server</Th>
                  <Th>Activated</Th>
                  <Th>Last check</Th>
                  <Th />
                </tr>
              </thead>
              <tbody>
                {(l.activations ?? []).map((a) => (
                  <tr key={a.id} className={cx(!a.active && 'opacity-60')}>
                    <Td>
                      <div className="font-semibold">{a.hostname || a.installation_id}</div>
                      <div className="font-mono text-[11px] text-subtle">{a.installation_id}</div>
                    </Td>
                    <Td className="text-[12px]">{formatDate(a.activated_at)}</Td>
                    <Td className="text-[12px]">{a.active ? timeAgo(a.last_validated_at) : `Deactivated ${formatDate(a.deactivated_at)}`}</Td>
                    <Td>
                      {manage && a.active && (
                        <Button size="sm" loading={act.isPending} onClick={() => act.mutate({ deactivate: a.id })}>
                          Deactivate
                        </Button>
                      )}
                    </Td>
                  </tr>
                ))}
              </tbody>
            </Table>
          )}
        </>
      )}
    </Modal>
  );
}

/** Per-licence add-ons and overrides on top of the edition's entitlements. */
function AddOns({ id, manage }: { id: string; manage: boolean }) {
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: adminKeys.licenseEntitlements(id), queryFn: () => adminApi.licenseEntitlements(id) });
  const cat = useQuery({ queryKey: adminKeys.entitlements, queryFn: adminApi.entitlements, enabled: manage });
  const [key, setKey] = useState('');
  const [value, setValue] = useState<EntitlementValue>(true);
  const m = useMutation({
    mutationFn: (body: { values?: Record<string, EntitlementValue>; remove?: string[] }) => adminApi.setLicenseEntitlements(id, body),
    onSuccess: (r) => {
      qc.setQueryData(adminKeys.licenseEntitlements(id), r);
      setKey('');
      toast.success('Add-ons saved — the server picks them up at its next validation');
    },
    onError: (e) => toast.error(errorMessage(e)),
  });
  const defs = cat.data?.entitlements ?? [];
  const def = defs.find((d) => d.key === key);
  const addons = Object.entries(q.data?.addons ?? {});
  return (
    <>
      <h3 className="mt-5 mb-2 text-[13px] font-semibold">Add-ons</h3>
      {addons.length === 0 ? (
        <p className="text-[12.5px] text-subtle">None — this licence gets exactly its edition's entitlements.</p>
      ) : (
        <ul className="m-0 flex list-none flex-col gap-1 p-0">
          {addons.map(([k, v]) => (
            <li key={k} className="flex items-center justify-between gap-2 rounded-lg border border-line px-3 py-1.5 text-[12.5px]">
              <span><span className="font-mono">{k}</span> = <strong>{v === null ? 'unlimited' : String(v)}</strong></span>
              {manage && <Button size="sm" loading={m.isPending} onClick={() => m.mutate({ remove: [k] })}>Remove</Button>}
            </li>
          ))}
        </ul>
      )}
      {manage && (
        <div className="mt-2 flex flex-wrap items-end gap-2">
          <Select label="Entitlement" value={key} onChange={(e) => { setKey(e.target.value); const d = defs.find((x) => x.key === e.target.value); setValue(d?.kind === 'bool' ? true : null); }} options={[{ value: '', label: 'Choose…' }, ...defs.map((d) => ({ value: d.key, label: `${d.label} (${d.key})` }))]} />
          {def && <Cell def={def} value={value} onChange={setValue} label={`Value for ${def.label}`} />}
          <Button size="sm" variant="primary" disabled={!def} loading={m.isPending} onClick={() => m.mutate({ values: { [key]: value } })}>Add</Button>
        </div>
      )}
    </>
  );
}
