import { AtwSeverity, AtwViolationType, Prisma, UserRole } from "@prisma/client";
import { subWeeks } from "date-fns";

import { db } from "@/lib/db";
import { evaluateAtwSignals } from "@/lib/atw/engine";
import { recordAuditEvent } from "@/lib/audit/service";
import { calculateNetMinutes, parseTimeOnDate } from "@/lib/domain/time";
import { queueAndSendEmail } from "@/lib/email/service";
import { getEnv } from "@/lib/env";
import { type WorkEntryInput, workEntryInputSchema } from "@/lib/validation/work-entry";

function assertAllowedRegistrar(role: UserRole): void {
  if (role !== UserRole.OWNER && role !== UserRole.MANAGER) {
    throw new Error("Alleen eigenaar of manager mag uren registreren.");
  }
}

function toViolationType(type: string): AtwViolationType {
  if (type === "DAILY_LIMIT") return AtwViolationType.DAILY_LIMIT;
  if (type === "WEEKLY_LIMIT" || type === "WEEKLY_WARNING") return AtwViolationType.WEEKLY_LIMIT;
  if (type === "SIXTEEN_WEEK_AVERAGE") return AtwViolationType.SIXTEEN_WEEK_AVERAGE;
  return AtwViolationType.REST_PERIOD;
}

function formatHours(minutes: number): string {
  return (minutes / 60).toFixed(2);
}

export async function createFinalizedWorkEntry(rawInput: WorkEntryInput, registrarId: string) {
  const input = workEntryInputSchema.parse(rawInput);
  const env = getEnv();

  const [registrar, employee] = await Promise.all([
    db.user.findUniqueOrThrow({ where: { id: registrarId } }),
    db.user.findUniqueOrThrow({ where: { id: input.employeeId } }),
  ]);

  const organization = await db.organization.findUniqueOrThrow({ where: { id: registrar.organizationId } });

  assertAllowedRegistrar(registrar.role);

  if (registrar.organizationId !== employee.organizationId) {
    throw new Error("Gebruikers en organisatie moeten overeenkomen.");
  }

  if (registrar.role === UserRole.MANAGER) {
    if (!registrar.teamId) {
      throw new Error("Manager moet gekoppeld zijn aan een team.");
    }

    if (registrar.teamId !== employee.teamId) {
      throw new Error("Manager mag alleen uren registreren voor eigen team.");
    }
  }

  const startAt = parseTimeOnDate(input.entryDate, input.startTime);
  const endAt = parseTimeOnDate(input.entryDate, input.endTime);
  const grossMinutes = Math.floor((endAt.getTime() - startAt.getTime()) / 60000);

  if (grossMinutes > 330 && input.pauseMinutes < 60) {
    throw new Error("Bij meer dan 5,5 uur werken is minimaal 60 minuten pauze verplicht.");
  }

  const netMinutes = calculateNetMinutes(startAt, endAt, input.pauseMinutes);
  const lookbackStart = subWeeks(startAt, 16);

  const existingShifts = await db.workEntry.findMany({
    where: {
      employeeId: input.employeeId,
      startAt: {
        gte: lookbackStart,
        lte: endAt,
      },
    },
    select: {
      id: true,
      startAt: true,
      endAt: true,
      netMinutes: true,
    },
  });

  const atwSignals = evaluateAtwSignals({
    proposedShift: {
      startAt,
      endAt,
      netMinutes,
    },
    existingShifts,
    policy: {
      dailyMaxMinutes: organization.atwDailyMaxMinutes,
      weeklyMaxMinutes: organization.atwWeeklyMaxMinutes,
      weeklyWarningMinutes: organization.atwWeeklyWarningMinutes,
      sixteenWeekAverageMaxMinutes: organization.atwAverage16WeekMinutes,
      minimumRestMinutes: organization.minimumRestMinutes,
    },
  });

  const result = await db.$transaction(async (tx: Prisma.TransactionClient) => {
    const createdEntry = await tx.workEntry.create({
      data: {
        organizationId: registrar.organizationId,
        employeeId: input.employeeId,
        teamId: employee.teamId,
        projectId: input.projectId,
        registeredById: registrar.id,
        entryDate: input.entryDate,
        startAt,
        endAt,
        pauseMinutes: input.pauseMinutes,
        netMinutes,
        type: input.type,
        note: input.note,
        isFinalized: true,
      },
    });

    await tx.workEntryHistory.create({
      data: {
        organizationId: registrar.organizationId,
        workEntryId: createdEntry.id,
        version: 1,
        changedById: registrar.id,
        changeReason: "created",
        startAt: createdEntry.startAt,
        endAt: createdEntry.endAt,
        pauseMinutes: createdEntry.pauseMinutes,
        netMinutes: createdEntry.netMinutes,
        note: createdEntry.note,
      },
    });

    await recordAuditEvent(tx, {
      organizationId: registrar.organizationId,
      actorId: registrar.id,
      action: "work_entry.created",
      targetType: "WorkEntry",
      targetId: createdEntry.id,
      afterData: {
        employeeId: createdEntry.employeeId,
        teamId: createdEntry.teamId,
        projectId: createdEntry.projectId,
        entryDate: createdEntry.entryDate,
        startAt: createdEntry.startAt,
        endAt: createdEntry.endAt,
        pauseMinutes: createdEntry.pauseMinutes,
        netMinutes: createdEntry.netMinutes,
        type: createdEntry.type,
      },
    });

    if (atwSignals.length > 0) {
      await tx.atwViolation.createMany({
        data: atwSignals.map((signal) => ({
          organizationId: registrar.organizationId,
          userId: input.employeeId,
          workEntryId: createdEntry.id,
          violationType: toViolationType(signal.type),
          severity: signal.severity === "critical" ? AtwSeverity.CRITICAL : AtwSeverity.WARNING,
          periodStart: input.entryDate,
          periodEnd: input.entryDate,
          currentMinutes: signal.currentMinutes,
          thresholdMinutes: signal.thresholdMinutes,
          details: signal.message,
        })),
      });
    }

    return createdEntry;
  });

  await queueAndSendEmail({
    organizationId: registrar.organizationId,
    recipient: employee.email,
    userId: employee.id,
    type: "HOURS_REGISTERED",
    variables: {
      naam: employee.fullName,
      datum: input.entryDate.toLocaleDateString("nl-NL"),
      link: `${env.APP_BASE_URL}/urenstaat`,
    },
  });

  if (atwSignals.length > 0) {
    const ownersAndManagers = await db.user.findMany({
      where: {
        organizationId: registrar.organizationId,
        role: { in: [UserRole.OWNER, UserRole.MANAGER] },
        isActive: true,
      },
      select: {
        id: true,
        email: true,
      },
    });

    const warningRecipients = new Map<string, string>();
    const exceededRecipients = new Map<string, string>();

    for (const user of ownersAndManagers) {
      warningRecipients.set(user.id, user.email);
      exceededRecipients.set(user.id, user.email);
    }

    exceededRecipients.set(employee.id, employee.email);

    const emailTasks: Array<Promise<void>> = [];

    for (const signal of atwSignals) {
      const isExceeded = signal.severity === "critical";
      const recipients = isExceeded ? exceededRecipients : warningRecipients;
      const type = isExceeded ? "ATW_LIMIT_EXCEEDED" : "ATW_LIMIT_WARNING";

      for (const [userId, recipient] of recipients.entries()) {
        emailTasks.push(
          queueAndSendEmail({
            organizationId: registrar.organizationId,
            userId,
            recipient,
            type,
            variables: {
              naam: employee.fullName,
              type: signal.type,
              uren: formatHours(signal.currentMinutes),
              ruimte: formatHours(Math.max(signal.thresholdMinutes - signal.currentMinutes, 0)),
              link: `${env.APP_BASE_URL}/dashboard/atw`,
            },
          }),
        );
      }
    }

    await Promise.allSettled(emailTasks);
  }

  return {
    workEntry: result,
    atwSignals,
  };
}
