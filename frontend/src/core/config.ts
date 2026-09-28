/** Build-time configuration (Vite env). */
const env = (import.meta.env ?? {}) as Record<string, string | boolean | undefined>;

export const config = {
  /** In-browser mock API (fixtures) instead of the real backend. */
  mock: env.VITE_API_MOCK === 'true' || env.VITE_API_MOCK === true,
  apiBase: (env.VITE_API_BASE as string | undefined) || '/api/v1',
  publicOrigin: ((env.VITE_PUBLIC_ORIGIN as string | undefined) || 'https://updates.worksuitecloud.com').replace(/\/$/, ''),
  siteName: 'WorkSuite Downloads',
};
