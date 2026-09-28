import { createContext, useContext, useEffect, type ReactNode } from 'react';
import { applyHead, type HeadData } from './seo';

/** Server-side collector: pages write their head data here during renderToString. */
export interface HeadCollector {
  head: HeadData | null;
}
const HeadCtx = createContext<HeadCollector | null>(null);

export function HeadProvider({ collector, children }: { collector: HeadCollector; children: ReactNode }) {
  return <HeadCtx.Provider value={collector}>{children}</HeadCtx.Provider>;
}

/** Declare the page's <head>. SSR: recorded synchronously; client: applied after render. */
export function useHead(head: HeadData | null): void {
  const collector = useContext(HeadCtx);
  if (collector && head) collector.head = head;
  const key = head ? JSON.stringify(head) : '';
  useEffect(() => {
    if (head) applyHead(head);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);
}
