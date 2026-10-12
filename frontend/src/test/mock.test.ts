import { beforeEach, describe, expect, it } from 'vitest';
import { expireStepUp, handleMock, resetMockState } from '@/services/mock/server';

function call(method: string, path: string, body?: unknown, headers: Record<string, string> = {}) {
  const [p, qs] = path.split('?');
  return handleMock({ method, path: p, query: new URLSearchParams(qs ?? ''), headers: { 'x-csrf-token': 'mock-csrf', ...headers }, body }).body as any;
}

describe('mock API (contract shapes + critical-action rules)', () => {
  beforeEach(() => {
    sessionStorage.clear();
    resetMockState();
    call('POST', '/auth/login', { email: 'r.fernando@livezen.lk', password: 'x' });
    call('POST', '/auth/mfa', { code: '123456' });
  });

  it('serves the public site with resolver download URLs and unsupported versions without links', () => {
    const r = call('GET', '/apps/desktop/releases/2.7.0');
    expect(r.packages[0].download_url).toMatch(/^\/download\/desktop\/2\.7\.0\//);
    const old = r.previous_versions.find((v: any) => v.support_state === 'UNSUPPORTED');
    expect(old.download_available).toBe(false);
    expect(call('GET', '/apps/desktop/releases/2.4.9').unavailable_message).toBe('Download unavailable. Please install 2.7.0.');
  });

  it('requires MFA to publish once the step-up window expired, then publishes with X-MFA-Token', () => {
    expireStepUp();
    expect(() => call('POST', '/admin/releases/rel_desktop_271/publish', {})).toThrow(/authenticator code/);
    expect(() => call('POST', '/admin/releases/rel_desktop_271/publish', {}, { 'x-mfa-token': '000000' })).toThrow(/not valid/);
    const published = call('POST', '/admin/releases/rel_desktop_271/publish', {}, { 'x-mfa-token': '654321' });
    expect(published.status).toBe('PUBLISHED');
    expect(call('GET', '/admin/releases/rel_desktop_270').status).toBe('SUPERSEDED');
  });

  it('rejects withdraw without the typed version', () => {
    expect(() => call('POST', '/admin/releases/rel_desktop_270/withdraw', { reason: 'x', confirm_version: '2.7' }, { 'x-mfa-token': '123456' })).toThrow(/Type the version/);
  });

  it('rejects mutations without the CSRF header', () => {
    expect(() => handleMock({ method: 'POST', path: '/admin/releases/rel_desktop_271/approve', query: new URLSearchParams(), headers: {}, body: {} })).toThrow(/session token/);
  });

  it('exposes allowed_actions and a checklist on the release detail', () => {
    const r = call('GET', '/admin/releases/rel_desktop_271');
    expect(r.allowed_actions).toContain('publish');
    expect(r.checklist.passed).toBe(true);
    const draft = call('GET', '/admin/releases/rel_installer_133');
    expect(draft.checklist.passed).toBe(false);
    expect(draft.allowed_actions).toContain('upload');
  });

  it('serves the server release console (DL-1…DL-4) in mock mode', () => {
    const list = call('GET', '/admin/server/releases?channel=STABLE');
    expect(list.kpis.linked).toBeGreaterThan(0);
    expect(list.data.every((r: any) => r.channel === 'STABLE' && r.severity && r.ring_summary)).toBe(true);
    const created = call('POST', '/admin/server/releases', { version: '3.5.0', build: 35001, channel: 'STABLE', severity: 'SECURITY', editions: ['BUSINESS'], migrations_reversible: true, notes: '## Security\n- Fix' });
    expect(created.severity).toBe('SECURITY');
    expect(call('GET', `/admin/releases/${created.id}`).packages.map((p: any) => p.edition)).toEqual(['BUSINESS']);
    const published = list.data.find((r: any) => r.status === 'PUBLISHED');
    const rollout = call('GET', `/admin/server/releases/${published.id}/rollout`);
    expect(rollout.rings).toHaveLength(4);
    expect(call('POST', `/admin/server/releases/${published.id}/rollout/pause`, { reason: 'x' }, { 'x-mfa-token': '123456' }).pause.kind).toBe('MANUAL');
    expect(call('GET', '/admin/installations/stats').segments.all).toBeGreaterThan(0);
    expect(call('GET', '/admin/installations?segment=below_minimum').data.every((i: any) => i.below_minimum)).toBe(true);
  });
});

