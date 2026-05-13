import { GebruikersClient } from "@/components/dashboard/gebruikers-client";
import { requireRole } from "@/lib/auth/guards";

export default async function GebruikersPage() {
  await requireRole(["OWNER", "MANAGER"]);

  return <GebruikersClient />;
}
