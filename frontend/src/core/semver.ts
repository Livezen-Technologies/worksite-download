/** Minimal SemVer compare (major.minor.patch[-pre]). */
export function parseSemver(v: string): [number, number, number, string] | null {
  const m = /^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?(?:\+[0-9A-Za-z.-]+)?$/.exec(v.trim());
  if (!m) return null;
  return [Number(m[1]), Number(m[2]), Number(m[3]), m[4] ?? ''];
}

export function isSemver(v: string): boolean {
  return parseSemver(v) !== null;
}

export function compareSemver(a: string, b: string): number {
  const pa = parseSemver(a);
  const pb = parseSemver(b);
  if (!pa || !pb) return a.localeCompare(b);
  for (let i = 0; i < 3; i++) if (pa[i] !== pb[i]) return (pa[i] as number) - (pb[i] as number);
  if (pa[3] === pb[3]) return 0;
  if (!pa[3]) return 1;
  if (!pb[3]) return -1;
  return pa[3].localeCompare(pb[3], undefined, { numeric: true });
}
