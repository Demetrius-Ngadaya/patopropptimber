/**
 * Browser-side API client. All calls go through the same-origin gateway
 * (/api/backend/...) which attaches the httpOnly session cookie.
 */

import { visitorId } from "./visitor";

export type ApiErrorBody = {
  success?: false;
  message?: string;
  code?: string;
  errors?: Record<string, string[]>;
};

export class ApiError extends Error {
  status: number;
  code?: string;
  errors: Record<string, string[]>;

  constructor(status: number, body: ApiErrorBody) {
    super(body.message || defaultMessage(status));
    this.status = status;
    this.code = body.code;
    this.errors = body.errors ?? {};
  }

  /** First validation message for a field, e.g. fieldError("phone"). */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

function defaultMessage(status: number): string {
  if (status === 0) return "No internet connection. Check your network and try again.";
  if (status === 401) return "Your session has ended. Please log in again.";
  if (status === 403) return "You do not have permission to perform this action.";
  if (status === 404) return "The requested record was not found.";
  if (status === 422) return "Please check the highlighted fields.";
  if (status === 429) return "Too many attempts. Wait a moment and try again.";
  return "Something went wrong. Please try again.";
}

export type Paginated<T> = {
  success: true;
  data: T[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  [extra: string]: unknown;
};

export type ApiResponse<T> = { success: true; message?: string; data: T; [extra: string]: unknown };

const BRANCH_KEY = "mz_branch";

export function getSelectedBranch(): string | null {
  if (typeof window === "undefined") return null;
  try {
    return window.localStorage.getItem(BRANCH_KEY);
  } catch {
    return null;
  }
}

export function setSelectedBranch(value: string | null) {
  try {
    if (value) window.localStorage.setItem(BRANCH_KEY, value);
    else window.localStorage.removeItem(BRANCH_KEY);
  } catch {
    /* private mode: branch falls back to the user's default */
  }
}

type RequestOptions = {
  method?: string;
  body?: unknown;
  query?: Record<string, string | number | boolean | null | undefined>;
  signal?: AbortSignal;
};

export function buildQuery(query?: RequestOptions["query"]): string {
  if (!query) return "";
  const params = new URLSearchParams();
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null || v === "") continue;
    params.set(k, String(v));
  }
  const s = params.toString();
  return s ? `?${s}` : "";
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = "GET", body, query, signal } = options;
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  const branch = getSelectedBranch();
  if (branch) headers["X-Branch-Id"] = branch;
  const visitor = visitorId();
  if (visitor) headers["X-Visitor-Id"] = visitor;

  let payload: BodyInit | undefined;
  if (body instanceof FormData) {
    payload = body;
  } else if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    payload = JSON.stringify(body);
  }

  let res: Response;
  try {
    res = await fetch(`/api/backend/${path.replace(/^\/+/, "")}${buildQuery(query)}`, {
      method,
      headers,
      body: payload,
      signal,
      credentials: "same-origin",
      cache: "no-store",
    });
  } catch (e) {
    if ((e as Error).name === "AbortError") throw e;
    throw new ApiError(0, {});
  }

  if (res.status === 204) return undefined as T;

  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    const error = new ApiError(res.status, data);
    if (res.status === 401 && typeof window !== "undefined") {
      window.dispatchEvent(new CustomEvent("mz:session-ended", { detail: error.message }));
    }
    throw error;
  }
  return data as T;
}

export const api = {
  get: <T>(path: string, query?: RequestOptions["query"], signal?: AbortSignal) => request<T>(path, { query, signal }),
  post: <T>(path: string, body?: unknown) => request<T>(path, { method: "POST", body }),
  put: <T>(path: string, body?: unknown) => request<T>(path, { method: "PUT", body }),
  del: <T>(path: string, body?: unknown) => request<T>(path, { method: "DELETE", body }),
  /** Multipart update: PHP cannot read multipart PUT bodies, so send POST with _method. */
  putForm: <T>(path: string, form: FormData) => {
    form.append("_method", "PUT");
    return request<T>(path, { method: "POST", body: form });
  },
};

/** Download an export (Excel / PDF) through the gateway. */
export async function download(path: string, query?: RequestOptions["query"]): Promise<void> {
  const headers: Record<string, string> = { "X-Requested-With": "XMLHttpRequest" };
  const branch = getSelectedBranch();
  if (branch) headers["X-Branch-Id"] = branch;

  let res: Response;
  try {
    res = await fetch(`/api/backend/${path}${buildQuery(query)}`, { headers, credentials: "same-origin" });
  } catch {
    throw new ApiError(0, {});
  }
  if (!res.ok) {
    throw new ApiError(res.status, await res.json().catch(() => ({})));
  }
  const blob = await res.blob();
  const disposition = res.headers.get("content-disposition") ?? "";
  const name = /filename="?([^"]+)"?/.exec(disposition)?.[1] ?? "export";
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}

export async function authRequest<T>(path: "login" | "logout" | "register" | "two-factor", body?: unknown): Promise<T> {
  let res: Response;
  try {
    res = await fetch(`/api/auth/${path}`, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
      body: body ? JSON.stringify(body) : undefined,
      credentials: "same-origin",
    });
  } catch {
    throw new ApiError(0, {});
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new ApiError(res.status, data);
  return data as T;
}
