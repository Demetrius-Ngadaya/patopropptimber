import "server-only";
import type { NextRequest } from "next/server";
import { NextResponse } from "next/server";

/**
 * Server-side helpers for talking to the Laravel API.
 * The API token lives only in an httpOnly cookie and is added here, so
 * browser JavaScript can never read or leak it.
 */
export const TOKEN_COOKIE = "mz_token";
export const TYPE_COOKIE = "mz_type"; // "staff" | "customer" (used by proxy.ts for redirects)

export function apiUrl(path: string): string {
  const base = (process.env.API_URL ?? "http://127.0.0.1:8000/api/v1").replace(/\/+$/, "");
  return `${base}/${path.replace(/^\/+/, "")}`;
}

/** The real browser IP, so the API audit logs record the user, not this server. */
export function clientIp(req: NextRequest): string {
  const forwarded = req.headers.get("x-forwarded-for");
  if (forwarded) return forwarded.split(",")[0].trim();
  return req.headers.get("x-real-ip") ?? "127.0.0.1";
}

export function forwardHeaders(req: NextRequest, extra: Record<string, string> = {}): Headers {
  const headers = new Headers();
  headers.set("Accept", "application/json");
  headers.set("X-Forwarded-For", clientIp(req));
  headers.set("User-Agent", req.headers.get("user-agent") ?? "PatoPro web");
  // x-visitor-id: the anonymous visitor id (visitor counter, chat limits);
  // x-vercel-ip-*: where the visitor is, as Vercel reports it (no extra service needed).
  for (const name of ["content-type", "x-branch-id", "accept-language", "x-locale", "x-visitor-id", "x-vercel-ip-country", "x-vercel-ip-country-region", "x-vercel-ip-city", "x-vercel-ip-latitude", "x-vercel-ip-longitude", "x-vercel-ip-timezone"]) {
    const value = req.headers.get(name);
    if (value) headers.set(name, value);
  }
  // API messages come back in the user's language.
  const locale = req.cookies.get("mz_locale")?.value;
  if (!headers.has("x-locale") && (locale === "en" || locale === "sw")) headers.set("X-Locale", locale);
  const token = req.cookies.get(TOKEN_COOKIE)?.value;
  if (token) headers.set("Authorization", `Bearer ${token}`);
  for (const [k, v] of Object.entries(extra)) headers.set(k, v);
  return headers;
}

/**
 * Blocks cross-site form posts (CSRF): write requests must come from our own
 * pages, which always send X-Requested-With and a same-origin Origin header.
 */
export function isTrustedWrite(req: NextRequest): boolean {
  if (["GET", "HEAD", "OPTIONS"].includes(req.method)) return true;
  if (req.headers.get("x-requested-with") !== "XMLHttpRequest") return false;
  const origin = req.headers.get("origin");
  if (!origin) return true; // same-origin fetches in some browsers omit Origin
  const allowed = new Set([req.nextUrl.origin, process.env.APP_URL?.replace(/\/+$/, "")].filter(Boolean));
  return allowed.has(origin);
}

export function forbiddenCsrf() {
  return NextResponse.json({ success: false, message: "Request blocked for security reasons. Reload the page and try again." }, { status: 403 });
}

const secure = () => process.env.COOKIE_SECURE !== "false";

export function setSession(res: NextResponse, token: string, type: string, expiresAt?: string | null) {
  const expires = expiresAt ? new Date(expiresAt) : undefined;
  res.cookies.set(TOKEN_COOKIE, token, { httpOnly: true, secure: secure(), sameSite: "lax", path: "/", expires });
  res.cookies.set(TYPE_COOKIE, type, { httpOnly: true, secure: secure(), sameSite: "lax", path: "/", expires });
}

export function clearSession(res: NextResponse) {
  res.cookies.set(TOKEN_COOKIE, "", { httpOnly: true, secure: secure(), sameSite: "lax", path: "/", maxAge: 0 });
  res.cookies.set(TYPE_COOKIE, "", { httpOnly: true, secure: secure(), sameSite: "lax", path: "/", maxAge: 0 });
}

export function unreachable() {
  return NextResponse.json(
    { success: false, code: "API_UNREACHABLE", message: "The server could not be reached. Please try again in a moment." },
    { status: 503 },
  );
}
