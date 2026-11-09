/** Shape of GET /wp-chrono/v1/status (see Services/SiteInspector.php). */
export interface SiteStatus {
  database: {
    size_bytes: number;
    table_count: number;
    row_estimate: number;
    largest_tables: { name: string; rows: number; bytes: number }[];
    snapshot_bytes: number;
    snapshot_table_count: number;
  };
  snapshots: {
    count: number;
    last_auto: { uid: string; label: string; status: string; created_at: string } | null;
  };
  disk: { free_bytes: number | null; total_bytes: number | null };
  environment: {
    wp_version: string;
    php_version: string;
    db_version: string;
    table_prefix: string;
    multisite: boolean;
    woocommerce: boolean;
    active_plugins: number;
    theme: string;
  };
  storage: { protected: boolean };
  storage_usage: StorageUsage;
}

/** WP Chrono's footprint and bloat warning (SiteInspector::storage_usage). */
export interface StorageUsage {
  snapshot_bytes: number;
  file_bytes: number;
  total_bytes: number;
  threshold_bytes: number;
  free_bytes: number | null;
  warn: boolean;
  reason: string | null;
  prunable: { count: number; bytes: number };
  /** Storage cap (bytes). */
  cap_bytes: number;
  /** Minimum auto-snapshots kept when over the GB cap (floor, not a ceiling). */
  cap_autos: number;
  /** Total bytes above the cap. */
  cap_used_bytes: boolean;
  /** Auto-snapshot count above the floor (reporting only). */
  cap_used_autos: boolean;
  /** Total bytes above the GB cap. */
  over_cap: boolean;
}

export type ResetTarget =
  | 'transients'
  | 'woocommerce'
  | 'uploads'
  | 'inactive_plugins'
  | 'inactive_themes'
  | 'custom_tables'
  | 'orphaned_meta'
  | 'options';

/** GET /reset/preview/{target} — a dry run (see Services/ResetService.php). */
export interface TargetPreview {
  target: ResetTarget;
  available: boolean;
  reason: string | null;
  items: number;
  bytes: number;
  approximate: boolean;
  details: string[];
  /** Uploads cleaner: backup-plugin folders that are always kept. */
  protected?: string[];
}

export interface ResetPreviewResponse {
  targets: Record<ResetTarget, TargetPreview>;
  full: { available: boolean; tables: number; bytes: number };
}

export interface PreflightResponse {
  state: { target: string; tables: number; rows: number; bytes: number; checked_at: string };
  /** Registered safety snapshot; status `building` means the client must build it. */
  snapshot: Snapshot | null;
  snapshot_error: string | null;
  log: string[];
}

/** One resumable tick of POST /reset/partial/{target}. */
export interface PartialTick {
  target: ResetTarget;
  done: boolean;
  processed: number;
  cursor: number;
  log: string[];
  duration_ms: number;
}

export interface FullResetResponse {
  duration_ms: number;
  tables_dropped: number;
  user_id: number;
  steps: { label: string; ms: number }[];
  plugin_errors: string[];
  nonces: { rest: string; chrono: string } | null;
  /** Fresh REST base computed after permalinks are restored; adopt when present. */
  restUrl?: string;
  redirect: string;
}

export type SnapshotTrigger = 'manual' | 'auto' | 'scheduled' | 'safety' | 'cloud' | 'import';

/** A plugin or theme pinned by a blueprint (Services/BlueprintService.php). */
export interface BlueprintItem {
  type: 'plugin' | 'theme';
  slug: string;
  file: string;
  name: string;
  version: string;
  active: boolean;
  source: 'package' | 'url' | 'wporg';
  url: string;
  package: string;
  has_package: boolean;
}

export interface Blueprint {
  id: string;
  name: string;
  description: string;
  created_at: string;
  updated_at: string;
  source_site?: string;
  plugins: BlueprintItem[];
  themes: BlueprintItem[];
  options: Record<string, string | number | null>;
  remove_others: boolean;
  plugin_count: number;
  theme_count: number;
  option_count: number;
  package_bytes: number;
  steps: { kind: string; index: number; label: string }[];
}

export interface BlueprintStepResult {
  done: boolean;
  step: number;
  next: number | null;
  total: number;
  label: string;
  ok: boolean;
  log: string[];
  duration_ms: number;
}

export interface CloudSettings {
  provider: 'r2' | 's3' | 'b2' | 'custom';
  endpoint: string;
  region: string;
  bucket: string;
  access_key: string;
  has_secret: boolean;
  path_style: boolean;
  prefix: string;
  configured: boolean;
  encryption: string;
  providers: Record<string, { label: string; endpoint: string; region: string; path_style: boolean }>;
}

export interface RemoteBackup {
  key: string;
  label: string;
  snapshot_id: number;
  trigger_type: string;
  created_at: string;
  pushed_at: string;
  table_count: number;
  row_count: number;
  bytes: number;
  files: number;
  site: string;
}

/** One tick of POST /cloud/push/{id} or POST /cloud/pull. */
export interface CloudTick {
  done: boolean;
  phase: string;
  job?: string;
  step?: number;
  total?: number;
  key?: string;
  message: string;
  snapshot_id?: number;
}

export interface Snapshot {
  id: number;
  uid: string;
  label: string;
  note: string;
  trigger_type: SnapshotTrigger;
  status: 'building' | 'ready' | 'failed';
  table_count: number;
  row_count: number;
  size_bytes: number;
  created_at: string;
  completed_at: string | null;
  meta: {
    error?: string;
    last_restored_at?: string;
    pinned?: boolean;
    exports?: { file: string; bytes: number; created_at: string; download_url?: string }[];
    rollback?: { files?: RollbackFile[]; skipped?: { type: string; slug: string; reason: string }[] };
    verified?: boolean;
    verification?: SnapshotVerification;
    cloud?: { key?: string; pushed_at?: string; pulled_from?: string; bytes?: number };
    safe_update?: SafeUpdateCheck;
  };
}

/** Safe Updates' check of the update batch a snapshot was taken for (Services/SafeUpdates.php). */
export interface SafeUpdateCheck {
  status: 'watching' | 'passed' | 'unverified' | 'undone' | 'undo_failed';
  message?: string;
  checked_at?: string;
  error?: { message: string; file: string; line: number } | null;
  culprit?: { type: string; slug: string; name: string; old_version: string; new_version: string | null } | null;
  /** How the broken update was undone: 'files' when new visitor data blocked a database rollback. */
  mode?: 'full' | 'files';
  /** New records found since the snapshot, by label. */
  fresh?: Record<string, number>;
}

/** A plugin / theme folder archived before an update (Services/Snapshot/FileArchive.php). */
export interface RollbackFile {
  type: 'plugin' | 'theme' | 'core';
  slug: string;
  file: string;
  old_version: string;
  new_version: string | null;
  zip: string;
  sha256: string;
  bytes: number;
}

export interface SnapshotVerification {
  tables: number;
  rows: number;
  mismatches: string[];
  ms: number;
  checked_at: string;
}

export type RestoreMode = 'full' | 'db' | 'files';

export interface ChronoSettings {
  auto_snapshot: boolean;
  safe_updates: boolean;
  auto_retention: number;
  /** Maximum total storage for snapshots, archives and exports, in GB. */
  storage_cap_gb: number;
  /** Minimum auto-snapshots kept when over the GB cap. */
  storage_cap_autos: number;
  storage_warn_mb: number;
  rollback_max_mb: number;
  schedule: 'off' | 'daily' | 'weekly';
  /** 0–23, in the site's timezone. */
  schedule_hour: number;
  schedule_keep: number;
  /** Deleting the plugin keeps snapshots, exports and settings. */
  keep_data_on_uninstall: boolean;
  /** Extra pages of this site that Safe Updates checks after each update. */
  safe_updates_urls: string[];
  /** Next scheduled snapshot (ISO, UTC); read-only. */
  schedule_next?: string | null;
}

/** GET /license (Services/LicenseService.php). */
export interface LicenseStatus {
  active: boolean;
  plan: 'free' | 'pro' | 'agency';
  from: 'constant' | 'option' | null;
  license: { lid: string; plan: string; sites: number; source: string; issued: string; expires: string | null; term: 'yearly' | 'lifetime' | null } | null;
  masked: string | null;
  error: string | null;
  features: Record<'blueprints' | 'cloud', boolean>;
  free: string[];
  server: string;
  /** The store's plans page, '' when no API is configured. */
  pricing: string;
}

/** GET /onboarding. */
export interface OnboardingStatus {
  dismissed: boolean;
  welcome_seen: boolean;
  kit_downloaded: boolean;
  steps: { recovery: boolean; auto: boolean; safety_copy: boolean };
  complete: boolean;
  recovery_exists: boolean;
  cloud_configured: boolean;
}

export interface SnapshotIndex {
  snapshots: Snapshot[];
  settings: ChronoSettings;
  orphans: { count: number; bytes: number };
  restore: { job: string; snapshot_id: number } | null;
  storage: StorageUsage;
  cloud: { configured: boolean };
  import: { max_chunk: number };
}

/** One step of the local .sql.gz import (chunk upload or import tick). */
export interface ImportTick {
  done: boolean;
  phase: 'upload' | 'import' | 'ready';
  upload?: string;
  job?: string;
  bytes?: number;
  statements?: number;
  snapshot_id?: number;
  message: string;
}

/** POST /snapshots and POST /snapshots/{id}/build. */
export interface BuildProgress {
  snapshot: Snapshot | null;
  done: boolean;
  tables_total: number;
  tables_done: number;
  rows_copied: number;
  current_table: string;
  cursor: number;
}

export interface RestoreStaging {
  done: false;
  phase: 'staging';
  job: string;
  snapshot_id: number;
  tables_total: number;
  tables_done: number;
  rows_copied: number;
  rows_total: number;
  current_table: string;
}

export interface RestoreSwapped {
  done: true;
  phase: 'swapped' | 'files';
  snapshot_id: number;
  tables_restored: number;
  tables_removed: number;
  tables_kept: string[];
  rows_copied: number;
  files_restored: number;
  swap_ms: number;
  duration_ms: number;
  nonces: { rest: string; chrono: string } | null;
  log: string[];
  redirect: string;
}

export type RestoreTick = RestoreStaging | RestoreSwapped;

export interface ExportTick {
  done: boolean;
  job?: string;
  tables_total: number;
  tables_done: number;
  bytes: number;
  file?: string;
  download_url?: string;
}

export interface TableChange {
  table: string;
  rows_before: number;
  rows_after: number;
  rows_delta: number;
  bytes_before: number;
  bytes_after: number;
  bytes_delta: number;
  schema_changed: boolean;
  schema_diff: { added: string[]; removed: string[] } | null;
  content_changed: boolean | null;
}

export interface OptionChange {
  name: string;
  label: string | null;
  old_value: string | null;
  new_value: string | null;
  summary?: string;
}

export interface SnapshotDiff {
  snapshot: Snapshot;
  tables: {
    added_tables: { table: string; rows: number; bytes: number }[];
    removed_tables: { table: string; rows: number; bytes: number }[];
    modified_tables: TableChange[];
    unchanged_tables: number;
  };
  options: {
    available: boolean;
    added: OptionChange[];
    deleted: OptionChange[];
    modified: OptionChange[];
    truncated: boolean;
  };
  duration_ms: number;
}

export interface OrphanScan {
  tables: { name: string; reason: string; rows: number; bytes: number }[];
  bytes: number;
}
