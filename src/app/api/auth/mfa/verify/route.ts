import { NextResponse } from "next/server";
import { z } from "zod";
import { verify } from "otplib";

import { getSession, setSession } from "@/lib/auth/session";
import { db } from "@/lib/db";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonError, jsonUnexpectedError } from "@/lib/security/error-handler";

const verifySchema = z.object({
  code: z.string().trim().regex(/^\d{6}$/, "MFA-code moet uit 6 cijfers bestaan."),
});

export async function POST(request: Request) {
  try {
    assertSameOrigin(request, { requireToken: true });

    const session = await getSession();
    if (!session) {
      return jsonError(request, 401, "Niet ingelogd.");
    }

    const { code } = verifySchema.parse(await request.json());

    const user = await db.user.findUniqueOrThrow({
      where: { id: session.userId },
      select: { id: true, organizationId: true, role: true, mfaSecret: true, sessionVersion: true },
    });

    if (!user.mfaSecret) {
      return jsonError(request, 400, "MFA-setup ontbreekt.");
    }

    const isValid = verify({ token: code, secret: user.mfaSecret });
    if (!isValid) {
      return jsonError(request, 400, "Ongeldige MFA-code.");
    }

    await db.user.update({
      where: { id: user.id },
      data: {
        mfaEnabled: true,
      },
    });

    await setSession({
      userId: user.id,
      organizationId: user.organizationId,
      role: user.role,
      mfaVerified: true,
      sessionVersion: user.sessionVersion,
    });

    return NextResponse.json({ ok: true });
  } catch (error) {
    return jsonUnexpectedError(request, error, "MFA verificatie mislukt.");
  }
}
