import { describe, expect, it } from 'vitest';
import { Deduper, DEFAULT_RATES, rateFor, sampled } from '../src/limits';

describe('de-duplication (60 s) and sampling (FR-BT-SDK-08)', () => {
  it('counts but does not re-send the same fingerprint within 60 s', () => {
    let now = 1_000_000;
    const d = new Deduper(60_000, () => now);
    expect(d.admit('a')).toBe(true);
    now += 10_000;
    expect(d.admit('a')).toBe(false);
    expect(d.admit('a')).toBe(false);
    expect(d.suppressed('a')).toBe(2);
    expect(d.admit('b')).toBe(true);
    now += 60_000;
    expect(d.admit('a')).toBe(true);
  });

  it('uses 100 % for crashes and unhandled errors, 25 % for handled errors', () => {
    expect(rateFor('crash', false, DEFAULT_RATES)).toBe(1);
    expect(rateFor('error', false, DEFAULT_RATES)).toBe(1);
    expect(rateFor('error', true, DEFAULT_RATES)).toBe(0.25);
    expect(rateFor('message', true, DEFAULT_RATES)).toBe(1);
  });

  it('samples deterministically with an injected random source', () => {
    expect(sampled(0.25, () => 0.1)).toBe(true);
    expect(sampled(0.25, () => 0.3)).toBe(false);
    expect(sampled(1, () => 0.99)).toBe(true);
    let kept = 0;
    let seed = 1;
    const rnd = () => ((seed = (seed * 16807) % 2147483647) / 2147483647);
    for (let i = 0; i < 4000; i++) if (sampled(0.25, rnd)) kept++;
    expect(kept).toBeGreaterThan(800);
    expect(kept).toBeLessThan(1200);
  });
});
