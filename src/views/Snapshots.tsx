import { useCallback, useEffect, useState } from 'react';
import {
  AlertTriangle,
  BadgeCheck,
  Camera,
  CloudDownload,
  CloudUpload,
  Download,
  FileArchive,
  FileUp,
  GitCompare,
  Hand,
  Loader2,
  RefreshCw,
  RotateCcw,
  ShieldCheck,
  Sparkles,
  Trash2,
  Undo2,
  Wrench,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Switch } from '@/components/ui/switch';
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { ConfirmResetDialog } from '@/components/reset/ConfirmResetDialog';
import { buildSnapshot, restorePlan } from '@/components/reset/plans';
import { CreateSnapshotDialog } from '@/components/snapshots/CreateSnapshotDialog';
import { DiffDrawer } from '@/components/snapshots/DiffDrawer';
import { ImportSnapshotDialog } from '@/components/snapshots/ImportSnapshotDialog';
import { ScavengerDialog } from '@/components/snapshots/ScavengerDialog';
import { config } from '@/lib/config';
import { StorageWarning } from '@/components/snapshots/StorageWarning';
import { useApi } from '@/hooks/useApi';
import type { RouteParams } from '@/hooks/useHashRoute';
import { api } from '@/lib/api';
import { formatBytes, formatNumber, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ChronoSettings, CloudTick, ExportTick, Snapshot, SnapshotIndex } from '@/types';

const TRIGGERS: Record<Snapshot['trigger_type'], { label: string; icon: typeof Hand; className: string }> = {
  manual: { label: 'Manual', icon: Hand, className: 'bg-secondary text-secondary-foreground' },
  auto: { label: 'Auto-Update', icon: Sparkles, className: 'bg-sky-500/10 text-sky-700 dark:text-sky-400' },
  safety: { label: 'Pre-Reset Safety', icon: ShieldCheck, className: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' },
  cloud: { label: 'From Cloud', icon: CloudDownload, className: 'bg-violet-500/10 text-violet-700 dark:text-violet-400' },
  import: { label: 'Imported', icon: FileUp, className: 'bg-amber-500/10 text-amber-700 dark:text-amber-400' },
};

const RETENTION_CHOICES = [1, 3, 5, 10, 20];
const WARN_CHOICES = [250, 500, 1024, 2048, 5120];

interface SnapshotsProps {
  params: RouteParams;
  clearParams: () => void;
}

export function Snapshots({ params, clearParams }: SnapshotsProps) {
  const { data, error, loading, reload } = useApi<SnapshotIndex>('snapshots');
  const [creating, setCreating] = useState(false);
  const [importing, setImporting] = useState(false);
  const [scavenging, setScavenging] = useState(false);
  const [diffing, setDiffing] = useState<Snapshot | null>(null);
  const [restoring, setRestoring] = useState<Snapshot | null>(null);
  const [restoreOpen, setRestoreOpen] = useState(false);
  const [deleting, setDeleting] = useState<Snapshot | null>(null);
  const [busy, setBusy] = useState<Record<number, string>>({});
  const [settings, setSettings] = useState<ChronoSettings | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    if (data) setSettings(data.settings);
  }, [data]);

  const openRestore = useCallback((s: Snapshot) => {
    setDiffing(null);
    setRestoring(s);
    setRestoreOpen(true);
  }, []);

  // One-shot intents from the command palette.
  useEffect(() => {
    if (!data || (!params.run && !params.restore)) return;
    clearParams();
    if (params.run === 'create') setCreating(true);
    if (params.run === 'scavenge') setScavenging(true);
    const target = data.snapshots.find((s) => String(s.id) === params.restore);
    if (target) openRestore(target);
  }, [params, data, clearParams, openRestore]);

  const saveSettings = async (changes: Partial<ChronoSettings>) => {
    setSettings((s) => (s ? { ...s, ...changes } : s));
    const next = await api<ChronoSettings>('settings', { method: 'POST', body: changes });
    setSettings(next);
    if ('auto_retention' in changes || 'storage_warn_mb' in changes) reload();
  };

  const runExport = async (s: Snapshot) => {
    setBusy((b) => ({ ...b, [s.id]: 'Exporting…' }));
    try {
      let job = '';
      let tick: ExportTick;
      do {
        tick = await api<ExportTick>(`snapshots/${s.id}/export`, { method: 'POST', body: { job } });
        job = tick.job ?? '';
        setBusy((b) => ({ ...b, [s.id]: `Exporting ${tick.tables_done}/${tick.tables_total}…` }));
      } while (!tick.done);
      setNotice(`Exported snapshot #${s.id} (${formatBytes(tick.bytes)}). Download started.`);
      if (tick.download_url) window.location.assign(tick.download_url);
      reload();
    } catch (e) {
      setNotice(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(({ [s.id]: _, ...rest }) => rest);
    }
  };

  const runPush = async (s: Snapshot) => {
    setBusy((b) => ({ ...b, [s.id]: 'Pushing…' }));
    try {
      let job = '';
      let step = 0;
      let tick: CloudTick;
      do {
        tick = await api<CloudTick>(`cloud/push/${s.id}`, { method: 'POST', body: { job, step } });
        job = tick.job ?? '';
        step = tick.step ?? 0;
        setBusy((b) => ({ ...b, [s.id]: tick.phase === 'export' ? 'Exporting…' : `Uploading ${tick.step}/${tick.total}…` }));
      } while (!tick.done);
      setNotice(tick.message);
      reload();
    } catch (e) {
      setNotice(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(({ [s.id]: _, ...rest }) => rest);
    }
  };

  const resumeBuild = async (s: Snapshot) => {
    setBusy((b) => ({ ...b, [s.id]: 'Building…' }));
    try {
      await buildSnapshot(s.id, {
        step: () => undefined,
        log: () => undefined,
        progress: (v) => setBusy((b) => ({ ...b, [s.id]: v === null ? 'Building…' : `Building ${Math.round(v * 100)}%` })),
      });
    } catch (e) {
      setNotice(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(({ [s.id]: _, ...rest }) => rest);
      reload();
    }
  };

  const confirmDelete = async () => {
    if (!deleting) return;
    const s = deleting;
    setDeleting(null);
    setBusy((b) => ({ ...b, [s.id]: 'Deleting…' }));
    try {
      await api(`snapshots/${s.id}`, { method: 'DELETE' });
    } catch (e) {
      setNotice(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(({ [s.id]: _, ...rest }) => rest);
      reload();
    }
  };

  const snapshots = data?.snapshots ?? [];
  const totalBytes = snapshots.reduce((sum, s) => sum + s.size_bytes, 0);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Snapshots & Time Machine</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {data ? `${snapshots.length} snapshots · ${formatBytes(totalBytes)}` : 'Point-in-time copies of your database.'} Restores swap
            tables atomically and keep you signed in.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {data && data.orphans.count > 0 && (
            <Button variant="outline" size="sm" onClick={() => setScavenging(true)} className="border-amber-500/40 text-amber-700 dark:text-amber-400">
              <Wrench />
              {data.orphans.count} orphaned tables · {formatBytes(data.orphans.bytes)}
            </Button>
          )}
          <Button variant="outline" size="sm" onClick={reload} disabled={loading}>
            <RefreshCw className={cn(loading && 'animate-spin')} />
            Refresh
          </Button>
          <Button variant="outline" size="sm" onClick={() => setImporting(true)} data-e2e="import-open">
            <FileUp />
            Import .sql.gz
          </Button>
          <Button size="sm" onClick={() => setCreating(true)}>
            <Camera />
            Create snapshot
          </Button>
        </div>
      </div>

      <Card className="flex flex-wrap items-center gap-x-8 gap-y-4 p-4">
        <label className="flex cursor-pointer items-center gap-3">
          <Switch
            checked={settings?.auto_snapshot ?? false}
            disabled={!settings}
            onCheckedChange={(v) => saveSettings({ auto_snapshot: v })}
          />
          <span className="flex flex-col">
            <span className="text-sm font-medium">Auto-snapshot before updates</span>
            <span className="text-xs text-muted-foreground">One snapshot per update batch, even when updating many plugins at once.</span>
          </span>
        </label>
        <label className="flex items-center gap-3 text-sm">
          Keep the last
          <select
            value={settings?.auto_retention ?? 5}
            disabled={!settings}
            onChange={(e) => saveSettings({ auto_retention: Number(e.target.value) })}
            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
          >
            {RETENTION_CHOICES.map((n) => (
              <option key={n} value={n}>
                {n}
              </option>
            ))}
          </select>
          auto-snapshots
        </label>
        <label className="flex items-center gap-3 text-sm">
          Warn above
          <select
            value={settings?.storage_warn_mb ?? 500}
            disabled={!settings}
            onChange={(e) => saveSettings({ storage_warn_mb: Number(e.target.value) })}
            className="h-8 rounded-md border border-input bg-background px-2 text-sm"
            aria-label="Storage warning threshold"
          >
            {WARN_CHOICES.map((mb) => (
              <option key={mb} value={mb}>
                {mb >= 1024 ? `${mb / 1024} GB` : `${mb} MB`}
              </option>
            ))}
            {settings && !WARN_CHOICES.includes(settings.storage_warn_mb) && (
              <option value={settings.storage_warn_mb}>{settings.storage_warn_mb} MB</option>
            )}
          </select>
          used
        </label>
        <button
          type="button"
          onClick={() => setScavenging(true)}
          className="ml-auto text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
        >
          Scan for orphaned tables
        </button>
      </Card>

      <StorageWarning usage={data?.storage} onPruned={reload} />

      {notice && (
        <p className="flex items-center justify-between gap-3 rounded-lg border bg-muted/40 p-3 text-sm">
          {notice}
          <button type="button" className="text-xs text-muted-foreground" onClick={() => setNotice(null)}>
            Dismiss
          </button>
        </p>
      )}
      {error && <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm">{error.message}</p>}
      {data?.restore && (
        <p className="flex items-center gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
          <AlertTriangle className="h-4 w-4 text-amber-600" />
          A restore of snapshot #{data.restore.snapshot_id} was interrupted. Start the restore again to continue, or it will be cleaned up
          after an hour.
        </p>
      )}

      <Card className="overflow-hidden">
        {!data &&
          Array.from({ length: 3 }, (_, i) => (
            <div key={i} className="border-b p-4 last:border-0">
              <Skeleton className="h-10 w-full" />
            </div>
          ))}
        {data && snapshots.length === 0 && (
          <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <span className="flex h-12 w-12 items-center justify-center rounded-xl border bg-secondary">
              <Camera className="h-5 w-5" />
            </span>
            <p className="font-medium">No snapshots yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
              Take one before you experiment. Snapshots are also taken automatically before plugin updates and resets.
            </p>
            <Button size="sm" onClick={() => setCreating(true)}>
              <Camera />
              Create your first snapshot
            </Button>
          </div>
        )}
        <ol>
          {snapshots.map((s, i) => (
            <SnapshotRow
              key={s.id}
              snapshot={s}
              last={i === snapshots.length - 1}
              busy={busy[s.id]}
              onDiff={() => setDiffing(s)}
              onRestore={() => openRestore(s)}
              onExport={() => runExport(s)}
              onDelete={() => setDeleting(s)}
              onResume={() => resumeBuild(s)}
              onPush={data?.cloud.configured ? () => runPush(s) : undefined}
            />
          ))}
        </ol>
      </Card>

      <CreateSnapshotDialog open={creating} onOpenChange={setCreating} onCreated={reload} />
      <ImportSnapshotDialog
        open={importing}
        onOpenChange={setImporting}
        maxChunk={data?.import.max_chunk ?? 2 * 1024 * 1024}
        onImported={(m) => {
          setNotice(m);
          reload();
        }}
      />
      <ScavengerDialog open={scavenging} onOpenChange={setScavenging} onPurged={reload} />
      <DiffDrawer snapshot={diffing} onOpenChange={(open) => !open && setDiffing(null)} onRestore={openRestore} />

      <ConfirmResetDialog
        open={restoreOpen}
        onOpenChange={setRestoreOpen}
        onFinished={reload}
        title={restoring ? `Restore snapshot #${restoring.id}` : 'Restore snapshot'}
        description={
          restoring
            ? `Replaces ${restoring.table_count} live tables with the copies from ${timeAgo(restoring.created_at)}. Tables are staged first, then swapped in one atomic RENAME, so the site is never half-restored. The snapshot itself is kept.`
            : ''
        }
        details={
          restoring
            ? [
                `${restoring.label || 'Untitled'} · ${formatNumber(restoring.row_count)} rows · ${formatBytes(restoring.size_bytes)}`,
                ...rollbackFiles(restoring).map(
                  (f) => `${f.type} ${f.slug}: ${f.new_version ?? 'updated'} → ${f.old_version || '?'} (archived, ${formatBytes(f.bytes)})`,
                ),
                'Your account and current login session are carried over.',
                'WP Chrono settings and other snapshots are not affected.',
              ]
            : []
        }
        choice={
          restoring && rollbackFiles(restoring).length
            ? {
                label: 'What to restore',
                default: 'full',
                options: [
                  {
                    value: 'full',
                    label: 'Restore Database + Revert Updated Plugin/Theme Files (Full Undo)',
                    hint: 'Recommended. Code and data go back together, so the old plugin never runs against the new database.',
                  },
                  { value: 'db', label: 'Database Only', hint: 'Keeps the updated plugin/theme files on disk.' },
                  { value: 'files', label: 'Files Only', hint: 'Reverts the archived folders and leaves the database as it is now.' },
                ],
              }
            : undefined
        }
        confirmWord="RESTORE"
        confirmLabel="Restore now"
        toggles={[
          {
            id: 'drop_extra',
            label: 'Drop extra tables created after snapshot',
            hint: 'Otherwise tables that did not exist at snapshot time are left as they are.',
            default: false,
          },
          ...(config.mainSite && config.superAdmin
            ? [
                {
                  id: 'include_network_tables',
                  label: 'Include Network Users & Blogs',
                  hint: 'Also roll back the network-wide users, usermeta, blogs, site, sitemeta, blogmeta and signups tables. Off by default so subsite users and blogs are never wiped.',
                  default: false,
                },
              ]
            : []),
        ]}
        plan={restoring ? restorePlan(restoring) : null}
      />

      <AlertDialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
        <AlertDialogContent>
          <AlertDialogTitle>Delete snapshot #{deleting?.id}?</AlertDialogTitle>
          <AlertDialogDescription>
            Drops its {deleting?.table_count} tables ({formatBytes(deleting?.size_bytes ?? 0)}) and any exported files. This cannot be
            undone.
          </AlertDialogDescription>
          <div className="flex justify-end gap-2">
            <AlertDialogCancel asChild>
              <Button variant="outline">Cancel</Button>
            </AlertDialogCancel>
            <Button variant="destructive" onClick={confirmDelete}>
              <Trash2 />
              Delete
            </Button>
          </div>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}

interface SnapshotRowProps {
  snapshot: Snapshot;
  last: boolean;
  busy?: string;
  onDiff: () => void;
  onRestore: () => void;
  onExport: () => void;
  onDelete: () => void;
  onResume: () => void;
  /** Present only when cloud storage is configured. */
  onPush?: () => void;
}

const rollbackFiles = (s: Snapshot) => s.meta.rollback?.files ?? [];

function SnapshotRow({ snapshot: s, last, busy, onDiff, onRestore, onExport, onDelete, onResume, onPush }: SnapshotRowProps) {
  const trigger = TRIGGERS[s.trigger_type] ?? TRIGGERS.manual;
  const TriggerIcon = trigger.icon;
  const ready = s.status === 'ready';
  const lastExport = s.meta.exports?.[s.meta.exports.length - 1];
  const files = rollbackFiles(s);

  return (
    <li className="relative flex flex-wrap items-center gap-x-4 gap-y-3 border-b px-4 py-4 last:border-0 sm:pl-14">
      {/* Timeline rail */}
      <span className={cn('absolute left-[27px] top-0 hidden w-px bg-border sm:block', last ? 'h-1/2' : 'h-full')} aria-hidden />
      <span
        className={cn('absolute left-[18px] top-1/2 hidden h-5 w-5 -translate-y-1/2 items-center justify-center rounded-full border bg-background sm:flex')}
        aria-hidden
      >
        <TriggerIcon className="h-3 w-3 text-muted-foreground" />
      </span>

      {/* Full width on phones so the actions wrap below instead of squeezing the label. */}
      <div className="flex w-full min-w-0 flex-col gap-1 sm:w-auto sm:flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-mono text-xs text-muted-foreground">#{s.id}</span>
          <span className="truncate text-sm font-medium" data-e2e-label>{s.label || 'Untitled snapshot'}</span>
          <span className={cn('inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium', trigger.className)}>
            {trigger.label}
          </span>
          {s.status === 'building' && <Badge variant="warning">building</Badge>}
          {s.status === 'failed' && (
            <Badge variant="destructive" title={s.meta.error}>
              failed
            </Badge>
          )}
          {s.meta.verified === true && (
            <Badge variant="success" title={`Row counts and schemas of ${s.meta.verification?.tables ?? s.table_count} tables match`}>
              <BadgeCheck className="h-3 w-3" />
              Verified
            </Badge>
          )}
          {s.meta.verified === false && (
            <Badge variant="destructive" title={s.meta.verification?.mismatches.join('\n')}>
              Verification failed
            </Badge>
          )}
          {files.length > 0 && (
            <Badge variant="outline" title={files.map((f) => `${f.slug} ${f.old_version} → ${f.new_version ?? '?'}`).join('\n')} data-e2e-files>
              <FileArchive className="h-3 w-3" />
              {files.length} {files.length === 1 ? 'folder' : 'folders'} archived
            </Badge>
          )}
          {s.meta.last_restored_at && <Badge variant="outline">restored {timeAgo(s.meta.last_restored_at)}</Badge>}
        </div>
        {files.length > 0 && (
          <p className="truncate text-xs text-muted-foreground">
            {files.map((f) => `${f.slug} ${f.old_version || '?'} → ${f.new_version ?? '?'}`).join(' · ')}
          </p>
        )}
        <p className="text-xs text-muted-foreground tabular-nums">
          {timeAgo(s.created_at)} · {s.table_count} tables · {formatNumber(s.row_count)} rows · {formatBytes(s.size_bytes)}
          {s.note && <span className="ml-1 italic">· {s.note}</span>}
        </p>
        {s.status === 'failed' && s.meta.error && <p className="text-xs text-destructive">{s.meta.error}</p>}
      </div>

      <div className="-ml-2 flex flex-wrap items-center gap-1 sm:ml-0">
        {busy ? (
          <span className="flex items-center gap-2 px-2 text-xs text-muted-foreground">
            <Loader2 className="h-3.5 w-3.5 animate-spin" />
            {busy}
          </span>
        ) : (
          <>
            {s.status === 'building' && (
              <Button variant="outline" size="sm" onClick={onResume}>
                <RefreshCw />
                Resume
              </Button>
            )}
            <Button variant="ghost" size="sm" disabled={!ready} onClick={onDiff}>
              <GitCompare />
              Diff
            </Button>
            {files.length > 0 ? (
              <Button variant="outline" size="sm" disabled={!ready} onClick={onRestore} data-e2e="undo-update">
                <Undo2 />
                Undo update
              </Button>
            ) : (
              <Button variant="ghost" size="sm" disabled={!ready} onClick={onRestore}>
                <RotateCcw />
                Restore
              </Button>
            )}
            {lastExport?.download_url ? (
              <Button variant="ghost" size="sm" asChild>
                <a href={lastExport.download_url} title={`Download last export (${formatBytes(lastExport.bytes)})`}>
                  <Download />
                  .sql.gz
                </a>
              </Button>
            ) : (
              <Button variant="ghost" size="sm" disabled={!ready} onClick={onExport} title="Export to .sql.gz">
                <Download />
                Export
              </Button>
            )}
            {onPush && (
              <Button
                variant="ghost"
                size="sm"
                disabled={!ready}
                onClick={onPush}
                title={s.meta.cloud?.pushed_at ? `Pushed ${timeAgo(s.meta.cloud.pushed_at)} — push again` : 'Push to your cloud bucket'}
              >
                <CloudUpload />
                {s.meta.cloud?.pushed_at ? 'Pushed' : 'Push'}
              </Button>
            )}
            <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-destructive" onClick={onDelete} aria-label="Delete snapshot">
              <Trash2 />
            </Button>
          </>
        )}
      </div>
    </li>
  );
}
