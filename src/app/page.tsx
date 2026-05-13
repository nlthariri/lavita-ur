import Link from "next/link";

export default function Home() {
  return (
    <div className="flex min-h-screen flex-col bg-[var(--canvas)]">
      <main className="mx-auto w-full max-w-6xl flex-1 px-5 py-8 sm:px-8 sm:py-12">
        <section className="mb-8 rounded-2xl border border-[var(--border-soft)] bg-white p-6 sm:p-8">
          <p className="mb-2 text-sm font-medium text-zinc-600">La Vita Trading</p>
          <h1 className="mb-3 text-3xl font-bold tracking-tight text-[var(--primary)] sm:text-4xl">
            Urenregistratie met directe vaststelling en ATW-bewaking
          </h1>
          <p className="max-w-3xl text-base leading-7 text-zinc-700">
            Eigenaren en managers registreren werktijden direct. Medewerkers hebben inzage en kunnen per regel
            bezwaar indienen met motivatie. Het systeem bewaakt automatisch dag-, week- en 16-weekslimieten.
          </p>
          <div className="mt-5 flex flex-wrap items-center gap-3">
            <Link href="/login" className="inline-flex h-10 items-center rounded-lg bg-[var(--primary)] px-5 text-sm font-semibold text-white">
              Inloggen
            </Link>
            <span className="badge-vastgesteld rounded-full px-3 py-1 text-xs font-semibold">Vastgesteld</span>
            <span className="badge-bezwaar rounded-full px-3 py-1 text-xs font-semibold">Bezwaar</span>
            <span className="badge-concept rounded-full px-3 py-1 text-xs font-semibold">Concept</span>
          </div>
        </section>

        <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <article className="card p-5">
            <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Weekoverzicht</h2>
            <p className="text-sm text-zinc-700">Manager ziet team, dagstatus en openstaande acties in een scherm.</p>
          </article>
          <article className="card p-5">
            <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Invoerenmodal</h2>
            <p className="text-sm text-zinc-700">Start/eind, pauze, project en directe netto-berekening met ATW-waarschuwing.</p>
          </article>
          <article className="card p-5">
            <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">ATW-dashboard</h2>
            <p className="text-sm text-zinc-700">Kleurcodes voor limieten per medewerker inclusief rusttijdcontroles.</p>
          </article>
          <article className="card p-5">
            <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Bezwaarafhandeling</h2>
            <p className="text-sm text-zinc-700">Medewerker dient bezwaar in, manager beslist met verplichte motivering.</p>
          </article>
          <article className="card p-5">
            <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">E-mailcycli</h2>
            <p className="text-sm text-zinc-700">Alle triggerteksten per mailtype configureerbaar door de admin.</p>
          </article>
          <article className="card p-5">
            <h2 className="mb-2 text-lg font-semibold text-[var(--primary)]">Rapportages</h2>
            <p className="text-sm text-zinc-700">PDF/Excel export per medewerker, team, project en periode.</p>
          </article>
        </section>
      </main>

      <footer className="bg-[var(--footer)] px-5 py-6 text-sm text-zinc-300 sm:px-8">
        <div className="mx-auto flex w-full max-w-6xl items-center justify-between">
          <span>La Vita Urenregistratie</span>
          <span>AVG, ATW en 7-jaarsarchief</span>
        </div>
      </footer>
    </div>
  );
}
