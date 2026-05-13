"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";

import { readCsrfTokenFromCookie } from "@/lib/security/csrf-client";

type Employee = {
  id: string;
  fullName: string;
  email: string;
};

type AtwSignal = {
  type: "DAILY_LIMIT" | "WEEKLY_WARNING" | "WEEKLY_LIMIT" | "SIXTEEN_WEEK_AVERAGE" | "REST_PERIOD";
  severity: "warning" | "critical";
  message: string;
  thresholdMinutes: number;
  currentMinutes: number;
};

const pauseOptions = [0, 30, 45, 60];
const CUID_PATTERN = /^c[a-z0-9]{24}$/i;

export default function UrenInvoerPage() {
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [employeeId, setEmployeeId] = useState("");
  const [entryDate, setEntryDate] = useState(() => new Date().toISOString().slice(0, 10));
  const [startTime, setStartTime] = useState("09:00");
  const [endTime, setEndTime] = useState("17:00");
  const [pauseMinutes, setPauseMinutes] = useState(30);
  const [customPause, setCustomPause] = useState(false);
  const [projectId, setProjectId] = useState("");
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [signals, setSignals] = useState<AtwSignal[]>([]);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    async function loadEmployees() {
      const response = await fetch("/api/internal/users/employees");
      const data = await response.json();
      if (!response.ok) {
        setError(data.error ?? "Medewerkers laden mislukt.");
        return;
      }

      setEmployees(data.users);
      if (data.users.length > 0) {
        setEmployeeId(data.users[0].id);
      }
    }

    loadEmployees();
  }, []);

  const netMinutes = useMemo(() => {
    const [startHour, startMinute] = startTime.split(":").map(Number);
    const [endHour, endMinute] = endTime.split(":").map(Number);
    if ([startHour, startMinute, endHour, endMinute].some((value) => Number.isNaN(value))) {
      return 0;
    }

    const start = startHour * 60 + startMinute;
    const end = endHour * 60 + endMinute;
    return Math.max(0, end - start - pauseMinutes);
  }, [startTime, endTime, pauseMinutes]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setSaving(true);
    setSignals([]);

    const response = await fetch("/api/internal/work-entries", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-csrf-token": readCsrfTokenFromCookie(),
      },
      body: JSON.stringify({
        employeeId,
        entryDate,
        startTime,
        endTime,
        pauseMinutes,
        projectId: projectId && CUID_PATTERN.test(projectId) ? projectId : undefined,
        note: note || undefined,
      }),
    });

    const data = await response.json();
    setSaving(false);

    if (!response.ok) {
      setError(data.error ?? "Uren opslaan mislukt.");
      return;
    }

    setSignals(data.atwSignals ?? []);
    setNote("");
  }

  return (
    <main className="mx-auto w-full max-w-3xl px-5 py-8 sm:px-8 sm:py-12">
      <h1 className="mb-4 text-3xl font-bold tracking-tight text-[var(--primary)]">Uren invoeren</h1>

      <form onSubmit={handleSubmit} className="card space-y-4 p-5 sm:p-6">
        <div>
          <label htmlFor="employee" className="mb-1 block text-sm font-medium text-zinc-700">
            Medewerker
          </label>
          <select
            id="employee"
            className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
            value={employeeId}
            onChange={(event) => setEmployeeId(event.target.value)}
            required
          >
            {employees.map((employee) => (
              <option key={employee.id} value={employee.id}>
                {employee.fullName}
              </option>
            ))}
          </select>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <label htmlFor="entryDate" className="mb-1 block text-sm font-medium text-zinc-700">
              Datum
            </label>
            <input
              id="entryDate"
              type="date"
              className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
              value={entryDate}
              onChange={(event) => setEntryDate(event.target.value)}
              required
            />
          </div>
          <div>
            <label htmlFor="project" className="mb-1 block text-sm font-medium text-zinc-700">
                Project-ID (optioneel)
            </label>
            <input
              id="project"
              type="text"
              placeholder="c..."
              className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
              value={projectId}
              onChange={(event) => setProjectId(event.target.value)}
            />
          </div>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div>
            <label htmlFor="startTime" className="mb-1 block text-sm font-medium text-zinc-700">
              Begintijd
            </label>
            <input
              id="startTime"
              type="time"
              className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
              value={startTime}
              onChange={(event) => setStartTime(event.target.value)}
              required
            />
          </div>
          <div>
            <label htmlFor="endTime" className="mb-1 block text-sm font-medium text-zinc-700">
              Eindtijd
            </label>
            <input
              id="endTime"
              type="time"
              className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
              value={endTime}
              onChange={(event) => setEndTime(event.target.value)}
              required
            />
          </div>
          <div>
            <label htmlFor="pause" className="mb-1 block text-sm font-medium text-zinc-700">
              Pauze
            </label>
            <select
              id="pause"
              className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
              value={customPause ? "custom" : String(pauseMinutes)}
              onChange={(event) => {
                if (event.target.value === "custom") {
                  setCustomPause(true);
                  return;
                }

                setCustomPause(false);
                setPauseMinutes(Number(event.target.value));
              }}
            >
              {pauseOptions.map((value) => (
                <option key={value} value={value}>
                  {value === 0 ? "Geen pauze" : `${value} minuten`}
                </option>
              ))}
              <option value="custom">Eigen invoer</option>
            </select>
          </div>
        </div>

        {customPause ? (
          <div>
            <label htmlFor="customPause" className="mb-1 block text-sm font-medium text-zinc-700">
              Eigen pauze (minuten)
            </label>
            <input
              id="customPause"
              type="number"
              min={0}
              max={120}
              value={pauseMinutes}
              onChange={(event) => setPauseMinutes(Number(event.target.value))}
              className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
            />
          </div>
        ) : null}

        <div>
          <label htmlFor="note" className="mb-1 block text-sm font-medium text-zinc-700">
            Notitie (optioneel)
          </label>
          <textarea
            id="note"
            className="min-h-24 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 py-2 text-sm"
            value={note}
            onChange={(event) => setNote(event.target.value)}
            maxLength={500}
          />
        </div>

        <p className="text-sm font-semibold text-zinc-800">Netto werktijd: {(netMinutes / 60).toFixed(2)} uur</p>

        {error ? <p className="text-sm text-red-700">{error}</p> : null}

        <button type="submit" disabled={saving} className="h-10 rounded-lg bg-[var(--primary)] px-5 text-sm font-semibold text-white">
          {saving ? "Opslaan..." : "Uren vaststellen"}
        </button>
      </form>

      {signals.length > 0 ? (
        <section className="mt-4 space-y-2">
          {signals.map((signal, index) => (
            <article key={`${signal.type}-${index}`} className="card p-4">
              <p className="text-sm font-semibold text-[var(--primary)]">{signal.type}</p>
              <p className="text-sm text-zinc-700">{signal.message}</p>
            </article>
          ))}
        </section>
      ) : null}
    </main>
  );
}
