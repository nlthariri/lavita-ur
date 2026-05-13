import { redirect } from "next/navigation";

import { getSession } from "@/lib/auth/session";

export async function requireSession() {
  const session = await getSession();
  if (!session) {
    redirect("/login");
  }

  return session;
}

export async function requireRole(roles: Array<"OWNER" | "MANAGER" | "EMPLOYEE" | "ACCOUNTANT">) {
  const session = await requireSession();
  if (!roles.includes(session.role)) {
    redirect("/dashboard");
  }

  return session;
}
