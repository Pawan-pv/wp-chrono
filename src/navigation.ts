import { Blocks, History, LayoutDashboard, LifeBuoy, RotateCcw, type LucideIcon } from 'lucide-react';
import type { Route } from '@/hooks/useHashRoute';

export const NAV: { route: Route; label: string; icon: LucideIcon }[] = [
  { route: 'dashboard', label: 'Overview', icon: LayoutDashboard },
  { route: 'reset', label: 'Reset Hub', icon: RotateCcw },
  { route: 'snapshots', label: 'Snapshots', icon: History },
  { route: 'recovery', label: 'Recovery', icon: LifeBuoy },
  { route: 'blueprints', label: 'Blueprints', icon: Blocks },
];
