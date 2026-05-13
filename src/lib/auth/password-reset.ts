import { createHmac, timingSafeEqual } from "node:crypto";

import bcrypt from "bcryptjs";

import { db } from "@/lib/db";
import { getEnv } from "@/lib/env";

const PASSWORD_RESET_TTL_MS = 24 * 60 * 60 * 1000;

type PasswordResetPayload = {
  userId: string;
  exp: number;
  sig: string;
};

function signValue(value: string): string {
  const env = getEnv();
  return createHmac("sha256", env.AUTH_SESSION_SECRET).update(value).digest("base64url");
}

function toToken(payload: PasswordResetPayload): string {
  return Buffer.from(JSON.stringify(payload), "utf-8").toString("base64url");
}

function fromToken(token: string): PasswordResetPayload {
  const raw = Buffer.from(token, "base64url").toString("utf-8");
  const parsed = JSON.parse(raw) as Partial<PasswordResetPayload>;

  if (!parsed.userId || !parsed.exp || !parsed.sig) {
    throw new Error("Resetlink is ongeldig.");
  }

  return {
    userId: parsed.userId,
    exp: parsed.exp,
    sig: parsed.sig,
  };
}

export async function createPasswordResetToken(userId: string): Promise<string> {
  const user = await db.user.findUnique({
    where: { id: userId },
    select: { id: true, passwordHash: true, isActive: true },
  });

  if (!user || !user.isActive || !user.passwordHash) {
    throw new Error("Gebruiker bestaat niet voor reset.");
  }

  const exp = Date.now() + PASSWORD_RESET_TTL_MS;
  const signatureBase = `${user.id}.${exp}.${user.passwordHash}`;

  return toToken({
    userId: user.id,
    exp,
    sig: signValue(signatureBase),
  });
}

export async function resetPasswordWithToken(token: string, newPassword: string): Promise<void> {
  const payload = fromToken(token);

  if (payload.exp < Date.now()) {
    throw new Error("Resetlink is verlopen.");
  }

  const user = await db.user.findUnique({
    where: { id: payload.userId },
    select: { id: true, passwordHash: true, isActive: true },
  });

  if (!user || !user.isActive || !user.passwordHash) {
    throw new Error("Resetlink is ongeldig.");
  }

  const expected = signValue(`${user.id}.${payload.exp}.${user.passwordHash}`);
  if (payload.sig.length !== expected.length) {
    throw new Error("Resetlink is ongeldig.");
  }

  const valid = timingSafeEqual(Buffer.from(payload.sig), Buffer.from(expected));
  if (!valid) {
    throw new Error("Resetlink is ongeldig.");
  }

  const sameAsOld = await bcrypt.compare(newPassword, user.passwordHash);
  if (sameAsOld) {
    throw new Error("Kies een nieuw wachtwoord dat verschilt van het huidige wachtwoord.");
  }

  const passwordHash = await bcrypt.hash(newPassword, 12);
  await db.user.update({
    where: { id: user.id },
    data: {
      passwordHash,
    },
  });
}
