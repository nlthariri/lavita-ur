import { NextResponse } from "next/server";

import { clearSession } from "@/lib/auth/session";
import { assertSameOrigin } from "@/lib/security/csrf";

export async function POST(request: Request) {
  assertSameOrigin(request);
  await clearSession();
  return NextResponse.json({ ok: true });
}
