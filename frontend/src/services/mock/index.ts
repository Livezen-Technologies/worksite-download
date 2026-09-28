/** Wires the mock server into the API client and the tus uploader. Imported dynamically only in mock mode. */
import type { HttpRequest, HttpResponse, HttpStack } from 'tus-js-client';
import { setTransport, type Transport } from '../api';
import { setTusHttpStack } from '../upload';
import { handleMock, MockHttpError, tusCreate, tusHead, tusPatch } from './server';

const delay = (ms: number) => new Promise((r) => setTimeout(r, ms));

function json(status: number, body: unknown): Response {
  return new Response(status === 204 ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
}

async function readBody(body: BodyInit | null | undefined): Promise<unknown> {
  if (body == null) return undefined;
  if (typeof body === 'string') {
    try {
      return JSON.parse(body);
    } catch {
      return body;
    }
  }
  return body;
}

export const mockTransport: Transport = async (req) => {
  await delay(60 + Math.random() * 120);
  const url = new URL(req.url, 'http://mock.local');
  const path = url.pathname.replace(/^\/api\/v1/, '');
  const headers = Object.fromEntries(Object.entries(req.headers).map(([k, v]) => [k.toLowerCase(), v]));
  try {
    const out = handleMock({ method: req.method, path, query: url.searchParams, headers, body: await readBody(req.body) });
    if (out.text !== undefined) return new Response(out.text, { status: out.status ?? 200, headers: { 'Content-Type': out.contentType ?? 'text/plain' } });
    return json(out.status ?? 200, out.body);
  } catch (e) {
    if (e instanceof MockHttpError) return json(e.status, { error: { code: e.code, message: e.message, details: e.details } });
    console.error('[mock]', e);
    return json(500, { error: { code: 'SERVER_ERROR', message: String(e) } });
  }
};

// ---------------------------------------------------------------- tus

class MockTusResponse implements HttpResponse {
  constructor(
    private status: number,
    private headers: Record<string, string>,
  ) {}
  getStatus() {
    return this.status;
  }
  getHeader(h: string) {
    return this.headers[h.toLowerCase()];
  }
  getBody() {
    return '';
  }
  getUnderlyingObject() {
    return null;
  }
}

class MockTusRequest implements HttpRequest {
  private headers: Record<string, string> = {};
  private progress: (n: number) => void = () => {};
  private aborted = false;
  constructor(
    private method: string,
    private url: string,
  ) {}
  getMethod() {
    return this.method;
  }
  getURL() {
    return this.url;
  }
  setHeader(h: string, v: string) {
    this.headers[h.toLowerCase()] = v;
  }
  getHeader(h: string) {
    return this.headers[h.toLowerCase()];
  }
  setProgressHandler(fn: (n: number) => void) {
    this.progress = fn;
  }
  async abort() {
    this.aborted = true;
  }
  getUnderlyingObject() {
    return null;
  }
  async send(body: unknown): Promise<HttpResponse> {
    const u = new URL(this.url, 'http://mock.local');
    const id = u.pathname.split('/uploads/tus/')[1];
    try {
      if (this.method === 'POST') {
        await delay(120);
        const meta = Object.fromEntries((this.headers['upload-metadata'] ?? '').split(',').map((kv) => kv.trim().split(' ')).map(([k, v]) => [k, v ? atob(v) : '']));
        const loc = tusCreate(meta.upload_id, Number(this.headers['upload-length']));
        return new MockTusResponse(201, { location: loc, 'tus-resumable': '1.0.0' });
      }
      if (this.method === 'HEAD') {
        await delay(60);
        const h = tusHead(id);
        return new MockTusResponse(200, { 'upload-offset': String(h.offset), 'upload-length': String(h.length), 'tus-resumable': '1.0.0' });
      }
      if (this.method === 'PATCH') {
        const size = body instanceof Blob ? body.size : ((body as ArrayBuffer | null)?.byteLength ?? 0);
        // Simulate transfer time (~40 MB/s) with progress callbacks.
        const steps = 5;
        for (let i = 1; i <= steps; i++) {
          await delay(Math.max(20, size / 40_000_000 / steps * 1000));
          if (this.aborted) throw new Error('aborted');
          this.progress((size * i) / steps);
        }
        const offset = tusPatch(id, Number(this.headers['upload-offset']), size);
        return new MockTusResponse(204, { 'upload-offset': String(offset), 'tus-resumable': '1.0.0' });
      }
      if (this.method === 'DELETE') return new MockTusResponse(204, {});
      return new MockTusResponse(405, {});
    } catch (e) {
      if (e instanceof MockHttpError) return new MockTusResponse(e.status, {});
      throw e; // network-level error → tus retry logic
    }
  }
}

export const mockTusStack: HttpStack = {
  createRequest: (method, url) => new MockTusRequest(method, url),
  getName: () => 'MockTusStack',
};

export function installMock(): void {
  setTransport(mockTransport);
  setTusHttpStack(mockTusStack);
  console.info('%c[Update Center] mock API enabled (VITE_API_MOCK=true)', 'color:#3B5BDB');
}
