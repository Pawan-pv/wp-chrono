import { afterEach, describe, expect, it, vi } from 'vitest';

import type { ChronoConfig } from './config';

/** Loads api.ts against a given window.wpChrono (config is read at import time). */
async function loadApi(overrides: Partial<ChronoConfig> = {}) {
  vi.resetModules();
  window.wpChrono = {
    version: 'test',
    restUrl: '/wp-json/wp-chrono/v1/',
    restNonce: 'rest-nonce',
    chronoNonce: 'chrono-nonce',
    adminUrl: '/wp-admin/',
    siteUrl: '/',
    siteName: 'Test',
    user: { name: 'A', login: 'a' },
    woocommerce: false,
    multisite: false,
    mainSite: false,
    superAdmin: false,
    welcome: false,
    license: { active: false, plan: 'free', features: { blueprints: false, cloud: false }, pricing: '' },
    ...overrides,
  };
  return import('./api');
}

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

describe('api client', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    delete window.wpChrono;
  });

  it('joins query params with & on plain permalinks (?rest_route=)', async () => {
    const { endpoint } = await loadApi({ restUrl: '/index.php?rest_route=/wp-chrono/v1/' });
    expect(endpoint('snapshots', { page: 2 })).toBe('/index.php?rest_route=/wp-chrono/v1/snapshots&page=2');
    expect(endpoint('/snapshots/5')).toBe('/index.php?rest_route=/wp-chrono/v1/snapshots/5');
  });

  it('encodes query values', async () => {
    const { endpoint } = await loadApi();
    expect(endpoint('x', { q: 'a&b=c d' })).toBe('/wp-json/wp-chrono/v1/x?q=a%26b%3Dc+d');
  });

  it('sends both nonces, same-origin credentials and a JSON body', async () => {
    const fetchMock = vi.fn(async () => json(200, { ok: true }));
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await loadApi();

    await expect(api('reset', { method: 'POST', body: { targets: ['transients'] } })).resolves.toEqual({ ok: true });
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('/wp-json/wp-chrono/v1/reset');
    expect(init.method).toBe('POST');
    expect(init.credentials).toBe('same-origin');
    expect(init.headers).toMatchObject({ 'X-WP-Nonce': 'rest-nonce', 'X-WP-Chrono-Nonce': 'chrono-nonce', 'Content-Type': 'application/json' });
    expect(init.body).toBe('{"targets":["transients"]}');
  });

  it('GET requests carry no body or Content-Type', async () => {
    const fetchMock = vi.fn(async () => json(200, []));
    vi.stubGlobal('fetch', fetchMock);
    const { api } = await loadApi();
    await api('snapshots');
    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(init.method).toBe('GET');
    expect(init.body).toBeUndefined();
    expect(init.headers).not.toHaveProperty('Content-Type');
  });

  it("throws ApiError with the server's message, status and code", async () => {
    vi.stubGlobal('fetch', vi.fn(async () => json(403, { code: 'wp_chrono_pro', message: 'Blueprints need a Pro license.' })));
    const { api, ApiError } = await loadApi();
    const error = await api('blueprints').catch((e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({ message: 'Blueprints need a Pro license.', status: 403, code: 'wp_chrono_pro' });
  });

  it('falls back to a generic message when the error body is not JSON (PHP fatal page)', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('<b>Fatal error</b>', { status: 500 })));
    const { api } = await loadApi();
    await expect(api('snapshots')).rejects.toMatchObject({ message: 'Request failed with status 500', status: 500 });
  });

  it('uploads multipart with the nonces and explains a web-server 413', async () => {
    const fetchMock = vi.fn(async () => new Response('<html>413</html>', { status: 413 }));
    vi.stubGlobal('fetch', fetchMock);
    const { apiUpload } = await loadApi();

    const error = await apiUpload('snapshots/import/chunk', 'chunk', new Blob(['x']), { offset: '0' }, 'chunk.part').catch((e: unknown) => e);
    expect(error).toMatchObject({ status: 413 });
    expect((error as Error).message).toMatch(/client_max_body_size/);

    const [, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    const form = init.body as FormData;
    expect((form.get('chunk') as File).name).toBe('chunk.part');
    expect(form.get('offset')).toBe('0');
    expect(init.headers).toMatchObject({ 'X-WP-Nonce': 'rest-nonce', 'X-WP-Chrono-Nonce': 'chrono-nonce' });
    // The browser sets the multipart boundary itself.
    expect(init.headers).not.toHaveProperty('Content-Type');
  });
});
