import { NextResponse } from "next/server";

import { unsubscribeFromToken } from "@/lib/email/preferences";

function escapeHtml(value: string): string {
  return value
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#x27;");
}

export async function GET(request: Request) {
  try {
    const url = new URL(request.url);
    const token = url.searchParams.get("token");

    if (!token) {
      return new NextResponse("Afmeldlink ontbreekt.", { status: 400 });
    }

    const result = await unsubscribeFromToken(token);
    const safeType = escapeHtml(result.type);

    const html = `<!doctype html>
<html lang="nl">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Afmelding voltooid</title>
    <style>
      body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 24px; }
      .card { max-width: 640px; margin: 0 auto; background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; }
      h1 { margin-top: 0; font-size: 24px; }
      p { line-height: 1.5; }
    </style>
  </head>
  <body>
    <main class="card">
      <h1>Afmelding verwerkt</h1>
      <p>Je bent afgemeld voor e-mailtype: <strong>${safeType}</strong>.</p>
      <p>Je kunt dit later weer inschakelen via je accountbeheerder.</p>
    </main>
  </body>
</html>`;

    return new NextResponse(html, {
      status: 200,
      headers: {
        "Content-Type": "text/html; charset=utf-8",
      },
    });
  } catch {
    return new NextResponse("Afmelden mislukt.", { status: 400 });
  }
}
