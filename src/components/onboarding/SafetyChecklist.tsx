import { ShieldCheck, X } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { Route, RouteParams } from '@/hooks/useHashRoute';
import { api } from '@/lib/api';
import type { OnboardingStatus } from '@/types';
import { ChecklistSteps } from './ChecklistSteps';

interface SafetyChecklistProps {
  status: OnboardingStatus | undefined;
  onChange: (next: OnboardingStatus) => void;
  onNavigate: (route: Route, params?: RouteParams) => void;
}

/** Dismissible first-run card on the Overview. Gone for good once dismissed. */
export function SafetyChecklist({ status, onChange, onNavigate }: SafetyChecklistProps) {
  if (!status || status.dismissed) return null;
  const done = Object.values(status.steps).filter(Boolean).length;

  const dismiss = async () => onChange(await api<OnboardingStatus>('onboarding', { method: 'POST', body: { dismissed: true } }));

  return (
    <Card data-e2e="safety-checklist">
      <CardHeader className="flex-row items-start justify-between gap-3 pb-3">
        <div className="flex flex-col gap-1.5">
          <CardTitle className="flex items-center gap-2">
            <ShieldCheck className="h-4 w-4 text-emerald-500" />
            {status.complete ? 'Your safety net is ready' : 'First-run safety checklist'}
            <span className="font-normal text-muted-foreground">{done}/3</span>
          </CardTitle>
          <CardDescription>
            {status.complete ? 'You can undo updates, recover a crashed site and restore any snapshot.' : 'Three quick steps before you need them.'}
          </CardDescription>
        </div>
        <button type="button" onClick={dismiss} className="rounded p-1 text-muted-foreground hover:text-foreground" aria-label="Dismiss checklist">
          <X className="h-4 w-4" />
        </button>
      </CardHeader>
      {!status.complete && (
        <CardContent>
          <ChecklistSteps status={status} onChange={onChange} onNavigate={onNavigate} />
        </CardContent>
      )}
    </Card>
  );
}
