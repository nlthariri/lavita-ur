import { PrismaClient } from "@prisma/client";
import { subYears } from "date-fns";

const prisma = new PrismaClient();
let activeJobRunId = null;
let activeJobStartedAt = null;

async function main() {
  const startedAt = new Date();
  activeJobStartedAt = startedAt;
  const jobRun = await prisma.systemJobRun.create({
    data: {
      jobName: "retention.pseudonymize",
      status: "started",
      startedAt,
    },
    select: { id: true },
  });
  activeJobRunId = jobRun.id;

  const organizations = await prisma.organization.findMany({
    select: {
      id: true,
      retentionYears: true,
    },
  });

  let totalUsers = 0;
  let totalWorkEntries = 0;
  let totalObjections = 0;

  for (const organization of organizations) {
    const retentionYears = organization.retentionYears || 7;
    const cutoff = subYears(new Date(), retentionYears);

    const usersToPseudonymize = await prisma.user.findMany({
      where: {
        organizationId: organization.id,
        isActive: false,
        updatedAt: { lt: cutoff },
        email: { not: { startsWith: "deleted+" } },
      },
      select: {
        id: true,
      },
    });

    for (const user of usersToPseudonymize) {
      await prisma.$transaction(async (tx) => {
        const result = await tx.user.updateMany({
          where: {
            id: user.id,
            email: { not: { startsWith: "deleted+" } },
          },
          data: {
            fullName: `Gepseudonimiseerd-${user.id.slice(0, 8)}`,
            email: `deleted+${user.id}@anonymized.local`,
            passwordHash: null,
            mfaEnabled: false,
            mfaSecret: null,
            hourlyRateCents: null,
          },
        });

        if (result.count === 0) {
          return;
        }
      });

      totalUsers += 1;
    }

    const oldWorkEntries = await prisma.workEntry.findMany({
      where: {
        organizationId: organization.id,
        entryDate: { lt: cutoff },
        note: { not: null },
      },
      select: { id: true },
    });

    if (oldWorkEntries.length > 0) {
      await prisma.workEntry.updateMany({
        where: {
          id: { in: oldWorkEntries.map((entry) => entry.id) },
        },
        data: {
          note: null,
        },
      });
      totalWorkEntries += oldWorkEntries.length;
    }

    const oldObjections = await prisma.objection.findMany({
      where: {
        organizationId: organization.id,
        submittedAt: { lt: cutoff },
      },
      select: { id: true },
    });

    if (oldObjections.length > 0) {
      await prisma.objection.updateMany({
        where: {
          id: { in: oldObjections.map((item) => item.id) },
        },
        data: {
          motivation: "[gepseudonimiseerd conform retentiebeleid]",
          managerResponse: "[gepseudonimiseerd conform retentiebeleid]",
        },
      });
      totalObjections += oldObjections.length;
    }
  }

  const finishedAt = new Date();
  const rowsAffected = totalUsers + totalWorkEntries + totalObjections;

  await prisma.systemJobRun.update({
    where: { id: jobRun.id },
    data: {
      status: "completed",
      finishedAt,
      durationMs: finishedAt.getTime() - startedAt.getTime(),
      rowsAffected,
      details: {
        users: totalUsers,
        workEntries: totalWorkEntries,
        objections: totalObjections,
      },
    },
  });

  console.log(`Pseudonimisering afgerond. Users: ${totalUsers}, workEntries: ${totalWorkEntries}, objections: ${totalObjections}`);
}

main()
  .catch(async (error) => {
    if (activeJobRunId) {
      const finishedAt = new Date();
      await prisma.systemJobRun
        .update({
          where: { id: activeJobRunId },
          data: {
            status: "failed",
            finishedAt,
            durationMs: activeJobStartedAt ? finishedAt.getTime() - activeJobStartedAt.getTime() : null,
            errorMessage: error instanceof Error ? error.message : String(error),
          },
        })
        .catch(() => undefined);
    }

    console.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 1;
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
