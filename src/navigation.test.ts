import { describe, expect, it } from 'vitest';

import { NAV } from './navigation';

describe('NAV', () => {
  it('has unique routes', () => {
    const routes = NAV.map((n) => n.route);
    expect(new Set(routes).size).toBe(routes.length);
  });

  it('every entry has a label and icon', () => {
    for (const n of NAV) {
      expect(n.label.length).toBeGreaterThan(0);
      expect(n.icon).toBeTruthy();
    }
  });
});