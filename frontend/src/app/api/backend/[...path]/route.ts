import { NextResponse, type NextRequest } from "next/server";
import { apiUrl, clearSession, forbiddenCsrf, forwardHeaders, isTrustedWrite, unreachable } from "@/lib/server/backend";

/**
 * Same-origin gateway to the Laravel API: /api/backend/admin/products -> {API_URL}/admin/products
 * Adds the bearer token from the httpOnly cookie, the client IP and streams the
 * response back (JSON, Excel and PDF downloads alike).
 */
async function handle(req: NextRequest, ctx: { params: Promise<{ path: string[] }> }) {
  if (!isTrustedWrite(req)) return forbiddenCsrf();

  const { path } = await ctx.params;
  if (path.some((segment) => segment === ".." || segment.includes("\\"))) {
    return NextResponse.json({ success: false, message: "Invalid path." }, { status: 400 });
  }

  const target = apiUrl(path.map(encodeURIComponent).join("/")) + req.nextUrl.search;
  const hasBody = !["GET", "HEAD"].includes(req.method);

  let upstream: Response;
  try {
    upstream = await fetch(target, {
      method: req.method,
      headers: forwardHeaders(req),
      body: hasBody ? req.body : undefined,
      // @ts-expect-error -- required by Node fetch when streaming a request body
      duplex: hasBody ? "half" : undefined,
      cache: "no-store",
      redirect: "manual",
    });
  } catch {
    return unreachable();
  }

  const headers = new Headers();
  for (const name of ["content-type", "content-disposition", "x-session-timeout", "retry-after", "cache-control"]) {
    const value = upstream.headers.get(name);
    if (value) headers.set(name, value);
  }

  const res = new NextResponse(upstream.body, { status: upstream.status, headers });
  if (upstream.status === 401) clearSession(res);
  return res;
}

export const GET = handle;
export const POST = handle;
export const PUT = handle;
export const PATCH = handle;
export const DELETE = handle;
