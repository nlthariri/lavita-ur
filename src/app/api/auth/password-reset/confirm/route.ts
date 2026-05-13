import { NextResponse } from "next/server";
import { z } from "zod";

import { clearSession } from "@/lib/auth/session";
import { resetPasswordWithToken } from "@/lib/auth/password-reset";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonError, jsonUnexpectedError } from "@/lib/security/error-handler";
import { getClientIp } from "@/lib/security/request";
import { checkRateLimitDetailed } from "@/lib/security/rate-limit";

const confirmSchema = z.object({
  token: z.string().min(1, "Resettoken ontbreekt."),
  password: z.string().min(12, "Wachtwoord moet minimaal 12 tekens zijn."),
});

export async function POST(request: Request) {
  try {
    assertSameOrigin(request, { requireToken: true });

    const ip = await getClientIp();
    if (!ip) {
      return jsonError(request, 503, "IP-adres kon niet worden bepaald.");
    }

    const limit = await checkRateLimitDetailed(`password-reset-confirm:${ip}`, 12, 60_000);
    if (limit.degraded) {
      return jsonError(request, 503, "Reset bevestigen tijdelijk niet beschikbaar. Probeer later opnieuw.");
    }

    if (!limit.allowed) {
      return jsonError(request, 429, "Te veel pogingen. Probeer later opnieuw.");
    }

    const payload = await request.json();
    const input = confirmSchema.parse(payload);

    await resetPasswordWithToken(input.token, input.password);
    await clearSession();

    return NextResponse.json({ ok: true });
  } catch (error) {
    return jsonUnexpectedError(request, error, "Wachtwoord resetten mislukt.");
  }
}
