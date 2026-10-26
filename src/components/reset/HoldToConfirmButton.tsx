import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { cn } from '@/lib/utils';

interface HoldToConfirmButtonProps {
  /** When armed (e.g. RESET was typed), a plain click confirms immediately. */
  armed: boolean;
  disabled?: boolean;
  holdMs?: number;
  onConfirm: () => void;
  children: ReactNode;
}

const RADIUS = 7;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

/**
 * Destructive confirm button: click when armed, or press and hold for
 * `holdMs` while a progress ring fills. Works with pointer and Space/Enter.
 */
export function HoldToConfirmButton({ armed, disabled, holdMs = 1500, onConfirm, children }: HoldToConfirmButtonProps) {
  const [progress, setProgress] = useState(0);
  const frame = useRef<number>();
  const startedAt = useRef<number | null>(null);
  const fired = useRef(false);

  const cancel = useCallback(() => {
    if (frame.current) cancelAnimationFrame(frame.current);
    startedAt.current = null;
    if (!fired.current) setProgress(0);
  }, []);

  const tick = useCallback(
    (now: number) => {
      if (startedAt.current === null) return;
      const value = Math.min((now - startedAt.current) / holdMs, 1);
      setProgress(value);
      if (value >= 1) {
        fired.current = true;
        startedAt.current = null;
        onConfirm();
        return;
      }
      frame.current = requestAnimationFrame(tick);
    },
    [holdMs, onConfirm],
  );

  const start = useCallback(() => {
    if (disabled || armed || startedAt.current !== null) return;
    fired.current = false;
    startedAt.current = performance.now();
    frame.current = requestAnimationFrame(tick);
  }, [armed, disabled, tick]);

  useEffect(() => cancel, [cancel]);

  return (
    <button
      type="button"
      disabled={disabled}
      onClick={() => armed && !disabled && onConfirm()}
      onPointerDown={start}
      onPointerUp={cancel}
      onPointerLeave={cancel}
      onKeyDown={(e) => {
        if ((e.key === ' ' || e.key === 'Enter') && !e.repeat && !armed) {
          e.preventDefault();
          start();
        }
      }}
      onKeyUp={(e) => (e.key === ' ' || e.key === 'Enter') && cancel()}
      className={cn(
        'relative inline-flex h-9 select-none items-center justify-center gap-2 overflow-hidden rounded-md bg-destructive px-4 text-sm font-medium text-destructive-foreground shadow-sm transition-colors hover:bg-destructive/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-destructive/50 disabled:pointer-events-none disabled:opacity-50',
      )}
    >
      {/* Fill sweeps left-to-right while holding. */}
      <span className="absolute inset-y-0 left-0 bg-black/20" style={{ width: `${progress * 100}%` }} aria-hidden />
      <svg viewBox="0 0 18 18" className="relative h-[18px] w-[18px] -rotate-90" aria-hidden>
        <circle cx="9" cy="9" r={RADIUS} fill="none" stroke="currentColor" strokeOpacity="0.3" strokeWidth="2" />
        <circle
          cx="9"
          cy="9"
          r={RADIUS}
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeDasharray={CIRCUMFERENCE}
          strokeDashoffset={CIRCUMFERENCE * (1 - (armed ? 1 : progress))}
        />
      </svg>
      <span className="relative">{children}</span>
    </button>
  );
}
