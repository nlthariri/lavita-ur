{{--
  Livewire-view — `Hours\MyWeek` (taak 9.1 spec lavita-urenregistratie).

  Bron:
   - requirements.md 7.1-7.8 → visuele tijdlijn met horizontale balken per dag,
       Color_Coding, weektotaal, bezwaar-iconen, week-navigatie, detailpaneel.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).

  Features:
   - Per dag (ma-zo) horizontale tijdlijnbalk (bereik 06:00-22:00).
   - Positie-berekening: left% = (start_minutes - 360) / 960 × 100, width% = duration / 960 × 100.
   - Color_Coding per type op de tijdlijnbalk (WORK=brand-green, SICK=danger, LEAVE=blue-500, HOLIDAY=purple-500).
   - Weektotaal bovenaan: "XX uur YY minuten" + vergelijking met contracturen.
   - Bezwaar-icoon naast balk (open=oranje, akkoord=groen, afgewezen=rood).
   - Begin/eindtijd tekst naast balk: "09:00 - 17:30 (8u netto)".
   - Lege dag: gestippelde rand + "Geen registratie" in steel-kleur.
   - Week-navigatie (vorige/volgende, vandaag-knop) + keyboard shortcuts (←/→).
   - Klik op balk → uitklapbaar detailpaneel met alle velden.
--}}
@php
    use Illuminate\Support\Carbon;
    use App\Livewire\Hours\MyWeek as MyWeekComponent;

    /** @var \Illuminate\Support\Carbon[] $weekDates */
    $weekDates = $this->getWeekDates();
    $monday = $weekDates[0];
    $sunday = $weekDates[6];

    /** @var array<string, array<int, array<string, mixed>>> $grouped */
    $grouped = $this->getEntriesGroupedByDay();

    /** @var array<int, string|null> $objectionStatuses */
    $objectionStatuses = $this->getObjectionStatuses();

    /** @var int $weekTotalMinutes */
    $weekTotalMinutes = $this->getWeekTotalMinutes();

    // NL-namen voor de dagkoppen
    $dayNames = ['Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'];

    // NL-labels voor het type-veld
    $typeLabels = [
        'WORK' => 'Werk',
        'SICK' => 'Ziek',
        'LEAVE' => 'Verlof',
        'HOLIDAY' => 'Feestdag',
        'OTHER' => 'Overig',
    ];

    /** Hulpfunctie: minuten → "X uur Y minuten" formaat */
    $formatWeekTotal = static function (int $minutes): string {
        if ($minutes <= 0) {
            return '0 uur 0 minuten';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h . ' uur ' . $m . ' minuten';
    };

    /** Hulpfunctie: minuten → "Xu" of "Xu Ymin" compact formaat */
    $formatMinutesCompact = static function (int $minutes): string {
        if ($minutes <= 0) {
            return '0u';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($m === 0) {
            return $h . 'u';
        }
        if ($h === 0) {
            return $m . 'min';
        }
        return $h . 'u ' . $m . 'min';
    };

    /** Hulpfunctie: minuten → "HH:mm" formaat voor contracturen vergelijking */
    $formatHHmm = static function (int $minutes): string {
        $h = intdiv(abs($minutes), 60);
        $m = abs($minutes) % 60;
        return sprintf('%d:%02d', $h, $m);
    };

    // Tijdlijn-uurmarkeringen (06:00 t/m 22:00)
    $timelineHours = range(6, 22);
@endphp

<div
    class="flex flex-col gap-4"
    data-livewire-component="hours.my-week"
    x-data="{}"
    x-on:keydown.left.window="$wire.previousWeek()"
    x-on:keydown.right.window="$wire.nextWeek()"
>
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-1">
                <h1 class="text-heading-2 text-ink">
                    Mijn week — week van {{ $monday->format('d-m-Y') }}
                </h1>
                @if ($employeeFullName !== '')
                    <p class="text-body-sm text-steel">{{ $employeeFullName }}</p>
                @endif
            </div>
        </x-slot:header>

        {{-- Weektotaal bovenaan (Requirement 7.3) --}}
        <div class="mb-4 flex flex-wrap items-baseline gap-3">
            <p class="text-heading-3 font-semibold text-ink">
                {{ $formatWeekTotal($weekTotalMinutes) }}
            </p>
            @if ($contractMinutesPerWeek !== null && $contractMinutesPerWeek > 0)
                <p class="text-body-md text-steel">
                    {{ $formatHHmm($weekTotalMinutes) }} / {{ $formatHHmm($contractMinutesPerWeek) }} uur
                </p>
            @endif
        </div>

        {{-- Week-navigatie (Requirement 7.7) --}}
        <div class="flex flex-wrap gap-2">
            <x-ui.button
                variant="secondary"
                type="button"
                wire:click="previousWeek"
                aria-label="Ga naar vorige week (sneltoets: pijl links)"
            >
                ← Vorige week
            </x-ui.button>
            <x-ui.button
                variant="secondary"
                type="button"
                wire:click="goToToday"
                aria-label="Spring naar deze week"
            >
                Vandaag
            </x-ui.button>
            <x-ui.button
                variant="secondary"
                type="button"
                wire:click="nextWeek"
                aria-label="Ga naar volgende week (sneltoets: pijl rechts)"
            >
                Volgende week →
            </x-ui.button>
        </div>

        {{-- Tijdlijn per dag (Requirement 7.1) --}}
        <div class="mt-6 flex flex-col gap-4">
            @php
                $totalEntriesInWeek = array_sum(array_map('count', $grouped));
            @endphp

            @if ($totalEntriesInWeek === 0)
                <p class="text-body-md text-steel">
                    Er zijn nog geen uren voor deze week.
                </p>
            @endif

            @foreach ($weekDates as $i => $date)
                @php
                    $iso = $date->toDateString();
                    $entriesForDay = $grouped[$iso] ?? [];
                    $headingId = 'my-week-day-'.$iso;
                    $dayTotal = 0;
                    foreach ($entriesForDay as $row) {
                        $dayTotal += (int) ($row['net_minutes'] ?? 0);
                    }
                    $isEmpty = count($entriesForDay) === 0;
                @endphp
                <section aria-labelledby="{{ $headingId }}" class="flex flex-col gap-2">
                    {{-- Dag-header --}}
                    <header class="flex flex-wrap items-baseline gap-2">
                        <h2
                            id="{{ $headingId }}"
                            class="text-button-md font-semibold text-ink"
                        >
                            {{ $dayNames[$i] }}
                        </h2>
                        <span class="text-body-sm text-steel">{{ $date->format('d-m-Y') }}</span>
                    </header>

                    @if ($isEmpty)
                        {{-- Lege dag: gestippelde rand + "Geen registratie" (Requirement 7.6) --}}
                        <div class="flex items-center rounded-input border-2 border-dashed border-hairline bg-canvas px-4 py-3">
                            <span class="text-body-sm text-steel">Geen registratie</span>
                        </div>
                    @else
                        @foreach ($entriesForDay as $entry)
                            @php
                                $entryId = (int) $entry['id'];
                                $startTime = (string) ($entry['start_time'] ?? '');
                                $endTime = (string) ($entry['end_time'] ?? '');
                                $netMin = (int) $entry['net_minutes'];
                                $type = (string) $entry['type'];
                                $typeLabel = $typeLabels[$type] ?? $type;
                                $note = (string) ($entry['note'] ?? '');
                                $project = (string) ($entry['project'] ?? '');
                                $pauseMin = (int) ($entry['pause_minutes'] ?? 0);
                                $isFinalized = (bool) $entry['is_finalized'];
                                $hasOpenObjection = (bool) $entry['has_open_objection'];

                                // Tijdlijn-positie berekenen (Requirement 7.1)
                                $position = ['left' => 0, 'width' => 0];
                                $hasValidTimes = $startTime !== '' && $endTime !== '';
                                if ($hasValidTimes) {
                                    $position = MyWeekComponent::calculateTimelinePosition($startTime, $endTime);
                                }

                                // Balk-kleur (Requirement 7.2)
                                $barColor = MyWeekComponent::getTimelineBarColor($type);

                                // Bezwaar-status (Requirement 7.4)
                                $objectionStatus = $objectionStatuses[$entryId] ?? null;

                                // Netto-uren compact formaat voor naast de balk
                                $netHours = intdiv($netMin, 60);
                                $netMins = $netMin % 60;
                                $netLabel = $netHours . 'u';
                                if ($netMins > 0) {
                                    $netLabel .= sprintf('%02d', $netMins);
                                }
                                $netLabel .= ' netto';

                                // Is dit entry uitgeklapt?
                                $isExpanded = $expandedEntryId === $entryId;
                            @endphp
                            <div class="flex flex-col">
                                {{-- Tijdlijn-rij --}}
                                <div class="flex items-center gap-3">
                                    {{-- Begin/eindtijd tekst (Requirement 7.5) --}}
                                    <div class="hidden w-44 shrink-0 text-right tablet:block">
                                        @if ($hasValidTimes)
                                            <span class="font-mono text-body-sm text-ink">
                                                {{ $startTime }} - {{ $endTime }}
                                            </span>
                                            <span class="text-body-sm text-steel">
                                                ({{ $netLabel }})
                                            </span>
                                        @else
                                            <span class="text-body-sm text-steel">Hele dag</span>
                                        @endif
                                    </div>

                                    {{-- Tijdlijnbalk container --}}
                                    <div
                                        class="relative flex-1 cursor-pointer rounded-input border border-hairline bg-surface"
                                        style="height: 2rem;"
                                        wire:click="toggleDetail({{ $entryId }})"
                                        role="button"
                                        tabindex="0"
                                        aria-label="Toon details voor werkregel {{ $startTime }} - {{ $endTime }} ({{ $typeLabel }})"
                                        aria-expanded="{{ $isExpanded ? 'true' : 'false' }}"
                                    >
                                        {{-- Uurmarkeringen (subtiele lijnen) --}}
                                        @foreach ($timelineHours as $hour)
                                            @php
                                                $hourLeft = (($hour * 60 - 360) / 960) * 100;
                                            @endphp
                                            @if ($hourLeft >= 0 && $hourLeft <= 100)
                                                <div
                                                    class="absolute top-0 h-full w-px bg-hairline opacity-40"
                                                    style="left: {{ $hourLeft }}%;"
                                                    aria-hidden="true"
                                                ></div>
                                            @endif
                                        @endforeach

                                        {{-- Gekleurde balk (Requirement 7.1, 7.2) --}}
                                        @if ($hasValidTimes && $position['width'] > 0)
                                            <div
                                                class="absolute top-1 bottom-1 rounded {{ $barColor }} opacity-90 transition-opacity hover:opacity-100"
                                                style="left: {{ $position['left'] }}%; width: {{ $position['width'] }}%;"
                                                aria-hidden="true"
                                            ></div>
                                        @elseif (!$hasValidTimes)
                                            {{-- Hele dag: vul de volledige balk --}}
                                            <div
                                                class="absolute top-1 bottom-1 left-0 right-0 rounded {{ $barColor }} opacity-90"
                                                aria-hidden="true"
                                            ></div>
                                        @endif
                                    </div>

                                    {{-- Bezwaar-icoon (Requirement 7.4) --}}
                                    @if ($objectionStatus !== null)
                                        @php
                                            $objectionColor = match ($objectionStatus) {
                                                'OPEN' => 'text-warning',
                                                'APPROVED' => 'text-brand-green',
                                                'REJECTED' => 'text-danger',
                                                default => 'text-steel',
                                            };
                                            $objectionLabel = match ($objectionStatus) {
                                                'OPEN' => 'Bezwaar open',
                                                'APPROVED' => 'Bezwaar akkoord',
                                                'REJECTED' => 'Bezwaar afgewezen',
                                                default => 'Bezwaar',
                                            };
                                        @endphp
                                        <span
                                            class="shrink-0 {{ $objectionColor }}"
                                            title="{{ $objectionLabel }}"
                                            aria-label="{{ $objectionLabel }}"
                                        >
                                            <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd" />
                                            </svg>
                                        </span>
                                    @endif
                                </div>

                                {{-- Mobiel: begin/eindtijd onder de balk --}}
                                <div class="mt-1 tablet:hidden">
                                    @if ($hasValidTimes)
                                        <span class="font-mono text-body-sm text-ink">
                                            {{ $startTime }} - {{ $endTime }}
                                        </span>
                                        <span class="text-body-sm text-steel">
                                            ({{ $netLabel }})
                                        </span>
                                    @else
                                        <span class="text-body-sm text-steel">Hele dag</span>
                                    @endif
                                </div>

                                {{-- Uitklapbaar detailpaneel (Requirement 7.8) --}}
                                @if ($isExpanded)
                                    <div
                                        class="mt-2 rounded-input border border-hairline bg-surface p-4"
                                        role="region"
                                        aria-label="Details werkregel {{ $startTime }} - {{ $endTime }}"
                                    >
                                        <dl class="grid grid-cols-1 gap-x-6 gap-y-2 text-body-sm tablet:grid-cols-2">
                                            <div>
                                                <dt class="font-medium text-steel">Type</dt>
                                                <dd class="text-ink">{{ $typeLabel }}</dd>
                                            </div>
                                            <div>
                                                <dt class="font-medium text-steel">Begintijd</dt>
                                                <dd class="font-mono text-ink">{{ $startTime ?: '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="font-medium text-steel">Eindtijd</dt>
                                                <dd class="font-mono text-ink">{{ $endTime ?: '—' }}</dd>
                                            </div>
                                            <div>
                                                <dt class="font-medium text-steel">Pauze</dt>
                                                <dd class="text-ink">{{ $pauseMin }} minuten</dd>
                                            </div>
                                            <div>
                                                <dt class="font-medium text-steel">Netto werktijd</dt>
                                                <dd class="text-ink">{{ $formatMinutesCompact($netMin) }}</dd>
                                            </div>
                                            @if ($project !== '')
                                                <div>
                                                    <dt class="font-medium text-steel">Project</dt>
                                                    <dd class="text-ink">{{ $project }}</dd>
                                                </div>
                                            @endif
                                            @if ($note !== '')
                                                <div class="tablet:col-span-2">
                                                    <dt class="font-medium text-steel">Notitie</dt>
                                                    <dd class="text-ink">{{ $note }}</dd>
                                                </div>
                                            @endif
                                            <div>
                                                <dt class="font-medium text-steel">Status</dt>
                                                <dd class="text-ink">
                                                    {{ $isFinalized ? 'Vastgesteld' : 'Concept' }}
                                                </dd>
                                            </div>
                                            @if ($objectionStatus !== null)
                                                <div>
                                                    <dt class="font-medium text-steel">Bezwaar</dt>
                                                    <dd class="text-ink">
                                                        @switch($objectionStatus)
                                                            @case('OPEN')
                                                                <span class="text-warning">Open</span>
                                                                @break
                                                            @case('APPROVED')
                                                                <span class="text-brand-green">Akkoord</span>
                                                                @break
                                                            @case('REJECTED')
                                                                <span class="text-danger">Afgewezen</span>
                                                                @break
                                                        @endswitch
                                                    </dd>
                                                </div>
                                            @endif
                                        </dl>

                                        {{-- Bezwaar indienen knop (als eligible) --}}
                                        @if ($isFinalized && !$hasOpenObjection)
                                            <div class="mt-3 border-t border-hairline pt-3">
                                                <x-ui.button
                                                    type="button"
                                                    variant="secondary"
                                                    wire:click="$dispatch('open-new-objection', { workEntryId: {{ $entryId }} })"
                                                    aria-label="Bezwaar indienen op werkregel van {{ $startTime }} tot {{ $endTime }}"
                                                >
                                                    Bezwaar indienen
                                                </x-ui.button>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </section>
            @endforeach
        </div>

        {{-- Legenda --}}
        <div class="mt-6 flex flex-wrap items-center gap-4 border-t border-hairline pt-4">
            <span class="text-body-sm font-medium text-steel">Legenda:</span>
            <div class="flex items-center gap-1.5">
                <span class="inline-block h-3 w-3 rounded bg-brand-green" aria-hidden="true"></span>
                <span class="text-body-sm text-ink">Werk</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="inline-block h-3 w-3 rounded bg-danger" aria-hidden="true"></span>
                <span class="text-body-sm text-ink">Ziek</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="inline-block h-3 w-3 rounded bg-blue-500" aria-hidden="true"></span>
                <span class="text-body-sm text-ink">Verlof</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="inline-block h-3 w-3 rounded bg-purple-500" aria-hidden="true"></span>
                <span class="text-body-sm text-ink">Feestdag</span>
            </div>
        </div>
    </x-ui.card>

    {{-- Embedded modal — luistert op `open-new-objection`. --}}
    <livewire:objections.new-objection-form />
</div>
