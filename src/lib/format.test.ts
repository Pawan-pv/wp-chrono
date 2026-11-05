import { describe, expect, it } from 'vitest';

import { formatBytes, formatNumber, timeAgo } from './format';

describe('formatBytes', () => {
  it('returns dash for null/undefined', () => {
    expect(formatBytes(null)).toBe('—');
    expect(formatBytes(undefined)).toBe('—');
  });

  it('formats zero', () => {
    expect(formatBytes(0)).toBe('0 B');
  });

  it('formats kilobytes', () => {
    expect(formatBytes(1024)).toBe('1.0 KB');
  });
});

describe('formatNumber', () => {
  it('formats with locale separators', () => {
    expect(formatNumber(1234567)).not.toBe('');
  });
});

describe('formatBytes (extra)', () => {
  it('formats megabytes and gigabytes', () => {
    expect(formatBytes(1024 * 1024)).toBe('1.0 MB');
    expect(formatBytes(1024 * 1024 * 1024)).toBe('1.0 GB');
  });

  it('caps at TB', () => {
    expect(formatBytes(1024 ** 5)).toMatch(/TB$/);
  });

  it('respects decimals param', () => {
    expect(formatBytes(1536, 2)).toBe('1.50 KB');
  });
});

describe('timeAgo', () => {
  it('returns a non-empty relative string for past date', () => {
    const past = new Date(Date.now() - 3600_000).toISOString();
    expect(typeof timeAgo(past)).toBe('string');
    expect(timeAgo(past).length).toBeGreaterThan(0);
  });

  it('returns a non-empty relative string for future date', () => {
    const future = new Date(Date.now() + 86400_000).toISOString();
    expect(timeAgo(future).length).toBeGreaterThan(0);
  });
});