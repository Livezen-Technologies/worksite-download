/** Administration: Users, Roles, API Clients, Security (signing keys), Activity (audit), Settings, account security. */
import { useState } from 'react';
import { useSearchParams } from 'react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Copy, Download, Plus, ShieldCheck } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { ApiError, errorMessage } from '@/services/api';
import type { AdminUser, ApiClient, SigningKey } from '@/types/api';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { can } from '@/core/permissions';
import { formatDate, formatDateTime, timeAgo, titleCase } from '@/core/format';
import { Alert, Badge, Button, Card, CardHeader, Checkbox, ErrorState, FilterSelect, KeyValue, PageHeader, Spinner, Table, Td, TextArea, TextField, Th } from '@/components/ui';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { AuditTimeline, describeAudit } from '@/components/admin/AuditTimeline';
import { ReasonDialog } from '@/modules/releases/dialogs';
import { SecuritySettings } from '@/pages/auth/AuthPages';

function useInvalidate() {
  const qc = useQueryClient();
  return (key: readonly unknown[]) => void qc.invalidateQueries({ queryKey: key });
}

// ---------------------------------------------------------------- Users

function UserDialog({ user, roles, onClose }: { user: AdminUser | null; roles: { slug: string; name: string }[]; onClose: () => void }) {
  const toast = useToast();
  const inv = useInvalidate();
  const [email, setEmail] = useState(user?.email ?? '');
  const [name, setName] = useState(user?.name ?? '');
  const [password, setPassword] = useState('');
  const [sel, setSel] = useState<string[]>(user?.roles ?? ['viewer']);
  const m = useMutation({
    mutationFn: () => (user ? adminApi.updateUser(user.id, { name, roles: sel }) : adminApi.createUser({ email, name, password, roles: sel })),
    onSuccess: () => (toast.success(user ? 'User updated' : 'User created — they must set up MFA at first sign-in'), inv(adminKeys.users), onClose()),
  });
  const errs = m.error instanceof ApiError ? m.error.fieldErrors : {};
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title={user ? `Edit ${user.name}` : 'Create staff user'}
      footer={
        <>
          <Button onClick={onClose}>Cancel</Button>
          <Button variant="primary" loading={m.isPending} disabled={!name || (!user && (!email || password.length < 12))} onClick={() => m.mutate()}>
            {user ? 'Save' : 'Create user'}
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        {m.error && !Object.keys(errs).length && <Alert tone="bad">{errorMessage(m.error)}</Alert>}
        {!user && <TextField label="Email" type="email" required value={email} onChange={(e) => setEmail(e.target.value)} error={errs.email} />}
        <TextField label="Name" required value={name} onChange={(e) => setName(e.target.value)} />
        {!user && <TextField label="Initial password" type="password" autoComplete="new-password" required value={password} onChange={(e) => setPassword(e.target.value)} hint="At least 12 characters. The user changes it and enrols MFA at first sign-in." error={errs.password} />}
        <fieldset>
          <legend className="mb-1 text-[12px] font-semibold">Roles</legend>
          <div className="grid grid-cols-2 gap-1.5">
            {roles.map((r) => (
              <Checkbox key={r.slug} label={r.name} checked={sel.includes(r.slug)} onChange={(e) => setSel(e.target.checked ? [...sel, r.slug] : sel.filter((x) => x !== r.slug))} />
            ))}
          </div>
        </fieldset>
      </div>
    </Modal>
  );
}

export function UsersPage() {
  useDocumentTitle('Users · Update Center');
  const toast = useToast();
  const inv = useInvalidate();
  const q = useQuery({ queryKey: adminKeys.users, queryFn: adminApi.users });
  const roles = useQuery({ queryKey: adminKeys.roles, queryFn: adminApi.roles });
  const [edit, setEdit] = useState<AdminUser | null | 'new'>(null);
  const [resetting, setResetting] = useState<AdminUser | null>(null);
  const status = useMutation({ mutationFn: (u: AdminUser) => adminApi.updateUser(u.id, { status: u.status === 'ACTIVE' ? 'DISABLED' : 'ACTIVE' }), onSuccess: () => (toast.success('User updated'), inv(adminKeys.users)), onError: (e) => toast.error(errorMessage(e)) });
  const reset = useMutation({ mutationFn: (v: { id: string; reason: string }) => adminApi.resetUserMfa(v.id, v.reason), onSuccess: () => (toast.success('MFA reset — the user must enrol again'), inv(adminKeys.users), setResetting(null)), onError: (e) => (e as { code?: string }).code !== 'MFA_CANCELLED' && toast.error(errorMessage(e)) });
  const roleName = (slug: string) => roles.data?.roles.find((r) => r.slug === slug)?.name ?? titleCase(slug);
  return (
    <>
      <PageHeader title="Users" sub="Staff and portal accounts." actions={<Button variant="primary" size="sm" icon={<Plus className="size-3.5" aria-hidden />} onClick={() => setEdit('new')}>Create user</Button>} />
      <Card>
        {!q.data ? (
          q.isLoading ? <Spinner /> : <ErrorState error={q.error} retry={q.refetch} />
        ) : (
          <Table label="Users">
            <thead>
              <tr>
                <Th>User</Th>
                <Th>Kind</Th>
                <Th>Roles</Th>
                <Th>MFA</Th>
                <Th>Status</Th>
                <Th>Last login</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((u) => (
                <tr key={u.id}>
                  <Td>
                    <div className="font-semibold">{u.name}</div>
                    <div className="text-[11.5px] text-subtle">{u.email}</div>
                  </Td>
                  <Td>{titleCase(u.kind)}</Td>
                  <Td className="text-[12px]">{u.roles.map(roleName).join(', ')}</Td>
                  <Td>{u.mfa_enabled ? <Badge tone="green">On</Badge> : <Badge tone="amber">Not set</Badge>}</Td>
                  <Td>
                    <Badge tone={u.status === 'ACTIVE' ? 'green' : 'grey'}>{u.status}</Badge>
                  </Td>
                  <Td className="text-[12px] text-subtle">{timeAgo(u.last_login_at)}</Td>
                  <Td className="text-right whitespace-nowrap">
                    <Button size="sm" variant="ghost" onClick={() => setEdit(u)}>
                      Edit
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => status.mutate(u)}>
                      {u.status === 'ACTIVE' ? 'Disable' : 'Enable'}
                    </Button>
                    {u.mfa_enabled && (
                      <Button size="sm" variant="ghost" onClick={() => setResetting(u)}>
                        Reset MFA
                      </Button>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      {edit && <UserDialog user={edit === 'new' ? null : edit} roles={roles.data?.roles ?? []} onClose={() => setEdit(null)} />}
      <ReasonDialog open={!!resetting} onClose={() => setResetting(null)} title={`Reset MFA for ${resetting?.name}?`} description="Removes TOTP and passkeys. The user must enrol again at next sign-in. Requires your MFA; audited." confirmLabel="Reset MFA" tone="danger" busy={reset.isPending} onConfirm={(reason) => resetting && reset.mutate({ id: resetting.id, reason })} />
    </>
  );
}

// ---------------------------------------------------------------- Roles matrix

export function RolesPage() {
  useDocumentTitle('Roles · Update Center');
  const q = useQuery({ queryKey: adminKeys.roles, queryFn: adminApi.roles });
  return (
    <>
      <PageHeader title="Roles and permissions" sub="Read-only matrix (§7.5). Separation of duties on production Stable: the uploader cannot give QA approval or publish." />
      <Card>
        {!q.data ? (
          q.isLoading ? <Spinner /> : <ErrorState error={q.error} retry={q.refetch} />
        ) : (
          <Table label="Role permission matrix">
            <thead>
              <tr>
                <Th>Permission</Th>
                {q.data.roles.map((r) => (
                  <Th key={r.slug} className="text-center">
                    {r.name}
                  </Th>
                ))}
              </tr>
            </thead>
            <tbody>
              {q.data.permissions.map((p) => (
                <tr key={p.slug}>
                  <Td>
                    <div className="font-mono text-[11.5px]">{p.slug}</div>
                    <div className="text-[11px] text-subtle">{p.name}</div>
                  </Td>
                  {q.data!.roles.map((r) => (
                    <Td key={r.slug} className="text-center">
                      {p.roles.includes(r.slug) ? (
                        <span className="font-bold text-ok" aria-label="granted">
                          ✓
                        </span>
                      ) : (
                        <span className="text-line" aria-label="not granted">
                          ·
                        </span>
                      )}
                    </Td>
                  ))}
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}

// ---------------------------------------------------------------- API clients

function SecretOnce({ secret, onClose, title }: { secret: string; onClose: () => void; title: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <Modal open onClose={onClose} size="sm" title={title} footer={<><span /><Button variant="primary" onClick={onClose}>I stored it safely</Button></>}>
      <Alert tone="warn">This secret is shown once. Store it in your CI secret store now — it cannot be retrieved later.</Alert>
      <div className="mt-3 flex items-center gap-2">
        <code className="flex-1 rounded-lg bg-navy p-3 font-mono text-[12px] break-all text-white">{secret}</code>
        <Button size="sm" icon={<Copy className="size-3.5" aria-hidden />} onClick={() => void navigator.clipboard?.writeText(secret).then(() => setCopied(true))}>
          {copied ? 'Copied' : 'Copy'}
        </Button>
      </div>
    </Modal>
  );
}

const CI_PERMS = ['release.view', 'release.create', 'release.upload', 'release.file.upload', 'changelog.manage', 'bugtrace.symbols.upload'];

export function ApiClientsPage() {
  useDocumentTitle('API Clients · Update Center');
  const toast = useToast();
  const inv = useInvalidate();
  const q = useQuery({ queryKey: adminKeys.apiClients, queryFn: adminApi.apiClients });
  const [creating, setCreating] = useState(false);
  const [secret, setSecret] = useState<{ title: string; value: string } | null>(null);
  const [name, setName] = useState('');
  const [perms, setPerms] = useState<string[]>(['release.view', 'release.create', 'release.upload', 'release.file.upload', 'changelog.manage']);
  const [ips, setIps] = useState('');
  const [expires, setExpires] = useState('');
  const create = useMutation({
    mutationFn: () => adminApi.createApiClient({ name, kind: 'CI', permissions: perms, ip_allowlist: ips.split(/[\s,]+/).filter(Boolean), expires_at: expires ? new Date(expires).toISOString() : null }),
    onSuccess: (r) => (setCreating(false), setSecret({ title: `Secret for ${r.client.name}`, value: r.secret }), inv(adminKeys.apiClients)),
  });
  const rotate = useMutation({ mutationFn: (c: ApiClient) => adminApi.rotateApiClient(c.id).then((r) => ({ c, r })), onSuccess: ({ c, r }) => (setSecret({ title: `New secret for ${c.name}`, value: r.secret }), inv(adminKeys.apiClients)), onError: (e) => toast.error(errorMessage(e)) });
  const revoke = useMutation({ mutationFn: (c: ApiClient) => adminApi.revokeApiClient(c.id), onSuccess: () => (toast.success('Client revoked'), inv(adminKeys.apiClients)), onError: (e) => toast.error(errorMessage(e)) });
  return (
    <>
      <PageHeader title="API Clients" sub="Scoped CI/CD tokens. CI tokens never hold release.publish." actions={<Button variant="primary" size="sm" icon={<Plus className="size-3.5" aria-hidden />} onClick={() => setCreating(true)}>Create client</Button>} />
      <Card>
        {!q.data ? (
          q.isLoading ? <Spinner /> : <ErrorState error={q.error} retry={q.refetch} />
        ) : (
          <Table label="API clients">
            <thead>
              <tr>
                <Th>Client</Th>
                <Th>Permissions</Th>
                <Th>IP allow-list</Th>
                <Th>Expires</Th>
                <Th>Last used</Th>
                <Th>Status</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((c) => (
                <tr key={c.id} className={c.revoked_at ? 'opacity-60' : ''}>
                  <Td>
                    <div className="font-semibold">{c.name}</div>
                    <div className="font-mono text-[11px] text-subtle">{c.client_id}</div>
                  </Td>
                  <Td className="max-w-64 font-mono text-[11px]">{c.permissions.join(', ')}</Td>
                  <Td className="font-mono text-[11px]">{c.ip_allowlist.length ? c.ip_allowlist.join(', ') : 'any'}</Td>
                  <Td className="text-[12px]">{formatDate(c.expires_at)}</Td>
                  <Td className="text-[12px] text-subtle">{timeAgo(c.last_used_at)}</Td>
                  <Td>{c.revoked_at ? <Badge tone="red">Revoked</Badge> : <Badge tone="green">Active</Badge>}</Td>
                  <Td className="text-right whitespace-nowrap">
                    {!c.revoked_at && (
                      <>
                        <Button size="sm" variant="ghost" onClick={() => rotate.mutate(c)}>
                          Rotate
                        </Button>
                        <Button size="sm" variant="ghost" className="text-bad" onClick={() => window.confirm(`Revoke ${c.name}? CI jobs using it will fail immediately.`) && revoke.mutate(c)}>
                          Revoke
                        </Button>
                      </>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      <Modal
        open={creating}
        onClose={() => setCreating(false)}
        title="Create API client"
        size="md"
        footer={
          <>
            <Button onClick={() => setCreating(false)}>Cancel</Button>
            <Button variant="primary" loading={create.isPending} disabled={!name.trim() || !perms.length} onClick={() => create.mutate()}>
              Create and show secret
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          {create.error && <Alert tone="bad">{errorMessage(create.error)}</Alert>}
          <TextField label="Name" value={name} onChange={(e) => setName(e.target.value)} placeholder="GitHub Actions — desktop" />
          <fieldset>
            <legend className="mb-1 text-[12px] font-semibold">Permissions</legend>
            <div className="grid grid-cols-2 gap-1.5">
              {CI_PERMS.map((p) => (
                <Checkbox key={p} label={<span className="font-mono text-[12px]">{p}</span>} checked={perms.includes(p)} onChange={(e) => setPerms(e.target.checked ? [...perms, p] : perms.filter((x) => x !== p))} />
              ))}
            </div>
          </fieldset>
          <TextArea label="IP allow-list (CIDR, optional)" mono rows={2} value={ips} onChange={(e) => setIps(e.target.value)} placeholder="140.82.112.0/20" />
          <TextField label="Expires" type="date" value={expires} onChange={(e) => setExpires(e.target.value)} />
        </div>
      </Modal>
      {secret && <SecretOnce title={secret.title} secret={secret.value} onClose={() => setSecret(null)} />}
    </>
  );
}

// ---------------------------------------------------------------- Security (signing keys)

export function SecurityPage() {
  useDocumentTitle('Security · Update Center');
  const { user } = useAuth();
  const toast = useToast();
  const inv = useInvalidate();
  const q = useQuery({ queryKey: adminKeys.signingKeys, queryFn: adminApi.signingKeys });
  const [rotating, setRotating] = useState<SigningKey | null>(null);
  const [newKey, setNewKey] = useState('');
  const [approver, setApprover] = useState('');
  const [reason, setReason] = useState('');
  const [ceremony, setCeremony] = useState([false, false, false]);
  const rotate = useMutation({
    mutationFn: () => adminApi.rotateKey({ purpose: rotating!.purpose, new_key_id: newKey, approver_code: approver, reason }),
    onSuccess: () => (toast.success('Key rotation started — new manifests will be signed with the new key'), inv(adminKeys.signingKeys), setRotating(null)),
    onError: (e) => (e as { code?: string }).code !== 'MFA_CANCELLED' && toast.error(errorMessage(e)),
  });
  return (
    <>
      <PageHeader title="Security" sub="Signing keys live in KMS/HSM behind the signing service — never in code, database or this UI. Only public keys are shown." />
      <Card>
        <CardHeader title="Signing keys" />
        {!q.data ? (
          q.isLoading ? <Spinner /> : <ErrorState error={q.error} retry={q.refetch} />
        ) : (
          <Table label="Signing keys">
            <thead>
              <tr>
                <Th>Key id</Th>
                <Th>Purpose</Th>
                <Th>Algorithm</Th>
                <Th>Public key</Th>
                <Th>Status</Th>
                <Th>Created</Th>
                <Th>Retire after</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((k) => (
                <tr key={k.key_id}>
                  <Td className="font-mono font-semibold">{k.key_id}</Td>
                  <Td>{k.purpose}</Td>
                  <Td>{k.algorithm}</Td>
                  <Td className="max-w-56 truncate font-mono text-[11px]" title={k.public_key}>
                    {k.public_key}
                  </Td>
                  <Td>
                    <Badge tone={k.status === 'ACTIVE' ? 'green' : k.status === 'RETIRING' ? 'amber' : 'grey'}>{k.status}</Badge>
                  </Td>
                  <Td className="text-[12px]">{formatDate(k.created_at)}</Td>
                  <Td className="text-[12px]">{formatDate(k.retire_after)}</Td>
                  <Td>
                    {can(user, 'signing_key.rotate') && k.status === 'ACTIVE' && (
                      <Button size="sm" onClick={() => (setRotating(k), setNewKey(`${k.key_id.replace(/\d{4}[a-z]$/, '')}2027a`), setCeremony([false, false, false]))}>
                        Rotate…
                      </Button>
                    )}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      <Card className="mt-4 p-4 text-[12.5px] text-muted">
        <ShieldCheck className="mr-1 inline size-4 text-ok" aria-hidden /> Staff sign-in requires MFA (TOTP or passkey). Critical actions (publish, withdraw, pause/resume, manifest regeneration, minimum/mandatory changes, file replacement, key rotation, privacy deletion) require step-up MFA within 10 minutes.
      </Card>
      <Modal
        open={!!rotating}
        onClose={() => setRotating(null)}
        title={`Rotate ${rotating?.purpose} key`}
        subtitle="Key ceremony: MFA + second approver. Clients must already pin the new public key."
        footer={
          <>
            <Button onClick={() => setRotating(null)}>Cancel</Button>
            <Button variant="danger" loading={rotate.isPending} disabled={!newKey || approver.length < 6 || !reason.trim() || ceremony.some((c) => !c)} onClick={() => rotate.mutate()}>
              Rotate key
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <fieldset className="space-y-1.5">
            <legend className="mb-1 text-[12px] font-semibold">Ceremony checklist</legend>
            {['New public key shipped in the pinned key set of current clients', 'Old key stays valid for verification until retire date', 'Second approver present'].map((l, i) => (
              <Checkbox key={l} label={l} checked={ceremony[i]} onChange={(e) => setCeremony((c) => c.map((v, j) => (j === i ? e.target.checked : v)))} />
            ))}
          </fieldset>
          <TextField label="New key id" mono value={newKey} onChange={(e) => setNewKey(e.target.value)} />
          <TextField label="Second approver’s authenticator code" inputMode="numeric" value={approver} onChange={(e) => setApprover(e.target.value.replace(/\D/g, '').slice(0, 6))} />
          <TextArea label="Reason (required)" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} />
        </div>
      </Modal>
    </>
  );
}

// ---------------------------------------------------------------- Activity (audit)

export function ActivityPage() {
  useDocumentTitle('Activity · Update Center');
  const toast = useToast();
  const [sp, setSp] = useSearchParams();
  const f = { action: sp.get('action') || undefined, entity_type: sp.get('entity_type') || undefined, actor: sp.get('actor') || undefined, from: sp.get('from') || undefined, to: sp.get('to') || undefined, limit: 100 };
  const q = useQuery({ queryKey: adminKeys.audit(f), queryFn: () => adminApi.audit(f) });
  const verify = useMutation({ mutationFn: adminApi.verifyAudit, onSuccess: (r) => (r.ok ? toast.success(`Hash chain intact · ${r.checked} entries verified`) : toast.error(`Hash chain broken at entry #${r.broken_at}`)), onError: (e) => toast.error(errorMessage(e)) });
  const [view, setView] = useState<'table' | 'timeline'>('table');
  const set = (k: string, v: string) => {
    const n = new URLSearchParams(sp);
    if (v) n.set(k, v);
    else n.delete(k);
    setSp(n, { replace: true });
  };
  return (
    <>
      <PageHeader
        title="Activity"
        sub="Append-only audit log with hash chaining."
        actions={
          <>
            <Button size="sm" loading={verify.isPending} onClick={() => verify.mutate()} icon={<ShieldCheck className="size-3.5" aria-hidden />}>
              Verify chain
            </Button>
            <a className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-line bg-white px-3 text-[12.5px] font-semibold hover:bg-ground" href={adminApi.auditCsvUrl(f)} download>
              <Download className="size-3.5" aria-hidden /> CSV
            </a>
          </>
        }
      />
      <form className="mb-3 flex flex-wrap items-end gap-2" onSubmit={(e) => e.preventDefault()} role="search">
        <TextField label="Action" value={sp.get('action') ?? ''} onChange={(e) => set('action', e.target.value)} placeholder="release.publish" className="w-44" />
        <TextField label="Actor" value={sp.get('actor') ?? ''} onChange={(e) => set('actor', e.target.value)} className="w-40" />
        <div className="flex flex-col gap-1">
          <span className="text-[12px] font-semibold">Entity</span>
          <FilterSelect label="Entity" value={sp.get('entity_type') ?? ''} onChange={(v) => set('entity_type', v)} options={[{ value: '', label: 'All' }, ...['release', 'application', 'user', 'api_client', 'channel', 'settings', 'signing_key'].map((x) => ({ value: x, label: x }))]} />
        </div>
        <TextField label="From" type="date" value={sp.get('from') ?? ''} onChange={(e) => set('from', e.target.value)} className="w-40" />
        <TextField label="To" type="date" value={sp.get('to') ?? ''} onChange={(e) => set('to', e.target.value)} className="w-40" />
        <div className="ml-auto flex gap-1">
          <Button size="sm" variant={view === 'table' ? 'primary' : 'secondary'} onClick={() => setView('table')} aria-pressed={view === 'table'}>
            Table
          </Button>
          <Button size="sm" variant={view === 'timeline' ? 'primary' : 'secondary'} onClick={() => setView('timeline')} aria-pressed={view === 'timeline'}>
            Timeline
          </Button>
        </div>
      </form>
      <Card>
        {!q.data ? (
          q.isLoading ? <Spinner /> : <ErrorState error={q.error} retry={q.refetch} />
        ) : view === 'timeline' ? (
          <div className="p-5">
            <AuditTimeline entries={q.data.data} />
          </div>
        ) : (
          <Table label="Audit log">
            <thead>
              <tr>
                <Th>#</Th>
                <Th>When</Th>
                <Th>Actor</Th>
                <Th>Action</Th>
                <Th>Entity</Th>
                <Th>Change / reason</Th>
                <Th>IP</Th>
                <Th>Hash</Th>
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((a) => (
                <tr key={a.id}>
                  <Td className="font-mono text-[11px]">{a.id}</Td>
                  <Td className="text-[12px] whitespace-nowrap">{formatDateTime(a.created_at)}</Td>
                  <Td>{a.actor_label ?? a.actor_type}</Td>
                  <Td className="font-mono text-[11.5px] text-indigo">{a.action}</Td>
                  <Td className="font-mono text-[11px]">{a.entity_type ? `${a.entity_type}:${a.entity_id ?? ''}` : '—'}</Td>
                  <Td className="max-w-72 text-[12px]">
                    {describeAudit(a) && <div className="font-mono text-[11px]">{describeAudit(a)}</div>}
                    {a.reason && <div className="text-muted">“{a.reason}”</div>}
                  </Td>
                  <Td className="font-mono text-[11px]">{a.ip ?? '—'}</Td>
                  <Td className="font-mono text-[11px] text-subtle">{a.hash.slice(0, 10)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}

// ---------------------------------------------------------------- Settings

export function SettingsPage() {
  useDocumentTitle('Settings · Update Center');
  const toast = useToast();
  const inv = useInvalidate();
  const q = useQuery({ queryKey: adminKeys.settings, queryFn: adminApi.settings });
  const [draft, setDraft] = useState<Record<string, unknown> | null>(null);
  const s = draft ?? q.data ?? null;
  const save = useMutation({ mutationFn: () => adminApi.saveSettings(s!), onSuccess: () => (toast.success('Settings saved'), inv(adminKeys.settings), setDraft(null)), onError: (e) => toast.error(errorMessage(e)) });
  if (!s) return q.isLoading ? <Spinner /> : <ErrorState error={q.error} retry={q.refetch} />;
  return (
    <>
      <PageHeader title="Settings" sub="Operational settings. Secrets (webhooks, provider credentials) are write-only and shown as set / not set." />
      <Card className="max-w-3xl p-5">
        <form
          className="grid gap-3 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault();
            save.mutate();
          }}
        >
          {Object.entries(s).map(([k, v]) =>
            typeof v === 'boolean' ? (
              k.endsWith('_set') ? (
                <KeyValue key={k} rows={[[titleCase(k.replace(/_set$/, '')), v ? <Badge tone="green">Set</Badge> : <Badge>Not set</Badge>]]} />
              ) : (
                <Checkbox key={k} label={titleCase(k)} checked={v} onChange={(e) => setDraft({ ...s, [k]: e.target.checked })} />
              )
            ) : (
              <TextField
                key={k}
                label={titleCase(k)}
                mono={typeof v !== 'string'}
                value={Array.isArray(v) ? v.join(', ') : String(v ?? '')}
                onChange={(e) => setDraft({ ...s, [k]: typeof v === 'number' ? Number(e.target.value) : Array.isArray(v) ? e.target.value.split(',').map((x) => (isNaN(Number(x)) ? x.trim() : Number(x))) : e.target.value })}
              />
            ),
          )}
          <div className="sm:col-span-2">
            <Button type="submit" variant="primary" loading={save.isPending} disabled={!draft}>
              Save settings
            </Button>
          </div>
        </form>
      </Card>
    </>
  );
}

export function AccountSecurity() {
  useDocumentTitle('Account security · Update Center');
  const [sp] = useSearchParams();
  return (
    <>
      <PageHeader title="Account security" sub="Two-step verification and passkeys for your staff account." />
      <SecuritySettings required={sp.get('required') === '1'} />
    </>
  );
}
