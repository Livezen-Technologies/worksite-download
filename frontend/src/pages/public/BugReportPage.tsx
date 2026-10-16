import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router';
import { CheckCircle2 } from 'lucide-react';
import { publicApi, publicKeys, type BugCategory, type BugReportReceipt, type BugSeverity, type BugReportStatus } from '@/services/public';
import { ApiError, errorMessage } from '@/services/api';
import { useHead } from '@/core/head';
import { config } from '@/core/config';
import { formatDateTime, PLATFORM_LABEL } from '@/core/format';
import { usePlatform } from '@/hooks/usePlatform';
import { defaultReportPlatform, isReportPlatform, REPORT_PLATFORMS, type ReportPlatform } from '@/core/bugreport';
import { Alert, Badge, Button, Checkbox, Select, TextArea, TextField, type Tone } from '@/components/ui';
import { cx } from '@/core/format';
import { Breadcrumb, Container, QueryState } from './common';

const CATEGORIES: { value: BugCategory; label: string }[] = [
  { value: 'BUG', label: 'Something doesn’t work' },
  { value: 'UI', label: 'Display or layout problem' },
  { value: 'PERFORMANCE', label: 'Slow or freezes' },
  { value: 'DATA', label: 'Data looks wrong' },
  { value: 'FEATURE_REQUEST', label: 'Feature request' },
];

const SEVERITIES: { value: BugSeverity; label: string }[] = [
  { value: 'MINOR', label: 'Minor' },
  { value: 'ANNOYING', label: 'Annoying' },
  { value: 'BLOCKS_WORK', label: 'Blocks my work' },
  { value: 'DATA_WRONG', label: 'Data looks wrong' },
];

const STATUS: Record<BugReportStatus['status'], { label: string; tone: Tone }> = {
  received: { label: 'Received', tone: 'blue' },
  linked: { label: 'Being worked on', tone: 'indigo' },
  fixed: { label: 'Fixed', tone: 'green' },
  feature_request: { label: 'Feature request', tone: 'purple' },
  need_info: { label: 'Needs more information', tone: 'amber' },
  closed: { label: 'Closed', tone: 'grey' },
};

function newClientId(): string {
  const c = typeof crypto !== 'undefined' ? crypto : undefined;
  if (c?.randomUUID) return c.randomUUID().replace(/-/g, '');
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
}

export function statusPath(r: BugReportReceipt): string | null {
  return r.status_token ? `/report-a-problem/status?ref=${encodeURIComponent(r.reference)}&token=${encodeURIComponent(r.status_token)}` : null;
}

// ---------------------------------------------------------------- captcha

declare global {
  interface Window {
    turnstile?: { render: (el: HTMLElement, opts: Record<string, unknown>) => string; reset: (id?: string) => void };
  }
}

const TURNSTILE_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

/** Cloudflare Turnstile widget when a site key is configured; otherwise reports a placeholder token (dev). */
function Captcha({ onToken, resetKey }: { onToken: (t: string) => void; resetKey: number }) {
  const ref = useRef<HTMLDivElement>(null);
  const widget = useRef<string | null>(null);
  const siteKey = config.turnstileSiteKey;

  useEffect(() => {
    if (!siteKey) {
      onToken('no-captcha');
      return;
    }
    let alive = true;
    const mount = () => {
      if (!alive || !ref.current || !window.turnstile || widget.current) return;
      widget.current = window.turnstile.render(ref.current, {
        sitekey: siteKey,
        callback: (t: string) => onToken(t),
        'expired-callback': () => onToken(''),
        'error-callback': () => onToken(''),
      });
    };
    if (window.turnstile) mount();
    else {
      let s = document.querySelector<HTMLScriptElement>(`script[src="${TURNSTILE_SRC}"]`);
      if (!s) {
        s = document.createElement('script');
        s.src = TURNSTILE_SRC;
        s.async = true;
        document.head.appendChild(s);
      }
      s.addEventListener('load', mount);
    }
    return () => {
      alive = false;
    };
  }, [siteKey, onToken]);

  useEffect(() => {
    if (resetKey && widget.current && window.turnstile) {
      onToken('');
      window.turnstile.reset(widget.current);
    }
  }, [resetKey, onToken]);

  return siteKey ? <div ref={ref} className="min-h-[65px]" /> : null;
}

// ---------------------------------------------------------------- form

type Errors = Partial<Record<'app' | 'platform' | 'title' | 'severity' | 'category' | 'email' | 'captcha', string>>;

export function BugReportPage() {
  const [sp] = useSearchParams();
  const apps = useQuery({ queryKey: publicKeys.apps, queryFn: publicApi.apps });
  const detected = usePlatform();
  useHead({ title: 'Report a problem | WorkSuite Downloads', description: 'Tell us about a bug, a download or installation problem, or request a feature for WorkSuite applications.', path: '/report-a-problem' });

  const [app, setApp] = useState(sp.get('app') ?? '');
  const [release, setRelease] = useState(sp.get('version') ?? '');
  const [category, setCategory] = useState<BugCategory>('BUG');
  const [severity, setSeverity] = useState<BugSeverity>('ANNOYING');
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [steps, setSteps] = useState('');
  const [expected, setExpected] = useState('');
  const [os, setOs] = useState('');
  const queryPlatform = sp.get('platform');
  const [platform, setPlatform] = useState<ReportPlatform | null>(isReportPlatform(queryPlatform) ? queryPlatform : null);
  const [platformTouched, setPlatformTouched] = useState(isReportPlatform(queryPlatform));
  const [email, setEmail] = useState('');
  const [consent, setConsent] = useState(false);
  const [website, setWebsite] = useState('');
  const [captcha, setCaptcha] = useState('');
  const [captchaReset, setCaptchaReset] = useState(0);
  const [clientId, setClientId] = useState(newClientId);
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<Errors>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [receipt, setReceipt] = useState<BugReportReceipt | null>(null);

  const list = useMemo(() => apps.data?.data ?? [], [apps.data]);
  useEffect(() => {
    if (!app && list.length) setApp(list[0].slug);
  }, [app, list]);
  // Until the reporter picks one, follow the application kind and their device.
  const appKind = list.find((a) => a.slug === app)?.kind;
  useEffect(() => {
    if (!platformTouched) setPlatform(defaultReportPlatform(appKind, detected.platform));
  }, [platformTouched, appKind, detected.platform]);
  useEffect(() => {
    if (!os && detected.platform) setOs(PLATFORM_LABEL[detected.platform]);
    // Only pre-fill once; the user may clear or change it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [detected.platform]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    const errs: Errors = {};
    if (!app) errs.app = 'Choose an application';
    if (!platform) errs.platform = 'Choose where the problem happened';
    if (!title.trim()) errs.title = 'Give the problem a short summary';
    if (consent && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())) errs.email = 'Enter a valid email address';
    if (config.turnstileSiteKey && !captcha) errs.captcha = 'Please complete the verification';
    setErrors(errs);
    setFormError(null);
    if (Object.keys(errs).length) return;

    setBusy(true);
    try {
      const r = await publicApi.submitBugReport({
        app,
        platform: platform ?? undefined,
        release: release.trim() || undefined,
        title: title.trim(),
        description: description.trim() || undefined,
        steps: steps
          .split('\n')
          .map((s) => s.replace(/^\s*(\d+[.)]|[-*•])\s*/, '').trim())
          .filter(Boolean),
        expected: expected.trim() || undefined,
        severity,
        category,
        context: { os: os.trim() ? { name: os.trim().slice(0, 80) } : undefined, platform, source: 'website' },
        contact: consent ? { consent: true, email: email.trim() } : { consent: false },
        client_report_id: clientId,
        captcha_token: captcha,
        website,
      });
      setReceipt(r);
    } catch (err) {
      if (err instanceof ApiError && err.code === 'CAPTCHA_FAILED') {
        setErrors({ captcha: 'Verification failed — please try again' });
        setCaptchaReset((n) => n + 1);
      } else if (err instanceof ApiError && err.code === 'VALIDATION_FAILED') {
        const f = err.fieldErrors;
        setErrors({ app: f.app, platform: f.platform, title: f.title, severity: f.severity, category: f.category, email: f['contact.email'] });
        setFormError(err.message);
      } else {
        setFormError(errorMessage(err));
      }
    } finally {
      setBusy(false);
    }
  };

  const reset = () => {
    setTitle('');
    setDescription('');
    setSteps('');
    setExpected('');
    setReceipt(null);
    setClientId(newClientId());
    setCaptchaReset((n) => n + 1);
  };

  if (receipt) {
    const status = statusPath(receipt);
    return (
      <Container className="py-12">
        <div className="card mx-auto max-w-lg p-8 text-center" role="status">
          <CheckCircle2 className="mx-auto size-10 text-ok" aria-hidden />
          <h1 className="mt-3 text-[20px] font-bold">Thanks — we’ve got your report</h1>
          <p className="mt-2 text-[13px] text-subtle">
            Your reference is <span className="font-mono font-semibold text-ink">{receipt.reference}</span>.
            {consent ? ' We’ll email you when there’s an update.' : ' Keep it if you contact support about this problem.'}
          </p>
          {status && (
            <p className="mt-3 text-[13px]">
              <Link className="link" to={status}>
                Track the status of this report
              </Link>
              <span className="mt-1 block text-[11.5px] text-subtle">Bookmark the link — it’s the only way to view this report without signing in.</span>
            </p>
          )}
          <Button className="mt-5" onClick={reset}>
            Report another problem
          </Button>
        </div>
      </Container>
    );
  }

  return (
    <Container className="py-8">
      <Breadcrumb items={[{ label: 'WorkSuite Downloads', to: '/' }, { label: 'Report a problem' }]} />
      <h1 className="mt-1 text-[24px] font-bold tracking-tight">Report a problem</h1>
      <p className="mt-1 max-w-2xl text-[13px] text-subtle">
        Found a bug, can’t download or install an application, or have an idea? Tell us below. Please don’t include passwords or personal data — reports are reviewed by the WorkSuite team.
      </p>

      {apps.isLoading || apps.error ? (
        <QueryState error={apps.error} isLoading={apps.isLoading} refetch={apps.refetch} />
      ) : (
        <form onSubmit={submit} noValidate className="card mt-5 grid max-w-3xl gap-4 p-5 sm:grid-cols-2" aria-label="Bug report">
          {formError && (
            <Alert tone="bad" className="sm:col-span-2">
              {formError}
            </Alert>
          )}
          <Select label="Application" required value={app} onChange={(e) => setApp(e.target.value)} error={errors.app} options={list.map((a) => ({ value: a.slug, label: a.name }))} />
          <fieldset className="sm:col-span-2" aria-describedby={errors.platform ? 'bt-platform-error' : undefined}>
            <legend className="mb-1 text-[12px] font-semibold text-ink">
              Where did it happen?
              <span aria-hidden className="ml-0.5 text-bad">
                *
              </span>
            </legend>
            <div className="grid gap-2 sm:grid-cols-3">
              {REPORT_PLATFORMS.map((p) => (
                <label
                  key={p.value}
                  className={cx(
                    'flex cursor-pointer items-start gap-2 rounded-lg border px-3 py-2 text-[13px] has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand/25',
                    platform === p.value ? 'border-brand bg-brand/5' : 'border-line hover:border-brand/50',
                  )}
                >
                  <input
                    type="radio"
                    name="platform"
                    value={p.value}
                    checked={platform === p.value}
                    onChange={() => {
                      setPlatform(p.value);
                      setPlatformTouched(true);
                    }}
                    className="mt-0.5 accent-brand"
                  />
                  <span>
                    <span className="block font-semibold text-ink">{p.label}</span>
                    <span className="block text-[11.5px] text-subtle">{p.hint}</span>
                  </span>
                </label>
              ))}
            </div>
            {errors.platform && (
              <p id="bt-platform-error" role="alert" className="mt-1 text-[11.5px] font-medium text-bad">
                {errors.platform}
              </p>
            )}
          </fieldset>
          <TextField label="Version" hint="Optional — e.g. 3.4.1 (Help → About)" value={release} onChange={(e) => setRelease(e.target.value)} maxLength={80} />
          <Select label="What kind of problem?" required value={category} onChange={(e) => setCategory(e.target.value as BugCategory)} error={errors.category} options={CATEGORIES} />
          <Select label="How bad is it?" required value={severity} onChange={(e) => setSeverity(e.target.value as BugSeverity)} error={errors.severity} options={SEVERITIES} />
          <TextField label="Summary" required className="sm:col-span-2" placeholder="e.g. Installer says the file is damaged on macOS" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={200} error={errors.title} />
          <TextArea label="What happened?" className="sm:col-span-2" rows={4} value={description} onChange={(e) => setDescription(e.target.value)} maxLength={10000} hint="Include any error message you saw." />
          <TextArea label="Steps to reproduce" className="sm:col-span-2" rows={4} value={steps} onChange={(e) => setSteps(e.target.value)} hint="One step per line." placeholder={'1. Open the downloaded installer\n2. …'} />
          <TextArea label="What did you expect to happen?" className="sm:col-span-2" rows={2} value={expected} onChange={(e) => setExpected(e.target.value)} maxLength={2000} />
          <TextField label="Operating system" hint="Detected automatically — edit if wrong, e.g. Windows 11 or macOS 15" value={os} onChange={(e) => setOs(e.target.value)} maxLength={80} />
          <div className="flex flex-col gap-2 sm:col-span-2">
            <Checkbox label="Email me about updates to this report" hint="We only use your address for this report." checked={consent} onChange={(e) => setConsent(e.target.checked)} />
            {consent && <TextField label="Email address" type="email" autoComplete="email" required placeholder="you@company.com" value={email} onChange={(e) => setEmail(e.target.value)} error={errors.email} className="max-w-sm" />}
          </div>
          {/* Honeypot: hidden from people and assistive tech; bots fill it. */}
          <div aria-hidden className="absolute -left-[9999px] h-px w-px overflow-hidden">
            <label>
              Website
              <input type="text" name="website" tabIndex={-1} autoComplete="off" value={website} onChange={(e) => setWebsite(e.target.value)} />
            </label>
          </div>
          <div className="sm:col-span-2">
            <Captcha onToken={setCaptcha} resetKey={captchaReset} />
            {errors.captcha && (
              <p role="alert" className="mt-1 text-[11.5px] font-medium text-bad">
                {errors.captcha}
              </p>
            )}
          </div>
          <div className="flex items-center gap-3 sm:col-span-2">
            <Button type="submit" variant="primary" loading={busy}>
              Send report
            </Button>
            <p className="text-[11.5px] text-subtle">
              Have an account?{' '}
              <Link className="link" to="/portal">
                Sign in
              </Link>{' '}
              to see all your reports.
            </p>
          </div>
        </form>
      )}
    </Container>
  );
}

// ---------------------------------------------------------------- status

export function BugReportStatusPage() {
  const [sp] = useSearchParams();
  const ref = sp.get('ref') ?? '';
  const token = sp.get('token') ?? '';
  useHead({ title: 'Report status | WorkSuite Downloads', description: 'Status of your WorkSuite problem report.', path: '/report-a-problem/status', noindex: true });
  const q = useQuery({ queryKey: ['bug-report-status', ref, token], queryFn: () => publicApi.bugReportStatus(ref, token), enabled: !!ref && !!token, retry: false });
  const r = q.data;
  return (
    <Container className="py-8">
      <Breadcrumb items={[{ label: 'Report a problem', to: '/report-a-problem' }, { label: 'Status' }]} />
      <div className="card mt-3 max-w-2xl p-6">
        {!ref || !token ? (
          <p className="text-[13px]">This link is missing its reference or token.</p>
        ) : r ? (
          <>
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-mono text-[12.5px] text-subtle">{r.reference}</span>
              <Badge tone={STATUS[r.status]?.tone ?? 'grey'}>{STATUS[r.status]?.label ?? r.status}</Badge>
              {r.fixed_in && <Badge tone="green">Fixed in {r.fixed_in}</Badge>}
            </div>
            <h1 className="mt-2 text-[20px] font-bold">{r.title}</h1>
            <p className="mt-1 text-[12px] text-subtle">
              Submitted {formatDateTime(r.created_at)} · updated {formatDateTime(r.updated_at)}
            </p>
            <h2 className="mt-5 mb-2 text-[13px] font-semibold">Updates</h2>
            {r.updates.length ? (
              <ol className="space-y-3 border-l border-line pl-4">
                {r.updates.map((u, i) => (
                  <li key={i}>
                    <p className="text-[11.5px] text-subtle">{formatDateTime(u.created_at)}</p>
                    <p className="text-[13px] whitespace-pre-line">{u.message}</p>
                  </li>
                ))}
              </ol>
            ) : (
              <p className="text-[13px] text-subtle">No updates yet. We review new reports every working day.</p>
            )}
          </>
        ) : (
          <QueryState error={q.error} isLoading={q.isLoading} refetch={q.refetch} />
        )}
      </div>
    </Container>
  );
}
