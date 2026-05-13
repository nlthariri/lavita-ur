import { NextResponse } from "next/server";

import { getSession } from "@/lib/auth/session";
import { db } from "@/lib/db";
import { getClientIp } from "@/lib/security/request";
import { checkRateLimit } from "@/lib/security/rate-limit";

export async function GET() {
  const session = await getSession();
  if (!session) {
    return NextResponse.json({ error: "Niet ingelogd." }, { status: 401 });
  }

  const ip = await getClientIp();
  if (!ip) {
    return NextResponse.json({ error: "IP-adres kon niet worden bepaald." }, { status: 503 });
  }

  const allowed = await checkRateLimit(`employees:${ip}`, 60, 60_000);
  if (!allowed) {
    return NextResponse.json({ error: "Te veel verzoeken, probeer later opnieuw." }, { status: 429 });
  }

  const manager =
    session.role === "MANAGER"
      ? await db.user.findUnique({
          where: { id: session.userId },
          select: { teamId: true },
        })
      : null;

  const where = {
    organizationId: session.organizationId,
    role: "EMPLOYEE" as const,
    ...(session.role === "MANAGER" ? { teamId: manager?.teamId ?? "__no_team__" } : {}),
  };

  const users = await db.user.findMany({
    where,
    select: {
      id: true,
      fullName: true,
      email: true,
    },
    orderBy: { fullName: "asc" },
  });

  return NextResponse.json({ users });
}
