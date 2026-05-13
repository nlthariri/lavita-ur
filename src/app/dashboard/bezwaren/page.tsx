import { BezwarenClient } from "@/components/dashboard/bezwaren-client";
import { requireRole } from "@/lib/auth/guards";

export default async function BezwarenPage() {
  await requireRole(["OWNER", "MANAGER"]);

  return <BezwarenClient />;
}
