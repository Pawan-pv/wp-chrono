import { useEffect, useState } from 'react';
import { Blocks, Loader2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { api } from '@/lib/api';
import type { Blueprint } from '@/types';

interface CaptureBlueprintDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCaptured: (bp: Blueprint) => void;
}

/** Saves the current site's plugins, theme and baseline settings as a blueprint. */
export function CaptureBlueprintDialog({ open, onOpenChange, onCaptured }: CaptureBlueprintDialogProps) {
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [bundle, setBundle] = useState(true);
  const [inactive, setInactive] = useState(true);
  const [running, setRunning] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setName('');
    setDescription('');
    setBundle(true);
    setInactive(true);
    setError(null);
  }, [open]);

  const capture = async () => {
    setRunning(true);
    setError(null);
    try {
      const bp = await api<Blueprint>('blueprints', {
        method: 'POST',
        body: { name: name.trim() || 'Dev baseline', description, bundle, include_inactive: inactive },
      });
      onCaptured(bp);
      onOpenChange(false);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setRunning(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={(next) => !running && onOpenChange(next)}>
      <DialogContent hideClose={running} onEscapeKeyDown={(e) => running && e.preventDefault()} onPointerDownOutside={(e) => running && e.preventDefault()}>
        <div className="flex flex-col gap-1.5">
          <DialogTitle>Capture current site as blueprint</DialogTitle>
          <DialogDescription>
            Saves installed plugins, the active theme and 24 baseline settings (permalinks, timezone, date format, media sizes…).
          </DialogDescription>
        </div>
        <form
          className="flex flex-col gap-3"
          onSubmit={(e) => {
            e.preventDefault();
            capture();
          }}
        >
          <label className="flex flex-col gap-1.5 text-sm">
            Name
            <Input autoFocus value={name} maxLength={120} onChange={(e) => setName(e.target.value)} placeholder="Agency dev baseline" />
          </label>
          <label className="flex flex-col gap-1.5 text-sm">
            Description <span className="text-xs text-muted-foreground">(optional)</span>
            <Input value={description} maxLength={2000} onChange={(e) => setDescription(e.target.value)} placeholder="Our standard stack for client builds" />
          </label>
          <label className="flex cursor-pointer items-center justify-between gap-4 rounded-lg border p-3">
            <span className="flex flex-col">
              <span className="text-sm font-medium">Bundle local copies (.zip)</span>
              <span className="text-xs text-muted-foreground">Works offline and for premium plugins wordpress.org can't serve.</span>
            </span>
            <Switch checked={bundle} onCheckedChange={setBundle} />
          </label>
          <label className="flex cursor-pointer items-center justify-between gap-4 rounded-lg border p-3">
            <span className="flex flex-col">
              <span className="text-sm font-medium">Include inactive plugins</span>
              <span className="text-xs text-muted-foreground">They are installed but left inactive when the blueprint is applied.</span>
            </span>
            <Switch checked={inactive} onCheckedChange={setInactive} />
          </label>
          {error && <p className="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">{error}</p>}
          <div className="flex justify-end gap-2">
            <Button type="button" variant="outline" disabled={running} onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" disabled={running}>
              {running ? <Loader2 className="animate-spin" /> : <Blocks />}
              {running ? 'Capturing…' : 'Capture blueprint'}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  );
}
