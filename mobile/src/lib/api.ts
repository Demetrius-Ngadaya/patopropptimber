import { runtime } from './runtime';
import { t } from '../i18n';

/**
 * PatoPro API client. Every error is an ApiError, with `offline` set when the
 * server could not be reached at all (no network, DNS, timeout), so callers
 * can tell "you are offline" from "the server said no".
 */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string | null = null,
    readonly errors: Record<string, string[]> | null = null,
    readonly offline = false,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** The first validation message, or the main message. */
  get firstError(): string {
    const first = this.errors ? Object.values(this.errors)[0]?.[0] : null;
    return first ?? this.message;
  }
}

type Query = Record<string, string | number | boolean | null | undefined | (string | number)[] | Record<string, string | null>>;

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  query?: Query;
  timeoutMs?: number;
  auth?: boolean;
  signal?: AbortSignal;
};

export type Envelope<T> = { success: boolean; message?: string; data: T; meta?: Record<string, unknown> };

/** PHP-style query string: arrays as key[]=, objects as key[sub]=. */
export function buildQuery(query?: Query): string {
  if (!query) return '';
  const parts: string[] = [];
  const add = (k: string, v: unknown) => {
    if (v === undefined || v === null || v === '') return;
    parts.push(`${encodeURIComponent(k)}=${encodeURIComponent(typeof v === 'boolean' ? (v ? '1' : '0') : String(v))}`);
  };
  for (const [key, value] of Object.entries(query)) {
    if (Array.isArray(value)) value.forEach((v) => add(`${key}[]`, v));
    else if (value && typeof value === 'object') Object.entries(value).forEach(([sub, v]) => add(`${key}[${sub}]`, v));
    else add(key, value);
  }
  return parts.length ? `?${parts.join('&')}` : '';
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<Envelope<T>> {
  const { apiUrl, token, branchId, locale } = runtime.get();
  if (!apiUrl) throw new ApiError(t('The server address is not set.'), 0, 'NO_SERVER', null, true);

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), options.timeoutMs ?? 20000);
  options.signal?.addEventListener('abort', () => controller.abort());

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': locale,
    'X-Requested-With': 'PatoProMobile',
  };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (options.auth !== false && token) headers.Authorization = `Bearer ${token}`;
  if (branchId) headers['X-Branch-Id'] = String(branchId);

  let response: Response;
  try {
    response = await fetch(`${apiUrl.replace(/\/+$/, '')}/api/v1/${path.replace(/^\/+/, '')}${buildQuery(options.query)}`, {
      method: options.method ?? (options.body !== undefined ? 'POST' : 'GET'),
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      signal: controller.signal,
    });
  } catch (e) {
    const aborted = e instanceof Error && e.name === 'AbortError';
    throw new ApiError(aborted ? t('The server took too long to answer.') : t('No internet connection.'), 0, aborted ? 'TIMEOUT' : 'OFFLINE', null, true);
  } finally {
    clearTimeout(timer);
  }

  let body: Partial<Envelope<T>> & { code?: string; errors?: Record<string, string[]> } = {};
  try {
    body = await response.json();
  } catch {
    // An HTML error page from a proxy, or an empty body.
  }

  if (response.status === 401 && options.auth !== false) {
    runtime.unauthorized();
  }
  if (!response.ok || body.success === false) {
    throw new ApiError(body.message || t('Request failed ({status}).', { status: response.status }), response.status, body.code ?? null, body.errors ?? null, response.status === 502 || response.status === 503 || response.status === 504);
  }

  return body as Envelope<T>;
}

export const api = {
  get: <T>(path: string, query?: Query, options?: RequestOptions) => request<T>(path, { ...options, method: 'GET', query }),
  post: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>(path, { ...options, method: 'POST', body: body ?? {} }),
  put: <T>(path: string, body?: unknown, options?: RequestOptions) => request<T>(path, { ...options, method: 'PUT', body: body ?? {} }),
  delete: <T>(path: string, options?: RequestOptions) => request<T>(path, { ...options, method: 'DELETE' }),
};
