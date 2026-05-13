import { EmailEventType } from "@prisma/client";
import nodemailer from "nodemailer";

import { db } from "@/lib/db";
import { canReceiveEmail, createUnsubscribeUrl, isOptionalEmailType } from "@/lib/email/preferences";
import { defaultEmailTemplates } from "@/lib/email/templates";
import { getEnv } from "@/lib/env";

type TemplateVariables = Record<string, string | number>;

type EmailAttachment = {
  filename: string;
  content: Buffer;
  contentType: string;
};

type QueueEmailInput = {
  organizationId: string;
  recipient: string;
  type: EmailEventType;
  variables: TemplateVariables;
  userId?: string;
  attachments?: EmailAttachment[];
};

type SerializedAttachment = {
  filename: string;
  content: string;
  contentType: string;
};

type OutboxEmailPayload = {
  emailEventId: string;
  recipient: string;
  subject: string;
  bodyText: string;
  bodyHtml: string;
  attachments?: SerializedAttachment[];
};

let transporterCache: nodemailer.Transporter | null = null;

function getTransporter(): nodemailer.Transporter {
  if (transporterCache) {
    return transporterCache;
  }

  const env = getEnv();
  const smtpPort = env.SMTP_PORT;

  transporterCache = nodemailer.createTransport({
    host: env.SMTP_HOST,
    port: smtpPort,
    secure: smtpPort === 465,
    connectionTimeout: 5_000,
    greetingTimeout: 5_000,
    socketTimeout: 5_000,
    requireTLS: true,
    tls: {
      rejectUnauthorized: true,
    },
    auth: {
      user: env.SMTP_USER,
      pass: env.SMTP_PASSWORD,
    },
  });

  return transporterCache;
}

function interpolate(template: string, variables: TemplateVariables): string {
  return Object.entries(variables).reduce((result, [key, value]) => {
    return result.replaceAll(`{{${key}}}`, String(value));
  }, template);
}

function escapeHtml(value: string): string {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#x27;");
}

function interpolateHtml(template: string, variables: TemplateVariables): string {
  return Object.entries(variables).reduce((result, [key, value]) => {
    return result.replaceAll(`{{${key}}}`, escapeHtml(String(value)));
  }, template);
}

export async function queueAndSendEmail(input: QueueEmailInput): Promise<void> {
  const { organizationId, recipient, type, variables, userId, attachments } = input;

  const allowed = await canReceiveEmail(userId, type);
  if (!allowed) {
    await db.emailEvent.create({
      data: {
        organizationId,
        userId,
        type,
        recipient,
        subject: "overgeslagen",
        bodyText: "Niet verzonden door e-mailvoorkeuren.",
        status: "skipped_opt_out",
      },
    });
    return;
  }

  const storedTemplate = await db.emailTemplate.findUnique({
    where: {
      organizationId_type: {
        organizationId,
        type,
      },
    },
  });

  const fallbackTemplate = defaultEmailTemplates[type];
  const unsubscribeUrl = userId && isOptionalEmailType(type) ? createUnsubscribeUrl(userId, type) : "";
  const mergedVariables = {
    ...variables,
    unsubscribe_url: unsubscribeUrl,
  };

  const subject = interpolate(storedTemplate?.subject ?? fallbackTemplate.subject, mergedVariables);
  const bodyText = interpolate(storedTemplate?.bodyText ?? fallbackTemplate.bodyText, mergedVariables);
  const bodyHtml = interpolateHtml(storedTemplate?.bodyHtml ?? fallbackTemplate.bodyHtml, mergedVariables);

  const event = await db.emailEvent.create({
    data: {
      organizationId,
      userId,
      type,
      recipient,
      subject,
      bodyText,
      status: "queued",
    },
  });

  const serializedAttachments = attachments?.map((attachment) => ({
    filename: attachment.filename,
    content: attachment.content.toString("base64"),
    contentType: attachment.contentType,
  }));

  const payload: OutboxEmailPayload = {
    emailEventId: event.id,
    recipient,
    subject,
    bodyText,
    bodyHtml,
    attachments: serializedAttachments,
  };

  await db.outboxEvent.create({
    data: {
      organizationId,
      aggregateType: "EmailEvent",
      aggregateId: event.id,
      eventType: "email.send",
      idempotencyKey: `email:${event.id}`,
      payload,
      status: "queued",
      nextAttemptAt: new Date(),
    },
  });
}

function toMailAttachments(attachments?: SerializedAttachment[]): EmailAttachment[] | undefined {
  if (!attachments || attachments.length === 0) {
    return undefined;
  }

  return attachments.map((attachment) => ({
    filename: attachment.filename,
    content: Buffer.from(attachment.content, "base64"),
    contentType: attachment.contentType,
  }));
}

function nextAttemptDate(retryCount: number): Date {
  const delaySeconds = Math.min(2 ** retryCount, 300);
  return new Date(Date.now() + delaySeconds * 1000);
}

export async function processEmailOutboxBatch(limit = 25): Promise<{
  processed: number;
  sent: number;
  failed: number;
}> {
  const now = new Date();
  const items = await db.outboxEvent.findMany({
    where: {
      eventType: "email.send",
      status: { in: ["queued", "retrying"] },
      nextAttemptAt: { lte: now },
    },
    orderBy: { createdAt: "asc" },
    take: limit,
  });

  let processed = 0;
  let sent = 0;
  let failed = 0;

  for (const item of items) {
    processed += 1;

    const payload = item.payload as unknown as OutboxEmailPayload;

    try {
      await getTransporter().sendMail({
        from: getEnv().SMTP_FROM,
        to: payload.recipient,
        subject: payload.subject,
        text: payload.bodyText,
        html: payload.bodyHtml,
        attachments: toMailAttachments(payload.attachments),
      });

      await db.$transaction([
        db.emailEvent.update({
          where: { id: payload.emailEventId },
          data: {
            status: "sent",
            sentAt: new Date(),
            errorMessage: null,
          },
        }),
        db.outboxEvent.update({
          where: { id: item.id },
          data: {
            status: "sent",
            sentAt: new Date(),
            errorMessage: null,
          },
        }),
      ]);

      sent += 1;
    } catch (error) {
      const message = error instanceof Error ? error.message : "Onbekende e-mailfout";
      const nextRetryCount = item.retryCount + 1;
      const deadLetter = nextRetryCount >= 5;

      await db.$transaction([
        db.emailEvent.update({
          where: { id: payload.emailEventId },
          data: {
            status: deadLetter ? "failed" : "queued",
            errorMessage: message,
          },
        }),
        db.outboxEvent.update({
          where: { id: item.id },
          data: {
            status: deadLetter ? "dead_letter" : "retrying",
            retryCount: nextRetryCount,
            nextAttemptAt: nextAttemptDate(nextRetryCount),
            failedAt: deadLetter ? new Date() : null,
            errorMessage: message,
          },
        }),
      ]);

      failed += 1;
    }
  }

  return { processed, sent, failed };
}
