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
 * Multipart upload to /wp-json/wp-chrono/v1/{path}: a file (or a Blob slice,
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

/** Calls /wp-json/wp-chrono/v1/{path} with both WP REST and WP Chrono nonces. */
export async function api<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-WP-Nonce': config.restNonce,
    'X-WP-Chrono-Nonce': config.chronoNonce,
  };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';

  const response = await fetch(endpoint(path, options.query), {
    method: options.method ?? 'GET',
    headers,
    credentials: 'same-origin',
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    signal: options.signal,
  });

  const payload = await response.json().catch(() => null);
  if (!response.ok) {
    throw new ApiError(
      payload?.message ?? `Request failed with status ${response.status}`,
      response.status,
      payload?.code,
    );
  }
  return payload as T;
}
