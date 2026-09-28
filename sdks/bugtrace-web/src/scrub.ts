/**
 * Client-side scrubbing (§18.8). The server scrubs again; this keeps secrets
 * and personal data from ever leaving the device.
 */

export const FILTERED = '[Filtered]';

const SENSITIVE_KEY =
  /^(pass(word|wd|phrase)?|pwd|secret|.*_secret|token|.*[_-]token|authorization|cookie|set-cookie|session(id)?|csrf.*|xsrf.*|x-ws-.*|x-mfa-token|api[_-]?key|apikey|private[_-]?key|card[_-]?number|cvv|cvc|iban|account[_-]?number|nic|otp)$/i;
const CONTENT_KEY = /^(body|subject|content|input|value|clipboard|message[_-]?body|file[_-]?name|filename|params)$/i;

export function luhn(d: string): boolean {
  let sum = 0;
  let alt = false;
  for (let i = d.length - 1; i >= 0; i--) {
    let n = d.charCodeAt(i) - 48;
    if (alt) {
      n *= 2;
      if (n > 9) n -= 9;
    }
    sum += n;
    alt = !alt;
  }
  return sum % 10 === 0;
}

type Rule = [RegExp, string | ((m: string, ...g: string[]) => string)];

const RULES: Rule[] = [
  [/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?(-----END [A-Z ]*PRIVATE KEY-----|$)/g, FILTERED],
  [/\beyJ[\w-]{5,}\.[\w-]{5,}\.[\w-]*/g, FILTERED],
  [/\b(Bearer|Basic|Token)\s+[\w.~+/=-]{6,}/gi, `$1 ${FILTERED}`],
  [/\b(pass(?:word|wd)?|pwd|secret|token|api[_-]?key|apikey|session[_-]?id|otp)((?:["']?\s*[:=]\s*["']?)|\s+is\s+)([^\s"'&,;}\]<>]+)/gi, `$1$2${FILTERED}`],
  [/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{10,}\b|\bAKIA[0-9A-Z]{16}\b|\bgh[pousr]_[A-Za-z0-9]{30,}\b/g, FILTERED],
  [/[\w.%+-]+@[\w-]+(?:\.[\w-]+)*\.[A-Za-z]{2,}/g, FILTERED],
  [/(https?:\/\/[^\s?#"'<>]+)[?#][^\s"'<>]*/gi, '$1'],
  [
    /(?<![\d.])(?:\d[ -]?){12,18}\d(?![\d.])/g,
    (m) => {
      const d = m.replace(/\D/g, '');
      return d.length >= 13 && '23456'.includes(d[0]) && luhn(d) ? FILTERED : m;
    },
  ],
  [/(?<![\d.])(?:19|20)\d{10}(?![\d.])/g, FILTERED],
  [/(?<![\w.])\d{9}[VvXx](?!\w)/g, FILTERED],
  [/(?<![\w+])\+\d[\d\s().-]{6,18}\d(?!\w)/g, (m) => { const n = m.replace(/\D/g, '').length; return n >= 8 && n <= 15 ? FILTERED : m; }],
  [/(?<![\w.+-])0(?:7\d|1\d|[2-9]\d)[\s-]?\d{3}[\s-]?\d{4}(?![\w.])/g, FILTERED],
  [/(?<![\w.])(?:\d{1,3}\.){3}\d{1,3}(?![\w.])/g, (m) => (m.split('.').every((o) => +o <= 255 && !(o.length > 1 && o[0] === '0')) ? FILTERED : m)],
  [/\b(?=[\w-]*\d)(?=[\w-]*[a-z])(?=[\w-]*[A-Z])[\w-]{32,}\b/g, FILTERED],
];

export class Scrubber {
  count = 0;
  constructor(private extra: RegExp[] = []) {}

  str(s: string): string {
    if (!s) return s;
    if (s.length > 8192) s = s.slice(0, 8192) + '…';
    for (const [re, rep] of RULES) {
      s = s.replace(re, (...args: unknown[]) => {
        const m = args[0] as string;
        const out = typeof rep === 'function' ? rep(m) : m.replace(new RegExp(re.source, re.flags.replace('g', '')), rep);
        if (out !== m) this.count++;
        return out;
      });
    }
    for (const re of this.extra) {
      const g = new RegExp(re.source, re.flags.includes('g') ? re.flags : re.flags + 'g');
      s = s.replace(g, () => (this.count++, FILTERED));
    }
    return s;
  }

  url(u: string): string {
    const cut = u.search(/[?#]/);
    if (cut >= 0) {
      u = u.slice(0, cut);
      this.count++;
    }
    const m = /^([a-z][\w+.-]*:\/\/[^/]*)(\/.*)?$/i.exec(u);
    return m ? m[1] + templatePath(m[2] || '') : this.str(templatePath(u));
  }

  value(v: unknown, key = '', depth = 0): unknown {
    if (key && SENSITIVE_KEY.test(key) && v !== null && v !== '' && typeof v !== 'boolean') {
      this.count++;
      return FILTERED;
    }
    if (key && CONTENT_KEY.test(key) && typeof v !== 'number' && typeof v !== 'boolean' && v != null && v !== '') {
      this.count++;
      return FILTERED;
    }
    if (typeof v === 'string') return /^(url|path|to|from|href|route|location|referrer)$/i.test(key) ? this.url(v) : this.str(v);
    if (Array.isArray(v)) return depth > 6 ? FILTERED : v.slice(0, 100).map((x) => this.value(x, key, depth + 1));
    if (v && typeof v === 'object') {
      if (depth > 6) return FILTERED;
      const out: Record<string, unknown> = {};
      for (const [k, x] of Object.entries(v as Record<string, unknown>).slice(0, 100)) {
        if (/^(ip|ip_address|remote_addr|client_ip)$/i.test(k)) {
          this.count++;
          continue;
        }
        out[k] = this.value(x, k, depth + 1);
      }
      return out;
    }
    return v;
  }
}

/** /mail/folders/88/messages/3f2a…-… → /mail/folders/:id/messages/:uuid */
export function templatePath(p: string): string {
  return p
    .split('/')
    .map((s) =>
      /^\d+$/.test(s)
        ? ':id'
        : /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(s)
          ? ':uuid'
          : /^[0-9a-f]{16,}$/i.test(s) || /^([a-z]{2,5}_)?[0-9A-HJKMNP-TV-Z]{26}$/.test(s)
            ? ':id'
            : s,
    )
    .join('/');
}
