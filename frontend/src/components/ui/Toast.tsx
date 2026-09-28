import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react';
import { CheckCircle2, XCircle } from 'lucide-react';
import { cx } from '@/core/format';

interface ToastItem {
  id: number;
  tone: 'ok' | 'bad';
  message: ReactNode;
}
interface ToastApi {
  success: (m: ReactNode) => void;
  error: (m: ReactNode) => void;
}

const Ctx = createContext<ToastApi>({ success: () => {}, error: () => {} });
let seq = 0;

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([]);
  const push = useCallback((tone: ToastItem['tone'], message: ReactNode) => {
    const id = ++seq;
    setItems((x) => [...x, { id, tone, message }]);
    setTimeout(() => setItems((x) => x.filter((t) => t.id !== id)), tone === 'bad' ? 7000 : 4000);
  }, []);
  const api = useMemo(() => ({ success: (m: ReactNode) => push('ok', m), error: (m: ReactNode) => push('bad', m) }), [push]);
  return (
    <Ctx.Provider value={api}>
      {children}
      <div aria-live="polite" className="pointer-events-none fixed right-4 bottom-4 z-[60] flex w-[min(92vw,380px)] flex-col gap-2">
        {items.map((t) => (
          <div key={t.id} role={t.tone === 'bad' ? 'alert' : 'status'} className={cx('pointer-events-auto flex items-start gap-2 rounded-xl border bg-white px-3 py-2.5 text-[13px] shadow-lg', t.tone === 'ok' ? 'border-ok/30' : 'border-bad/40')}>
            {t.tone === 'ok' ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-ok" aria-hidden /> : <XCircle className="mt-0.5 size-4 shrink-0 text-bad" aria-hidden />}
            <div>{t.message}</div>
          </div>
        ))}
      </div>
    </Ctx.Provider>
  );
}

export const useToast = () => useContext(Ctx);
