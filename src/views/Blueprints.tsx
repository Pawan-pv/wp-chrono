import { useCallback, useEffect, useRef, useState } from 'react';
import {
  Blocks,
  ChevronDown,
  Download,
  FileJson,
  Loader2,
  Package,
  Palette,
  Plug,
  RefreshCw,
  RotateCcw,
  Trash2,
  Upload,
  X,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { CaptureBlueprintDialog } from '@/components/blueprints/CaptureBlueprintDialog';
import { CloudPanel } from '@/components/blueprints/CloudPanel';
import { ProLockCard } from '@/components/ProLockCard';
import { ConfirmResetDialog } from '@/components/reset/ConfirmResetDialog';
import { blueprintPlan } from '@/components/reset/plans';
import { useApi } from '@/hooks/useApi';
import { useLicense } from '@/hooks/useLicense';
import type { RouteParams } from '@/hooks/useHashRoute';
import { api, apiUpload } from '@/lib/api';
import { formatBytes, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Blueprint, BlueprintItem } from '@/types';

interface BlueprintsProps {
  params: RouteParams;
  clearParams: () => void;
  onOpenLicense: () => void;
}

const SOURCE_LABEL: Record<BlueprintItem['source'], string> = { package: 'zip', url: 'URL', wporg: 'wordpress.org' };

export function Blueprints({ params, clearParams, onOpenLicense }: BlueprintsProps) {
  const license = useLicense();
  const pro = license.features.blueprints;
  const { data, error, loading, reload } = useApi<{ blueprints: Blueprint[] }>('blueprints');
  const [capturing, setCapturing] = useState(false);
  const [applying, setApplying] = useState<Blueprint | null>(null);
  const [applyOpen, setApplyOpen] = useState(false);
  const [deleting, setDeleting] = useState<Blueprint | null>(null);
  const [notice, setNotice] = useState<{ text: string; tone: 'ok' | 'err' } | null>(null);
  const importInput = useRef<HTMLInputElement>(null);

  // One-shot intents from the command palette.
  useEffect(() => {
    if (!params.run) return;
    clearParams();
    if (params.run === 'capture') setCapturing(true);
  }, [params, clearParams]);

  const say = useCallback((text: string, tone: 'ok' | 'err' = 'ok') => setNotice({ text, tone }), []);

  const importFile = async (file: File) => {
    try {
      const bp = await api<Blueprint>('blueprints/import', { method: 'POST', body: { blueprint: await file.text() } });
      say(`Imported blueprint "${bp.name}" (${bp.plugin_count} plugins, ${bp.theme_count} themes).`);
      reload();
    } catch (e) {
      say(e instanceof Error ? e.message : String(e), 'err');
    }
  };

  const exportBlueprint = async (bp: Blueprint, embed: boolean) => {
    try {
      const doc = await api<unknown>(`blueprints/${bp.id}/export`, { query: { embed } });
      const url = URL.createObjectURL(new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' }));
      const a = Object.assign(document.createElement('a'), { href: url, download: `${bp.name.replace(/[^\w.-]+/g, '-')}.wpchrono-blueprint.json` });
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      say(e instanceof Error ? e.message : String(e), 'err');
    }
  };

  const confirmDelete = async () => {
    if (!deleting) return;
    const bp = deleting;
    setDeleting(null);
    try {
      await api(`blueprints/${bp.id}`, { method: 'DELETE' });
      reload();
    } catch (e) {
      say(e instanceof Error ? e.message : String(e), 'err');
    }
  };

  const blueprints = data?.blueprints ?? [];

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Blueprints & Cloud</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            Reusable dev baselines (plugins, theme, settings) and off-site storage for snapshots in your own bucket.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="sm" onClick={reload} disabled={loading}>
            <RefreshCw className={cn(loading && 'animate-spin')} />
            Refresh
          </Button>
          <input
            ref={importInput}
            type="file"
            accept="application/json,.json"
            className="hidden"
            data-e2e="import-input"
            onChange={(e) => {
              const file = e.target.files?.[0];
              e.target.value = '';
              if (file) importFile(file);
            }}
          />
          {pro && (
            <>
              <Button variant="outline" size="sm" onClick={() => importInput.current?.click()}>
                <FileJson />
                Import JSON
              </Button>
              <Button size="sm" onClick={() => setCapturing(true)}>
                <Blocks />
                Capture current site
              </Button>
            </>
          )}
        </div>
      </div>

      {!pro && <ProLockCard feature="Blueprints (Reset to Dev Baseline)" onEnterLicense={onOpenLicense} />}

      {notice && (
        <p
          className={cn(
            'flex items-center justify-between gap-3 rounded-lg border p-3 text-sm',
            notice.tone === 'err' ? 'border-destructive/40 bg-destructive/5' : 'bg-muted/40',
          )}
          data-e2e="blueprint-notice"
        >
          {notice.text}
          <button type="button" className="text-xs text-muted-foreground" onClick={() => setNotice(null)}>
            Dismiss
          </button>
        </p>
      )}
      {error && <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm">{error.message}</p>}

      <Card className="overflow-hidden">
        {!data &&
          Array.from({ length: 2 }, (_, i) => (
            <div key={i} className="border-b p-4 last:border-0">
              <Skeleton className="h-10 w-full" />
            </div>
          ))}
        {data && blueprints.length === 0 && (
          <div className="flex flex-col items-center gap-3 px-6 py-16 text-center">
            <span className="flex h-12 w-12 items-center justify-center rounded-xl border bg-secondary">
              <Blocks className="h-5 w-5" />
            </span>
            <p className="font-medium">No blueprints yet</p>
            <p className="max-w-md text-sm text-muted-foreground">
              Set a site up the way you like to start projects, then capture it. "Reset to Dev Baseline" wipes the site and rebuilds exactly
              that stack in one click — offline too, thanks to bundled zips.
            </p>
            {pro && (
              <Button size="sm" onClick={() => setCapturing(true)}>
                <Blocks />
                Capture current site
              </Button>
            )}
          </div>
        )}
        <ul className="divide-y">
          {blueprints.map((bp) => (
            <BlueprintRow
              key={bp.id}
              blueprint={bp}
              locked={!pro}
              onApply={() => {
                setApplying(bp);
                setApplyOpen(true);
              }}
              onExport={(embed) => exportBlueprint(bp, embed)}
              onDelete={() => setDeleting(bp)}
              onChanged={reload}
              onError={(m) => say(m, 'err')}
            />
          ))}
        </ul>
      </Card>

      <CloudPanel locked={!license.features.cloud} onEnterLicense={onOpenLicense} onPulled={(m) => say(`${m} Find it on the Snapshots screen.`)} />

      <CaptureBlueprintDialog
        open={capturing}
        onOpenChange={setCapturing}
        onCaptured={(bp) => {
          say(`Captured "${bp.name}": ${bp.plugin_count} plugins, ${bp.theme_count} theme(s), ${bp.option_count} settings${bp.package_bytes ? `, ${formatBytes(bp.package_bytes)} of zips` : ''}.`);
          reload();
        }}
      />

      <ConfirmResetDialog
        open={applyOpen}
        onOpenChange={setApplyOpen}
        onFinished={reload}
        title={applying ? `Reset to Dev Baseline: ${applying.name}` : 'Reset to Dev Baseline'}
        description="Wipes the database like a full reset (you stay signed in), removes plugins that are not in the blueprint, then installs and activates the blueprint's plugins and theme and applies its settings."
        details={
          applying
            ? [
                ...applying.plugins.map((p) => `plugin  ${p.slug} ${p.version} (${SOURCE_LABEL[p.source]})${p.active ? '' : ' — inactive'}`),
                ...applying.themes.map((t) => `theme   ${t.slug} ${t.version} (${SOURCE_LABEL[t.source]})${t.active ? ' — active' : ''}`),
                `${applying.option_count} baseline settings`,
              ]
            : []
        }
        confirmLabel="Reset to baseline"
        plan={applying ? blueprintPlan(applying) : null}
      />

      <AlertDialog open={deleting !== null} onOpenChange={(open) => !open && setDeleting(null)}>
        <AlertDialogContent>
          <AlertDialogTitle>Delete blueprint "{deleting?.name}"?</AlertDialogTitle>
          <AlertDialogDescription>
            Its stored zips ({formatBytes(deleting?.package_bytes ?? 0)}) are removed unless another blueprint uses them. Your site is not changed.
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

interface BlueprintRowProps {
  blueprint: Blueprint;
  onApply: () => void;
  onExport: (embed: boolean) => void;
  onDelete: () => void;
  onChanged: () => void;
  onError: (message: string) => void;
  /** No license: export and delete still work (it's your data); applying and editing don't. */
  locked: boolean;
}

function BlueprintRow({ blueprint: bp, locked, onApply, onExport, onDelete, onChanged, onError }: BlueprintRowProps) {
  const [expanded, setExpanded] = useState(false);
  const [uploading, setUploading] = useState(false);
  const upload = useRef<HTMLInputElement>(null);

  const addZip = async (file: File) => {
    setUploading(true);
    try {
      await apiUpload(`blueprints/${bp.id}/packages`, 'package', file);
      setExpanded(true);
      onChanged();
    } catch (e) {
      onError(e instanceof Error ? e.message : String(e));
    } finally {
      setUploading(false);
    }
  };

  const removeItem = async (item: BlueprintItem) => {
    try {
      await api(`blueprints/${bp.id}/items`, { method: 'DELETE', query: { type: item.type, slug: item.slug } });
      onChanged();
    } catch (e) {
      onError(e instanceof Error ? e.message : String(e));
    }
  };

  return (
    <li className="flex flex-col gap-3 p-4" data-e2e-blueprint={bp.name}>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-3">
        <div className="flex w-full min-w-0 flex-col gap-1 sm:w-auto sm:flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <span className="truncate text-sm font-medium">{bp.name}</span>
            <Badge variant="outline">
              <Plug className="h-3 w-3" /> {bp.plugin_count}
            </Badge>
            <Badge variant="outline">
              <Palette className="h-3 w-3" /> {bp.theme_count}
            </Badge>
            <Badge variant="outline">{bp.option_count} settings</Badge>
            {bp.package_bytes > 0 && (
              <Badge variant="secondary">
                <Package className="h-3 w-3" /> {formatBytes(bp.package_bytes)} zips
              </Badge>
            )}
          </div>
          <p className="text-xs text-muted-foreground">
            captured {timeAgo(bp.created_at)}
            {bp.description && <span className="ml-1 italic">· {bp.description}</span>}
          </p>
        </div>
        <div className="-ml-2 flex flex-wrap items-center gap-1 sm:ml-0">
          <Button size="sm" onClick={onApply} disabled={locked} data-e2e="apply-blueprint">
            <RotateCcw />
            Reset to Dev Baseline
          </Button>
          <Button variant="ghost" size="sm" onClick={() => onExport(false)} title="Download as JSON (zips stay here; wordpress.org slugs used elsewhere)">
            <Download />
            Export
          </Button>
          <Button variant="ghost" size="sm" onClick={() => onExport(true)} title="Self-contained JSON including the plugin / theme zips">
            <Download />
            + zips
          </Button>
          <Button variant="ghost" size="icon" className="h-8 w-8" onClick={() => setExpanded((e) => !e)} aria-label="Show contents">
            <ChevronDown className={cn('transition-transform', expanded && 'rotate-180')} />
          </Button>
          <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-destructive" onClick={onDelete} aria-label="Delete blueprint">
            <Trash2 />
          </Button>
        </div>
      </div>

      {expanded && (
        <div className="flex flex-col gap-2 rounded-lg border bg-muted/30 p-3">
          <ul className="flex flex-col gap-1.5">
            {[...bp.plugins, ...bp.themes].map((item) => (
              <li key={`${item.type}:${item.slug}`} className="flex items-center gap-2 text-xs">
                {item.type === 'theme' ? <Palette className="h-3.5 w-3.5 text-muted-foreground" /> : <Plug className="h-3.5 w-3.5 text-muted-foreground" />}
                <span className="font-medium">{item.name || item.slug}</span>
                <span className="font-mono text-muted-foreground">{item.version}</span>
                <Badge variant={item.source === 'package' ? 'success' : 'outline'} className="px-1.5 py-0 text-[10px]">
                  {SOURCE_LABEL[item.source]}
                </Badge>
                {item.active && <Badge variant="secondary" className="px-1.5 py-0 text-[10px]">active</Badge>}
                <button
                  type="button"
                  onClick={() => removeItem(item)}
                  className="ml-auto rounded p-0.5 text-muted-foreground hover:text-destructive"
                  aria-label={`Remove ${item.slug}`}
                >
                  <X className="h-3.5 w-3.5" />
                </button>
              </li>
            ))}
          </ul>
          <div className="flex items-center gap-2 border-t pt-2">
            <input
              ref={upload}
              type="file"
              accept=".zip,application/zip"
              className="hidden"
              onChange={(e) => {
                const file = e.target.files?.[0];
                e.target.value = '';
                if (file) addZip(file);
              }}
            />
            <Button variant="outline" size="sm" disabled={uploading || locked} onClick={() => upload.current?.click()}>
              {uploading ? <Loader2 className="animate-spin" /> : <Upload />}
              Add plugin / theme .zip
            </Button>
            <span className="text-xs text-muted-foreground">Premium or custom plugins: upload the zip once, it is stored with the blueprint.</span>
          </div>
        </div>
      )}
    </li>
  );
}
