import { useEffect } from 'react';

/** Client-side title/description updates for SPA navigation (SSR renders the full <head> via core/seo). */
export function useDocumentTitle(title: string | null | undefined, description?: string | null) {
  useEffect(() => {
    if (!title) return;
    document.title = title;
    if (description) {
      let m = document.querySelector<HTMLMetaElement>('meta[name="description"]');
      if (!m) {
        m = document.createElement('meta');
        m.name = 'description';
        document.head.appendChild(m);
      }
      m.content = description;
    }
  }, [title, description]);
}
