import { api } from './api';
import type {
  AppDetail,
  AppSummary,
  ChangelogEntry,
  ChannelCode,
  ListResponse,
  PreviousVersion,
  PublicChannel,
  PublicRelease,
  PublicReleaseDetail,
  SiteInfo,
} from '@/types/api';

/** Query keys shared by the client, the SSR entry and the prerender script. */
export const publicKeys = {
  site: ['site'] as const,
  apps: ['apps'] as const,
  app: (slug: string) => ['app', slug] as const,
  release: (slug: string, version: string) => ['release', slug, version] as const,
  releases: (slug: string, channel?: string) => ['releases', slug, channel ?? 'STABLE'] as const,
  versions: (slug: string) => ['versions', slug] as const,
  appChangelog: (slug: string, offset = 0) => ['changelog', slug, offset] as const,
  changelog: (offset = 0) => ['changelog', '*', offset] as const,
  channels: ['channels'] as const,
};

export const PAGE_SIZE = 10;

export const publicApi = {
  site: () => api.get<SiteInfo>('/site'),
  apps: () => api.get<{ data: AppSummary[] }>('/apps'),
  app: (slug: string) => api.get<AppDetail>(`/apps/${encodeURIComponent(slug)}`),
  releases: (slug: string, channel: ChannelCode = 'STABLE', limit = 50, offset = 0) =>
    api.get<ListResponse<PublicRelease>>(`/apps/${encodeURIComponent(slug)}/releases`, { channel, limit, offset }),
  release: (slug: string, version: string, channel?: ChannelCode) =>
    api.get<PublicReleaseDetail>(`/apps/${encodeURIComponent(slug)}/releases/${encodeURIComponent(version)}`, { channel }),
  versions: (slug: string) => api.get<{ data: PreviousVersion[] }>(`/apps/${encodeURIComponent(slug)}/versions`),
  appChangelog: (slug: string, offset = 0, limit = PAGE_SIZE) =>
    api.get<ListResponse<ChangelogEntry>>(`/apps/${encodeURIComponent(slug)}/changelog`, { limit, offset }),
  changelog: (offset = 0, limit = PAGE_SIZE) => api.get<ListResponse<ChangelogEntry>>('/changelog', { limit, offset }),
  channels: () => api.get<{ data: PublicChannel[] }>('/channels'),
  subscribe: (email: string, app: string, channel: ChannelCode) => api.post<{ status: string }>('/subscriptions', { email, app, channel }),
  confirmSubscription: (token: string) => api.get<{ status: string }>('/subscriptions/confirm', { token }),
  unsubscribe: (token: string) => api.get<{ status: string }>('/subscriptions/unsubscribe', { token }),
};
