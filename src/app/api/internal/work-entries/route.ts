import { NextResponse } from "next/server";
import { z } from "zod";

import { getSession } from "@/lib/auth/session";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonError, jsonUnexpectedError } from "@/lib/security/error-handler";
import { getClientIp } from "@/lib/security/request";
import { checkRateLimit } from "@/lib/security/rate-limit";
import { db } from "@/lib/db";
import { createFinalizedWorkEntry } from "@/lib/work-entries/service";

const listQuerySchema = z.object({
  weekStart: z.string().datetime().optional(),
  weekEnd: z.string().datetime().optional(),
});

export async function GET(request: Request) {
  const session = await getSession();
  if (!session) {
    return NextResponse.json({ error: "Niet ingelogd." }, { status: 401 });
  }

  const url = new URL(request.url);
  const query = listQuerySchema.parse({
    weekStart: url.searchParams.get("weekStart") ?? undefined,
    weekEnd: url.searchParams.get("weekEnd") ?? undefined,
  });

  const where =
    session.role === "EMPLOYEE"
      ? {
          organizationId: session.organizationId,
          employeeId: session.userId,
        }
      : session.role === "MANAGER"
        ? {
            organizationId: session.organizationId,
            teamId: (
              await db.user.findUniqueOrThrow({
                where: { id: session.userId },
                select: { teamId: true },
              })
            ).teamId ?? "__no_team__",
          }
        : {
            organizationId: session.organizationId,
          };

  const entries = await db.workEntry.findMany({
    where: {
      ...where,
      ...(query.weekStart || query.weekEnd
        ? {
            entryDate: {
              ...(query.weekStart ? { gte: new Date(query.weekStart) } : {}),
              ...(query.weekEnd ? { lte: new Date(query.weekEnd) } : {}),
            },
          }
        : {}),
    },
    include: {
      employee: { select: { id: true, fullName: true } },
      objections: { select: { id: true, status: true, motivation: true } },
    },
    orderBy: [{ entryDate: "desc" }, { startAt: "desc" }],
  });

  return NextResponse.json({ entries });
}

export async function POST(request: Request) {
  try {
    assertSameOrigin(request, { requireToken: true });

    const session = await getSession();
    if (!session) {
      return jsonError(request, 401, "Niet ingelogd.");
    }

    if (session.role !== "OWNER" && session.role !== "MANAGER") {
      return jsonError(request, 403, "Onvoldoende rechten.");
    }

    if (!session.mfaVerified) {
      return jsonError(request, 403, "MFA-verificatie is vereist.");
    }

    const clientIp = await getClientIp();
    if (!clientIp) {
      return jsonError(request, 503, "IP-adres kon niet worden bepaald.");
    }

    const allowed = await checkRateLimit(`work-entry:${clientIp}`, 100, 60_000);
    if (!allowed) {
      return jsonError(request, 429, "Te veel verzoeken, probeer later opnieuw.");
    }

    const payload = await request.json();
    const result = await createFinalizedWorkEntry(payload, session.userId);

    return NextResponse.json(result, { status: 201 });
  } catch (error) {
    return jsonUnexpectedError(request, error, "Onbekende fout tijdens registreren.");
  }
}
