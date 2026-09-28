/**
 * Cloudflare Worker for cdn-updates.worksuitecloud.com (FR-UC-STO-05, FR-UC-SEC-07).
 * Validates the Update Center's short-lived HMAC URL (exp, fn, sig) and streams
 * the object from the R2 bucket binding with Content-Disposition: attachment.
 * sig = base64url(HMAC-SHA256(CDN_SECRET, "/<key>\n<exp>\n<filename>")).
 *
 * Bindings: RELEASES (R2 bucket), CDN_SECRET (secret).
 */
export default {
  async fetch(request, env) {
    if (request.method !== 'GET' && request.method !== 'HEAD') return new Response('Method not allowed', { status: 405 });
    const url = new URL(request.url);
    const key = decodeURIComponent(url.pathname.slice(1));
    const exp = Number(url.searchParams.get('exp'));
    const fn = url.searchParams.get('fn') ?? '';
    const sig = url.searchParams.get('sig') ?? '';
    if (!key || key.startsWith('_incoming/') || key.startsWith('bugtrace/')) return new Response('Not found', { status: 404 });
    if (!exp || exp < Math.floor(Date.now() / 1000)) return new Response('Link expired — request a new download', { status: 403 });

    const mac = await crypto.subtle.importKey('raw', new TextEncoder().encode(env.CDN_SECRET), { name: 'HMAC', hash: 'SHA-256' }, false, ['verify']);
    const expected = Uint8Array.from(atob(sig.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(sig.length / 4) * 4, '=')), (c) => c.charCodeAt(0));
    const ok = await crypto.subtle.verify('HMAC', mac, expected, new TextEncoder().encode(`/${key}\n${exp}\n${fn}`));
    if (!ok) return new Response('Invalid signature', { status: 403 });

    const range = request.headers.get('Range');
    const object = await env.RELEASES.get(key, range ? { range: request.headers } : undefined);
    if (!object) return new Response('Not found', { status: 404 });

    const headers = new Headers();
    object.writeHttpMetadata(headers);
    headers.set('Content-Type', 'application/octet-stream');
    headers.set('Content-Disposition', `attachment; filename="${fn.replace(/"/g, '')}"`);
    headers.set('Cache-Control', 'private, max-age=0, no-store');
    headers.set('X-Content-Type-Options', 'nosniff');
    headers.set('ETag', object.httpEtag);
    headers.set('Accept-Ranges', 'bytes');
    if (range && object.range) {
      headers.set('Content-Range', `bytes ${object.range.offset}-${object.range.offset + object.range.length - 1}/${object.size}`);
      return new Response(request.method === 'HEAD' ? null : object.body, { status: 206, headers });
    }
    headers.set('Content-Length', String(object.size));
    return new Response(request.method === 'HEAD' ? null : object.body, { headers });
  },
};
