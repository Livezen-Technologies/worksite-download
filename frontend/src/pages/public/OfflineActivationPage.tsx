import { useState, type FormEvent } from 'react';
import { CheckCircle2, Download } from 'lucide-react';
import { publicApi } from '@/services/public';
import { errorMessage } from '@/services/api';
import { useHead } from '@/core/head';
import { formatDateTime } from '@/core/format';
import { Alert, Button, TextField } from '@/components/ui';
import { Breadcrumb, Container } from './common';

/**
 * Offline activation (IMPL-LIC-01 §7): a WorkSuite Server with no internet writes a request file
 * (.wsr, Admin Center › System › License › Offline activation, or `spark license:export-request`);
 * here it becomes a signed licence file (.wsl) to import on that server. Enterprise licences.
 */
export function OfflineActivationPage() {
  useHead({ title: 'Offline activation | WorkSuite Downloads', description: 'Activate WorkSuite Server on a server without internet access.', path: '/offline-activation' });
  const [key, setKey] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<{ until: string; name: string } | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!file) return;
    setBusy(true);
    setError(null);
    try {
      const request = JSON.parse(await file.text()) as unknown;
      const wsl = await publicApi.offlineActivation(key.trim(), request);
      const name = `worksuite-licence-${String((wsl as { installation_id?: string }).installation_id ?? 'server')}.wsl`;
      const url = URL.createObjectURL(new Blob([JSON.stringify(wsl, null, 2)], { type: 'application/json' }));
      const a = document.createElement('a');
      a.href = url;
      a.download = name;
      a.click();
      URL.revokeObjectURL(url);
      setDone({ until: String((wsl as { offline_valid_until?: string }).offline_valid_until ?? ''), name });
    } catch (err) {
      setError(err instanceof SyntaxError ? 'That file is not a WorkSuite licence request (.wsr).' : errorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Container className="max-w-2xl py-8">
      <Breadcrumb items={[{ label: 'Downloads', to: '/' }, { label: 'Offline activation' }]} />
      <h1 className="mt-4 text-2xl font-bold">Offline activation</h1>
      <p className="mt-2 text-[14px] text-subtle">
        For WorkSuite Server on a network without internet access (Enterprise licences). On that server, open <strong>Admin Center › System › License › Offline activation</strong> and download the request file (.wsr). Upload it here with your licence key, then import the licence file (.wsl) you get back on the same server.
      </p>
      {done ? (
        <Alert tone="ok" className="mt-6" title="Licence file downloaded">
          <div className="flex items-start gap-2">
            <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span>
              {done.name} is valid until {formatDateTime(done.until)}. Import it in Admin Center › System › License on the server that made the request. It works on that server only.
            </span>
          </div>
        </Alert>
      ) : (
        <form onSubmit={submit} className="mt-6 flex flex-col gap-4">
          <TextField label="Licence key" required mono placeholder="WS-XXXX-XXXX-XXXX-XXXX-XXXX" value={key} onChange={(e) => setKey(e.target.value)} autoComplete="off" />
          <label className="flex flex-col gap-1 text-[13px] font-semibold">
            Request file (.wsr)
            <input type="file" accept=".wsr,application/json" required onChange={(e) => setFile(e.target.files?.[0] ?? null)} className="text-[13px] font-normal" />
          </label>
          {error && <Alert tone="bad">{error}</Alert>}
          <div>
            <Button type="submit" variant="primary" loading={busy} disabled={!file || key.trim().length < 20} icon={<Download className="size-4" aria-hidden />}>
              Get the licence file
            </Button>
          </div>
        </form>
      )}
    </Container>
  );
}
