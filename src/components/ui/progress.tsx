import { cn } from '@/lib/utils';

/** Determinate when `value` is 0–1, indeterminate (animated stripe) when null. */
export function Progress({ value, className }: { value: number | null; className?: string }) {
  return (
    <div className={cn('relative h-1.5 w-full overflow-hidden rounded-full bg-muted', className)}>
      {value === null ? (
        <div className="absolute inset-y-0 w-1/3 animate-[wpc-indeterminate_1.2s_ease-in-out_infinite] rounded-full bg-primary/70" />
      ) : (
        <div className="h-full rounded-full bg-primary transition-[width] duration-300" style={{ width: `${Math.min(100, value * 100)}%` }} />
      )}
    </div>
  );
}
