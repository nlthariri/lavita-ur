import { randomUUID } from "node:crypto";

import { NextResponse } from "next/server";

import { getSession } from "@/lib/auth/session";
import { extractRequestMeta, recordAuditEvent } from "@/lib/audit/service";
import { db } from "@/lib/db";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonError, jsonUnexpectedError } from "@/lib/security/error-handler";

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
  try {
    assertSameOrigin(request, { requireToken: true });

    const session = await getSession();
    if (!session) {
      return jsonError(request, 401, "Niet ingelogd.");
    }

    if (session.role !== "OWNER") {
      return jsonError(request, 403, "Alleen eigenaar mag verwijderen.");
    }

    if (!session.mfaVerified) {
      return jsonError(request, 403, "MFA-verificatie is vereist.");
    }

    const { id } = await context.params;
    if (id === session.userId) {
      return jsonError(request, 400, "Je kunt je eigen eigenaaraccount niet verwijderen.");
    }

    const user = await db.user.findFirst({
      where: {
        id,
        organizationId: session.organizationId,
      },
      select: {
        id: true,
        role: true,
        isActive: true,
        fullName: true,
        email: true,
        teamId: true,
        hourlyRateCents: true,
      },
    });

    if (!user) {
      return jsonError(request, 404, "Gebruiker niet gevonden.");
    }

    if (user.role === "OWNER") {
      return jsonError(request, 400, "Eigenaaraccount kan niet via deze route verwijderd worden.");
    }

    const deletedTag = randomUUID().slice(0, 8);

    await db.$transaction(async (tx) => {
      await tx.user.update({
        where: { id: user.id },
        data: {
          isActive: false,
          fullName: `Verwijderde medewerker ${deletedTag}`,
          email: `deleted+${user.id}@anonymized.local`,
          passwordHash: null,
          mfaEnabled: false,
          mfaSecret: null,
          teamId: null,
          hourlyRateCents: null,
          employmentEnd: new Date(),
          sessionVersion: { increment: 1 },
        },
      });

      await recordAuditEvent(tx, {
        organizationId: session.organizationId,
        actorId: session.userId,
        action: "user.soft_deleted",
        targetType: "User",
        targetId: user.id,
        beforeData: {
          isActive: user.isActive,
          fullName: user.fullName,
          email: user.email,
          teamId: user.teamId,
          hourlyRateCents: user.hourlyRateCents,
          role: user.role,
        },
        afterData: {
          isActive: false,
          fullName: `Verwijderde medewerker ${deletedTag}`,
          email: `deleted+${user.id}@anonymized.local`,
          teamId: null,
          hourlyRateCents: null,
        },
        requestMeta: extractRequestMeta(request),
      });
    });

    return NextResponse.json({ ok: true, userId: user.id });
  } catch (error) {
    return jsonUnexpectedError(request, error, "Verwijdering mislukt.");
  }
}
