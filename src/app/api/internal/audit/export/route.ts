import { NextResponse } from "next/server";

import { getSession } from "@/lib/auth/session";
import { db } from "@/lib/db";

function parseDate(value: string | null): Date | null {
  if (!value) {
    return null;
  }

  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date;
}

export async function GET(request: Request) {
  const session = await getSession();
  if (!session) {
    return NextResponse.json({ error: "Niet ingelogd." }, { status: 401 });
  }

  if (session.role !== "OWNER" && session.role !== "MANAGER") {
    return NextResponse.json({ error: "Onvoldoende rechten." }, { status: 403 });
  }

  const url = new URL(request.url);
  const targetType = url.searchParams.get("targetType");
  const targetId = url.searchParams.get("targetId");
  const actorId = url.searchParams.get("actorId");
  const startDate = parseDate(url.searchParams.get("startDate"));
  const endDate = parseDate(url.searchParams.get("endDate"));

  const events = await db.auditEvent.findMany({
    where: {
      organizationId: session.organizationId,
      targetType: targetType || undefined,
      targetId: targetId || undefined,
      actorId: actorId || undefined,
      createdAt:
        startDate || endDate
          ? {
              gte: startDate ?? undefined,
              lte: endDate ?? undefined,
            }
          : undefined,
    },
    orderBy: { createdAt: "desc" },
    take: 10_000,
  });

  return NextResponse.json({
    count: events.length,
    events,
  });
}
