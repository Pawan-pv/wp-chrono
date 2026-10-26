import { useEffect, useState } from 'react';
import { Loader2, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { api } from '@/lib/api';
import { formatBytes } from '@/lib/format';
import type { OrphanScan } from '@/types';

interface ScavengerDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onPurged: () => void;
}

export function ScavengerDialog({ open, onOpenChange, onPurged }: ScavengerDialogProps) {
  const [scan, setScan] = useState<OrphanScan | null>(null);
  const [purging, setPurging] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setScan(null);
    setMessage(null);
    api<OrphanScan>('snapshots/orphans').then(setScan, (e: Error) => setMessage(e.message));
  }, [open]);

  const purge = async () => {
    if (!scan) return;
    setPurging(true);
    try {
      const res = await api<{ dropped: string[] }>('snapshots/orphans/purge', {
        method: 'POST',
        body: { confirm: 'PURGE', tables: scan.tables.map((t) => t.name) },
      });
      setMessage(`Dropped ${res.dropped.length} tables and freed ${formatBytes(scan.bytes)}.`);
      setScan({ tables: [], bytes: 0 });
      onPurged();
    } catch (e) {
      setMessage(e instanceof Error ? e.message : String(e));
    } finally {
      setPurging(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <div className="flex flex-col gap-1.5">
          <DialogTitle>Orphaned table scavenger</DialogTitle>
          <DialogDescription>
            Snapshot-style tables (<code>_wpc_*</code>, tagged comments, legacy WP Reset copies) that no snapshot owns. Live tables
            and tables belonging to other installs in this database are never listed.
          </DialogDescription>
        </div>

        {!scan && !message && (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" /> Scanning INFORMATION_SCHEMA…
          </p>
        )}
        {scan && scan.tables.length === 0 && !message && (
          <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">No orphaned tables. 🎉</p>
        )}
        {scan && scan.tables.length > 0 && (
          <ul className="max-h-72 divide-y overflow-y-auto rounded-lg border">
            {scan.tables.map((t) => (
              <li key={t.name} className="flex items-center gap-3 p-3 text-sm">
                <span className="flex min-w-0 flex-col">
                  <span className="truncate font-mono text-xs">{t.name}</span>
                  <span className="text-xs text-muted-foreground">{t.reason}</span>
                </span>
                <span className="ml-auto shrink-0 text-xs text-muted-foreground tabular-nums">{formatBytes(t.bytes)}</span>
              </li>
            ))}
          </ul>
        )}
        {message && <p className="rounded-md border bg-muted/40 p-3 text-sm">{message}</p>}

        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
          {scan && scan.tables.length > 0 && (
            <Button variant="destructive" onClick={purge} disabled={purging}>
              {purging ? <Loader2 className="animate-spin" /> : <Trash2 />}
              Drop {scan.tables.length} tables ({formatBytes(scan.bytes)})
            </Button>
          )}
        </div>
      </DialogContent>
    </Dialog>
  );
}
