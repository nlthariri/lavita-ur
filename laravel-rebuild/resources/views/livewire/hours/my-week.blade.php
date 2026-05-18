{{--
  Livewire-view — `Hours\MyWeek` (taak 10.3 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.4  → eigen weekoverzicht voor de medewerker met
       per finalized regel een bezwaarknop. Bezwaarstatus zichtbaar
       (open / akkoord / afgewezen) — dit scherm toont in elk geval
       "open" via een badge op regels met een actief bezwaar.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).
   - requirements.md 4.x  → bezwaar alleen op finalized regels, één open
       bezwaar per regel.

  Compositie:
   - `<x-ui.card>` met titelheader + subtitle (eigen naam).
   - Drie navigatieknoppen (Vorige week / Vandaag / Volgende week).
   - Embedded modal `<livewire:objections.new-objection-form />` zodat
     het `open-new-objection`-event direct in de DOM kan landen.
   - Een lijst van `<section>`s, één per dag (ma..zo). Per dag tonen we
     de NL-dagnaam + datum, daaronder per werkregel een `<article>` met
     tijden, netto-minuten, type-badge en óf de bezwaarknop óf een
     "Bezwaar open"-badge óf een "Concept"-badge afhankelijk van de
     status van de regel.
   - Lege dag → NL-melding "Geen uren geregistreerd."
   - Lege week → NL-melding "Er zijn nog geen uren voor deze week."

  Toegankelijkheid:
   - `<section aria-labelledby="day-…">` per dag voor screenreader-context.
   - Bezwaarknop heeft een NL-`aria-label` zodat de screenreader
     aankondigt op welke regel de gebruiker bezwaar gaat indienen.
--}}
@php
    use Illuminate\Support\Carbon;

    /** @var \Illuminate\Support\Carbon[] $weekDates */
    $weekDates = $this->getWeekDates();
    $monday = $weekDates[0];
    $sunday = $weekDates[6];

    /** @var array<string, array<int, array<string, mixed>>> $grouped */
    $grouped = $this->getEntriesGroupedByDay();

    // NL-namen voor de dagkoppen — Carbon's `dayName` is locale-afhankelijk
    // en deze app heeft (nog) geen NL-locale-config. Volgorde matcht
    // `getWeekDates()`: ma, di, wo, do, vr, za, zo.
    $dayNames = ['Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'];

    // NL-labels voor het type-veld; matched met EntryFormModal::getAvailableTypes.
    $typeLabels = [
        'WORK' => 'Werk',
        'SICK' => 'Ziek',
        'LEAVE' => 'Verlof',
        'HOLIDAY' => 'Feestdag',
        'OTHER' => 'Overig',
    ];

    /** Hulpfunctie: minuten → "Xu Ymin" of "0min" wanneer 0. */
    $formatMinutes = static function (int $minutes): string {
        if ($minutes <= 0) {
            return '0min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h === 0) {
            return $m.'min';
        }
        if ($m === 0) {
            return $h.'u';
        }
        return $h.'u '.$m.'min';
    };

    // Total over alle entries — zodat we kunnen bepalen of de week leeg is.
    $totalEntriesInWeek = array_sum(array_map('count', $grouped));
@endphp

<div class="flex flex-col gap-4" data-livewire-component="hours.my-week">
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

        {{-- Week-navigatie --}}
        <div class="flex flex-wrap gap-2">
            <x-ui.button
                variant="secondary"
                type="button"
                wire:click="previousWeek"
                aria-label="Ga naar vorige week"
            >
                Vorige week
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
                aria-label="Ga naar volgende week"
            >
                Volgende week
            </x-ui.button>
        </div>

        {{-- Lege-week-melding --}}
        @if ($totalEntriesInWeek === 0)
            <p class="mt-6 text-body-md text-steel">
                Er zijn nog geen uren voor deze week.
            </p>
        @else
            <div class="mt-6 flex flex-col gap-6">
                @foreach ($weekDates as $i => $date)
                    @php
                        $iso = $date->toDateString();
                        $entriesForDay = $grouped[$iso] ?? [];
                        $headingId = 'my-week-day-'.$iso;
                        $dayTotal = 0;
                        foreach ($entriesForDay as $row) {
                            $dayTotal += (int) ($row['net_minutes'] ?? 0);
                        }
                    @endphp
                    <section aria-labelledby="{{ $headingId }}" class="flex flex-col gap-2">
                        <header class="flex flex-wrap items-baseline gap-2">
                            <h2
                                id="{{ $headingId }}"
                                class="text-button-md font-semibold text-ink"
                            >
                                {{ $dayNames[$i] }} {{ $date->format('d-m-Y') }}
                            </h2>
                            @if ($dayTotal > 0)
                                <span class="font-mono text-body-sm text-steel">
                                    Totaal: {{ $formatMinutes($dayTotal) }}
                                </span>
                            @endif
                        </header>

                        @if (count($entriesForDay) === 0)
                            <p class="text-body-sm text-steel">Geen uren geregistreerd.</p>
                        @else
                            <ul class="flex flex-col gap-2" role="list">
                                @foreach ($entriesForDay as $entry)
                                    @php
                                        $entryId = (int) $entry['id'];
                                        $isFinalized = (bool) $entry['is_finalized'];
                                        $hasOpenObjection = (bool) $entry['has_open_objection'];
                                        $type = (string) $entry['type'];
                                        $typeLabel = $typeLabels[$type] ?? $type;
                                        $netMin = (int) $entry['net_minutes'];
                                        $startDisplay = (string) ($entry['start_time'] ?? '');
                                        $endDisplay = (string) ($entry['end_time'] ?? '');
                                        $note = (string) ($entry['note'] ?? '');
                                    @endphp
                                    <li>
                                        <article
                                            class="flex flex-col gap-2 rounded-input border border-hairline bg-surface p-3 tablet:flex-row tablet:items-center tablet:justify-between"
                                            data-entry-id="{{ $entryId }}"
                                        >
                                            <div class="flex flex-col gap-1">
                                                <p class="font-mono text-body-md text-ink">
                                                    @if ($startDisplay !== '' && $endDisplay !== '')
                                                        {{ $startDisplay }}–{{ $endDisplay }}
                                                    @else
                                                        Hele dag
                                                    @endif
                                                    <span class="text-steel">
                                                        ({{ $formatMinutes($netMin) }})
                                                    </span>
                                                </p>

                                                <div class="flex flex-wrap items-center gap-2">
                                                    <x-ui.status-badge variant="concept">
                                                        {{ $typeLabel }}
                                                    </x-ui.status-badge>

                                                    @if ($isFinalized && ! $hasOpenObjection)
                                                        <x-ui.status-badge variant="success">
                                                            Vastgesteld
                                                        </x-ui.status-badge>
                                                    @endif
                                                </div>

                                                @if ($note !== '')
                                                    <p class="text-body-sm text-steel">{{ $note }}</p>
                                                @endif
                                            </div>

                                            <div class="flex flex-wrap items-center gap-2">
                                                @if ($hasOpenObjection)
                                                    <x-ui.status-badge variant="warning">
                                                        Bezwaar open
                                                    </x-ui.status-badge>
                                                @elseif ($isFinalized)
                                                    <x-ui.button
                                                        type="button"
                                                        variant="secondary"
                                                        wire:click="$dispatch('open-new-objection', { workEntryId: {{ $entryId }} })"
                                                        aria-label="Bezwaar indienen op werkregel van {{ $startDisplay }} tot {{ $endDisplay }}"
                                                    >
                                                        Bezwaar indienen
                                                    </x-ui.button>
                                                @else
                                                    <x-ui.status-badge variant="concept">
                                                        Concept
                                                    </x-ui.status-badge>
                                                @endif
                                            </div>
                                        </article>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach
            </div>
        @endif
    </x-ui.card>

    {{-- Embedded modal — luistert op `open-new-objection`. --}}
    <livewire:objections.new-objection-form />
</div>
