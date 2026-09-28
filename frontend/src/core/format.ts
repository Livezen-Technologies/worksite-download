import type { Arch, PackageType, Platform } from '@/types/api';

export function formatBytes(bytes: number | null | undefined, digits = 0): string {
  if (bytes == null || !Number.isFinite(bytes)) return '—';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let v = bytes;
  let i = 0;
  while (v >= 1000 && i < units.length - 1) {
    v /= 1000;
    i++;
  }
  // "320 MB", "2.8 GB"
  const d = i >= 3 && v < 100 ? Math.max(digits, 1) : digits;
  return `${v.toFixed(d).replace(/\.0+$/, '')} ${units[i]}`;
}

export function formatNumber(n: number | null | undefined): string {
  if (n == null) return '—';
  return new Intl.NumberFormat('en-US').format(n);
}

export function compactNumber(n: number | null | undefined): string {
  if (n == null) return '—';
  if (n < 10000) return formatNumber(n);
  return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
}

export function formatPct(n: number | null | undefined, digits = 0): string {
  if (n == null) return '—';
  return `${n.toFixed(digits)}%`;
}

const LONG = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
const SHORT = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
const DT = new Intl.DateTimeFormat('en-GB', {
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'UTC',
});

function toDate(v: string | null | undefined): Date | null {
  if (!v) return null;
  const d = new Date(v.length === 10 ? `${v}T00:00:00Z` : v);
  return Number.isNaN(d.getTime()) ? null : d;
}

/** "27 September 2026" */
export function formatDateLong(v: string | null | undefined): string {
  const d = toDate(v);
  return d ? LONG.format(d) : '—';
}
/** "27 Sep 2026" */
export function formatDate(v: string | null | undefined): string {
  const d = toDate(v);
  return d ? SHORT.format(d) : '—';
}
/** "27 Sep, 11:20" (UTC) */
export function formatDateTime(v: string | null | undefined): string {
  const d = toDate(v);
  return d ? `${DT.format(d)} UTC` : '—';
}

export function timeAgo(v: string | null | undefined, now: Date = new Date()): string {
  const d = toDate(v);
  if (!d) return '—';
  const s = Math.round((now.getTime() - d.getTime()) / 1000);
  if (s < 60) return `${Math.max(s, 0)} s ago`;
  const m = Math.round(s / 60);
  if (m < 60) return `${m} min ago`;
  const h = Math.round(m / 60);
  if (h < 48) return `${h} h ago`;
  return `${Math.round(h / 24)} d ago`;
}

export function shortHash(h: string | null | undefined, n = 4): string {
  if (!h) return '—';
  const clean = h.replace(/^sha256:/, '');
  return clean.length <= n * 2 + 1 ? clean : `${clean.slice(0, n)}…${clean.slice(-n)}`;
}

export const PLATFORM_LABEL: Record<Platform, string> = {
  windows: 'Windows',
  macos: 'macOS',
  linux: 'Linux',
  android: 'Android',
  ios: 'iOS',
  server: 'Server',
};

export function archLabel(platform: Platform, arch: Arch): string {
  if (platform === 'macos') return arch === 'arm64' ? 'Apple Silicon' : arch === 'x64' ? 'Intel' : arch === 'universal' ? 'Universal' : arch;
  if (arch === 'arm64') return 'ARM64';
  if (arch === 'any') return 'Any';
  if (arch === 'universal') return 'Universal';
  return arch;
}

export const PACKAGE_LABEL: Record<PackageType, string> = {
  exe: 'EXE',
  msi: 'MSI',
  dmg: 'DMG',
  pkg: 'PKG',
  zip: 'ZIP',
  appimage: 'AppImage',
  deb: 'DEB',
  rpm: 'RPM',
  apk: 'APK',
  aab: 'AAB',
  ipa: 'IPA',
};

export function titleCase(s: string): string {
  return s
    .toLowerCase()
    .split(/[_\s]+/)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join(' ');
}

export function cx(...parts: (string | false | null | undefined)[]): string {
  return parts.filter(Boolean).join(' ');
}
