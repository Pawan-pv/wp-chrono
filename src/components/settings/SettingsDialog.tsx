import { useEffect, useState } from 'react';
import { BadgeCheck, Check, KeyRound, Loader2, Lock, Save } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { publishLicense } from '@/hooks/useLicense';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';
import type { ChronoSettings, LicenseStatus } from '@/types';

export type SettingsTab = 'settings' | 'license';

interface SettingsDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  tab: SettingsTab;
  onTabChange: (tab: SettingsTab) => void;
}

const FREE = [
  'Full & partial resets with dry runs',
  'Snapshots, auto-snapshots before updates, diff',
  'Every restore — incl. Full Undo of plugin updates',
  'Safe Updates: broken updates undone automatically',
  'Import your .sql.gz backups',
  'Emergency Recovery Script',
  'Pull backups from your cloud bucket',
];
const PRO = ['Blueprints: Reset to Dev Baseline, zip bundles', 'Cloud offload: push to R2 / S3 / B2'];

/** Settings (thresholds, retention, rollback size) and the license key. */
export function SettingsDialog({ open, onOpenChange, tab, onTabChange }: SettingsDialogProps) {
  const [settings, setSettings] = useState<ChronoSettings | null>(null);
  const [license, setLicense] = useState<LicenseStatus | null>(null);
  const [key, setKey] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  useEffect(() => {
    if (!open) return;
    setMessage(null);
    setKey('');
    api<ChronoSettings>('settings').then(setSettings).catch(() => undefined);
    api<LicenseStatus>('license').then(setLicense).catch(() => undefined);
  }, [open]);

  const saveSettings = async () => {
    if (!settings) return;
    setBusy('settings');
    try {
      setSettings(await api<ChronoSettings>('settings', { method: 'POST', body: settings }));
      setMessage({ ok: true, text: 'Settings saved.' });
    } catch (e) {
      setMessage({ ok: false, text: e instanceof Error ? e.message : String(e) });
    } finally {
      setBusy(null);
    }
  };

  const activate = async () => {
    setBusy('activate');
    setMessage(null);
    try {
      const status = await api<LicenseStatus>('license', { method: 'POST', body: { key: key.trim() } });
      setLicense(status);
      publishLicense(status);
      setKey('');
      setMessage({ ok: true, text: `License activated — ${status.plan} plan. Blueprints and Cloud are unlocked.` });
    } catch (e) {
      setMessage({ ok: false, text: e instanceof Error ? e.message : String(e) });
    } finally {
      setBusy(null);
    }
  };

  const deactivate = async () => {
    setBusy('deactivate');
    try {
      const status = await api<LicenseStatus>('license', { method: 'DELETE' });
      setLicense(status);
      publishLicense(status);
      setMessage({ ok: true, text: 'License removed from this site. Restoring, importing and the recovery script stay free.' });
    } finally {
      setBusy(null);
    }
  };

  const num = (k: keyof ChronoSettings, v: string) => setSettings((s) => (s ? { ...s, [k]: Math.max(1, Number(v) || 1) } : s));

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg" data-e2e="settings-dialog">
        <div className="flex flex-col gap-1.5">
          <DialogTitle>Settings & License</DialogTitle>
          <DialogDescription>No nags, no tracking. Restoring your site is always free.</DialogDescription>
        </div>

        <div className="grid grid-cols-2 rounded-md border p-0.5 text-sm" role="tablist">
          {(['settings', 'license'] as const).map((t) => (
            <button
              key={t}
              type="button"
              role="tab"
              aria-selected={tab === t}
              onClick={() => {
                onTabChange(t);
                setMessage(null);
              }}
              className={cn('rounded px-3 py-1.5 capitalize transition-colors', tab === t ? 'bg-secondary font-medium' : 'text-muted-foreground hover:text-foreground')}
            >
              {t}
            </button>
          ))}
        </div>

        {tab === 'settings' && (
          <form
            className="flex flex-col gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              saveSettings();
            }}
          >
            <label className="flex cursor-pointer items-center justify-between gap-4 rounded-lg border p-3">
              <span className="flex flex-col">
                <span className="text-sm font-medium">Auto-snapshot before updates</span>
                <span className="text-xs text-muted-foreground">Includes plugin / theme file archives for Full Undo.</span>
              </span>
              <Switch checked={settings?.auto_snapshot ?? false} disabled={!settings} onCheckedChange={(v) => setSettings((s) => (s ? { ...s, auto_snapshot: v } : s))} />
            </label>
            <label className="flex cursor-pointer items-center justify-between gap-4 rounded-lg border p-3">
              <span className="flex flex-col">
                <span className="text-sm font-medium">Safe Updates</span>
                <span className="text-xs text-muted-foreground">
                  After each update, check that the site still loads. If it broke, undo the update (files + database) automatically and email you.
                </span>
              </span>
              <Switch
                checked={(settings?.auto_snapshot && settings.safe_updates) ?? false}
                disabled={!settings?.auto_snapshot}
                onCheckedChange={(v) => setSettings((s) => (s ? { ...s, safe_updates: v } : s))}
                aria-label="Safe Updates"
                data-e2e="safe-updates-switch"
              />
            </label>
            <label className="flex items-center justify-between gap-4 text-sm">
              Keep the newest auto-snapshots
              <Input type="number" min={1} max={50} className="w-24" value={settings?.auto_retention ?? ''} onChange={(e) => num('auto_retention', e.target.value)} />
            </label>
            <label className="flex items-center justify-between gap-4 text-sm">
              Storage warning threshold (MB)
              <Input type="number" min={1} className="w-24" value={settings?.storage_warn_mb ?? ''} onChange={(e) => num('storage_warn_mb', e.target.value)} />
            </label>
            <label className="flex items-center justify-between gap-4 text-sm">
              <span className="flex flex-col">
                Rollback archive size limit (MB)
                <span className="text-xs text-muted-foreground">Plugin / theme folders larger than this are not archived before updates.</span>
              </span>
              <Input type="number" min={1} max={10240} className="w-24" value={settings?.rollback_max_mb ?? ''} onChange={(e) => num('rollback_max_mb', e.target.value)} aria-label="Rollback archive size limit (MB)" />
            </label>
            <div className="flex justify-end">
              <Button type="submit" size="sm" disabled={!settings || busy === 'settings'}>
                {busy === 'settings' ? <Loader2 className="animate-spin" /> : <Save />}
                Save settings
              </Button>
            </div>
          </form>
        )}

        {tab === 'license' && (
          <div className="flex flex-col gap-4" data-e2e="license-tab">
            <div className="flex items-center justify-between rounded-lg border p-3">
              <span className="flex flex-col gap-0.5">
                <span className="text-sm font-medium">
                  Plan:{' '}
                  <span className="capitalize" data-e2e="license-plan">
                    {license?.plan ?? '…'}
                  </span>
                </span>
                <span className="text-xs text-muted-foreground">
                  {license?.active
                    ? `${license.masked} · ${license.license?.source} · ${license.license?.expires ? `until ${license.license.expires}` : 'lifetime'}${license.from === 'constant' ? ' · set in wp-config.php' : ''}`
                    : license?.error ?? 'No license key — every restore feature is still free.'}
                </span>
              </span>
              {license?.active ? <Badge variant="success"><BadgeCheck className="h-3 w-3" /> Pro</Badge> : <Badge variant="secondary">Free</Badge>}
            </div>

            {license?.from !== 'constant' && (
              <form
                className="flex flex-col gap-2"
                onSubmit={(e) => {
                  e.preventDefault();
                  if (key.trim()) activate();
                }}
              >
                <label className="flex flex-col gap-1.5 text-sm">
                  License key
                  <Input value={key} onChange={(e) => setKey(e.target.value)} placeholder="WPC1.…" spellCheck={false} autoComplete="off" className="font-mono text-xs" />
                </label>
                <div className="flex justify-end gap-2">
                  {license?.active && (
                    <Button type="button" variant="outline" size="sm" onClick={deactivate} disabled={busy !== null}>
                      Deactivate
                    </Button>
                  )}
                  <Button type="submit" size="sm" disabled={!key.trim() || busy !== null}>
                    {busy === 'activate' ? <Loader2 className="animate-spin" /> : <KeyRound />}
                    Activate
                  </Button>
                </div>
                <p className="text-xs text-muted-foreground">
                  Keys are verified offline (Ed25519). Once a day the site checks in with the WP Chrono license server to count
                  sites; if it can&apos;t be reached, your key keeps working. Agencies can also set{' '}
                  <code className="rounded bg-muted px-1">WP_CHRONO_LICENSE_KEY</code> in wp-config.php.
                </p>
              </form>
            )}

            <div className="grid grid-cols-1 gap-3 rounded-lg border p-3 text-xs sm:grid-cols-2">
              <div>
                <p className="mb-1.5 font-medium">Free forever</p>
                <ul className="flex flex-col gap-1 text-muted-foreground">
                  {FREE.map((f) => (
                    <li key={f} className="flex gap-1.5">
                      <Check className="h-3.5 w-3.5 shrink-0 text-emerald-500" />
                      {f}
                    </li>
                  ))}
                </ul>
              </div>
              <div>
                <p className="mb-1.5 font-medium">Pro</p>
                <ul className="flex flex-col gap-1 text-muted-foreground">
                  {PRO.map((f) => (
                    <li key={f} className="flex gap-1.5">
                      {license?.active ? <Check className="h-3.5 w-3.5 shrink-0 text-emerald-500" /> : <Lock className="h-3.5 w-3.5 shrink-0" />}
                      {f}
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          </div>
        )}

        {message && (
          <p className={cn('text-sm', message.ok ? 'text-emerald-600' : 'text-destructive')} data-e2e="settings-message">
            {message.text}
          </p>
        )}
      </DialogContent>
    </Dialog>
  );
}
