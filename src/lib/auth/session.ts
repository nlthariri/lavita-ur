import { createHmac, timingSafeEqual } from "node:crypto";

import { cookies } from "next/headers";

import { db } from "@/lib/db";
import { getEnv } from "@/lib/env";

const SESSION_COOKIE = "lavita_session";
const SESSION_TTL_SECONDS = 60 * 60 * 2;

type SessionPayload = {
  userId: string;
  organizationId: string;
  role: "OWNER" | "MANAGER" | "EMPLOYEE" | "ACCOUNTANT";
  mfaVerified: boolean;
  sessionVersion: number;
  exp: number;
};

function toBase64Url(value: string): string {
  return Buffer.from(value, "utf-8").toString("base64url");
}

function sign(value: string): string {
  const env = getEnv();
  return createHmac("sha256", env.AUTH_SESSION_SECRET).update(value).digest("base64url");
}

function serialize(payload: SessionPayload): string {
  const body = toBase64Url(JSON.stringify(payload));
  const signature = sign(body);
  return `${body}.${signature}`;
}

function parse(token: string): SessionPayload | null {
  const [body, signature] = token.split(".");
  if (!body || !signature) {
    return null;
  }

  const expected = sign(body);
  if (signature.length !== expected.length) {
    return null;
  }

  const valid = timingSafeEqual(Buffer.from(signature), Buffer.from(expected));
  if (!valid) {
    return null;
  }

  try {
    const parsed = JSON.parse(Buffer.from(body, "base64url").toString("utf-8")) as SessionPayload;
    if (parsed.exp < Date.now()) {
      return null;
    }

    return parsed;
  } catch {
    return null;
  }
}

export async function setSession(input: Omit<SessionPayload, "exp">): Promise<void> {
  const cookieStore = await cookies();
  const payload: SessionPayload = {
    ...input,
    exp: Date.now() + SESSION_TTL_SECONDS * 1000,
  };

  cookieStore.set(SESSION_COOKIE, serialize(payload), {
    httpOnly: true,
    secure: process.env.NODE_ENV === "production",
    sameSite: "strict",
    path: "/",
    maxAge: SESSION_TTL_SECONDS,
  });
}

export async function clearSession(): Promise<void> {
  const cookieStore = await cookies();
  cookieStore.delete(SESSION_COOKIE);
}

export async function getSession(): Promise<SessionPayload | null> {
  const cookieStore = await cookies();
  const token = cookieStore.get(SESSION_COOKIE)?.value;
  if (!token) {
    return null;
  }

  const parsed = parse(token);
  if (!parsed) {
    return null;
  }

  const user = await db.user.findUnique({
    where: { id: parsed.userId },
    select: {
      id: true,
      isActive: true,
      role: true,
      organizationId: true,
      sessionVersion: true,
    },
  });

  if (!user || !user.isActive) {
    return null;
  }

  if (user.organizationId !== parsed.organizationId || user.role !== parsed.role) {
    return null;
  }

  if (user.sessionVersion !== parsed.sessionVersion) {
    return null;
  }

  return parsed;
}
