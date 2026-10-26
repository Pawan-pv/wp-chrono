import { useEffect, useState } from 'react';
import { History } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import type { Route, RouteParams } from '@/hooks/useHashRoute';
import { api } from '@/lib/api';
import { config } from '@/lib/config';
import type { OnboardingStatus } from '@/types';
import { ChecklistSteps } from './ChecklistSteps';

/** Shown once, right after the first activation. */
export function WelcomeModal({ onNavigate }: { onNavigate: (route: Route, params?: RouteParams) => void }) {
  const [open, setOpen] = useState(config.welcome);
  const [status, setStatus] = useState<OnboardingStatus | null>(null);

  useEffect(() => {
    if (open) api<OnboardingStatus>('onboarding').then(setStatus).catch(() => undefined);
  }, [open]);

  const close = () => {
    setOpen(false);
    config.welcome = false;
    api('onboarding', { method: 'POST', body: { welcome_seen: true } }).catch(() => undefined);
  };

  return (
    <Dialog open={open} onOpenChange={(next) => !next && close()}>
      <DialogContent className="max-w-lg" data-e2e="welcome-modal">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground">
            <History className="h-5 w-5" />
          </span>
          <div className="flex flex-col gap-1.5">
            <DialogTitle>Welcome to WP Chrono</DialogTitle>
            <DialogDescription>Set up your safety net in under a minute. Restoring your site is always free — no upsells, ever.</DialogDescription>
          </div>
        </div>
        {status && (
          <ChecklistSteps
            status={status}
            onChange={setStatus}
            onNavigate={(route, params) => {
              close();
              onNavigate(route, params);
            }}
          />
        )}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={close}>
            {status?.complete ? 'Done' : 'Skip for now'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
