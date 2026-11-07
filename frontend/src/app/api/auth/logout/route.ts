import { NextResponse, type NextRequest } from "next/server";
import { apiUrl, clearSession, forbiddenCsrf, forwardHeaders, isTrustedWrite } from "@/lib/server/backend";

export async function POST(req: NextRequest) {
  if (!isTrustedWrite(req)) return forbiddenCsrf();

  try {
    await fetch(apiUrl("auth/logout"), { method: "POST", headers: forwardHeaders(req), cache: "no-store" });
  } catch {
    // Even if the API is unreachable the local session is cleared.
  }

  const res = NextResponse.json({ success: true, message: "You have been logged out." });
  clearSession(res);
  return res;
}
