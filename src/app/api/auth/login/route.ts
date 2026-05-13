import { NextResponse } from "next/server";
import { z } from "zod";

import { loginWithPassword } from "@/lib/auth/service";
import { setSession } from "@/lib/auth/session";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonError, jsonUnexpectedError } from "@/lib/security/error-handler";
import { getClientIp } from "@/lib/security/request";
import { checkRateLimitDetailed } from "@/lib/security/rate-limit";

const loginSchema = z.object({
  email: z.string().email("Vul een geldig e-mailadres in."),
  password: z.string().min(12, "Wachtwoord moet minimaal 12 tekens zijn."),
  totpCode: z
    .string()
    .trim()
    .regex(/^\d{6}$/i, "MFA-code moet uit 6 cijfers bestaan.")
    .optional(),
});

export async function POST(request: Request) {
  try {
    assertSameOrigin(request, { requireToken: true });

    const ip = await getClientIp();
    if (!ip) {
      return jsonError(request, 503, "IP-adres kon niet worden bepaald.");
    }

    const limit = await checkRateLimitDetailed(`login:${ip}`, 12, 60_000);
    if (limit.degraded) {
      return jsonError(request, 503, "Inloggen tijdelijk niet beschikbaar. Probeer later opnieuw.");
    }

    if (!limit.allowed) {
      return jsonError(request, 429, "Te veel inlogpogingen. Probeer later opnieuw.");
    }

    const payload = await request.json();
    const input = loginSchema.parse(payload);
    const user = await loginWithPassword(input);

    await setSession({
      userId: user.id,
      organizationId: user.organizationId,
      role: user.role,
      mfaVerified: user.mfaVerified,
      sessionVersion: user.sessionVersion,
    });

    return NextResponse.json({ ok: true });
  } catch (error) {
    return jsonUnexpectedError(request, error, "Inloggen mislukt.");
  }
}
