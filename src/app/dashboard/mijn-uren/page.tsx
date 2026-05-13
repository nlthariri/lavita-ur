import { MijnUrenClient } from "@/components/dashboard/mijn-uren-client";
import { requireSession } from "@/lib/auth/guards";

export default async function MijnUrenPage() {
  await requireSession();

  return <MijnUrenClient />;
}
