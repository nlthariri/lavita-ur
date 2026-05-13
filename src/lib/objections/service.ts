import { AtwSeverity, AtwViolationType, ObjectionStatus, PrismaClient, UserRole } from "@prisma/client";
import { subWeeks } from "date-fns";

import { evaluateAtwSignals } from "@/lib/atw/engine";
import { recordAuditEvent } from "@/lib/audit/service";
import { calculateNetMinutes, parseTimeOnDate } from "@/lib/domain/time";
import { queueAndSendEmail } from "@/lib/email/service";
import { getEnv } from "@/lib/env";

export type SessionRole = "OWNER" | "MANAGER" | "EMPLOYEE" | "ACCOUNTANT";

export type SessionContext = {
  userId: string;
  organizationId: string;
  role: SessionRole;
};

type RequestMeta = {
  requestId?: string;
  ipAddress?: string;
  userAgent?: string;
};

function toViolationType(type: string): AtwViolationType {
  if (type === "DAILY_LIMIT") return AtwViolationType.DAILY_LIMIT;
  if (type === "WEEKLY_LIMIT" || type === "WEEKLY_WARNING") return AtwViolationType.WEEKLY_LIMIT;
  if (type === "SIXTEEN_WEEK_AVERAGE") return AtwViolationType.SIXTEEN_WEEK_AVERAGE;
  return AtwViolationType.REST_PERIOD;
}

function formatHours(minutes: number): string {
  return (minutes / 60).toFixed(2);
}

export async function listObjections(db: PrismaClient, session: SessionContext) {
  const manager =
    session.role === "MANAGER"
      ? await db.user.findUnique({
          where: { id: session.userId },
          select: { teamId: true },
        })
      : null;

  const where =
    session.role === "EMPLOYEE"
      ? {
          organizationId: session.organizationId,
          workEntry: { employeeId: session.userId },
        }
      : session.role === "MANAGER"
        ? {
            organizationId: session.organizationId,
            workEntry: { teamId: manager?.teamId ?? "__no_team__" },
          }
        : {
            organizationId: session.organizationId,
          };

  return db.objection.findMany({
    where,
    include: {
      submittedBy: { select: { id: true, fullName: true } },
      reviewedBy: { select: { id: true, fullName: true } },
      workEntry: {
        select: {
          id: true,
          entryDate: true,
          startAt: true,
          endAt: true,
          pauseMinutes: true,
          netMinutes: true,
          employee: { select: { id: true, fullName: true, email: true } },
        },
      },
    },
    orderBy: { submittedAt: "desc" },
  });
}

export async function submitObjection(
  db: PrismaClient,
  session: SessionContext,
  workEntryId: string,
  motivation: string,
  requestMeta?: RequestMeta,
): Promise<void> {
  if (session.role !== "EMPLOYEE") {
    throw new Error("Alleen medewerkers kunnen bezwaar indienen.");
  }

  const entry = await db.workEntry.findUniqueOrThrow({
    where: { id: workEntryId },
    include: {
      employee: true,
      team: true,
    },
  });

  if (entry.organizationId !== session.organizationId || entry.employeeId !== session.userId) {
    throw new Error("Je mag alleen bezwaar indienen op je eigen urenregels.");
  }

  const existingOpen = await db.objection.findFirst({
    where: {
      workEntryId,
      status: ObjectionStatus.OPEN,
    },
    select: { id: true },
  });

  if (existingOpen) {
    throw new Error("Er staat al een open bezwaar voor deze urenregel.");
  }

  const created = await db.objection.create({
    data: {
      organizationId: session.organizationId,
      workEntryId,
      submittedById: session.userId,
      motivation,
      status: ObjectionStatus.OPEN,
    },
  });

  await recordAuditEvent(db, {
    organizationId: session.organizationId,
    actorId: session.userId,
    action: "objection.submitted",
    targetType: "Objection",
    targetId: created.id,
    afterData: {
      status: created.status,
      workEntryId,
      submittedById: session.userId,
      motivation,
    },
    requestMeta,
  });

  const env = getEnv();
  const ownerRecipients = await db.user.findMany({
    where: {
      organizationId: session.organizationId,
      role: UserRole.OWNER,
      isActive: true,
    },
    select: { id: true, email: true },
  });

  const managerRecipients = entry.team
    ? await db.user.findMany({
        where: {
          organizationId: session.organizationId,
          role: UserRole.MANAGER,
          teamId: entry.team.id,
          isActive: true,
        },
        select: { id: true, email: true },
      })
    : [];

  const recipients = [...managerRecipients, ...ownerRecipients];

  await Promise.all(
    recipients.map((recipient) =>
      queueAndSendEmail({
        organizationId: session.organizationId,
        userId: recipient.id,
        recipient: recipient.email,
        type: "OBJECTION_SUBMITTED",
        variables: {
          naam: entry.employee.fullName,
          datum: entry.entryDate.toLocaleDateString("nl-NL"),
          reden: motivation,
          link: `${env.APP_BASE_URL}/dashboard/bezwaren`,
        },
      }),
    ),
  );
}

type ReviewInput = {
  decision: "APPROVED" | "REJECTED";
  managerResponse: string;
  correction?: {
    startTime?: string;
    endTime?: string;
    pauseMinutes?: number;
    note?: string;
  };
};

export async function reviewObjection(
  db: PrismaClient,
  session: SessionContext,
  objectionId: string,
  input: ReviewInput,
  requestMeta?: RequestMeta,
): Promise<void> {
  if (session.role !== "OWNER" && session.role !== "MANAGER") {
    throw new Error("Alleen eigenaar of manager mag bezwaar beoordelen.");
  }

  const objection = await db.objection.findUniqueOrThrow({
    where: { id: objectionId },
    include: {
      workEntry: {
        include: {
          employee: true,
        },
      },
    },
  });

  if (objection.organizationId !== session.organizationId) {
    throw new Error("Bezwaar hoort niet bij jouw organisatie.");
  }

  if (objection.status !== ObjectionStatus.OPEN) {
    throw new Error("Alleen open bezwaren kunnen beoordeeld worden.");
  }

  if (session.role === "MANAGER") {
    const manager = await db.user.findUniqueOrThrow({
      where: { id: session.userId },
      select: { teamId: true },
    });

    if (!manager.teamId || manager.teamId !== objection.workEntry.teamId) {
      throw new Error("Manager mag alleen bezwaren van het eigen team beoordelen.");
    }
  }

  let atwSignals: ReturnType<typeof evaluateAtwSignals> = [];

  await db.$transaction(async (tx) => {
    if (input.decision === "APPROVED" && !input.correction) {
      throw new Error("Bij een akkoord op bezwaar is een correctie op uren verplicht.");
    }

    const reviewReservation = await tx.objection.updateMany({
      where: {
        id: objection.id,
        status: ObjectionStatus.OPEN,
      },
      data: {
        status: input.decision,
        managerResponse: input.managerResponse,
        reviewedById: session.userId,
        reviewedAt: new Date(),
      },
    });

    if (reviewReservation.count !== 1) {
      throw new Error("Bezwaar is al beoordeeld door een andere gebruiker.");
    }

    if (input.decision === "APPROVED" && input.correction) {
      const previousEntry = {
        startAt: objection.workEntry.startAt,
        endAt: objection.workEntry.endAt,
        pauseMinutes: objection.workEntry.pauseMinutes,
        netMinutes: objection.workEntry.netMinutes,
        note: objection.workEntry.note,
      };

      const updatedStartAt = input.correction.startTime
        ? parseTimeOnDate(objection.workEntry.entryDate, input.correction.startTime)
        : objection.workEntry.startAt;
      const updatedEndAt = input.correction.endTime
        ? parseTimeOnDate(objection.workEntry.entryDate, input.correction.endTime)
        : objection.workEntry.endAt;
      const updatedPause = input.correction.pauseMinutes ?? objection.workEntry.pauseMinutes;
      const updatedNet = calculateNetMinutes(updatedStartAt, updatedEndAt, updatedPause);
      const organization = await tx.organization.findUniqueOrThrow({
        where: { id: objection.organizationId },
      });
      const existingShifts = await tx.workEntry.findMany({
        where: {
          employeeId: objection.workEntry.employeeId,
          id: { not: objection.workEntry.id },
          startAt: {
            gte: subWeeks(updatedStartAt, 16),
            lte: updatedEndAt,
          },
        },
        select: {
          id: true,
          startAt: true,
          endAt: true,
          netMinutes: true,
        },
      });

      atwSignals = evaluateAtwSignals({
        proposedShift: {
          startAt: updatedStartAt,
          endAt: updatedEndAt,
          netMinutes: updatedNet,
        },
        existingShifts,
        policy: {
          dailyMaxMinutes: organization.atwDailyMaxMinutes,
          weeklyMaxMinutes: organization.atwWeeklyMaxMinutes,
          weeklyWarningMinutes: organization.atwWeeklyWarningMinutes,
          sixteenWeekAverageMaxMinutes: organization.atwAverage16WeekMinutes,
          minimumRestMinutes: organization.minimumRestMinutes,
        },
        timezone: organization.defaultTimezone,
      });

      const updatedEntry = await tx.workEntry.update({
        where: { id: objection.workEntry.id },
        data: {
          startAt: updatedStartAt,
          endAt: updatedEndAt,
          pauseMinutes: updatedPause,
          netMinutes: updatedNet,
          note: input.correction.note ?? objection.workEntry.note,
        },
      });

      const latestVersion = await tx.workEntryHistory.findFirst({
        where: { workEntryId: objection.workEntry.id },
        orderBy: { version: "desc" },
        select: { version: true },
      });

      await tx.workEntryHistory.create({
        data: {
          organizationId: objection.organizationId,
          workEntryId: objection.workEntry.id,
          version: (latestVersion?.version ?? 0) + 1,
          changedById: session.userId,
          changeReason: "objection_approved",
          startAt: updatedEntry.startAt,
          endAt: updatedEntry.endAt,
          pauseMinutes: updatedEntry.pauseMinutes,
          netMinutes: updatedEntry.netMinutes,
          note: updatedEntry.note,
        },
      });

      await recordAuditEvent(tx, {
        organizationId: objection.organizationId,
        actorId: session.userId,
        action: "work_entry.corrected_by_objection",
        targetType: "WorkEntry",
        targetId: objection.workEntry.id,
        beforeData: previousEntry,
        afterData: {
          startAt: updatedEntry.startAt,
          endAt: updatedEntry.endAt,
          pauseMinutes: updatedEntry.pauseMinutes,
          netMinutes: updatedEntry.netMinutes,
          note: updatedEntry.note,
        },
        requestMeta,
      });

      await tx.atwViolation.deleteMany({
        where: { workEntryId: objection.workEntry.id },
      });

      if (atwSignals.length > 0) {
        await tx.atwViolation.createMany({
          data: atwSignals.map((signal) => ({
            organizationId: objection.organizationId,
            userId: objection.workEntry.employeeId,
            workEntryId: objection.workEntry.id,
            violationType: toViolationType(signal.type),
            severity: signal.severity === "critical" ? AtwSeverity.CRITICAL : AtwSeverity.WARNING,
            periodStart: objection.workEntry.entryDate,
            periodEnd: objection.workEntry.entryDate,
            currentMinutes: signal.currentMinutes,
            thresholdMinutes: signal.thresholdMinutes,
            details: signal.message,
          })),
        });
      }
    }

    const reviewed = await tx.objection.findUniqueOrThrow({
      where: { id: objection.id },
      select: {
        status: true,
        reviewedById: true,
        managerResponse: true,
      },
    });

    await recordAuditEvent(tx, {
      organizationId: objection.organizationId,
      actorId: session.userId,
      action: "objection.reviewed",
      targetType: "Objection",
      targetId: objection.id,
      beforeData: {
        status: objection.status,
        reviewedById: objection.reviewedById,
        managerResponse: objection.managerResponse,
      },
      afterData: {
        status: reviewed.status,
        reviewedById: reviewed.reviewedById,
        managerResponse: reviewed.managerResponse,
      },
      requestMeta,
    });
  });

  const env = getEnv();
  await queueAndSendEmail({
    organizationId: session.organizationId,
    userId: objection.workEntry.employeeId,
    recipient: objection.workEntry.employee.email,
    type: "OBJECTION_RESOLVED",
    variables: {
      naam: objection.workEntry.employee.fullName,
      uitkomst: input.decision === "APPROVED" ? "Akkoord" : "Afgewezen",
      toelichting: input.managerResponse,
      link: `${env.APP_BASE_URL}/dashboard/mijn-uren`,
    },
  });

  if (atwSignals.length > 0) {
    const recipients = await db.user.findMany({
      where: {
        organizationId: session.organizationId,
        isActive: true,
        OR: [
          { id: objection.workEntry.employeeId },
          { role: UserRole.OWNER },
          { role: UserRole.MANAGER, teamId: objection.workEntry.teamId ?? "__no_team__" },
        ],
      },
      select: {
        id: true,
        email: true,
      },
    });

    await Promise.all(
      atwSignals.flatMap((signal) => {
        const type = signal.severity === "critical" ? "ATW_LIMIT_EXCEEDED" : "ATW_LIMIT_WARNING";

        return recipients.map((recipient) =>
          queueAndSendEmail({
            organizationId: session.organizationId,
            userId: recipient.id,
            recipient: recipient.email,
            type,
            variables: {
              naam: objection.workEntry.employee.fullName,
              type: signal.type,
              uren: formatHours(signal.currentMinutes),
              ruimte: formatHours(Math.max(signal.thresholdMinutes - signal.currentMinutes, 0)),
              link: `${env.APP_BASE_URL}/dashboard/atw`,
            },
          }),
        );
      }),
    );
  }
}
