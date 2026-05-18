{{--
  Livewire-view — `Dashboard\EmployeeHome`

  Dashboard voor medewerkers: eigen uren, bezwaren, snelkoppelingen.
--}}
@php
    $formattedHours = $this->getFormattedHours();
@endphp

<div class="flex flex-col gap-4" data-livewire-component="dashboard.employee-home">
    {{-- Header --}}
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 text-ink">Dashboard</h1>
                <p class="text-body-sm text-steel">
                    @if ($userFullName !== '')
                        Welkom {{ $userFullName }}
                        @if ($organizationName !== '')
                            — {{ $organizationName }}
                        @endif
                    @else
                        Welkom op je dashboard.
                    @endif
                </p>
            </div>
        </x-slot:header>

        <p class="text-body-md text-ink">
            Hier zie je een overzicht van je uren en bezwaren voor de huidige week.
        </p>
    </x-ui.card>

    {{-- Stat-cards --}}
    <section aria-label="Statistieken" class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
        {{-- Uren deze week --}}
        <x-ui.card>
            <x-slot:header>
                <h2 class="text-button-md font-semibold text-ink">Mijn uren deze week</h2>
            </x-slot:header>

            <div class="flex flex-col gap-2">
                <p
                    class="font-mono text-heading-2 text-ink"
                    aria-label="Totaal gewerkt: {{ $formattedHours }}"
                >
                    {{ $formattedHours }}
                </p>
                <p class="text-body-sm text-steel">
                    {{ $daysWorkedThisWeek }} {{ $daysWorkedThisWeek === 1 ? 'dag' : 'dagen' }} geregistreerd
                </p>
            </div>
        </x-ui.card>

        {{-- Bezwaren --}}
        <x-ui.card>
            <x-slot:header>
                <h2 class="text-button-md font-semibold text-ink">Mijn bezwaren</h2>
            </x-slot:header>

            <div class="flex flex-col gap-2">
                <p
                    class="font-mono text-heading-2 text-ink"
                    aria-label="Openstaande bezwaren: {{ $openObjectionsCount }}"
                >
                    {{ $openObjectionsCount }}
                </p>
                @if ($openObjectionsCount > 0)
                    <x-ui.status-badge variant="warning" icon>
                        {{ $openObjectionsCount }} openstaand
                    </x-ui.status-badge>
                @else
                    <x-ui.status-badge variant="success">
                        Geen openstaande bezwaren
                    </x-ui.status-badge>
                @endif
            </div>
        </x-ui.card>
    </section>

    {{-- Snelkoppelingen --}}
    <x-ui.card>
        <x-slot:header>
            <h2 class="text-button-md font-semibold text-ink">Snelkoppelingen</h2>
        </x-slot:header>

        <nav aria-label="Snelkoppelingen-navigatie">
            <ul class="grid grid-cols-1 gap-3 tablet:grid-cols-2">
                <li>
                    <a href="/uren/mijn-week" class="group flex flex-col gap-1 rounded-input border-2 border-hairline p-4 no-underline transition-colors hover:border-brand-green hover:bg-surface/50">
                        <span class="text-button-md font-semibold text-ink group-hover:text-brand-green">Mijn weekoverzicht</span>
                        <span class="text-body-sm text-steel">Bekijk en beheer je uren voor deze week.</span>
                    </a>
                </li>
                <li>
                    <a href="/verlof" class="group flex flex-col gap-1 rounded-input border-2 border-hairline p-4 no-underline transition-colors hover:border-brand-green hover:bg-surface/50">
                        <span class="text-button-md font-semibold text-ink group-hover:text-brand-green">Verlof aanvragen</span>
                        <span class="text-body-sm text-steel">Dien een verlofaanvraag in.</span>
                    </a>
                </li>
                <li>
                    <a href="/bezwaren" class="group flex flex-col gap-1 rounded-input border-2 border-hairline p-4 no-underline transition-colors hover:border-brand-green hover:bg-surface/50">
                        <span class="text-button-md font-semibold text-ink group-hover:text-brand-green">Bezwaren</span>
                        <span class="text-body-sm text-steel">Bekijk de status van je ingediende bezwaren.</span>
                    </a>
                </li>
                <li>
                    <a href="/profiel" class="group flex flex-col gap-1 rounded-input border-2 border-hairline p-4 no-underline transition-colors hover:border-brand-green hover:bg-surface/50">
                        <span class="text-button-md font-semibold text-ink group-hover:text-brand-green">Mijn profiel</span>
                        <span class="text-body-sm text-steel">Bekijk en bewerk je profielgegevens.</span>
                    </a>
                </li>
            </ul>
        </nav>
    </x-ui.card>
</div>
