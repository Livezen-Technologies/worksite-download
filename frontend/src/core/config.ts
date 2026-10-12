/** Build-time configuration (Vite env). */
const env = (import.meta.env ?? {}) as Record<string, string | boolean | undefined>;

export const config = {
  /** In-browser mock API (fixtures) instead of the real backend. */
  mock: env.VITE_API_MOCK === 'true' || env.VITE_API_MOCK === true,
  apiBase: (env.VITE_API_BASE as string | undefined) || '/api/v1',
  publicOrigin: ((env.VITE_PUBLIC_ORIGIN as string | undefined) || 'https://download.worksuitecloud.com').replace(/\/$/, ''),
  /** Cloudflare Turnstile site key for the public bug report form; unset → no widget (dev). */
  turnstileSiteKey: (env.VITE_TURNSTILE_SITE_KEY as string | undefined) || '',
  siteName: 'WorkSuite Downloads',
};
