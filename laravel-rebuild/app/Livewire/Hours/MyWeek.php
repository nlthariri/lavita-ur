<?php

declare(strict_types=1);

namespace App\Livewire\Hours;

use App\Livewire\Objections\NewObjectionForm;
use App\Models\Objection;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Hours\MyWeek` (taak 10.3 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.4  → scherm "Medewerker-urenstaat" op
 *      `/uren/mijn-week` met per regel een bezwaarknop, bezwaarstatus
 *      zichtbaar (open/akkoord/afgewezen).
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens
 *      uit `design.md`.
 *  - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).
 *  - requirements.md 4.x  → bezwaar alleen op finalized regels, motivatie
 *      ≥10 / ≤2000 tekens, één open bezwaar per regel.
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Medewerker-urenstaat" → component `Hours\MyWeek` op
 *      `/uren/mijn-week`.
 *  - tasks.md 10.3.
 *
 * Verantwoordelijkheid:
 *  - Lees-only weergave van de uren van de ingelogde gebruiker zelf voor
 *    één ISO-week (maandag t/m zondag, Europe/Amsterdam). Per dag een
 *    sectie met de werkregels van die dag, gegroepeerd op `entry_date`,
 *    inclusief netto-minuten en — voor finalized regels zonder open
 *    bezwaar — een bezwaarknop die het modal `Objections\NewObjectionForm`
 *    opent via een Livewire-event (`open-new-objection`).
 *  - Week-navigatie via Vorige / Vandaag / Volgende, identiek aan
 *    {@see WeekOverviewTable}.
 *
 * Rolinterpretatie (req 6.4 zegt "voor employees" maar managers/owners
 * kunnen ook eigen shifts hebben):
 *  - Allow ALL roles behalve `boekhouder`. Boekhouder is read-only en
 *    heeft geen eigen urenregels — die rol krijgt 403 met NL-melding.
 *  - Owner/manager kunnen dit scherm gebruiken voor hun eigen week (zij
 *    hebben in praktijk ook diensten); zij zien dan alleen hun eigen
 *    werkregels (`employee_id = Auth::id()`).
 *
 * Bewust niet:
 *  - Geen entry-creatie (taak 10.2 — `Hours\EntryFormModal` op
 *    `/uren/week`). Alleen leesweergave + bezwaar-launch.
 *  - Geen objection-review-knop — dat is manager/owner-functionaliteit
 *    (taak 11.2 — `Objections\ReviewForm`).
 *  - Geen route-registratie in `routes/web.php` — wordt opgenomen in een
 *    latere taak (sectie 13 of een interim-taak voor /uren-routes).
 *  - Geen feestdagen-tooltip — die hangt aan taak 14.8 zodra de
 *    `holidays`-tabel bestaat.
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt op `<x-ui.button>`, `<x-ui.card>`, `<x-ui.status-badge>`.
 *  - De ingebedde modal `<livewire:objections.new-objection-form>` gebruikt
 *    een native `<textarea>` voor de motivatie; zie deviation-note in
 *    {@see NewObjectionForm} (`<x-ui.text-input>`
 *    levert geen textarea-mode — zelfde deviation als taak 10.2).
 */
#[Layout('layouts.app')]
#[Title('Mijn week — LaVita Urenregistratie')]
final class MyWeek extends Component
{
    /**
     * Maandag van de zichtbare week, ISO-formaat `Y-m-d`. Wordt in
     * {@see mount()} geïnitialiseerd op de maandag van vandaag in de
     * tijdzone `Europe/Amsterdam` zodat de UI altijd in de Nederlandse
     * weekindeling draait. Identiek aan {@see WeekOverviewTable::$weekStart}.
     */
    public string $weekStart = '';

    /**
     * Request-lifecycle cache voor getEntriesGroupedByDay(). Voorkomt
     * N+1 queries wanneer getNetMinutesForDay() voor 7 dagen wordt
     * aangeroepen. Private properties persisteren niet tussen Livewire-
     * requests, dus dit is veilig.
     *
     * @var array<string, array<int, array<string, mixed>>>|null
     */
    private ?array $cachedEntriesGroupedByDay = null;

    /**
     * Display-naam van de actor (current user). Cachen we als property
     * zodat we 'm niet bij elke render opnieuw via een relation moeten
     * resolven. Bron: `users.full_name` met fallback naar `users.name`.
     */
    public string $employeeFullName = '';

    /**
     * Mount-fase.
     *
     *  1. Resolve current user via de `Auth`-facade. Geen user → 403.
     *  2. Verbied rol `boekhouder` — die heeft geen eigen urenregels.
     *     Owner / manager / employee mogen dit scherm gebruiken.
     *  3. Cache de display-naam in `$employeeFullName`.
     *  4. Initialiseer `$weekStart` op de maandag van vandaag in
     *     Europe/Amsterdam.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            // Defensief: routes worden in `web`-middleware-stack geserveerd
            // maar de auth-guard wordt pas in een latere taak vol-geactiveerd.
            // Tests gebruiken `$this->actingAs($user)` zodat dit pad alleen
            // wordt geraakt door anonieme requests in productie.
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role === 'boekhouder') {
            // Boekhouder heeft geen eigen urenregels — read-only rol op
            // rapportages en uren van anderen, niet op eigen week.
            abort(403, 'Geen eigen weekoverzicht voor boekhouder.');
        }

        $this->employeeFullName = (string) ($user->full_name ?? $user->name ?? '');

        // Maandag van vandaag, expliciet in Europe/Amsterdam zodat de
        // navigatie consistent met de Nederlandse weekindeling werkt
        // (ook bij DST-overgangen rond 02:00 's nachts).
        $this->weekStart = Carbon::now('Europe/Amsterdam')
            ->startOfWeek(Carbon::MONDAY)
            ->toDateString();
    }

    /**
     * Schuif een week terug.
     */
    public function previousWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart, 'Europe/Amsterdam')
            ->subDays(7)
            ->toDateString();
        $this->cachedEntriesGroupedByDay = null;
    }

    /**
     * Schuif een week vooruit.
     */
    public function nextWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart, 'Europe/Amsterdam')
            ->addDays(7)
            ->toDateString();
        $this->cachedEntriesGroupedByDay = null;
    }

    /**
     * Spring terug naar de maandag van vandaag.
     */
    public function goToToday(): void
    {
        $this->weekStart = Carbon::now('Europe/Amsterdam')
            ->startOfWeek(Carbon::MONDAY)
            ->toDateString();
        $this->cachedEntriesGroupedByDay = null;
    }

    /**
     * Geef 7 Carbon-instances ma..zo terug, gebaseerd op `$weekStart`.
     * Identiek aan {@see WeekOverviewTable::getWeekDates()}.
     *
     * @return array<int, Carbon>
     */
    public function getWeekDates(): array
    {
        $monday = Carbon::parse($this->weekStart, 'Europe/Amsterdam')->startOfDay();

        return array_map(
            static fn (int $offset): Carbon => $monday->copy()->addDays($offset),
            [0, 1, 2, 3, 4, 5, 6]
        );
    }

    /**
     * Bouw de werkregels van de zichtbare week voor de actor (current user)
     * gegroepeerd op ISO-datum. Iedere dag-key bestaat altijd, ook al heeft
     * de medewerker geen werkregels op die dag (dan een lege array).
     *
     * Het resultaat wordt gecached in `$cachedEntriesGroupedByDay` zodat
     * herhaalde aanroepen (bijv. vanuit getNetMinutesForDay voor 7 dagen)
     * geen extra queries veroorzaken.
     *
     * Per entry retourneren we een sober array zodat de view geen Eloquent-
     * model-magic nodig heeft:
     *  - `id`                 : `int` werkregel-id (voor `wire:click`-payload).
     *  - `start_at`           : `string` ISO datetime (UTC) of leeg.
     *  - `end_at`             : `string` ISO datetime (UTC) of leeg.
     *  - `start_time`         : `string` `H:i` in Europe/Amsterdam — voor display.
     *  - `end_time`           : `string` `H:i` in Europe/Amsterdam — voor display.
     *  - `net_minutes`        : `int`.
     *  - `type`               : `string` (WORK/SICK/LEAVE/HOLIDAY/OTHER).
     *  - `is_finalized`       : `bool`.
     *  - `has_open_objection` : `bool` — bezwaarknop wel/niet tonen.
     *  - `note`               : `string`.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getEntriesGroupedByDay(): array
    {
        if ($this->cachedEntriesGroupedByDay !== null) {
            return $this->cachedEntriesGroupedByDay;
        }
        $weekDates = $this->getWeekDates();
        $monday = $weekDates[0];
        $sunday = $weekDates[6]->copy()->endOfDay();

        $grouped = [];
        foreach ($weekDates as $date) {
            $grouped[$date->toDateString()] = [];
        }

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            $this->cachedEntriesGroupedByDay = $grouped;

            return $grouped;
        }

        $entries = WorkEntry::query()
            ->where('employee_id', (int) $user->id)
            ->whereBetween('entry_date', [$monday->toDateString(), $weekDates[6]->toDateString()])
            ->whereNull('deleted_at')
            ->orderBy('entry_date')
            ->orderBy('start_at')
            ->get();

        if ($entries->isEmpty()) {
            $this->cachedEntriesGroupedByDay = $grouped;

            return $grouped;
        }

        // Open objections per work_entry_id om de bezwaarknop te
        // verbergen voor regels die al een open bezwaar hebben.
        $entryIds = $entries->pluck('id')->map(static fn ($id) => (int) $id)->all();
        /** @var array<int, true> $openObjectionEntryIds */
        $openObjectionEntryIds = [];
        if ($entryIds !== []) {
            $openIds = Objection::query()
                ->whereIn('work_entry_id', $entryIds)
                ->where('status', 'OPEN')
                ->pluck('work_entry_id')
                ->map(static fn ($id) => (int) $id)
                ->all();
            foreach ($openIds as $id) {
                $openObjectionEntryIds[$id] = true;
            }
        }

        foreach ($entries as $entry) {
            $iso = $entry->entry_date instanceof Carbon
                ? $entry->entry_date->toDateString()
                : (string) $entry->entry_date;

            // Defensief: skip wanneer de entry buiten de zichtbare range
            // valt (kan gebeuren bij timezone-edge-cases rond DST).
            if (! array_key_exists($iso, $grouped)) {
                continue;
            }

            $startTime = '';
            $endTime = '';
            if ($entry->start_at instanceof Carbon) {
                $startTime = $entry->start_at->copy()->setTimezone('Europe/Amsterdam')->format('H:i');
            }
            if ($entry->end_at instanceof Carbon) {
                $endTime = $entry->end_at->copy()->setTimezone('Europe/Amsterdam')->format('H:i');
            }

            $entryId = (int) $entry->id;

            $grouped[$iso][] = [
                'id' => $entryId,
                'start_at' => $entry->start_at instanceof Carbon ? $entry->start_at->toIso8601String() : '',
                'end_at' => $entry->end_at instanceof Carbon ? $entry->end_at->toIso8601String() : '',
                'start_time' => $startTime,
                'end_time' => $endTime,
                'net_minutes' => (int) $entry->net_minutes,
                'type' => (string) $entry->type,
                'is_finalized' => (bool) $entry->is_finalized,
                'has_open_objection' => isset($openObjectionEntryIds[$entryId]),
                'note' => (string) ($entry->note ?? ''),
            ];
        }

        $this->cachedEntriesGroupedByDay = $grouped;

        return $grouped;
    }

    /**
     * Som van netto-minuten voor de gegeven dag (Y-m-d).
     */
    public function getNetMinutesForDay(string $iso): int
    {
        $grouped = $this->getEntriesGroupedByDay();

        $total = 0;
        foreach ($grouped[$iso] ?? [] as $entry) {
            $total += (int) ($entry['net_minutes'] ?? 0);
        }

        return $total;
    }

    /**
     * Bezwaar-eligibility per werkregel.
     *
     * Een werkregel is bezwaar-eligible wanneer:
     *  - hij aan de huidige actor toebehoort (impliciet via de query in
     *    {@see getEntriesGroupedByDay()}, hier nogmaals gecheckt voor
     *    een directe call);
     *  - hij `is_finalized = true` is;
     *  - er nog geen open bezwaar op staat.
     *
     * Geen 14-dagen-cap: in `requirements.md` is geen termijn vastgelegd
     * voor bezwaar-indiening; we volgen alleen wat de spec voorschrijft.
     */
    public function canSubmitObjection(int $entryId): bool
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return false;
        }

        /** @var WorkEntry|null $entry */
        $entry = WorkEntry::query()
            ->where('id', $entryId)
            ->where('employee_id', (int) $user->id)
            ->whereNull('deleted_at')
            ->first();

        if ($entry === null) {
            return false;
        }

        if (! (bool) $entry->is_finalized) {
            return false;
        }

        $hasOpen = Objection::query()
            ->where('work_entry_id', $entryId)
            ->where('status', 'OPEN')
            ->exists();

        return ! $hasOpen;
    }

    /**
     * Render de view. De view zelf rendert óók
     * `<livewire:objections.new-objection-form />` als ingebedde modal
     * zodat het `open-new-objection`-event direct opgepikt kan worden
     * zonder extra wiring op de pagina-layout.
     */
    public function render(): View
    {
        return view('livewire.hours.my-week');
    }
}
