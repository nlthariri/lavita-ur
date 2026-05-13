import Link from "next/link";

import { requireSession } from "@/lib/auth/guards";

export default async function DashboardPage() {
  const session = await requireSession();
  const isManagerOrOwner = session.role === "MANAGER" || session.role === "OWNER";

  return (
    <main className="mx-auto w-full max-w-6xl px-5 py-8 sm:px-8 sm:py-12">
      {isManagerOrOwner && !session.mfaVerified ? (
        <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
          MFA is verplicht voor jouw rol. Rond eerst MFA setup af via
          <Link href="/dashboard/mfa" className="ml-1 font-semibold underline">
            MFA instellingen
          </Link>
          .
        </div>
      ) : null}

      <div className="mb-6 flex items-center justify-between">
        <div>
          <p className="text-sm text-zinc-600">Ingelogd als {session.role}</p>
          <h1 className="text-3xl font-bold tracking-tight text-[var(--primary)]">Dashboard</h1>
        </div>
        <form action="/api/auth/logout" method="post">
          <button className="h-10 rounded-lg border border-[var(--border-soft)] px-4 text-sm font-semibold" type="submit">
            Uitloggen
          </button>
        </form>
      </div>

      <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {isManagerOrOwner ? (
          <>
            <article className="card p-5">
              <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Weekoverzicht</h2>
              <p className="mb-4 text-sm text-zinc-700">Bekijk teamstatus en openstaande acties per dag.</p>
              <Link href="/dashboard/weekoverzicht" className="text-sm font-semibold text-[var(--primary)]">
                Open weekoverzicht
              </Link>
            </article>

            <article className="card p-5">
              <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Uren invoeren</h2>
              <p className="mb-4 text-sm text-zinc-700">Registreer uren direct met realtime ATW-signalen.</p>
              <Link href="/dashboard/uren-invoer" className="text-sm font-semibold text-[var(--primary)]">
                Open invoerscherm
              </Link>
            </article>

            <article className="card p-5">
              <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">ATW-status</h2>
              <p className="mb-4 text-sm text-zinc-700">Controleer limieten en rusttijd per medewerker.</p>
              <Link href="/dashboard/atw" className="text-sm font-semibold text-[var(--primary)]">
                Open ATW-dashboard
              </Link>
            </article>

            <article className="card p-5">
              <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Bezwaren</h2>
              <p className="mb-4 text-sm text-zinc-700">Beoordeel open bezwaren van medewerkers.</p>
              <Link href="/dashboard/bezwaren" className="text-sm font-semibold text-[var(--primary)]">
                Open bezwaarscherm
              </Link>
            </article>

            <article className="card p-5">
              <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Gebruikersbeheer</h2>
              <p className="mb-4 text-sm text-zinc-700">Maak accounts aan en beheer rollen binnen je organisatie.</p>
              <Link href="/dashboard/gebruikers" className="text-sm font-semibold text-[var(--primary)]">
                Open gebruikersbeheer
              </Link>
            </article>

            <article className="card p-5">
              <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Maandrapportage</h2>
              <p className="mb-4 text-sm text-zinc-700">Verstuur handmatig de PDF-maandrapportage naar eigenaar en managers.</p>
              <form action="/api/jobs/monthly-report" method="post">
                <button className="text-sm font-semibold text-[var(--primary)]" type="submit">
                  Verstuur maandrapportage
                </button>
              </form>
            </article>
          </>
        ) : null}

        <article className="card p-5">
          <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Mijn urenstaat</h2>
          <p className="mb-4 text-sm text-zinc-700">Bekijk je vastgestelde uren en dien bezwaar in waar nodig.</p>
          <Link href="/dashboard/mijn-uren" className="text-sm font-semibold text-[var(--primary)]">
            Open mijn uren
          </Link>
        </article>

        <article className="card p-5">
          <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">MFA instellingen</h2>
          <p className="mb-4 text-sm text-zinc-700">Activeer of controleer multi-factor authenticatie.</p>
          <Link href="/dashboard/mfa" className="text-sm font-semibold text-[var(--primary)]">
            Open MFA pagina
          </Link>
        </article>
      </section>
    </main>
  );
}
