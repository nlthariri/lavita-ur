import { startOfWeek, addDays, format } from "date-fns";

import { requireRole } from "@/lib/auth/guards";
import { db } from "@/lib/db";

export default async function WeekoverzichtPage() {
  const session = await requireRole(["OWNER", "MANAGER"]);

  const manager = await db.user.findUnique({
    where: { id: session.userId },
    select: { teamId: true },
  });

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

  const weekStart = startOfWeek(new Date(), { weekStartsOn: 1 });
  const dates = Array.from({ length: 7 }).map((_, index) => addDays(weekStart, index));

  const entries = await db.workEntry.findMany({
    where: {
      organizationId: session.organizationId,
      entryDate: {
        gte: weekStart,
        lt: addDays(weekStart, 7),
      },
      ...(session.role === "MANAGER" ? { teamId: manager?.teamId ?? undefined } : {}),
    },
    select: {
      employeeId: true,
      entryDate: true,
      objections: {
        select: { status: true },
      },
    },
  });

  const statusFor = (employeeId: string, day: Date) => {
    const sameDay = entries.filter(
      (entry) => entry.employeeId === employeeId && entry.entryDate.toDateString() === day.toDateString(),
    );

    if (sameDay.length === 0) return "concept";
    if (sameDay.some((entry) => entry.objections.some((objection) => objection.status === "OPEN"))) return "bezwaar";
    return "vastgesteld";
  };

  return (
    <main className="mx-auto w-full max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
      <h1 className="mb-4 text-3xl font-bold tracking-tight text-[var(--primary)]">Weekoverzicht</h1>
      <div className="overflow-x-auto rounded-xl border border-[var(--border-soft)]">
        <table className="min-w-full bg-white text-sm">
          <thead className="bg-[var(--surface-card)]">
            <tr>
              <th className="px-4 py-3 text-left">Medewerker</th>
              {dates.map((date) => (
                <th key={date.toISOString()} className="px-4 py-3 text-left">
                  {format(date, "EEE dd-MM")}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {employees.map((employee) => (
              <tr key={employee.id} className="border-t border-[var(--border-soft)]">
                <td className="px-4 py-3 font-medium text-zinc-800">{employee.fullName}</td>
                {dates.map((date) => {
                  const status = statusFor(employee.id, date);
                  const className =
                    status === "vastgesteld"
                      ? "badge-vastgesteld"
                      : status === "bezwaar"
                        ? "badge-bezwaar"
                        : "badge-concept";

                  return (
                    <td key={date.toISOString()} className="px-4 py-3">
                      <span className={`${className} rounded-full px-2 py-1 text-xs font-semibold`}>
                        {status}
                      </span>
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </main>
  );
}
