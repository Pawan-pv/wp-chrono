import { useEffect, useState } from 'react';
import { CheckCircle2, CloudDownload, KeyRound, Loader2, PlugZap, RefreshCw, Save, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { ProLockCard } from '@/components/ProLockCard';
import { Switch } from '@/components/ui/switch';
import { useApi } from '@/hooks/useApi';
import { api } from '@/lib/api';
import { formatBytes, formatNumber, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CloudSettings, CloudTick, RemoteBackup } from '@/types';

type Form = Pick<CloudSettings, 'provider' | 'endpoint' | 'region' | 'bucket' | 'access_key' | 'path_style' | 'prefix'> & { secret_key: string };

const MAX_TICKS = 5000;

interface CloudPanelProps {
  onPulled: (message: string) => void;
  /** No Pro license: settings / push are locked, but pulling existing backups (a restore) stays free. */
  locked: boolean;
  onEnterLicense: () => void;
}

/** BYOC settings (R2 / S3 / B2), connection test, and remote backups with Pull. */
export function CloudPanel({ onPulled, locked, onEnterLicense }: CloudPanelProps) {
  const { data, reload } = useApi<CloudSettings>('cloud');
  const [form, setForm] = useState<Form | null>(null);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [result, setResult] = useState<{ ok: boolean; text: string } | null>(null);
  const [remote, setRemote] = useState<RemoteBackup[] | null>(null);
  const [remoteError, setRemoteError] = useState<string | null>(null);
  const [pulling, setPulling] = useState<string | null>(null);
  const [pullStatus, setPullStatus] = useState('');

  useEffect(() => {
    if (data && !form) {
      const { provider, endpoint, region, bucket, access_key, path_style, prefix } = data;
      setForm({ provider, endpoint, region, bucket, access_key, path_style, prefix, secret_key: '' });
    }
  }, [data, form]);

  const loadRemote = async () => {
    setRemoteError(null);
    try {
      setRemote((await api<{ backups: RemoteBackup[] }>('cloud/remote')).backups);
    } catch (e) {
      setRemote(null);
      setRemoteError(e instanceof Error ? e.message : String(e));
    }
  };

  useEffect(() => {
    if (data?.configured) loadRemote();
  }, [data?.configured]);

  if (!data || !form) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Cloud storage</CardTitle>
        </CardHeader>
        <CardContent className="text-sm text-muted-foreground">Loading…</CardContent>
      </Card>
    );
  }

  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm((f) => (f ? { ...f, [key]: value } : f));
  const preset = data.providers[form.provider];

  const pickProvider = (provider: Form['provider']) => {
    const p = data.providers[provider];
    setForm((f) => (f ? { ...f, provider, region: p.region, path_style: p.path_style } : f));
  };

  const save = async () => {
    setSaving(true);
    setResult(null);
    try {
      await api<CloudSettings>('cloud', { method: 'POST', body: form });
      setForm((f) => (f ? { ...f, secret_key: '' } : f));
      reload();
      setResult({ ok: true, text: 'Saved. The secret key is stored encrypted.' });
    } catch (e) {
      setResult({ ok: false, text: e instanceof Error ? e.message : String(e) });
    } finally {
      setSaving(false);
    }
  };

  const test = async () => {
    setTesting(true);
    setResult(null);
    try {
      const r = await api<{ message: string; ms: number }>('cloud/test', { method: 'POST' });
      setResult({ ok: true, text: `${r.message} (${r.ms} ms)` });
      loadRemote();
    } catch (e) {
      setResult({ ok: false, text: e instanceof Error ? e.message : String(e) });
    } finally {
      setTesting(false);
    }
  };

  const pull = async (b: RemoteBackup) => {
    setPulling(b.key);
    setPullStatus('Reading manifest…');
    try {
      let job = '';
      for (let i = 0; i < MAX_TICKS; i++) {
        const t = await api<CloudTick>('cloud/pull', { method: 'POST', body: { key: b.key, job } });
        setPullStatus(t.message);
        job = t.job ?? '';
        if (t.done) {
          onPulled(t.message);
          break;
        }
      }
    } catch (e) {
      setPullStatus(e instanceof Error ? e.message : String(e));
    } finally {
      setPulling(null);
    }
  };

  return (
    <Card data-e2e="cloud-panel">
      <CardHeader className="flex-row flex-wrap items-start justify-between gap-3">
        <div className="flex flex-col gap-1.5">
          <CardTitle className="flex items-center gap-2">
            Cloud storage
            {data.configured ? <Badge variant="success">Connected</Badge> : <Badge variant="secondary">Not configured</Badge>}
          </CardTitle>
          <CardDescription>Bring your own bucket: snapshots (.sql.gz + plugin archives) are pushed straight from this server.</CardDescription>
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {locked && <ProLockCard feature="Cloud offload (push to R2 / S3 / B2)" onEnterLicense={onEnterLicense} />}
        {!locked && (
        <>
        <div className="grid grid-cols-2 gap-1 rounded-md border p-0.5 text-xs sm:grid-cols-4" role="radiogroup" aria-label="Provider">
          {(Object.keys(data.providers) as Form['provider'][]).map((p) => (
            <button
              key={p}
              type="button"
              role="radio"
              aria-checked={form.provider === p}
              onClick={() => pickProvider(p)}
              className={cn(
                'rounded px-2 py-1.5 transition-colors',
                form.provider === p ? 'bg-secondary font-medium text-foreground' : 'text-muted-foreground hover:text-foreground',
              )}
            >
              {data.providers[p].label}
            </button>
          ))}
        </div>

        <form
          className="grid grid-cols-1 gap-3 sm:grid-cols-2"
          onSubmit={(e) => {
            e.preventDefault();
            save();
          }}
        >
          <label className="flex flex-col gap-1.5 text-sm sm:col-span-2">
            Endpoint URL
            <Input value={form.endpoint} onChange={(e) => set('endpoint', e.target.value)} placeholder={preset?.endpoint || 'https://s3.example.com'} spellCheck={false} />
          </label>
          <label className="flex flex-col gap-1.5 text-sm">
            Bucket
            <Input value={form.bucket} onChange={(e) => set('bucket', e.target.value)} placeholder="my-site-backups" spellCheck={false} />
          </label>
          <label className="flex flex-col gap-1.5 text-sm">
            Region
            <Input value={form.region} onChange={(e) => set('region', e.target.value)} placeholder={preset?.region} spellCheck={false} />
          </label>
          <label className="flex flex-col gap-1.5 text-sm">
            Access key ID
            <Input value={form.access_key} onChange={(e) => set('access_key', e.target.value)} autoComplete="off" spellCheck={false} />
          </label>
          <label className="flex flex-col gap-1.5 text-sm">
            Secret access key
            <Input
              type="password"
              value={form.secret_key}
              onChange={(e) => set('secret_key', e.target.value)}
              autoComplete="new-password"
              placeholder={data.has_secret ? '•••••••• stored encrypted — leave blank to keep' : ''}
            />
          </label>
          <label className="flex flex-col gap-1.5 text-sm">
            Folder in bucket
            <Input value={form.prefix} onChange={(e) => set('prefix', e.target.value)} placeholder="wp-chrono/" spellCheck={false} />
          </label>
          <label className="flex cursor-pointer items-center justify-between gap-3 self-end rounded-lg border p-2.5 text-sm">
            Path-style URLs
            <Switch checked={form.path_style} onCheckedChange={(v) => set('path_style', v)} />
          </label>

          <p className="flex items-center gap-1.5 text-xs text-muted-foreground sm:col-span-2">
            <KeyRound className="h-3.5 w-3.5" /> Secret encrypted at rest with {data.encryption}; it is never sent back to the browser.
          </p>

          <div className="flex flex-wrap items-center gap-2 sm:col-span-2">
            <Button type="submit" size="sm" disabled={saving}>
              {saving ? <Loader2 className="animate-spin" /> : <Save />}
              Save
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={test} disabled={testing || !data.configured}>
              {testing ? <Loader2 className="animate-spin" /> : <PlugZap />}
              Test connection
            </Button>
            {result && (
              <span className={cn('flex items-center gap-1.5 text-xs', result.ok ? 'text-emerald-600' : 'text-destructive')} data-e2e="cloud-result">
                {result.ok ? <CheckCircle2 className="h-3.5 w-3.5" /> : <XCircle className="h-3.5 w-3.5" />}
                {result.text}
              </span>
            )}
          </div>
        </form>
        </>
        )}

        {data.configured && (
          <div className="flex flex-col gap-2 border-t pt-4">
            <div className="flex items-center justify-between">
              <p className="text-sm font-medium">Backups in the bucket</p>
              <Button variant="ghost" size="sm" onClick={loadRemote}>
                <RefreshCw /> Refresh
              </Button>
            </div>
            {remoteError && <p className="text-xs text-destructive">{remoteError}</p>}
            {remote && remote.length === 0 && (
              <p className="text-xs text-muted-foreground">No backups yet. Push a snapshot from the Snapshots screen.</p>
            )}
            <ul className="flex flex-col divide-y rounded-lg border" data-e2e="remote-backups">
              {remote?.map((b) => (
                <li key={b.key} className="flex flex-wrap items-center gap-x-4 gap-y-2 p-3">
                  <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="truncate text-sm font-medium">{b.label || `Snapshot #${b.snapshot_id}`}</span>
                    <span className="text-xs text-muted-foreground tabular-nums">
                      pushed {timeAgo(b.pushed_at)} · {b.table_count} tables · {formatNumber(b.row_count)} rows · {formatBytes(b.bytes)}
                      {b.files > 0 && ` · ${b.files} plugin/theme archive(s)`}
                    </span>
                    {pulling === b.key && <span className="text-xs text-muted-foreground">{pullStatus}</span>}
                  </div>
                  <Button variant="outline" size="sm" disabled={pulling !== null} onClick={() => pull(b)}>
                    {pulling === b.key ? <Loader2 className="animate-spin" /> : <CloudDownload />}
                    Pull
                  </Button>
                </li>
              ))}
            </ul>
            {!pulling && pullStatus && <p className="text-xs text-muted-foreground">{pullStatus}</p>}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
