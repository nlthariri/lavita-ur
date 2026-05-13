import { assertSameOrigin } from "@/lib/security/csrf";

beforeAll(() => {
  process.env.DATABASE_URL = process.env.DATABASE_URL ?? "mysql://user:pass@localhost:3306/lavita";
  process.env.SMTP_HOST = process.env.SMTP_HOST ?? "smtp.example.com";
  process.env.SMTP_PORT = process.env.SMTP_PORT ?? "465";
  process.env.SMTP_USER = process.env.SMTP_USER ?? "user";
  process.env.SMTP_PASSWORD = process.env.SMTP_PASSWORD ?? "pass";
  process.env.SMTP_FROM = process.env.SMTP_FROM ?? "noreply@example.com";
  process.env.APP_BASE_URL = process.env.APP_BASE_URL ?? "https://uren.example.com";
  process.env.AUTH_SESSION_SECRET = process.env.AUTH_SESSION_SECRET ?? "12345678901234567890123456789012";
});

describe("assertSameOrigin", () => {
  it("accepteert geldige origin zonder tokenvereiste", () => {
    const request = new Request("https://uren.example.com/api/test", {
      method: "POST",
      headers: {
        origin: "https://uren.example.com",
      },
    });

    expect(() => assertSameOrigin(request)).not.toThrow();
  });

  it("accepteert geldige origin met matching csrf token", () => {
    const request = new Request("https://uren.example.com/api/test", {
      method: "POST",
      headers: {
        origin: "https://uren.example.com",
        cookie: "lavita_csrf=zelfde-token",
        "x-csrf-token": "zelfde-token",
      },
    });

    expect(() => assertSameOrigin(request, { requireToken: true })).not.toThrow();
  });

  it("weigert verzoek bij foutieve origin", () => {
    const request = new Request("https://uren.example.com/api/test", {
      method: "POST",
      headers: {
        origin: "https://evil.example.com",
      },
    });

    expect(() => assertSameOrigin(request)).toThrow("CSRF-controle mislukt.");
  });

  it("weigert token mismatch wanneer token verplicht is", () => {
    const request = new Request("https://uren.example.com/api/test", {
      method: "POST",
      headers: {
        origin: "https://uren.example.com",
        cookie: "lavita_csrf=cookie-token",
        "x-csrf-token": "header-token",
      },
    });

    expect(() => assertSameOrigin(request, { requireToken: true })).toThrow("CSRF-token ontbreekt of is ongeldig.");
  });
});
