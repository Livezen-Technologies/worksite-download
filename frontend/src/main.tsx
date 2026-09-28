import { StrictMode } from 'react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router';
import { hydrate, type DehydratedState } from '@tanstack/react-query';
import './index.css';
import { createQueryClient } from '@/app/queryClient';
import { Providers } from '@/app/Providers';
import { AppRoutes } from '@/app/AppRoutes';

declare global {
  interface Window {
    __UC_STATE__?: DehydratedState;
  }
}

async function boot() {
  // Literal env check so Vite drops the mock chunk entirely from non-mock builds.
  if (import.meta.env.VITE_API_MOCK === 'true') {
    const mock = await import('./services/mock');
    mock.installMock();
  }
  const client = createQueryClient();
  const state = window.__UC_STATE__;
  if (state) hydrate(client, state);
  const root = document.getElementById('root')!;
  const app = (
    <StrictMode>
      <Providers client={client}>
        <BrowserRouter>
          <AppRoutes />
        </BrowserRouter>
      </Providers>
    </StrictMode>
  );
  // Prerendered pages (scripts/prerender.mjs) are hydrated; everything else is client-rendered.
  if (state && root.firstElementChild) hydrateRoot(root, app);
  else createRoot(root).render(app);
}

void boot();
