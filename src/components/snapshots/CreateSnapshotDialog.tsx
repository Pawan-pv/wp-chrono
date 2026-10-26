import { useEffect, useState } from 'react';
import { Camera, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { api } from '@/lib/api';
import { formatNumber } from '@/lib/format';
import type { BuildProgress } from '@/types';

interface CreateSnapshotDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated: () => void;
}

/** Name/note form, then a live chunked-build progress bar. */
export function CreateSnapshotDialog({ open, onOpenChange, onCreated }: CreateSnapshotDialogProps) {
  const [label, setLabel] = useState('');
  const [note, setNote] = useState('');
  const [progress, setProgress] = useState<BuildProgress | null>(null);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setLabel('');
    setNote('');
    setProgress(null);
    setError(null);
  }, [open]);

  const create = async () => {
    setRunning(true);
    setError(null);
    try {
      let p = await api<BuildProgress>('snapshots', { method: 'POST', body: { label, note } });
      setProgress(p);
      const id = p.snapshot!.id;
      while (!p.done) {
        p = await api<BuildProgress>(`snapshots/${id}/build`, { method: 'POST' });
        setProgress(p);
      }
      onCreated();
      onOpenChange(false);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
      onCreated();
    } finally {
      setRunning(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(next) => !running && onOpenChange(next)}>
      <DialogContent hideClose={running} onEscapeKeyDown={(e) => running && e.preventDefault()} onPointerDownOutside={(e) => running && e.preventDefault()}>
        <div className="flex flex-col gap-1.5">
          <DialogTitle>Create snapshot</DialogTitle>
          <DialogDescription>Copies every site table into tagged snapshot tables, in batches that survive host time limits.</DialogDescription>
        </div>

        {progress ? (
          <div className="flex flex-col gap-3">
            <Progress value={progress.tables_total ? progress.tables_done / progress.tables_total : null} />
            <p className="flex items-center gap-2 text-sm text-muted-foreground tabular-nums">
              {running && <Loader2 className="h-4 w-4 animate-spin" />}
              {progress.tables_done} / {progress.tables_total} tables · {formatNumber(progress.rows_copied)} rows
              {progress.current_table && <span className="truncate font-mono text-xs">· {progress.current_table}</span>}
            </p>
          </div>
        ) : (
          <form
            className="flex flex-col gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              create();
            }}
          >
            <label className="flex flex-col gap-1.5 text-sm">
              Name
              <Input autoFocus value={label} maxLength={191} onChange={(e) => setLabel(e.target.value)} placeholder="Before switching themes" />
            </label>
            <label className="flex flex-col gap-1.5 text-sm">
              Note <span className="text-xs text-muted-foreground">(optional)</span>
              <textarea
                value={note}
                maxLength={2000}
                onChange={(e) => setNote(e.target.value)}
                rows={3}
                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                placeholder="What are you about to try?"
              />
            </label>
            <div className="flex justify-end gap-2">
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button type="submit">
                <Camera />
                Create snapshot
              </Button>
            </div>
          </form>
        )}

        {error && <p className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">{error}</p>}
      </DialogContent>
    </Dialog>
  );
}
