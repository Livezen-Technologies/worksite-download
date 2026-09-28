import type { Me } from '@/types/api';

export function can(user: Me | null | undefined, permission: string | string[] | undefined): boolean {
  if (!permission) return true;
  if (!user) return false;
  const perms = Array.isArray(permission) ? permission : [permission];
  if (perms.length === 0) return true;
  return perms.some((p) => user.permissions.includes(p));
}
