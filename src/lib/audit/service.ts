import type { Prisma, PrismaClient } from "@prisma/client";

type RequestMeta = {
  requestId?: string;
  ipAddress?: string;
  userAgent?: string;
};

type AuditInput = {
  organizationId: string;
  actorId: string;
  action: string;
  targetType: string;
  targetId: string;
  beforeData?: Prisma.InputJsonValue;
  afterData?: Prisma.InputJsonValue;
  requestMeta?: RequestMeta;
};

type DbClient = PrismaClient | Prisma.TransactionClient;

export function extractRequestMeta(request: Request): RequestMeta {
  return {
    requestId: request.headers.get("x-request-id") ?? undefined,
    ipAddress: request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ?? request.headers.get("x-real-ip") ?? undefined,
    userAgent: request.headers.get("user-agent") ?? undefined,
  };
}

export async function recordAuditEvent(db: DbClient, input: AuditInput): Promise<void> {
  await db.auditEvent.create({
    data: {
      organizationId: input.organizationId,
      actorId: input.actorId,
      action: input.action,
      targetType: input.targetType,
      targetId: input.targetId,
      beforeData: input.beforeData,
      afterData: input.afterData,
      requestId: input.requestMeta?.requestId,
      ipAddress: input.requestMeta?.ipAddress,
      userAgent: input.requestMeta?.userAgent,
    },
  });
}
