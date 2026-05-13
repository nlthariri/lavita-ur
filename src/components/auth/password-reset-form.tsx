"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";

import { readCsrfTokenFromCookie } from "@/lib/security/csrf-client";

type PasswordResetFormProps = {
  token: string;
};

export function PasswordResetForm({ token }: PasswordResetFormProps) {
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage(null);
    setError(null);

    if (!token) {
      setError("Resettoken ontbreekt of is ongeldig.");
      return;
    }

    if (password !== confirmPassword) {
      setError("Wachtwoorden komen niet overeen.");
      return;
    }

    setLoading(true);

    const response = await fetch("/api/auth/password-reset/confirm", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-csrf-token": readCsrfTokenFromCookie(),
      },
      body: JSON.stringify({ token, password }),
    });

    const data = await response.json();
    setLoading(false);

    if (!response.ok) {
      setError(data.error ?? "Wachtwoord resetten mislukt.");
      return;
    }

    setMessage("Je wachtwoord is bijgewerkt. Je kunt nu opnieuw inloggen.");
    setPassword("");
    setConfirmPassword("");
  }

  return (
    <form onSubmit={handleSubmit} className="card w-full p-6 sm:p-8" aria-label="Wachtwoord reset formulier">
      <h1 className="mb-1 text-2xl font-bold text-[var(--primary)]">Nieuw wachtwoord instellen</h1>
      <p className="mb-6 text-sm text-zinc-600">Kies een nieuw wachtwoord van minimaal 12 tekens.</p>

      <label className="mb-2 block text-sm font-medium text-zinc-700" htmlFor="password">
        Nieuw wachtwoord
      </label>
      <input
        id="password"
        type="password"
        required
        minLength={12}
        className="mb-4 h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
        value={password}
        onChange={(event) => setPassword(event.target.value)}
      />

      <label className="mb-2 block text-sm font-medium text-zinc-700" htmlFor="confirmPassword">
        Herhaal wachtwoord
      </label>
      <input
        id="confirmPassword"
        type="password"
        required
        minLength={12}
        className="mb-4 h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
        value={confirmPassword}
        onChange={(event) => setConfirmPassword(event.target.value)}
      />

      {message ? <p className="mb-4 text-sm text-green-700">{message}</p> : null}
      {error ? <p className="mb-4 text-sm text-red-700">{error}</p> : null}

      <button
        type="submit"
        disabled={loading}
        className="mb-3 h-10 w-full rounded-lg bg-[var(--primary)] px-5 text-sm font-semibold text-white disabled:opacity-60"
      >
        {loading ? "Opslaan..." : "Wachtwoord opslaan"}
      </button>

      <Link href="/login" className="text-sm text-zinc-700 underline">
        Terug naar inloggen
      </Link>
    </form>
  );
}
