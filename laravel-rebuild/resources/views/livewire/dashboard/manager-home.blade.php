{{--
  Livewire-view — `Dashboard\ManagerHome` (taak 11.3 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.9  → "Managementdashboard" met aanwezigheid huidige
       week, openstaande bezwaren teller, ATW-status samenvatting,
       snelkoppelingen naar weekoverzicht en rapportages.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
       `design.md`.
   - requirements.md 6.14 → NL-labels, NL-bevestigingen (NFR-10).

  Compositie:
   - Buitenste header-card `<x-ui.card>` met `<x-slot:header>` voor titel
     "Dashboard" + persoonlijke begroeting.
   - Drie stat-cards in een responsive grid (1 koloms < tablet, 3 koloms
     ≥ tablet) — elk gebruikt `<x-ui.card>` als atom en geen nieuwe atoms.
       1. Aanwezigheid deze week (cijfer + percentage).
       2. Openstaande bezwaren (cijfer + warning-badge wanneer > 0).
       3. ATW-meldingen (kritiek + waarschuwing teller, elk met
          danger/warning-badge wanneer > 0).
   - Snelkoppelingen-sectie met `<x-ui.button>` wrappers (`as="a"`) zodat
     de design-token-pillvorm en focus-ring worden hergebruikt.

  Toegankelijkheid (WCAG 2.1 AA):
   - Iedere stat-card heeft een `<h2>` met heading-2-niveau onder de
     hoofdkop, plus een `aria-label` op het cijfer-element zodat een
     screenreader het cijfer in context aankondigt
     (bijv. "Aanwezig: 3 van de 10").
   - De snelkoppelingen-lijst is een `<nav>` met `aria-label` zodat de
     navigatie als landmark herkend wordt.
   - Status-badges erven hun rol/contrast uit `<x-ui.status-badge>`.
--}}
@php
    /** @var array<int, array{label: string, url: string, description: string, owner_only: bool}> $quickLinks */
    $quickLinks = $this->getQuickLinks();
    $isOwner = $this->getIsOwner();
    $presencePct = $this->getPresencePercentage();
@endphp

<div class="flex flex-col gap-4" data-livewire-component="dashboard.manager-home">
    {{-- Header-card: titel + persoonlijke begroeting + organisatie. --}}
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 text-ink">Dashboard</h1>
                <p class="text-body-sm text-steel">
                    @if ($userFullName !== '' && $organizationName !== '')
                        Welkom {{ $userFullName }} — {{ $organizationName }}
                    @elseif ($userFullName !== '')
                        Welkom {{ $userFullName }}
                    @elseif ($organizationName !== '')
                        {{ $organizationName }}
                    @else
                        Welkom op je dashboard.
                    @endif
                </p>
            </div>
        </x-slot:header>

        <p class="text-body-md text-ink">
            Een overzicht van aanwezigheid, openstaande bezwaren en ATW-meldingen
            voor de huidige week. Gebruik de snelkoppelingen om dieper in te zoomen.
        </p>
    </x-ui.card>

    {{-- Sectie: stat-cards (3 cards) --}}
    <section
        aria-label="Statistieken"
        class="grid grid-cols-1 gap-4 tablet:grid-cols-3"
    >
        {{-- Card 1: aanwezigheid deze week --}}
        <x-ui.card data-stat-card="presence">
            <x-slot:header>
                <h2 class="text-button-md font-semibold text-ink">
                    Aanwezigheid deze week
                </h2>
            </x-slot:header>

            <div class="flex flex-col gap-2">
                <p
                    class="font-mono text-heading-2 text-ink"
                    aria-label="Aanwezig: {{ $presentEmployeesThisWeek }} van de {{ $totalEmployeesInScope }} medewerkers"
                >
                    {{ $presentEmployeesThisWeek }} / {{ $totalEmployeesInScope }}
                </p>
                <p class="text-body-sm text-steel">
                    @if ($totalEmployeesInScope > 0)
                        {{ $presencePct }}% aanwezig
                    @else
                        Geen medewerkers in scope.
                    @endif
                </p>
            </div>
        </x-ui.card>

        {{-- Card 2: openstaande bezwaren --}}
        <x-ui.card data-stat-card="objections">
            <x-slot:header>
                <h2 class="text-button-md font-semibold text-ink">
                    Openstaande bezwaren
                </h2>
            </x-slot:header>

            <div class="flex flex-col gap-2">
                <p
                    class="font-mono text-heading-2 text-ink"
                    aria-label="Openstaande bezwaren: {{ $openObjectionsCount }}"
                >
                    {{ $openObjectionsCount }}
                </p>
                @if ($openObjectionsCount > 0)
                    <x-ui.status-badge variant="warning" data-severity="warning" icon>
                        Actie vereist
                    </x-ui.status-badge>
                    <p class="text-body-sm text-steel">
                        Beoordeel openstaande bezwaren in de bezwarenlijst.
                    </p>
                @else
                    <x-ui.status-badge variant="success" data-severity="ok">
                        Geen openstaande bezwaren
                    </x-ui.status-badge>
                @endif
            </div>
        </x-ui.card>

        {{-- Card 3: ATW-meldingen (critical + warning samen) --}}
        <x-ui.card data-stat-card="atw">
            <x-slot:header>
                <h2 class="text-button-md font-semibold text-ink">
                    ATW-meldingen
                </h2>
            </x-slot:header>

            <div class="flex flex-col gap-2">
                <div class="flex flex-wrap items-baseline gap-3">
                    <p
                        class="font-mono text-heading-2 text-ink"
                        aria-label="Kritieke ATW-meldingen: {{ $atwCriticalCount }}"
                    >
                        {{ $atwCriticalCount }}
                    </p>
                    <span class="text-body-sm text-steel">kritiek</span>
                    <p
                        class="font-mono text-heading-2 text-ink"
                        aria-label="ATW-waarschuwingen: {{ $atwWarningCount }}"
                    >
                        {{ $atwWarningCount }}
                    </p>
                    <span class="text-body-sm text-steel">waarschuwing</span>
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($atwCriticalCount > 0)
                        <x-ui.status-badge variant="danger" data-severity="critical" icon>
                            {{ $atwCriticalCount }} kritiek
                        </x-ui.status-badge>
                    @endif
                    @if ($atwWarningCount > 0)
                        <x-ui.status-badge variant="warning" data-severity="warning" icon>
                            {{ $atwWarningCount }} waarschuwing
                        </x-ui.status-badge>
                    @endif
                    @if ($atwCriticalCount === 0 && $atwWarningCount === 0)
                        <x-ui.status-badge variant="success" data-severity="ok">
                            Geen ATW-meldingen
                        </x-ui.status-badge>
                    @endif
                </div>
            </div>
        </x-ui.card>
    </section>

    {{-- Sectie: snelkoppelingen --}}
    <section aria-label="Snelkoppelingen">
        <x-ui.card>
            <x-slot:header>
                <h2 class="text-button-md font-semibold text-ink">Snelkoppelingen</h2>
            </x-slot:header>

            <nav aria-label="Snelkoppelingen-navigatie">
                <ul class="grid grid-cols-1 gap-3 tablet:grid-cols-2">
                    @foreach ($quickLinks as $link)
                        @if ($link['owner_only'] && ! $isOwner)
                            @continue
                        @endif
                        <li>
                            <x-ui.button
                                as="a"
                                variant="secondary"
                                :href="$link['url']"
                                class="w-full justify-start text-left"
                                data-quick-link="{{ $link['label'] }}"
                                aria-label="{{ $link['label'] }} — {{ $link['description'] }}"
                            >
                                <span class="flex flex-col items-start gap-0.5">
                                    <span class="font-medium">{{ $link['label'] }}</span>
                                    <span class="text-body-sm font-normal text-steel">
                                        {{ $link['description'] }}
                                    </span>
                                </span>
                            </x-ui.button>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </x-ui.card>
    </section>
</div>
