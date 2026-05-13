import { checkRateLimitDetailed } from "@/lib/security/rate-limit";

beforeAll(() => {
  process.env.DATABASE_URL = process.env.DATABASE_URL ?? "mysql://user:pass@localhost:3306/lavita";
  process.env.SMTP_HOST = process.env.SMTP_HOST ?? "smtp.example.com";
  process.env.SMTP_PORT = process.env.SMTP_PORT ?? "465";
  process.env.SMTP_USER = process.env.SMTP_USER ?? "user";
  process.env.SMTP_PASSWORD = process.env.SMTP_PASSWORD ?? "pass";
  process.env.SMTP_FROM = process.env.SMTP_FROM ?? "noreply@example.com";
  process.env.APP_BASE_URL = process.env.APP_BASE_URL ?? "https://uren.example.com";
  process.env.AUTH_SESSION_SECRET = process.env.AUTH_SESSION_SECRET ?? "12345678901234567890123456789012";
  delete process.env.REDIS_URL;
});

describe("checkRateLimitDetailed", () => {
  it("rapporteert degrade-mode zonder REDIS_URL", async () => {
    const result = await checkRateLimitDetailed("test-key", 10, 60_000);

    expect(result.degraded).toBe(true);
    expect(result.fallbackUsed).toBe(true);
    expect(result.allowed).toBe(true);
  });

  it("blokkeert boven maxRequests in memory fallback", async () => {
    const key = `limited-key-${Date.now()}`;

    const first = await checkRateLimitDetailed(key, 2, 60_000);
    const second = await checkRateLimitDetailed(key, 2, 60_000);
    const third = await checkRateLimitDetailed(key, 2, 60_000);

    expect(first.allowed).toBe(true);
    expect(second.allowed).toBe(true);
    expect(third.allowed).toBe(false);
    expect(third.degraded).toBe(true);
  });
});
