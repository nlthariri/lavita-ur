import { NextResponse } from "next/server";
import { generateSecret, generateURI } from "otplib";

import { getSession } from "@/lib/auth/session";
import { db } from "@/lib/db";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonError, jsonUnexpectedError } from "@/lib/security/error-handler";

export async function POST(request: Request) {
  try {
    assertSameOrigin(request, { requireToken: true });

    const session = await getSession();
    if (!session) {
      return jsonError(request, 401, "Niet ingelogd.");
    }

    const user = await db.user.findUniqueOrThrow({
      where: { id: session.userId },
      select: { id: true, email: true },
    });

    const secret = generateSecret();
    const otpauthUri = generateURI({
      secret,
      label: user.email,
      issuer: "La Vita Urenregistratie",
      algorithm: "sha1",
      digits: 6,
      period: 30,
      strategy: "totp",
    });

    await db.user.update({
      where: { id: user.id },
      data: {
        mfaSecret: secret,
        mfaEnabled: false,
      },
    });

    return NextResponse.json({ secret, otpauthUri });
  } catch (error) {
    return jsonUnexpectedError(request, error, "MFA-setup mislukt.");
  }
}
