const CSRF_COOKIE_NAME = "lavita_csrf";

export function readCsrfTokenFromCookie(): string {
  if (typeof document === "undefined") {
    return "";
  }

  const match = document.cookie
    .split(";")
    .map((segment) => segment.trim())
    .find((segment) => segment.startsWith(`${CSRF_COOKIE_NAME}=`));

  return match ? decodeURIComponent(match.slice(`${CSRF_COOKIE_NAME}=`.length)) : "";
}
