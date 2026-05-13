import { endOfMonth, endOfWeek, format, startOfMonth, startOfWeek, subMonths } from "date-fns";
import { nl } from "date-fns/locale";
import ExcelJS from "exceljs";
import { PDFDocument, StandardFonts, rgb } from "pdf-lib";

import { db } from "@/lib/db";

export type ReportPeriod = "week" | "month";
export type ReportFormat = "csv" | "xlsx" | "pdf";
export type SessionRole = "OWNER" | "MANAGER" | "EMPLOYEE" | "ACCOUNTANT";

export type ReportScope = {
  organizationId: string;
  role: SessionRole;
  userId: string;
};

export type WorkEntryReportEntry = {
  entryDate: Date;
  startAt: Date;
  endAt: Date;
  pauseMinutes: number;
  netMinutes: number;
  employee: { fullName: string };
  project: { name: string } | null;
};

export type WorkEntryReportRow = {
  medewerker: string;
  datum: string;
  start: string;
  einde: string;
  pauzeMinuten: number;
  nettoUren: string;
  project: string;
};

export function getReportRange(period: ReportPeriod, now: Date = new Date()): { start: Date; end: Date; label: string } {
  if (period === "week") {
    const start = startOfWeek(now, { weekStartsOn: 1 });
    const end = endOfWeek(now, { weekStartsOn: 1 });
    return {
      start,
      end,
      label: `${format(start, "dd-MM-yyyy")} t/m ${format(end, "dd-MM-yyyy")}`,
    };
  }

  const start = startOfMonth(now);
  const end = endOfMonth(now);
  return {
    start,
    end,
    label: format(start, "MMMM yyyy", { locale: nl }),
  };
}

export function getPreviousMonthRange(now: Date = new Date()): { start: Date; end: Date; label: string } {
  const target = subMonths(now, 1);
  const start = startOfMonth(target);
  const end = endOfMonth(target);

  return {
    start,
    end,
    label: format(start, "MMMM yyyy", { locale: nl }),
  };
}

async function getManagerTeamId(userId: string): Promise<string> {
  const manager = await db.user.findUniqueOrThrow({
    where: { id: userId },
    select: { teamId: true },
  });

  return manager.teamId ?? "__no_team__";
}

export async function listWorkEntryReportEntries(
  scope: ReportScope,
  range: { start: Date; end: Date },
): Promise<WorkEntryReportEntry[]> {
  const teamId = scope.role === "MANAGER" ? await getManagerTeamId(scope.userId) : null;

  const where =
    scope.role === "EMPLOYEE"
      ? {
          organizationId: scope.organizationId,
          employeeId: scope.userId,
        }
      : scope.role === "MANAGER"
        ? {
            organizationId: scope.organizationId,
            teamId: teamId ?? "__no_team__",
          }
        : {
            organizationId: scope.organizationId,
          };

  return db.workEntry.findMany({
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

export function toReportRows(entries: WorkEntryReportEntry[]): WorkEntryReportRow[] {
  return entries.map((entry) => ({
    medewerker: entry.employee.fullName,
    datum: entry.entryDate.toLocaleDateString("nl-NL"),
    start: entry.startAt.toLocaleTimeString("nl-NL", { hour: "2-digit", minute: "2-digit" }),
    einde: entry.endAt.toLocaleTimeString("nl-NL", { hour: "2-digit", minute: "2-digit" }),
    pauzeMinuten: entry.pauseMinutes,
    nettoUren: (entry.netMinutes / 60).toFixed(2),
    project: entry.project?.name ?? "",
  }));
}

function escapeCsvValue(value: string | number): string {
  const text = String(value);
  if (text.includes(",") || text.includes("\n") || text.includes('"')) {
    return `"${text.replaceAll('"', '""')}"`;
  }

  return text;
}

export function generateCsv(rows: WorkEntryReportRow[]): string {
  const header = ["Medewerker", "Datum", "Start", "Einde", "PauzeMinuten", "NettoUren", "Project"];
  const lines = rows.map((row) =>
    [row.medewerker, row.datum, row.start, row.einde, row.pauzeMinuten, row.nettoUren, row.project]
      .map(escapeCsvValue)
      .join(","),
  );

  return [header.join(","), ...lines].join("\n");
}

export async function generateXlsx(rows: WorkEntryReportRow[]): Promise<Buffer> {
  const workbook = new ExcelJS.Workbook();
  const worksheet = workbook.addWorksheet("Urenstaat");

  worksheet.columns = [
    { header: "Medewerker", key: "medewerker", width: 24 },
    { header: "Datum", key: "datum", width: 14 },
    { header: "Start", key: "start", width: 10 },
    { header: "Einde", key: "einde", width: 10 },
    { header: "PauzeMinuten", key: "pauzeMinuten", width: 16 },
    { header: "NettoUren", key: "nettoUren", width: 12 },
    { header: "Project", key: "project", width: 20 },
  ];

  worksheet.addRows(rows);
  worksheet.getRow(1).font = { bold: true };

  const arrayBuffer = await workbook.xlsx.writeBuffer();
  return Buffer.from(arrayBuffer);
}

export async function generatePdf(
  rows: WorkEntryReportRow[],
  title: string,
  subtitle: string,
): Promise<Buffer> {
  const pdf = await PDFDocument.create();
  const font = await pdf.embedFont(StandardFonts.Helvetica);
  const boldFont = await pdf.embedFont(StandardFonts.HelveticaBold);

  let page = pdf.addPage([842, 595]);
  let { width, height } = page.getSize();
  let y = height - 40;
  const margin = 40;
  const lineHeight = 16;

  const addHeader = () => {
    page.drawText(title, { x: margin, y, size: 18, font: boldFont, color: rgb(0.07, 0.07, 0.07) });
    y -= 22;
    page.drawText(subtitle, { x: margin, y, size: 10, font, color: rgb(0.35, 0.35, 0.35) });
    y -= 26;
    page.drawText("Medewerker", { x: margin, y, size: 10, font: boldFont });
    page.drawText("Datum", { x: 220, y, size: 10, font: boldFont });
    page.drawText("Tijden", { x: 320, y, size: 10, font: boldFont });
    page.drawText("Pauze", { x: 450, y, size: 10, font: boldFont });
    page.drawText("Netto", { x: 520, y, size: 10, font: boldFont });
    page.drawText("Project", { x: 590, y, size: 10, font: boldFont });
    y -= 14;
    page.drawLine({ start: { x: margin, y }, end: { x: width - margin, y }, thickness: 1, color: rgb(0.85, 0.85, 0.85) });
    y -= 12;
  };

  addHeader();

  for (const row of rows) {
    if (y < 40) {
      page = pdf.addPage([842, 595]);
      ({ width, height } = page.getSize());
      y = height - 40;
      addHeader();
    }

    page.drawText(row.medewerker.slice(0, 28), { x: margin, y, size: 10, font });
    page.drawText(row.datum, { x: 220, y, size: 10, font });
    page.drawText(`${row.start} - ${row.einde}`, { x: 320, y, size: 10, font });
    page.drawText(`${row.pauzeMinuten} min`, { x: 450, y, size: 10, font });
    page.drawText(`${row.nettoUren} u`, { x: 520, y, size: 10, font });
    page.drawText(row.project.slice(0, 24), { x: 590, y, size: 10, font });
    y -= lineHeight;
  }

  const bytes = await pdf.save();
  return Buffer.from(bytes);
}

export function getReportContentType(format: ReportFormat): string {
  if (format === "csv") return "text/csv; charset=utf-8";
  if (format === "pdf") return "application/pdf";
  return "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";
}

export function getReportFilename(period: ReportPeriod, format: ReportFormat): string {
  return `urenstaat-${period}.${format}`;
}