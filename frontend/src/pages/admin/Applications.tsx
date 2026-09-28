import { Link } from 'react-router';
import { useQuery, useQueryClient, useMutation } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
import { adminApi, adminKeys } from '@/services/admin';
import { errorMessage } from '@/services/api';
import { useAuth } from '@/hooks/useAuth';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import { can } from '@/core/permissions';
import { archLabel, PACKAGE_LABEL, PLATFORM_LABEL, titleCase } from '@/core/format';
import { Badge, ButtonLink, Card, ErrorState, PageHeader, Spinner, Table, Td, Th } from '@/components/ui';
import { useToast } from '@/components/ui/Toast';
import type { AdminChannel } from '@/types/api';

export function ApplicationsList() {
  useDocumentTitle('Applications · Update Center');
  const { user } = useAuth();
  const q = useQuery({ queryKey: adminKeys.apps, queryFn: adminApi.apps });
  return (
    <>
      <PageHeader
        title="Applications"
        sub="Distributable WorkSuite products."
        actions={
          can(user, 'application.manage') && (
            <ButtonLink to="/admin/applications/new" variant="primary" size="sm" icon={<Plus className="size-3.5" aria-hidden />}>
              New Application
            </ButtonLink>
          )
        }
      />
      <Card>
        {q.isLoading ? (
          <Spinner />
        ) : q.error || !q.data ? (
          <div className="p-4">
            <ErrorState error={q.error} retry={q.refetch} />
          </div>
        ) : (
          <Table label="Applications">
            <thead>
              <tr>
                <Th>Application</Th>
                <Th>Identifier</Th>
                <Th>Kind</Th>
                <Th>Platforms</Th>
                <Th>Latest stable</Th>
                <Th>Beta</Th>
                <Th>Releases</Th>
                <Th>Public</Th>
                <Th>Status</Th>
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((a) => (
                <tr key={a.id} className="hover:bg-ground/60">
                  <Td>
                    <Link to={`/admin/applications/${a.id}`} className="font-semibold text-indigo hover:underline">
                      {a.name}
                    </Link>
                  </Td>
                  <Td className="font-mono text-[11.5px]">{a.app_identifier}</Td>
                  <Td>{titleCase(a.kind)}</Td>
                  <Td>{Array.from(new Set(a.platforms.map((p) => PLATFORM_LABEL[p.platform]))).join(', ')}</Td>
                  <Td className="font-mono">{a.latest_stable?.version ?? '—'}</Td>
                  <Td className="font-mono">{a.latest_beta?.version ?? '—'}</Td>
                  <Td>{a.releases_count}</Td>
                  <Td>{a.private ? <Badge tone="purple">Private</Badge> : a.public_download_enabled ? <Badge tone="green">Public</Badge> : <Badge>Off</Badge>}</Td>
                  <Td>
                    <Badge tone={a.status === 'ACTIVE' ? 'green' : a.status === 'HIDDEN' ? 'amber' : 'grey'}>{a.status}</Badge>
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

export function PlatformsPage() {
  useDocumentTitle('Platforms · Update Center');
  const q = useQuery({ queryKey: adminKeys.platforms, queryFn: adminApi.platforms });
  return (
    <>
      <PageHeader title="Platforms" sub="Platform × architecture × package types per application." />
      <Card>
        {q.isLoading ? (
          <Spinner />
        ) : q.error || !q.data ? (
          <div className="p-4">
            <ErrorState error={q.error} retry={q.refetch} />
          </div>
        ) : (
          <Table label="Platforms">
            <thead>
              <tr>
                <Th>Application</Th>
                <Th>Platform</Th>
                <Th>Architecture</Th>
                <Th>Package types</Th>
                <Th>Default</Th>
                <Th>Min OS</Th>
                <Th>Required for publish</Th>
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((p, i) => (
                <tr key={i}>
                  <Td>
                    <Link className="hover:text-brand" to={`/admin/applications/${p.app_id}/platforms`}>
                      {p.app_name}
                    </Link>
                  </Td>
                  <Td className="font-semibold">{PLATFORM_LABEL[p.platform]}</Td>
                  <Td>{archLabel(p.platform, p.architecture)}</Td>
                  <Td>{p.package_types.map((t) => PACKAGE_LABEL[t]).join(', ')}</Td>
                  <Td>{p.default_package_type ? PACKAGE_LABEL[p.default_package_type] : '—'}</Td>
                  <Td>{p.min_os_version ?? '—'}</Td>
                  <Td>{p.required_for_publish ? 'Yes' : 'No'}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}

export function ChannelsAdmin() {
  useDocumentTitle('Channels · Update Center');
  const { user } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({ queryKey: adminKeys.channels, queryFn: adminApi.channels });
  const patch = useMutation({
    mutationFn: (v: { id: string; body: Partial<AdminChannel> }) => adminApi.updateChannel(v.id, v.body),
    onSuccess: () => (toast.success('Channel updated'), void qc.invalidateQueries({ queryKey: adminKeys.channels })),
    onError: (e) => toast.error(errorMessage(e)),
  });
  const editable = can(user, 'application.manage');
  return (
    <>
      <PageHeader title="Channels" sub="Stable, Beta, Dev, Internal and Custom — visibility, access and retention (§4.2)." />
      <Card>
        {q.isLoading ? (
          <Spinner />
        ) : q.error || !q.data ? (
          <div className="p-4">
            <ErrorState error={q.error} retry={q.refetch} />
          </div>
        ) : (
          <Table label="Channels">
            <thead>
              <tr>
                <Th>Channel</Th>
                <Th>Public</Th>
                <Th>Direct download</Th>
                <Th>Auto update</Th>
                <Th>Access</Th>
                <Th>Retention</Th>
              </tr>
            </thead>
            <tbody>
              {q.data.data.map((c) => (
                <tr key={c.id}>
                  <Td>
                    <div className="font-semibold">
                      {c.name} {c.is_default && <Badge tone="indigo">Default</Badge>}
                    </div>
                    <div className="max-w-72 text-[11.5px] text-subtle">{c.description}</div>
                  </Td>
                  {(['is_public', 'allow_direct_download', 'allow_auto_update'] as const).map((k) => (
                    <Td key={k}>
                      <input
                        type="checkbox"
                        className="size-4 accent-brand"
                        aria-label={`${c.name}: ${k.replace(/_/g, ' ')}`}
                        checked={c[k]}
                        disabled={!editable || patch.isPending}
                        onChange={(e) => patch.mutate({ id: c.id, body: { [k]: e.target.checked } })}
                      />
                    </Td>
                  ))}
                  <Td className="max-w-56 text-[12px]">{describeRule(c.access_rule)}</Td>
                  <Td className="text-[12px]">{describeRule(c.retention)}</Td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </>
  );
}

/** "audience: signed_in_beta · tpt: beta" for channel access / retention JSON. */
function describeRule(rule: Record<string, unknown> | null | undefined): string {
  if (!rule) return '—';
  const parts = Object.entries(rule)
    .filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && v.length === 0))
    .map(([k, v]) => `${k.replace(/_/g, ' ')}: ${Array.isArray(v) ? v.join(', ') : typeof v === 'object' ? JSON.stringify(v) : String(v)}`);
  return parts.length ? parts.join(' · ') : '—';
}
