import { useEffect, useId, useRef, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { X } from 'lucide-react';
import { cx } from '@/core/format';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export interface ModalProps {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  subtitle?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg' | 'xl';
  tone?: 'default' | 'danger';
  /** Block closing via Esc/backdrop (e.g. while an upload is running). */
  dismissible?: boolean;
  aside?: ReactNode;
  initialFocus?: string;
}

/** Accessible modal dialog: role=dialog, aria-modal, labelled by title, focus trap, Esc to close, focus restore. */
export function Modal({ open, onClose, title, subtitle, children, footer, size = 'md', tone = 'default', dismissible = true, aside, initialFocus }: ModalProps) {
  const titleId = useId();
  const descId = useId();
  const panel = useRef<HTMLDivElement>(null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;
  const dismissRef = useRef(dismissible);
  dismissRef.current = dismissible;

  useEffect(() => {
    if (!open) return;
    const previous = document.activeElement as HTMLElement | null;
    const root = panel.current;
    const first = (initialFocus && root?.querySelector<HTMLElement>(initialFocus)) || root?.querySelector<HTMLElement>('[data-autofocus]') || root?.querySelector<HTMLElement>(FOCUSABLE);
    (first ?? root)?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && dismissRef.current) {
        e.stopPropagation();
        onCloseRef.current();
      }
      if (e.key === 'Tab' && root) {
        const items = Array.from(root.querySelectorAll<HTMLElement>(FOCUSABLE)).filter((el) => el.offsetParent !== null || el === document.activeElement);
        if (!items.length) return;
        const firstEl = items[0];
        const lastEl = items[items.length - 1];
        if (e.shiftKey && document.activeElement === firstEl) {
          e.preventDefault();
          lastEl.focus();
        } else if (!e.shiftKey && document.activeElement === lastEl) {
          e.preventDefault();
          firstEl.focus();
        }
      }
    };
    document.addEventListener('keydown', onKey, true);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey, true);
      document.body.style.overflow = overflow;
      previous?.focus?.();
    };
  }, [open, initialFocus]);

  if (!open || typeof document === 'undefined') return null;
  const width = { sm: 'max-w-md', md: 'max-w-xl', lg: 'max-w-3xl', xl: 'max-w-5xl' }[size];
  return createPortal(
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#1b2340]/60 p-3 sm:items-center sm:p-6" onMouseDown={(e) => e.target === e.currentTarget && dismissible && onClose()}>
      <div className={cx('flex w-full flex-col gap-4 lg:flex-row lg:items-center', aside ? 'max-w-6xl justify-center' : width)}>
        <div
          ref={panel}
          role="dialog"
          aria-modal="true"
          aria-labelledby={titleId}
          aria-describedby={subtitle ? descId : undefined}
          tabIndex={-1}
          className={cx('w-full overflow-hidden rounded-2xl bg-white shadow-2xl focus:outline-none', aside ? width : null)}
        >
          <div className={cx('flex items-start justify-between gap-3 border-b px-5 py-4', tone === 'danger' ? 'border-bad bg-bad text-white' : 'border-line')}>
            <div className="min-w-0">
              <h2 id={titleId} className="text-[16px] font-bold">
                {title}
              </h2>
              {subtitle && (
                <p id={descId} className={cx('mt-0.5 text-[12px]', tone === 'danger' ? 'text-white/85' : 'text-subtle')}>
                  {subtitle}
                </p>
              )}
            </div>
            {dismissible && (
              <button
                type="button"
                onClick={onClose}
                aria-label="Close dialog"
                className={cx('grid size-8 shrink-0 place-items-center rounded-lg border', tone === 'danger' ? 'border-white/40 text-white hover:bg-white/10' : 'border-line text-ink hover:bg-ground')}
              >
                <X className="size-4" aria-hidden />
              </button>
            )}
          </div>
          <div className="max-h-[calc(100vh-220px)] overflow-y-auto px-5 py-4">{children}</div>
          {footer && <div className="flex flex-wrap items-center justify-between gap-2 border-t border-line bg-white px-5 py-3">{footer}</div>}
        </div>
        {aside}
      </div>
    </div>,
    document.body,
  );
}
