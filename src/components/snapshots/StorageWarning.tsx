import { useState } from 'react';
import { HardDrive, Loader2, Scissors } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { formatBytes } from '@/lib/format';
import type { StorageUsage } from '@/types';

interface StorageWarningProps {
  usage: StorageUsage | undefined;
  /** Called after pruning so the parent can refetch. */
  onPruned: () => void;
}

/**
 * Non-intrusive bloat warning: shown only when WP Chrono's storage (snapshot
 * tables + archives + exports) passes the threshold or 25% of free disk.
 */
export function StorageWarning({ usage, onPruned }: StorageWarningProps) {
  const [pruning, setPruning] = useState(false);
  const [result, setResult] = useState<string | null>(null);

  if (!usage?.warn && !result) return null;

  const prune = async () => {
    setPruning(true);
    try {
      const r = await api<{ deleted: number[]; storage: StorageUsage }>('snapshots/prune-auto', { method: 'POST', body: { keep: 1 } });
      setResult(
        r.deleted.length
          ? `Pruned ${r.deleted.length} old auto-snapshot(s). WP Chrono now uses ${formatBytes(r.storage.total_bytes)}.`
          : 'No old auto-snapshots to prune.',
      );
      onPruned();
    } catch (e) {
      setResult(e instanceof Error ? e.message : String(e));
    } finally {
      setPruning(false);
    }
  };

  return (
    <div
      role="status"
      data-e2e="storage-warning"
      className="flex flex-wrap items-center gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm"
    >
      <HardDrive className="h-4 w-4 shrink-0 text-amber-600" />
      <span className="min-w-0 flex-1">
        {result ?? (
          <>
            {usage?.reason}{' '}
            <span className="text-muted-foreground">
              ({formatBytes(usage?.snapshot_bytes)} in snapshot tables, {formatBytes(usage?.file_bytes)} in archives & exports)
            </span>
          </>
        )}
      </span>
      {!result && usage && usage.prunable.count > 0 && (
        <Button size="sm" variant="outline" onClick={prune} disabled={pruning} className="border-amber-500/40">
          {pruning ? <Loader2 className="animate-spin" /> : <Scissors />}
          Prune old auto-snapshots ({usage.prunable.count} · {formatBytes(usage.prunable.bytes)})
        </Button>
      )}
      {!result && usage && usage.prunable.count === 0 && !location.hash.startsWith('#/snapshots') && (
        <Button size="sm" variant="outline" asChild className="border-amber-500/40">
          <a href="#/snapshots">Review snapshots</a>
        </Button>
      )}
      {result && (
        <button type="button" className="text-xs text-muted-foreground" onClick={() => setResult(null)}>
          Dismiss
        </button>
      )}
    </div>
  );
}
