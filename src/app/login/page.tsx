"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";

import { readCsrfTokenFromCookie } from "@/lib/security/csrf-client";

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [totpCode, setTotpCode] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setError(null);

    const response = await fetch("/api/auth/login", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-csrf-token": readCsrfTokenFromCookie(),
      },
      body: JSON.stringify({ email, password, totpCode: totpCode || undefined }),
    });

    const data = await response.json();
    setLoading(false);

    if (!response.ok) {
      setError(data.error ?? "Inloggen mislukt.");
      return;
    }

    router.push("/dashboard");
    router.refresh();
  }

  return (
    <main className="mx-auto flex min-h-screen w-full max-w-md items-center px-5 py-10">
      <form onSubmit={handleSubmit} className="card w-full p-6 sm:p-8" aria-label="Inlogformulier">
        <h1 className="mb-1 text-2xl font-bold text-[var(--primary)]">Inloggen</h1>
        <p className="mb-6 text-sm text-zinc-600">Log in met je account. Voor eigenaar en manager is MFA verplicht.</p>

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

        <label className="mb-2 block text-sm font-medium text-zinc-700" htmlFor="password">
          Wachtwoord
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

        <label className="mb-2 block text-sm font-medium text-zinc-700" htmlFor="totp">
          MFA-code (6 cijfers)
        </label>
        <input
          id="totp"
          inputMode="numeric"
          pattern="[0-9]{6}"
          className="mb-4 h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
          value={totpCode}
          onChange={(event) => setTotpCode(event.target.value)}
        />

        {error ? <p className="mb-4 text-sm text-red-700">{error}</p> : null}

        <button
          type="submit"
          disabled={loading}
          className="h-10 w-full rounded-lg bg-[var(--primary)] px-5 text-sm font-semibold text-white disabled:opacity-60"
        >
          {loading ? "Bezig met inloggen..." : "Inloggen"}
        </button>

        <div className="mt-3 text-right">
          <Link href="/wachtwoord-vergeten" className="text-sm text-zinc-700 underline">
            Wachtwoord vergeten?
          </Link>
        </div>
      </form>
    </main>
  );
}
