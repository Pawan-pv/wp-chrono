import { useEffect, useRef, useState } from 'react';
import { FileUp, Loader2, Upload } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { api, apiUpload } from '@/lib/api';
import { formatBytes } from '@/lib/format';
import type { ImportTick } from '@/types';

interface ImportSnapshotDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** Largest chunk the server accepts (from GET /snapshots). */
  maxChunk: number;
  onImported: (message: string) => void;
}

const MAX_TICKS = 5000;

/**
 * Upload a WP Chrono .sql.gz back into the site (chunked, so any size works
 * regardless of PHP upload limits), then rebuild it as a verified snapshot.
 */
export function ImportSnapshotDialog({ open, onOpenChange, maxChunk, onImported }: ImportSnapshotDialogProps) {
  const [file, setFile] = useState<File | null>(null);
  const [phase, setPhase] = useState<'pick' | 'upload' | 'import'>('pick');
  const [progress, setProgress] = useState<number | null>(0);
  const [status, setStatus] = useState('');
  const [error, setError] = useState<string | null>(null);
  const input = useRef<HTMLInputElement>(null);
  const running = phase !== 'pick';

  useEffect(() => {
    if (!open) return;
    setFile(null);
    setPhase('pick');
    setProgress(0);
    setStatus('');
    setError(null);
  }, [open]);

  const run = async () => {
    if (!file) return;
    setError(null);
    setPhase('upload');
    try {
      const size = Math.max(65536, maxChunk || 2 * 1024 * 1024);
      let upload = '';
      let tick: ImportTick | null = null;
      for (let offset = 0; offset < file.size || offset === 0; offset += size) {
        const last = offset + size >= file.size;
        tick = await apiUpload<ImportTick>(
          'snapshots/import/chunk',
          'chunk',
          file.slice(offset, offset + size),
          { upload, offset: String(offset), final: last ? '1' : '0', name: file.name },
          'chunk.part',
        );
        upload = tick.upload ?? upload;
        setProgress(Math.min(1, (offset + size) / file.size));
        setStatus(last ? tick.message : `Uploaded ${formatBytes(Math.min(file.size, offset + size))} of ${formatBytes(file.size)}`);
        if (last) break;
      }
      setPhase('import');
      setProgress(null);
      for (let i = 0; i < MAX_TICKS && tick && !tick.done; i++) {
        tick = await api<ImportTick>('snapshots/import/tick', { method: 'POST', body: { job: tick.job } });
        setStatus(tick.message);
      }
      if (!tick?.done) throw new Error('The import did not finish.');
      onImported(tick.message);
      onOpenChange(false);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
      setPhase('pick');
    }
  };

  return (
    <Dialog open={open} onOpenChange={(next) => !running && onOpenChange(next)}>
      <DialogContent hideClose={running} onEscapeKeyDown={(e) => running && e.preventDefault()} onPointerDownOutside={(e) => running && e.preventDefault()}>
        <div className="flex flex-col gap-1.5">
          <DialogTitle>Import a .sql.gz snapshot</DialogTitle>
          <DialogDescription>
            Upload a backup you exported from WP Chrono — even onto a freshly reset site. It is checked, loaded into a new snapshot (never
            straight into your live tables) and verified; then restore it like any other snapshot. Always free.
          </DialogDescription>
        </div>

        {phase === 'pick' ? (
          <div className="flex flex-col gap-3">
            <input
              ref={input}
              type="file"
              accept=".gz,application/gzip"
              className="hidden"
              data-e2e="import-file"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
            />
            <button
              type="button"
              onClick={() => input.current?.click()}
              className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-6 text-sm text-muted-foreground transition-colors hover:bg-muted/50"
            >
              <FileUp className="h-6 w-6" />
              {file ? (
                <span className="font-medium text-foreground">
                  {file.name} · {formatBytes(file.size)}
                </span>
              ) : (
                'Choose a WP Chrono .sql.gz export'
              )}
            </button>
            <p className="text-xs text-muted-foreground">
              Only files exported by WP Chrono are accepted, and only tables with this site's prefix. Large files are sent in{' '}
              {formatBytes(maxChunk)} pieces, so upload limits don't matter.
            </p>
            {error && <p className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">{error}</p>}
            <div className="flex justify-end gap-2">
              <Button variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
              <Button disabled={!file} onClick={run} data-e2e="import-start">
                <Upload />
                Upload & import
              </Button>
            </div>
          </div>
        ) : (
          <div className="flex flex-col gap-3">
            <Progress value={progress} />
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
              <Loader2 className="h-4 w-4 shrink-0 animate-spin" />
              {phase === 'upload' ? 'Uploading… ' : 'Importing… '}
              <span className="truncate">{status}</span>
            </p>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}
