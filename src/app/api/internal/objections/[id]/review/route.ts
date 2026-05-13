import { NextResponse } from "next/server";
import { z } from "zod";

import { getSession } from "@/lib/auth/session";
import { extractRequestMeta } from "@/lib/audit/service";
import { db } from "@/lib/db";
import { reviewObjection } from "@/lib/objections/service";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonError, jsonUnexpectedError } from "@/lib/security/error-handler";

const reviewSchema = z.object({
  decision: z.enum(["APPROVED", "REJECTED"]),
  managerResponse: z.string().trim().min(5, "Toelichting moet minimaal 5 tekens bevatten.").max(2000),
  correction: z
    .object({
      startTime: z.string().regex(/^([01]\d|2[0-3]):([0-5]\d)$/).optional(),
      endTime: z.string().regex(/^([01]\d|2[0-3]):([0-5]\d)$/).optional(),
      pauseMinutes: z.number().int().min(0).max(120).optional(),
      note: z.string().trim().max(500).optional(),
    })
    .optional(),
});

export async function POST(request: Request, context: { params: Promise<{ id: string }> }) {
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

    const { id } = await context.params;
    const input = reviewSchema.parse(await request.json());

    await reviewObjection(
      db,
      {
        userId: session.userId,
        organizationId: session.organizationId,
        role: session.role,
      },
      id,
      input,
      extractRequestMeta(request),
    );

    return NextResponse.json({ ok: true });
  } catch (error) {
    return jsonUnexpectedError(request, error, "Beoordeling mislukt.");
  }
}
