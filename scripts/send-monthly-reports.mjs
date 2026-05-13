import { endOfMonth, format, startOfMonth, subMonths } from "date-fns";
import { nl } from "date-fns/locale";
import nodemailer from "nodemailer";
import { PDFDocument, StandardFonts, rgb } from "pdf-lib";
import { PrismaClient, UserRole } from "@prisma/client";

const prisma = new PrismaClient();

function getRequiredEnv(name) {
  const value = process.env[name];
  if (!value) {
    throw new Error(`${name} ontbreekt.`);
  }

  return value;
}

function getTransporter() {
  const port = Number(getRequiredEnv("SMTP_PORT"));

  return nodemailer.createTransport({
    host: getRequiredEnv("SMTP_HOST"),
    port,
    secure: port === 465,
    auth: {
      user: getRequiredEnv("SMTP_USER"),
      pass: getRequiredEnv("SMTP_PASSWORD"),
    },
  });
}

function previousMonthRange() {
  const base = subMonths(new Date(), 1);
  const start = startOfMonth(base);
  const end = endOfMonth(base);
  return {
    start,
    end,
    label: format(start, "MMMM yyyy", { locale: nl }),
  };
}

async function listEntriesForRecipient(recipient, range) {
  const manager =
    recipient.role === UserRole.MANAGER
      ? await prisma.user.findUnique({ where: { id: recipient.id }, select: { teamId: true } })
      : null;

  const where =
    recipient.role === UserRole.MANAGER
      ? {
          organizationId: recipient.organizationId,
          teamId: manager?.teamId ?? "__no_team__",
        }
      : {
          organizationId: recipient.organizationId,
        };

  return prisma.workEntry.findMany({
    where: {
      ...where,
      entryDate: {
        gte: range.start,
        lte: range.end,
      },
    },
    select: {
      entryDate: true,
      startAt: true,
      endAt: true,
      pauseMinutes: true,
      netMinutes: true,
      employee: { select: { fullName: true } },
      project: { select: { name: true } },
    },
    orderBy: [{ entryDate: "asc" }, { startAt: "asc" }],
  });
}

function toRows(entries) {
  return entries.map((entry) => ({
    medewerker: entry.employee.fullName,
    datum: entry.entryDate.toLocaleDateString("nl-NL"),
    tijden: `${entry.startAt.toLocaleTimeString("nl-NL", { hour: "2-digit", minute: "2-digit" })} - ${entry.endAt.toLocaleTimeString("nl-NL", { hour: "2-digit", minute: "2-digit" })}`,
    pauze: `${entry.pauseMinutes} min`,
    netto: `${(entry.netMinutes / 60).toFixed(2)} u`,
    project: entry.project?.name ?? "",
  }));
}

async function generatePdf(rows, title, subtitle) {
  const pdf = await PDFDocument.create();
  const font = await pdf.embedFont(StandardFonts.Helvetica);
  const boldFont = await pdf.embedFont(StandardFonts.HelveticaBold);
  let page = pdf.addPage([842, 595]);
  let y = 555;

  function drawHeader() {
    page.drawText(title, { x: 40, y, size: 18, font: boldFont, color: rgb(0.07, 0.07, 0.07) });
    y -= 22;
    page.drawText(subtitle, { x: 40, y, size: 10, font, color: rgb(0.35, 0.35, 0.35) });
    y -= 26;
    page.drawText("Medewerker", { x: 40, y, size: 10, font: boldFont });
    page.drawText("Datum", { x: 220, y, size: 10, font: boldFont });
    page.drawText("Tijden", { x: 320, y, size: 10, font: boldFont });
    page.drawText("Pauze", { x: 460, y, size: 10, font: boldFont });
    page.drawText("Netto", { x: 530, y, size: 10, font: boldFont });
    page.drawText("Project", { x: 600, y, size: 10, font: boldFont });
    y -= 18;
  }

  drawHeader();

  for (const row of rows) {
    if (y < 40) {
      page = pdf.addPage([842, 595]);
      y = 555;
      drawHeader();
    }

    page.drawText(row.medewerker.slice(0, 28), { x: 40, y, size: 10, font });
    page.drawText(row.datum, { x: 220, y, size: 10, font });
    page.drawText(row.tijden, { x: 320, y, size: 10, font });
    page.drawText(row.pauze, { x: 460, y, size: 10, font });
    page.drawText(row.netto, { x: 530, y, size: 10, font });
    page.drawText(row.project.slice(0, 24), { x: 600, y, size: 10, font });
    y -= 16;
  }

  return Buffer.from(await pdf.save());
}

async function main() {
  const range = previousMonthRange();
  const transporter = getTransporter();
  const from = getRequiredEnv("SMTP_FROM");

  const recipients = await prisma.user.findMany({
    where: {
      isActive: true,
      role: { in: [UserRole.OWNER, UserRole.MANAGER] },
    },
    select: {
      id: true,
      organizationId: true,
      fullName: true,
      email: true,
      role: true,
    },
  });

  for (const recipient of recipients) {
    const entries = await listEntriesForRecipient(recipient, range);
    const rows = toRows(entries);
    const pdf = await generatePdf(rows, "Maandrapportage urenstaat", `Periode: ${range.label}`);

    await transporter.sendMail({
      from,
      to: recipient.email,
      subject: "Maandrapportage urenstaten",
      text: `De maandrapportage over ${range.label} is beschikbaar als bijlage.`,
      html: `<p>De maandrapportage over <strong>${range.label}</strong> is beschikbaar als bijlage.</p>`,
      attachments: [
        {
          filename: `maandrapportage-${range.label.replaceAll(" ", "-")}.pdf`,
          content: pdf,
          contentType: "application/pdf",
        },
      ],
    });
  }

  console.log(`Maandrapportages verstuurd voor ${range.label} naar ${recipients.length} ontvangers.`);
}

main()
  .catch((error) => {
    console.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 1;
  })
  .finally(async () => {
    await prisma.$disconnect();
  });