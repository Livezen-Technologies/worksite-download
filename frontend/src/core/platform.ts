/**
 * Platform / architecture detection (§5.2). Uses UA Client Hints high-entropy values when available,
 * falls back to the User-Agent string, and never blocks rendering: the synchronous guess is used
 * immediately and refined asynchronously.
 */
import type { Arch, Platform } from '@/types/api';

export interface DetectedPlatform {
  platform: Platform | null;
  arch: Arch | null;
  /** true when the architecture was confirmed by client hints (not guessed). */
  archConfirmed: boolean;
  source: 'client-hints' | 'user-agent' | 'none';
}

export interface NavigatorLike {
  userAgent?: string;
  platform?: string;
  maxTouchPoints?: number;
  userAgentData?: {
    platform?: string;
    mobile?: boolean;
    getHighEntropyValues?: (hints: string[]) => Promise<{ platform?: string; architecture?: string; bitness?: string }>;
  };
}

export const NONE: DetectedPlatform = { platform: null, arch: null, archConfirmed: false, source: 'none' };

function platformFromName(name: string | undefined): Platform | null {
  if (!name) return null;
  const n = name.toLowerCase();
  if (n.includes('win')) return 'windows';
  if (n.includes('android')) return 'android';
  if (n.includes('iphone') || n.includes('ipad') || n === 'ios') return 'ios';
  if (n.includes('mac')) return 'macos';
  if (n.includes('cros') || n.includes('chrome os')) return 'linux';
  if (n.includes('linux') || n.includes('x11')) return 'linux';
  return null;
}

/** Synchronous best guess from the User-Agent string. */
export function detectFromUserAgent(nav: NavigatorLike): DetectedPlatform {
  const ua = nav.userAgent ?? '';
  if (!ua) return NONE;
  let platform: Platform | null = null;
  if (/Windows NT|Win64|WOW64|Windows/i.test(ua)) platform = 'windows';
  else if (/Android/i.test(ua)) platform = 'android';
  else if (/iPhone|iPad|iPod/i.test(ua)) platform = 'ios';
  else if (/Macintosh|Mac OS X/i.test(ua)) {
    // iPadOS 13+ reports as Macintosh but has touch points.
    platform = (nav.maxTouchPoints ?? 0) > 1 ? 'ios' : 'macos';
  } else if (/Linux|X11|CrOS/i.test(ua)) platform = 'linux';
  else platform = platformFromName(nav.platform);

  let arch: Arch | null = null;
  let confirmed = false;
  if (platform === 'windows') {
    if (/ARM64|aarch64/i.test(ua)) {
      arch = 'arm64';
      confirmed = true;
    } else arch = 'x64'; // Most PCs; ARM64 Windows browsers often report x64 in UA.
  } else if (platform === 'linux') {
    if (/aarch64|arm64|armv8/i.test(ua)) {
      arch = 'arm64';
      confirmed = true;
    } else arch = 'x64';
  } else if (platform === 'macos') {
    // UA always says "Intel Mac OS X" — cannot tell Apple Silicon apart without client hints.
    arch = null;
  }
  return { platform, arch, archConfirmed: confirmed, source: platform ? 'user-agent' : 'none' };
}

/** Async refinement via navigator.userAgentData.getHighEntropyValues (Chromium). */
export async function detectPlatform(nav: NavigatorLike | undefined = typeof navigator !== 'undefined' ? (navigator as NavigatorLike) : undefined): Promise<DetectedPlatform> {
  if (!nav) return NONE;
  const fallback = detectFromUserAgent(nav);
  const uad = nav.userAgentData;
  if (!uad?.getHighEntropyValues) return fallback;
  try {
    const hints = await Promise.race([
      uad.getHighEntropyValues(['platform', 'architecture', 'bitness']),
      new Promise<null>((resolve) => setTimeout(() => resolve(null), 800)),
    ]);
    if (!hints) return fallback;
    const platform = platformFromName(hints.platform || uad.platform) ?? fallback.platform;
    if (!platform) return fallback;
    let arch: Arch | null = fallback.arch;
    let confirmed = fallback.archConfirmed;
    const a = (hints.architecture || '').toLowerCase();
    if (a === 'arm') {
      arch = 'arm64';
      confirmed = true;
    } else if (a === 'x86') {
      arch = hints.bitness === '32' ? 'x64' : 'x64';
      confirmed = true;
    }
    return { platform, arch, archConfirmed: confirmed, source: 'client-hints' };
  } catch {
    return fallback;
  }
}
