import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { ApiError, errorMessage } from '@/services/api';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { useAuth } from '@/hooks/useAuth';
import { can } from '@/core/permissions';
import type { EntitlementDef, EntitlementMatrix, EntitlementValue } from '@/types/api';
import { Alert, Button, Card, PageHeader, Select, Table, Td, TextArea, TextField, Th } from '@/components/ui';
import { Modal } from '@/components/ui/Modal';
import { useToast } from '@/components/ui/Toast';
import { QueryState, editionLabel } from './serverUi';

const EDITIONS = ['FREE', 'BUSINESS', 'ENTERPRISE'] as const;
type Edition = (typeof EDITIONS)[number];
const CATEGORY_LABEL: Record<string, string> = { apps: 'Apps', limits: 'Limits', updates: 'Updates', services: 'Cloud services', support: 'Support' };

/** Show a value the way staff think about it. */
export function entitlementText(def: Pick<EntitlementDef, 'kind' | 'key'>, v: EntitlementValue | undefined): string {
  if (def.kind === 'bool') return v ? 'Yes' : '—';
  if (def.kind === 'limit') {
    if (v === null || v === undefined) return 'Unlimited';
    if (def.key.endsWith('bytes') && typeof v === 'number') return `${(v / 1024 ** 3).toLocaleString()} GB`;
    return String(v);
  }
  return v === null || v === undefined ? '—' : String(v);
}

/**
 * The entitlement catalog (IMPL-LIC-01 §5): every key a WorkSuite Server licence can grant and what each
 * edition gives. Editions are entitlement packages; servers grant from these keys only.
 */
export default function Entitlements() {
  useDocumentTitle('Entitlements · Update Center');
  const { user } = useAuth();
  const manage = can(user, 'license.manage');
  const q = useQuery({ queryKey: adminKeys.entitlements, queryFn: adminApi.entitlements });
  const [defining, setDefining] = useState(false);
  return (
    <>
      <PageHeader
        breadcrumb={<>Server › Licences</>}
        title="Entitlements"
        sub="What each edition grants. A licence gets its edition's values plus its own add-ons, signed into what the server enforces."
        actions={manage && <Button variant="primary" icon={<Plus className="size-4" aria-hidden />} onClick={() => setDefining(true)}>New entitlement</Button>}
      />
      <Alert tone="info" className="mb-4" title="Placeholder values">
        The seeded values follow the licensing plan's table and await commercial sign-off. A change applies to every licence of that edition at its server's next validation.
      </Alert>
      {!q.data ? <QueryState q={q} /> : <Matrix m={q.data} manage={manage} />}
      {defining && <DefineDialog onClose={() => setDefining(false)} />}
    </>
  );
}

function Matrix({ m, manage }: { m: EntitlementMatrix; manage: boolean }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [draft, setDraft] = useState<Record<Edition, Record<string, EntitlementValue>>>(() => structuredClone(m.editions) as Record<Edition, Record<string, EntitlementValue>>);
  useEffect(() => setDraft(structuredClone(m.editions) as Record<Edition, Record<string, EntitlementValue>>), [m]);
  const changed = useMemo(() => {
    const out: Partial<Record<Edition, Record<string, EntitlementValue>>> = {};
    for (const e of EDITIONS) {
      for (const d of m.entitlements) {
        const a = m.editions[e]?.[d.key];
        const b = draft[e]?.[d.key];
        if (JSON.stringify(a ?? null) !== JSON.stringify(b ?? null)) (out[e] ??= {})[d.key] = b ?? null;
      }
    }
    return out;
  }, [draft, m]);
  const dirty = Object.keys(changed).length > 0;
  const save = useMutation({
    mutationFn: async () => {
      for (const [e, values] of Object.entries(changed)) await adminApi.setEditionEntitlements(e, values ?? {});
    },
    onSuccess: () => {
      toast.success('Entitlements saved');
      void qc.invalidateQueries({ queryKey: adminKeys.entitlements });
    },
    onError: (err) => toast.error(err instanceof ApiError && Object.keys(err.fieldErrors).length ? Object.entries(err.fieldErrors).map(([k, v]) => `${k}: ${v}`).join(' · ') : errorMessage(err)),
  });
  const set = (e: Edition, key: string, v: EntitlementValue) => setDraft((d) => ({ ...d, [e]: { ...d[e], [key]: v } }));
  const groups = Object.keys(CATEGORY_LABEL).map((c) => ({ c, rows: m.entitlements.filter((d) => d.category === c) })).filter((g) => g.rows.length);

  return (
    <Card>
      {manage && (
        <div className="flex items-center justify-end gap-2 border-b border-line px-4 py-2.5">
          {dirty && <span className="text-[12px] text-subtle">Unsaved changes</span>}
          <Button size="sm" disabled={!dirty} onClick={() => setDraft(structuredClone(m.editions) as Record<Edition, Record<string, EntitlementValue>>)}>Discard</Button>
          <Button size="sm" variant="primary" disabled={!dirty} loading={save.isPending} onClick={() => save.mutate()}>Save</Button>
        </div>
      )}
      <Table label="Entitlements by edition">
        <thead>
          <tr>
            <Th>Entitlement</Th>
            {EDITIONS.map((e) => <Th key={e}>{editionLabel(e)}</Th>)}
          </tr>
        </thead>
        <tbody>
          {groups.map((g) => (
            <GroupRows key={g.c} title={CATEGORY_LABEL[g.c]} rows={g.rows} draft={draft} manage={manage} onSet={set} />
          ))}
        </tbody>
      </Table>
    </Card>
  );
}

function GroupRows({ title, rows, draft, manage, onSet }: { title: string; rows: EntitlementDef[]; draft: Record<Edition, Record<string, EntitlementValue>>; manage: boolean; onSet: (e: Edition, key: string, v: EntitlementValue) => void }) {
  return (
    <>
      <tr>
        <Td colSpan={4} className="bg-ground text-[11.5px] font-semibold tracking-wide text-subtle uppercase">{title}</Td>
      </tr>
      {rows.map((d) => (
        <tr key={d.key}>
          <Td>
            <div className="font-semibold">{d.label}</div>
            <div className="font-mono text-[11px] text-subtle">{d.key}</div>
          </Td>
          {EDITIONS.map((e) => (
            <Td key={e}>
              {manage ? <Cell def={d} value={draft[e]?.[d.key]} onChange={(v) => onSet(e, d.key, v)} label={`${d.label}, ${editionLabel(e)}`} /> : entitlementText(d, draft[e]?.[d.key])}
            </Td>
          ))}
        </tr>
      ))}
    </>
  );
}

export function Cell({ def, value, onChange, label }: { def: EntitlementDef; value: EntitlementValue | undefined; onChange: (v: EntitlementValue) => void; label: string }) {
  if (def.kind === 'bool') {
    return <input type="checkbox" aria-label={label} className="size-4 accent-brand" checked={value === true} onChange={(e) => onChange(e.target.checked)} />;
  }
  if (def.kind === 'limit') {
    return (
      <input
        type="number"
        min={0}
        aria-label={label}
        placeholder="Unlimited"
        className="h-8 w-28 rounded-lg border border-line bg-white px-2 text-[12.5px]"
        value={value === null || value === undefined ? '' : String(value)}
        onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
      />
    );
  }
  return <input aria-label={label} className="h-8 w-32 rounded-lg border border-line bg-white px-2 text-[12.5px]" value={value === null || value === undefined ? '' : String(value)} onChange={(e) => onChange(e.target.value || null)} />;
}

function DefineDialog({ onClose }: { onClose: () => void }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [body, setBody] = useState({ key: '', label: '', kind: 'bool', category: 'apps', description: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const m = useMutation({
    mutationFn: () => adminApi.defineEntitlement(body),
    onSuccess: () => {
      toast.success(`${body.key} added — off for every edition until you turn it on`);
      void qc.invalidateQueries({ queryKey: adminKeys.entitlements });
      onClose();
    },
    onError: (err) => (err instanceof ApiError && Object.keys(err.fieldErrors).length ? setErrors(err.fieldErrors) : toast.error(errorMessage(err))),
  });
  return (
    <Modal open onClose={onClose} title="New entitlement" footer={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={m.isPending} onClick={() => m.mutate()}>Add</Button></>}>
      <div className="grid gap-3 sm:grid-cols-2">
        <TextField label="Key" required mono hint="e.g. repairmate.enabled" value={body.key} error={errors.key} onChange={(e) => setBody({ ...body, key: e.target.value })} />
        <TextField label="Label" required value={body.label} error={errors.label} onChange={(e) => setBody({ ...body, label: e.target.value })} />
        <Select label="Kind" value={body.kind} onChange={(e) => setBody({ ...body, kind: e.target.value })} options={[{ value: 'bool', label: 'On / off' }, { value: 'limit', label: 'Limit (number)' }, { value: 'value', label: 'Value' }]} />
        <Select label="Category" value={body.category} onChange={(e) => setBody({ ...body, category: e.target.value })} options={Object.entries(CATEGORY_LABEL).map(([value, label]) => ({ value, label }))} />
        <TextArea label="Description" className="sm:col-span-2" rows={2} value={body.description} onChange={(e) => setBody({ ...body, description: e.target.value })} />
      </div>
    </Modal>
  );
}
