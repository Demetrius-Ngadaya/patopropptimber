import { NextResponse, type NextRequest } from "next/server";

/**
 * Route protection before any page renders:
 *  - /admin/*   staff only
 *  - /account/* and /checkout  customers only
 * The API enforces every permission again; this only avoids flashing private pages.
 */
export function proxy(req: NextRequest) {
  const { pathname, search } = req.nextUrl;
  const hasToken = Boolean(req.cookies.get("mz_token")?.value);
  const type = req.cookies.get("mz_type")?.value;

  const toLogin = (target: string) => {
    const url = req.nextUrl.clone();
    url.pathname = target;
    url.search = `?next=${encodeURIComponent(pathname + search)}`;
    return NextResponse.redirect(url);
  };

  if (pathname.startsWith("/admin") || pathname.startsWith("/receipt")) {
    if (!hasToken || type !== "staff") return toLogin("/login");
  }

  if (pathname.startsWith("/account") || pathname.startsWith("/checkout")) {
    if (!hasToken) return toLogin("/login");
    if (type === "staff") return NextResponse.redirect(new URL("/admin", req.url));
  }

  if (pathname === "/login" && hasToken) {
    return NextResponse.redirect(new URL(type === "staff" ? "/admin" : "/account", req.url));
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/admin/:path*", "/receipt/:path*", "/account/:path*", "/checkout", "/login"],
};
