import { PrismaClient } from "@prisma/client";
import nodemailer from "nodemailer";

const prisma = new PrismaClient();

function getEnv(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`${name} ontbreekt`);
  }

  return value;
}

function getTransporter() {
  const port = Number(getEnv("SMTP_PORT"));

  return nodemailer.createTransport({
    host: getEnv("SMTP_HOST"),
    port,
    secure: port === 465,
    requireTLS: true,
    tls: {
      rejectUnauthorized: true,
    },
    connectionTimeout: 5_000,
    greetingTimeout: 5_000,
    socketTimeout: 5_000,
    auth: {
      user: getEnv("SMTP_USER"),
      pass: getEnv("SMTP_PASSWORD"),
    },
  });
}

function toMailAttachments(attachments) {
  if (!Array.isArray(attachments) || attachments.length === 0) {
    return undefined;
  }

  return attachments.map((attachment) => ({
    filename: attachment.filename,
    content: Buffer.from(attachment.content, "base64"),
    contentType: attachment.contentType,
  }));
}

function nextAttemptDate(retryCount) {
  const delaySeconds = Math.min(2 ** retryCount, 300);
  return new Date(Date.now() + delaySeconds * 1000);
}

async function processBatch(limit = 50) {
  const items = await prisma.outboxEvent.findMany({
    where: {
      eventType: "email.send",
      status: { in: ["queued", "retrying"] },
      nextAttemptAt: { lte: new Date() },
    },
    orderBy: { createdAt: "asc" },
    take: limit,
  });

  const transporter = getTransporter();

  let sent = 0;
  let failed = 0;

  for (const item of items) {
    const payload = item.payload;

    try {
      await transporter.sendMail({
        from: getEnv("SMTP_FROM"),
        to: payload.recipient,
        subject: payload.subject,
        text: payload.bodyText,
        html: payload.bodyHtml,
        attachments: toMailAttachments(payload.attachments),
      });

      await prisma.$transaction([
        prisma.emailEvent.update({
          where: { id: payload.emailEventId },
          data: {
            status: "sent",
            sentAt: new Date(),
            errorMessage: null,
          },
        }),
        prisma.outboxEvent.update({
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

      await prisma.$transaction([
        prisma.emailEvent.update({
          where: { id: payload.emailEventId },
          data: {
            status: deadLetter ? "failed" : "queued",
            errorMessage: message,
          },
        }),
        prisma.outboxEvent.update({
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

  return { processed: items.length, sent, failed };
}

async function main() {
  const startedAt = new Date();
  const jobRun = await prisma.systemJobRun.create({
    data: {
      jobName: "email.outbox.process",
      status: "started",
      startedAt,
    },
    select: { id: true },
  });

  try {
    const result = await processBatch(100);
    const finishedAt = new Date();

    await prisma.systemJobRun.update({
      where: { id: jobRun.id },
      data: {
        status: "completed",
        finishedAt,
        durationMs: finishedAt.getTime() - startedAt.getTime(),
        rowsAffected: result.processed,
        details: result,
      },
    });

    console.log(JSON.stringify({ job: "email-outbox", ...result }));
  } catch (error) {
    const finishedAt = new Date();
    await prisma.systemJobRun.update({
      where: { id: jobRun.id },
      data: {
        status: "failed",
        finishedAt,
        durationMs: finishedAt.getTime() - startedAt.getTime(),
        errorMessage: error instanceof Error ? error.message : String(error),
      },
    });

    throw error;
  }
}

main()
  .catch((error) => {
    console.error(error instanceof Error ? error.stack ?? error.message : String(error));
    process.exitCode = 1;
  })
  .finally(async () => {
    await prisma.$disconnect();
  });
