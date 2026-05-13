import { NextResponse } from "next/server";
import { z } from "zod";

import { getSession } from "@/lib/auth/session";
import { extractRequestMeta } from "@/lib/audit/service";
import { db } from "@/lib/db";
import { listObjections, submitObjection } from "@/lib/objections/service";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonError, jsonUnexpectedError } from "@/lib/security/error-handler";

const submitSchema = z.object({
  workEntryId: z.string().cuid(),
  motivation: z.string().trim().min(10, "Motivatie moet minimaal 10 tekens bevatten.").max(2000),
});

export async function GET() {
  const session = await getSession();
  if (!session) {
    return NextResponse.json({ error: "Niet ingelogd." }, { status: 401 });
  }

  const objections = await listObjections(db, {
    userId: session.userId,
    organizationId: session.organizationId,
    role: session.role,
  });

  return NextResponse.json({ objections });
}

export async function POST(request: Request) {
  try {
    assertSameOrigin(request, { requireToken: true });

    const session = await getSession();
    if (!session) {
      return jsonError(request, 401, "Niet ingelogd.");
    }

    const input = submitSchema.parse(await request.json());
    await submitObjection(
      db,
      {
        userId: session.userId,
        organizationId: session.organizationId,
        role: session.role,
      },
      input.workEntryId,
      input.motivation,
      extractRequestMeta(request),
    );

    return NextResponse.json({ ok: true }, { status: 201 });
  } catch (error) {
    return jsonUnexpectedError(request, error, "Bezwaar indienen mislukt.");
  }
}
