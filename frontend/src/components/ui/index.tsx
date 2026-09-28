import { forwardRef, useId, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react';
import { Link, type LinkProps } from 'react-router';
import { Loader2 } from 'lucide-react';
import { cx } from '@/core/format';
import { ApiError, errorMessage } from '@/services/api';

// ---------------------------------------------------------------- buttons

type Variant = 'primary' | 'secondary' | 'danger' | 'ghost' | 'brand';
type Size = 'sm' | 'md' | 'lg';

const VARIANT: Record<Variant, string> = {
  primary: 'bg-indigo text-white hover:bg-indigo-700 border border-indigo disabled:bg-indigo/50 disabled:border-transparent',
  brand: 'bg-brand text-white hover:bg-indigo border border-brand disabled:opacity-60',
  secondary: 'bg-white text-ink border border-line hover:bg-ground hover:border-[#cfd4e2] disabled:text-subtle',
  danger: 'bg-bad text-white border border-bad hover:bg-[#931d13] disabled:bg-bad/50 disabled:border-transparent',
  ghost: 'bg-transparent text-ink border border-transparent hover:bg-black/5',
};
const SIZE: Record<Size, string> = {
  sm: 'h-8 px-3 text-[12.5px] gap-1.5',
  md: 'h-9 px-3.5 text-[13px] gap-2',
  lg: 'h-11 px-5 text-[14px] gap-2',
};

export function buttonClass(variant: Variant = 'secondary', size: Size = 'md', extra?: string): string {
  return cx(
    'inline-flex items-center justify-center rounded-lg font-semibold whitespace-nowrap transition-colors disabled:cursor-not-allowed',
    VARIANT[variant],
    SIZE[size],
    extra,
  );
}

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  icon?: ReactNode;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(function Button({ variant = 'secondary', size = 'md', loading, icon, className, children, disabled, type = 'button', ...rest }, ref) {
  return (
    <button ref={ref} type={type} className={buttonClass(variant, size, className)} disabled={disabled || loading} aria-busy={loading || undefined} {...rest}>
      {loading ? <Loader2 className="size-4 animate-spin" aria-hidden /> : icon}
      {children}
    </button>
  );
});

export function ButtonLink({ variant = 'secondary', size = 'md', className, icon, children, ...rest }: LinkProps & { variant?: Variant; size?: Size; icon?: ReactNode }) {
  return (
    <Link className={buttonClass(variant, size, className)} {...rest}>
      {icon}
      {children}
    </Link>
  );
}

// ---------------------------------------------------------------- badges

export type Tone = 'green' | 'blue' | 'grey' | 'amber' | 'red' | 'indigo' | 'teal' | 'purple' | 'navy';
const TONE: Record<Tone, string> = {
  green: 'bg-ok-bg text-ok',
  blue: 'bg-info-bg text-info',
  indigo: 'bg-brand-50 text-indigo',
  grey: 'bg-[#eef0f5] text-[#454d66]',
  amber: 'bg-warn-bg text-warn',
  red: 'bg-bad-bg text-bad',
  teal: 'bg-[#e3f5f1] text-[#0b6b5a]',
  purple: 'bg-[#f1eafd] text-[#6b2fb3]',
  navy: 'bg-navy text-white',
};

export function Badge({ tone = 'grey', children, className, title }: { tone?: Tone; children: ReactNode; className?: string; title?: string }) {
  return (
    <span title={title} className={cx('inline-flex items-center rounded-md px-1.5 py-0.5 text-[10.5px] leading-4 font-bold tracking-[0.04em] whitespace-nowrap uppercase', TONE[tone], className)}>
      {children}
    </span>
  );
}

// ---------------------------------------------------------------- cards & layout

export function Card({ children, className, as: As = 'section', ...rest }: { children: ReactNode; className?: string; as?: 'section' | 'div' | 'article' | 'aside' } & Record<string, unknown>) {
  return (
    <As className={cx('card', className)} {...rest}>
      {children}
    </As>
  );
}

export function CardHeader({ title, sub, actions, id, className }: { title: ReactNode; sub?: ReactNode; actions?: ReactNode; id?: string; className?: string }) {
  return (
    <div className={cx('flex flex-wrap items-center justify-between gap-2 px-4 pt-3.5 pb-2', className)}>
      <div className="flex min-w-0 items-baseline gap-2">
        <h2 id={id} className="text-[14px] font-bold text-ink">
          {title}
        </h2>
        {sub && <span className="truncate text-[12px] text-subtle">{sub}</span>}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </div>
  );
}

export function PageHeader({ title, sub, actions, breadcrumb, badges }: { title: ReactNode; sub?: ReactNode; actions?: ReactNode; breadcrumb?: ReactNode; badges?: ReactNode }) {
  return (
    <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
      <div className="min-w-0">
        {breadcrumb && <div className="mb-1 text-[12px] text-subtle">{breadcrumb}</div>}
        <div className="flex flex-wrap items-center gap-2">
          <h1 className="text-[20px] font-bold tracking-tight text-ink">{title}</h1>
          {badges}
        </div>
        {sub && <p className="mt-0.5 text-[12.5px] text-subtle">{sub}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

export function Kpi({ label, value, sub, tone }: { label: string; value: ReactNode; sub?: ReactNode; tone?: 'ok' | 'warn' | 'bad' }) {
  return (
    <div className="card px-4 py-3">
      <div className="label-caps">{label}</div>
      <div className={cx('mt-1 text-[24px] leading-7 font-bold', tone === 'ok' && 'text-ok', tone === 'warn' && 'text-warn', tone === 'bad' && 'text-bad')}>{value}</div>
      {sub && <div className="mt-0.5 text-[11.5px] text-subtle">{sub}</div>}
    </div>
  );
}

export function KeyValue({ rows, className }: { rows: [ReactNode, ReactNode][]; className?: string }) {
  return (
    <dl className={cx('divide-y divide-line text-[12.5px]', className)}>
      {rows.map(([k, v], i) => (
        <div key={i} className="flex items-start justify-between gap-4 py-2">
          <dt className="shrink-0 text-subtle">{k}</dt>
          <dd className="min-w-0 text-right font-medium break-words text-ink">{v}</dd>
        </div>
      ))}
    </dl>
  );
}

// ---------------------------------------------------------------- feedback

export function Spinner({ label = 'Loading…', className }: { label?: string; className?: string }) {
  return (
    <div role="status" className={cx('flex items-center gap-2 p-6 text-[13px] text-subtle', className)}>
      <Loader2 className="size-4 animate-spin" aria-hidden />
      {label}
    </div>
  );
}

export function EmptyState({ title, children, icon }: { title: string; children?: ReactNode; icon?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-1 px-6 py-10 text-center">
      {icon && <div className="mb-1 text-subtle">{icon}</div>}
      <p className="text-[14px] font-semibold text-ink">{title}</p>
      {children && <div className="max-w-md text-[12.5px] text-subtle">{children}</div>}
    </div>
  );
}

export function ErrorState({ error, retry }: { error: unknown; retry?: () => void }) {
  const code = error instanceof ApiError ? error.code : null;
  return (
    <div role="alert" className="card flex flex-wrap items-center justify-between gap-3 border-bad/30 bg-bad-bg/40 px-4 py-3 text-[13px]">
      <div>
        <strong className="text-bad">Couldn’t load this.</strong> <span className="text-ink">{errorMessage(error)}</span>
        {code && <span className="ml-2 font-mono text-[11px] text-subtle">{code}</span>}
      </div>
      {retry && (
        <Button size="sm" onClick={retry}>
          Try again
        </Button>
      )}
    </div>
  );
}

export function Alert({ tone = 'info', title, children, className }: { tone?: 'info' | 'warn' | 'bad' | 'ok'; title?: ReactNode; children?: ReactNode; className?: string }) {
  const map = { info: 'border-brand/25 bg-brand-50 text-ink', warn: 'border-warn/30 bg-warn-bg text-ink', bad: 'border-bad/30 bg-bad-bg text-ink', ok: 'border-ok/30 bg-ok-bg text-ink' };
  return (
    <div role={tone === 'bad' ? 'alert' : 'status'} className={cx('rounded-lg border px-3 py-2 text-[12.5px]', map[tone], className)}>
      {title && <strong className="mr-1">{title}</strong>}
      {children}
    </div>
  );
}

// ---------------------------------------------------------------- form fields

interface FieldShell {
  label: ReactNode;
  hint?: ReactNode;
  error?: string | null;
  className?: string;
  required?: boolean;
}

function Shell({ id, label, hint, error, className, required, children }: FieldShell & { id: string; children: ReactNode }) {
  return (
    <div className={cx('flex flex-col gap-1', className)}>
      <label htmlFor={id} className="text-[12px] font-semibold text-ink">
        {label}
        {required && (
          <span aria-hidden className="ml-0.5 text-bad">
            *
          </span>
        )}
      </label>
      {children}
      {hint && !error && (
        <p id={`${id}-hint`} className="text-[11.5px] text-subtle">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-error`} role="alert" className="text-[11.5px] font-medium text-bad">
          {error}
        </p>
      )}
    </div>
  );
}

const inputBase =
  'w-full rounded-lg border bg-white px-3 text-[13px] text-ink placeholder:text-[#8a91a8] focus:border-brand focus:ring-2 focus:ring-brand/25 focus:outline-none disabled:bg-ground disabled:text-subtle';

function describedBy(id: string, hint?: ReactNode, error?: string | null) {
  return error ? `${id}-error` : hint ? `${id}-hint` : undefined;
}

export const TextField = forwardRef<HTMLInputElement, FieldShell & InputHTMLAttributes<HTMLInputElement> & { mono?: boolean }>(function TextField({ label, hint, error, className, required, mono, id: idProp, ...rest }, ref) {
  const auto = useId();
  const id = idProp ?? auto;
  return (
    <Shell id={id} label={label} hint={hint} error={error} className={className} required={required}>
      <input
        ref={ref}
        id={id}
        required={required}
        aria-invalid={!!error || undefined}
        aria-describedby={describedBy(id, hint, error)}
        className={cx(inputBase, 'h-9', error ? 'border-bad' : 'border-line', mono && 'font-mono')}
        {...rest}
      />
    </Shell>
  );
});

export function TextArea({ label, hint, error, className, required, mono, id: idProp, rows = 4, ...rest }: FieldShell & TextareaHTMLAttributes<HTMLTextAreaElement> & { mono?: boolean }) {
  const auto = useId();
  const id = idProp ?? auto;
  return (
    <Shell id={id} label={label} hint={hint} error={error} className={className} required={required}>
      <textarea
        id={id}
        rows={rows}
        required={required}
        aria-invalid={!!error || undefined}
        aria-describedby={describedBy(id, hint, error)}
        className={cx(inputBase, 'py-2', error ? 'border-bad' : 'border-line', mono && 'font-mono text-[12.5px]')}
        {...rest}
      />
    </Shell>
  );
}

export function Select({ label, hint, error, className, required, options, id: idProp, ...rest }: FieldShell & SelectHTMLAttributes<HTMLSelectElement> & { options: { value: string; label: string }[] }) {
  const auto = useId();
  const id = idProp ?? auto;
  return (
    <Shell id={id} label={label} hint={hint} error={error} className={className} required={required}>
      <select
        id={id}
        required={required}
        aria-invalid={!!error || undefined}
        aria-describedby={describedBy(id, hint, error)}
        className={cx(inputBase, 'h-9 pr-8', error ? 'border-bad' : 'border-line')}
        {...rest}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </Shell>
  );
}

export function Checkbox({ label, hint, className, id: idProp, ...rest }: { label: ReactNode; hint?: ReactNode; className?: string } & InputHTMLAttributes<HTMLInputElement>) {
  const auto = useId();
  const id = idProp ?? auto;
  return (
    <div className={cx('flex items-start gap-2', className)}>
      <input id={id} type="checkbox" className="mt-0.5 size-4 rounded border-line accent-brand" aria-describedby={hint ? `${id}-hint` : undefined} {...rest} />
      <div>
        <label htmlFor={id} className="text-[13px] text-ink">
          {label}
        </label>
        {hint && (
          <p id={`${id}-hint`} className="text-[11.5px] text-subtle">
            {hint}
          </p>
        )}
      </div>
    </div>
  );
}

/** Compact inline filter <select> (B1 filter chips, list filters). */
export function FilterSelect({ label, value, onChange, options }: { label: string; value: string; onChange: (v: string) => void; options: { value: string; label: string }[] }) {
  const id = useId();
  return (
    <div className="flex items-center">
      <label htmlFor={id} className="sr-only">
        {label}
      </label>
      <select
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="h-8 rounded-lg border border-line bg-white px-2.5 pr-7 text-[12px] text-ink focus:border-brand focus:ring-2 focus:ring-brand/25 focus:outline-none"
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {label}: {o.label}
          </option>
        ))}
      </select>
    </div>
  );
}

// ---------------------------------------------------------------- table

export function Table({ children, className, label }: { children: ReactNode; className?: string; label?: string }) {
  return (
    <div className={cx('overflow-x-auto', className)}>
      <table className="w-full border-collapse text-left text-[12.5px]" aria-label={label}>
        {children}
      </table>
    </div>
  );
}
export function Th({ children, className, ...rest }: { children?: ReactNode; className?: string } & Record<string, unknown>) {
  return (
    <th scope="col" className={cx('border-b border-line px-3 py-2 text-[10.5px] font-bold tracking-[0.06em] whitespace-nowrap text-subtle uppercase', className)} {...rest}>
      {children}
    </th>
  );
}
export function Td({ children, className, ...rest }: { children?: ReactNode; className?: string } & Record<string, unknown>) {
  return (
    <td className={cx('border-b border-line/70 px-3 py-2.5 align-middle', className)} {...rest}>
      {children}
    </td>
  );
}

// ---------------------------------------------------------------- bars

/** Horizontal labelled bars (dashboard distributions, downloads by package). */
export function BarList({ items, color = 'bg-indigo', valueFormat, max, label }: { items: { label: ReactNode; value: number; color?: string; display?: ReactNode }[]; color?: string; valueFormat?: (n: number) => ReactNode; max?: number; label?: string }) {
  const m = max ?? Math.max(1, ...items.map((i) => i.value));
  return (
    <ul className="space-y-2" aria-label={label}>
      {items.map((it, i) => (
        <li key={i} className="grid grid-cols-[minmax(64px,110px)_1fr_auto] items-center gap-3 text-[12px]">
          <span className="truncate font-mono text-ink">{it.label}</span>
          <span className="h-2.5 rounded-full bg-[#e9ecf4]" aria-hidden>
            <span className={cx('block h-2.5 rounded-full', it.color ?? color)} style={{ width: `${Math.max(1.5, (it.value / m) * 100)}%` }} />
          </span>
          <span className="min-w-[44px] text-right font-mono text-[11.5px] text-ink">{it.display ?? (valueFormat ? valueFormat(it.value) : it.value)}</span>
        </li>
      ))}
    </ul>
  );
}

/** Vertical bar series (downloads per day, occurrences). */
export function ColumnChart({ data, height = 120, color = 'bg-indigo', label, formatLabel }: { data: { label: string; value: number }[]; height?: number; color?: string; label: string; formatLabel?: (l: string) => string }) {
  const m = Math.max(1, ...data.map((d) => d.value));
  return (
    <figure aria-label={label}>
      <div className="flex items-end gap-[3px]" style={{ height }} role="img" aria-label={`${label}: ${data.map((d) => `${d.label} ${d.value}`).join(', ')}`}>
        {data.map((d, i) => (
          <div key={i} className="flex h-full flex-1 items-end" title={`${d.label}: ${d.value}`}>
            <div className={cx('w-full rounded-t-[3px]', color)} style={{ height: `${Math.max(2, (d.value / m) * 100)}%` }} />
          </div>
        ))}
      </div>
      {data.length > 1 && (
        <figcaption className="mt-1 flex justify-between font-mono text-[10.5px] text-subtle">
          <span>{formatLabel ? formatLabel(data[0].label) : data[0].label}</span>
          <span>{formatLabel ? formatLabel(data[data.length - 1].label) : data[data.length - 1].label}</span>
        </figcaption>
      )}
    </figure>
  );
}

export function ProgressBar({ value, tone = 'bg-ok', label, className }: { value: number; tone?: string; label: string; className?: string }) {
  return (
    <div className={cx('h-1.5 w-full rounded-full bg-[#e6e9f2]', className)} role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(value)}>
      <div className={cx('h-1.5 rounded-full transition-[width]', tone)} style={{ width: `${Math.min(100, Math.max(0, value))}%` }} />
    </div>
  );
}

export function Mono({ children, className }: { children: ReactNode; className?: string }) {
  return <span className={cx('font-mono', className)}>{children}</span>;
}
