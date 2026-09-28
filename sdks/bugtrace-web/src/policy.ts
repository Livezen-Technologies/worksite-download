import type { EffectivePolicy } from './types';

const DAY = 86_400_000;
const OPT_OUT = 'bt:optout';

/** Read the `bugtrace` claim straight from the TPT payload (no network needed). */
export function policyFromTpt(tpt: string | null | undefined): EffectivePolicy | null {
  if (!tpt) return null;
  try {
    const json = tpt.trim().startsWith('{') ? tpt : atob(tpt.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(tpt.length / 4) * 4, '='));
    const c = JSON.parse(json)?.payload?.bugtrace;
    if (!c || !['OFF', 'CRASHES_ONLY', 'CRASHES_AND_ERRORS'].includes(c.policy)) return null;
    return {
      policy: c.policy,
      user_reports: c.user_reports !== false,
      screenshots: c.screenshots !== false,
      logs: c.logs !== false,
      server_reporting: c.server_reporting !== false,
      user_opt_out_allowed: !c.required_by_contract,
      source: 'tpt',
    };
  } catch {
    return null;
  }
}

export function allowsAutomatic(p: EffectivePolicy | null, type: string, optedOut: boolean): boolean {
  if (!p || p.policy === 'OFF') return false; // unknown policy → keep locally, send nothing
  if (optedOut && p.user_opt_out_allowed) return false;
  return p.policy === 'CRASHES_AND_ERRORS' || type === 'crash' || type === 'hang';
}

function storage(): Storage | null {
  try {
    return typeof localStorage !== 'undefined' ? localStorage : null;
  } catch {
    return null;
  }
}

export function cachedPolicy(key: string, now = Date.now()): EffectivePolicy | null {
  try {
    const raw = storage()?.getItem('bt:policy:' + key);
    if (!raw) return null;
    const p = JSON.parse(raw) as EffectivePolicy & { _at: number };
    return now - p._at < 7 * DAY ? p : null; // usable while offline for up to the TPT validity
  } catch {
    return null;
  }
}

export function isFresh(key: string, now = Date.now()): boolean {
  try {
    const p = JSON.parse(storage()?.getItem('bt:policy:' + key) || 'null');
    return !!p && now - p._at < (p.ttl ? p.ttl * 1000 : DAY);
  } catch {
    return false;
  }
}

export function storePolicy(key: string, p: EffectivePolicy, now = Date.now()): void {
  try {
    storage()?.setItem('bt:policy:' + key, JSON.stringify({ ...p, _at: now }));
  } catch {
    /* private mode */
  }
}

export function isOptedOut(): boolean {
  return storage()?.getItem(OPT_OUT) === '1';
}

export function setOptOut(v: boolean): void {
  try {
    v ? storage()?.setItem(OPT_OUT, '1') : storage()?.removeItem(OPT_OUT);
  } catch {
    /* ignore */
  }
}
