import { useEffect, useRef, useState } from 'react';
import { AlertTriangle, CheckCircle2, Circle, Loader2, ShieldCheck, XCircle } from 'lucide-react';
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { Switch } from '@/components/ui/switch';
import { cn } from '@/lib/utils';
import { HoldToConfirmButton } from './HoldToConfirmButton';
import type { LogTone, PlanOutcome, ResetPlan, StepStatus } from './plans';

export interface DialogToggle {
  id: string;
  label: string;
  hint: string;
  default: boolean;
}

/** A required one-of-N choice (radio cards), passed to the plan as `choice`. */
export interface DialogChoice {
  label: string;
  default: string;
  options: { value: string; label: string; hint: string }[];
}

export interface ConfirmResetDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: string;
  details?: string[];
  /** Shown instead of the confirm controls when the action can't run. */
  unavailableReason?: string | null;
  confirmLabel: string;
  /** Word the user types to arm the button. */
  confirmWord?: string;
  defaultSnapshot?: boolean;
  /** Extra switches passed to the plan as `flags`. */
  toggles?: DialogToggle[];
  choice?: DialogChoice;
  plan: ResetPlan | null;
  /** Called once a run finishes (success or failure) so the caller can refresh data. */
  onFinished?: () => void;
}

type Phase = 'confirm' | 'running' | 'done' | 'error';

export function ConfirmResetDialog({
  open,
  onOpenChange,
  title,
  description,
  details = [],
  unavailableReason,
  confirmLabel,
  confirmWord = 'RESET',
  defaultSnapshot = true,
  toggles = [],
  choice,
  plan,
  onFinished,
}: ConfirmResetDialogProps) {
  const [phase, setPhase] = useState<Phase>('confirm');
  const [typed, setTyped] = useState('');
  const [snapshot, setSnapshot] = useState(defaultSnapshot);
  const [flags, setFlags] = useState<Record<string, boolean>>({});
  const [picked, setPicked] = useState('');
  const [steps, setSteps] = useState<Record<string, StepStatus>>({});
  const [log, setLog] = useState<{ text: string; tone: LogTone; at: string }[]>([]);
  const [progress, setProgress] = useState<number | null>(null);
  const [outcome, setOutcome] = useState<PlanOutcome | null>(null);
  const [error, setError] = useState<string | null>(null);
  const logEnd = useRef<HTMLDivElement>(null);

  // Fresh state every time the dialog opens.
  useEffect(() => {
    if (!open) return;
    setPhase('confirm');
    setTyped('');
    setSnapshot(defaultSnapshot);
    setFlags(Object.fromEntries(toggles.map((t) => [t.id, t.default])));
    setPicked(choice?.default ?? '');
    setSteps({});
    setLog([]);
    setProgress(null);
    setOutcome(null);
    setError(null);
  }, [open, defaultSnapshot, choice?.default]);

  // Block body: scrollIntoView() returns a Promise in newer browsers, and an
  // effect must not return anything but a cleanup function.
  useEffect(() => {
    logEnd.current?.scrollIntoView({ block: 'nearest' });
  }, [log]);

  const running = phase === 'running';
  const armed = typed.trim() === confirmWord;
  const blocked = Boolean(unavailableReason) || !plan;

  const execute = async () => {
    if (!plan || running) return;
    setPhase('running');
    const time = () => new Date().toLocaleTimeString(undefined, { hour12: false });
    try {
      const result = await plan.execute(
        {
          step: (id, status) => setSteps((s) => ({ ...s, [id]: status })),
          log: (text, tone = 'info') => setLog((l) => [...l, { text, tone, at: time() }]),
          progress: setProgress,
        },
        { snapshot, flags, choice: picked },
      );
      setOutcome(result);
      setLog((l) => [...l, { text: result.summary, tone: 'success', at: time() }]);
      setPhase('done');
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      setSteps((s) => Object.fromEntries(Object.entries(s).map(([k, v]) => [k, v === 'running' ? 'error' : v])));
      setLog((l) => [...l, { text: message, tone: 'error', at: time() }]);
      setError(message);
      setPhase('error');
    } finally {
      onFinished?.();
    }
  };

  return (
    <AlertDialog open={open} onOpenChange={(next) => !running && onOpenChange(next)}>
      <AlertDialogContent className="max-w-xl" onEscapeKeyDown={(e) => running && e.preventDefault()}>
        <div className="flex gap-4">
          <span
            className={cn(
              'flex h-10 w-10 shrink-0 items-center justify-center rounded-full',
              phase === 'done' ? 'bg-emerald-500/10 text-emerald-600' : 'bg-destructive/10 text-destructive',
            )}
          >
            {phase === 'done' ? <CheckCircle2 className="h-5 w-5" /> : <AlertTriangle className="h-5 w-5" />}
          </span>
          <div className="flex min-w-0 flex-col gap-1.5">
            <AlertDialogTitle>{title}</AlertDialogTitle>
            <AlertDialogDescription>{description}</AlertDialogDescription>
          </div>
        </div>

        {phase === 'confirm' ? (
          <>
            {details.length > 0 && (
              <ul className="max-h-40 overflow-y-auto rounded-lg border bg-muted/40 p-3 font-mono text-xs leading-relaxed text-muted-foreground">
                {details.map((d) => (
                  <li key={d} className="truncate">
                    {d}
                  </li>
                ))}
              </ul>
            )}

            {unavailableReason ? (
              <p className="rounded-lg border border-amber-500/30 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
                {unavailableReason}
              </p>
            ) : (
              <>
                {choice && (
                  <fieldset className="flex flex-col gap-2" data-e2e="restore-mode">
                    <legend className="mb-2 text-sm font-medium">{choice.label}</legend>
                    {choice.options.map((o) => (
                      <label
                        key={o.value}
                        className={cn(
                          'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                          picked === o.value ? 'border-primary bg-primary/5' : 'hover:bg-muted/50',
                        )}
                      >
                        <input
                          type="radio"
                          name="wpc-choice"
                          value={o.value}
                          checked={picked === o.value}
                          onChange={() => setPicked(o.value)}
                          className="mt-0.5 accent-current"
                        />
                        <span className="flex flex-col">
                          <span className="text-sm font-medium">{o.label}</span>
                          <span className="text-xs text-muted-foreground">{o.hint}</span>
                        </span>
                      </label>
                    ))}
                  </fieldset>
                )}

                <label className="flex cursor-pointer items-center justify-between gap-4 rounded-lg border p-3">
                  <span className="flex items-center gap-3">
                    <ShieldCheck className="h-4 w-4 text-emerald-500" />
                    <span className="flex flex-col">
                      <span className="text-sm font-medium">Take a safety snapshot first</span>
                      <span className="text-xs text-muted-foreground">Lets you roll back if something goes wrong.</span>
                    </span>
                  </span>
                  <Switch checked={snapshot} onCheckedChange={setSnapshot} />
                </label>

                {toggles.map((t) => (
                  <label key={t.id} className="flex cursor-pointer items-center justify-between gap-4 rounded-lg border p-3">
                    <span className="flex flex-col">
                      <span className="text-sm font-medium">{t.label}</span>
                      <span className="text-xs text-muted-foreground">{t.hint}</span>
                    </span>
                    <Switch checked={Boolean(flags[t.id])} onCheckedChange={(v) => setFlags((f) => ({ ...f, [t.id]: v }))} />
                  </label>
                ))}

                <div className="flex flex-col gap-2">
                  <label htmlFor="wpc-confirm" className="text-sm">
                    Type <span className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs font-semibold">{confirmWord}</span> to
                    confirm, or hold the button.
                  </label>
                  <Input
                    id="wpc-confirm"
                    autoFocus
                    autoComplete="off"
                    spellCheck={false}
                    value={typed}
                    onChange={(e) => setTyped(e.target.value)}
                    onKeyDown={(e) => e.key === 'Enter' && armed && execute()}
                    placeholder={confirmWord}
                    className="font-mono"
                  />
                </div>
              </>
            )}

            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <AlertDialogCancel asChild>
                <Button variant="outline">Cancel</Button>
              </AlertDialogCancel>
              {!blocked && (
                <HoldToConfirmButton armed={armed} onConfirm={execute}>
                  {armed ? confirmLabel : 'Hold to confirm'}
                </HoldToConfirmButton>
              )}
            </div>
          </>
        ) : (
          <>
            <ol className="flex flex-col gap-2">
              {plan?.steps.map(({ id, label }) => (
                <li key={id} className="flex items-center gap-3 text-sm">
                  <StepIcon status={steps[id] ?? 'pending'} />
                  <span className={cn((steps[id] ?? 'pending') === 'pending' && 'text-muted-foreground')}>{label}</span>
                </li>
              ))}
            </ol>

            {phase !== 'error' && <Progress value={progress} />}

            <div className="max-h-56 min-h-[7rem] overflow-y-auto rounded-lg border bg-zinc-950 p-3 font-mono text-[11px] leading-relaxed text-zinc-300">
              {log.map((line, i) => (
                <div
                  key={i}
                  className={cn(
                    'flex gap-3',
                    line.tone === 'error' && 'text-red-400',
                    line.tone === 'success' && 'text-emerald-400',
                  )}
                >
                  <span className="shrink-0 text-zinc-600">{line.at}</span>
                  <span className="whitespace-pre-wrap break-words">{line.text}</span>
                </div>
              ))}
              {running && <div className="animate-pulse text-zinc-500">▍</div>}
              <div ref={logEnd} />
            </div>

            {phase === 'error' && (
              <p className="flex items-center gap-2 text-sm text-destructive">
                <XCircle className="h-4 w-4" /> {error}
              </p>
            )}

            <div className="flex justify-end gap-2">
              {outcome?.action && (
                <Button variant="outline" onClick={() => window.location.assign(outcome.action!.href)}>
                  {outcome.action.label}
                </Button>
              )}
              <Button disabled={running} onClick={() => onOpenChange(false)}>
                {running ? (
                  <>
                    <Loader2 className="animate-spin" /> Working…
                  </>
                ) : (
                  'Close'
                )}
              </Button>
            </div>
          </>
        )}
      </AlertDialogContent>
    </AlertDialog>
  );
}

function StepIcon({ status }: { status: StepStatus }) {
  switch (status) {
    case 'running':
      return <Loader2 className="h-4 w-4 animate-spin text-primary" />;
    case 'done':
      return <CheckCircle2 className="h-4 w-4 text-emerald-500" />;
    case 'error':
      return <XCircle className="h-4 w-4 text-destructive" />;
    default:
      return <Circle className="h-4 w-4 text-muted-foreground/50" />;
  }
}
