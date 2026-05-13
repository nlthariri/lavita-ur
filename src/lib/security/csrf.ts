import { getEnv } from "@/lib/env";

export const CSRF_COOKIE_NAME = "lavita_csrf";

type CsrfOptions = {
  requireToken?: boolean;
};

function extractOrigin(value: string | null): string | null {
  if (!value) {
    return null;
  }

  try {
    return new URL(value).origin;
  } catch {
    return null;
  }
}

export function assertSameOrigin(request: Request, options: CsrfOptions = {}): void {
  const env = getEnv();
  const expectedOrigin = extractOrigin(env.APP_BASE_URL);
  const origin = extractOrigin(request.headers.get("origin"));
  const referer = extractOrigin(request.headers.get("referer"));

  if (!expectedOrigin) {
    throw new Error("APP_BASE_URL is ongeldig geconfigureerd.");
  }

  if (origin !== expectedOrigin && referer !== expectedOrigin) {
    throw new Error("CSRF-controle mislukt.");
  }

  if (!options.requireToken) {
    return;
  }

  const token = request.headers.get("x-csrf-token");
  const cookieHeader = request.headers.get("cookie") ?? "";
  const cookieToken = cookieHeader
    .split(";")
    .map((segment) => segment.trim())
    .find((segment) => segment.startsWith(`${CSRF_COOKIE_NAME}=`))
    ?.slice(`${CSRF_COOKIE_NAME}=`.length);

  if (!token || !cookieToken || token !== cookieToken) {
    throw new Error("CSRF-token ontbreekt of is ongeldig.");
  }
}

export function createCsrfToken(): string {
  const bytes = new Uint8Array(32);
  crypto.getRandomValues(bytes);

  let binary = "";
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }

  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
}
