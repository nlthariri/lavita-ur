import { startOfWeek, addDays } from "date-fns";

import { requireRole } from "@/lib/auth/guards";
import { db } from "@/lib/db";

export default async function AtwDashboardPage() {
  const session = await requireRole(["OWNER", "MANAGER"]);

  const manager = await db.user.findUnique({
    where: { id: session.userId },
    select: { teamId: true },
  });

  const weekStart = startOfWeek(new Date(), { weekStartsOn: 1 });
  const weekEnd = addDays(weekStart, 7);

  const employees = await db.user.findMany({
    where: {
      organizationId: session.organizationId,
      role: "EMPLOYEE",
      ...(session.role === "MANAGER" ? { teamId: manager?.teamId ?? undefined } : {}),
    },
    select: {
      id: true,
      fullName: true,
    },
    orderBy: { fullName: "asc" },
  });

  const entries = await db.workEntry.findMany({
    where: {
      organizationId: session.organizationId,
      entryDate: {
        gte: weekStart,
        lt: weekEnd,
      },
      ...(session.role === "MANAGER" ? { teamId: manager?.teamId ?? undefined } : {}),
    },
    select: {
      employeeId: true,
      netMinutes: true,
    },
  });

  const weekMinutesByEmployee = new Map<string, number>();
  for (const entry of entries) {
    weekMinutesByEmployee.set(entry.employeeId, (weekMinutesByEmployee.get(entry.employeeId) ?? 0) + entry.netMinutes);
  }

  return (
    <main className="mx-auto w-full max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
      <h1 className="mb-4 text-3xl font-bold tracking-tight text-[var(--primary)]">ATW-dashboard</h1>
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {employees.map((employee) => {
          const weekMinutes = weekMinutesByEmployee.get(employee.id) ?? 0;
          const hours = (weekMinutes / 60).toFixed(2);
          const status = weekMinutes >= 3600 ? "Kritiek" : weekMinutes >= 2880 ? "Waarschuwing" : "Normaal";
          const badgeClass =
            status === "Kritiek" ? "badge-bezwaar" : status === "Waarschuwing" ? "badge-concept" : "badge-vastgesteld";

          return (
            <article key={employee.id} className="card p-5">
              <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">{employee.fullName}</h2>
              <p className="mb-3 text-sm text-zinc-700">Weekuren: {hours}</p>
              <span className={`${badgeClass} rounded-full px-2 py-1 text-xs font-semibold`}>{status}</span>
            </article>
          );
        })}
      </div>
    </main>
  );
}
