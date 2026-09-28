import { useEffect, useRef, useState, type FormEvent } from 'react';
import { ShieldCheck } from 'lucide-react';
import { ApiError, errorMessage, setMfaHandler } from '@/services/api';
import { passkeyAssertion, passkeysSupported } from '@/services/webauthn';
import { Modal } from './ui/Modal';
import { Alert, Button, TextField } from './ui';
import { OtpInput } from './OtpInput';

interface Pending {
  resolve: (code: string | null) => void;
  error: ApiError;
  attempt: number;
}

/**
 * Global step-up MFA prompt. Registers itself as the API client's MFA handler: any mutation that fails with
 * 401 MFA_REQUIRED (or MFA_INVALID on retry) opens this dialog; the typed TOTP / recovery code is returned
 * and the client retries the request with X-MFA-Token. A passkey assertion completes step-up server-side
 * (step_up_valid_until) and the request is retried without the header.
 */
export function MfaPromptProvider() {
  const [pending, setPending] = useState<Pending | null>(null);
  const [code, setCode] = useState('');
  const [recovery, setRecovery] = useState(false);
  const [passkeyBusy, setPasskeyBusy] = useState(false);
  const [passkeyError, setPasskeyError] = useState<string | null>(null);
  const pendingRef = useRef<Pending | null>(null);
  pendingRef.current = pending;

  useEffect(() => {
    setMfaHandler(
      (error, attempt) =>
        new Promise<string | null>((resolve) => {
          setCode('');
          setPasskeyError(null);
          setPending({ resolve, error, attempt });
        }),
    );
    return () => setMfaHandler(null);
  }, []);

  const finish = (value: string | null) => {
    pendingRef.current?.resolve(value);
    setPending(null);
  };

  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (recovery ? code.trim().length >= 8 : code.length === 6) finish(code.trim());
  };

  const usePasskey = async () => {
    setPasskeyBusy(true);
    setPasskeyError(null);
    try {
      await passkeyAssertion();
      finish('');
    } catch (e) {
      setPasskeyError(errorMessage(e));
    } finally {
      setPasskeyBusy(false);
    }
  };

  const invalid = pending?.error.code === 'MFA_INVALID' && pending.attempt > 0;
  return (
    <Modal
      open={!!pending}
      onClose={() => finish(null)}
      size="sm"
      title={
        <span className="flex items-center gap-2">
          <ShieldCheck className="size-5 text-indigo" aria-hidden /> Confirm it’s you
        </span>
      }
      subtitle="This is a critical action. Enter the 6-digit code from your authenticator app."
      footer={
        <>
          <Button onClick={() => finish(null)}>Cancel</Button>
          <Button variant="primary" type="submit" form="mfa-prompt-form" disabled={recovery ? code.trim().length < 8 : code.length !== 6}>
            Verify and continue
          </Button>
        </>
      }
    >
      <form id="mfa-prompt-form" onSubmit={submit} className="space-y-3">
        {invalid && <Alert tone="bad">That code was not accepted. Try the next code from your app.</Alert>}
        {pending && pending.error.message && !invalid && <p className="text-[12.5px] text-subtle">{pending.error.message}</p>}
        {recovery ? (
          <TextField label="Recovery code" mono autoComplete="off" value={code} onChange={(e) => setCode(e.target.value)} data-autofocus="" hint="Each recovery code works once." />
        ) : (
          <div>
            <p className="mb-2 text-[12px] font-semibold">Enter the 6-digit code from your authenticator</p>
            <OtpInput value={code} onChange={setCode} invalid={invalid} idPrefix="mfa-prompt" />
          </div>
        )}
        <p className="text-[12px] text-subtle">
          <button type="button" className="link" onClick={() => (setRecovery((r) => !r), setCode(''))}>
            {recovery ? 'Use authenticator code' : 'Use a recovery code'}
          </button>
          {passkeysSupported() && (
            <>
              {' · '}
              <button type="button" className="link" onClick={usePasskey} disabled={passkeyBusy}>
                {passkeyBusy ? 'Waiting for passkey…' : 'Use a passkey'}
              </button>
            </>
          )}
        </p>
        {passkeyError && <Alert tone="bad">{passkeyError}</Alert>}
      </form>
    </Modal>
  );
}
