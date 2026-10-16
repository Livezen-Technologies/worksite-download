import { api } from './api';
import type { PortalApp, PortalSubscription } from '@/types/api';

export const portalKeys = {
  downloads: ['portal', 'downloads'] as const,
  history: ['portal', 'history'] as const,
  subscriptions: ['portal', 'subscriptions'] as const,
  bugReports: ['portal', 'bug-reports'] as const,
  licences: ['portal', 'licences'] as const,
  licence: (id: string) => ['portal', 'licences', id] as const,
};

export interface PortalLicenceActivation {
  id: number;
  installation_id: string;
  hostname: string | null;
  installation_type?: string;
  activated_at: string | null;
  last_validated_at: string | null;
  deactivated_at: string | null;
  active: boolean;
}

export interface PortalLicence {
  id: string;
  license_key_masked: string;
  license_key?: string;
  organization: string;
  edition: 'FREE' | 'BUSINESS' | 'ENTERPRISE';
  state: string;
  max_activations: number;
  active_activations: number;
  expires_at: string | null;
  updates_until: string | null;
  is_trial: boolean;
  key_revealable: boolean;
  activations?: PortalLicenceActivation[];
  entitlements?: Record<string, boolean | number | string | null>;
  history?: { event: string; at: string | null }[];
}

export interface EditionPreview {
  from: string;
  to: string;
  adds: { key: string; label: string }[];
  removes: { key: string; label: string }[];
}

export const portalApi = {
  downloads: () => api.get<{ apps: PortalApp[] }>('/portal/downloads'),
  history: () => api.get<{ data: { app: string; version: string; package: string; created_at: string }[] }>('/portal/history'),
  profile: (name: string) => api.put<unknown>('/portal/profile', { name }),
  beta: (opt_in: boolean) => api.post<unknown>('/portal/beta', { opt_in }),
  subscriptions: () => api.get<{ data?: PortalSubscription[]; items?: PortalSubscription[] }>('/portal/subscriptions'),
  saveSubscriptions: (items: PortalSubscription[]) => api.put<unknown>('/portal/subscriptions', { items }),
  bugReports: () => api.get<{ data: { reference: string; title: string; status: string; updated_at: string; fixed_in: string | null }[] }>('/portal/bug-reports'),
  licences: () => api.get<{ data: PortalLicence[]; trial_available: boolean }>('/portal/licenses'),
  licence: (id: string) => api.get<PortalLicence>(`/portal/licenses/${id}`),
  reveal: (id: string) => api.post<{ license_key: string }>(`/portal/licenses/${id}/reveal`, {}),
  replaceKey: (id: string) => api.post<PortalLicence>(`/portal/licenses/${id}/replace-key`, {}),
  deactivate: (id: string, activationId: number, reason: string) => api.post<PortalLicence>(`/portal/licenses/${id}/activations/${activationId}/deactivate`, { reason }),
  offline: (id: string, request: unknown) => api.post<Record<string, unknown>>(`/portal/licenses/${id}/offline`, { request }),
  editionPreview: (id: string, edition: string) => api.get<EditionPreview>(`/portal/licenses/${id}/edition-preview`, { edition }),
  startTrial: (edition: string, organization: string) => api.post<PortalLicence>('/portal/licenses/trial', { edition, organization }),
};
