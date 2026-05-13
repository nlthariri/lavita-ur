import { NextResponse } from "next/server";
import { UserRole } from "@prisma/client";
import { z } from "zod";

import { getSession } from "@/lib/auth/session";
import { db } from "@/lib/db";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonError, jsonUnexpectedError } from "@/lib/security/error-handler";
import { createUserForSession, listUsersForSession } from "@/lib/users/service";

const createSchema = z.object({
  fullName: z.string().trim().min(2, "Naam is verplicht."),
  email: z.string().trim().email("Vul een geldig e-mailadres in."),
  role: z.nativeEnum(UserRole),
  teamId: z.string().cuid().optional(),
});

export async function GET(request: Request) {
  try {
    const session = await getSession();
    if (!session) {
      return NextResponse.json({ error: "Niet ingelogd." }, { status: 401 });
    }

    const users = await listUsersForSession(db, {
      userId: session.userId,
      organizationId: session.organizationId,
      role: session.role,
    });

    const teams = await db.team.findMany({
      where: { organizationId: session.organizationId },
      select: { id: true, name: true },
      orderBy: { name: "asc" },
    });

    return NextResponse.json({ users, teams });
  } catch (error) {
    return jsonUnexpectedError(request, error, "Gebruikers laden mislukt.");
  }
}

export async function POST(request: Request) {
  try {
    assertSameOrigin(request, { requireToken: true });

    const session = await getSession();
    if (!session) {
      return jsonError(request, 401, "Niet ingelogd.");
    }

    if (!session.mfaVerified && (session.role === "OWNER" || session.role === "MANAGER")) {
      return jsonError(request, 403, "MFA-verificatie is vereist.");
    }

    const payload = createSchema.parse(await request.json());
    const result = await createUserForSession(
      db,
      {
        userId: session.userId,
        organizationId: session.organizationId,
        role: session.role,
      },
      payload,
    );

    return NextResponse.json(result, { status: 201 });
  } catch (error) {
    return jsonUnexpectedError(request, error, "Account aanmaken mislukt.");
  }
}
