import type { NextRequest } from "next/server";
import { NextResponse } from "next/server";

import { createCsrfToken, CSRF_COOKIE_NAME } from "@/lib/security/csrf";

const PUBLIC_PATHS = [
  "/login",
  "/wachtwoord-vergeten",
  "/wachtwoord-reset",
  "/api/auth/login",
  "/api/health",
  "/api/email/unsubscribe",
  "/",
];

function hasSessionCookie(request: NextRequest): boolean {
  return Boolean(request.cookies.get("lavita_session")?.value);
}

function withSecurityHeaders(response: NextResponse): NextResponse {
  response.headers.set("Strict-Transport-Security", "max-age=31536000; includeSubDomains; preload");
  response.headers.set("X-Content-Type-Options", "nosniff");
  response.headers.set("X-Frame-Options", "DENY");
  response.headers.set("Referrer-Policy", "strict-origin-when-cross-origin");
  response.headers.set("Content-Security-Policy", "default-src 'self'; frame-ancestors 'none'; base-uri 'self'");
  return response;
}

function withRequestId(request: NextRequest, response: NextResponse): NextResponse {
  const requestId = request.headers.get("x-request-id") ?? crypto.randomUUID();
  response.headers.set("x-request-id", requestId);

  if (!request.cookies.get(CSRF_COOKIE_NAME)?.value) {
    response.cookies.set(CSRF_COOKIE_NAME, createCsrfToken(), {
      httpOnly: false,
      secure: process.env.NODE_ENV === "production",
      sameSite: "strict",
      path: "/",
      maxAge: 60 * 60 * 24,
    });
  }

  return response;
}

export function middleware(request: NextRequest) {
  const { pathname } = request.nextUrl;

  const forwardedProto = request.headers.get("x-forwarded-proto");
  if (process.env.NODE_ENV === "production" && forwardedProto && forwardedProto !== "https") {
    const httpsUrl = request.nextUrl.clone();
    httpsUrl.protocol = "https";
    return withRequestId(request, withSecurityHeaders(NextResponse.redirect(httpsUrl, 308)));
  }

  if (pathname.startsWith("/_next") || pathname.startsWith("/favicon")) {
    return withRequestId(request, withSecurityHeaders(NextResponse.next()));
  }

  const isPublic = PUBLIC_PATHS.some((path) => pathname === path || pathname.startsWith(`${path}/`));
  if (isPublic) {
    return withRequestId(request, withSecurityHeaders(NextResponse.next()));
  }

  if (!hasSessionCookie(request)) {
    if (pathname.startsWith("/api/")) {
      return withRequestId(request, withSecurityHeaders(NextResponse.json({ error: "Niet ingelogd." }, { status: 401 })));
    }

    const loginUrl = new URL("/login", request.url);
    return withRequestId(request, withSecurityHeaders(NextResponse.redirect(loginUrl)));
  }

  return withRequestId(request, withSecurityHeaders(NextResponse.next()));
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|.*\\.png$).*)"],
};
