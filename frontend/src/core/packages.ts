import type { Arch, Platform, PublicPackage } from '@/types/api';
import { archLabel, PACKAGE_LABEL, PLATFORM_LABEL } from './format';

export const PLATFORM_ORDER: Platform[] = ['windows', 'macos', 'linux', 'android', 'ios', 'server'];
const ARCH_ORDER: Record<string, number> = { x64: 0, arm64: 1, universal: 2, any: 3 };
const TYPE_ORDER: Record<string, number> = { exe: 0, msi: 1, dmg: 0, pkg: 1, zip: 2, appimage: 0, deb: 1, rpm: 2 };

/** Sort packages: recommended type first within each arch; macOS: Apple Silicon before Intel. */
export function sortPackages(pkgs: PublicPackage[]): PublicPackage[] {
  return [...pkgs].sort((a, b) => {
    const p = PLATFORM_ORDER.indexOf(a.platform) - PLATFORM_ORDER.indexOf(b.platform);
    if (p) return p;
    if (a.platform === 'macos') {
      const am = a.architecture === 'arm64' ? 0 : a.architecture === 'universal' ? 1 : 2;
      const bm = b.architecture === 'arm64' ? 0 : b.architecture === 'universal' ? 1 : 2;
      if (am !== bm) return am - bm;
    } else {
      const ar = (ARCH_ORDER[a.architecture] ?? 9) - (ARCH_ORDER[b.architecture] ?? 9);
      if (ar) return ar;
    }
    if (a.recommended !== b.recommended) return a.recommended ? -1 : 1;
    return (TYPE_ORDER[a.package_type] ?? 9) - (TYPE_ORDER[b.package_type] ?? 9);
  });
}

export function groupByPlatform(pkgs: PublicPackage[]): { platform: Platform; packages: PublicPackage[] }[] {
  const out: { platform: Platform; packages: PublicPackage[] }[] = [];
  for (const p of sortPackages(pkgs)) {
    let g = out.find((x) => x.platform === p.platform);
    if (!g) out.push((g = { platform: p.platform, packages: [] }));
    g.packages.push(p);
  }
  return out;
}

/** Short button label: "x64 · EXE", "Apple Silicon · DMG", "AppImage", "DEB · Ubuntu / Debian". */
export function packageButtonLabel(p: PublicPackage): string {
  const type = PACKAGE_LABEL[p.package_type];
  if (p.platform === 'linux') {
    if (p.package_type === 'deb') return 'DEB · Ubuntu / Debian';
    if (p.package_type === 'rpm') return 'RPM · Fedora / RHEL';
    return p.architecture === 'x64' ? type : `${type} · ${archLabel(p.platform, p.architecture)}`;
  }
  if (p.edition) return p.label;
  const hint = p.hint ? ` (${p.hint})` : '';
  return `${archLabel(p.platform, p.architecture)} · ${type}${hint}`;
}

export function packageLongLabel(p: PublicPackage): string {
  return p.label || `${PLATFORM_LABEL[p.platform]} ${archLabel(p.platform, p.architecture)} · ${PACKAGE_LABEL[p.package_type]}`;
}

/** Latest-stable resolver path (§5.1): /download/{slug}/{platform}/{arch}/{type}. */
export function latestResolverUrl(slug: string, p: { platform: Platform; architecture: Arch; package_type: string }): string {
  return `/download/${slug}/${p.platform}/${p.architecture}/${p.package_type}`;
}

export const ARCH_GUIDANCE: Partial<Record<Platform, string>> = {
  windows: 'Not sure? Most PCs are x64. Surface Pro X and Snapdragon laptops use ARM64 — Settings › System › About › System type.',
  macos: 'M1, M2, M3, M4 Macs are Apple Silicon. Check Apple menu › About This Mac › Chip.',
  linux: 'DEB/RPM installed from our repository update through your package manager.',
  server: 'Business and Enterprise editions are delivered to licensed installations via Admin Center › System › Updates.',
};

/** Choose the package the primary CTA should offer for a detected platform/arch. */
export function pickForPlatform(pkgs: PublicPackage[], platform: Platform | null, arch: Arch | null): PublicPackage | null {
  if (!platform) return null;
  const list = sortPackages(pkgs.filter((p) => p.platform === platform));
  if (!list.length) return null;
  const byArch = arch ? list.filter((p) => p.architecture === arch || p.architecture === 'universal') : list;
  const pool = byArch.length ? byArch : list;
  return pool.find((p) => p.recommended) ?? pool[0];
}
