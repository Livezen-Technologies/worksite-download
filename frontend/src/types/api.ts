/**
 * Types mirroring docs/api-contract.md (frontend ↔ backend). Keep in sync with the contract;
 * anything the contract leaves open is marked "assumption" and listed in README.md.
 */

export type Platform = 'windows' | 'macos' | 'linux' | 'android' | 'ios' | 'server';
export type Arch = 'x64' | 'arm64' | 'universal' | 'any';
export type PackageType = 'exe' | 'msi' | 'dmg' | 'pkg' | 'zip' | 'appimage' | 'deb' | 'rpm' | 'apk' | 'aab' | 'ipa';
export type ChannelCode = 'STABLE' | 'BETA' | 'DEV' | 'INTERNAL' | 'CUSTOM' | 'LTS';
export type ReleaseStatus =
  | 'DRAFT'
  | 'UPLOADING'
  | 'READY_FOR_TEST'
  | 'TESTING'
  | 'APPROVED'
  | 'PUBLISHED'
  | 'PAUSED'
  | 'WITHDRAWN'
  | 'SUPERSEDED';
export type ReleaseType = 'OPTIONAL' | 'RECOMMENDED' | 'MANDATORY' | 'CRITICAL';
export type SupportState = 'SUPPORTED' | 'DEPRECATED' | 'UNSUPPORTED' | 'BLOCKED';
export type Edition = 'FREE' | 'BUSINESS' | 'ENTERPRISE' | 'ALL';
export type Environment = 'PRODUCTION' | 'STAGING' | 'DEV';
export type AppKind = 'DESKTOP' | 'SERVER' | 'MOBILE' | 'OTHER';
export type AppStatus = 'ACTIVE' | 'HIDDEN' | 'RETIRED';

export const RELEASE_STATUSES: ReleaseStatus[] = [
  'DRAFT',
  'UPLOADING',
  'READY_FOR_TEST',
  'TESTING',
  'APPROVED',
  'PUBLISHED',
  'PAUSED',
  'WITHDRAWN',
  'SUPERSEDED',
];

// ---------------------------------------------------------------- errors & lists

export interface ApiErrorBody {
  error: { code: string; message: string; details?: Record<string, unknown> | null };
}

export interface ListResponse<T> {
  data: T[];
  total: number;
  limit?: number;
  offset?: number;
}

// ---------------------------------------------------------------- shared/public

export interface AppPlatform {
  platform: Platform;
  architecture: Arch;
  package_types: PackageType[];
  default_package_type: PackageType | null;
  required_for_publish: boolean;
  min_os_version: string | null;
}

export interface AppSummary {
  id: string;
  slug: string;
  name: string;
  app_identifier: string;
  kind: AppKind;
  description: string;
  icon: string | null;
  status: AppStatus;
  public_download_enabled: boolean;
  private: boolean;
  latest_stable: { release_id: string; version: string; build: number; published_at: string; release_date: string | null } | null;
  latest_beta: { release_id: string; version: string; build: number; published_at: string } | null;
  platforms: AppPlatform[];
}

export interface PublicPackage {
  id: string;
  platform: Platform;
  architecture: Arch;
  package_type: PackageType;
  edition: Edition | null;
  label: string;
  file_name: string;
  size: number;
  sha256: string;
  min_os: string | null;
  download_url: string;
  recommended: boolean;
  hint: string | null;
}

export interface ChangelogSections {
  new_features: string[];
  improvements: string[];
  bug_fixes: string[];
  performance: string[];
  security: string[];
  known_issues: string[];
  deprecated: string[];
  removed: string[];
}

export interface Changelog {
  locale: string;
  summary: string | null;
  highlights: string[];
  sections: ChangelogSections;
  markdown: string;
  html: string;
  version: number;
  updated_at: string | null;
}

export interface PublicRelease {
  id: string;
  version: string;
  build: number;
  channel: ChannelCode;
  release_type: ReleaseType;
  support_state: SupportState;
  release_date: string | null;
  published_at: string | null;
  is_latest: boolean;
  summary: string | null;
  notes_url: string;
}

export interface PreviousVersion {
  version: string;
  build: number;
  channel: ChannelCode;
  release_date: string | null;
  support_state: SupportState;
  download_available: boolean;
  notes_url: string;
  is_latest: boolean;
}

export interface PublicReleaseDetail extends PublicRelease {
  changelog: Changelog;
  packages: PublicPackage[];
  checksums_url: string;
  manifest_url: string;
  download_available: boolean;
  unavailable_message: string | null;
  requirements: Record<string, string[]>;
  previous_versions: PreviousVersion[];
  app: AppSummary;
}

export interface DownloadPage {
  hero: { title: string; subtitle: string; screenshot?: string | null };
  description_md: string;
  description_html: string;
  requirements: Record<string, string[]>;
  install_help: Record<string, { markdown: string; html: string }>;
  faq: { q: string; a: string; a_html: string }[];
  support_url: string | null;
  version: number;
  status: 'DRAFT' | 'PUBLISHED';
}

export interface PublicChannel {
  code: ChannelCode;
  name: string;
  description: string;
  is_public: boolean;
  allow_direct_download?: boolean;
}

export interface SiteInfo {
  environment: Environment;
  name: string;
  apps: AppSummary[];
}

export type AppDetail = AppSummary & {
  page: DownloadPage;
  latest: PublicReleaseDetail | null;
  /** The current Beta release when this viewer may see Beta and it is newer than Stable. */
  beta?: PublicReleaseDetail | null;
  channels: PublicChannel[];
};

export type ChangelogEntry = PublicRelease & { changelog: Changelog; app?: { slug: string; name: string } };

export interface AuditEntry {
  id: number;
  actor_type: string;
  actor_id: string | null;
  actor_label: string | null;
  action: string;
  entity_type: string | null;
  entity_id: string | null;
  before: any;
  after: any;
  reason: string | null;
  ip: string | null;
  created_at: string;
  hash: string;
}

// ---------------------------------------------------------------- auth

export type MfaMethod = 'totp' | 'passkey';

export interface Me {
  id: string;
  email: string;
  name: string;
  kind: 'STAFF' | 'PORTAL';
  mfa_enabled: boolean;
  passkeys: number;
  roles: string[];
  permissions: string[];
  beta_opt_in: boolean;
}

export interface LoginResponse {
  mfa_required: boolean;
  csrf: string;
  user: Me | null;
  methods: MfaMethod[];
}

export interface SessionResponse {
  user: Me;
  csrf: string;
}

export interface MeResponse {
  user: Me;
  csrf: string;
  environment: Environment;
  step_up_valid_until: string | null;
}

// ---------------------------------------------------------------- admin shell

export type HealthStatus = 'OK' | 'DEGRADED' | 'DOWN';
export interface HealthComponent {
  component: 'api' | 'database' | 'redis' | 'storage' | 'cdn' | 'signing' | 'queue';
  status: HealthStatus;
  latency_ms: number | null;
  detail: string;
  checked_at: string;
}

export interface Dashboard {
  environment: Environment;
  kpis: {
    apps: number;
    apps_public: number;
    releases: number;
    releases_testing: number;
    releases_draft: number;
    downloads_30d: number;
    downloads_redirect_ok_30d: number;
    active_devices: number;
  };
  latest_releases: { id: string; app_name: string; app_slug: string; version: string; channel: ChannelCode; status: ReleaseStatus }[];
  version_distribution: {
    app_name: string;
    items: { version: string; count: number; pct: number }[];
    current_pct: number;
    update_available_pct: number;
    unsupported_pct: number;
  };
  downloads: { release_id: string; app_name: string; version: string; items: { label: string; count: number }[] } | null;
  active_rollouts: {
    release_id: string;
    app_name: string;
    version: string;
    channel: ChannelCode;
    stage_no: number;
    stages: number[];
    percentage: number;
    success_rate: number | null;
    status: string;
  }[];
  recent_activity: AuditEntry[];
  health: { ok: boolean; components: HealthComponent[] };
  notifications_unread: number;
}

export interface AdminNotification {
  id: string;
  kind: string;
  payload: { title: string; body: string; link?: string };
  read_at: string | null;
  created_at: string;
}

export interface HealthReport {
  ok: boolean;
  environment: Environment;
  components: HealthComponent[];
  queue: { backlog: number; oldest_age_s: number; failed_24h: number };
}

// ---------------------------------------------------------------- applications

export interface ManifestSettings {
  schema: number;
  key_id: string | null;
  response_key_id: string | null;
  update_api_enabled: boolean;
}

export interface SecuritySettings {
  private: boolean;
  requires_license_token: boolean;
  malware_scan_required: boolean;
  signatures: Partial<Record<Platform, string>>;
}

export type AdminApp = AppSummary & {
  default_channel: ChannelCode;
  channels: { code: ChannelCode; enabled: boolean }[];
  manifest_settings: ManifestSettings;
  security_settings: SecuritySettings;
  show_previous_versions: boolean;
  releases_count: number;
  created_at: string;
};

export interface CreateAppBody {
  name: string;
  slug: string;
  app_identifier: string;
  description: string;
  icon?: string | null;
  status: AppStatus;
  kind: AppKind;
  platforms: Omit<AppPlatform, never>[];
  channels: ChannelCode[];
  default_channel: ChannelCode;
  public_download_enabled: boolean;
  show_previous_versions: boolean;
  manifest_settings: ManifestSettings;
  security_settings: SecuritySettings;
}

export interface MinimumVersion {
  channel: ChannelCode;
  platform: Platform | null;
  minimum_version: string;
  enforce_from: string | null;
  message: string | null;
  updated_at: string | null;
}

export interface MinimumVersionsResponse {
  data: MinimumVersion[];
  history: (MinimumVersion & { before_version: string | null; reason: string | null; updated_by: string | null; created_at: string })[];
}

export interface MinimumImpact {
  active_devices: number;
  update_required: number;
  unsupported: number;
  by_version: { version: string; count: number }[];
}

export interface DownloadPageAdmin {
  published: DownloadPage;
  draft: DownloadPage | null;
  history: { version: number; updated_by: string | null; created_at: string }[];
}

export interface DownloadPageDraftBody {
  hero: DownloadPage['hero'];
  description_md: string;
  requirements: Record<string, string[]>;
  install_help: Record<string, string>;
  faq: { q: string; a: string }[];
  support_url: string | null;
}

export interface AdminChannel {
  id: string;
  code: ChannelCode;
  name: string;
  description: string;
  is_public: boolean;
  is_default: boolean;
  allow_direct_download: boolean;
  allow_auto_update: boolean;
  access_rule: Record<string, unknown> | null;
  retention: Record<string, unknown> | null;
}

// ---------------------------------------------------------------- releases

export interface AdminRelease {
  id: string;
  app: { id: string; slug: string; name: string; kind: AppKind };
  version: string;
  build: number;
  build_revision: number | string | null;
  channel: ChannelCode;
  release_type: ReleaseType;
  status: ReleaseStatus;
  support_state: SupportState;
  visibility: 'PUBLIC' | 'HIDDEN';
  minimum_supported_version: string | null;
  minimum_no_change: boolean;
  mandatory: boolean;
  mandatory_after: string | null;
  grace_minutes: number | null;
  rollback_supported: boolean;
  release_date: string | null;
  scheduled_publish_at: string | null;
  published_at: string | null;
  published_by: string | null;
  created_by: string | null;
  withdrawn_at: string | null;
  withdraw_reason: string | null;
  superseded_by: { id: string; version: string } | null;
  notes: string | null;
  known_issues: string | null;
  auto_pause_on_gate: boolean;
  last_error: string | null;
  created_at: string;
  updated_at: string;
  packages_ready: number;
  packages_total: number;
}

export interface ReleaseListResponse extends ListResponse<AdminRelease> {
  counts: Partial<Record<ReleaseStatus, number>>;
}

export type ChecklistStatus = 'pass' | 'fail' | 'pending' | 'skipped';
export interface ChecklistItem {
  key: string;
  label: string;
  status: ChecklistStatus;
  blocking: boolean;
  detail: string | null;
}
export interface Checklist {
  passed: boolean;
  items: ChecklistItem[];
}

/** Assumption: the contract does not spell out Approval; this is the shape the UI reads. */
export interface Approval {
  kind: 'QA' | 'RELEASE' | string;
  decision: 'PASS' | 'FAIL' | 'APPROVED' | string;
  platform: Platform | null;
  user: string;
  comment: string | null;
  created_at: string;
}

export type PipelineStepName = 'upload' | 'quarantine' | 'scan' | 'sha256' | 'metadata' | 'signature' | 'attestation' | 'move' | 'ready';
export interface PipelineStep {
  step: PipelineStepName;
  status: 'pending' | 'running' | 'done' | 'failed' | 'skipped';
  detail: string | null;
  at: string | null;
}

export interface ServerValidationItem {
  check: string;
  status: 'pass' | 'fail' | 'pending' | 'warn' | string;
  detail: string | null;
}

export interface WorksuitePackageJson {
  schema?: number;
  product?: string;
  version?: string;
  build?: number;
  edition?: string;
  scope?: string;
  min_from_version?: string;
  modules?: string[];
  requires?: Record<string, unknown>;
  migrations?: { count: number; one_way: string[] };
  desktop_compat?: string;
  [key: string]: unknown;
}

export interface Revision {
  id: string;
  revision_number: number;
  file_name: string;
  size: number;
  sha256: string | null;
  status: 'UPLOADING' | 'PROCESSING' | 'READY' | 'REJECTED' | 'REPLACED';
  reject_code: string | null;
  signature_status: string | null;
  signature_detail: string | null;
  scan_status: string | null;
  scan_detail: string | null;
  attestation_match: boolean | null;
  metadata: { package_json?: WorksuitePackageJson; validation?: ServerValidationItem[]; [key: string]: unknown };
  pipeline: PipelineStep[];
  replace_reason: string | null;
  uploaded_by: string | null;
  uploaded_at: string | null;
}

export interface AdminPackage {
  id: string;
  platform: Platform;
  architecture: Arch;
  package_type: PackageType;
  edition: Edition | null;
  scope: 'full' | 'core-patch' | null;
  status: 'EMPTY' | 'PROCESSING' | 'READY' | 'REJECTED';
  health: 'OK' | 'DEGRADED' | 'UNKNOWN';
  minimum_os_version: string | null;
  active_revision: Revision | null;
  /** A newer revision still being processed (shown live while validation runs). */
  processing_revision?: Revision | null;
  revisions: Revision[];
}

export interface ManifestRecord {
  id: string;
  manifest_version: number;
  key_id: string | null;
  signature_valid: boolean | null;
  sha256: string | null;
  generated_at: string | null;
  published_at: string | null;
  json: object;
}

export interface ManifestInfo {
  release: ManifestRecord | null;
  channel: ManifestRecord | null;
  history: {
    id: string;
    kind: string;
    manifest_version: number;
    key_id: string | null;
    generated_at: string | null;
    published_at: string | null;
    superseded_at: string | null;
    reason: string | null;
  }[];
  diff?: { path: string; before: unknown; after: unknown }[];
}

export interface RolloutThresholds {
  dwell_hours: number;
  min_success_rate: number;
  max_failure_rate: number;
  max_rollback_rate: number;
}

export interface Rollout {
  status: 'PENDING' | 'ACTIVE' | 'PAUSED' | 'DONE';
  mode: 'FULL' | 'STAGED';
  stages: number[];
  stage_no: number;
  percentage: number;
  started_at: string | null;
  paused_at: string | null;
  completed_at: string | null;
  auto_advance: boolean;
  auto_pause: boolean;
  thresholds: RolloutThresholds;
  rules: { kind: string; value: string }[];
  progress: {
    stage: number;
    percentage: number;
    eligible: number;
    updated: number;
    failed: number;
    rolled_back: number;
    success_rate: number | null;
  }[];
}

export interface ServerMeta {
  min_from_version: string | null;
  requires: Record<string, unknown> | null;
  migrations: { count: number; one_way: string[] } | null;
  desktop_compat: string | null;
  downtime_estimate_s: number | null;
  staging_test: { status: 'PASSED' | 'FAILED' | 'PENDING'; url?: string; editions?: Partial<Record<Edition, string>> } | null;
  admin_notice: string | null;
  advisory: string | null;
  /** Server release console fields (WS-CORE-SYSUPDATE §4); absent on older responses. */
  severity?: ServerSeverity | null;
  commit?: string | null;
  upgrade_notes?: string | null;
  migrations_reversible?: boolean | null;
  editions?: Edition[] | null;
}

export type DeploymentMode = 'MANAGED' | 'SELF_HOSTED' | 'AIR_GAPPED';
export type CampaignChannel = 'webhook' | 'email' | 'in_app' | 'whatsapp';

export interface CampaignBody {
  audience: { editions: Edition[]; channels: ChannelCode[]; version_range: string; modes: DeploymentMode[] };
  urgency: ReleaseType;
  deadline: string | null;
  rollout_pct: number;
  channels: CampaignChannel[];
  fleet_window: string | null;
  fleet_stages: number[];
}

export type Campaign = CampaignBody & {
  status: string;
  sent_count: number;
  eligible_count: number;
  fleet_status: string | null;
  fleet_stage_no: number | null;
};

export type ReleaseAction =
  | 'edit'
  | 'upload'
  | 'submit_test'
  | 'start_testing'
  | 'qa'
  | 'approve'
  | 'publish'
  | 'pause'
  | 'resume'
  | 'withdraw'
  | 'supersede'
  | 'regenerate_manifest'
  | 'replace_file'
  | 'delete_file';

export type AdminReleaseDetail = AdminRelease & {
  packages: AdminPackage[];
  changelog: Changelog;
  checklist: Checklist;
  approvals: Approval[];
  rollout: Rollout | null;
  manifest: ManifestInfo | null;
  server_meta: ServerMeta | null;
  campaign: Campaign | null;
  allowed_actions: ReleaseAction[];
};

export interface CreateReleaseBody {
  app_id: string;
  version: string;
  build: number;
  channel: ChannelCode;
  release_type: ReleaseType;
  release_date?: string | null;
  minimum_supported_version?: string | null;
  mandatory?: boolean;
  mandatory_after?: string | null;
  grace_minutes?: number | null;
  notes?: string | null;
  known_issues?: string | null;
}

export interface PublishSummary {
  channel: ChannelCode;
  release_type: ReleaseType;
  rollout: { mode: 'FULL' | 'STAGED'; stages: number[] };
  minimum_supported_version: string | null;
  effective_minimum: string | null;
  mandatory: boolean;
  mandatory_after: string | null;
  supersedes: { id: string; version: string }[];
  approvals: Approval[];
  checklist: Checklist;
  affected: { active_devices: number; will_be_offered: number };
  health_ok: boolean;
  requires_mfa: boolean;
}

export interface PolicyBody {
  minimum_supported_version?: string | null;
  minimum_no_change?: boolean;
  mandatory?: boolean;
  mandatory_after?: string | null;
  grace_minutes?: number | null;
  release_type?: ReleaseType;
  reason?: string;
  immediate?: boolean;
}

export interface UpdateResponse {
  status: string;
  [key: string]: unknown;
}

export interface ReleasePreview {
  public: PublicReleaseDetail;
  update_responses: {
    sample: { platform: Platform; architecture: Arch; install_type: string; current_version: string };
    response: UpdateResponse;
  }[];
}

export interface ReleaseDownloads {
  total: number;
  by_package: { label: string; platform: Platform; architecture: Arch; package_type: PackageType; count: number }[];
  series: { date: string; count: number }[];
}

export interface ReleaseDevices {
  adoption: { version: string; count: number; pct: number }[];
  update_success_rate: number | null;
  failures_by_code: { error_code: string; count: number }[];
  rolled_back: number;
  events_by_type: Record<string, number>;
}

export interface CreatePackageBody {
  platform: Platform;
  architecture: Arch;
  package_type: PackageType;
  edition?: Edition | null;
  file_name: string;
  size: number;
  protocol?: 'tus' | 'multipart';
  reason?: string;
}

export interface CreatePackageResponse {
  package: AdminPackage;
  upload: {
    id: string;
    protocol: 'tus' | 'multipart';
    tus_endpoint: string;
    chunk_size: number;
    parts?: { part_number: number; url: string }[];
  };
}

// ---------------------------------------------------------------- server

export interface Installation {
  installation_id: string;
  name: string;
  edition: Edition;
  version: string;
  build: number;
  channel: ChannelCode;
  mode: DeploymentMode;
  support_state: SupportState;
  last_report_at: string | null;
  last_run_status: string | null;
  php_version: string | null;
  db_version: string | null;
  /** Fleet fields (WS-CORE-SYSUPDATE FR-DL-FLEET-01); optional for older responses. */
  node_version?: string | null;
  disk_free_mb?: number | null;
  health?: 'OK' | 'DEGRADED' | 'DOWN' | null;
  offline_mode?: boolean;
  policy_mode?: PolicyMode | null;
  policy_version?: number | null;
  ring?: number;
  ring_name?: 'canary' | 'early' | 'broad' | 'conservative';
  ring_override?: boolean;
  below_minimum?: boolean;
  linked?: boolean;
  registered_at?: string | null;
}

export interface InstallationStats {
  by_edition: Partial<Record<Edition, { version: string; count: number }[]>>;
  pending_updates: number;
  failed_runs: { installation_id: string; name?: string; version?: string; error?: string; at?: string }[];
  rolled_back: { installation_id: string; name?: string; version?: string; at?: string }[];
  unsupported: number;
  /** DL-4 segment counts. */
  segments?: FleetSegments;
  /** DL-4 adoption per release. */
  adoption?: { release_id: string; version: string; status: ReleaseStatus; channel: ChannelCode; installations: number; pct: number }[];
}

// ---------------------------------------------------------------- server release console (WS-CORE-SYSUPDATE §4, DL-1…DL-4)

export type ServerSeverity = 'PATCH' | 'MINOR' | 'MAJOR' | 'SECURITY' | 'CRITICAL';
export type PolicyMode = 'OFF' | 'NOTIFY' | 'AUTO_SECURITY' | 'AUTO_MINOR' | 'AUTO_ALL';
export type ServerChannel = 'STABLE' | 'BETA' | 'LTS';

export interface ServerReleaseKpis {
  linked: number;
  online: number;
  offline: number;
  latest_stable: string | null;
  on_latest_stable: number;
  on_latest_pct: number;
  below_minimum: number;
  minimum_stable: string | null;
  rollbacks_30d: number;
  rollback_versions: string[];
}

export type ServerReleaseRow = AdminRelease & {
  severity: ServerSeverity;
  editions: Edition[];
  editions_label: string;
  ring_summary: string;
  adoption_pct: number;
  installations_on_version: number;
  commit: string | null;
};

export interface ServerReleasesResponse {
  kpis: ServerReleaseKpis;
  data: ServerReleaseRow[];
  minimums: { channel: ServerChannel; minimum_version: string | null; enforce_from: string | null }[];
}

export interface CreateServerReleaseBody {
  version: string;
  build: number;
  commit?: string;
  channel: ServerChannel;
  severity: ServerSeverity;
  min_from_version?: string;
  notes?: string;
  known_issues?: string;
  upgrade_notes?: string;
  migrations_reversible: boolean | null;
  editions: Edition[];
  desktop_compat?: string;
  admin_notice?: string;
}

export type RingState = 'DONE' | 'OPEN' | 'PAUSED' | 'WAITING';
export interface ServerRing {
  ring: 0 | 1 | 2 | 3;
  key: 'canary' | 'early' | 'broad' | 'conservative';
  name: string;
  state: RingState;
  opened_at: string | null;
  opens_at: string | null;
  hold_hours: number;
  extended_hours: number;
  hold_ends_at: string | null;
  installations: number;
  updated: number;
  detail: string;
}

export interface GateSignal {
  key: 'rollback_rate' | 'failed_runs' | 'crash_free' | 'new_p0p1';
  label: string;
  value: number | null;
  display: string;
  threshold: string;
  state: 'OK' | 'WATCH' | 'FAIL';
}

export interface ServerRollout {
  release: { id: string; version: string; build: number; status: ReleaseStatus; channel: ChannelCode; severity: ServerSeverity; published_at: string | null; withdrawn_at: string | null };
  status: 'PENDING' | 'ACTIVE' | 'PAUSED' | 'DONE';
  mode: 'RINGS' | string | null;
  compressed: boolean;
  pause: { kind: 'GATE' | 'MANUAL'; reason: string; at: string; by: string; ring: number | null } | null;
  rings: ServerRing[];
  gate: { state: 'PASSING' | 'WATCH' | 'FAILING'; signals: GateSignal[]; reasons: string[]; runs: number; evaluated_at: string };
  outcomes: { updated: number; auto_scheduled: number; waiting_for_admin: number; postponed: number; offline: number; held: number; skipped: number };
  campaign: (Campaign & { delivered: { in_app: number; email: number } }) | null;
}

export interface FleetSegments {
  all: number;
  on_latest: number;
  latest_stable: string | null;
  below_minimum: number;
  on_hold: number;
  stale: number;
}

// ---------------------------------------------------------------- analytics

export interface DownloadAnalytics {
  total: number;
  bots_excluded: number;
  series: { period: string; count: number }[];
  by_platform: { platform: Platform; count: number }[];
  by_package: { label: string; count: number }[];
  by_country: { country: string; count: number }[];
  top_releases: { release_id: string; version: string; app_name: string; count: number }[];
}

export interface DeviceAnalytics {
  active_devices: number;
  by_version: { version: string; count: number; pct: number; state: 'CURRENT' | 'UPDATE_AVAILABLE' | 'UNSUPPORTED' }[];
  by_platform: { platform: Platform; count: number }[];
  by_channel: { channel: ChannelCode; count: number }[];
  current_pct: number;
  update_available_pct: number;
  unsupported_pct: number;
}

export interface UpdateStatusAnalytics {
  releases: {
    release_id: string;
    version: string;
    checked: number;
    downloaded: number;
    installed: number;
    failed: number;
    rolled_back: number;
    success_rate: number | null;
  }[];
  failures_by_code: { error_code: string; count: number }[];
}

// ---------------------------------------------------------------- administration

export interface AdminUser {
  id: string;
  email: string;
  name: string;
  kind: 'STAFF' | 'PORTAL';
  status: 'ACTIVE' | 'DISABLED' | 'LOCKED' | string;
  roles: string[];
  mfa_enabled: boolean;
  last_login_at: string | null;
  created_at: string;
}

export interface RolesMatrix {
  roles: { slug: string; name: string }[];
  permissions: { slug: string; name: string; roles: string[] }[];
}

export interface ApiClient {
  id: string;
  name: string;
  kind: string;
  client_id: string;
  permissions: string[];
  ip_allowlist: string[];
  expires_at: string | null;
  last_used_at: string | null;
  rotated_at: string | null;
  revoked_at: string | null;
  created_at: string;
}

export interface StorageProvider {
  id: string;
  name: string;
  kind: string;
  bucket: string;
  region: string | null;
  endpoint: string | null;
  cdn_host: string | null;
  is_active: boolean;
  versioning: boolean;
  replication: string | null;
  status: HealthStatus | string;
  status_detail: string | null;
  last_check_at: string | null;
  usage_bytes: number;
  object_count: number;
  credentials: 'set' | 'missing';
  credentials_rotated_at: string | null;
  release_count: number;
}

export interface StorageOverview {
  providers: StorageProvider[];
  cdn: {
    host: string;
    url_lifetime_s: number;
    signing: 'hmac' | 'presign';
    purge: 'none' | 'cloudflare';
    status: string;
    last_purge_at: string | null;
    hit_ratio: number | null;
    egress_30d_bytes: number | null;
  };
  distribution: { degraded_packages: { release_id: string; version: string; package: string; detail: string }[] };
  migrations: { id?: string; from?: string; to?: string; status?: string; progress?: number; [key: string]: unknown }[];
}

export interface SigningKey {
  key_id: string;
  purpose: string;
  algorithm: string;
  public_key: string;
  status: string;
  created_at: string;
  rotated_at: string | null;
  retire_after: string | null;
}

export type Settings = Record<string, unknown> & { cdn_url_lifetime_s?: number; notify_chat_webhook_set?: boolean };

// ---------------------------------------------------------------- bug trace

export type IssueStatus = 'NEW' | 'TRIAGED' | 'IN_PROGRESS' | 'RESOLVED' | 'REGRESSED' | 'IGNORED' | 'DUPLICATE' | 'WONT_FIX';
export type IssuePriority = 'P0' | 'P1' | 'P2' | 'P3' | 'P4';

export interface Issue {
  id: string;
  project: { id: string; name: string; platform: string };
  type: 'crash' | 'error' | 'hang' | 'message';
  title: string;
  culprit: string;
  status: IssueStatus;
  resolution: 'NOW' | 'NEXT_RELEASE' | 'VERSION' | null;
  resolve_version: string | null;
  priority: IssuePriority;
  assignee: { id: string; name: string } | null;
  first_seen: string;
  last_seen: string;
  first_release: string | null;
  last_release: string | null;
  events_count: number;
  users_count: number;
  installations_count: number;
  labels: string[];
  sparkline: number[];
}

export interface IssueListResponse extends ListResponse<Issue> {
  kpis: { unresolved: number; regressed: number; crash_free_users: number | null; events_24h: number; untriaged_reports: number };
}

export interface ReportSummary {
  reference: string;
  title: string;
  severity: string;
  category: string;
  status: 'UNTRIAGED' | 'LINKED' | 'FEATURE_REQUEST' | 'NEED_INFO' | 'CLOSED';
  app: string;
  release: string | null;
  created_at: string;
  has_screenshot: boolean;
  suggested_issue: { id: string; title: string } | null;
  issue: { id: string; title: string } | null;
}

export interface StackFrame {
  file: string | null;
  function: string | null;
  line: number | null;
  col: number | null;
  in_app: boolean;
  /** Native module or JVM class (desktop / mobile). */
  module?: string;
  symbolicated?: boolean;
  context?: { pre: string[]; line: string; post: string[] };
}

export interface IssueDetail extends Issue {
  latest_event: { id: string; timestamp: string; message?: string; [key: string]: unknown };
  frames: StackFrame[];
  symbolication?: 'ok' | 'partial' | 'missing' | 'pending' | 'n/a';
  breadcrumbs: { timestamp: string; category: string; message: string; level?: string }[];
  tags: Record<string, string> | { key: string; value: string }[];
  distributions: Record<'os' | 'browser' | 'release' | 'edition', { name: string; count: number }[]>;
  releases: { release: string; events: number; first_seen: string; last_seen: string }[];
  hourly: { hour: string; events: number }[];
  reports: ReportSummary[];
  comments: { id: string; user: string; body: string; kind: string; created_at: string }[];
  links: { kind: string; ref: string; url: string }[];
  scrubbed_fields: number;
  tenant_count?: number;
}

export interface ReportDetail extends ReportSummary {
  description: string;
  steps: string[];
  expected: string | null;
  context: Record<string, unknown>;
  attachments: { id: string; kind: string; url: string; meta: Record<string, unknown> }[];
  updates: { at: string; message: string; public?: boolean; by?: string }[];
  contact: { email: string } | null;
}

export interface ReleaseHealth {
  releases: {
    release: string;
    release_id: string | null;
    adoption_pct: number;
    sessions: number;
    crash_free_sessions: number | null;
    crash_free_users: number | null;
    new_issues: number;
    regressions: number;
    reports: number;
    update_failures: number;
    gate: 'OK' | 'WARN' | 'BLOCK';
  }[];
  series: { date: string; release: string; crash_free_users: number }[];
  gate_rules: Record<string, unknown>;
}

export interface BugAlert {
  id: string;
  name: string;
  /** new_issue | regression | spike | threshold | crash_free_below | resolved */
  condition: string;
  /** Condition parameters, e.g. {types:['crash']}, {multiplier:5, min_events:20}, {value:99} */
  threshold: Record<string, unknown>;
  channels: string[];
  enabled: boolean;
  quiet_hours?: string | null;
  digest?: boolean;
  webhook_url?: string | null;
  project?: string | null;
  [key: string]: unknown;
}

// ---------------------------------------------------------------- portal

export type PortalApp = AppSummary & {
  channels_available: ChannelCode[];
  latest_by_channel: Partial<Record<ChannelCode, PublicReleaseDetail>>;
};

export interface PortalSubscription {
  app: string;
  channel: ChannelCode;
  enabled: boolean;
}

// ---------------------------------------------------------------- licences
export type LicenseState = 'ACTIVE' | 'EXPIRED' | 'SUSPENDED' | 'REVOKED' | 'NOT_STARTED' | 'DEACTIVATED';

export interface LicenseActivation {
  id: number;
  installation_id: string;
  hostname: string | null;
  server_url: string | null;
  fingerprint: string | null;
  activated_at: string | null;
  last_validated_at: string | null;
  deactivated_at: string | null;
  deactivate_reason: string | null;
  active: boolean;
}

export interface License {
  id: string;
  license_key_masked: string;
  /** Present once, in the create response only. */
  license_key?: string;
  organization: string;
  contact_email: string | null;
  edition: 'FREE' | 'BUSINESS' | 'ENTERPRISE';
  status: 'ACTIVE' | 'SUSPENDED' | 'REVOKED';
  state: LicenseState;
  status_reason: string | null;
  max_activations: number;
  active_activations: number;
  starts_at: string | null;
  expires_at: string | null;
  updates_until: string | null;
  server_bound: boolean;
  notes: string | null;
  created_by: string | null;
  created_at: string | null;
  updated_at: string | null;
  activations?: LicenseActivation[];
}

export interface LicenseBody {
  organization: string;
  contact_email?: string;
  edition: 'FREE' | 'BUSINESS' | 'ENTERPRISE';
  max_activations: number;
  expires_at: string;
  updates_until?: string;
  server_bound?: boolean;
  notes?: string;
}

export type EntitlementValue = boolean | number | string | null;
export interface EntitlementDef {
  key: string;
  label: string;
  kind: 'bool' | 'limit' | 'value';
  category: 'apps' | 'limits' | 'updates' | 'services' | 'support';
  module: string | null;
  description: string | null;
}
export interface EntitlementMatrix {
  entitlements: EntitlementDef[];
  editions: Record<string, Record<string, EntitlementValue>>;
}
export interface LicenseEntitlements {
  edition: string;
  addons: Record<string, EntitlementValue>;
  resolved: Record<string, EntitlementValue>;
}
