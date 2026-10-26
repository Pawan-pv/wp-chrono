import { useState } from 'react';
import { Camera, Check, CheckCircle2, Circle, Cloud, Copy, Download, LifeBuoy, Loader2, Sparkles } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import type { Route, RouteParams } from '@/hooks/useHashRoute';
import { api } from '@/lib/api';
import { downloadRecoveryKit } from '@/lib/recoveryKit';
import { cn } from '@/lib/utils';
import type { OnboardingStatus } from '@/types';

interface ChecklistStepsProps {
  status: OnboardingStatus;
  onChange: (next: OnboardingStatus) => void;
  onNavigate: (route: Route, params?: RouteParams) => void;
}

/**
 * The three first-run safety steps. Step state comes from the server, so it
 * reflects reality (e.g. a recovery script made on the Recovery screen).
 */
export function ChecklistSteps({ status, onChange, onNavigate }: ChecklistStepsProps) {
  const [busy, setBusy] = useState<string | null>(null);
  const [kit, setKit] = useState<{ url: string; password: string } | null>(null);
  const [copied, setCopied] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const refresh = async (flags: Partial<Pick<OnboardingStatus, 'kit_downloaded'>> = {}) =>
    onChange(await api<OnboardingStatus>('onboarding', { method: 'POST', body: flags }));

  const generate = async () => {
    setBusy('recovery');
    setError(null);
    try {
      const r = await api<{ url: string; password: string }>('recovery', { method: 'POST', body: {} });
      setKit({ url: r.url, password: r.password });
      await refresh();
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(null);
    }
  };

  const download = async () => {
    if (!kit) return;
    downloadRecoveryKit(kit.url, kit.password);
    await refresh({ kit_downloaded: true });
  };

  const copy = async (text: string, which: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(which);
      setTimeout(() => setCopied(null), 1500);
    } catch {
      /* insecure origin: the text stays selectable */
    }
  };

  const toggleAuto = async (on: boolean) => {
    setBusy('auto');
    try {
      await api('settings', { method: 'POST', body: { auto_snapshot: on } });
      await refresh();
    } finally {
      setBusy(null);
    }
  };

  return (
    <ol className="flex flex-col gap-3" data-e2e="checklist">
      <Step done={status.steps.recovery} icon={LifeBuoy} title="1. Generate & bookmark your Emergency Recovery Script" id="recovery">
        <p className="text-xs text-muted-foreground">
          A secret page that can repair this site even when WordPress itself is down. Save it <em>before</em> you ever need it.
        </p>
        {kit ? (
          <div className="flex flex-col gap-2 rounded-md border bg-muted/40 p-2.5 text-xs">
            <KitRow label="URL" value={kit.url} copied={copied === 'url'} onCopy={() => copy(kit.url, 'url')} />
            <KitRow label="Password" value={kit.password} copied={copied === 'pw'} onCopy={() => copy(kit.password, 'pw')} />
            <p className="text-muted-foreground">The password is shown only once.</p>
            <Button size="sm" onClick={download} data-e2e="download-kit">
              <Download />
              Download recovery kit (.txt)
            </Button>
          </div>
        ) : (
          !status.steps.recovery && (
            <Button size="sm" variant="outline" onClick={generate} disabled={busy !== null} data-e2e="generate-recovery">
              {busy === 'recovery' ? <Loader2 className="animate-spin" /> : <LifeBuoy />}
              {status.recovery_exists ? 'Regenerate & get a recovery kit' : 'Generate recovery script'}
            </Button>
          )
        )}
      </Step>

      <Step done={status.steps.auto} icon={Sparkles} title="2. Enable pre-update auto-snapshots" id="auto">
        <label className="flex cursor-pointer items-center justify-between gap-3 text-xs text-muted-foreground">
          One snapshot + plugin file archive before every update batch, so any update can be undone (files + database).
          <Switch checked={status.steps.auto} disabled={busy !== null} onCheckedChange={toggleAuto} aria-label="Auto-snapshots" />
        </label>
      </Step>

      <Step done={status.steps.safety_copy} icon={Camera} title="3. Take a baseline snapshot or connect cloud storage" id="safety">
        {!status.steps.safety_copy && (
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="outline" onClick={() => onNavigate('snapshots', { run: 'create' })}>
              <Camera />
              Create baseline snapshot
            </Button>
            <Button size="sm" variant="ghost" onClick={() => onNavigate('blueprints')}>
              <Cloud />
              Configure cloud (R2 / S3 / B2)
            </Button>
          </div>
        )}
      </Step>
      {error && <p className="text-xs text-destructive">{error}</p>}
    </ol>
  );
}

function Step({ done, icon: Icon, title, id, children }: { done: boolean; icon: typeof Circle; title: string; id: string; children: React.ReactNode }) {
  return (
    <li className={cn('flex gap-3 rounded-lg border p-3', done && 'bg-emerald-500/5')} data-e2e-step={id} data-done={done ? '1' : '0'}>
      {done ? <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" /> : <Icon className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />}
      <div className="flex min-w-0 flex-1 flex-col gap-2">
        <p className={cn('text-sm font-medium', done && 'text-muted-foreground line-through decoration-emerald-500/50')}>{title}</p>
        {!done && children}
      </div>
    </li>
  );
}

function KitRow({ label, value, copied, onCopy }: { label: string; value: string; copied: boolean; onCopy: () => void }) {
  return (
    <div className="flex items-center gap-2">
      <span className="w-16 shrink-0 text-muted-foreground">{label}</span>
      <code className="min-w-0 flex-1 truncate rounded bg-background px-1.5 py-1 font-mono" data-e2e={`kit-${label.toLowerCase()}`}>
        {value}
      </code>
      <button type="button" onClick={onCopy} className="rounded p-1 text-muted-foreground hover:text-foreground" aria-label={`Copy ${label}`}>
        {copied ? <Check className="h-3.5 w-3.5 text-emerald-500" /> : <Copy className="h-3.5 w-3.5" />}
      </button>
    </div>
  );
}
