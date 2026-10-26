import { useEffect, useState, type ReactNode } from 'react';
import { ArrowRight, Loader2, Minus, Plus, RotateCcw, Table2 } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api';
import { formatBytes, formatNumber, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { OptionChange, Snapshot, SnapshotDiff } from '@/types';

interface DiffDrawerProps {
  snapshot: Snapshot | null;
  onOpenChange: (open: boolean) => void;
  onRestore: (snapshot: Snapshot) => void;
}

type Tab = 'tables' | 'options';

export function DiffDrawer({ snapshot, onOpenChange, onRestore }: DiffDrawerProps) {
  const [diff, setDiff] = useState<SnapshotDiff | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>('tables');

  useEffect(() => {
    if (!snapshot) return;
    const ctrl = new AbortController();
    setDiff(null);
    setError(null);
    setTab('tables');
    api<SnapshotDiff>(`snapshots/${snapshot.id}/diff`, { signal: ctrl.signal })
      .then(setDiff)
      .catch((e: Error) => e.name !== 'AbortError' && setError(e.message));
    return () => ctrl.abort();
  }, [snapshot]);

  const t = diff?.tables;
  const o = diff?.options;
  const tableChanges = t ? t.added_tables.length + t.removed_tables.length + t.modified_tables.length : 0;
  const optionChanges = o ? o.added.length + o.deleted.length + o.modified.length : 0;

  return (
    <Sheet open={snapshot !== null} onOpenChange={onOpenChange}>
      <SheetContent aria-describedby={undefined}>
        <div className="flex flex-col gap-1 border-b p-6 pr-12">
          <SheetTitle className="text-lg font-semibold tracking-tight">
            Snapshot #{snapshot?.id} vs. live site
          </SheetTitle>
          <SheetDescription className="text-sm text-muted-foreground">
            {snapshot?.label || 'Untitled snapshot'} · taken {snapshot ? timeAgo(snapshot.created_at) : ''}. Showing what changed since.
          </SheetDescription>
          <div className="mt-4 grid grid-cols-2 rounded-lg border p-0.5 text-sm">
            {(
              [
                ['tables', 'Tables changed', tableChanges],
                ['options', 'wp_options changes', optionChanges],
              ] as const
            ).map(([id, label, count]) => (
              <button
                key={id}
                type="button"
                onClick={() => setTab(id)}
                className={cn(
                  'flex items-center justify-center gap-2 rounded-md px-3 py-1.5 transition-colors',
                  tab === id ? 'bg-secondary font-medium' : 'text-muted-foreground hover:text-foreground',
                )}
              >
                {label}
                {diff && <Badge variant={count ? 'warning' : 'secondary'}>{count}</Badge>}
              </button>
            ))}
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-6">
          {error && <p className="rounded-lg border border-destructive/40 bg-destructive/5 p-3 text-sm">{error}</p>}
          {!diff && !error && (
            <div className="flex flex-col gap-3">
              <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="h-4 w-4 animate-spin" /> Comparing tables and options…
              </p>
              {Array.from({ length: 6 }, (_, i) => (
                <Skeleton key={i} className="h-12 w-full" />
              ))}
            </div>
          )}
          {diff && tab === 'tables' && <TablesTab diff={diff} />}
          {diff && tab === 'options' && <OptionsTab diff={diff} />}
        </div>

        <div className="flex items-center justify-between gap-3 border-t p-4">
          <p className="text-xs text-muted-foreground">
            {diff ? `Compared in ${diff.duration_ms} ms` : ' '}
          </p>
          <Button variant="destructive" disabled={!snapshot} onClick={() => snapshot && onRestore(snapshot)}>
            <RotateCcw />
            Restore this snapshot
          </Button>
        </div>
      </SheetContent>
    </Sheet>
  );
}

function TablesTab({ diff }: { diff: SnapshotDiff }) {
  const t = diff.tables;
  const nothing = !t.added_tables.length && !t.removed_tables.length && !t.modified_tables.length;

  return (
    <div className="flex flex-col gap-6">
      {nothing && <Empty text={`All ${t.unchanged_tables} tables match the snapshot.`} />}

      {t.modified_tables.length > 0 && (
        <Section title="Modified">
          {t.modified_tables.map((m) => (
            <div key={m.table} className="flex flex-col gap-2 rounded-lg border p-3">
              <div className="flex flex-wrap items-center gap-2">
                <Table2 className="h-4 w-4 text-muted-foreground" />
                <span className="font-mono text-sm">{m.table}</span>
                <span className="ml-auto flex flex-wrap gap-1.5">
                  {m.rows_delta !== 0 && (
                    <Badge variant={m.rows_delta > 0 ? 'success' : 'destructive'} className="tabular-nums">
                      {m.rows_delta > 0 ? '+' : ''}
                      {formatNumber(m.rows_delta)} rows
                    </Badge>
                  )}
                  {m.content_changed && <Badge variant="warning">rows edited</Badge>}
                  {m.schema_changed && <Badge variant="warning">schema changed</Badge>}
                </span>
              </div>
              <p className="text-xs text-muted-foreground tabular-nums">
                {formatNumber(m.rows_before)} → {formatNumber(m.rows_after)} rows · {formatBytes(m.bytes_before)} →{' '}
                {formatBytes(m.bytes_after)}
              </p>
              {m.schema_diff && (
                <pre className="overflow-x-auto rounded-md bg-muted/50 p-2 text-[11px] leading-relaxed">
                  {m.schema_diff.removed.map((l) => (
                    <div key={`-${l}`} className="text-red-600 dark:text-red-400">
                      - {l}
                    </div>
                  ))}
                  {m.schema_diff.added.map((l) => (
                    <div key={`+${l}`} className="text-emerald-600 dark:text-emerald-400">
                      + {l}
                    </div>
                  ))}
                </pre>
              )}
            </div>
          ))}
        </Section>
      )}

      {t.added_tables.length > 0 && (
        <Section title="Created since the snapshot">
          {t.added_tables.map((a) => (
            <Row key={a.table} icon={<Plus className="h-4 w-4 text-emerald-500" />} name={a.table} meta={`${formatNumber(a.rows)} rows · ${formatBytes(a.bytes)}`} />
          ))}
        </Section>
      )}

      {t.removed_tables.length > 0 && (
        <Section title="Dropped since the snapshot">
          {t.removed_tables.map((r) => (
            <Row key={r.table} icon={<Minus className="h-4 w-4 text-red-500" />} name={r.table} meta={`${formatNumber(r.rows)} rows · ${formatBytes(r.bytes)}`} />
          ))}
        </Section>
      )}

      {!nothing && <p className="text-xs text-muted-foreground">{t.unchanged_tables} tables unchanged.</p>}
    </div>
  );
}

function OptionsTab({ diff }: { diff: SnapshotDiff }) {
  const o = diff.options;
  if (!o.available) return <Empty text="This snapshot has no copy of wp_options." />;
  if (!o.added.length && !o.deleted.length && !o.modified.length) return <Empty text="No option changes (transients and cron excluded)." />;

  return (
    <div className="flex flex-col gap-6">
      {o.truncated && <p className="text-xs text-amber-600">Showing the first 500 changes.</p>}
      {o.modified.length > 0 && (
        <Section title={`Modified (${o.modified.length})`}>
          {o.modified.map((c) => (
            <OptionDiff key={c.name} change={c} />
          ))}
        </Section>
      )}
      {o.added.length > 0 && (
        <Section title={`Added since the snapshot (${o.added.length})`}>
          {o.added.map((c) => (
            <OptionDiff key={c.name} change={c} />
          ))}
        </Section>
      )}
      {o.deleted.length > 0 && (
        <Section title={`Deleted since the snapshot (${o.deleted.length})`}>
          {o.deleted.map((c) => (
            <OptionDiff key={c.name} change={c} />
          ))}
        </Section>
      )}
    </div>
  );
}

function OptionDiff({ change }: { change: OptionChange }) {
  const [open, setOpen] = useState(Boolean(change.label));
  return (
    <div className="rounded-lg border">
      <button type="button" onClick={() => setOpen((v) => !v)} className="flex w-full flex-wrap items-center gap-2 p-3 text-left">
        {change.label && <span className="text-sm font-medium">{change.label}</span>}
        <code className="text-xs text-muted-foreground">{change.name}</code>
        {change.summary && <span className="ml-auto truncate text-xs text-muted-foreground">{change.summary}</span>}
      </button>
      {open && (
        <div className="grid grid-cols-1 gap-px border-t bg-border sm:grid-cols-[1fr_auto_1fr]">
          <ValueBox title="Snapshot" value={change.old_value} tone="old" />
          <div className="hidden items-center bg-background px-1 sm:flex">
            <ArrowRight className="h-3.5 w-3.5 text-muted-foreground" />
          </div>
          <ValueBox title="Live now" value={change.new_value} tone="new" />
        </div>
      )}
    </div>
  );
}

function ValueBox({ title, value, tone }: { title: string; value: string | null; tone: 'old' | 'new' }) {
  return (
    <div className="flex min-w-0 flex-col gap-1 bg-background p-3">
      <span className="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">{title}</span>
      {value === null ? (
        <span className="text-xs italic text-muted-foreground">(not set)</span>
      ) : (
        <pre
          className={cn(
            'max-h-48 overflow-auto whitespace-pre-wrap break-all rounded-md p-2 text-[11px] leading-relaxed',
            tone === 'old' ? 'bg-red-500/5 text-red-700 dark:text-red-300' : 'bg-emerald-500/5 text-emerald-700 dark:text-emerald-300',
          )}
        >
          {value === '' ? '(empty)' : value}
        </pre>
      )}
    </div>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="flex flex-col gap-2">
      <h3 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{title}</h3>
      {children}
    </section>
  );
}

function Row({ icon, name, meta }: { icon: ReactNode; name: string; meta: string }) {
  return (
    <div className="flex items-center gap-2 rounded-lg border p-3">
      {icon}
      <span className="font-mono text-sm">{name}</span>
      <span className="ml-auto text-xs text-muted-foreground tabular-nums">{meta}</span>
    </div>
  );
}

function Empty({ text }: { text: string }) {
  return <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">{text}</p>;
}
