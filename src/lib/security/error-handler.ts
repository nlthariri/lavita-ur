import { NextResponse } from "next/server";

import { logError } from "@/lib/observability/log";

function getRequestId(request: Request): string | undefined {
  return request.headers.get("x-request-id") ?? undefined;
}

export function jsonError(request: Request, status: number, publicMessage: string, internalMessage?: string) {
  const requestId = getRequestId(request);

  if (internalMessage) {
    logError("api.request.failed", internalMessage, { status }, requestId);
  }

  return NextResponse.json(
    {
      error: publicMessage,
      requestId,
    },
    { status },
  );
}

export function jsonUnexpectedError(request: Request, error: unknown, publicMessage = "Verzoek kon niet worden verwerkt.") {
  const requestId = getRequestId(request);
  const internalMessage = error instanceof Error ? error.message : String(error);

  logError("api.request.exception", internalMessage, {}, requestId);

  return NextResponse.json(
    {
      error: publicMessage,
      requestId,
    },
    { status: 400 },
  );
}
