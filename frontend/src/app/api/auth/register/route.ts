import { NextResponse, type NextRequest } from "next/server";
import { apiUrl, forbiddenCsrf, forwardHeaders, isTrustedWrite, setSession, unreachable } from "@/lib/server/backend";

export async function POST(req: NextRequest) {
  if (!isTrustedWrite(req)) return forbiddenCsrf();

  let upstream: Response;
  try {
    upstream = await fetch(apiUrl("shop/register"), {
      method: "POST",
      headers: forwardHeaders(req, { "Content-Type": "application/json" }),
      body: await req.text(),
      cache: "no-store",
    });
  } catch {
    return unreachable();
  }

  const body = await upstream.json().catch(() => ({ success: false, message: "Unexpected response from the server." }));
  if (!upstream.ok || !body?.data?.token) {
    return NextResponse.json(body, { status: upstream.status || 500 });
  }

  const res = NextResponse.json({ success: true, message: body.message, data: { user: body.data.user } }, { status: 201 });
  const thirtyDays = new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString();
  setSession(res, body.data.token, "customer", thirtyDays);
  return res;
}
