import { randomBytes } from "node:crypto";

import bcrypt from "bcryptjs";
import { PrismaClient, UserRole } from "@prisma/client";

import { recordAuditEvent } from "@/lib/audit/service";
import { queueAndSendEmail } from "@/lib/email/service";
import { getEnv } from "@/lib/env";

export type SessionContext = {
  userId: string;
  organizationId: string;
  role: "OWNER" | "MANAGER" | "EMPLOYEE" | "ACCOUNTANT";
};

type CreateUserInput = {
  fullName: string;
  email: string;
  role: UserRole;
  teamId?: string;
};

function generateTemporaryPassword(): string {
  return `Lv-${randomBytes(8).toString("base64url")}-9a!`;
}

export async function listUsersForSession(db: PrismaClient, session: SessionContext) {
  if (session.role !== "OWNER" && session.role !== "MANAGER") {
    throw new Error("Onvoldoende rechten.");
  }

  const manager =
    session.role === "MANAGER"
      ? await db.user.findUniqueOrThrow({
          where: { id: session.userId },
          select: { teamId: true },
        })
      : null;

  return db.user.findMany({
    where: {
      organizationId: session.organizationId,
      ...(session.role === "MANAGER" ? { teamId: manager?.teamId ?? "__no_team__" } : {}),
    },
    select: {
      id: true,
      fullName: true,
      email: true,
      role: true,
      isActive: true,
      mfaEnabled: true,
      team: { select: { id: true, name: true } },
    },
    orderBy: [{ role: "asc" }, { fullName: "asc" }],
  });
}

export async function createUserForSession(db: PrismaClient, session: SessionContext, input: CreateUserInput) {
  if (session.role !== "OWNER" && session.role !== "MANAGER") {
    throw new Error("Onvoldoende rechten.");
  }

  const normalizedEmail = input.email.trim().toLowerCase();
  const existing = await db.user.findUnique({ where: { email: normalizedEmail }, select: { id: true } });
  if (existing) {
    throw new Error("Er bestaat al een account met dit e-mailadres.");
  }

  let finalRole = input.role;
  let finalTeamId = input.teamId;

  if (session.role === "MANAGER") {
    if (input.role !== UserRole.EMPLOYEE) {
      throw new Error("Manager kan alleen medewerkeraccounts aanmaken.");
    }

    const manager = await db.user.findUniqueOrThrow({
      where: { id: session.userId },
      select: { teamId: true },
    });

    if (!manager.teamId) {
      throw new Error("Manager moet aan een team gekoppeld zijn.");
    }

    finalRole = UserRole.EMPLOYEE;
    finalTeamId = manager.teamId;
  }

  if (finalRole === UserRole.MANAGER && !finalTeamId) {
    throw new Error("Manageraccount moet gekoppeld zijn aan een team.");
  }

  if (finalTeamId) {
    const team = await db.team.findFirst({
      where: {
        id: finalTeamId,
        organizationId: session.organizationId,
      },
      select: { id: true },
    });

    if (!team) {
      throw new Error("Ongeldig team geselecteerd.");
    }
  }

  const temporaryPassword = generateTemporaryPassword();
  const passwordHash = await bcrypt.hash(temporaryPassword, 12);

  const created = await db.$transaction(async (tx) => {
    const user = await tx.user.create({
      data: {
        organizationId: session.organizationId,
        fullName: input.fullName.trim(),
        email: normalizedEmail,
        role: finalRole,
        teamId: finalTeamId,
        passwordHash,
        isActive: true,
        mfaEnabled: false,
      },
    });

    await recordAuditEvent(tx, {
      organizationId: session.organizationId,
      actorId: session.userId,
      action: "user.created",
      targetType: "User",
      targetId: user.id,
      afterData: {
        fullName: user.fullName,
        email: user.email,
        role: user.role,
        teamId: user.teamId,
      },
    });

    return user;
  });

  const env = getEnv();
  await queueAndSendEmail({
    organizationId: session.organizationId,
    userId: created.id,
    recipient: created.email,
    type: "ACCOUNT_CREATED",
    variables: {
      naam: created.fullName,
      link: `${env.APP_BASE_URL}/login`,
      tijdelijk_wachtwoord: temporaryPassword,
    },
  });

  return {
    userId: created.id,
    temporaryPassword,
  };
}
