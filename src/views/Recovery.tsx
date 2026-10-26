import { useState } from 'react';
import { Check, Copy, Download, ExternalLink, KeyRound, LifeBuoy, Loader2, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useApi } from '@/hooks/useApi';
import { api } from '@/lib/api';
import { downloadRecoveryKit } from '@/lib/recoveryKit';

interface RecoveryStatus {
  exists: boolean;
  url: string | null;
  file: string | null;
  created_at: string | null;
  writable: boolean;
}

interface Generated {
  url: string;
  file: string;
  password: string;
  generated_password: boolean;
  created_at: string;
}

const CAPABILITIES = [
  'Shows the latest fatal errors from debug.log and names the plugin or theme that caused them',
  'Turns individual plugins on or off, or deactivates them all, without loading WordPress',
  'Switches to a default theme when the active one crashes',
  'Rolls back to any snapshot with an atomic table swap, even if the tracking table is damaged',
  'Creates an emergency administrator and repairs siteurl / home',
];

export function Recovery() {
  const { data, error, loading, reload } = useApi<RecoveryStatus>('recovery');
  const [password, setPassword] = useState('');
  const [busy, setBusy] = useState<'generate' | 'delete' | null>(null);
  const [created, setCreated] = useState<Generated | null>(null);
  const [message, setMessage] = useState<string | null>(null);
  const [copied, setCopied] = useState<string | null>(null);

  const generate = async () => {
    setBusy('generate');
    setMessage(null);
    try {
      const result = await api<Generated>('recovery', { method: 'POST', body: { password } });
      setCreated(result);
      setPassword('');
      reload();
    } catch (e) {
      setMessage(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(null);
    }
  };

  const remove = async () => {
    setBusy('delete');
    try {
      await api('recovery', { method: 'DELETE' });
      setCreated(null);
      reload();
    } finally {
      setBusy(null);
    }
  };

  const copy = async (text: string, key: string) => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(key);
      setTimeout(() => setCopied(null), 1500);
    } catch {
      /* clipboard unavailable (insecure origin): text stays selectable */
    }
  };

  const url = created?.url ?? data?.url ?? null;

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Emergency Recovery</h1>
        <p className="mt-1 text-sm text-muted-foreground">
          A standalone, password-protected console that keeps working when WordPress itself does not: white screen, fatal
          errors, lost admin access.
        </p>
      </div>

      {error && <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm">{error.message}</p>}

      <div className="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
        <Card>
          <CardHeader>
            <div className="flex items-center justify-between gap-3">
              <CardTitle className="flex items-center gap-2 text-base">
                <LifeBuoy className="h-4 w-4" /> Recovery script
              </CardTitle>
              {data ? (
                data.exists ? <Badge variant="success">installed</Badge> : <Badge variant="secondary">not installed</Badge>
              ) : (
                <Skeleton className="h-5 w-20" />
              )}
            </div>
            <CardDescription>
              {data?.exists
                ? `Generated ${data.created_at}. Bookmark the URL somewhere outside this site.`
                : 'Generate the script before you need it. It does nothing until someone signs in with the recovery password.'}
            </CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {url && (
              <div className="flex flex-col gap-1.5">
                <span className="text-xs font-medium text-muted-foreground">Recovery URL</span>
                <div className="flex gap-2">
                  <code className="flex h-9 min-w-0 flex-1 items-center truncate rounded-md border bg-muted/40 px-3 text-xs">{url}</code>
                  <Button variant="outline" size="icon" onClick={() => copy(url, 'url')} aria-label="Copy URL">
                    {copied === 'url' ? <Check /> : <Copy />}
                  </Button>
                  <Button variant="outline" size="icon" asChild>
                    <a href={url} target="_blank" rel="noreferrer noopener" aria-label="Open recovery console">
                      <ExternalLink />
                    </a>
                  </Button>
                </div>
              </div>
            )}

            {created && (
              <div className="flex flex-col gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3">
                <span className="flex items-center gap-2 text-sm font-medium">
                  <KeyRound className="h-4 w-4 text-amber-600" />
                  {created.generated_password ? 'Recovery password: shown only once' : 'Your custom recovery password is set'}
                </span>
                {created.generated_password && (
                  <div className="flex gap-2">
                    <code className="flex h-9 flex-1 select-all items-center rounded-md border bg-background px-3 font-mono text-sm">{created.password}</code>
                    <Button variant="outline" size="icon" onClick={() => copy(created.password, 'pw')} aria-label="Copy password">
                      {copied === 'pw' ? <Check /> : <Copy />}
                    </Button>
                  </div>
                )}
                <span className="text-xs text-muted-foreground">Only a bcrypt hash is stored in the script. If you lose the password, regenerate the script.</span>
                <Button variant="outline" size="sm" className="self-start" onClick={() => downloadRecoveryKit(created.url, created.password)}>
                  <Download />
                  Download recovery kit (.txt)
                </Button>
              </div>
            )}

            <form
              className="flex flex-col gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                generate();
              }}
            >
              <label className="text-xs font-medium text-muted-foreground" htmlFor="wpc-recovery-password">
                Custom password (optional, 12+ characters, or leave empty for a random one)
              </label>
              <div className="flex flex-wrap gap-2">
                <Input
                  id="wpc-recovery-password"
                  type="password"
                  autoComplete="new-password"
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  className="min-w-[200px] flex-1"
                />
                <Button type="submit" disabled={busy !== null || (password.length > 0 && password.length < 12) || data?.writable === false}>
                  {busy === 'generate' ? <Loader2 className="animate-spin" /> : data?.exists ? <RefreshCw /> : <ShieldCheck />}
                  {data?.exists ? 'Regenerate' : 'Generate script'}
                </Button>
                {data?.exists && (
                  <Button type="button" variant="outline" onClick={remove} disabled={busy !== null} className="text-destructive">
                    {busy === 'delete' ? <Loader2 className="animate-spin" /> : <Trash2 />}
                    Delete
                  </Button>
                )}
              </div>
              {data?.writable === false && <p className="text-xs text-destructive">The WordPress root is not writable, so the script cannot be created.</p>}
              {message && <p className="text-xs text-destructive">{message}</p>}
              {loading && !data && <Skeleton className="h-4 w-48" />}
            </form>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">What it can do without WordPress</CardTitle>
            <CardDescription>Reads wp-config.php without executing it and talks to MySQL directly.</CardDescription>
          </CardHeader>
          <CardContent>
            <ul className="flex flex-col gap-2.5 text-sm">
              {CAPABILITIES.map((c) => (
                <li key={c} className="flex gap-2">
                  <Check className="mt-0.5 h-4 w-4 shrink-0 text-emerald-500" />
                  <span className="text-muted-foreground">{c}</span>
                </li>
              ))}
            </ul>
            <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
              Protected by a bcrypt password, signed session cookies, CSRF tokens and a lockout after 5 failed attempts in 15
              minutes.
            </p>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
