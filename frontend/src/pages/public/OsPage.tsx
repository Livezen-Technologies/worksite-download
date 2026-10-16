import { useQuery } from '@tanstack/react-query';
import { publicApi, publicKeys, type OsPublicRelease } from '@/services/public';
import { useHead } from '@/core/head';
import { Badge } from '@/components/ui';
import { Container, QueryState } from './common';

const RING_LABEL: Record<OsPublicRelease['ring'], string> = { stable: 'Stable', pilot: 'Beta', development: 'Canary' };
const ORDER = ['stable', 'pilot', 'development'] as const;

function size(bytes: number) {
  return bytes >= 1e9 ? `${(bytes / 1e9).toFixed(1)} GB` : `${Math.max(1, Math.round(bytes / 1e6))} MB`;
}

/** WorkSuite OS ISO downloads with SHA-256 and SBOM, grouped by ring. */
export function OsPage() {
  const q = useQuery({ queryKey: publicKeys.os, queryFn: publicApi.os });
  useHead({ title: 'Download WorkSuite OS | WorkSuite Downloads', description: 'WorkSuite OS installer images (ISO) for amd64 and arm64 with SHA-256 checksums and SBOM.', path: '/os' });
  const rows = q.data?.data ?? [];
  return (
    <Container className="py-8">
      <h1 className="mb-1 text-[24px] font-bold tracking-tight">WorkSuite OS</h1>
      <p className="mb-6 max-w-2xl text-[13px] text-muted">
        Installer images for new PCs. Installed systems update themselves from this site with signed updates; verify each download against its SHA-256 before writing it to a USB stick.
      </p>
      {q.data ? (
        rows.length === 0 ? (
          <p className="text-[13px] text-muted">No WorkSuite OS release has been published yet.</p>
        ) : (
          <div className="grid gap-6">
            {ORDER.filter((ring) => rows.some((r) => r.ring === ring)).map((ring) => (
              <section key={ring}>
                <h2 className="mb-2 text-[15px] font-bold">{RING_LABEL[ring]}</h2>
                <ul className="grid gap-3 md:grid-cols-2">
                  {rows.filter((r) => r.ring === ring).map((r) => (
                    <li key={`${r.ring}-${r.arch}`} className="card p-5" data-testid={`os-${r.ring}-${r.arch}`}>
                      <div className="flex flex-wrap items-center gap-2">
                        <strong className="text-[14px]">{r.version}</strong>
                        <Badge tone="purple">{r.arch}</Badge>
                        {r.release_type !== 'FEATURE' && <Badge tone="green">{r.release_type === 'EMERGENCY' ? 'Emergency security' : 'Security'}</Badge>}
                        {r.state === 'paused' && <Badge tone="purple">Paused</Badge>}
                      </div>
                      {r.iso ? (
                        <>
                          <a className="btn btn-primary mt-3 inline-block" href={r.iso.url}>
                            Download ISO ({size(r.iso.size_bytes)})
                          </a>
                          <p className="mt-2 break-all font-mono text-[11.5px]">SHA-256: {r.iso.sha256 ?? '—'}</p>
                          <p className="mt-1 text-[12.5px]">
                            <a className="link" href={r.iso.sha256_url}>.sha256</a>
                            {r.sbom && (
                              <>
                                {' · '}
                                <a className="link" href={r.sbom.url}>SBOM</a>
                              </>
                            )}
                          </p>
                        </>
                      ) : (
                        <p className="mt-2 text-[12.5px] text-muted">Update only (no installer image for this build).</p>
                      )}
                    </li>
                  ))}
                </ul>
              </section>
            ))}
            <p className="text-[12.5px] text-muted">
              APT repository: <code>{rows[0].apt.url}</code> · archive keyring: <a className="link" href={rows[0].apt.keyring_url}>worksuite-archive-keyring.gpg</a> · release keys: <a className="link" href={q.data.keys_url}>{q.data.keys_url}</a>
            </p>
          </div>
        )
      ) : (
        <QueryState error={q.error} isLoading={q.isLoading} refetch={q.refetch} />
      )}
    </Container>
  );
}
