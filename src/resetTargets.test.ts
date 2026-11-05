import { describe, expect, it } from 'vitest';

import { RESET_TARGETS, targetMeta } from './resetTargets';

describe('RESET_TARGETS', () => {
  it('has unique ids', () => {
    const ids = RESET_TARGETS.map((t) => t.id);
    expect(new Set(ids).size).toBe(ids.length);
  });

  it('every target has required fields', () => {
    for (const t of RESET_TARGETS) {
      expect(t.title.length).toBeGreaterThan(0);
      expect(t.description.length).toBeGreaterThan(0);
      expect(t.unit.length).toBeGreaterThan(0);
      expect(t.keywords.length).toBeGreaterThan(0);
    }
  });

  it('targetMeta returns the matching entry', () => {
    const t = targetMeta('transients');
    expect(t.id).toBe('transients');
  });
});