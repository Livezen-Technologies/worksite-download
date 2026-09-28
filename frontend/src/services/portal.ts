import { api } from './api';
import type { PortalApp, PortalSubscription } from '@/types/api';

export const portalKeys = {
  downloads: ['portal', 'downloads'] as const,
  history: ['portal', 'history'] as const,
  subscriptions: ['portal', 'subscriptions'] as const,
  bugReports: ['portal', 'bug-reports'] as const,
};

export const portalApi = {
  downloads: () => api.get<{ apps: PortalApp[] }>('/portal/downloads'),
  history: () => api.get<{ data: { app: string; version: string; package: string; created_at: string }[] }>('/portal/history'),
  profile: (name: string) => api.put<unknown>('/portal/profile', { name }),
  beta: (opt_in: boolean) => api.post<unknown>('/portal/beta', { opt_in }),
  subscriptions: () => api.get<{ data?: PortalSubscription[]; items?: PortalSubscription[] }>('/portal/subscriptions'),
  saveSubscriptions: (items: PortalSubscription[]) => api.put<unknown>('/portal/subscriptions', { items }),
  bugReports: () => api.get<{ data: { reference: string; title: string; status: string; updated_at: string; fixed_in: string | null }[] }>('/portal/bug-reports'),
};
