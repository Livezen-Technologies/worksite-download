import { describe, expect, it } from 'vitest';
import { detectFromUserAgent, detectPlatform, type NavigatorLike } from '@/core/platform';
import { pickForPlatform } from '@/core/packages';
import type { PublicPackage } from '@/types/api';

const WIN = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
const MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15';
const LINUX_ARM = 'Mozilla/5.0 (X11; Linux aarch64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';
const IPAD = MAC;

describe('detectFromUserAgent', () => {
  it('detects Windows x64 (unconfirmed arch)', () => {
    expect(detectFromUserAgent({ userAgent: WIN })).toMatchObject({ platform: 'windows', arch: 'x64', archConfirmed: false, source: 'user-agent' });
  });
  it('cannot tell Apple Silicon from Intel via UA alone', () => {
    expect(detectFromUserAgent({ userAgent: MAC })).toMatchObject({ platform: 'macos', arch: null });
  });
  it('treats a touch "Macintosh" as iPadOS', () => {
    expect(detectFromUserAgent({ userAgent: IPAD, maxTouchPoints: 5 }).platform).toBe('ios');
  });
  it('detects Linux ARM64', () => {
    expect(detectFromUserAgent({ userAgent: LINUX_ARM })).toMatchObject({ platform: 'linux', arch: 'arm64', archConfirmed: true });
  });
  it('returns none for an empty UA', () => {
    expect(detectFromUserAgent({}).platform).toBeNull();
  });
});

describe('detectPlatform (client hints)', () => {
  it('refines macOS to arm64 using high-entropy values', async () => {
    const nav: NavigatorLike = { userAgent: MAC, userAgentData: { platform: 'macOS', getHighEntropyValues: async () => ({ platform: 'macOS', architecture: 'arm', bitness: '64' }) } };
    await expect(detectPlatform(nav)).resolves.toMatchObject({ platform: 'macos', arch: 'arm64', archConfirmed: true, source: 'client-hints' });
  });
  it('confirms Windows on ARM even when the UA says x64', async () => {
    const nav: NavigatorLike = { userAgent: WIN, userAgentData: { platform: 'Windows', getHighEntropyValues: async () => ({ platform: 'Windows', architecture: 'arm', bitness: '64' }) } };
    await expect(detectPlatform(nav)).resolves.toMatchObject({ platform: 'windows', arch: 'arm64' });
  });
  it('falls back to the UA and never throws when client hints reject', async () => {
    const nav: NavigatorLike = { userAgent: WIN, userAgentData: { getHighEntropyValues: () => Promise.reject(new Error('denied')) } };
    await expect(detectPlatform(nav)).resolves.toMatchObject({ platform: 'windows', source: 'user-agent' });
  });
  it('does not block when client hints never resolve', async () => {
    const nav: NavigatorLike = { userAgent: WIN, userAgentData: { getHighEntropyValues: () => new Promise(() => {}) } };
    const t0 = Date.now();
    await expect(detectPlatform(nav)).resolves.toMatchObject({ platform: 'windows' });
    expect(Date.now() - t0).toBeLessThan(2000);
  });
});

describe('pickForPlatform', () => {
  const pkg = (platform: PublicPackage['platform'], architecture: PublicPackage['architecture'], package_type: PublicPackage['package_type'], recommended: boolean): PublicPackage => ({
    id: `${platform}-${architecture}-${package_type}`,
    platform,
    architecture,
    package_type,
    edition: null,
    label: '',
    file_name: '',
    size: 1,
    sha256: '',
    min_os: null,
    download_url: '',
    recommended,
    hint: null,
  });
  const pkgs = [pkg('windows', 'x64', 'msi', false), pkg('windows', 'x64', 'exe', true), pkg('windows', 'arm64', 'exe', true), pkg('macos', 'x64', 'dmg', true), pkg('macos', 'arm64', 'dmg', true)];
  it('prefers the recommended (default) package type — EXE over MSI', () => {
    expect(pickForPlatform(pkgs, 'windows', 'x64')?.id).toBe('windows-x64-exe');
  });
  it('honours the detected architecture', () => {
    expect(pickForPlatform(pkgs, 'windows', 'arm64')?.id).toBe('windows-arm64-exe');
    expect(pickForPlatform(pkgs, 'macos', 'arm64')?.id).toBe('macos-arm64-dmg');
  });
  it('returns null for unknown platforms', () => {
    expect(pickForPlatform(pkgs, 'linux', 'x64')).toBeNull();
    expect(pickForPlatform(pkgs, null, null)).toBeNull();
  });
});
