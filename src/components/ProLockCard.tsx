import { ExternalLink, KeyRound, Lock } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useLicense } from '@/hooks/useLicense';

/** The one place a locked Pro feature is mentioned: inline, no banners elsewhere. */
export function ProLockCard({ feature, onEnterLicense }: { feature: string; onEnterLicense: () => void }) {
  const { pricing } = useLicense();
  return (
    <div className="flex flex-wrap items-center gap-3 rounded-lg border border-dashed p-4 text-sm" data-e2e="pro-lock">
      <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border bg-secondary">
        <Lock className="h-4 w-4" />
      </span>
      <span className="min-w-0 flex-1">
        <span className="font-medium">{feature} is part of WP Chrono Pro.</span>{' '}
        <span className="text-muted-foreground">Restoring, importing and the recovery script stay free — always.</span>
      </span>
      {pricing && (
        <Button size="sm" variant="ghost" asChild>
          <a href={pricing} target="_blank" rel="noopener noreferrer">
            <ExternalLink />
            See plans
          </a>
        </Button>
      )}
      <Button size="sm" variant="outline" onClick={onEnterLicense}>
        <KeyRound />
        Enter license
      </Button>
    </div>
  );
}
