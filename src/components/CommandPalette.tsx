import { useEffect, useState } from 'react';
import { Blocks, Camera, Cloud, ExternalLink, History, KeyRound, LifeBuoy, Moon, RotateCcw, Settings, Wrench } from 'lucide-react';
import {
  CommandDialog,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
  CommandSeparator,
  CommandShortcut,
} from '@/components/ui/command';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import { timeAgo } from '@/lib/format';
import { NAV } from '@/navigation';
import { RESET_TARGETS } from '@/resetTargets';
import type { Route, RouteParams } from '@/hooks/useHashRoute';
import type { Snapshot, SnapshotIndex } from '@/types';

interface CommandPaletteProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onNavigate: (route: Route, params?: RouteParams) => void;
  onToggleTheme: () => void;
  onOpenSettings: (tab?: 'settings' | 'license') => void;
}

export function CommandPalette({ open, onOpenChange, onNavigate, onToggleTheme, onOpenSettings }: CommandPaletteProps) {
  // Cmd+K / Ctrl+K anywhere on the page. WordPress 7+ binds the same keys to
  // its own admin command palette on every screen; claiming them on window in
  // the capture phase (before any document listener) keeps the two from
  // opening together on this screen.
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key.toLowerCase() === 'k' && (e.metaKey || e.ctrlKey)) {
        e.preventDefault();
        e.stopImmediatePropagation();
        onOpenChange(!open);
      }
    };
    window.addEventListener('keydown', onKeyDown, true);
    return () => window.removeEventListener('keydown', onKeyDown, true);
  }, [open, onOpenChange]);

  // Recent snapshots for "Restore …" commands, fetched when the palette opens.
  const [recent, setRecent] = useState<Snapshot[]>([]);
  useEffect(() => {
    if (!open) return;
    api<SnapshotIndex>('snapshots')
      .then((d) => setRecent(d.snapshots.filter((s) => s.status === 'ready').slice(0, 5)))
      .catch(() => setRecent([]));
  }, [open]);

  const run = (fn: () => void) => () => {
    onOpenChange(false);
    fn();
  };

  return (
    <CommandDialog open={open} onOpenChange={onOpenChange}>
      <CommandInput placeholder="Type a command or search…" />
      <CommandList>
        <CommandEmpty>No matching commands.</CommandEmpty>

        <CommandGroup heading="Actions">
          <CommandItem value="create snapshot backup" onSelect={run(() => onNavigate('snapshots', { run: 'create' }))}>
            <Camera />
            Create Snapshot
          </CommandItem>
          <CommandItem value="scan orphaned tables scavenger cleanup" onSelect={run(() => onNavigate('snapshots', { run: 'scavenge' }))}>
            <Wrench />
            Scan Orphaned Tables
          </CommandItem>
          <CommandItem value="open recovery url emergency script" onSelect={run(() => onNavigate('recovery'))}>
            <LifeBuoy />
            Open Recovery URL
          </CommandItem>
          <CommandItem value="capture blueprint collection plugins baseline" onSelect={run(() => onNavigate('blueprints', { run: 'capture' }))}>
            <Blocks />
            Capture Site as Blueprint
          </CommandItem>
          <CommandItem value="reset to dev baseline blueprint apply" onSelect={run(() => onNavigate('blueprints'))}>
            <RotateCcw />
            Reset to Dev Baseline
          </CommandItem>
          <CommandItem value="cloud storage r2 s3 backblaze offload push pull" onSelect={run(() => onNavigate('blueprints'))}>
            <Cloud />
            Cloud Storage (R2 / S3 / B2)
          </CommandItem>
        </CommandGroup>

        <CommandSeparator />

        {recent.length > 0 && (
          <>
            <CommandGroup heading="Restore snapshot">
              {recent.map((s) => (
                <CommandItem
                  key={s.id}
                  value={`restore snapshot ${s.id} ${s.label}`}
                  onSelect={run(() => onNavigate('snapshots', { restore: String(s.id) }))}
                >
                  <History />
                  Restore #{s.id} {s.label && `— ${s.label}`}
                  <CommandShortcut>{timeAgo(s.created_at)}</CommandShortcut>
                </CommandItem>
              ))}
            </CommandGroup>
            <CommandSeparator />
          </>
        )}

        <CommandGroup heading="Reset">
          {RESET_TARGETS.map(({ id, title, icon: Icon, keywords }) => (
            <CommandItem key={id} value={`${title} ${keywords}`} onSelect={run(() => onNavigate('reset', { run: id }))}>
              <Icon />
              {title}
              {id === 'woocommerce' && !config.woocommerce && <CommandShortcut>inactive</CommandShortcut>}
            </CommandItem>
          ))}
          <CommandItem value="full site reset wipe reinstall" onSelect={run(() => onNavigate('reset', { run: 'full' }))}>
            <RotateCcw />
            Full Site Reset
            <CommandShortcut>danger</CommandShortcut>
          </CommandItem>
        </CommandGroup>

        <CommandSeparator />

        <CommandGroup heading="Navigate">
          {NAV.map(({ route, label, icon: Icon }) => (
            <CommandItem key={route} value={`go ${label}`} onSelect={run(() => onNavigate(route))}>
              <Icon />
              {label}
            </CommandItem>
          ))}
        </CommandGroup>

        <CommandSeparator />

        <CommandGroup heading="Preferences">
          <CommandItem value="toggle theme dark light mode" onSelect={run(onToggleTheme)}>
            <Moon />
            Toggle Dark Mode
          </CommandItem>
          <CommandItem value="settings retention storage threshold rollback limit" onSelect={run(() => onOpenSettings('settings'))}>
            <Settings />
            Settings
          </CommandItem>
          <CommandItem value="license key activate pro appsumo" onSelect={run(() => onOpenSettings('license'))}>
            <KeyRound />
            License
          </CommandItem>
          <CommandItem value="wordpress dashboard admin" onSelect={run(() => window.location.assign(config.adminUrl))}>
            <ExternalLink />
            Back to WordPress Dashboard
          </CommandItem>
        </CommandGroup>
      </CommandList>
    </CommandDialog>
  );
}
