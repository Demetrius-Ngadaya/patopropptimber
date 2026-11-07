import { NextResponse, type NextRequest } from "next/server";
import { apiUrl, forbiddenCsrf, forwardHeaders, isTrustedWrite, setSession, unreachable } from "@/lib/server/backend";

export async function POST(req: NextRequest) {
  if (!isTrustedWrite(req)) return forbiddenCsrf();

  let upstream: Response;
  try {
    upstream = await fetch(apiUrl("auth/login"), {
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

  const { token, expires_at, user } = body.data;
  const res = NextResponse.json({ success: true, message: body.message, data: { user } });
  setSession(res, token, user.type, expires_at);
  return res;
}
