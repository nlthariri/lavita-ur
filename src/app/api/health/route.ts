import { NextResponse } from "next/server";

import { db } from "@/lib/db";

const OUTBOX_HEARTBEAT_MINUTES = 10;
const RETENTION_HEARTBEAT_DAYS = 8;

export async function GET(request: Request) {
  try {
    await db.$queryRaw`SELECT 1`;

    const checkJobs = new URL(request.url).searchParams.get("check") === "jobs";

    if (!checkJobs) {
      return NextResponse.json({
        status: "ok",
        timestamp: new Date().toISOString(),
        db: "connected",
      });
    }

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

    const now = Date.now();
    const outboxHealthy =
      Boolean(lastOutboxJob?.finishedAt) &&
      now - new Date(lastOutboxJob!.finishedAt!).getTime() <= OUTBOX_HEARTBEAT_MINUTES * 60 * 1000;
    const retentionHealthy =
      Boolean(lastRetentionJob?.finishedAt) &&
      now - new Date(lastRetentionJob!.finishedAt!).getTime() <= RETENTION_HEARTBEAT_DAYS * 24 * 60 * 60 * 1000;

    const status = outboxHealthy && retentionHealthy ? "ok" : "degraded";
    const httpStatus = outboxHealthy && retentionHealthy ? 200 : 503;

    return NextResponse.json(
      {
        status,
        timestamp: new Date().toISOString(),
        db: "connected",
        jobs: {
          outbox: {
            healthy: outboxHealthy,
            lastFinishedAt: lastOutboxJob?.finishedAt ?? null,
          },
          retention: {
            healthy: retentionHealthy,
            lastFinishedAt: lastRetentionJob?.finishedAt ?? null,
          },
        },
      },
      { status: httpStatus },
    );
  } catch {
    return NextResponse.json(
      {
        status: "degraded",
        timestamp: new Date().toISOString(),
        db: "disconnected",
      },
      { status: 503 },
    );
  }
}
