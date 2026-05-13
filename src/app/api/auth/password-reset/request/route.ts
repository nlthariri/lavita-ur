import { NextResponse } from "next/server";
import { z } from "zod";

import { createPasswordResetToken } from "@/lib/auth/password-reset";
import { db } from "@/lib/db";
import { queueAndSendEmail } from "@/lib/email/service";
import { getEnv } from "@/lib/env";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonError, jsonUnexpectedError } from "@/lib/security/error-handler";
import { getClientIp } from "@/lib/security/request";
import { checkRateLimitDetailed } from "@/lib/security/rate-limit";

const requestSchema = z.object({
  email: z.string().email("Vul een geldig e-mailadres in."),
});

const genericSuccessMessage = "Als dit e-mailadres bestaat, ontvang je een resetlink.";

export async function POST(request: Request) {
  try {
    assertSameOrigin(request, { requireToken: true });

    const ip = await getClientIp();
    if (!ip) {
      return jsonError(request, 503, "IP-adres kon niet worden bepaald.");
    }

    const ipLimit = await checkRateLimitDetailed(`password-reset-request:${ip}`, 8, 60_000);
    if (ipLimit.degraded) {
      return jsonError(request, 503, "Reset aanvragen tijdelijk niet beschikbaar. Probeer later opnieuw.");
    }

    if (!ipLimit.allowed) {
      return jsonError(request, 429, "Te veel resetaanvragen. Probeer later opnieuw.");
    }

    const payload = await request.json();
    const input = requestSchema.parse(payload);
    const email = input.email.trim().toLowerCase();

    const emailLimit = await checkRateLimitDetailed(`password-reset-request-email:${email}`, 3, 60 * 60 * 1000);
    if (!emailLimit.allowed) {
      return NextResponse.json({ ok: true, message: genericSuccessMessage });
    }

    const user = await db.user.findUnique({
      where: { email },
      select: {
        id: true,
        fullName: true,
        email: true,
        isActive: true,
        organizationId: true,
        passwordHash: true,
      },
    });

    if (user && user.isActive && user.passwordHash) {
      const token = await createPasswordResetToken(user.id);
      const env = getEnv();
      const link = `${env.APP_BASE_URL}/wachtwoord-reset?token=${encodeURIComponent(token)}`;

      await queueAndSendEmail({
        organizationId: user.organizationId,
        userId: user.id,
        recipient: user.email,
        type: "PASSWORD_RESET",
        variables: {
          naam: user.fullName,
          link,
        },
      });
    }

    return NextResponse.json({ ok: true, message: genericSuccessMessage });
  } catch (error) {
    return jsonUnexpectedError(request, error, "Resetaanvraag mislukt.");
  }
}
