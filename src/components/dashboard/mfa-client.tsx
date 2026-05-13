"use client";

import { FormEvent, useState } from "react";

import { readCsrfTokenFromCookie } from "@/lib/security/csrf-client";

export function MfaClient() {
  const [secret, setSecret] = useState<string | null>(null);
  const [otpauthUri, setOtpauthUri] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function handleSetup() {
    setError(null);
    setMessage(null);

    const response = await fetch("/api/auth/mfa/setup", {
      method: "POST",
      headers: {
        "x-csrf-token": readCsrfTokenFromCookie(),
      },
    });

    const data = await response.json();
    if (!response.ok) {
      setError(data.error ?? "MFA setup mislukt.");
      return;
    }

    setSecret(data.secret);
    setOtpauthUri(data.otpauthUri);
    setMessage("MFA geheim gegenereerd. Voeg deze toe in je authenticator app.");
  }

  async function handleVerify(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setMessage(null);

    const response = await fetch("/api/auth/mfa/verify", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-csrf-token": readCsrfTokenFromCookie(),
      },
      body: JSON.stringify({ code }),
    });

    const data = await response.json();
    if (!response.ok) {
      setError(data.error ?? "MFA verificatie mislukt.");
      return;
    }

    setMessage("MFA is geactiveerd.");
    setCode("");
  }

  return (
    <main className="mx-auto w-full max-w-3xl px-5 py-8 sm:px-8 sm:py-12">
      <h1 className="mb-4 text-3xl font-bold tracking-tight text-[var(--primary)]">MFA instellen</h1>

      <section className="card mb-4 p-5">
        <p className="mb-4 text-sm text-zinc-700">
          Voor eigenaar en manager is MFA verplicht. Start met het genereren van een geheim en verifieer daarna een
          geldige 6-cijferige code.
        </p>
        <button onClick={handleSetup} className="h-10 rounded-lg bg-[var(--primary)] px-4 text-sm font-semibold text-white">
          MFA setup starten
        </button>

        {secret ? (
          <div className="mt-4 space-y-2 text-sm">
            <p className="font-semibold text-zinc-800">Geheim:</p>
            <p className="break-all rounded border border-[var(--border-soft)] bg-white p-2">{secret}</p>
            <p className="font-semibold text-zinc-800">OTPAuth URI:</p>
            <p className="break-all rounded border border-[var(--border-soft)] bg-white p-2">{otpauthUri}</p>
          </div>
        ) : null}
      </section>

      <form onSubmit={handleVerify} className="card p-5">
        <label htmlFor="code" className="mb-2 block text-sm font-medium text-zinc-700">
          Verificatiecode
        </label>
        <input
          id="code"
          value={code}
          onChange={(event) => setCode(event.target.value)}
          pattern="[0-9]{6}"
          inputMode="numeric"
          required
          className="mb-4 h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
        />

        <button type="submit" className="h-10 rounded-lg border border-[var(--border-soft)] px-4 text-sm font-semibold">
          Code verifiëren
        </button>
      </form>

      {message ? <p className="mt-4 text-sm text-green-700">{message}</p> : null}
      {error ? <p className="mt-2 text-sm text-red-700">{error}</p> : null}
    </main>
  );
}