/** Sign-in, password reset and MFA enrolment — shared by the admin portal and the user portal. */
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Fingerprint, KeyRound, ShieldCheck } from 'lucide-react';
import { authApi } from '@/services/auth';
import { ApiError, errorMessage } from '@/services/api';
import { passkeyAssertion, passkeysSupported, registerPasskey } from '@/services/webauthn';
import { meKey, useAuth } from '@/hooks/useAuth';
import { LogoMark } from '@/components/Logo';
import { OtpInput } from '@/components/OtpInput';
import { Alert, Button, Card, CardHeader, TextField } from '@/components/ui';
import { useToast } from '@/components/ui/Toast';
import { useDocumentTitle } from '@/hooks/useDocumentHead';
import type { MfaMethod } from '@/types/api';

type Area = 'admin' | 'portal';
const home = (area: Area) => (area === 'admin' ? '/admin' : '/portal');

export function AuthShell({ title, sub, children, area }: { title: string; sub?: ReactNode; children: ReactNode; area: Area }) {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-navy px-4 py-10">
      <Link to="/" className="mb-6 flex items-center gap-2 text-[15px] font-bold text-white">
        <LogoMark /> {area === 'admin' ? 'WorkSuite Update Center' : 'WorkSuite Downloads'}
      </Link>
      <main className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
        <h1 className="text-[18px] font-bold">{title}</h1>
        {sub && <p className="mt-1 text-[12.5px] text-subtle">{sub}</p>}
        <div className="mt-5">{children}</div>
      </main>
    </div>
  );
}

export function LoginPage({ area }: { area: Area }) {
  useDocumentTitle(area === 'admin' ? 'Sign in · Update Center' : 'Sign in · WorkSuite Downloads');
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [sp] = useSearchParams();
  const next = sp.get('next') || home(area);
  const [step, setStep] = useState<'password' | 'mfa'>('password');
  const [methods, setMethods] = useState<MfaMethod[]>([]);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const done = async () => {
    await qc.invalidateQueries({ queryKey: meKey });
    navigate(next, { replace: true });
  };

  const submitPassword = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const r = await authApi.login(email, password);
      if (r.mfa_required) {
        setMethods(r.methods.length ? r.methods : ['totp']);
        setStep('mfa');
      } else await done();
    } catch (err) {
      setError(err instanceof ApiError && err.status === 401 ? 'Email or password is incorrect.' : errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  const submitCode = async (e?: FormEvent) => {
    e?.preventDefault();
    if (code.length !== 6) return;
    setBusy(true);
    setError(null);
    try {
      await authApi.mfa(code);
      await done();
    } catch (err) {
      setError(err instanceof ApiError && err.code === 'MFA_INVALID' ? 'That code is not valid. Try the next code from your app.' : errorMessage(err));
      setCode('');
    } finally {
      setBusy(false);
    }
  };

  const usePasskey = async () => {
    setBusy(true);
    setError(null);
    try {
      await passkeyAssertion();
      await done();
    } catch (err) {
      setError(errorMessage(err));
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    if (step === 'mfa' && code.length === 6) void submitCode();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [code, step]);

  if (step === 'mfa')
    return (
      <AuthShell area={area} title="Two-step verification" sub={`Signed in as ${email}. Enter the 6-digit code from your authenticator app.`}>
        <form onSubmit={submitCode} className="space-y-4">
          {error && <Alert tone="bad">{error}</Alert>}
          <OtpInput value={code} onChange={setCode} invalid={!!error} idPrefix="login-otp" autoFocus />
          <Button type="submit" variant="primary" className="w-full" loading={busy} disabled={code.length !== 6}>
            Verify
          </Button>
          {methods.includes('passkey') && passkeysSupported() && (
            <Button className="w-full" icon={<Fingerprint className="size-4" aria-hidden />} onClick={usePasskey} disabled={busy}>
              Use a passkey instead
            </Button>
          )}
          <button type="button" className="link text-[12px]" onClick={() => (setStep('password'), setCode(''), setError(null))}>
            Use a different account
          </button>
        </form>
      </AuthShell>
    );

  return (
    <AuthShell area={area} title={area === 'admin' ? 'Staff sign in' : 'Sign in'} sub={area === 'admin' ? 'Livezen staff only. MFA is required.' : 'Access beta and private downloads, notifications and your bug reports.'}>
      <form onSubmit={submitPassword} className="space-y-3">
        {error && <Alert tone="bad">{error}</Alert>}
        <TextField label="Email" type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} autoFocus />
        <TextField label="Password" type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} />
        <Button type="submit" variant="primary" className="w-full" loading={busy}>
          Continue
        </Button>
        <div className="flex justify-between text-[12px]">
          <Link className="link" to={`${home(area)}/forgot`}>
            Forgot password?
          </Link>
          {area === 'portal' && (
            <Link className="link" to="/portal/signup">
              Create an account
            </Link>
          )}
        </div>
      </form>
    </AuthShell>
  );
}

export function ForgotPage({ area }: { area: Area }) {
  useDocumentTitle('Reset password');
  const [email, setEmail] = useState('');
  const m = useMutation({ mutationFn: () => authApi.forgot(email) });
  return (
    <AuthShell area={area} title="Reset your password" sub="We’ll email you a link if an account exists for this address.">
      {m.isSuccess ? (
        <Alert tone="ok">Check your inbox for a reset link. It expires in 60 minutes.</Alert>
      ) : (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            m.mutate();
          }}
          className="space-y-3"
        >
          {m.error && <Alert tone="bad">{errorMessage(m.error)}</Alert>}
          <TextField label="Email" type="email" required autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} />
          <Button type="submit" variant="primary" className="w-full" loading={m.isPending}>
            Send reset link
          </Button>
        </form>
      )}
      <Link className="link mt-4 inline-block text-[12px]" to={`${home(area)}/login`}>
        Back to sign in
      </Link>
    </AuthShell>
  );
}

export function ResetPage({ area }: { area: Area }) {
  useDocumentTitle('Choose a new password');
  const [sp] = useSearchParams();
  const token = sp.get('token') ?? '';
  const [pw, setPw] = useState('');
  const [pw2, setPw2] = useState('');
  const m = useMutation({ mutationFn: () => authApi.reset(token, pw) });
  const fieldErr = m.error instanceof ApiError ? m.error.fieldErrors.password : undefined;
  return (
    <AuthShell area={area} title="Choose a new password">
      {m.isSuccess ? (
        <>
          <Alert tone="ok">Password updated. You can now sign in.</Alert>
          <Link className="link mt-4 inline-block text-[12px]" to={`${home(area)}/login`}>
            Sign in
          </Link>
        </>
      ) : (
        <form
          onSubmit={(e) => {
            e.preventDefault();
            if (pw === pw2) m.mutate();
          }}
          className="space-y-3"
        >
          {!token && <Alert tone="bad">This reset link is missing its token.</Alert>}
          {m.error && !fieldErr && <Alert tone="bad">{errorMessage(m.error)}</Alert>}
          <TextField label="New password" type="password" autoComplete="new-password" required minLength={12} hint="At least 12 characters." value={pw} onChange={(e) => setPw(e.target.value)} error={fieldErr} />
          <TextField label="Confirm password" type="password" autoComplete="new-password" required value={pw2} onChange={(e) => setPw2(e.target.value)} error={pw2 && pw !== pw2 ? 'Passwords don’t match' : null} />
          <Button type="submit" variant="primary" className="w-full" loading={m.isPending} disabled={!token}>
            Save password
          </Button>
        </form>
      )}
    </AuthShell>
  );
}

/** QR code rendered client-side from the otpauth:// URI (qrcode is loaded on demand). */
function OtpQr({ uri }: { uri: string }) {
  const [src, setSrc] = useState<string | null>(null);
  useEffect(() => {
    let alive = true;
    import('qrcode').then((QR) => QR.toDataURL(uri, { margin: 1, width: 180, color: { dark: '#0F1B3D', light: '#FFFFFF' } })).then((d) => alive && setSrc(d));
    return () => {
      alive = false;
    };
  }, [uri]);
  return src ? <img src={src} alt="QR code for your authenticator app" width={180} height={180} className="rounded-lg border border-line" /> : <div className="size-[180px] animate-pulse rounded-lg bg-ground" />;
}

/** MFA enrolment: TOTP (QR + confirm + recovery codes) and passkeys. Used by admin and portal. */
export function SecuritySettings({ required }: { required?: boolean }) {
  const { user, refresh } = useAuth();
  const toast = useToast();
  const [setup, setSetup] = useState<{ secret: string; otpauth_uri: string } | null>(null);
  const [code, setCode] = useState('');
  const [codes, setCodes] = useState<string[] | null>(null);
  const [pkName, setPkName] = useState('');
  const start = useMutation({ mutationFn: authApi.totpSetup, onSuccess: setSetup });
  const confirm = useMutation({
    mutationFn: () => authApi.totpConfirm(code),
    onSuccess: (r) => {
      setCodes(r.recovery_codes);
      setSetup(null);
      void refresh();
    },
  });
  const passkey = useMutation({
    mutationFn: () => registerPasskey(pkName || 'Passkey'),
    onSuccess: () => {
      toast.success('Passkey added');
      setPkName('');
      void refresh();
    },
  });
  return (
    <div className="grid gap-4 lg:grid-cols-2">
      {required && !user?.mfa_enabled && (
        <Alert tone="warn" className="lg:col-span-2">
          Set up two-step verification to continue. Staff accounts must use MFA (TOTP or a passkey).
        </Alert>
      )}
      <Card>
        <CardHeader title={<span className="flex items-center gap-2"><KeyRound className="size-4" aria-hidden /> Authenticator app (TOTP)</span>} sub={user?.mfa_enabled ? 'Enabled' : 'Not set up'} />
        <div className="space-y-3 px-4 pb-4 text-[13px]">
          {codes ? (
            <>
              <Alert tone="ok">Two-step verification is on. Save these recovery codes somewhere safe — each works once.</Alert>
              <ul className="grid grid-cols-2 gap-1 rounded-lg bg-ground p-3 font-mono text-[12.5px]">
                {codes.map((c) => (
                  <li key={c}>{c}</li>
                ))}
              </ul>
            </>
          ) : setup ? (
            <>
              <p>Scan this QR code with Google Authenticator, 1Password, Authy or Microsoft Authenticator, then enter the 6-digit code.</p>
              <OtpQr uri={setup.otpauth_uri} />
              <p className="text-[12px] text-subtle">
                Can’t scan? Enter this key: <code className="font-mono break-all text-ink">{setup.secret}</code>
              </p>
              <form
                onSubmit={(e) => {
                  e.preventDefault();
                  confirm.mutate();
                }}
                className="space-y-2"
              >
                <OtpInput value={code} onChange={setCode} idPrefix="enrol-otp" invalid={!!confirm.error} />
                {confirm.error && <Alert tone="bad">{errorMessage(confirm.error)}</Alert>}
                <Button type="submit" variant="primary" disabled={code.length !== 6} loading={confirm.isPending}>
                  Confirm and turn on
                </Button>
              </form>
            </>
          ) : (
            <>
              <p className="text-muted">Use a 6-digit code from an authenticator app when signing in and for critical actions (publish, withdraw, key rotation).</p>
              {start.error && <Alert tone="bad">{errorMessage(start.error)}</Alert>}
              <Button variant="primary" onClick={() => start.mutate()} loading={start.isPending}>
                {user?.mfa_enabled ? 'Re-configure authenticator' : 'Set up authenticator'}
              </Button>
            </>
          )}
        </div>
      </Card>
      <Card>
        <CardHeader title={<span className="flex items-center gap-2"><Fingerprint className="size-4" aria-hidden /> Passkeys</span>} sub={`${user?.passkeys ?? 0} registered`} />
        <div className="space-y-3 px-4 pb-4 text-[13px]">
          <p className="text-muted">Sign in and confirm critical actions with Touch ID, Windows Hello or a security key.</p>
          {!passkeysSupported() && <Alert tone="warn">This browser doesn’t support passkeys.</Alert>}
          <form
            onSubmit={(e) => {
              e.preventDefault();
              passkey.mutate();
            }}
            className="flex items-end gap-2"
          >
            <TextField label="Passkey name" placeholder="MacBook Touch ID" value={pkName} onChange={(e) => setPkName(e.target.value)} className="flex-1" />
            <Button type="submit" variant="secondary" loading={passkey.isPending} disabled={!passkeysSupported()} icon={<ShieldCheck className="size-4" aria-hidden />}>
              Add passkey
            </Button>
          </form>
          {passkey.error && <Alert tone="bad">{errorMessage(passkey.error)}</Alert>}
        </div>
      </Card>
    </div>
  );
}
