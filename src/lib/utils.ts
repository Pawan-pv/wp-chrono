import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

/** Radix portals must render inside the root so scoped Tailwind styles apply. */
export function portalContainer(): HTMLElement | undefined {
  return document.getElementById('wp-chrono-root') ?? undefined;
}

export const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
