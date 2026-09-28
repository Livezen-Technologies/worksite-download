import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api, ApiError, buildUrl, getCsrfToken, readCookie, setCsrfToken, setMfaHandler, setTransport, type TransportRequest } from '@/services/api';

function json(status: number, body: unknown) {
  return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

describe('api client', () => {
  let calls: TransportRequest[];
  beforeEach(() => {
    calls = [];
    setCsrfToken(null);
    setMfaHandler(null);
    document.cookie = 'uc_csrf=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  });
  afterEach(() => setTransport(null));

  it('builds URLs under /api/v1 and drops empty query values', () => {
    expect(buildUrl('/admin/releases', { status: 'DRAFT,UPLOADING', q: '', app: undefined, limit: 50 })).toBe('/api/v1/admin/releases?status=DRAFT%2CUPLOADING&limit=50');
  });

  it('stores the csrf token from a login response and sends it on non-GET requests only', async () => {
    setTransport(async (req) => {
      calls.push(req);
      return req.url.endsWith('/auth/login') ? json(200, { mfa_required: true, csrf: 'tok-123', user: null, methods: ['totp'] }) : json(200, { ok: true });
    });
    await api.post('/auth/login', { email: 'a@b.c', password: 'x' });
    expect(getCsrfToken()).toBe('tok-123');
    await api.get('/admin/dashboard');
    await api.post('/admin/releases/rel_1/approve', {});
    expect(calls[1].headers['X-CSRF-Token']).toBeUndefined();
    expect(calls[2].headers['X-CSRF-Token']).toBe('tok-123');
    expect(calls[2].headers['Content-Type']).toBe('application/json');
  });

  it('falls back to the readable uc_csrf cookie', async () => {
    document.cookie = 'uc_csrf=cookie-tok';
    expect(readCookie('uc_csrf')).toBe('cookie-tok');
    setTransport(async (req) => (calls.push(req), json(200, {})));
    await api.put('/admin/settings', {});
    expect(calls[0].headers['X-CSRF-Token']).toBe('cookie-tok');
  });

  it('throws ApiError carrying code, status and details', async () => {
    setTransport(async () => json(422, { error: { code: 'VALIDATION_FAILED', message: 'Check the highlighted fields', details: { version: 'Invalid SemVer' } } }));
    const err = (await api.post('/admin/releases', {}).catch((e: unknown) => e)) as ApiError;
    expect(err).toBeInstanceOf(ApiError);
    expect(err).toMatchObject({ code: 'VALIDATION_FAILED', status: 422 });
    expect(err.fieldErrors).toEqual({ version: 'Invalid SemVer' });
  });

  it('intercepts 401 MFA_REQUIRED, asks the MFA handler and retries with X-MFA-Token', async () => {
    setTransport(async (req) => {
      calls.push(req);
      return req.headers['X-MFA-Token'] === '123456' ? json(200, { status: 'PUBLISHED' }) : json(401, { error: { code: 'MFA_REQUIRED', message: 'Confirm' } });
    });
    const handler = vi.fn(async (_err: ApiError, _attempt: number) => '123456');
    setMfaHandler(handler);
    await expect(api.post('/admin/releases/rel_1/publish', {})).resolves.toEqual({ status: 'PUBLISHED' });
    expect(handler).toHaveBeenCalledTimes(1);
    expect(handler.mock.calls[0][0]).toMatchObject({ code: 'MFA_REQUIRED' });
    expect(calls).toHaveLength(2);
    expect(calls[1].headers['X-MFA-Token']).toBe('123456');
  });

  it('re-prompts after MFA_INVALID and stops when the user cancels', async () => {
    setTransport(async (req) => json(401, { error: { code: req.headers['X-MFA-Token'] ? 'MFA_INVALID' : 'MFA_REQUIRED', message: 'no' } }));
    const handler = vi.fn().mockResolvedValueOnce('000000').mockResolvedValueOnce(null);
    setMfaHandler(handler);
    await expect(api.post('/admin/releases/rel_1/withdraw', {})).rejects.toMatchObject({ code: 'MFA_CANCELLED' });
    expect(handler).toHaveBeenCalledTimes(2);
    expect(handler.mock.calls[1][0]).toMatchObject({ code: 'MFA_INVALID' });
  });

  it('retries without the header when step-up was completed by passkey', async () => {
    let stepped = false;
    setTransport(async (req) => (calls.push(req), stepped ? json(200, { ok: true }) : json(401, { error: { code: 'MFA_REQUIRED', message: '' } })));
    setMfaHandler(async () => ((stepped = true), ''));
    await api.post('/admin/releases/rel_1/pause', { reason: 'x' });
    expect(calls[1].headers['X-MFA-Token']).toBeUndefined();
  });

  it('never prompts for GET requests or when skipMfaPrompt is set', async () => {
    setTransport(async () => json(401, { error: { code: 'MFA_REQUIRED', message: '' } }));
    const handler = vi.fn(async () => '123456');
    setMfaHandler(handler);
    await expect(api.get('/admin/x')).rejects.toMatchObject({ code: 'MFA_REQUIRED' });
    await expect(api.post('/admin/releases/rel_1/publish', {}, { mfaToken: '111111', skipMfaPrompt: true })).rejects.toMatchObject({ code: 'MFA_REQUIRED' });
    expect(handler).not.toHaveBeenCalled();
  });
});
