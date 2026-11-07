import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { formatBytes, formatNumber } from '@/lib/format';
import type {
  Blueprint,
  BlueprintStepResult,
  BuildProgress,
  FullResetResponse,
  PartialTick,
  PreflightResponse,
  ResetTarget,
  RestoreMode,
  RestoreTick,
  Snapshot,
} from '@/types';

export type StepStatus = 'pending' | 'running' | 'done' | 'error';
export type LogTone = 'info' | 'success' | 'error';

/** Callbacks a plan uses to drive the execution UI. */
export interface Reporter {
  step(id: string, status: StepStatus): void;
  log(text: string, tone?: LogTone): void;
  progress(value: number | null): void;
}

export interface PlanOutcome {
  summary: string;
  action?: { label: string; href: string };
}

export interface ExecuteOptions {
  /** "Take a safety snapshot first" switch. */
  snapshot: boolean;
  /** Extra switches declared by the dialog (e.g. drop_extra). */
  flags: Record<string, boolean>;
  /** Value of the dialog's one-of-N choice, if it declares one (e.g. restore mode). */
  choice?: string;
}

/** A destructive operation, described as UI steps plus the code that runs them. */
export interface ResetPlan {
  steps: { id: string; label: string }[];
  execute(reporter: Reporter, opts: ExecuteOptions): Promise<PlanOutcome>;
}

const CONFIRM = 'RESET';
/** Guards against a server bug turning a tick loop into an infinite one. */
const MAX_TICKS = 5000;

/** After a full reset or restore the auth cookie is re-issued; old nonces are stale. */
function adoptNonces(nonces: { rest: string; chrono: string } | null) {
  if (!nonces) return;
  config.restNonce = nonces.rest;
  config.chronoNonce = nonces.chrono;
}

/** Fetch fresh nonces from the server (needed after a full reset wipes nonces).
 *
 * If the nonce endpoint returns an empty body or an error, fall back to the
 * nonces already obtained from reset/full (the `fallback` parameter). Those
 * nonces were created by Session::refresh() after wp_install() regenerated
 * the nonce keys, so they are guaranteed valid for the new session.
 */
export async function refreshConfig(fallback?: { rest: string; chrono: string } | null): Promise<void> {
  try {
    const fresh = await api<{ rest: string; chrono: string }>('nonce');
    config.restNonce = fresh.rest;
    config.chronoNonce = fresh.chrono;
  } catch (_e) {
    if (fallback) {
      adoptNonces(fallback);
    } else {
      throw _e;
    }
  }
}

/** Runs build ticks until the snapshot is ready, reporting table progress. */
export async function buildSnapshot(id: number, r: Reporter, label = `Snapshot #${id}`): Promise<Snapshot> {
  let lastTable = '';
  for (let tick = 0; tick < MAX_TICKS; tick++) {
    const p = await api<BuildProgress>(`snapshots/${id}/build`, { method: 'POST' });
    r.progress(p.tables_total ? p.tables_done / p.tables_total : null);
    if (p.current_table && p.current_table !== lastTable) {
      lastTable = p.current_table;
      r.log(`${label}: copying ${p.current_table} (${formatNumber(p.rows_copied)} rows so far)`);
    }
    if (p.done && p.snapshot) {
      r.log(
        `${label} ready: ${p.snapshot.table_count} tables, ${formatNumber(p.snapshot.row_count)} rows, ${formatBytes(p.snapshot.size_bytes)}.`,
        'success',
      );
      return p.snapshot;
    }
  }
  throw new Error(`${label} did not finish building.`);
}

async function preflight(r: Reporter, target: ResetTarget | 'full', snapshot: boolean) {
  r.step('preflight', 'running');
  const result = await api<PreflightResponse>('reset/preflight', { method: 'POST', body: { target, snapshot } });
  result.log.forEach((line) => r.log(line));
  if (snapshot && result.snapshot_error) {
    // Never run a destructive step without the safety net the user asked for.
    throw new Error(`Safety snapshot failed, nothing was changed: ${result.snapshot_error}`);
  }
  if (result.snapshot && result.snapshot.status === 'building') {
    await buildSnapshot(result.snapshot.id, r, `Safety snapshot #${result.snapshot.id}`);
  }
  r.step('preflight', 'done');
}

export function partialPlan(target: ResetTarget, opts: { total: number; mode?: 'truncate' | 'drop' }): ResetPlan {
  return {
    steps: [
      { id: 'preflight', label: 'Pre-flight check & safety snapshot' },
      { id: 'run', label: 'Cleaning in batches' },
    ],
    async execute(r, { snapshot }) {
      await preflight(r, target, snapshot);

      r.step('run', 'running');
      r.progress(opts.total > 0 ? 0 : null);
      let cursor = 0;
      let processed = 0;
      for (let tick = 0; tick < MAX_TICKS; tick++) {
        const result = await api<PartialTick>(`reset/partial/${target}`, {
          method: 'POST',
          body: { confirm: CONFIRM, cursor, mode: opts.mode ?? 'truncate' },
        });
        processed += result.processed;
        cursor = result.cursor;
        result.log.forEach((line) => r.log(line, line.startsWith('Error') || line.startsWith('Could not') ? 'error' : 'info'));
        if (opts.total > 0) r.progress(Math.min(processed / opts.total, 0.99));
        if (result.done) {
          r.progress(1);
          r.step('run', 'done');
          return { summary: `Done: ${formatNumber(processed)} items processed.` };
        }
      }
      throw new Error('The cleanup did not finish after the maximum number of batches.');
    },
  };
}

export function fullResetPlan(opts: { keep_theme: boolean; keep_plugins: boolean; keep_users: boolean }): ResetPlan {
  return {
    steps: [
      { id: 'preflight', label: 'Pre-flight check & safety snapshot' },
      { id: 'reset', label: 'Drop tables & reinstall WordPress' },
      { id: 'session', label: 'Restore admin session' },
    ],
    async execute(r, { snapshot }) {
      await preflight(r, 'full', snapshot);

      r.step('reset', 'running');
      r.progress(null);
      const result = await api<FullResetResponse>('reset/full', { method: 'POST', body: { confirm: CONFIRM, ...opts } });
      result.steps.forEach((s) => r.log(`${String(s.ms).padStart(6)} ms  ${s.label}`));
      result.plugin_errors.forEach((e) => r.log(e, 'error'));
      r.step('reset', 'done');

      r.step('session', 'running');
      adoptNonces(result.nonces);
      r.step('session', 'done');
      r.progress(1);

      return {
        summary: `Site reset in ${(result.duration_ms / 1000).toFixed(2)}s, ${result.tables_dropped} tables dropped. You are still signed in.`,
        action: { label: 'Reload WP Chrono', href: result.redirect },
      };
    },
  };
}

/**
 * "Reset to Dev Baseline": safety snapshot → full reset (session kept) →
 * every blueprint step (remove extras, install + activate each plugin and
 * theme, apply settings), one request per step.
 */
export function blueprintPlan(blueprint: Blueprint): ResetPlan {
  return {
    steps: [
      { id: 'preflight', label: 'Pre-flight check & safety snapshot' },
      { id: 'reset', label: 'Full reset (you stay signed in)' },
      { id: 'apply', label: `Install ${blueprint.plugin_count} plugins, ${blueprint.theme_count} theme(s) & ${blueprint.option_count} settings` },
    ],
    async execute(r, { snapshot }) {
      await preflight(r, 'full', snapshot);

      r.step('reset', 'running');
      r.progress(null);
      const reset = await api<FullResetResponse>('reset/full', {
        method: 'POST',
        body: { confirm: CONFIRM, keep_theme: false, keep_plugins: false, keep_users: false },
      });
      reset.steps.forEach((s) => r.log(`${String(s.ms).padStart(6)} ms  ${s.label}`));
      adoptNonces(reset.nonces);
      // After a full reset the WordPress nonce keys are regenerated. The nonces
      // returned by reset/full should be fresh, but re-fetch via the nonce
      // endpoint (which only needs the WordPress REST nonce, not the chrono
      // nonce) to be sure the SPA's next request isn't rejected. If the nonce
      // endpoint returns an empty body or errors, fall back to the nonces
      // already obtained from reset/full — they are valid for the new session.
      await refreshConfig(reset.nonces);
      r.step('reset', 'done');

      r.step('apply', 'running');
      r.progress(0);
      let step = 0;
      let failed = 0;
      for (let i = 0; i < MAX_TICKS; i++) {
        let tick: BlueprintStepResult;
        let lastError: unknown;
        for (let attempt = 0; attempt < 3; attempt++) {
          try {
            tick = await api<BlueprintStepResult>(`blueprints/${blueprint.id}/apply`, {
              method: 'POST',
              body: { step, confirm: CONFIRM },
            });
            break;
          } catch (e) {
            lastError = e;
            // After a full reset, the first apply call(s) can return an empty body.
            // Retry with a short delay so the REST server can settle.
            if (attempt < 2) {
              await new Promise((r) => setTimeout(r, 500));
            }
          }
        }
        if (!tick) throw lastError;
        r.log(`${tick.ok ? '✓' : '✗'} ${tick.label}`, tick.ok ? 'info' : 'error');
        tick.log.forEach((line) => r.log(`    ${line}`, tick.ok ? 'info' : 'error'));
        failed += tick.ok ? 0 : 1;
        r.progress((tick.step + 1) / tick.total);
        if (tick.done) break;
        step = tick.next ?? tick.step + 1;
      }
      r.step('apply', failed ? 'error' : 'done');

      return {
        summary: failed
          ? `Rebuilt from "${blueprint.name}" with ${failed} failed step(s) — see the log. You are still signed in.`
          : `Site rebuilt from blueprint "${blueprint.name}". You are still signed in.`,
        action: { label: 'Reload WP Chrono', href: `${config.adminUrl}admin.php?page=wp-chrono#/blueprints` },
      };
    },
  };
}

export function restorePlan(snapshot: Snapshot): ResetPlan {
  const files = snapshot.meta.rollback?.files ?? [];
  return {
    steps: [
      { id: 'preflight', label: 'Safety snapshot of the current state' },
      { id: 'stage', label: files.length ? 'Verify file archives & stage tables' : 'Copy snapshot into staging tables' },
      { id: 'swap', label: files.length ? 'Atomic swap (tables, then plugin/theme folders)' : 'Atomic swap & session repair' },
    ],
    async execute(r, { snapshot: safety, flags, choice }) {
      const mode: RestoreMode = files.length && (choice === 'files' || choice === 'full') ? choice : 'db';
      r.step('preflight', 'running');
      if (safety) {
        const created = await api<BuildProgress>('snapshots', {
          method: 'POST',
          body: { label: `Before restoring #${snapshot.id}` },
        });
        if (created.snapshot) await buildSnapshot(created.snapshot.id, r, `Safety snapshot #${created.snapshot.id}`);
      } else {
        r.log('Safety snapshot skipped.');
      }
      r.step('preflight', 'done');

      r.step('stage', 'running');
      r.progress(0);
      if (mode !== 'db') r.log(`Verifying SHA-256 of ${files.length} plugin/theme archive(s) and staging them…`);
      let tick = await api<RestoreTick>(`snapshots/${snapshot.id}/restore`, {
        method: 'POST',
        body: { confirm: 'RESTORE', drop_extra: Boolean(flags.drop_extra), include_network_tables: Boolean(flags.include_network_tables), mode },
      });
      if (tick.done && tick.phase === 'files') {
        // Files-only: no tables to stage, the folders were swapped in that call.
        r.step('stage', 'done');
        r.step('swap', 'running');
        tick.log.forEach((line) => r.log(line, line.startsWith('Could not') ? 'error' : 'success'));
        r.step('swap', 'done');
        r.progress(1);
        return {
          summary: `Reverted ${tick.files_restored} of ${files.length} plugin/theme folder(s) from snapshot #${snapshot.id}. The database was not changed.`,
        };
      }
      for (let i = 0; i < MAX_TICKS && !tick.done; i++) {
        const job: string = tick.job;
        tick = await api<RestoreTick>('snapshots/restore/tick', { method: 'POST', body: { job, confirm: 'RESTORE' } });
        if (!tick.done) {
          r.progress(tick.rows_total ? tick.rows_copied / tick.rows_total : tick.tables_done / tick.tables_total);
          if (tick.current_table) r.log(`Staging ${tick.current_table}: ${formatNumber(tick.rows_copied)} / ${formatNumber(tick.rows_total)} rows`);
        }
      }
      if (!tick.done) throw new Error('Restore staging did not finish.');
      r.step('stage', 'done');

      r.step('swap', 'running');
      r.log(`Atomic RENAME TABLE swap of ${tick.tables_restored} tables took ${tick.swap_ms} ms.`, 'success');
      if (tick.tables_removed) r.log(`Removed ${tick.tables_removed} tables created after the snapshot.`);
      if (tick.tables_kept.length) r.log(`Kept ${tick.tables_kept.length} newer tables: ${tick.tables_kept.join(', ')}`);
      tick.log.forEach((line) => r.log(line));
      adoptNonces(tick.nonces);
      r.step('swap', 'done');
      r.progress(1);

      return {
        summary: `Restored snapshot #${snapshot.id} in ${(tick.duration_ms / 1000).toFixed(1)}s (${formatNumber(tick.rows_copied)} rows${
          tick.files_restored ? `, ${tick.files_restored} plugin/theme folder(s) reverted` : ''
        }). You are still signed in.`,
        action: { label: 'Reload WP Chrono', href: tick.redirect },
      };
    },
  };
}
