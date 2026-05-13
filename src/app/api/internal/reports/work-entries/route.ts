import { NextResponse } from "next/server";
import { z } from "zod";

import { getSession } from "@/lib/auth/session";
import {
  generateCsv,
  generatePdf,
  generateXlsx,
  getReportContentType,
  getReportFilename,
  getReportRange,
  listWorkEntryReportEntries,
  toReportRows,
} from "@/lib/reports/work-entries";
import { jsonUnexpectedError } from "@/lib/security/error-handler";
import { getClientIp } from "@/lib/security/request";
import { checkRateLimit } from "@/lib/security/rate-limit";

const reportQuerySchema = z.object({
  period: z.enum(["week", "month"]).default("week"),
  format: z.enum(["csv", "xlsx", "pdf"]).default("xlsx"),
});

export async function GET(request: Request) {
  try {
    const session = await getSession();
    if (!session) {
      return NextResponse.json({ error: "Niet ingelogd." }, { status: 401 });
    }

    const ip = await getClientIp();
    if (!ip) {
      return NextResponse.json({ error: "IP-adres kon niet worden bepaald." }, { status: 503 });
    }

    const allowed = await checkRateLimit(`reports:${ip}`, 30, 60_000);
    if (!allowed) {
      return NextResponse.json({ error: "Te veel exportverzoeken. Probeer later opnieuw." }, { status: 429 });
    }

    const url = new URL(request.url);
    const query = reportQuerySchema.parse({
      period: url.searchParams.get("period") ?? undefined,
      format: url.searchParams.get("format") ?? undefined,
    });

    const range = getReportRange(query.period);
    const entries = await listWorkEntryReportEntries(
      {
        organizationId: session.organizationId,
        role: session.role,
        userId: session.userId,
      },
      range,
    );
    const rows = toReportRows(entries);

    if (query.format === "csv") {
      const csvBody = generateCsv(rows);
      return new NextResponse(csvBody, {
        status: 200,
        headers: {
          "Content-Type": getReportContentType(query.format),
          "Content-Disposition": `attachment; filename="${getReportFilename(query.period, query.format)}"`,
        },
      });
    }

    if (query.format === "pdf") {
      const pdfBuffer = await generatePdf(rows, "Urenstaat", `Periode: ${range.label}`);

      return new NextResponse(pdfBuffer as BodyInit, {
        status: 200,
        headers: {
          "Content-Type": getReportContentType(query.format),
          "Content-Disposition": `attachment; filename="${getReportFilename(query.period, query.format)}"`,
        },
      });
    }

    const xlsxBuffer = await generateXlsx(rows);

    return new NextResponse(xlsxBuffer as BodyInit, {
      status: 200,
      headers: {
        "Content-Type": getReportContentType(query.format),
        "Content-Disposition": `attachment; filename="${getReportFilename(query.period, query.format)}"`,
      },
    });
  } catch (error) {
    return jsonUnexpectedError(request, error, "Exporteren mislukt.");
  }
}
