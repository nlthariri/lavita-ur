"use client";

import { FormEvent, useCallback, useEffect, useState } from "react";
import { endOfMonth, endOfWeek, startOfMonth, startOfWeek } from "date-fns";

import { readCsrfTokenFromCookie } from "@/lib/security/csrf-client";

type WorkEntry = {
  id: string;
  entryDate: string;
  startAt: string;
  endAt: string;
  pauseMinutes: number;
  netMinutes: number;
  objections: Array<{ id: string; status: string; motivation: string }>;
};

export function MijnUrenClient() {
  const [entries, setEntries] = useState<WorkEntry[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [selectedEntryId, setSelectedEntryId] = useState<string | null>(null);
  const [motivation, setMotivation] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [period, setPeriod] = useState<"week" | "month">("week");

  function getRangeForPeriod(value: "week" | "month") {
    const now = new Date();
    if (value === "week") {
      return {
        start: startOfWeek(now, { weekStartsOn: 1 }).toISOString(),
        end: endOfWeek(now, { weekStartsOn: 1 }).toISOString(),
      };
    }

    return {
      start: startOfMonth(now).toISOString(),
      end: endOfMonth(now).toISOString(),
    };
  }

  const loadEntries = useCallback(async () => {
    try {
      const range = getRangeForPeriod(period);
      const params = new URLSearchParams({
        weekStart: range.start,
        weekEnd: range.end,
      });

      const response = await fetch(`/api/internal/work-entries?${params.toString()}`);
      const data = await response.json();

      if (!response.ok) {
        setError(data.error ?? "Uren laden mislukt.");
        return;
      }

      setEntries(data.entries ?? []);
    } catch {
      setError("Netwerkfout tijdens laden van uren.");
    }
  }, [period]);

  useEffect(() => {
    const timer = setTimeout(() => {
      void loadEntries();
    }, 0);

    return () => clearTimeout(timer);
  }, [loadEntries]);

  async function handleSubmitObjection(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setMessage(null);

    if (!selectedEntryId) {
      setError("Selecteer eerst een urenregel.");
      return;
    }

    const response = await fetch("/api/internal/objections", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-csrf-token": readCsrfTokenFromCookie(),
      },
      body: JSON.stringify({
        workEntryId: selectedEntryId,
        motivation,
      }),
    });

    const data = await response.json();

    if (!response.ok) {
      setError(data.error ?? "Bezwaar indienen mislukt.");
      return;
    }

    setMessage("Bezwaar is ingediend.");
    setMotivation("");
    setSelectedEntryId(null);
    await loadEntries();
  }

  function downloadExport(format: "csv" | "xlsx" | "pdf") {
    const params = new URLSearchParams({
      period,
      format,
    });
    window.open(`/api/internal/reports/work-entries?${params.toString()}`, "_blank", "noopener,noreferrer");
  }

  return (
    <main className="mx-auto w-full max-w-5xl px-5 py-8 sm:px-8 sm:py-12">
      <h1 className="mb-4 text-3xl font-bold tracking-tight text-[var(--primary)]">Mijn urenstaat</h1>

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <select
          value={period}
          onChange={(event) => setPeriod(event.target.value as "week" | "month")}
          className="h-10 rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
        >
          <option value="week">Huidige week</option>
          <option value="month">Huidige maand</option>
        </select>

        <button
          type="button"
          onClick={() => downloadExport("xlsx")}
          className="h-10 rounded-lg border border-[var(--border-soft)] px-4 text-sm font-semibold"
        >
          Exporteer Excel
        </button>

        <button
          type="button"
          onClick={() => downloadExport("csv")}
          className="h-10 rounded-lg border border-[var(--border-soft)] px-4 text-sm font-semibold"
        >
          Exporteer CSV
        </button>

        <button
          type="button"
          onClick={() => downloadExport("pdf")}
          className="h-10 rounded-lg border border-[var(--border-soft)] px-4 text-sm font-semibold"
        >
          Exporteer PDF
        </button>
      </div>

      <div className="overflow-x-auto rounded-xl border border-[var(--border-soft)] bg-white">
        <table className="min-w-full text-sm">
          <thead className="bg-[var(--surface-card)]">
            <tr>
              <th className="px-4 py-3 text-left">Datum</th>
              <th className="px-4 py-3 text-left">Start</th>
              <th className="px-4 py-3 text-left">Einde</th>
              <th className="px-4 py-3 text-left">Pauze</th>
              <th className="px-4 py-3 text-left">Netto uren</th>
              <th className="px-4 py-3 text-left">Bezwaarstatus</th>
            </tr>
          </thead>
          <tbody>
            {entries.map((entry) => {
              const openObjection = entry.objections.find((objection) => objection.status === "OPEN");
              const status = openObjection ? "OPEN" : entry.objections[0]?.status ?? "GEEN";

              return (
                <tr
                  key={entry.id}
                  className={`border-t border-[var(--border-soft)] ${selectedEntryId === entry.id ? "bg-zinc-50" : ""}`}
                  onClick={() => setSelectedEntryId(entry.id)}
                >
                  <td className="px-4 py-3">{new Date(entry.entryDate).toLocaleDateString("nl-NL")}</td>
                  <td className="px-4 py-3">{new Date(entry.startAt).toLocaleTimeString("nl-NL", { hour: "2-digit", minute: "2-digit" })}</td>
                  <td className="px-4 py-3">{new Date(entry.endAt).toLocaleTimeString("nl-NL", { hour: "2-digit", minute: "2-digit" })}</td>
                  <td className="px-4 py-3">{entry.pauseMinutes} min</td>
                  <td className="px-4 py-3">{(entry.netMinutes / 60).toFixed(2)}</td>
                  <td className="px-4 py-3">{status}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <form onSubmit={handleSubmitObjection} className="card mt-4 p-5">
        <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Bezwaar indienen</h2>
        <p className="mb-3 text-sm text-zinc-700">Selecteer een urenregel en geef een duidelijke motivatie.</p>

        <textarea
          required
          minLength={10}
          value={motivation}
          onChange={(event) => setMotivation(event.target.value)}
          className="mb-3 min-h-24 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 py-2 text-sm"
        />

        <button type="submit" className="h-10 rounded-lg bg-[var(--primary)] px-4 text-sm font-semibold text-white">
          Bezwaar versturen
        </button>
      </form>

      {message ? <p className="mt-3 text-sm text-green-700">{message}</p> : null}
      {error ? <p className="mt-3 text-sm text-red-700">{error}</p> : null}
    </main>
  );
}
