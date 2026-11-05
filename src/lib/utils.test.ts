import { describe, expect, it } from 'vitest';

import { cn } from './utils';

describe('cn', () => {
  it('merges class names', () => {
    expect(cn('foo', 'bar')).toBe('foo bar');
  });

  it('drops falsy values', () => {
    expect(cn('foo', false && 'bar', undefined, null, 'baz')).toBe('foo baz');
  });

  it('resolves tailwind conflicts', () => {
    expect(cn('px-2', 'px-4')).toBe('px-4');
  });
});