import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ShieldAlert } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { errorMessage } from '@/services/api';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { useAuth } from '@/hooks/useAuth';
import { can } from '@/core/permissions';
import { formatDate, timeAgo } from '@/core/format';
import type { LicenseRiskFlag } from '@/types/api';
import { Badge, Button, Card, EmptyState, FilterSelect, PageHeader, Table, Td, TextField, Th, type Tone } from '@/components/ui';
import { useToast } from '@/components/ui/Toast';
import { QueryState } from './serverUi';

const SEVERITY: Record<string, Tone> = { LOW: 'grey', MEDIUM: 'amber', HIGH: 'red', CRITICAL: 'red' };

/** The licence risk engine (IMPL-LIC-01 §4.5): what servers report and do that needs a person. HIGH holds updates. */
export default function LicenseRisk() {
  useDocumentTitle('Licence risk · Update Center');
  const { user } = useAuth();
  const manage = can(user, 'license.manage');
  const [status, setStatus] = useState('OPEN');
  const [resolving, setResolving] = useState<number | null>(null);
  const [note, setNote] = useState('');
  const toast = useToast();
  const qc = useQueryClient();
  const q = useQuery({ queryKey: adminKeys.licenseRisk(status), queryFn: () => adminApi.licenseRisk({ status }) });
  const act = useMutation({
    mutationFn: ({ id, action }: { id: number; action: 'acknowledge' | 'resolve' | 'reopen' }) => adminApi.licenseRiskStatus(id, action, action === 'resolve' ? { resolution: note } : {}),
    onSuccess: (_r, v) => {
      toast.success(v.action === 'resolve' ? 'Resolved' : v.action === 'acknowledge' ? 'Acknowledged' : 'Reopened');
      setResolving(null);
      setNote('');
      void qc.invalidateQueries({ queryKey: ['admin', 'license-risk'] });
    },
    onError: (e) => toast.error(errorMessage(e)),
  });
  const rows = q.data?.data ?? [];
  return (
    <>
      <PageHeader breadcrumb={<>Server › Licences</>} title="Licence risk" sub="Findings from what installations report and do. An open HIGH finding holds that licence's updates; customers only ever see a generic message." />
      <Card>
        <div className="flex items-center gap-2 px-4 py-3">
          <FilterSelect label="Status" value={status} onChange={setStatus} options={[{ value: 'OPEN', label: 'Open' }, { value: 'ACKNOWLEDGED', label: 'Acknowledged' }, { value: 'RESOLVED', label: 'Resolved' }, { value: 'ALL', label: 'All' }]} />
        </div>
        {!q.data ? (
          <QueryState q={q} />
        ) : rows.length === 0 ? (
          <EmptyState title="Nothing to review" icon={<ShieldAlert className="size-5" aria-hidden />}>No findings with this status.</EmptyState>
        ) : (
          <Table label="Licence risk findings">
            <thead>
              <tr>
                <Th>Finding</Th>
                <Th>Licence</Th>
                <Th>Seen</Th>
                <Th>Status</Th>
                <Th />
              </tr>
            </thead>
            <tbody>
              {rows.map((f: LicenseRiskFlag) => (
                <tr key={f.id}>
                  <Td>
                    <div className="flex items-center gap-2"><Badge tone={SEVERITY[f.severity] ?? 'grey'}>{f.severity}</Badge><span className="font-semibold">{f.rule.replace(/_/g, ' ')}</span></div>
                    <div className="mt-1 text-[12px] text-subtle">{f.label}</div>
                    {f.installation_id && <div className="font-mono text-[11px] text-subtle">{f.installation_id}</div>}
                    {f.resolution && <div className="mt-1 text-[12px]">Resolution: {f.resolution}{f.resolved_by ? ` (${f.resolved_by})` : ''}</div>}
                  </Td>
                  <Td>
                    <div className="font-semibold">{f.organization}</div>
                    <div className="font-mono text-[11px] text-subtle">{f.license_key_masked}</div>
                  </Td>
                  <Td className="text-[12px]">{f.occurrences}× · first {formatDate(f.first_seen_at)} · last {timeAgo(f.last_seen_at)}</Td>
                  <Td>{f.status}</Td>
                  <Td>
                    {manage && f.status !== 'RESOLVED' && resolving !== f.id && (
                      <div className="flex gap-1.5">
                        {f.status === 'OPEN' && <Button size="sm" loading={act.isPending} onClick={() => act.mutate({ id: f.id, action: 'acknowledge' })}>Acknowledge</Button>}
                        <Button size="sm" variant="primary" onClick={() => setResolving(f.id)}>Resolve</Button>
                      </div>
                    )}
                    {manage && resolving === f.id && (
                      <div className="flex items-end gap-1.5">
                        <TextField label="How was it resolved?" value={note} onChange={(e) => setNote(e.target.value)} />
                        <Button size="sm" variant="primary" disabled={!note.trim()} loading={act.isPending} onClick={() => act.mutate({ id: f.id, action: 'resolve' })}>Save</Button>
                      </div>
                    )}
                    {manage && f.status === 'RESOLVED' && <Button size="sm" onClick={() => act.mutate({ id: f.id, action: 'reopen' })}>Reopen</Button>}
                  </Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}
