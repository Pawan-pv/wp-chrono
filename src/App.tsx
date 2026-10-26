import { useState } from 'react';
import { CommandPalette } from '@/components/CommandPalette';
import { TopNav } from '@/components/TopNav';
import { WelcomeModal } from '@/components/onboarding/WelcomeModal';
import { SettingsDialog, type SettingsTab } from '@/components/settings/SettingsDialog';
import { useHashRoute } from '@/hooks/useHashRoute';
import { useTheme } from '@/hooks/useTheme';
import { Blueprints } from '@/views/Blueprints';
import { Dashboard } from '@/views/Dashboard';
import { Recovery } from '@/views/Recovery';
import { ResetHub } from '@/views/ResetHub';
import { Snapshots } from '@/views/Snapshots';

export function App({ root }: { root: HTMLElement }) {
  const { route, params, navigate, clearParams } = useHashRoute();
  const { resolved, toggle } = useTheme(root);
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [settingsTab, setSettingsTab] = useState<SettingsTab>('settings');

  const openSettings = (tab: SettingsTab = 'settings') => {
    setSettingsTab(tab);
    setSettingsOpen(true);
  };

  return (
    <>
      <TopNav
        route={route}
        onNavigate={navigate}
        onOpenPalette={() => setPaletteOpen(true)}
        dark={resolved === 'dark'}
        onToggleTheme={toggle}
        onOpenSettings={() => openSettings()}
      />

      <main className="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        {route === 'dashboard' && <Dashboard onNavigate={navigate} />}
        {route === 'reset' && <ResetHub params={params} clearParams={clearParams} />}
        {route === 'snapshots' && <Snapshots params={params} clearParams={clearParams} />}
        {route === 'recovery' && <Recovery />}
        {route === 'blueprints' && <Blueprints params={params} clearParams={clearParams} onOpenLicense={() => openSettings('license')} />}
      </main>

      <CommandPalette
        open={paletteOpen}
        onOpenChange={setPaletteOpen}
        onNavigate={navigate}
        onToggleTheme={toggle}
        onOpenSettings={openSettings}
      />
      <SettingsDialog open={settingsOpen} onOpenChange={setSettingsOpen} tab={settingsTab} onTabChange={setSettingsTab} />
      <WelcomeModal onNavigate={navigate} />
    </>
  );
}
