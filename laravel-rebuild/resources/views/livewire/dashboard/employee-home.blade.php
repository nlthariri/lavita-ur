{{--
  Livewire-view — `Dashboard\EmployeeHome`

  Dashboard voor medewerkers: begroeting, uren-voortgang, verlof-saldo,
  bezwaren, snelacties, mini-weekoverzicht, notificaties.

  Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.10
--}}
@php
    $greeting = $this->getGreeting();
    $formattedDate = $this->getFormattedDate();
    $formattedHours = $this->getFormattedHours();
    $showHoursProgress = $this->getShowHoursProgress();
    $showLeaveBalance = $this->getShowLeaveBalance();
    $leaveVariant = $this->getLeaveBalanceVariant();

    // Color_Coding mapping per type
    $colorMap = [
        'WORK' => 'bg-brand-green',
        'SICK' => 'bg-danger',
        'LEAVE' => 'bg-blue-500',
        'HOLIDAY' => 'bg-purple-500',
    ];
@endphp

<div class="flex flex-col gap-4" data-livewire-component="dashboard.employee-home">
    {{-- Skeleton placeholders tijdens laden (Requirement 2.8) --}}
    @if (! $dataLoaded)
        <x-ui.skeleton type="card" />
        <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
            <x-ui.skeleton type="card" />
            <x-ui.skeleton type="card" />
        </div>
        <x-ui.skeleton type="card" />
        <x-ui.skeleton type="card" />
    @else
        {{-- Header met begroeting (Requirement 2.1) --}}
        <x-ui.card>
            <x-slot:header>
                <div class="flex flex-col gap-1">
                    <h1 class="text-heading-2 text-ink">{{ $greeting }}</h1>
                    <p class="text-body-sm text-steel">{{ $formattedDate }}</p>
                </div>
            </x-slot:header>
        </x-ui.card>

        {{-- Notificaties (Requirement 2.9) --}}
        @if (count($notifications) > 0)
            <x-ui.card>
                <x-slot:header>
                    <h2 class="text-button-md font-semibold text-ink">Meldingen</h2>
                </x-slot:header>

                <ul class="flex flex-col gap-2" aria-label="Recente meldingen">
                    @foreach ($notifications as $notification)
                        <li class="flex items-center gap-2 text-body-sm">
                            @if ($notification['type'] === 'success')
                                <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-brand-green" aria-hidden="true"></span>
                            @else
                                <span class="inline-block h-2 w-2 shrink-0 rounded-full bg-blue-500" aria-hidden="true"></span>
                            @endif
                            <span class="text-ink">{{ $notification['message'] }}</span>
                            <span class="ml-auto whitespace-nowrap text-steel">{{ $notification['date'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-ui.card>
        @endif

        {{-- Progress bars: Uren + Verlof-saldo --}}
        <section aria-label="Voortgang" class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
            {{-- Uren deze week (Requirement 2.2, 2.10) --}}
            <x-ui.card>
                <x-slot:header>
                    <h2 class="text-button-md font-semibold text-ink">Mijn uren deze week</h2>
                </x-slot:header>

                <div class="flex flex-col gap-3">
                    @if ($showHoursProgress)
                        {{-- Progress bar: netto-minuten vs contracturen --}}
                        <x-ui.progress
                            :value="$totalMinutesThisWeek"
                            :max="$contractMinutesPerWeek"
                            variant="success"
                            label="Gewerkt: {{ $this->formatMinutes($totalMinutesThisWeek) }} / {{ $this->formatMinutes($contractMinutesPerWeek) }}"
                        />
                    @else
                        {{-- Alleen absoluut totaal tonen als geen contracturen geconfigureerd --}}
                        <p
                            class="font-mono text-heading-2 text-ink"
                            aria-label="Totaal gewerkt: {{ $formattedHours }}"
                        >
                            {{ $formattedHours }}
                        </p>
                    @endif
                    <p class="text-body-sm text-steel">
                        {{ $daysWorkedThisWeek }} {{ $daysWorkedThisWeek === 1 ? 'dag' : 'dagen' }} geregistreerd
                    </p>
                </div>
            </x-ui.card>

            {{-- Verlof-saldo (Requirement 2.3, 2.4) --}}
            @if ($showLeaveBalance)
                <x-ui.card>
                    <x-slot:header>
                        <div class="flex items-center gap-2">
                            <h2 class="text-button-md font-semibold text-ink">Verlof-saldo</h2>
                            @if ($leaveBalance['status'] === 'warning')
                                <x-ui.status-badge variant="warning" icon>Bijna op</x-ui.status-badge>
                            @elseif ($leaveBalance['status'] === 'danger')
                                <x-ui.status-badge variant="danger" icon>Saldo op</x-ui.status-badge>
                            @endif
                        </div>
                    </x-slot:header>

                    <div class="flex flex-col gap-3">
                        <x-ui.progress
                            :value="$leaveBalance['taken_days']"
                            :max="$leaveBalance['annual_days']"
                            :variant="$leaveVariant"
                            label="Opgenomen: {{ $leaveBalance['taken_days'] }} / {{ $leaveBalance['annual_days'] }} dagen"
                        />
                        <p class="text-body-sm text-steel">
                            Resterend: {{ $leaveBalance['remaining_days'] }} {{ $leaveBalance['remaining_days'] == 1 ? 'dag' : 'dagen' }}
                        </p>
                    </div>
                </x-ui.card>
            @else
                {{-- Placeholder card als verlof niet geconfigureerd --}}
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
            @endif
        </section>

        {{-- Openstaande bezwaren lijst (Requirement 2.5) --}}
        @if ($showLeaveBalance)
            {{-- Als verlof-saldo getoond wordt, toon bezwaren apart --}}
            <x-ui.card>
                <x-slot:header>
                    <div class="flex items-center gap-2">
                        <h2 class="text-button-md font-semibold text-ink">Mijn bezwaren</h2>
                        @if ($openObjectionsCount > 0)
                            <x-ui.status-badge variant="warning" icon>
                                {{ $openObjectionsCount }} openstaand
                            </x-ui.status-badge>
                        @endif
                    </div>
                </x-slot:header>

                @if (count($objections) > 0)
                    <ul class="flex flex-col gap-2" aria-label="Bezwaren">
                        @foreach ($objections as $objection)
                            <li class="flex items-center justify-between gap-2 rounded-input border border-hairline p-3">
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-body-sm text-ink">{{ $objection['motivation'] }}</span>
                                    <span class="text-body-sm text-steel">{{ $objection['submitted_at'] }}</span>
                                </div>
                                @php
                                    $badgeVariant = match(strtoupper($objection['status'])) {
                                        'OPEN' => 'warning',
                                        'ACCEPTED', 'AKKOORD' => 'success',
                                        'REJECTED', 'AFGEWEZEN' => 'danger',
                                        default => 'concept',
                                    };
                                @endphp
                                <x-ui.status-badge :variant="$badgeVariant">
                                    {{ ucfirst(strtolower($objection['status'])) }}
                                </x-ui.status-badge>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-body-sm text-steel">Geen bezwaren ingediend.</p>
                @endif
            </x-ui.card>
        @endif

        {{-- Snelactie-knoppen (Requirement 2.6) --}}
        <x-ui.card>
            <x-slot:header>
                <h2 class="text-button-md font-semibold text-ink">Snelacties</h2>
            </x-slot:header>

            <nav aria-label="Snelacties-navigatie">
                <ul class="grid grid-cols-1 gap-3 tablet:grid-cols-2">
                    <li>
                        <a href="/uren/mijn-week" class="group flex flex-col gap-1 rounded-input border-2 border-hairline p-4 no-underline transition-colors hover:border-brand-green hover:bg-surface/50">
                            <span class="text-button-md font-semibold text-ink group-hover:text-brand-green">Uren invoeren</span>
                            <span class="text-body-sm text-steel">Registreer je uren voor deze week.</span>
                        </a>
                    </li>
                    <li>
                        <a href="/verlof" class="group flex flex-col gap-1 rounded-input border-2 border-hairline p-4 no-underline transition-colors hover:border-brand-green hover:bg-surface/50">
                            <span class="text-button-md font-semibold text-ink group-hover:text-brand-green">Verlof aanvragen</span>
                            <span class="text-body-sm text-steel">Dien een verlofaanvraag in.</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </x-ui.card>

        {{-- Mini-weekoverzicht (Requirement 2.7) --}}
        <x-ui.card>
            <x-slot:header>
                <h2 class="text-button-md font-semibold text-ink">Weekoverzicht</h2>
            </x-slot:header>

            <div class="flex flex-col gap-2" aria-label="Mini-weekoverzicht">
                @foreach ($weekOverview as $day)
                    <div class="flex items-center gap-3">
                        {{-- Dag-label --}}
                        <span class="w-8 shrink-0 text-body-sm font-medium text-ink">{{ $day['day_name'] }}</span>
                        <span class="w-12 shrink-0 text-body-sm text-steel">{{ $day['date'] }}</span>

                        {{-- Horizontale balk --}}
                        <div class="relative h-6 flex-1 overflow-hidden rounded bg-surface">
                            @if (count($day['entries']) > 0)
                                @foreach ($day['entries'] as $entry)
                                    @php
                                        // Bereken breedte als percentage van 16 uur (06:00-22:00 = 960 min)
                                        $barColor = $colorMap[$entry['type']] ?? 'bg-steel';
                                        $netMin = max(1, $entry['net_minutes']);
                                        // Max breedte gebaseerd op 960 minuten (16 uur werkdag)
                                        $widthPercent = min(100, max(2, ($netMin / 960) * 100));
                                    @endphp
                                    <div
                                        class="{{ $barColor }} h-full rounded"
                                        style="width: {{ $widthPercent }}%"
                                        title="{{ $entry['type'] }}: {{ $entry['start_at'] }} - {{ $entry['end_at'] }} ({{ intdiv($entry['net_minutes'], 60) }}u {{ $entry['net_minutes'] % 60 }}min)"
                                        aria-label="{{ $entry['type'] }}: {{ intdiv($entry['net_minutes'], 60) }} uur {{ $entry['net_minutes'] % 60 }} minuten"
                                    ></div>
                                @endforeach
                            @else
                                {{-- Lege dag --}}
                                <div class="flex h-full items-center justify-center border border-dashed border-hairline rounded">
                                    <span class="text-body-sm text-steel">—</span>
                                </div>
                            @endif
                        </div>

                        {{-- Uren-tekst --}}
                        <span class="w-14 shrink-0 text-right text-body-sm text-steel">
                            @if (count($day['entries']) > 0)
                                @php
                                    $dayTotal = collect($day['entries'])->sum('net_minutes');
                                @endphp
                                {{ $this->formatMinutes($dayTotal) }}
                            @else
                                —
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- Legenda --}}
            <div class="mt-3 flex flex-wrap gap-3 border-t border-hairline pt-3">
                <span class="flex items-center gap-1 text-body-sm text-steel">
                    <span class="inline-block h-3 w-3 rounded bg-brand-green" aria-hidden="true"></span> Werk
                </span>
                <span class="flex items-center gap-1 text-body-sm text-steel">
                    <span class="inline-block h-3 w-3 rounded bg-danger" aria-hidden="true"></span> Ziek
                </span>
                <span class="flex items-center gap-1 text-body-sm text-steel">
                    <span class="inline-block h-3 w-3 rounded bg-blue-500" aria-hidden="true"></span> Verlof
                </span>
                <span class="flex items-center gap-1 text-body-sm text-steel">
                    <span class="inline-block h-3 w-3 rounded bg-purple-500" aria-hidden="true"></span> Feestdag
                </span>
            </div>
        </x-ui.card>
    @endif
</div>
