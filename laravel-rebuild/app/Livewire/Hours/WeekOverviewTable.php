<?php

declare(strict_types=1);

namespace App\Livewire\Hours;

use App\Models\Objection;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\HolidaysService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Hours\WeekOverviewTable` (taak 10.1 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.2  → scherm "Weekoverzicht admin/manager" op
 *      `/uren/week`: tabel met rijen = medewerkers, kolommen = ma t/m zo,
 *      cellen tonen status (vastgesteld/bezwaar/concept/leeg/feestdag)
 *      met badge-kleuren uit design tokens, plus voor manager-rol een
 *      team-scope-filter.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens
 *      uit `design.md`.
 *  - requirements.md 6.14 → Foutmeldingen en bevestigingen in NL.
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Weekoverzicht admin/manager" → component
 *      `Hours\WeekOverviewTable` op `/uren/week`.
 *
 * Verantwoordelijkheid:
 *  - Lees-only weergave van het urenrooster van een organisatie voor één
 *    ISO-week (maandag t/m zondag, Europe/Amsterdam). Per medewerker × dag
 *    één cel met statusbadge en netto-minuten.
 *  - Week-navigatie via Vorige / Vandaag / Volgende.
 *  - Owner/boekhouder zien alle teams, manager ziet alleen het eigen team
 *    (`$user->team_id`). Owner/boekhouder kunnen optioneel scopen via een
 *    team-filter.
 *  - Boekhouder mag bekijken (req 14.7), employee niet (heeft eigen
 *    `MyWeek`-scherm op `/uren/mijn-week`).
 *
 * Bewust niet:
 *  - Geen entry-creatie/-edit-modal — die volgt in taak 10.2
 *    (`Hours\EntryFormModal`).
 *  - Geen route-registratie in `routes/web.php` — dat wordt opgenomen in
 *    een latere taak (sectie 13 of een interim-taak voor /uren-routes).
 *  - Geen feestdagen-tooltip-cel — die hangt aan taak 14.8 zodra de
 *    holidays-tabel bestaat.
 *  - Geen `team_id`-cookie- of session-persistentie van de filter.
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt uitsluitend op `<x-ui.button>`, `<x-ui.card>`,
 *    `<x-ui.status-badge>`. Voor de team-filter wordt bewust een native
 *    `<select>` met `<label>` gebruikt, omdat het bestaande
 *    `<x-ui.text-input>` geen `type=select` ondersteunt; alternatief
 *    introduceren van een nieuwe UI-atom valt buiten scope van taak 10.1
 *    (zie deviation-note in het taakrapport).
 */
#[Layout('layouts.app')]
#[Title('Weekoverzicht — LaVita Urenregistratie')]
final class WeekOverviewTable extends Component
{
    /**
     * Maandag van de zichtbare week, ISO-formaat `Y-m-d`. Wordt in
     * {@see mount()} geïnitialiseerd op de maandag van vandaag in de
     * tijdzone `Europe/Amsterdam` zodat de UI altijd in de Nederlandse
     * weekindeling draait.
     */
    public string $weekStart = '';

    /**
     * Optionele team-scope-filter voor owners/boekhouders. `null` = alle
     * teams van de organisatie (default voor owner/boekhouder). Voor
     * managers irrelevant — zij zijn altijd vastgepind op `$user->team_id`.
     */
    public ?int $teamFilter = null;

    /**
     * Naam van de organisatie van de ingelogde gebruiker. Wordt in de
     * header van de view getoond; cachen we als property zodat we 'm
     * niet bij elke render opnieuw via een relation moeten resolven.
     */
    public string $organizationName = '';

    /**
     * In-memory cache van de status-matrix `[employee_id => [Y-m-d => status]]`
     * zodat we niet bij elke cel-render opnieuw door de entries-loop hoeven.
     * Wordt lazy gevuld via {@see getStatusMatrix()}.
     *
     * @var array<int, array<string, string>>|null
     */
    private ?array $statusMatrixCache = null;

    /**
     * In-memory cache van netto-minuten-matrix `[employee_id => [Y-m-d => minutes]]`.
     *
     * @var array<int, array<string, int>>|null
     */
    private ?array $netMinutesMatrixCache = null;

    /**
     * Mount-fase.
     *
     * 1. Resolve current user via de `Auth`-facade. Geen user → 403.
     * 2. Verbied rol `employee` (zij gebruiken `/uren/mijn-week`).
     * 3. Stel `$organizationName` in voor de header.
     * 4. Initialiseer `$weekStart` op de maandag van vandaag in
     *    Europe/Amsterdam.
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

        if ((string) $user->role === 'employee') {
            // Employee heeft eigen weekoverzicht op /uren/mijn-week.
            abort(403, 'Geen toegang tot weekoverzicht.');
        }

        $this->organizationName = (string) ($user->organization?->name ?? '');

        // Maandag van vandaag, expliciet in Europe/Amsterdam zodat de
        // navigatie consistent met de Nederlandse weekindeling werkt
        // (ook bij DST-overgangen rond 02:00 's nachts).
        $this->weekStart = Carbon::now('Europe/Amsterdam')
            ->startOfWeek(Carbon::MONDAY)
            ->toDateString();
    }

    /**
     * Listener voor het `entry-saved`-event dat door EntryFormModal wordt
     * gedispatcht na een succesvolle werkregel-aanmaak. Reset de matrix-
     * caches zodat de volgende render verse data ophaalt.
     */
    #[On('entry-saved')]
    public function onEntrySaved(): void
    {
        $this->resetMatrixCaches();
    }

    /**
     * Schuif een week terug. Reset de in-memory matrix-caches zodat de
     * volgende render verse data ophaalt.
     */
    public function previousWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart, 'Europe/Amsterdam')
            ->subDays(7)
            ->toDateString();

        $this->resetMatrixCaches();
    }

    /**
     * Schuif een week vooruit. Reset de matrix-caches.
     */
    public function nextWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart, 'Europe/Amsterdam')
            ->addDays(7)
            ->toDateString();

        $this->resetMatrixCaches();
    }

    /**
     * Spring terug naar de maandag van vandaag.
     */
    public function goToToday(): void
    {
        $this->weekStart = Carbon::now('Europe/Amsterdam')
            ->startOfWeek(Carbon::MONDAY)
            ->toDateString();

        $this->resetMatrixCaches();
    }

    /**
     * Stel de team-scope-filter in voor owners/boekhouders.
     *
     * Validatie:
     *  - `null` of een geldig integer-id binnen de eigen organisatie wordt
     *    geaccepteerd; ongeldige waarden worden stilzwijgend genegeerd
     *    (filter blijft zoals hij was) en een NL-foutmelding op
     *    `teamFilter` wordt toegevoegd voor screenreaders.
     *  - Managers kunnen hun filter niet wijzigen (zij zijn vastgepind);
     *    we accepteren `null` of het eigen `team_id`, anders silent reject.
     */
    public function setTeamFilter(?int $teamId): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            // Geen sessie → niets doen; defensief, productie wordt
            // afgevangen door auth-middleware (taak 9.x/10.x).
            return;
        }

        if ($teamId === null) {
            $this->teamFilter = null;
            $this->resetMatrixCaches();

            return;
        }

        // Manager: alleen eigen team toestaan.
        if ((string) $user->role === 'manager') {
            if ($user->team_id !== null && $teamId === (int) $user->team_id) {
                $this->teamFilter = $teamId;
                $this->resetMatrixCaches();

                return;
            }

            // Andere teams negeren we voor managers.
            $this->addError('teamFilter', 'Je kunt alleen je eigen team filteren.');

            return;
        }

        // Owner / boekhouder: team moet binnen eigen organisatie liggen.
        $exists = Team::where('organization_id', (int) $user->organization_id)
            ->where('id', $teamId)
            ->exists();

        if (! $exists) {
            $this->addError('teamFilter', 'Onbekend team.');

            return;
        }

        $this->teamFilter = $teamId;
        $this->resetMatrixCaches();
    }

    /**
     * Geef 7 Carbon-instances ma..zo terug, gebaseerd op `$weekStart`.
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
     * Bouw de medewerker-collectie voor de zichtbare scope.
     *
     * Filters:
     *  - `organization_id` = die van de actieve gebruiker.
     *  - `role` ∈ {employee, manager, owner} — boekhouder werkt niet en
     *    verschijnt dus niet als rij.
     *  - `is_active` = true.
     *  - manager → vast op eigen `team_id`.
     *  - owner / boekhouder → respect `$teamFilter` indien gezet.
     *  - sorteer op `full_name` ASC, dan `name` ASC voor stabiele volgorde.
     */
    public function getEmployees(): Collection
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return collect();
        }

        $query = User::query()
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('role', ['employee', 'manager', 'owner'])
            ->where('is_active', true);

        if ((string) $user->role === 'manager') {
            // Manager altijd vastgepind op eigen team; ook null wordt
            // gerespecteerd (manager zonder team ziet niemand).
            $query->where('team_id', $user->team_id);
        } elseif ($this->teamFilter !== null) {
            $query->where('team_id', $this->teamFilter);
        }

        return $query
            ->orderByRaw('COALESCE(full_name, name) ASC')
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Beschikbare teams voor de team-filter-dropdown.
     *
     * - Manager: 1-element collectie met het eigen team (of leeg).
     * - Owner/boekhouder: alle teams binnen eigen organisatie, alfabetisch.
     */
    public function getAvailableTeams(): Collection
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return collect();
        }

        if ((string) $user->role === 'manager') {
            if ($user->team_id === null) {
                return collect();
            }

            return Team::where('id', (int) $user->team_id)
                ->where('organization_id', (int) $user->organization_id)
                ->get();
        }

        return Team::where('organization_id', (int) $user->organization_id)
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Haal feestdagen op voor de zichtbare week.
     *
     * Retourneert een map [Y-m-d => naam] zodat de view per cel kan
     * controleren of het een feestdag is en de naam als tooltip kan tonen.
     *
     * Requirements: 7.7
     *
     * @return array<string, string>
     */
    public function getHolidaysForWeek(): array
    {
        $monday = Carbon::parse($this->weekStart, 'Europe/Amsterdam');
        $year = (int) $monday->year;

        /** @var HolidaysService $service */
        $service = app(HolidaysService::class);
        $holidays = $service->forYear($year);

        // Mogelijk overlapt de week twee jaren (bijv. week 1 januari)
        $sunday = $monday->copy()->addDays(6);
        if ($sunday->year !== $year) {
            $holidays = array_merge($holidays, $service->forYear((int) $sunday->year));
        }

        $weekDates = [];
        for ($i = 0; $i < 7; $i++) {
            $weekDates[] = $monday->copy()->addDays($i)->toDateString();
        }

        $map = [];
        foreach ($holidays as $holiday) {
            if (in_array($holiday['date'], $weekDates, true)) {
                $map[$holiday['date']] = $holiday['name'];
            }
        }

        return $map;
    }

    /**
     * Bouw de status- en netto-minuten-matrix in één pass over de
     * werkregels van de zichtbare week + medewerkerset. We doen één
     * SQL-query voor entries en één voor open objections, en cachen
     * het resultaat in private properties zodat herhaalde view-renders
     * van dezelfde request goedkoop zijn.
     *
     * Statusbepaling per cel:
     *  - geen entries        → 'empty'
     *  - >=1 OPEN-objection  → 'objection'
     *  - >=1 entry !finalized→ 'draft'
     *  - alles finalized      → 'finalized'
     *
     * @return array<int, array<string, string>>
     */
    public function getStatusMatrix(): array
    {
        if ($this->statusMatrixCache !== null) {
            return $this->statusMatrixCache;
        }

        [$matrixStatus, $matrixMinutes] = $this->buildMatrices();

        $this->statusMatrixCache = $matrixStatus;
        $this->netMinutesMatrixCache = $matrixMinutes;

        return $matrixStatus;
    }

    /**
     * Lookup helper — sla het matrix-rebuild over wanneer al opgebouwd.
     */
    public function getStatusForCell(int $employeeId, string $isoDate): string
    {
        $matrix = $this->getStatusMatrix();

        return $matrix[$employeeId][$isoDate] ?? 'empty';
    }

    /**
     * Som van netto-minuten voor (employee, dag). 0 wanneer leeg.
     */
    public function getNetMinutesForCell(int $employeeId, string $isoDate): int
    {
        if ($this->netMinutesMatrixCache === null) {
            // Door getStatusMatrix() aan te roepen vullen we beide caches in
            // één keer; we negeren bewust de return-value.
            $this->getStatusMatrix();
        }

        return (int) ($this->netMinutesMatrixCache[$employeeId][$isoDate] ?? 0);
    }

    /**
     * Map status-string naar `<x-ui.status-badge>`-variant.
     *
     *  - 'finalized' → 'success'  (bg #DCFCE7 / fg #166534)
     *  - 'draft'     → 'concept'  (bg #f7f7f7 / fg #5a5a5c)
     *  - 'objection' → 'warning'  (bg #FEF9C3 / fg #854D0E)
     *  - 'empty'     → 'concept'  (bg #f7f7f7 / fg #5a5a5c)
     */
    public function getStatusBadgeVariantFor(string $status): string
    {
        return match ($status) {
            'finalized' => 'success',
            'objection' => 'warning',
            'draft', 'empty' => 'concept',
            default => 'concept',
        };
    }

    /**
     * Map status-string naar Nederlandstalig label voor de badge.
     */
    public function getStatusBadgeLabelFor(string $status): string
    {
        return match ($status) {
            'finalized' => 'Vastgesteld',
            'draft' => 'Concept',
            'objection' => 'Bezwaar open',
            'empty' => '—',
            default => '—',
        };
    }

    public function render(): View
    {
        return view('livewire.hours.week-overview-table');
    }

    /**
     * Bouw status- en netto-minuten-matrices in één pass.
     *
     * @return array{0: array<int, array<string, string>>, 1: array<int, array<string, int>>}
     */
    private function buildMatrices(): array
    {
        $employees = $this->getEmployees();
        /** @var array<int, int> $employeeIds */
        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();

        $monday = Carbon::parse($this->weekStart, 'Europe/Amsterdam')->startOfDay();
        $sunday = $monday->copy()->addDays(6)->endOfDay();

        $matrixStatus = [];
        $matrixMinutes = [];

        foreach ($employeeIds as $eid) {
            $matrixStatus[$eid] = [];
            $matrixMinutes[$eid] = [];

            for ($i = 0; $i < 7; $i++) {
                $iso = $monday->copy()->addDays($i)->toDateString();
                $matrixStatus[$eid][$iso] = 'empty';
                $matrixMinutes[$eid][$iso] = 0;
            }
        }

        if ($employeeIds === []) {
            return [$matrixStatus, $matrixMinutes];
        }

        // Entries in één SQL-query ophalen.
        $entries = WorkEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('entry_date', [$monday->toDateString(), $sunday->toDateString()])
            ->whereNull('deleted_at')
            ->get(['id', 'employee_id', 'entry_date', 'net_minutes', 'is_finalized']);

        // Open objections per work_entry_id.
        /** @var array<int, int> $openObjectionEntryIds — set: entry_id => 1 */
        $openObjectionEntryIds = [];
        if ($entries->isNotEmpty()) {
            $entryIds = $entries->pluck('id')->map(fn ($id) => (int) $id)->all();
            $openIds = Objection::query()
                ->whereIn('work_entry_id', $entryIds)
                ->where('status', 'OPEN')
                ->pluck('work_entry_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($openIds as $id) {
                $openObjectionEntryIds[$id] = 1;
            }
        }

        // Aggregeer per cel.
        // Houd per cel bij: heeft_objection, heeft_draft, heeft_finalized.
        $hasObjection = [];
        $hasDraft = [];
        $hasFinalized = [];

        foreach ($entries as $entry) {
            $eid = (int) $entry->employee_id;
            $iso = $entry->entry_date instanceof Carbon
                ? $entry->entry_date->toDateString()
                : (string) $entry->entry_date;

            // Cel kan ontbreken in matrix wanneer employee buiten scope viel
            // tussen twee renders; defensief skippen.
            if (! isset($matrixStatus[$eid][$iso])) {
                continue;
            }

            $matrixMinutes[$eid][$iso] += (int) $entry->net_minutes;

            $entryId = (int) $entry->id;
            if (isset($openObjectionEntryIds[$entryId])) {
                $hasObjection["$eid|$iso"] = true;
            } elseif ((bool) $entry->is_finalized) {
                $hasFinalized["$eid|$iso"] = true;
            } else {
                $hasDraft["$eid|$iso"] = true;
            }
        }

        // Bepaal eind-status per cel volgens de prioriteit:
        // objection > draft > finalized > empty.
        foreach (array_keys($hasFinalized) as $key) {
            [$eidStr, $iso] = explode('|', $key, 2);
            $matrixStatus[(int) $eidStr][$iso] = 'finalized';
        }
        foreach (array_keys($hasDraft) as $key) {
            [$eidStr, $iso] = explode('|', $key, 2);
            $matrixStatus[(int) $eidStr][$iso] = 'draft';
        }
        foreach (array_keys($hasObjection) as $key) {
            [$eidStr, $iso] = explode('|', $key, 2);
            $matrixStatus[(int) $eidStr][$iso] = 'objection';
        }

        return [$matrixStatus, $matrixMinutes];
    }

    /**
     * Reset de in-memory matrix-caches zodat de volgende
     * `getStatusMatrix()`-aanroep opnieuw uit de database leest.
     */
    private function resetMatrixCaches(): void
    {
        $this->statusMatrixCache = null;
        $this->netMinutesMatrixCache = null;
    }
}
