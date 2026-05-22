{{--
  Livewire-view — `Dashboard\ManagerHome` (taak 4.1, 4.3 spec lavita-urenregistratie).

  Requirements: 1.1, 1.2, 1.4, 1.5, 1.6, 1.7, 1.9

  Features:
   - Persoonlijke begroeting met naam + datum (Nederlands formaat)
   - 6 KPI-cards via `<x-ui.stat-card>` met trend-indicatoren
   - wire:poll.30s voor auto-refresh van KPI-data
   - Skeleton placeholders tijdens laden via `<x-ui.skeleton type="card">`
   - Activiteit-feed: laatste 10 acties met avatar, beschrijving en relatieve tijd
   - Snelactie-knoppen: "Uren invoeren", "Verlof goedkeuren" (badge), "Bezwaar beoordelen" (badge)

  Toegankelijkheid (WCAG 2.1 AA):
   - Stat-cards hebben aria-labels voor screenreaders
   - Snelkoppelingen in een `<nav>` landmark
   - Skeleton met role="status" en aria-label
   - Activity feed items met semantische list markup
--}}
@php
    $quickLinks = $this->getQuickLinks();
    $isOwner = $this->getIsOwner();
    $greeting = $this->getGreeting();
    $formattedDate = $this->getFormattedDate();
@endphp

<div
    class="flex flex-col gap-4"
    data-livewire-component="dashboard.manager-home"
    wire:poll.30s="refreshKpiData"
>
    {{-- Header: persoonlijke begroeting + datum --}}
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 text-ink">{{ $greeting }}</h1>
                <p class="text-body-sm text-steel">
                    {{ $formattedDate }}
                    @if ($organizationName !== '')
                        — {{ $organizationName }}
                    @endif
                </p>
            </div>
        </x-slot:header>
    </x-ui.card>

    {{-- KPI-cards sectie: 6 cards in responsive grid --}}
    <section aria-label="Kernprestatie-indicatoren">
        @if (! $dataLoaded)
            {{-- Skeleton placeholders tijdens laden --}}
            <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2 desktop:grid-cols-3">
                @for ($i = 0; $i < 6; $i++)
                    <x-ui.skeleton type="card" />
                @endfor
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2 desktop:grid-cols-3">
                {{-- KPI 1: Totaal uren deze week --}}
                <x-ui.stat-card
                    title="Totaal uren deze week"
                    :value="$this->formatMinutesToHours($kpiData['total_hours_this_week'] ?? 0)"
                    :trend="$this->getHoursTrend()"
                    :trend-value="$this->getHoursTrendValue()"
                />

                {{-- KPI 2: Aanwezigheidspercentage --}}
                @php
                    $attendancePct = $kpiData['attendance_percentage'] ?? 0;
                    $attendanceTrend = $attendancePct >= 80 ? 'up' : ($attendancePct >= 50 ? 'neutral' : 'down');
                @endphp
                <x-ui.stat-card
                    title="Aanwezigheid"
                    :value="$attendancePct . '%'"
                    :trend="$attendanceTrend"
                    trend-value="deze week"
                />

                {{-- KPI 3: Openstaande verlofaanvragen --}}
                @php
                    $pendingLeave = $kpiData['pending_leave_count'] ?? 0;
                    $leaveTrend = $pendingLeave > 0 ? 'down' : 'neutral';
                @endphp
                <x-ui.stat-card
                    title="Verlofaanvragen"
                    :value="(string) $pendingLeave"
                    :trend="$leaveTrend"
                    :trend-value="$pendingLeave > 0 ? 'openstaand' : 'geen openstaand'"
                />

                {{-- KPI 4: ATW-meldingen (critical + warning) --}}
                @php
                    $atwCritical = $kpiData['atw_critical_count'] ?? 0;
                    $atwWarning = $kpiData['atw_warning_count'] ?? 0;
                    $atwTotal = $atwCritical + $atwWarning;
                    $atwTrend = $atwCritical > 0 ? 'down' : ($atwWarning > 0 ? 'neutral' : 'up');
                    $atwTrendValue = $atwCritical > 0
                        ? $atwCritical . ' kritiek'
                        : ($atwWarning > 0 ? $atwWarning . ' waarschuwing' : 'geen meldingen');
                @endphp
                <x-ui.stat-card
                    title="ATW-meldingen"
                    :value="(string) $atwTotal"
                    :trend="$atwTrend"
                    :trend-value="$atwTrendValue"
                />

                {{-- KPI 5: Openstaande bezwaren --}}
                @php
                    $objections = $kpiData['open_objections_count'] ?? 0;
                    $objectionsTrend = $objections > 0 ? 'down' : 'up';
                    $objectionsTrendValue = $objections > 0 ? 'actie vereist' : 'geen openstaand';
                @endphp
                <x-ui.stat-card
                    title="Bezwaren"
                    :value="(string) $objections"
                    :trend="$objectionsTrend"
                    :trend-value="$objectionsTrendValue"
                />

                {{-- KPI 6: Ziekteverzuim --}}
                @php
                    $sickPct = $kpiData['sick_percentage'] ?? 0.0;
                    $sickTrend = $sickPct > 5 ? 'down' : ($sickPct > 0 ? 'neutral' : 'up');
                    $sickTrendValue = $sickPct > 0 ? 'deze week' : 'geen verzuim';
                @endphp
                <x-ui.stat-card
                    title="Ziekteverzuim"
                    :value="number_format($sickPct, 1) . '%'"
                    :trend="$sickTrend"
                    :trend-value="$sickTrendValue"
                />
            </div>
        @endif
    </section>

    {{-- Sectie: Staafgrafiek uren per dag (Requirements 1.3, 1.10) --}}
    {{-- Lazy-loaded via apart Livewire-component met #[Lazy] attribute --}}
    <livewire:dashboard.manager-week-chart />

    {{-- Sectie: snelactie-knoppen (Requirement 1.5) --}}
    <section aria-label="Snelacties">
        <x-ui.card>
            <x-slot:header>
                <h2 class="text-button-md font-semibold text-ink">Snelacties</h2>
            </x-slot:header>

            <nav aria-label="Snelacties-navigatie">
                <div class="flex flex-wrap gap-3">
                    {{-- Uren invoeren --}}
                    <x-ui.button
                        as="a"
                        variant="primary"
                        href="/uren/week"
                        data-quick-action="uren-invoeren"
                        aria-label="Uren invoeren"
                    >
                        Uren invoeren
                    </x-ui.button>

                    {{-- Verlof goedkeuren (met badge) --}}
                    <x-ui.button
                        as="a"
                        variant="secondary"
                        href="/verlof"
                        data-quick-action="verlof-goedkeuren"
                        aria-label="Verlof goedkeuren — {{ $kpiData['pending_leave_count'] ?? 0 }} openstaand"
                    >
                        <span class="flex items-center gap-2">
                            Verlof goedkeuren
                            @if (($kpiData['pending_leave_count'] ?? 0) > 0)
                                <span
                                    class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-danger px-1.5 text-xs font-bold text-white"
                                    aria-hidden="true"
                                >
                                    {{ $kpiData['pending_leave_count'] }}
                                </span>
                            @endif
                        </span>
                    </x-ui.button>

                    {{-- Bezwaar beoordelen (met badge) --}}
                    <x-ui.button
                        as="a"
                        variant="secondary"
                        href="/bezwaren"
                        data-quick-action="bezwaar-beoordelen"
                        aria-label="Bezwaar beoordelen — {{ $kpiData['open_objections_count'] ?? 0 }} openstaand"
                    >
                        <span class="flex items-center gap-2">
                            Bezwaar beoordelen
                            @if (($kpiData['open_objections_count'] ?? 0) > 0)
                                <span
                                    class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-warning px-1.5 text-xs font-bold text-white"
                                    aria-hidden="true"
                                >
                                    {{ $kpiData['open_objections_count'] }}
                                </span>
                            @endif
                        </span>
                    </x-ui.button>
                </div>
            </nav>
        </x-ui.card>
    </section>

    {{-- Sectie: activiteit-feed (Requirement 1.4) --}}
    <section aria-label="Recente activiteit">
        <x-ui.card>
            <x-slot:header>
                <h2 class="text-button-md font-semibold text-ink">Recente activiteit</h2>
            </x-slot:header>

            @if (! $dataLoaded)
                {{-- Skeleton placeholders voor activity feed --}}
                <div class="flex flex-col gap-4">
                    @for ($i = 0; $i < 5; $i++)
                        <div class="flex items-center gap-3">
                            <x-ui.skeleton type="avatar" />
                            <div class="flex-1">
                                <x-ui.skeleton type="text" :lines="1" />
                            </div>
                        </div>
                    @endfor
                </div>
            @elseif (empty($kpiData['activity_feed']))
                <p class="text-body-sm text-steel">Geen recente activiteit beschikbaar.</p>
            @else
                <ul class="flex flex-col divide-y divide-hairline" role="list">
                    @foreach ($kpiData['activity_feed'] as $activity)
                        <li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                            <x-ui.avatar
                                :name="$activity['actor_name']"
                                size="sm"
                            />
                            <div class="flex flex-1 flex-col gap-0.5 min-w-0">
                                <p class="text-body-sm text-ink truncate">
                                    {{ $activity['description'] }}
                                </p>
                                <time
                                    class="text-body-sm text-steel"
                                    datetime="{{ $activity['created_at'] }}"
                                >
                                    {{ $this->formatRelativeTime($activity['created_at']) }}
                                </time>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>
    </section>

    {{-- Sectie: overige snelkoppelingen --}}
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
