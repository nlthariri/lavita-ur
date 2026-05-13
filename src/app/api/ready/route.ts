import { NextResponse } from "next/server";

import { db } from "@/lib/db";
import { getEnv } from "@/lib/env";

export async function GET() {
  try {
    getEnv();
    await db.$queryRaw`SELECT 1`;
    await db.$queryRaw`SELECT migration_name FROM _prisma_migrations ORDER BY finished_at DESC LIMIT 1`;

    const [lastOutboxJob, lastRetentionJob] = await Promise.all([
      db.systemJobRun.findFirst({
        where: {
          jobName: "email.outbox.process",
          status: "completed",
        },
        orderBy: { finishedAt: "desc" },
        select: { finishedAt: true },
      }),
      db.systemJobRun.findFirst({
        where: {
          jobName: "retention.pseudonymize",
          status: "completed",
        },
        orderBy: { finishedAt: "desc" },
        select: { finishedAt: true },
      }),
    ]);

    return NextResponse.json(
      {
        status: "ready",
        timestamp: new Date().toISOString(),
        jobs: {
          outboxLastFinishedAt: lastOutboxJob?.finishedAt ?? null,
          retentionLastFinishedAt: lastRetentionJob?.finishedAt ?? null,
        },
      },
      { status: 200 },
    );
  } catch {
    return NextResponse.json(
      {
        status: "not-ready",
        timestamp: new Date().toISOString(),
      },
      { status: 503 },
    );
  }
}
