<?php

declare(strict_types=1);

namespace App\Livewire\Atw;

use App\Livewire\Hours\EntryFormModal;
use App\Models\AtwViolation;
use App\Models\Team;
use App\Models\User;
use App\Services\AtwEngine;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Atw\StatusDashboard` (taak 11.1 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.5  → scherm "ATW-statusdashboard" op `/atw` met
 *      per medewerker per limiettype (dag/week/16-weken/rust/pauze) een
 *      gekleurde status (groen=ok, geel=warning, rood=critical).
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens
 *      uit `design.md`.
 *  - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      "ATW-statusdashboard | /atw | Atw\StatusDashboard".
 *  - tasks.md 11.1.
 *
 * Verantwoordelijkheid:
 *  - Lees-only weergave van de meest-recente ATW-signalen per medewerker
 *    in een 5-koloms-grid: DAILY_LIMIT, WEEKLY (samengevoegd voor
 *    `WEEKLY_LIMIT` + `WEEKLY_WARNING`), 16W (`SIXTEEN_WEEK_AVERAGE`),
 *    REST (`REST_PERIOD`), PAUSE (`PAUSE_REQUIRED`). Per cel een
 *    `<x-ui.status-badge>` met variant success (groen), warning (geel)
 *    of danger (rood).
 *  - Owner/boekhouder zien alle teams; manager zit vastgepind op het
 *    eigen team. Owner/boekhouder kunnen optioneel scopen via een
 *    team-filter. Pattern parity met
 *    {@see \App\Livewire\Hours\WeekOverviewTable}.
 *
 * Rolinterpretatie (req 6.5):
 *  - employee: 403 — employees zien hun eigen ATW-alerts in
 *    {@see EntryFormModal} en op
 *    `/uren/mijn-week`. Het ATW-dashboard is een management-overzicht.
 *  - manager: zichtbaar voor eigen team (zelfde scope-regels als
 *    `WeekOverviewTable`).
 *  - owner / boekhouder: alle teams binnen eigen organisatie, optioneel
 *    gescoopt via team-filter.
 *
 * Bewust niet:
 *  - Geen route-registratie in `routes/web.php` — wordt opgenomen in een
 *    latere taak (sectie 13 of een interim-taak voor /atw-routes), zelfde
 *    keuze als bij {@see \App\Livewire\Hours\WeekOverviewTable}.
 *  - Geen drill-down naar een per-medewerker detail-view — taak 11.1 vraagt
 *    alleen het overzichtsgrid.
 *  - Geen herberekening van signals on-demand — we lezen de persisted
 *    `atw_violations`-tabel via {@see AtwService::getSignalsForUser}-
 *    equivalent (we doen één bulk-query i.p.v. één per medewerker zodat
 *    de N+1-problematiek vermeden wordt). De inhoud is identiek aan wat
 *    `getSignalsForUser` retourneert; alleen de query-vorm verschilt voor
 *    performance.
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt uitsluitend op `<x-ui.button>`, `<x-ui.card>`,
 *    `<x-ui.status-badge>`. Voor de team-filter wordt bewust een native
 *    `<select>` met `<label>` gebruikt — zelfde deviation-rationale als
 *    {@see \App\Livewire\Hours\WeekOverviewTable}.
 */
#[Layout('layouts.app')]
#[Title('ATW-dashboard — LaVita Urenregistratie')]
final class StatusDashboard extends Component
{
    /**
     * Naam van de organisatie van de ingelogde gebruiker. Wordt in de
     * header van de view getoond; cachen we als property zodat we 'm
     * niet bij elke render opnieuw via een relation moeten resolven.
     */
    public string $organizationName = '';

    /**
     * Optionele team-scope-filter voor owners/boekhouders. `null` = alle
     * teams van de organisatie (default voor owner/boekhouder). Voor
     * managers irrelevant — zij zijn altijd vastgepind op `$user->team_id`.
     */
    public ?int $teamFilter = null;

    /**
     * In-memory cache van de status-matrix
     * `[employee_id => [columnKey => ['severity' => ..., 'message' => ..., ...]]]`
     * zodat we niet bij elke cel-render opnieuw door de violations-loop hoeven.
     * Wordt lazy gevuld via {@see getStatusMatrix()}.
     *
     * @var array<int, array<string, array<string, mixed>>>|null
     */
    private ?array $statusMatrixCache = null;

    /**
     * Mount-fase.
     *
     *  1. Resolve current user via de `Auth`-facade. Geen user → 403.
     *  2. Verbied rol `employee` (zij zien hun eigen ATW-alerts via
     *     `/uren/mijn-week` + EntryFormModal-feedback).
     *  3. Stel `$organizationName` in voor de header.
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
            // Employees zien hun eigen ATW-status in EntryFormModal +
            // /uren/mijn-week. Het dashboard is management-only.
            abort(403, 'Geen toegang tot ATW-dashboard.');
        }

        $this->organizationName = (string) ($user->organization?->name ?? '');
    }

    /**
     * Stel de team-scope-filter in voor owners/boekhouders.
     *
     * Zelfde validatie-pattern als {@see WeekOverviewTable::setTeamFilter}:
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
            $this->statusMatrixCache = null;

            return;
        }

        // Manager: alleen eigen team toestaan.
        if ((string) $user->role === 'manager') {
            if ($user->team_id !== null && $teamId === (int) $user->team_id) {
                $this->teamFilter = $teamId;
                $this->statusMatrixCache = null;

                return;
            }

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
        $this->statusMatrixCache = null;
    }

    /**
     * Geef de 5 grid-kolommen + NL-labels in vaste volgorde terug.
     *
     * Mapping van persisted `violation_type` (zie `AtwEngine::evaluate` +
     * `AtwViolation`) naar grid-kolom:
     *  - `DAILY_LIMIT`         → kolom `DAILY_LIMIT`        ("Daglimiet")
     *  - `WEEKLY_LIMIT`        → kolom `WEEKLY`             ("Weeklimiet")
     *  - `WEEKLY_WARNING`      → kolom `WEEKLY`             ("Weeklimiet")
     *  - `SIXTEEN_WEEK_AVERAGE`→ kolom `SIXTEEN_WEEK_AVERAGE` ("16-weken-gem.")
     *  - `REST_PERIOD`         → kolom `REST_PERIOD`        ("Rusttijd")
     *  - `PAUSE_REQUIRED`      → kolom `PAUSE_REQUIRED`     ("Pauzeplicht")
     *
     * @return array<string, string>
     */
    public function getColumnTypes(): array
    {
        return [
            'DAILY_LIMIT' => 'Daglimiet',
            'WEEKLY' => 'Weeklimiet',
            'SIXTEEN_WEEK_AVERAGE' => '16-weken-gem.',
            'REST_PERIOD' => 'Rusttijd',
            'PAUSE_REQUIRED' => 'Pauzeplicht',
        ];
    }

    /**
     * Bouw de medewerker-collectie voor de zichtbare scope. Identiek aan
     * {@see WeekOverviewTable::getEmployees()}:
     *
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
     * Beschikbare teams voor de team-filter-dropdown. Identiek aan
     * {@see WeekOverviewTable::getAvailableTeams()}.
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
     * Bouw de status-matrix in één pass over de violations van de
     * zichtbare medewerkerset.
     *
     * Resultaat:
     *   `[employee_id => [columnKey => ['severity' => 'ok'|'warning'|'critical',
     *                                   'message' => string|null,
     *                                   'current_minutes' => int|null,
     *                                   'threshold_minutes' => int|null]]]`
     *
     * Iedere combinatie (employee, kolom) bestaat altijd in het resultaat,
     * ook als er geen violation is — dan staat er een `severity = 'ok'`-cel.
     *
     * Algoritme:
     *  1. Vooraf default-cellen `severity=ok` voor alle (employee, kolom).
     *  2. Bulk-query alle `atw_violations` voor de zichtbare employees,
     *     gesorteerd op `created_at DESC` zodat de meest-recente per cel
     *     als eerste wordt geraakt.
     *  3. Per row bepaal het kolom-key via de mapping
     *     (`WEEKLY_LIMIT`/`WEEKLY_WARNING` → `WEEKLY`); plaats alleen
     *     de eerste (= meest recente) match per cel. We slaan
     *     `superseded_at`-rijen over (DELETE-cascade van werkregel maakt
     *     de violation achterhaald, zie taak 4.6).
     *
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function getStatusMatrix(): array
    {
        if ($this->statusMatrixCache !== null) {
            return $this->statusMatrixCache;
        }

        $employees = $this->getEmployees();
        /** @var array<int, int> $employeeIds */
        $employeeIds = $employees->pluck('id')->map(static fn ($id) => (int) $id)->all();

        $columnKeys = array_keys($this->getColumnTypes());

        // Initialiseer alle cellen op 'ok'.
        $matrix = [];
        foreach ($employeeIds as $eid) {
            $matrix[$eid] = [];
            foreach ($columnKeys as $columnKey) {
                $matrix[$eid][$columnKey] = [
                    'severity' => 'ok',
                    'message' => null,
                    'current_minutes' => null,
                    'threshold_minutes' => null,
                    'violation_type' => null,
                ];
            }
        }

        if ($employeeIds === []) {
            $this->statusMatrixCache = $matrix;

            return $matrix;
        }

        // Bulk-query — één SQL i.p.v. N (één per medewerker) en alléén
        // de niet-superseded rijen. Sorteer op `created_at DESC` zodat
        // de meest-recente per cel als eerste binnenkomt; we vullen elke
        // cel maar één keer.
        $violations = AtwViolation::query()
            ->whereIn('user_id', $employeeIds)
            ->whereNull('superseded_at')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->get([
                'id',
                'user_id',
                'violation_type',
                'severity',
                'period_start',
                'period_end',
                'current_minutes',
                'threshold_minutes',
                'details',
                'created_at',
            ]);

        // Houd per (employee, kolom) bij of de cel al gevuld is met de
        // meest-recente violation — als hij gevuld is, slaan we oudere
        // rijen over.
        $filled = [];

        foreach ($violations as $violation) {
            $eid = (int) $violation->user_id;
            if (! isset($matrix[$eid])) {
                // Defensief: violation hoort bij een gebruiker buiten
                // huidige scope (bijv. inactieve user). Skip.
                continue;
            }

            $columnKey = $this->mapViolationTypeToColumn((string) $violation->violation_type);
            if ($columnKey === null) {
                continue;
            }

            $cellKey = $eid.'|'.$columnKey;
            if (isset($filled[$cellKey])) {
                // Cel al gevuld door een nieuwere violation; skip oudere.
                continue;
            }

            $severity = (string) $violation->severity;
            // AtwEngine produceert alleen 'critical' of 'warning'; we
            // mappen alles wat we niet herkennen op 'warning' (defensief).
            if (! in_array($severity, ['critical', 'warning'], true)) {
                $severity = 'warning';
            }

            $matrix[$eid][$columnKey] = [
                'severity' => $severity,
                'message' => (string) ($violation->details ?? ''),
                'current_minutes' => (int) $violation->current_minutes,
                'threshold_minutes' => (int) $violation->threshold_minutes,
                'violation_type' => (string) $violation->violation_type,
            ];

            $filled[$cellKey] = true;
        }

        $this->statusMatrixCache = $matrix;

        return $matrix;
    }

    /**
     * Map `severity` ('ok'|'warning'|'critical') naar een
     * `<x-ui.status-badge>`-variant. Volgens design.md statustabel:
     *
     *  - 'ok'       → 'success' (groen, bg #DCFCE7 / fg #166534)
     *  - 'warning'  → 'warning' (geel,  bg #FEF9C3 / fg #854D0E)
     *  - 'critical' → 'danger'  (rood,  bg #FEE2E2 / fg #991B1B)
     *  - default    → 'concept' (grijs)
     */
    public function getStatusBadgeVariantFor(string $severity): string
    {
        return match ($severity) {
            'ok' => 'success',
            'warning' => 'warning',
            'critical' => 'danger',
            default => 'concept',
        };
    }

    /**
     * Map `severity` naar een Nederlandstalig badge-label.
     *
     *  - 'ok'       → 'OK'
     *  - 'warning'  → 'Waarschuwing'
     *  - 'critical' → 'Overschreden'
     *  - default    → '—'
     */
    public function getStatusBadgeLabelFor(string $severity, ?string $violationType = null): string
    {
        return match ($severity) {
            'ok' => 'OK',
            'warning' => 'Waarschuwing',
            'critical' => 'Overschreden',
            default => '—',
        };
    }

    public function render(): View
    {
        return view('livewire.atw.status-dashboard');
    }

    /**
     * Map een `violation_type` (zoals geproduceerd door
     * {@see AtwEngine::evaluate}) naar de bijbehorende
     * grid-kolom. Geeft `null` terug voor onbekende types zodat de cel
     * niet ten onrechte wordt gemarkeerd.
     */
    private function mapViolationTypeToColumn(string $violationType): ?string
    {
        return match ($violationType) {
            'DAILY_LIMIT' => 'DAILY_LIMIT',
            'WEEKLY_LIMIT', 'WEEKLY_WARNING' => 'WEEKLY',
            'SIXTEEN_WEEK_AVERAGE' => 'SIXTEEN_WEEK_AVERAGE',
            'REST_PERIOD' => 'REST_PERIOD',
            'PAUSE_REQUIRED' => 'PAUSE_REQUIRED',
            default => null,
        };
    }
}
