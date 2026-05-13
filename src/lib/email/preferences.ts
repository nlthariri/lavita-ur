import { createHmac, timingSafeEqual } from "node:crypto";

import { EmailEventType } from "@prisma/client";

import { db } from "@/lib/db";
import { getEnv } from "@/lib/env";

const OPTIONAL_EMAIL_TYPES = new Set<EmailEventType>([
  "HOURS_REGISTERED",
  "MISSING_ENTRY_REMINDER",
  "ATW_LIMIT_WARNING",
  "MONTHLY_REPORT",
]);

type PreferenceKey =
  | "allowHoursRegistered"
  | "allowMissingEntryReminder"
  | "allowAtwLimitWarning"
  | "allowMonthlyReport";

const PREFERENCE_BY_TYPE: Partial<Record<EmailEventType, PreferenceKey>> = {
  HOURS_REGISTERED: "allowHoursRegistered",
  MISSING_ENTRY_REMINDER: "allowMissingEntryReminder",
  ATW_LIMIT_WARNING: "allowAtwLimitWarning",
  MONTHLY_REPORT: "allowMonthlyReport",
};

type UnsubscribePayload = {
  userId: string;
  type: EmailEventType;
  exp: number;
  sig: string;
};

function sign(value: string): string {
  const env = getEnv();
  return createHmac("sha256", env.AUTH_SESSION_SECRET).update(value).digest("base64url");
}

function toToken(payload: UnsubscribePayload): string {
  return Buffer.from(JSON.stringify(payload), "utf-8").toString("base64url");
}

function fromToken(token: string): UnsubscribePayload {
  const raw = Buffer.from(token, "base64url").toString("utf-8");
  const parsed = JSON.parse(raw) as Partial<UnsubscribePayload>;

  if (!parsed.userId || !parsed.type || !parsed.exp || !parsed.sig) {
    throw new Error("Ongeldige afmeldlink.");
  }

  return {
    userId: parsed.userId,
    type: parsed.type,
    exp: parsed.exp,
    sig: parsed.sig,
  };
}

export function isOptionalEmailType(type: EmailEventType): boolean {
  return OPTIONAL_EMAIL_TYPES.has(type);
}

export async function canReceiveEmail(userId: string | undefined, type: EmailEventType): Promise<boolean> {
  if (!userId) {
    return true;
  }

  if (!isOptionalEmailType(type)) {
    return true;
  }

  const preferences = await db.userEmailPreference.findUnique({ where: { userId } });
  if (!preferences) {
    return true;
  }

  const key = PREFERENCE_BY_TYPE[type];
  if (!key) {
    return true;
  }

  return preferences[key] === true;
}

export function createUnsubscribeUrl(userId: string, type: EmailEventType): string {
  const env = getEnv();
  const exp = Date.now() + 1000 * 60 * 60 * 24 * 30;
  const signatureBase = `${userId}.${type}.${exp}`;
  const token = toToken({
    userId,
    type,
    exp,
    sig: sign(signatureBase),
  });

  return `${env.APP_BASE_URL}/api/email/unsubscribe?token=${encodeURIComponent(token)}`;
}

export async function unsubscribeFromToken(token: string): Promise<{ userId: string; type: EmailEventType }> {
  const payload = fromToken(token);
  const expected = sign(`${payload.userId}.${payload.type}.${payload.exp}`);
  if (payload.sig.length !== expected.length) {
    throw new Error("Ongeldige afmeldlink.");
  }

  const valid = timingSafeEqual(Buffer.from(payload.sig), Buffer.from(expected));
  if (!valid) {
    throw new Error("Ongeldige afmeldlink.");
  }

  if (payload.exp < Date.now()) {
    throw new Error("Afmeldlink is verlopen.");
  }

  if (!isOptionalEmailType(payload.type)) {
    throw new Error("Dit e-mailtype is verplicht en kan niet worden uitgeschakeld.");
  }

  const key = PREFERENCE_BY_TYPE[payload.type];
  if (!key) {
    throw new Error("Onbekend e-mailtype voor afmelding.");
  }

  await db.userEmailPreference.upsert({
    where: { userId: payload.userId },
    update: {
      [key]: false,
    },
    create: {
      userId: payload.userId,
      [key]: false,
    },
  });

  return {
    userId: payload.userId,
    type: payload.type,
  };
}
