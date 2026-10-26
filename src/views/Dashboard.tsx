import { useEffect, useState, type ReactNode } from 'react';
import {
  AlertTriangle,
  Camera,
  Clock,
  Database,
  HardDrive,
  History,
  RefreshCw,
  RotateCcw,
  ShieldCheck,
  ShieldAlert,
  type LucideIcon,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { SafetyChecklist } from '@/components/onboarding/SafetyChecklist';
import { StorageWarning } from '@/components/snapshots/StorageWarning';
import { useApi } from '@/hooks/useApi';
import type { Route, RouteParams } from '@/hooks/useHashRoute';
import { config } from '@/lib/config';
import { formatBytes, formatNumber, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { OnboardingStatus, SiteStatus } from '@/types';

export function Dashboard({ onNavigate }: { onNavigate: (route: Route, params?: RouteParams) => void }) {
  const { data, error, loading, reload } = useApi<SiteStatus>('status');
  const onboarding = useApi<OnboardingStatus>('onboarding');
  const [checklist, setChecklist] = useState<OnboardingStatus>();
  useEffect(() => setChecklist(onboarding.data), [onboarding.data]);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Overview</h1>
          <p className="mt-1 text-sm text-muted-foreground">
            {config.siteName} · signed in as {config.user.name}
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={reload} disabled={loading}>
            <RefreshCw className={cn(loading && 'animate-spin')} />
            Refresh
          </Button>
          <Button size="sm" onClick={() => onNavigate('snapshots')}>
            <Camera />
            New snapshot
          </Button>
        </div>
      </div>

      <SafetyChecklist status={checklist} onChange={setChecklist} onNavigate={onNavigate} />
      <StorageWarning usage={data?.storage_usage} onPruned={reload} />

      {error && (
        <Card className="border-destructive/40 bg-destructive/5">
          <CardContent className="flex items-center gap-3 p-4 text-sm">
            <AlertTriangle className="h-4 w-4 text-destructive" />
            <span>{error.message}</span>
          </CardContent>
        </Card>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <DatabaseCard data={data} />
        <Metric
          icon={History}
          title="Snapshots"
          value={data ? formatNumber(data.snapshots.count) : undefined}
          hint={data ? `${formatBytes(data.database.snapshot_bytes)} across ${data.database.snapshot_table_count} tables` : undefined}
        />
        <DiskCard data={data} />
        <LastAutoCard data={data} />
        <SafetyCard data={data} />
        <EnvironmentCard data={data} />
        <QuickActions onNavigate={onNavigate} />
      </div>
    </div>
  );
}

function Metric({ icon: Icon, title, value, hint, children, className }: {
  icon: LucideIcon;
  title: string;
  value?: ReactNode;
  hint?: ReactNode;
  children?: ReactNode;
  className?: string;
}) {
  return (
    <Card className={cn('flex flex-col', className)}>
      <CardHeader className="flex-row items-center justify-between pb-3">
        <CardTitle className="text-muted-foreground">{title}</CardTitle>
        <Icon className="h-4 w-4 text-muted-foreground" />
      </CardHeader>
      <CardContent className="flex flex-1 flex-col gap-1">
        {value === undefined ? <Skeleton className="h-8 w-24" /> : <div className="text-2xl font-semibold tracking-tight tabular-nums">{value}</div>}
        {hint === undefined ? <Skeleton className="mt-1 h-4 w-40" /> : <p className="text-xs text-muted-foreground">{hint}</p>}
        {children}
      </CardContent>
    </Card>
  );
}

function DatabaseCard({ data }: { data?: SiteStatus }) {
  const db = data?.database;
  const max = db?.largest_tables[0]?.bytes ?? 1;

  return (
    <Metric
      icon={Database}
      title="Database size"
      className="sm:col-span-2 lg:row-span-2"
      value={db ? formatBytes(db.size_bytes) : undefined}
      hint={db ? `${db.table_count} tables · ~${formatNumber(db.row_estimate)} rows` : undefined}
    >
      <div className="mt-5 flex flex-col gap-3">
        <p className="text-xs font-medium text-muted-foreground">Largest tables</p>
        {db
          ? db.largest_tables.map((t) => (
              <div key={t.name} className="flex flex-col gap-1.5">
                <div className="flex items-center justify-between gap-2 text-xs">
                  <span className="truncate font-mono">{t.name}</span>
                  <span className="shrink-0 tabular-nums text-muted-foreground">{formatBytes(t.bytes)}</span>
                </div>
                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                  <div className="h-full rounded-full bg-primary/80" style={{ width: `${Math.max(2, (t.bytes / max) * 100)}%` }} />
                </div>
              </div>
            ))
          : Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-6 w-full" />)}
      </div>
    </Metric>
  );
}

function DiskCard({ data }: { data?: SiteStatus }) {
  const disk = data?.disk;
  const used = disk?.free_bytes != null && disk.total_bytes ? 1 - disk.free_bytes / disk.total_bytes : null;

  return (
    <Metric
      icon={HardDrive}
      title="Disk available"
      value={disk ? (disk.free_bytes == null ? 'Unknown' : formatBytes(disk.free_bytes)) : undefined}
      hint={disk ? (disk.total_bytes ? `of ${formatBytes(disk.total_bytes)} in wp-content` : 'Host disables disk metrics') : undefined}
    >
      {used !== null && (
        <div className="mt-3 h-1.5 overflow-hidden rounded-full bg-muted">
          <div
            className={cn('h-full rounded-full', used > 0.9 ? 'bg-destructive' : used > 0.75 ? 'bg-amber-500' : 'bg-emerald-500')}
            style={{ width: `${used * 100}%` }}
          />
        </div>
      )}
    </Metric>
  );
}

function LastAutoCard({ data }: { data?: SiteStatus }) {
  const last = data?.snapshots.last_auto;
  return (
    <Metric
      icon={Clock}
      title="Last auto-snapshot"
      value={data ? (last ? timeAgo(last.created_at) : 'Never') : undefined}
      hint={data ? (last ? last.label || last.uid : 'Taken automatically before plugin & theme updates') : undefined}
    >
      {last && (
        <Badge variant={last.status === 'ready' ? 'success' : 'warning'} className="mt-2 w-fit">
          {last.status}
        </Badge>
      )}
    </Metric>
  );
}

function SafetyCard({ data }: { data?: SiteStatus }) {
  const sealed = data?.storage.protected;
  return (
    <Metric
      icon={sealed === false ? ShieldAlert : ShieldCheck}
      title="Safety net"
      value={data ? (sealed ? 'Sealed' : 'Unsealed') : undefined}
      hint={
        data
          ? sealed
            ? 'Export folder locked with .htaccess, web.config and index.php'
            : 'Re-activate the plugin to create the protected export folder'
          : undefined
      }
    >
      {data && (
        <Badge variant={sealed ? 'success' : 'warning'} className="mt-2 w-fit">
          {sealed ? 'exports protected' : 'action needed'}
        </Badge>
      )}
    </Metric>
  );
}

function EnvironmentCard({ data }: { data?: SiteStatus }) {
  const env = data?.environment;
  const rows: [string, ReactNode][] = env
    ? [
        ['WordPress', env.wp_version],
        ['PHP', env.php_version],
        ['Database', env.db_version],
        ['Table prefix', <code key="p">{env.table_prefix}</code>],
        ['Theme', env.theme],
        ['Active plugins', env.active_plugins],
      ]
    : [];

  return (
    <Card className="sm:col-span-2">
      <CardHeader className="pb-3">
        <CardTitle className="text-muted-foreground">Environment</CardTitle>
      </CardHeader>
      <CardContent>
        {env ? (
          <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-xs sm:grid-cols-[auto_1fr_auto_1fr]">
            {rows.map(([k, v]) => (
              <div key={k} className="contents">
                <dt className="text-muted-foreground">{k}</dt>
                <dd className="m-0 truncate text-right font-medium">{v}</dd>
              </div>
            ))}
          </dl>
        ) : (
          <Skeleton className="h-16 w-full" />
        )}
      </CardContent>
    </Card>
  );
}

function QuickActions({ onNavigate }: { onNavigate: (route: Route) => void }) {
  const actions: { label: string; description: string; icon: LucideIcon; route: Route }[] = [
    { label: 'Take a snapshot', description: 'Copy every table before you experiment', icon: Camera, route: 'snapshots' },
    { label: 'Reset Hub', description: 'Full or partial resets with safety nets', icon: RotateCcw, route: 'reset' },
  ];

  return (
    <Card className="sm:col-span-2">
      <CardHeader className="pb-3">
        <CardTitle className="text-muted-foreground">Quick actions</CardTitle>
        <CardDescription className="text-xs">
          Press <span className="font-mono">Ctrl/⌘ K</span> for everything else
        </CardDescription>
      </CardHeader>
      <CardContent className="grid gap-2 sm:grid-cols-2">
        {actions.map(({ label, description, icon: Icon, route }) => (
          <button
            key={label}
            type="button"
            onClick={() => onNavigate(route)}
            className="flex items-center gap-3 rounded-lg border p-3 text-left transition-colors hover:bg-accent"
          >
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-secondary">
              <Icon className="h-4 w-4" />
            </span>
            <span className="flex flex-col">
              <span className="text-sm font-medium">{label}</span>
              <span className="text-xs text-muted-foreground">{description}</span>
            </span>
          </button>
        ))}
      </CardContent>
    </Card>
  );
}
