import type { EventType, SampleRates } from './types';

export const DEFAULT_RATES: SampleRates = { crash: 1, unhandled: 1, error: 0.25, message: 1, hang: 1 };

/**
 * Same fingerprint within the window counts, but is not re-sent (FR-BT-SDK-08).
 */
export class Deduper {
  private seen = new Map<string, { at: number; count: number }>();
  constructor(private windowMs = 60_000, private now: () => number = Date.now) {}

  /** Returns true when the event should be sent. */
  admit(key: string): boolean {
    const t = this.now();
    const hit = this.seen.get(key);
    if (hit && t - hit.at < this.windowMs) {
      hit.count++;
      return false;
    }
    this.seen.set(key, { at: t, count: 1 });
    if (this.seen.size > 500) {
      for (const [k, v] of this.seen) if (t - v.at >= this.windowMs) this.seen.delete(k);
    }
    return true;
  }

  /** How many duplicates were suppressed for a key in its current window. */
  suppressed(key: string): number {
    const hit = this.seen.get(key);
    return hit ? hit.count - 1 : 0;
  }
}

/** Rate for an event: crashes 100 %, unhandled 100 %, handled errors 25 % by default. */
export function rateFor(type: EventType, handled: boolean, rates: SampleRates): number {
  if (type === 'error' && !handled) return rates.unhandled;
  return rates[type] ?? 1;
}

export function sampled(rate: number, random: () => number = Math.random): boolean {
  return rate >= 1 || random() < rate;
}
