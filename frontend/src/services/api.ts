/**
 * Fetch wrapper for the Update Center API (docs/api-contract.md).
 *
 *  - credentials: 'include' (cookie session `uc_session`)
 *  - X-CSRF-Token on every non-GET, taken from the `csrf` field of /auth/login, /auth/mfa, /auth/me or
 *    passkey verify responses, falling back to the readable `uc_csrf` cookie
 *  - errors become ApiError { code, status, details }
 *  - step-up MFA: a mutation that fails with 401 MFA_REQUIRED/MFA_INVALID is handed to the registered
 *    MFA handler (<MfaPrompt>), then retried with X-MFA-Token
 */
import { config } from '@/core/config';
import type { ApiErrorBody } from '@/types/api';

export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly details: Record<string, unknown> | null;

  constructor(status: number, code: string, message: string, details: Record<string, unknown> | null = null) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.details = details;
  }

  /** Field → message map for 422 VALIDATION_FAILED. */
  get fieldErrors(): Record<string, string> {
    if (this.code !== 'VALIDATION_FAILED' || !this.details) return {};
    const out: Record<string, string> = {};
    for (const [k, v] of Object.entries(this.details)) out[k] = Array.isArray(v) ? String(v[0]) : String(v);
    return out;
  }
}

export const MFA_CODES = new Set(['MFA_REQUIRED', 'MFA_INVALID']);

// ---------------------------------------------------------------- CSRF

let csrfToken: string | null = null;

export function setCsrfToken(token: string | null | undefined): void {
  csrfToken = token ?? null;
}

export function readCookie(name: string, cookieString: string | undefined = typeof document !== 'undefined' ? document.cookie : undefined): string | null {
  if (!cookieString) return null;
  for (const part of cookieString.split(';')) {
    const [k, ...rest] = part.trim().split('=');
    if (k === name) return decodeURIComponent(rest.join('='));
  }
  return null;
}

export function getCsrfToken(): string | null {
  return csrfToken ?? readCookie('uc_csrf');
}

// ---------------------------------------------------------------- MFA handler

/**
 * Called when a mutation needs step-up MFA. Resolve with the TOTP / recovery code to retry with
 * `X-MFA-Token`, with '' when step-up was completed another way (passkey → retry without header),
 * or null when the user cancelled.
 */
export type MfaHandler = (error: ApiError, attempt: number) => Promise<string | null>;
let mfaHandler: MfaHandler | null = null;

export function setMfaHandler(handler: MfaHandler | null): void {
  mfaHandler = handler;
}

// ---------------------------------------------------------------- transport

export interface TransportRequest {
  method: string;
  url: string;
  headers: Record<string, string>;
  body?: BodyInit | null;
  signal?: AbortSignal;
}
export type Transport = (req: TransportRequest) => Promise<Response>;

const fetchTransport: Transport = (req) =>
  fetch(req.url, { method: req.method, headers: req.headers, body: req.body, credentials: 'include', signal: req.signal });

let transport: Transport = fetchTransport;

/** Replace the network layer (mock mode, tests). */
export function setTransport(t: Transport | null): void {
  transport = t ?? fetchTransport;
}

// ---------------------------------------------------------------- request

export type Query = Record<string, string | number | boolean | null | undefined | (string | number)[]>;

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  query?: Query;
  headers?: Record<string, string>;
  signal?: AbortSignal;
  /** Send X-MFA-Token up front (e.g. code typed in the publish modal). */
  mfaToken?: string;
  /** Don't route MFA errors to the global prompt (caller handles them). */
  skipMfaPrompt?: boolean;
  /** Return the raw text body instead of JSON (CSV export). */
  text?: boolean;
}

export function buildUrl(path: string, query?: Query, base: string = config.apiBase): string {
  const url = path.startsWith('http') || path.startsWith('/api/') ? path : `${base}${path.startsWith('/') ? '' : '/'}${path}`;
  if (!query) return url;
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null || v === '') continue;
    params.set(k, Array.isArray(v) ? v.join(',') : String(v));
  }
  const qs = params.toString();
  return qs ? `${url}${url.includes('?') ? '&' : '?'}${qs}` : url;
}

async function parseError(res: Response): Promise<ApiError> {
  let body: Partial<ApiErrorBody> | null = null;
  try {
    body = (await res.json()) as ApiErrorBody;
  } catch {
    body = null;
  }
  const err = body?.error;
  const fallback =
    res.status === 401 ? 'UNAUTHENTICATED' : res.status === 403 ? 'FORBIDDEN' : res.status === 404 ? 'NOT_FOUND' : res.status >= 500 ? 'SERVER_ERROR' : 'HTTP_ERROR';
  return new ApiError(res.status, err?.code ?? fallback, err?.message ?? res.statusText ?? 'Request failed', (err?.details as Record<string, unknown>) ?? null);
}

async function send(path: string, opts: RequestOptions, mfaToken?: string): Promise<Response> {
  const method = opts.method ?? 'GET';
  const headers: Record<string, string> = { Accept: opts.text ? 'text/csv, text/plain, */*' : 'application/json', ...opts.headers };
  let body: BodyInit | null | undefined;
  if (opts.body !== undefined) {
    if (opts.body instanceof FormData || opts.body instanceof Blob) body = opts.body;
    else {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(opts.body);
    }
  }
  if (method !== 'GET') {
    const csrf = getCsrfToken();
    if (csrf) headers['X-CSRF-Token'] = csrf;
  }
  if (mfaToken) headers['X-MFA-Token'] = mfaToken;
  return transport({ method, url: buildUrl(path, opts.query), headers, body, signal: opts.signal });
}

export async function apiRequest<T>(path: string, opts: RequestOptions = {}): Promise<T> {
  const method = opts.method ?? 'GET';
  let token = opts.mfaToken;
  for (let attempt = 0; ; attempt++) {
    const res = await send(path, opts, token);
    if (res.ok) {
      if (res.status === 204) return undefined as T;
      if (opts.text) return (await res.text()) as T;
      const text = await res.text();
      const data = text ? (JSON.parse(text) as T) : (undefined as T);
      const csrf = data && typeof data === 'object' ? (data as unknown as { csrf?: unknown }).csrf : undefined;
      if (typeof csrf === 'string') setCsrfToken(csrf);
      return data;
    }
    const error = await parseError(res);
    const canPrompt = method !== 'GET' && !opts.skipMfaPrompt && mfaHandler && res.status === 401 && MFA_CODES.has(error.code) && attempt < 3;
    if (!canPrompt) throw error;
    const code = await mfaHandler!(error, attempt);
    if (code === null) throw new ApiError(401, 'MFA_CANCELLED', 'Verification cancelled', null);
    token = code || undefined;
  }
}

export const api = {
  get: <T>(path: string, query?: Query, opts: Omit<RequestOptions, 'method' | 'query'> = {}) => apiRequest<T>(path, { ...opts, method: 'GET', query }),
  post: <T>(path: string, body?: unknown, opts: Omit<RequestOptions, 'method' | 'body'> = {}) => apiRequest<T>(path, { ...opts, method: 'POST', body }),
  put: <T>(path: string, body?: unknown, opts: Omit<RequestOptions, 'method' | 'body'> = {}) => apiRequest<T>(path, { ...opts, method: 'PUT', body }),
  patch: <T>(path: string, body?: unknown, opts: Omit<RequestOptions, 'method' | 'body'> = {}) => apiRequest<T>(path, { ...opts, method: 'PATCH', body }),
  delete: <T>(path: string, body?: unknown, opts: Omit<RequestOptions, 'method' | 'body'> = {}) => apiRequest<T>(path, { ...opts, method: 'DELETE', body }),
};

/** Human message for an unknown error. */
export function errorMessage(e: unknown): string {
  if (e instanceof ApiError) return e.message || e.code;
  if (e instanceof Error) return e.message;
  return 'Something went wrong';
}
