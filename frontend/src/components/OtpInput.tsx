import { useRef, type ClipboardEvent, type KeyboardEvent } from 'react';
import { cx } from '@/core/format';

/** 6-digit one-time code as separate boxes (A4). Accessible: grouped, each digit labelled, paste fills all. */
export function OtpInput({ value, onChange, length = 6, invalid, idPrefix = 'otp', label = 'Authenticator code', autoFocus }: { value: string; onChange: (v: string) => void; length?: number; invalid?: boolean; idPrefix?: string; label?: string; autoFocus?: boolean }) {
  const refs = useRef<(HTMLInputElement | null)[]>([]);
  const digits = Array.from({ length }, (_, i) => value[i] ?? '');
  const set = (i: number, d: string) => {
    const next = digits.slice();
    next[i] = d;
    onChange(next.join('').replace(/\s/g, '').slice(0, length));
  };
  const onKey = (e: KeyboardEvent<HTMLInputElement>, i: number) => {
    if (e.key === 'Backspace' && !digits[i] && i > 0) {
      e.preventDefault();
      set(i - 1, '');
      refs.current[i - 1]?.focus();
    }
    if (e.key === 'ArrowLeft' && i > 0) refs.current[i - 1]?.focus();
    if (e.key === 'ArrowRight' && i < length - 1) refs.current[i + 1]?.focus();
  };
  const onPaste = (e: ClipboardEvent<HTMLInputElement>) => {
    const text = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, length);
    if (!text) return;
    e.preventDefault();
    onChange(text);
    refs.current[Math.min(text.length, length - 1)]?.focus();
  };
  return (
    <div role="group" aria-label={label} className="flex gap-2">
      {digits.map((d, i) => (
        <input
          key={i}
          ref={(el) => void (refs.current[i] = el)}
          id={`${idPrefix}-${i}`}
          inputMode="numeric"
          autoComplete={i === 0 ? 'one-time-code' : 'off'}
          pattern="[0-9]*"
          maxLength={1}
          aria-label={`Digit ${i + 1} of ${length}`}
          aria-invalid={invalid || undefined}
          autoFocus={autoFocus && i === 0}
          data-autofocus={i === 0 ? '' : undefined}
          value={d}
          onPaste={onPaste}
          onKeyDown={(e) => onKey(e, i)}
          onChange={(e) => {
            const v = e.target.value.replace(/\D/g, '');
            if (v.length > 1) {
              onChange((digits.slice(0, i).join('') + v).slice(0, length));
              refs.current[Math.min(i + v.length, length - 1)]?.focus();
              return;
            }
            set(i, v);
            if (v && i < length - 1) refs.current[i + 1]?.focus();
          }}
          className={cx(
            'size-11 rounded-lg border bg-white text-center font-mono text-[18px] font-semibold text-ink focus:border-brand focus:ring-2 focus:ring-brand/25 focus:outline-none',
            invalid ? 'border-bad' : 'border-line',
          )}
        />
      ))}
    </div>
  );
}
