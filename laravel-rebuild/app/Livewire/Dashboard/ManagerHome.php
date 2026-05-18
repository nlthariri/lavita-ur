<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Livewire\Atw\StatusDashboard;
use App\Livewire\Hours\WeekOverviewTable;
use App\Models\AtwViolation;
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
 * Livewire-component — `Dashboard\ManagerHome` (taak 11.3 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.9  → scherm "Managementdashboard" met aanwezigheid
 *      huidige week, openstaande bezwaren teller, ATW-status samenvatting,
 *      snelkoppelingen naar weekoverzicht en rapportages.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
 *      `design.md`.
 *  - requirements.md 6.14 → NL-labels en NL-bevestigingen (NFR-10).
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      "Managementdashboard | /dashboard | Dashboard\ManagerHome".
 *  - tasks.md 11.3.
 *
 * Verantwoordelijkheid:
 *  - Lees-only samenvattingsscherm voor manager/owner/boekhouder met:
 *      (a) aanwezigheid huidige week (distinct medewerkers met
 *          tenminste één werkregel ma..zo Europe/Amsterdam),
 *      (b) openstaande bezwaren teller (`Objection.status = 'OPEN'`),
 *      (c) ATW-meldingen-teller gesplitst in critical en warning
 *          (distinct user_id per severity, niet-superseded),
 *      (d) snelkoppelingen naar weekoverzicht, ATW, rapportages,
 *          bezwaren en (alleen voor owner) accountbeheer.
 *  - Owner/boekhouder zien alle teams binnen eigen organisatie. Manager
 *    is vastgepind op het eigen team — alle counters worden gefilterd op
 *    `team_id = $user->team_id` (presence- en ATW-tellers via de
 *    employees-set, bezwaren via de werkregels in dat team).
 *
 * Rolinterpretatie (req 6.9):
 *  - employee: 403 — employees zien hun eigen weekoverzicht via
 *    `/uren/mijn-week` en hun eigen ATW-feedback in de EntryFormModal.
 *  - manager: zichtbaar voor eigen team (zelfde scope-regels als
 *    {@see WeekOverviewTable} en
 *    {@see StatusDashboard}).
 *  - owner / boekhouder: alle teams binnen eigen organisatie.
 *
 * Bewust niet:
 *  - Geen route-registratie in `routes/web.php` — de web-route op
 *    `/dashboard` wordt opgenomen in een latere taak (sectie 13 of een
 *    interim-taak voor de dashboard-route). Pattern parity met
 *    {@see WeekOverviewTable} en
 *    {@see StatusDashboard}.
 *  - Geen interactieve filters of drill-down — taak 11.3 vraagt alleen
 *    een statisch read-only overzicht. Alle counters worden in mount()
 *    één keer berekend en daarna in de view getoond. Dat past bij het
 *    karakter van een "home"-scherm en houdt de pagina snel.
 *  - Geen quick-link voor `/instellingen/email` of `/verlof` — taak 11.3
 *    noemt alleen weekoverzicht / ATW / rapportages / bezwaren /
 *    accountbeheer als snelkoppelingen.
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt uitsluitend op `<x-ui.button>`, `<x-ui.card>` en
 *    `<x-ui.status-badge>`; geen nieuwe atoms.
 */
#[Layout('layouts.app')]
#[Title('Dashboard — LaVita Urenregistratie')]
final class ManagerHome extends Component
{
    /**
     * Volledige naam (of `name`-fallback) van de ingelogde gebruiker —
     * wordt in de header van het dashboard getoond als persoonlijke
     * begroeting. Cachen we als property zodat we niet bij elke render
     * de relation opnieuw resolven.
     */
    public string $userFullName = '';

    /**
     * Naam van de organisatie van de ingelogde gebruiker — wordt naast
     * de begroeting getoond.
     */
    public string $organizationName = '';

    /**
     * Aantal distinct medewerkers met tenminste één `WorkEntry`
     * (ongeacht type, ongeacht draft/finalized) in de huidige ISO-week
     * (ma..zo, Europe/Amsterdam) binnen de zichtbare scope.
     *
     * Telt op `employee_id`, niet op aantal regels — twee diensten op
     * één dag = nog steeds 1 aanwezige medewerker.
     */
    public int $presentEmployeesThisWeek = 0;

    /**
     * Noemer voor de aanwezigheids-card: het totaal aantal actieve
     * users in scope met rol `employee`, `manager` of `owner`. Identiek
     * aan de set die {@see WeekOverviewTable::getEmployees()}
     * genereert; we benaderen 'm hier via een count-only query om de
     * payload klein te houden.
     */
    public int $totalEmployeesInScope = 0;

    /**
     * Aantal openstaande bezwaren. Owner/boekhouder: alle bezwaren met
     * `status = 'OPEN'` in de eigen organisatie. Manager: alleen
     * bezwaren op werkregels van het eigen team.
     */
    public int $openObjectionsCount = 0;

    /**
     * Distinct user_id-count uit `atw_violations` met `severity = 'critical'`
     * en `superseded_at IS NULL` binnen de scope (alleen `user_id`s die
     * tot de zichtbare employees-set behoren).
     */
    public int $atwCriticalCount = 0;

    /**
     * Distinct user_id-count uit `atw_violations` met `severity = 'warning'`
     * en `superseded_at IS NULL` binnen de scope.
     */
    public int $atwWarningCount = 0;

    /**
     * Mount-fase.
     *
     *  1. Resolve current user via de `Auth`-facade. Geen user → 403.
     *  2. Verbied rol `employee` (zij gebruiken /uren/mijn-week en
     *     EntryFormModal-feedback voor hun eigen data).
     *  3. Stel `$userFullName` en `$organizationName` in voor de header.
     *  4. Bereken alle vier counters in helper-methodes; we doen dat in
     *     mount() zodat de view zelf alleen properties hoeft te lezen.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            // Defensief pad — productie is afgedekt door auth-middleware,
            // tests gebruiken `actingAs`. Zonder sessie geen dashboard.
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role === 'employee') {
            // Employee heeft eigen dashboard op /dashboard/medewerker.
            $this->redirect('/dashboard/medewerker');

            return;
        }

        $this->userFullName = (string) ($user->full_name ?: $user->name ?: '');
        $this->organizationName = (string) ($user->organization?->name ?? '');

        // Werk de scope-set één keer uit zodat alle counters consistent
        // dezelfde employee-IDs gebruiken (presence-noemer, presence-
        // teller én ATW-tellers).
        $employeeIds = $this->resolveEmployeeIdsInScope($user);
        $this->totalEmployeesInScope = count($employeeIds);

        $this->presentEmployeesThisWeek = $this->countPresentEmployeesThisWeek($employeeIds);
        $this->openObjectionsCount = $this->countOpenObjections($user, $employeeIds);
        [$critical, $warning] = $this->countAtwSignals($employeeIds);
        $this->atwCriticalCount = $critical;
        $this->atwWarningCount = $warning;
    }

    /**
     * Snelkoppelingen die in de view als knoppen of links worden
     * gerenderd. Alle URLs zijn relatief zodat de UI ook werkt in een
     * sub-pad-deployment.
     *
     * Bewust gemodelleerd als een dictionary met `label`, `url` en een
     * korte `description` (NL) zodat een screenreader-helper-tekst
     * beschikbaar is. De accountbeheer-link wordt gemarkeerd met
     * `owner_only = true` zodat de view dit kan filteren.
     *
     * @return array<int, array{label: string, url: string, description: string, owner_only: bool}>
     */
    public function getQuickLinks(): array
    {
        return [
            [
                'label' => 'Weekoverzicht uren',
                'url' => '/uren/week',
                'description' => 'Bekijk en bewerk de urenstaat van je team voor de huidige week.',
                'owner_only' => false,
            ],
            [
                'label' => 'ATW-statusdashboard',
                'url' => '/atw',
                'description' => 'Bekijk de ATW-meldingen per medewerker en limiet.',
                'owner_only' => false,
            ],
            [
                'label' => 'Rapportages',
                'url' => '/rapportages',
                'description' => 'Genereer overzichten en exports (PDF/Excel) per periode.',
                'owner_only' => false,
            ],
            [
                'label' => 'Bezwaren',
                'url' => '/bezwaren',
                'description' => 'Bekijk en beoordeel ingediende bezwaren op werkregels.',
                'owner_only' => false,
            ],
            [
                'label' => 'Accountbeheer',
                'url' => '/accounts',
                'description' => 'Beheer accounts, rollen en activatie binnen je organisatie.',
                'owner_only' => true,
            ],
        ];
    }

    /**
     * Bouw de set medewerker-IDs die binnen de zichtbare scope vallen.
     *
     * Filters identiek aan {@see WeekOverviewTable::getEmployees()}:
     *  - `organization_id` = die van de actieve gebruiker;
     *  - `role` ∈ {employee, manager, owner} (boekhouder werkt niet en
     *    telt dus niet mee als "aanwezig");
     *  - `is_active` = true;
     *  - manager → vast op eigen `team_id` (zelfs `null`).
     *
     * @return array<int, int>
     */
    private function resolveEmployeeIdsInScope(User $user): array
    {
        $query = User::query()
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('role', ['employee', 'manager', 'owner'])
            ->where('is_active', true);

        if ((string) $user->role === 'manager') {
            // Manager zonder team → ziet niemand, ook zichzelf niet.
            $query->where('team_id', $user->team_id);
        }

        return $query->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }

    /**
     * Tel distinct medewerkers met tenminste één werkregel deze week.
     *
     * Algoritme:
     *  - Bepaal maandag..zondag in Europe/Amsterdam (ISO-week).
     *  - Match `entry_date BETWEEN [maandag, zondag]`. We gebruiken een
     *    inclusieve range op `DATE`-kolom — `BETWEEN` is hier veilig
     *    omdat `entry_date` een DATE is, geen DATETIME, dus DST en
     *    randgevallen rond 00:00 spelen geen rol.
     *  - Skip soft-deleted regels (`deleted_at IS NULL`).
     *  - Groepeer op `employee_id` en tel het aantal groepen.
     *
     * @param  array<int, int>  $employeeIds
     */
    private function countPresentEmployeesThisWeek(array $employeeIds): int
    {
        if ($employeeIds === []) {
            return 0;
        }

        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $sunday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->addDays(6)->toDateString();

        return (int) WorkEntry::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('entry_date', [$monday, $sunday])
            ->whereNull('deleted_at')
            ->distinct()
            ->count('employee_id');
    }

    /**
     * Tel openstaande bezwaren binnen de zichtbare scope.
     *
     * - Owner/boekhouder: alle bezwaren met `status = 'OPEN'` in de
     *   eigen organisatie.
     * - Manager: alleen bezwaren op werkregels van het eigen team —
     *   gemodelleerd als JOIN op `work_entries.team_id = $user->team_id`.
     *   We doen dat via een `whereIn('work_entry_id', subquery)`-clause
     *   zodat we geen Eloquent-relations hoeven te modelleren die in
     *   andere modules nog niet bestaan.
     *
     * @param  array<int, int>  $employeeIds
     */
    private function countOpenObjections(User $user, array $employeeIds): int
    {
        $query = Objection::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('status', 'OPEN');

        if ((string) $user->role === 'manager') {
            // Manager-scope: alleen bezwaren op werkregels die op dit
            // moment in het team zitten van de manager. We selecteren
            // de relevante work_entry-IDs via `team_id`, niet via
            // `employee_id`, omdat een werkregel een eigen `team_id`
            // heeft die historisch correct is gekoppeld aan de regel
            // (een medewerker kan teamhoppen).
            $teamId = $user->team_id;

            if ($teamId === null) {
                // Manager zonder team → ziet geen bezwaren.
                return 0;
            }

            $entryIds = WorkEntry::query()
                ->where('organization_id', (int) $user->organization_id)
                ->where('team_id', (int) $teamId)
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();

            if ($entryIds === []) {
                return 0;
            }

            $query->whereIn('work_entry_id', $entryIds);
        }

        return (int) $query->count();
    }

    /**
     * Tel distinct medewerkers met tenminste één critical respectievelijk
     * warning ATW-violation die nog niet is gesuperseded. Returnt een
     * 2-tuple `[critical, warning]`.
     *
     * Algoritme:
     *  - Filter op `user_id IN scope` zodat we niets uit andere teams
     *    of organisaties tellen.
     *  - Filter op `superseded_at IS NULL` (zie taak 4.6 — DELETE op
     *    een werkregel markeert gerelateerde violations als superseded).
     *  - Groepeer op `severity` en tel distinct `user_id` per groep.
     *
     * @param  array<int, int>  $employeeIds
     * @return array{0: int, 1: int}
     */
    private function countAtwSignals(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [0, 0];
        }

        $critical = (int) AtwViolation::query()
            ->whereIn('user_id', $employeeIds)
            ->whereNull('superseded_at')
            ->where('severity', 'critical')
            ->distinct()
            ->count('user_id');

        $warning = (int) AtwViolation::query()
            ->whereIn('user_id', $employeeIds)
            ->whereNull('superseded_at')
            ->where('severity', 'warning')
            ->distinct()
            ->count('user_id');

        return [$critical, $warning];
    }

    /**
     * Bepaalt het aanwezigheidspercentage voor de presence-card.
     *
     * - Geen medewerkers in scope → 0%.
     * - Anders: floor(present / total * 100). We rounden naar beneden
     *   zodat "9 / 10 aanwezig" niet als "100%" wordt getoond.
     */
    public function getPresencePercentage(): int
    {
        if ($this->totalEmployeesInScope <= 0) {
            return 0;
        }

        $ratio = $this->presentEmployeesThisWeek / $this->totalEmployeesInScope;

        // Floor zodat we conservatief afronden — een manager die 9/10
        // ziet wil niet "100%" als visuele feedback krijgen.
        return (int) floor($ratio * 100);
    }

    /**
     * Geeft `true` wanneer de ingelogde gebruiker rol `owner` heeft. De
     * view gebruikt deze flag om de "Accountbeheer"-quick-link wel of
     * niet te tonen.
     */
    public function getIsOwner(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user !== null && (string) $user->role === 'owner';
    }

    public function render(): View
    {
        return view('livewire.dashboard.manager-home');
    }
}
