import { useId } from 'react';
import { Check } from 'lucide-react';
import { cx } from '@/core/format';

export function isConfirmed(expected: string, value: string): boolean {
  return expected.length > 0 && value.trim() === expected;
}

/** "Type the version / file name to confirm" input for destructive actions (§7.6). */
export function TypeToConfirm({ expected, value, onChange, what = 'version', disabled }: { expected: string; value: string; onChange: (v: string) => void; what?: string; disabled?: boolean }) {
  const id = useId();
  const ok = isConfirmed(expected, value);
  return (
    <div className="flex flex-col gap-1">
      <label htmlFor={id} className="text-[12px] font-semibold text-ink">
        Type-to-confirm: type the {what} <code className="rounded bg-ground px-1 font-mono">{expected}</code>
      </label>
      <div className="relative">
        <input
          id={id}
          value={value}
          disabled={disabled}
          autoComplete="off"
          spellCheck={false}
          onChange={(e) => onChange(e.target.value)}
          aria-describedby={`${id}-state`}
          aria-invalid={value.length > 0 && !ok ? true : undefined}
          className={cx(
            'h-9 w-full rounded-lg border bg-white px-3 pr-9 font-mono text-[13px] focus:ring-2 focus:ring-brand/25 focus:outline-none',
            ok ? 'border-ok' : value ? 'border-bad' : 'border-line',
          )}
        />
        {ok && <Check className="absolute top-2.5 right-3 size-4 text-ok" aria-hidden />}
      </div>
      <p id={`${id}-state`} aria-live="polite" className={cx('text-[11.5px]', ok ? 'text-ok' : 'text-subtle')}>
        {ok ? 'Confirmed' : `Must match exactly: ${expected}`}
      </p>
    </div>
  );
}
