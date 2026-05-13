import { NextResponse } from "next/server";

import { getSession } from "@/lib/auth/session";
import { db } from "@/lib/db";
import { jsonUnexpectedError } from "@/lib/security/error-handler";
import { getClientIp } from "@/lib/security/request";
import { checkRateLimit } from "@/lib/security/rate-limit";

export async function GET(_request: Request, context: { params: Promise<{ id: string }> }) {
  try {
    const session = await getSession();
    if (!session) {
      return NextResponse.json({ error: "Niet ingelogd." }, { status: 401 });
    }

    const { id } = await context.params;

    const isSelf = session.userId === id;
    const isOwner = session.role === "OWNER";
    if (!isSelf && !isOwner) {
      return NextResponse.json({ error: "Onvoldoende rechten." }, { status: 403 });
    }

    const ip = await getClientIp();
    if (!ip) {
      return NextResponse.json({ error: "IP-adres kon niet worden bepaald." }, { status: 503 });
    }

    const allowed = await checkRateLimit(`gdpr-data-export:${session.userId}:${ip}`, 5, 60 * 60 * 1000);
    if (!allowed) {
      return NextResponse.json({ error: "Te veel exportverzoeken. Probeer later opnieuw." }, { status: 429 });
    }

    const user = await db.user.findFirst({
      where: {
        id,
        organizationId: session.organizationId,
      },
      select: {
        id: true,
        organizationId: true,
        teamId: true,
        email: true,
        fullName: true,
        role: true,
        isActive: true,
        employmentStart: true,
        employmentEnd: true,
        hourlyRateCents: true,
        createdAt: true,
        updatedAt: true,
      },
    });

    if (!user) {
      return NextResponse.json({ error: "Gebruiker niet gevonden." }, { status: 404 });
    }

    const [workEntries, objections, atwViolations, emailEvents] = await Promise.all([
      db.workEntry.findMany({
        where: {
          organizationId: session.organizationId,
          employeeId: id,
        },
        orderBy: { entryDate: "asc" },
      }),
      db.objection.findMany({
        where: {
          organizationId: session.organizationId,
          OR: [{ submittedById: id }, { reviewedById: id }, { workEntry: { employeeId: id } }],
        },
        orderBy: { submittedAt: "asc" },
      }),
      db.atwViolation.findMany({
        where: {
          organizationId: session.organizationId,
          userId: id,
        },
        orderBy: { createdAt: "asc" },
      }),
      db.emailEvent.findMany({
        where: {
          organizationId: session.organizationId,
          userId: id,
        },
        orderBy: { createdAt: "asc" },
      }),
    ]);

    const payload = {
      generatedAt: new Date().toISOString(),
      user,
      workEntries,
      objections,
      atwViolations,
      emailEvents,
    };

    return new NextResponse(JSON.stringify(payload, null, 2), {
      status: 200,
      headers: {
        "Content-Type": "application/json; charset=utf-8",
        "Content-Disposition": `attachment; filename=\"gegevens-export-${id}.json\"`,
      },
    });
  } catch (error) {
    return jsonUnexpectedError(_request, error, "Gegevensexport mislukt.");
  }
}
