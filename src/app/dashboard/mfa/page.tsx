import { MfaClient } from "@/components/dashboard/mfa-client";
import { requireRole } from "@/lib/auth/guards";

export default async function MfaPage() {
  await requireRole(["OWNER", "MANAGER"]);

  return <MfaClient />;
}
