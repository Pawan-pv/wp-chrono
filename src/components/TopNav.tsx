import { History, Moon, Search, Settings, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { config } from '@/lib/config';
import { cn, isMac } from '@/lib/utils';
import { NAV } from '@/navigation';
import type { Route } from '@/hooks/useHashRoute';

interface TopNavProps {
  route: Route;
  onNavigate: (route: Route) => void;
  onOpenPalette: () => void;
  dark: boolean;
  onToggleTheme: () => void;
  onOpenSettings: () => void;
}

export function TopNav({ route, onNavigate, onOpenPalette, dark, onToggleTheme, onOpenSettings }: TopNavProps) {
  return (
    <header className="sticky top-0 z-30 border-b bg-background/80 backdrop-blur supports-[backdrop-filter]:bg-background/70">
      <div className="mx-auto flex h-14 max-w-7xl items-center gap-6 px-4 sm:px-6">
        <a href="#/dashboard" className="flex items-center gap-2.5 font-semibold tracking-tight">
          <span className="flex h-7 w-7 items-center justify-center rounded-md bg-primary text-primary-foreground">
            <History className="h-4 w-4" strokeWidth={2.25} />
          </span>
          <span className="hidden text-[15px] sm:inline">WP Chrono</span>
          <span className="hidden rounded border px-1.5 py-px font-mono text-[10px] font-normal text-muted-foreground sm:inline">
            v{config.version}
          </span>
        </a>

        <nav className="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto">
          {NAV.map(({ route: r, label, icon: Icon }) => (
            <button
              key={r}
              type="button"
              onClick={() => onNavigate(r)}
              aria-current={route === r ? 'page' : undefined}
              className={cn(
                'flex h-8 shrink-0 items-center gap-2 rounded-md px-3 text-sm transition-colors',
                route === r
                  ? 'bg-secondary font-medium text-foreground'
                  : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
              )}
            >
              <Icon className="h-4 w-4" />
              <span className="hidden md:inline">{label}</span>
            </button>
          ))}
        </nav>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={onOpenPalette}
            className="hidden w-56 justify-start font-normal text-muted-foreground lg:flex"
          >
            <Search />
            <span className="flex-1 text-left">Search commands…</span>
            <Kbd>{isMac ? '⌘' : 'Ctrl'} K</Kbd>
          </Button>
          <Button variant="ghost" size="icon" onClick={onOpenPalette} className="lg:hidden" aria-label="Open command palette">
            <Search />
          </Button>
          <Button variant="ghost" size="icon" onClick={onToggleTheme} aria-label="Toggle theme">
            {dark ? <Sun /> : <Moon />}
          </Button>
          <Button variant="ghost" size="icon" onClick={onOpenSettings} aria-label="Settings & License">
            <Settings />
          </Button>
        </div>
      </div>
    </header>
  );
}
