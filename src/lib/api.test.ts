import { describe, expect, it } from 'vitest';

import { endpoint } from './api';

describe('endpoint', () => {
  it('builds a pretty-permalink URL', () => {
    expect(endpoint('snapshots')).toBe('/wp-json/wp-chrono/v1/snapshots');
  });

  it('strips leading slashes from path', () => {
    expect(endpoint('/snapshots/5/build')).toBe('/wp-json/wp-chrono/v1/snapshots/5/build');
  });

  it('appends query params with ? on pretty permalinks', () => {
    expect(endpoint('snapshots', { page: 2, q: 'a' })).toBe('/wp-json/wp-chrono/v1/snapshots?page=2&q=a');
  });

  // Plain permalinks (?rest_route=, params joined with &) are covered in api.client.test.ts.
  it('skips undefined query values', () => {
    expect(endpoint('x', { a: 1, b: undefined })).toBe('/wp-json/wp-chrono/v1/x?a=1');
  });

  it('handles boolean/number query values', () => {
    expect(endpoint('x', { flag: true, n: 0 })).toBe('/wp-json/wp-chrono/v1/x?flag=true&n=0');
  });
});