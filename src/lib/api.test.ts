import { describe, expect, it } from 'vitest';

import { endpoint } from './api';

describe('endpoint', () => {
  // The default base is the plain index.php?rest_route= form (Admin +
  // config fallback), so these test that shape with params joined by &.
  // Pretty /wp-json/… bases are covered in api.client.test.ts.
  it('builds a plain-permalink URL', () => {
    expect(endpoint('snapshots')).toBe('/index.php?rest_route=/wp-chrono/v1/snapshots');
  });

  it('strips leading slashes from path', () => {
    expect(endpoint('/snapshots/5/build')).toBe('/index.php?rest_route=/wp-chrono/v1/snapshots/5/build');
  });

  it('appends query params with & on plain permalinks', () => {
    expect(endpoint('snapshots', { page: 2, q: 'a' })).toBe('/index.php?rest_route=/wp-chrono/v1/snapshots&page=2&q=a');
  });

  it('skips undefined query values', () => {
    expect(endpoint('x', { a: 1, b: undefined })).toBe('/index.php?rest_route=/wp-chrono/v1/x&a=1');
  });

  it('handles boolean/number query values', () => {
    expect(endpoint('x', { flag: true, n: 0 })).toBe('/index.php?rest_route=/wp-chrono/v1/x&flag=true&n=0');
  });
});