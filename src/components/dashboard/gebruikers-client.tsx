"use client";

import { FormEvent, useEffect, useMemo, useState } from "react";

import { readCsrfTokenFromCookie } from "@/lib/security/csrf-client";

type Team = {
  id: string;
  name: string;
};

type UserItem = {
  id: string;
  fullName: string;
  email: string;
  role: "OWNER" | "MANAGER" | "EMPLOYEE" | "ACCOUNTANT";
  isActive: boolean;
  mfaEnabled: boolean;
  team: Team | null;
};

export function GebruikersClient() {
  const [users, setUsers] = useState<UserItem[]>([]);
  const [teams, setTeams] = useState<Team[]>([]);
  const [fullName, setFullName] = useState("");
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<UserItem["role"]>("EMPLOYEE");
  const [teamId, setTeamId] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  const needsTeam = useMemo(() => role === "MANAGER" || role === "EMPLOYEE", [role]);

  async function loadData() {
    try {
      const response = await fetch("/api/internal/admin/users");
      const data = await response.json();

      if (!response.ok) {
        setError(data.error ?? "Gebruikers laden mislukt.");
        return;
      }

      setUsers(data.users ?? []);
      setTeams(data.teams ?? []);
    } catch {
      setError("Netwerkfout bij laden van gebruikers.");
    }
  }

  useEffect(() => {
    const timer = setTimeout(() => {
      void loadData();
    }, 0);

    return () => clearTimeout(timer);
  }, []);

  async function handleCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError(null);
    setMessage(null);

    const response = await fetch("/api/internal/admin/users", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-csrf-token": readCsrfTokenFromCookie(),
      },
      body: JSON.stringify({
        fullName,
        email,
        role,
        teamId: needsTeam ? teamId || undefined : undefined,
      }),
    });

    const data = await response.json();

    if (!response.ok) {
      setError(data.error ?? "Account aanmaken mislukt.");
      return;
    }

    setMessage(`Account aangemaakt. Tijdelijk wachtwoord: ${data.temporaryPassword}`);
    setFullName("");
    setEmail("");
    setRole("EMPLOYEE");
    setTeamId("");
    await loadData();
  }

  return (
    <main className="mx-auto w-full max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
      <h1 className="mb-4 text-3xl font-bold tracking-tight text-[var(--primary)]">Gebruikersbeheer</h1>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section className="card p-5">
          <h2 className="mb-3 text-lg font-semibold text-[var(--primary)]">Nieuw account aanmaken</h2>
          <form onSubmit={handleCreate} className="space-y-3">
            <input
              required
              value={fullName}
              onChange={(event) => setFullName(event.target.value)}
              placeholder="Volledige naam"
              className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
            />
            <input
              required
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              placeholder="E-mailadres"
              className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
            />
            <select
              value={role}
              onChange={(event) => setRole(event.target.value as UserItem["role"])}
              className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
            >
              <option value="EMPLOYEE">Medewerker</option>
              <option value="MANAGER">Manager</option>
              <option value="ACCOUNTANT">Boekhouder</option>
              <option value="OWNER">Eigenaar/Admin</option>
            </select>

            {needsTeam ? (
              <select
                value={teamId}
                onChange={(event) => setTeamId(event.target.value)}
                className="h-10 w-full rounded-lg border border-[var(--border-soft)] bg-white px-3 text-sm"
                required
              >
                <option value="">Selecteer team</option>
                {teams.map((team) => (
                  <option key={team.id} value={team.id}>
                    {team.name}
                  </option>
                ))}
              </select>
            ) : null}

            <button type="submit" className="h-10 rounded-lg bg-[var(--primary)] px-4 text-sm font-semibold text-white">
              Account aanmaken
            </button>
          </form>
        </section>

        <section className="card p-5">
          <h2 className="mb-3 text-lg font-semibold text-[var(--primary)]">Bestaande accounts</h2>
          <div className="space-y-2">
            {users.map((user) => (
              <article key={user.id} className="rounded-lg border border-[var(--border-soft)] bg-white p-3">
                <p className="text-sm font-semibold text-zinc-800">{user.fullName}</p>
                <p className="text-xs text-zinc-600">{user.email}</p>
                <p className="mt-1 text-xs text-zinc-700">
                  Rol: {user.role} | Team: {user.team?.name ?? "-"} | MFA: {user.mfaEnabled ? "Actief" : "Uit"}
                </p>
              </article>
            ))}
          </div>
        </section>
      </div>

      {message ? <p className="mt-3 text-sm text-green-700">{message}</p> : null}
      {error ? <p className="mt-3 text-sm text-red-700">{error}</p> : null}
    </main>
  );
}