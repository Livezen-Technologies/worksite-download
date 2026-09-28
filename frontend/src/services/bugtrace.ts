import { api, type Query } from './api';
import type { BugAlert, IssueDetail, IssueListResponse, ReleaseHealth, ReportDetail, ReportSummary } from '@/types/api';

const B = '/admin/bugtrace';

export const bugKeys = {
  issues: (q: Query) => ['bt', 'issues', q] as const,
  issue: (id: string) => ['bt', 'issue', id] as const,
  reports: (q: Query) => ['bt', 'reports', q] as const,
  report: (ref: string) => ['bt', 'report', ref] as const,
  health: (q: Query) => ['bt', 'health', q] as const,
  alerts: ['bt', 'alerts'] as const,
  projects: ['bt', 'projects'] as const,
};

export type IssuePatch = {
  status?: string;
  resolution?: 'NOW' | 'NEXT_RELEASE' | 'VERSION';
  resolve_version?: string;
  priority?: string;
  assignee_id?: string | null;
  labels?: string[];
  ignore_until?: string;
  known_issue_public?: boolean;
};

export const bugtraceApi = {
  issues: (q: Query) => api.get<IssueListResponse>(`${B}/issues`, q),
  issue: (id: string) => api.get<IssueDetail>(`${B}/issues/${encodeURIComponent(id)}`),
  patchIssue: (id: string, body: IssuePatch) => api.patch<IssueDetail>(`${B}/issues/${encodeURIComponent(id)}`, body),
  mergeIssue: (id: string, into: string) => api.post<unknown>(`${B}/issues/${encodeURIComponent(id)}/merge`, { into }),
  comment: (id: string, body: string) => api.post<unknown>(`${B}/issues/${encodeURIComponent(id)}/comments`, { body }),
  link: (id: string, body: { kind: string; ref: string; url: string }) => api.post<unknown>(`${B}/issues/${encodeURIComponent(id)}/links`, body),
  bulk: (ids: string[], patch: IssuePatch) => api.post<unknown>(`${B}/issues/bulk`, { ids, patch }),
  reports: (q: Query) => api.get<{ data: ReportSummary[]; counts: Record<string, number> }>(`${B}/reports`, q),
  report: (ref: string) => api.get<ReportDetail>(`${B}/reports/${encodeURIComponent(ref)}`),
  patchReport: (ref: string, body: { status?: string; issue_id?: string; close_reason?: string }) => api.patch<ReportDetail>(`${B}/reports/${encodeURIComponent(ref)}`, body),
  reply: (ref: string, message: string) => api.post<unknown>(`${B}/reports/${encodeURIComponent(ref)}/reply`, { message, public: true }),
  createIssueFromReport: (ref: string) => api.post<{ id: string } & Partial<IssueDetail>>(`${B}/reports/${encodeURIComponent(ref)}/create-issue`),
  health: (q: Query) => api.get<ReleaseHealth>(`${B}/health`, q),
  alerts: () => api.get<{ data: BugAlert[] }>(`${B}/alerts`),
  createAlert: (body: Partial<BugAlert>) => api.post<BugAlert>(`${B}/alerts`, body),
  updateAlert: (id: string, body: Partial<BugAlert>) => api.patch<BugAlert>(`${B}/alerts/${id}`, body),
  deleteAlert: (id: string) => api.delete<unknown>(`${B}/alerts/${id}`),
  projects: () => api.get<{ data: { id: string; name: string; platform: string }[] }>(`${B}/projects`),
};
