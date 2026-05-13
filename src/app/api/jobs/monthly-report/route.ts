import { NextResponse } from "next/server";

import { getSession } from "@/lib/auth/session";
import { db } from "@/lib/db";
import { queueAndSendEmail } from "@/lib/email/service";
import { getPreviousMonthRange, generatePdf, listWorkEntryReportEntries, toReportRows } from "@/lib/reports/work-entries";
import { assertSameOrigin } from "@/lib/security/csrf";
import { jsonUnexpectedError } from "@/lib/security/error-handler";
import { getClientIp } from "@/lib/security/request";
import { checkRateLimit } from "@/lib/security/rate-limit";

export async function POST(request: Request) {
  try {
    assertSameOrigin(request);

    const session = await getSession();
    if (!session) {
      return NextResponse.json({ error: "Niet ingelogd." }, { status: 401 });
    }

    if (session.role !== "OWNER" && session.role !== "MANAGER") {
      return NextResponse.json({ error: "Onvoldoende rechten." }, { status: 403 });
    }

    if (!session.mfaVerified) {
      return NextResponse.json({ error: "MFA-verificatie is vereist." }, { status: 403 });
    }

    const ip = await getClientIp();
    if (!ip) {
      return NextResponse.json({ error: "IP-adres kon niet worden bepaald." }, { status: 503 });
    }

    const allowed = await checkRateLimit(`monthly-report:${ip}`, 4, 60_000);
    if (!allowed) {
      return NextResponse.json({ error: "Te veel rapportageverzoeken. Probeer later opnieuw." }, { status: 429 });
    }

    const range = getPreviousMonthRange();
    const recipients = await db.user.findMany({
      where: {
        isActive: true,
        organizationId: session.organizationId,
        role: { in: ["OWNER", "MANAGER"] },
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
      const entries = await listWorkEntryReportEntries(
        {
          organizationId: recipient.organizationId,
          role: recipient.role,
          userId: recipient.id,
        },
        range,
      );
      const rows = toReportRows(entries);
      const pdf = await generatePdf(rows, "Maandrapportage urenstaat", `Periode: ${range.label}`);

      await queueAndSendEmail({
        organizationId: recipient.organizationId,
        recipient: recipient.email,
        userId: recipient.id,
        type: "MONTHLY_REPORT",
        variables: {
          periode: range.label,
          naam: recipient.fullName,
        },
        attachments: [
          {
            filename: `maandrapportage-${range.label.replaceAll(" ", "-")}.pdf`,
            content: pdf,
            contentType: "application/pdf",
          },
        ],
      });
    }

    return NextResponse.redirect(new URL("/dashboard", request.url));
  } catch (error) {
    return jsonUnexpectedError(request, error, "Maandrapportage versturen mislukt.");
  }
}