import bcrypt from "bcryptjs";
import { verify } from "otplib";

import { db } from "@/lib/db";

type LoginInput = {
  email: string;
  password: string;
  totpCode?: string;
};

function requiresMfa(): boolean {
  return true;
}

export async function loginWithPassword(input: LoginInput) {
  const email = input.email.toLowerCase().trim();
  const user = await db.user.findUnique({ where: { email } });

  if (!user || !user.isActive || !user.passwordHash) {
    throw new Error("Ongeldige inloggegevens.");
  }

  const ok = await bcrypt.compare(input.password, user.passwordHash);
  if (!ok) {
    throw new Error("Ongeldige inloggegevens.");
  }

  let mfaVerified = true;

  if (requiresMfa()) {
    if (!user.mfaEnabled || !user.mfaSecret) {
      throw new Error("MFA is verplicht voor deze rol. Stel MFA eerst in via een beheerder.");
    } else {
      if (!input.totpCode) {
        throw new Error("MFA-code ontbreekt.");
      }

      const validTotp = verify({ token: input.totpCode, secret: user.mfaSecret });
      if (!validTotp) {
        throw new Error("Ongeldige MFA-code.");
      }

      mfaVerified = true;
    }
  }

  return {
    id: user.id,
    organizationId: user.organizationId,
    role: user.role,
    mfaVerified,
    sessionVersion: user.sessionVersion,
  };
}
