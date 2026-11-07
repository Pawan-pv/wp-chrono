import { afterEach, describe, expect, it, vi } from 'vitest';

import { refreshConfig } from './plans';
import { config } from './config';

describe('refreshConfig', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('updates config from a successful nonce response', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ rest: 'fresh-rest', chrono: 'fresh-chrono' }), { status: 200 })));

    await refreshConfig();

    expect(config.restNonce).toBe('fresh-rest');
    expect(config.chronoNonce).toBe('fresh-chrono');
  });

  it('falls back to provided nonces when nonce endpoint returns empty body', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 200 })));

    await refreshConfig({ rest: 'fallback-rest', chrono: 'fallback-chrono' });

    expect(config.restNonce).toBe('fallback-rest');
    expect(config.chronoNonce).toBe('fallback-chrono');
  });

  it('throws when nonce endpoint fails and no fallback is provided', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 200 })));

    await expect(refreshConfig()).rejects.toThrow('empty response');
  });
});
