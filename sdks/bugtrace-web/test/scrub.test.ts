import { describe, expect, it } from 'vitest';
import { FILTERED, luhn, Scrubber, templatePath } from '../src/scrub';

describe('client-side scrubbing (§18.8)', () => {
  const seeds = [
    'kasun.perera@example.lk',
    'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U',
    'hunter2',
    '901234567V',
    '199012345678',
    '4111 1111 1111 1111',
    '+94 77 123 4567',
    '0771234567',
    '192.168.10.20',
    'sk_live_51HxYzAbCdEfGhIjKlMnOp',
  ];

  it('removes seeded secrets and personal data from strings', () => {
    const s = new Scrubber();
    const text = `user kasun.perera@example.lk token eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PlFUP0THsR8U password=hunter2 NIC 901234567V / 199012345678 card 4111 1111 1111 1111 call +94 77 123 4567 or 0771234567 from 192.168.10.20 key sk_live_51HxYzAbCdEfGhIjKlMnOp`;
    const out = s.str(text);
    for (const seed of seeds) expect(out).not.toContain(seed);
    expect(s.count).toBeGreaterThanOrEqual(10);
  });

  it('keeps versions, times and non-Luhn numbers', () => {
    const s = new Scrubber();
    expect(s.str('version 3.4.0 build 10.0.19045.1 at 09:41:10')).toBe('version 3.4.0 build 10.0.19045.1 at 09:41:10');
    expect(s.str('order 4242 4242 4242 4241')).toBe('order 4242 4242 4242 4241');
    expect(luhn('4111111111111111')).toBe(true);
    expect(luhn('4111111111111112')).toBe(false);
  });

  it('filters sensitive keys, content keys and IPs in objects', () => {
    const s = new Scrubber();
    const out = s.value({ password: 'x', Authorization: 'Bearer abc', 'X-WS-Tenant': 'secret', subject: 'Salary', ip: '1.2.3.4', url: 'https://x.lk/mail/88?q=salary', ok: 'fine' }) as Record<string, unknown>;
    expect(out).toEqual({ password: FILTERED, Authorization: FILTERED, 'X-WS-Tenant': FILTERED, subject: FILTERED, url: 'https://x.lk/mail/:id', ok: 'fine' });
  });

  it('templates numeric and UUID path segments and strips query strings', () => {
    expect(templatePath('/api/mail/folders/88/messages/3f2a8c1e-1b2c-4d5e-8f90-123456789abc')).toBe('/api/mail/folders/:id/messages/:uuid');
    expect(new Scrubber().url('https://x.lk/a/12?token=abc#f')).toBe('https://x.lk/a/:id');
  });

  it('applies custom patterns', () => {
    const s = new Scrubber([/EMP-\d{5}/]);
    expect(s.str('employee EMP-12345')).toBe(`employee ${FILTERED}`);
  });
});
