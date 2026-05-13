"use client";

import { FormEvent, useEffect, useState } from "react";

import { readCsrfTokenFromCookie } from "@/lib/security/csrf-client";

type Objection = {
  id: string;
  status: "OPEN" | "APPROVED" | "REJECTED";
  motivation: string;
  managerResponse: string | null;
  submittedAt: string;
  workEntry: {
    id: string;
    entryDate: string;
    startAt: string;
    endAt: string;
    pauseMinutes: number;
    employee: {
      fullName: string;
    };
  };
};

export function BezwarenClient() {
  const [objections, setObjections] = useState<Objection[]>([]);
  const [selected, setSelected] = useState<Objection | null>(null);
  const [decision, setDecision] = useState<"APPROVED" | "REJECTED">("APPROVED");
  const [managerResponse, setManagerResponse] = useState("");
  const [correctionStartTime, setCorrectionStartTime] = useState("");
  const [correctionEndTime, setCorrectionEndTime] = useState("");
  const [correctionPauseMinutes, setCorrectionPauseMinutes] = useState("");
  const [correctionNote, setCorrectionNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  async function loadObjections() {
    try {
      const response = await fetch("/api/internal/objections");
      const data = await response.json();

      if (!response.ok) {
        setError(data.error ?? "Bezwaren laden mislukt.");
        return;
      }

      setObjections(data.objections ?? []);
    } catch {
      setError("Netwerkfout tijdens laden van bezwaren.");
    }
  }

  useEffect(() => {
    const timer = setTimeout(() => {
      void loadObjections();
    }, 0);

    return () => clearTimeout(timer);
  }, []);

  async function handleReview(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected) {
      return;
    }

    const response = await fetch(`/api/internal/objections/${selected.id}/review`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-csrf-token": readCsrfTokenFromCookie(),
      },
      body: JSON.stringify({
        decision,
        managerResponse,
        correction:
          decision === "APPROVED"
            ? {
                startTime: correctionStartTime || undefined,
                endTime: correctionEndTime || undefined,
                pauseMinutes: correctionPauseMinutes ? Number(correctionPauseMinutes) : undefined,
                note: correctionNote || undefined,
              }
            : undefined,
      }),
    });

    const data = await response.json();

    if (!response.ok) {
      setError(data.error ?? "Beoordelen mislukt.");
      return;
    }

    setMessage("Bezwaar is beoordeeld.");
    setManagerResponse("");
    setCorrectionStartTime("");
    setCorrectionEndTime("");
    setCorrectionPauseMinutes("");
    setCorrectionNote("");
    setSelected(null);
    await loadObjections();
  }

  return (
    <main className="mx-auto w-full max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
      <h1 className="mb-4 text-3xl font-bold tracking-tight text-[var(--primary)]">Bezwaren beoordelen</h1>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section className="card p-4">
          <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Open en afgehandelde bezwaren</h2>
          <div className="space-y-2">
            {objections.map((objection) => (
              <button
                key={objection.id}
                onClick={() => setSelected(objection)}
                className="w-full rounded-lg border border-[var(--border-soft)] bg-white p-3 text-left"
              >
                <p className="text-sm font-semibold text-zinc-800">{objection.workEntry.employee.fullName}</p>
                <p className="text-xs text-zinc-600">{new Date(objection.workEntry.entryDate).toLocaleDateString("nl-NL")}</p>
                <p className="mt-1 text-sm text-zinc-700">Status: {objection.status}</p>
              </button>
            ))}
          </div>
        </section>

        <section className="card p-4">
          <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Beoordeling</h2>
          {selected ? (
            <form onSubmit={handleReview} className="space-y-3">
              <p className="text-sm text-zinc-700">Medewerker: {selected.workEntry.employee.fullName}</p>
              <p className="text-sm text-zinc-700">Motivatie: {selected.motivation}</p>

              <select
                value={decision}
                onChange={(event) => setDecision(event.target.value as "APPROVED" | "REJECTED")}
                className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
              >
                <option value="APPROVED">Akkoord</option>
                <option value="REJECTED">Afwijzen</option>
              </select>

              <textarea
                required
                minLength={5}
                value={managerResponse}
                onChange={(event) => setManagerResponse(event.target.value)}
                className="min-h-24 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 py-2 text-sm"
              />

              {decision === "APPROVED" ? (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <input
                    type="time"
                    value={correctionStartTime}
                    onChange={(event) => setCorrectionStartTime(event.target.value)}
                    className="h-10 rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
                    placeholder="Nieuwe starttijd"
                  />
                  <input
                    type="time"
                    value={correctionEndTime}
                    onChange={(event) => setCorrectionEndTime(event.target.value)}
                    className="h-10 rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
                    placeholder="Nieuwe eindtijd"
                  />
                  <input
                    type="number"
                    min={0}
                    max={120}
                    value={correctionPauseMinutes}
                    onChange={(event) => setCorrectionPauseMinutes(event.target.value)}
                    className="h-10 rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
                    placeholder="Pauze minuten"
                  />
                  <input
                    type="text"
                    value={correctionNote}
                    onChange={(event) => setCorrectionNote(event.target.value)}
                    className="h-10 rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
                    placeholder="Correctienotitie"
                  />
                </div>
              ) : null}

              <button type="submit" className="h-10 rounded-lg bg-[var(--primary)] px-4 text-sm font-semibold text-white">
                Beoordeling opslaan
              </button>
            </form>
          ) : (
            <p className="text-sm text-zinc-600">Selecteer links een bezwaar om te beoordelen.</p>
          )}
        </section>
      </div>

      {message ? <p className="mt-3 text-sm text-green-700">{message}</p> : null}
      {error ? <p className="mt-3 text-sm text-red-700">{error}</p> : null}
    </main>
  );
}
