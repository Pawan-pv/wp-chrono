import { useCallback, useEffect, useMemo, useState } from 'react';
import { ChevronDown, Eye, Loader2, Play, RefreshCw, RotateCcw, ShieldAlert, ShieldCheck } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { ConfirmResetDialog, type ConfirmResetDialogProps } from '@/components/reset/ConfirmResetDialog';
import { fullResetPlan, partialPlan } from '@/components/reset/plans';
import { useApi } from '@/hooks/useApi';
import type { RouteParams } from '@/hooks/useHashRoute';
import { api } from '@/lib/api';
import { formatBytes, formatNumber } from '@/lib/format';
import { cn } from '@/lib/utils';
import { RESET_TARGETS, targetMeta, type TargetMeta } from '@/resetTargets';
import type { ResetPreviewResponse, ResetTarget, TargetPreview } from '@/types';

type DialogState = Omit<ConfirmResetDialogProps, 'open' | 'onOpenChange' | 'onFinished'>;

interface ResetHubProps {
  params: RouteParams;
  clearParams: () => void;
}

export function ResetHub({ params, clearParams }: ResetHubProps) {
  const { data, error, loading, reload } = useApi<ResetPreviewResponse>('reset/preview');
  const [previews, setPreviews] = useState<Partial<Record<ResetTarget, TargetPreview>>>({});
  const [refreshing, setRefreshing] = useState<ResetTarget | null>(null);
  const [mode, setMode] = useState<'truncate' | 'drop'>('truncate');
  const [retain, setRetain] = useState({ keep_theme: true, keep_plugins: false, keep_users: false, snapshot: true });
  // Content is kept after closing so the exit animation doesn't flash an empty dialog.
  const [dialog, setDialog] = useState<DialogState | null>(null);
  const [dialogOpen, setDialogOpen] = useState(false);
  const show = useCallback((state: DialogState) => {
    setDialog(state);
    setDialogOpen(true);
  }, []);

  // Per-card dry runs override the bulk preview until the next full reload.
  useEffect(() => {
    setPreviews({});
  }, [data]);
  const preview = useCallback((id: ResetTarget) => previews[id] ?? data?.targets[id], [previews, data]);

  const dryRun = async (id: ResetTarget) => {
    setRefreshing(id);
    try {
      const result = await api<TargetPreview>(`reset/partial/${id}`, { method: 'POST', body: { dry_run: true, mode } });
      setPreviews((p) => ({ ...p, [id]: result }));
    } finally {
      setRefreshing(null);
    }
  };

  const openPartial = useCallback(
    (id: ResetTarget) => {
      const meta = targetMeta(id);
      const p = preview(id);
      const drop = id === 'custom_tables' && mode === 'drop';
      show({
        title: drop ? 'Drop custom tables' : meta.title,
        description: p
          ? `${meta.description} This will remove ${p.approximate ? '~' : ''}${formatNumber(p.items)} ${meta.unit} (${formatBytes(p.bytes)}).`
          : meta.description,
        details: p?.details,
        unavailableReason: p && !p.available ? p.reason : null,
        confirmLabel: drop ? 'Drop tables' : 'Run cleanup',
        plan: partialPlan(id, { total: p?.items ?? 0, mode }),
      });
    },
    [preview, mode, show],
  );

  const openFull = useCallback(() => {
    const full = data?.full;
    show({
      title: 'Reset the entire site',
      description: `Drops ${full?.tables ?? 'all'} tables${full ? ` (${formatBytes(full.bytes)})` : ''} and reinstalls WordPress. You stay signed in; WP Chrono and its snapshots are kept.`,
      details: [
        `Theme: ${retain.keep_theme ? 'reactivated' : 'reset to default'}`,
        `Plugins: ${retain.keep_plugins ? 'reactivated' : 'deactivated (files stay installed)'}`,
        `Users: ${retain.keep_users ? 'kept' : 'only your account is kept'}`,
        'Posts, pages, comments, settings and custom tables: deleted',
      ],
      unavailableReason: full && !full.available ? 'Full reset is not supported on multisite networks.' : null,
      confirmLabel: 'Reset site',
      defaultSnapshot: retain.snapshot,
      plan: fullResetPlan({ keep_theme: retain.keep_theme, keep_plugins: retain.keep_plugins, keep_users: retain.keep_users }),
    });
  }, [data, retain, show]);

  // One-shot intents from the command palette: #/reset?run=<target|full>
  useEffect(() => {
    const run = params.run;
    if (!run || !data) return;
    clearParams();
    if (run === 'full') openFull();
    else if (RESET_TARGETS.some((t) => t.id === run)) openPartial(run as ResetTarget);
  }, [params, data, clearParams, openFull, openPartial]);

  return (
    <div className="flex flex-col gap-10">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Reset Hub</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Preview exactly what will be removed, then run it in safe, resumable batches.
          </p>
        </div>
        <Button variant="outline" size="sm" onClick={reload} disabled={loading}>
          <RefreshCw className={cn(loading && 'animate-spin')} />
          Refresh counts
        </Button>
      </div>

      {error && <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm">{error.message}</p>}

      <section className="flex flex-col gap-4">
        <div>
          <h2 className="text-sm font-semibold">Partial cleanup</h2>
          <p className="text-sm text-muted-foreground">Targeted tools that leave the rest of your site untouched.</p>
        </div>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {RESET_TARGETS.map((meta) => (
            <TargetCard
              key={meta.id}
              meta={meta}
              preview={preview(meta.id)}
              refreshing={refreshing === meta.id}
              mode={meta.id === 'custom_tables' ? mode : undefined}
              onModeChange={setMode}
              onPreview={() => dryRun(meta.id)}
              onRun={() => openPartial(meta.id)}
            />
          ))}
        </div>
      </section>

      <section className="flex flex-col gap-4">
        <div>
          <h2 className="text-sm font-semibold text-destructive">Danger zone</h2>
          <p className="text-sm text-muted-foreground">Irreversible without a snapshot.</p>
        </div>
        <Card className="border-destructive/40">
          <CardHeader className="flex-row items-start gap-4">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-destructive/10 text-destructive">
              <ShieldAlert className="h-5 w-5" />
            </span>
            <div className="flex flex-col gap-1">
              <CardTitle className="text-base">Full site reset</CardTitle>
              <CardDescription>
                {data
                  ? `Drops ${data.full.tables} tables (${formatBytes(data.full.bytes)}) and reinstalls WordPress with your current site title, URLs and admin account.`
                  : 'Drops every site table and reinstalls WordPress with your current site title, URLs and admin account.'}
              </CardDescription>
            </div>
          </CardHeader>
          <CardContent className="flex flex-col gap-5">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Retention
                label="Keep active theme"
                hint="Reactivate it after the reset"
                checked={retain.keep_theme}
                onChange={(v) => setRetain((r) => ({ ...r, keep_theme: v }))}
              />
              <Retention
                label="Keep active plugins"
                hint="Reactivate them after the reset"
                checked={retain.keep_plugins}
                onChange={(v) => setRetain((r) => ({ ...r, keep_plugins: v }))}
              />
              <Retention
                label="Keep current users"
                hint="Preserve every account and role"
                checked={retain.keep_users}
                onChange={(v) => setRetain((r) => ({ ...r, keep_users: v }))}
              />
              <Retention
                label="Create safety snapshot"
                hint="Snapshot the database first"
                checked={retain.snapshot}
                onChange={(v) => setRetain((r) => ({ ...r, snapshot: v }))}
              />
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
              <p className="text-xs text-muted-foreground">WP Chrono stays active and its snapshot tables are never dropped.</p>
              <Button variant="destructive" onClick={openFull} disabled={!data || !data.full.available}>
                <RotateCcw />
                Reset entire site…
              </Button>
            </div>
          </CardContent>
        </Card>
      </section>

      <ConfirmResetDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        onFinished={reload}
        {...(dialog ?? { title: '', description: '', confirmLabel: '', plan: null })}
      />
    </div>
  );
}

interface TargetCardProps {
  meta: TargetMeta;
  preview?: TargetPreview;
  refreshing: boolean;
  mode?: 'truncate' | 'drop';
  onModeChange: (mode: 'truncate' | 'drop') => void;
  onPreview: () => void;
  onRun: () => void;
}

function TargetCard({ meta, preview, refreshing, mode, onModeChange, onPreview, onRun }: TargetCardProps) {
  const [expanded, setExpanded] = useState(false);
  const Icon = meta.icon;
  const empty = preview?.available && preview.items === 0 && preview.bytes === 0;

  const badge = useMemo(() => {
    if (!preview) return <Skeleton className="h-5 w-24" />;
    if (!preview.available) return <Badge variant="secondary">Unavailable</Badge>;
    if (empty) return <Badge variant="success">Clean</Badge>;
    return (
      <>
        <Badge variant="outline" className="tabular-nums">
          {preview.approximate ? '~' : ''}
          {formatNumber(preview.items)} {meta.unit}
        </Badge>
        {preview.bytes > 0 && (
          <Badge variant="secondary" className="tabular-nums">
            {formatBytes(preview.bytes)}
          </Badge>
        )}
      </>
    );
  }, [preview, empty, meta.unit]);

  return (
    <Card className={cn('flex flex-col transition-colors', preview && !preview.available && 'opacity-70')}>
      <CardHeader className="gap-3 pb-3">
        <div className="flex items-center justify-between">
          <span className="flex h-8 w-8 items-center justify-center rounded-md border bg-secondary">
            <Icon className="h-4 w-4" />
          </span>
          <div className="flex items-center gap-1.5">{badge}</div>
        </div>
        <div className="flex flex-col gap-1">
          <CardTitle>{meta.title}</CardTitle>
          <CardDescription className="text-xs leading-relaxed">
            {preview && !preview.available ? preview.reason : meta.description}
          </CardDescription>
        </div>
      </CardHeader>

      <CardContent className="mt-auto flex flex-col gap-3">
        {mode && (
          <div className="grid grid-cols-2 rounded-md border p-0.5 text-xs">
            {(['truncate', 'drop'] as const).map((m) => (
              <button
                key={m}
                type="button"
                onClick={() => onModeChange(m)}
                className={cn(
                  'rounded px-2 py-1 capitalize transition-colors',
                  mode === m ? 'bg-secondary font-medium text-foreground' : 'text-muted-foreground hover:text-foreground',
                )}
              >
                {m}
              </button>
            ))}
          </div>
        )}

        {preview?.protected && preview.protected.length > 0 && (
          <p
            className="flex items-start gap-1.5 rounded-md border border-emerald-500/30 bg-emerald-500/5 p-2 text-xs text-emerald-700 dark:text-emerald-400"
            title={preview.protected.join(', ')}
            data-e2e="protected-backups"
          >
            <ShieldCheck className="mt-0.5 h-3.5 w-3.5 shrink-0" />
            {preview.protected.length} backup {preview.protected.length === 1 ? 'folder' : 'folders'} protected and never touched (
            {preview.protected.join(', ')})
          </p>
        )}

        {expanded && preview && preview.details.length > 0 && (
          <ul className="max-h-32 overflow-y-auto rounded-md border bg-muted/40 p-2 font-mono text-[11px] leading-relaxed text-muted-foreground">
            {preview.details.map((d) => (
              <li key={d} className="truncate" title={d}>
                {d}
              </li>
            ))}
          </ul>
        )}

        <div className="flex items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            className="px-2"
            disabled={!preview?.available || refreshing}
            onClick={() => {
              setExpanded(true);
              onPreview();
            }}
          >
            {refreshing ? <Loader2 className="animate-spin" /> : <Eye />}
            Dry run
          </Button>
          {preview && preview.details.length > 0 && (
            <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => setExpanded((e) => !e)} aria-label="Toggle details">
              <ChevronDown className={cn('transition-transform', expanded && 'rotate-180')} />
            </Button>
          )}
          <Button variant="outline" size="sm" className="ml-auto" disabled={!preview?.available || empty} onClick={onRun}>
            <Play />
            Run
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

function Retention({ label, hint, checked, onChange }: { label: string; hint: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <label className="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors hover:bg-accent/50">
      <Checkbox checked={checked} onCheckedChange={(v) => onChange(v === true)} className="mt-0.5" />
      <span className="flex flex-col">
        <span className="text-sm font-medium">{label}</span>
        <span className="text-xs text-muted-foreground">{hint}</span>
      </span>
    </label>
  );
}
