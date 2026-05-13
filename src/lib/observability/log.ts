type LogLevel = "info" | "warn" | "error";

type LogEntry = {
  level: LogLevel;
  event: string;
  message: string;
  requestId?: string;
  context?: Record<string, unknown>;
};

function write(entry: LogEntry): void {
  const payload = {
    ts: new Date().toISOString(),
    ...entry,
  };

  if (entry.level === "error") {
    console.error(JSON.stringify(payload));
    return;
  }

  if (entry.level === "warn") {
    console.warn(JSON.stringify(payload));
    return;
  }

  console.info(JSON.stringify(payload));
}

export function logInfo(event: string, message: string, context?: Record<string, unknown>, requestId?: string): void {
  write({ level: "info", event, message, context, requestId });
}

export function logWarn(event: string, message: string, context?: Record<string, unknown>, requestId?: string): void {
  write({ level: "warn", event, message, context, requestId });
}

export function logError(event: string, message: string, context?: Record<string, unknown>, requestId?: string): void {
  write({ level: "error", event, message, context, requestId });
}
