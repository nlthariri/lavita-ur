<?php

declare(strict_types=1);

namespace App\Livewire\Hours;

use App\Models\AtwViolation;
use App\Models\Objection;
use App\Models\Team;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\CopyWeekService;
use App\Services\HolidaysService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Exceptions\HttpResponseException;
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
     * In-memory cache van type-matrix `[employee_id => [Y-m-d => type]]`.
     * Type is WORK, SICK, LEAVE, HOLIDAY of null (leeg).
     *
     * @var array<int, array<string, string|null>>|null
     */
    private ?array $typeMatrixCache = null;

    /**
     * In-memory cache van entry-ID-matrix `[employee_id => [Y-m-d => entry_id]]`.
     * Bevat het ID van de eerste entry per cel, zodat de invoermodal in
     * edit-modus kan worden geopend. `null` = geen entry op die dag.
     *
     * @var array<int, array<string, int|null>>|null
     */
    private ?array $entryIdMatrixCache = null;

    /**
     * In-memory cache van ATW-violations per cel `[employee_id|Y-m-d => severity]`.
     * Severity is 'critical' of 'warning'.
     *
     * @var array<string, string>|null
     */
    private ?array $atwViolationsCache = null;

    /**
     * Geeft aan of de bevestigingsmodal voor copy-week zichtbaar is.
     */
    public bool $showCopyWeekModal = false;

    /**
     * Loading state voor de copy-week operatie.
     */
    public bool $copyWeekLoading = false;

    /**
     * Details van overgeslagen regels na copy-week (voor warning-toast detail).
     *
     * @var array<int, array{date: string, start_time: string, reason: string}>
     */
    public array $copyWeekSkippedDetails = [];

    /**
     * In-memory cache van per-medewerker ATW-status.
     * [employee_id => ['severity' => 'warning'|'critical', 'violations' => [...]]].
     * Requirement 15.1: indicator naast medewerker-naam.
     *
     * @var array<int, array{severity: string, violations: array<int, array{type: string, current_minutes: int, threshold_minutes: int}>}>|null
     */
    private ?array $employeeAtwStatusCache = null;

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
     * Open de bevestigingsmodal voor copy-week.
     * Requirement 5.2: bevestigingsmodal met bron/doel-week datums.
     */
    public function openCopyWeekModal(): void
    {
        $this->showCopyWeekModal = true;
        $this->copyWeekSkippedDetails = [];
    }

    /**
     * Sluit de bevestigingsmodal voor copy-week.
     */
    public function closeCopyWeekModal(): void
    {
        $this->showCopyWeekModal = false;
        $this->copyWeekSkippedDetails = [];
    }

    /**
     * Voer de copy-week operatie uit.
     *
     * Kopieert werkregels van de vorige week naar de huidige week voor alle
     * medewerkers in de zichtbare scope. Geeft toast-feedback op basis van
     * het resultaat.
     *
     * Requirements: 5.3, 5.4, 5.5, 5.6, 5.7
     */
    public function executeCopyWeek(): void
    {
        $this->copyWeekLoading = true;

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            $this->copyWeekLoading = false;
            $this->showCopyWeekModal = false;

            return;
        }

        // Alleen owner/manager mag kopiëren (Requirement 5.8)
        if (! in_array((string) $user->role, ['owner', 'manager'], true)) {
            $this->copyWeekLoading = false;
            $this->showCopyWeekModal = false;

            return;
        }

        $targetMonday = $this->weekStart;
        $sourceMonday = Carbon::parse($this->weekStart, 'Europe/Amsterdam')
            ->subDays(7)
            ->toDateString();

        // Haal de zichtbare medewerkers op (scope-filtering)
        $employees = $this->getEmployees();
        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($employeeIds === []) {
            $this->copyWeekLoading = false;
            $this->showCopyWeekModal = false;
            $this->dispatch('toast', variant: 'info', message: 'Geen medewerkers in de huidige scope.');

            return;
        }

        /** @var CopyWeekService $copyWeekService */
        $copyWeekService = app(CopyWeekService::class);

        $totalCreated = 0;
        $totalSkipped = 0;
        $allSkippedDetails = [];
        $sourceWeekEmpty = true;

        foreach ($employeeIds as $employeeId) {
            try {
                $result = $copyWeekService->copyWeek($employeeId, $sourceMonday, $targetMonday, $user->id);
                $sourceWeekEmpty = false;
                $totalCreated += count($result['created']);
                $totalSkipped += count($result['skipped']);
                $allSkippedDetails = array_merge($allSkippedDetails, $result['skipped']);
            } catch (HttpResponseException $e) {
                $response = $e->getResponse();
                $statusCode = $response->getStatusCode();

                if ($statusCode === 422) {
                    // SOURCE_WEEK_EMPTY voor deze medewerker — ga door met de volgende
                    continue;
                }

                if ($statusCode === 403) {
                    // Scope-overtreding — skip deze medewerker
                    continue;
                }

                // Onverwachte fout — stop en toon error
                $this->copyWeekLoading = false;
                $this->showCopyWeekModal = false;
                $this->dispatch('toast', variant: 'error', message: 'Er is een fout opgetreden bij het kopiëren.');

                return;
            }
        }

        // Resultaat verwerken
        $this->copyWeekLoading = false;
        $this->showCopyWeekModal = false;

        if ($sourceWeekEmpty && $totalCreated === 0 && $totalSkipped === 0) {
            // Requirement 5.6: bronweek leeg
            $this->dispatch('toast', variant: 'info', message: 'Vorige week bevat geen werkregels om te kopiëren.');
        } elseif ($totalSkipped > 0) {
            // Requirement 5.5: warning met overgeslagen regels
            $this->copyWeekSkippedDetails = $allSkippedDetails;
            $this->dispatch('toast', variant: 'warning', message: "Week gekopieerd met {$totalSkipped} overgeslagen regels.");
        } else {
            // Requirement 5.4: success
            $this->dispatch('toast', variant: 'success', message: "Week gekopieerd: {$totalCreated} regels aangemaakt.");
        }

        // Ververs het weekoverzicht
        $this->resetMatrixCaches();
    }

    /**
     * Geeft de bron-week datums terug voor de bevestigingsmodal.
     * Format: "maandag DD-MM-YYYY - zondag DD-MM-YYYY"
     */
    public function getSourceWeekLabel(): string
    {
        $sourceMonday = Carbon::parse($this->weekStart, 'Europe/Amsterdam')->subDays(7);
        $sourceSunday = $sourceMonday->copy()->addDays(6);

        return $sourceMonday->format('d-m-Y') . ' - ' . $sourceSunday->format('d-m-Y');
    }

    /**
     * Geeft de doel-week datums terug voor de bevestigingsmodal.
     * Format: "maandag DD-MM-YYYY - zondag DD-MM-YYYY"
     */
    public function getTargetWeekLabel(): string
    {
        $targetMonday = Carbon::parse($this->weekStart, 'Europe/Amsterdam');
        $targetSunday = $targetMonday->copy()->addDays(6);

        return $targetMonday->format('d-m-Y') . ' - ' . $targetSunday->format('d-m-Y');
    }

    /**
     * Bepaalt of de huidige gebruiker de copy-week knop mag zien.
     * Requirement 5.1, 5.8: alleen owner en manager.
     */
    public function canCopyWeek(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return in_array((string) $user->role, ['owner', 'manager'], true);
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

    /**
     * Haal het werkregel-type op voor een cel (WORK, SICK, LEAVE, HOLIDAY of null).
     * Requirement 4.1, 4.10: kleurcodering ongeacht is_finalized.
     */
    public function getTypeForCell(int $employeeId, string $isoDate): ?string
    {
        if ($this->typeMatrixCache === null) {
            $this->getStatusMatrix(); // Bouwt alle caches op
        }

        return $this->typeMatrixCache[$employeeId][$isoDate] ?? null;
    }

    /**
     * Haal het werk-entry-ID op voor een cel (eerste entry op die dag).
     * Retourneert null als de cel leeg is.
     */
    public function getEntryIdForCell(int $employeeId, string $isoDate): ?int
    {
        if ($this->entryIdMatrixCache === null) {
            $this->getStatusMatrix(); // Bouwt alle caches op
        }

        return $this->entryIdMatrixCache[$employeeId][$isoDate] ?? null;
    }

    /**
     * Geeft de CSS-klassen voor kleurcodering van een cel op basis van type.
     * Requirement 4.1: Color_Coding mapping.
     *
     * @return array{bg: string, border: string}
     */
    public static function getColorCodingClasses(?string $type): array
    {
        return match ($type) {
            'WORK' => ['bg' => 'bg-emerald-50', 'border' => 'border-l-4 border-brand-green'],
            'SICK' => ['bg' => 'bg-red-50', 'border' => 'border-l-4 border-danger'],
            'LEAVE' => ['bg' => 'bg-blue-50', 'border' => 'border-l-4 border-blue-500'],
            'HOLIDAY' => ['bg' => 'bg-purple-50', 'border' => 'border-l-4 border-purple-500'],
            default => ['bg' => 'bg-canvas', 'border' => 'border-l-4 border-dashed border-hairline'],
        };
    }

    /**
     * Bereken het weektotaal (som netto-minuten) voor een medewerker.
     * Requirement 4.3: totaal-kolom per medewerker.
     */
    public function getWeekTotalForEmployee(int $employeeId): int
    {
        if ($this->netMinutesMatrixCache === null) {
            $this->getStatusMatrix();
        }

        $employeeMinutes = $this->netMinutesMatrixCache[$employeeId] ?? [];

        return array_sum($employeeMinutes);
    }

    /**
     * Bereken het dagtotaal (som netto-minuten) voor alle zichtbare medewerkers op een dag.
     * Requirement 4.4: totaal-rij per dag.
     */
    public function getDayTotal(string $isoDate): int
    {
        if ($this->netMinutesMatrixCache === null) {
            $this->getStatusMatrix();
        }

        $total = 0;
        foreach ($this->netMinutesMatrixCache as $employeeMinutes) {
            $total += ($employeeMinutes[$isoDate] ?? 0);
        }

        return $total;
    }

    /**
     * Bereken het Grand_Total (som van alle uren van alle medewerkers voor de hele week).
     * Requirement 4.5: Grand_Total rechtsonder.
     */
    public function getGrandTotal(): int
    {
        if ($this->netMinutesMatrixCache === null) {
            $this->getStatusMatrix();
        }

        $total = 0;
        foreach ($this->netMinutesMatrixCache as $employeeMinutes) {
            $total += array_sum($employeeMinutes);
        }

        return $total;
    }

    /**
     * Formatteer minuten naar HH:mm formaat.
     * Requirement 4.3, 4.4, 4.5: totalen in HH:mm formaat.
     */
    public static function formatMinutesToHHmm(int $minutes): string
    {
        if ($minutes <= 0) {
            return '00:00';
        }

        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return sprintf('%02d:%02d', $h, $m);
    }

    /**
     * Genereer tooltip-tekst voor een cel.
     * Requirement 4.6: tooltips bij hover.
     */
    public function getTooltipForCell(int $employeeId, string $employeeName, string $isoDate): string
    {
        $type = $this->getTypeForCell($employeeId, $isoDate);

        if ($type === null) {
            // Lege cel
            $date = Carbon::parse($isoDate, 'Europe/Amsterdam');
            $dayName = $this->getDutchDayName($date);

            return "Klik om uren in te voeren voor {$employeeName} op {$dayName} {$date->format('d-m-Y')}";
        }

        // Gevulde cel: "[type]: [HH:mm] netto"
        $minutes = $this->getNetMinutesForCell($employeeId, $isoDate);
        $formatted = self::formatMinutesToHHmm($minutes);
        $typeLabel = $this->getDutchTypeLabel($type);

        return "{$typeLabel}: {$formatted} netto";
    }

    /**
     * Controleer of een cel een ATW-waarschuwing heeft.
     * Requirement 4.7: oranje rand + icoon bij cellen met violations.
     *
     * @return string|null 'warning' of 'critical', of null als geen violation
     */
    public function getAtwViolationForCell(int $employeeId, string $isoDate): ?string
    {
        if ($this->atwViolationsCache === null) {
            $this->buildAtwViolationsCache();
        }

        return $this->atwViolationsCache["{$employeeId}|{$isoDate}"] ?? null;
    }

    /**
     * Haal de per-medewerker ATW-status op voor de zichtbare week.
     * Requirement 15.1: indicator naast medewerker-naam (oranje driehoek / rood uitroepteken).
     * Requirement 15.3: opgehaald in dezelfde query als de status-matrix (geen extra roundtrips).
     *
     * @return array{severity: string, violations: array<int, array{type: string, current_minutes: int, threshold_minutes: int}>}|null
     */
    public function getAtwStatusForEmployee(int $employeeId): ?array
    {
        if ($this->employeeAtwStatusCache === null) {
            $this->buildAtwViolationsCache();
        }

        return $this->employeeAtwStatusCache[$employeeId] ?? null;
    }

    /**
     * Genereer tooltip-tekst voor de per-medewerker ATW-indicator.
     * Requirement 15.2: tooltip met overtreding-type.
     *
     * Formaten:
     *  - "Weekwaarschuwing: [X]u van max 48u"
     *  - "Weeklimiet overschreden: [X]u van max 60u"
     *  - "Rusttijd te kort: [X]u van min 11u"
     */
    public function getAtwTooltipForEmployee(int $employeeId): string
    {
        $status = $this->getAtwStatusForEmployee($employeeId);

        if ($status === null) {
            return '';
        }

        $lines = [];
        foreach ($status['violations'] as $violation) {
            $currentHours = round($violation['current_minutes'] / 60, 0);
            $thresholdHours = round($violation['threshold_minutes'] / 60, 0);

            $lines[] = match ($violation['type']) {
                'WEEKLY_WARNING' => "Weekwaarschuwing: {$currentHours}u van max {$thresholdHours}u",
                'WEEKLY_LIMIT', 'DAILY_LIMIT' => "Weeklimiet overschreden: {$currentHours}u van max {$thresholdHours}u",
                'REST_PERIOD' => "Rusttijd te kort: {$currentHours}u van min {$thresholdHours}u",
                default => "ATW-overtreding: {$violation['type']}",
            };
        }

        return implode("\n", array_unique($lines));
    }

    /**
     * Nederlandse dagnaam voor tooltip.
     */
    private function getDutchDayName(Carbon $date): string
    {
        $days = ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];

        return $days[$date->dayOfWeekIso - 1];
    }

    /**
     * Nederlands label voor werkregel-type.
     */
    private function getDutchTypeLabel(string $type): string
    {
        return match ($type) {
            'WORK' => 'Werk',
            'SICK' => 'Ziek',
            'LEAVE' => 'Verlof',
            'HOLIDAY' => 'Feestdag',
            default => $type,
        };
    }

    /**
     * Bouw de ATW-violations cache op voor de zichtbare week.
     * Haalt alle actieve (niet-superseded) violations op voor de medewerkers
     * in de huidige scope en week.
     *
     * Bouwt tegelijkertijd de per-medewerker ATW-status cache op
     * (Requirement 15.3: geen extra roundtrips).
     */
    private function buildAtwViolationsCache(): void
    {
        $this->atwViolationsCache = [];
        $this->employeeAtwStatusCache = [];

        $employees = $this->getEmployees();
        $employeeIds = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($employeeIds === []) {
            return;
        }

        $monday = Carbon::parse($this->weekStart, 'Europe/Amsterdam')->startOfDay();
        $sunday = $monday->copy()->addDays(6)->endOfDay();

        // Haal violations op die gekoppeld zijn aan work_entries in deze week
        $violations = AtwViolation::query()
            ->whereIn('user_id', $employeeIds)
            ->whereNull('superseded_at')
            ->whereHas('workEntry', function ($query) use ($monday, $sunday) {
                $query->whereBetween('entry_date', [$monday->toDateString(), $sunday->toDateString()])
                    ->whereNull('deleted_at');
            })
            ->with(['workEntry:id,employee_id,entry_date'])
            ->get(['id', 'user_id', 'work_entry_id', 'severity', 'violation_type', 'current_minutes', 'threshold_minutes']);

        // Per-medewerker aggregatie voor de naam-indicator
        $employeeViolations = [];

        foreach ($violations as $violation) {
            if ($violation->workEntry === null) {
                continue;
            }

            $eid = (int) $violation->user_id;
            $iso = $violation->workEntry->entry_date instanceof Carbon
                ? $violation->workEntry->entry_date->toDateString()
                : (string) $violation->workEntry->entry_date;

            // Cel-level cache (bestaande functionaliteit)
            $key = "{$eid}|{$iso}";

            // Bewaar de hoogste severity (critical > warning)
            $existing = $this->atwViolationsCache[$key] ?? null;
            if ($existing === null || ($violation->severity === 'critical' && $existing === 'warning')) {
                $this->atwViolationsCache[$key] = $violation->severity;
            }

            // Per-medewerker aggregatie (Requirement 15.1)
            if (! isset($employeeViolations[$eid])) {
                $employeeViolations[$eid] = [
                    'severity' => $violation->severity,
                    'violations' => [],
                ];
            }

            // Bewaar hoogste severity per medewerker
            if ($violation->severity === 'critical' && $employeeViolations[$eid]['severity'] === 'warning') {
                $employeeViolations[$eid]['severity'] = 'critical';
            }

            // Voeg violation-details toe voor tooltip (Requirement 15.2)
            $employeeViolations[$eid]['violations'][] = [
                'type' => (string) $violation->violation_type,
                'current_minutes' => (int) $violation->current_minutes,
                'threshold_minutes' => (int) $violation->threshold_minutes,
            ];
        }

        $this->employeeAtwStatusCache = $employeeViolations;
    }

    public function render(): View
    {
        return view('livewire.hours.week-overview-table');
    }

    /**
     * Bouw status-, netto-minuten- en type-matrices in één pass.
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
        $matrixType = [];
        $matrixEntryId = [];

        foreach ($employeeIds as $eid) {
            $matrixStatus[$eid] = [];
            $matrixMinutes[$eid] = [];
            $matrixType[$eid] = [];
            $matrixEntryId[$eid] = [];

            for ($i = 0; $i < 7; $i++) {
                $iso = $monday->copy()->addDays($i)->toDateString();
                $matrixStatus[$eid][$iso] = 'empty';
                $matrixMinutes[$eid][$iso] = 0;
                $matrixType[$eid][$iso] = null;
                $matrixEntryId[$eid][$iso] = null;
            }
        }

        if ($employeeIds === []) {
            $this->typeMatrixCache = $matrixType;
            $this->entryIdMatrixCache = $matrixEntryId;

            return [$matrixStatus, $matrixMinutes];
        }

        // Entries in één SQL-query ophalen (inclusief type voor kleurcodering).
        $entries = WorkEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('entry_date', [$monday->toDateString(), $sunday->toDateString()])
            ->whereNull('deleted_at')
            ->get(['id', 'employee_id', 'entry_date', 'net_minutes', 'is_finalized', 'type']);

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

            // Entry-ID opslaan — bij meerdere entries per cel, neem het eerste ID.
            if ($matrixEntryId[$eid][$iso] === null) {
                $matrixEntryId[$eid][$iso] = (int) $entry->id;
            }

            // Type opslaan — bij meerdere entries per cel, neem het eerste
            // niet-null type (prioriteit: WORK > SICK > LEAVE > HOLIDAY).
            if ($matrixType[$eid][$iso] === null && $entry->type !== null) {
                $matrixType[$eid][$iso] = (string) $entry->type;
            }

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

        $this->typeMatrixCache = $matrixType;
        $this->entryIdMatrixCache = $matrixEntryId;

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
        $this->typeMatrixCache = null;
        $this->entryIdMatrixCache = null;
        $this->atwViolationsCache = null;
        $this->employeeAtwStatusCache = null;
    }
}
