import { config } from './config';

export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code?: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

type Query = Record<string, string | number | boolean | undefined>;

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  query?: Query;
  body?: unknown;
  signal?: AbortSignal;
}

/**
 * Builds an endpoint URL. Handles both pretty permalinks (/wp-json/…) and
 * plain permalinks (?rest_route=/…), where query params must join with `&`.
 */
export function endpoint(path: string, query?: Query): string {
  let url = config.restUrl + path.replace(/^\/+/, '');
  if (query) {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined) params.set(key, String(value));
    }
    const qs = params.toString();
    if (qs) url += (url.includes('?') ? '&' : '?') + qs;
  }
  return url;
}

/**
 * Multipart upload to wp-chrono/v1/{path}: a file (or a Blob slice,
 * for chunked uploads) plus optional extra form fields.
 */
export async function apiUpload<T>(path: string, field: string, file: Blob, extra: Record<string, string> = {}, filename = 'upload'): Promise<T> {
  const body = new FormData();
  body.append(field, file, file instanceof File ? file.name : filename);
  for (const [key, value] of Object.entries(extra)) body.append(key, value);
  const response = await fetch(endpoint(path), {
    method: 'POST',
    headers: { Accept: 'application/json', 'X-WP-Nonce': config.restNonce, 'X-WP-Chrono-Nonce': config.chronoNonce },
    credentials: 'same-origin',
    body,
  });
  const payload = await response.json().catch(() => null);
  if (!response.ok) {
    const fallback =
      response.status === 413
        ? 'The web server rejected the upload as too large (HTTP 413). Raise its request size limit (nginx client_max_body_size, Apache LimitRequestBody) and try again.'
        : `Upload failed with status ${response.status}`;
    throw new ApiError(payload?.message ?? fallback, response.status, payload?.code);
  }
  return payload as T;
}

/** Calls wp-chrono/v1/{path} with both WP REST and WP Chrono nonces. The base is the ?rest_route= form (see Admin), so any permalink setting works. */
export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-WP-Nonce': config.restNonce,
    'X-WP-Chrono-Nonce': config.chronoNonce,
  };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';

  const url = endpoint(path, options.query);
  const response = await fetch(url, {
    method: options.method ?? 'GET',
    headers,
    credentials: 'same-origin',
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    signal: options.signal,
  });

  const text = await response.text();
  let payload: unknown = null;
  try {
    payload = text ? JSON.parse(text) : null;
  } catch {
    // Empty or non-JSON body (e.g. server returned HTTP 200 with no body).
    payload = null;
  }
  if (!response.ok) {
    // payload is `unknown` until proven otherwise: narrow at runtime instead of
    // giving it a fake type. Only string `message`/`code` are trusted.
    const body = typeof payload === 'object' && payload !== null
      ? (payload as Record<string, unknown>)
      : {};
    const message = typeof body.message === 'string'
      ? body.message
      : `Request failed with status ${response.status}`;
    const code = typeof body.code === 'string' ? body.code : undefined;
    throw new ApiError(message, response.status, code);
  }
  if (payload === null || payload === undefined) {
    // A 200 that isn't JSON (e.g. the homepage after a permalink change
    // broke /wp-json/…) must not be mistaken for an empty API reply:
    // name what actually came back so the mismatch is obvious.
    const contentType = response.headers.get('content-type') ?? 'unknown';
    throw new ApiError(
      `The server returned a web page instead of data (HTTP ${response.status}, ${contentType}) for ${path}.`,
      response.status,
    );
  }
  return payload as T;
}
