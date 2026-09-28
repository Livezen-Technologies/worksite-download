import { beforeEach, describe, expect, it } from 'vitest';
import { allowsAutomatic, cachedPolicy, isOptedOut, policyFromTpt, setOptOut, storePolicy } from '../src/policy';
import type { EffectivePolicy } from '../src/types';

const tpt = (claim: unknown) => btoa(JSON.stringify({ typ: 'ws-tpt+jws', payload: { tenant_id: 'tnt_1', bugtrace: claim } })).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const P = (policy: EffectivePolicy['policy'], extra: Partial<EffectivePolicy> = {}): EffectivePolicy => ({ policy, user_reports: true, screenshots: true, logs: true, user_opt_out_allowed: true, ...extra });

describe('reporting policy (§18.9, FR-BT-SDK-10, FR-BT-PRV-03)', () => {
  beforeEach(() => localStorage.clear());

  it('reads the bugtrace claim from the Tenant Policy Token', () => {
    expect(policyFromTpt(tpt({ policy: 'OFF', user_reports: true }))?.policy).toBe('OFF');
    expect(policyFromTpt(tpt({ policy: 'CRASHES_ONLY', screenshots: false }))).toMatchObject({ policy: 'CRASHES_ONLY', screenshots: false, user_opt_out_allowed: true });
    expect(policyFromTpt(tpt({ policy: 'CRASHES_ONLY', required_by_contract: true }))?.user_opt_out_allowed).toBe(false);
    expect(policyFromTpt('not-a-token')).toBeNull();
    expect(policyFromTpt(null)).toBeNull();
  });

  it('OFF means nothing automatic leaves the device; CRASHES_ONLY drops handled errors', () => {
    expect(allowsAutomatic(P('OFF'), 'crash', false)).toBe(false);
    expect(allowsAutomatic(P('CRASHES_ONLY'), 'crash', false)).toBe(true);
    expect(allowsAutomatic(P('CRASHES_ONLY'), 'error', false)).toBe(false);
    expect(allowsAutomatic(P('CRASHES_AND_ERRORS'), 'error', false)).toBe(true);
    expect(allowsAutomatic(null, 'crash', false)).toBe(false);
  });

  it('honours the user opt-out unless the tenant requires reporting', () => {
    setOptOut(true);
    expect(isOptedOut()).toBe(true);
    expect(allowsAutomatic(P('CRASHES_AND_ERRORS'), 'crash', isOptedOut())).toBe(false);
    expect(allowsAutomatic(P('CRASHES_ONLY', { user_opt_out_allowed: false }), 'crash', isOptedOut())).toBe(true);
    setOptOut(false);
    expect(isOptedOut()).toBe(false);
  });

  it('caches the fetched policy', () => {
    storePolicy('pk_x', P('CRASHES_ONLY'), 1000);
    expect(cachedPolicy('pk_x', 2000)?.policy).toBe('CRASHES_ONLY');
    expect(cachedPolicy('pk_x', 1000 + 8 * 86_400_000)).toBeNull();
  });
});
