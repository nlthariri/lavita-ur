"use client";

import { FormEvent, useState } from "react";

import { readCsrfTokenFromCookie } from "@/lib/security/csrf-client";

export default function WachtwoordVergetenPage() {
  const [email, setEmail] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage(null);
    setError(null);
    setLoading(true);

    const response = await fetch("/api/auth/password-reset/request", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-csrf-token": readCsrfTokenFromCookie(),
      },
      body: JSON.stringify({ email }),
    });

    const data = await response.json();
    setLoading(false);

    if (!response.ok) {
      setError(data.error ?? "Resetaanvraag mislukt.");
      return;
    }

    setMessage(data.message ?? "Als dit e-mailadres bestaat, ontvang je een resetlink.");
  }

  return (
    <main className="mx-auto flex min-h-screen w-full max-w-md items-center px-5 py-10">
      <form onSubmit={handleSubmit} className="card w-full p-6 sm:p-8" aria-label="Wachtwoord vergeten formulier">
        <h1 className="mb-1 text-2xl font-bold text-[var(--primary)]">Wachtwoord vergeten</h1>
        <p className="mb-6 text-sm text-zinc-600">Voer je e-mailadres in en ontvang een resetlink (24 uur geldig).</p>

        <label className="mb-2 block text-sm font-medium text-zinc-700" htmlFor="email">
          E-mailadres
        </label>
        <input
          id="email"
          type="email"
          required
          className="mb-4 h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
          value={email}
          onChange={(event) => setEmail(event.target.value)}
        />

        {message ? <p className="mb-4 text-sm text-green-700">{message}</p> : null}
        {error ? <p className="mb-4 text-sm text-red-700">{error}</p> : null}

        <button
          type="submit"
          disabled={loading}
          className="h-10 w-full rounded-lg bg-[var(--primary)] px-5 text-sm font-semibold text-white disabled:opacity-60"
        >
          {loading ? "Aanvraag versturen..." : "Resetlink versturen"}
        </button>
      </form>
    </main>
  );
}
