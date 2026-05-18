<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Http\Controllers\Transitie\ReportsModule\ReportsModuleController;
use App\Livewire\Atw\StatusDashboard;
use App\Livewire\Hours\EntryFormModal;
use App\Livewire\Hours\WeekOverviewTable;
use App\Models\Team;
use App\Models\User;
use App\Services\CostCentersService;
use App\Services\ProjectsService;
use App\Services\ReportQueryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileDownloads\TestsFileDownloads;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Livewire-component — `Reports\Filters` (taak 12.1 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.7  → scherm "Rapportages & export" met filters
 *      medewerker, team, project, kostenplaats en periode (van/tot),
 *      plus download-knoppen PDF en Excel.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
 *      `design.md`.
 *  - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Rapportages & export" → component `Reports\Filters` op
 *      `/rapportages`.
 *  - tasks.md 12.1.
 *
 * Verantwoordelijkheid:
 *  - Filterscherm voor het rapportage-overzicht: medewerker, team,
 *    project, kostenplaats en periode (van/tot). Twee download-knoppen
 *    (PDF en Excel) genereren een werkregels-export gefilterd op de
 *    huidige form-state. Daarnaast een "Toon aantal regels"-knop die een
 *    voorvertoning van het aantal te exporteren regels geeft zonder de
 *    download te triggeren.
 *  - Owner/boekhouder zien alle medewerkers en teams in de eigen organisatie.
 *    Manager zit vastgepind op het eigen team (zelfde scope-regels als
 *    {@see WeekOverviewTable}). Employees krijgen 403:
 *    zij gebruiken `/uren/mijn-week` voor hun eigen week en hebben geen
 *    cross-employee rapportage-rechten (req 6.7 spreekt over filters
 *    medewerker/team — wat voor employees niet zinvol is).
 *
 * Spec-deviation — service-call vs HTTP POST:
 *  Het taak-spec-fragment 12.1 specificeert "POST'en" naar
 *  `/api/internal/reports/work-entries/{pdf,excel}`. De feitelijke
 *  backend-routes zijn echter GET-endpoints
 *  ({@see ReportsModuleController})
 *  beveiligd via bearer-token-auth (de `internal.auth`-middleware-groep).
 *  Een Livewire-component op de web-stack heeft die bearer-token niet in
 *  scope; een HTTP-roundtrip naar zichzelf zou bovendien een onnodige
 *  TLS-hop introduceren bij elke download-klik. Daarom roepen we de
 *  onderliggende `ReportQueryService` direct aan (dezelfde codepath die
 *  de HTTP-controller intern uitvoert) en streamen we de PDF/Excel als
 *  {@see StreamedResponse} terug — Livewire 3 herkent
 *  `StreamedResponse`/`BinaryFileResponse` als file-download en
 *  triggert dan zowel het browser-`Content-Disposition: attachment`-pad
 *  als de test-helper {@see TestsFileDownloads::assertFileDownloaded()}.
 *  De render-logica voor PDF en Excel is identiek aan de HTTP-controller:
 *  zelfde `reports.work-entries`-Blade voor PDF en zelfde Spreadsheet-
 *  opbouw voor Excel.
 *
 * Spec-deviation — UI-only filters team/project/kostenplaats:
 *  Het spec eist filters voor medewerker, team, project en kostenplaats.
 *  De huidige `ReportQueryService::getEntries` ondersteunt momenteel
 *  alleen `from`, `to` en `employee_id` (zie service-signature). Om de
 *  UI parity met de spec te behouden tonen we de filters voor team,
 *  project en kostenplaats wél in het formulier, maar geven we alleen
 *  `from`, `to` en `employee_id` door aan de service. De andere drie
 *  filters zijn voorlopig no-ops — een volledig wired backend (uitbreiden
 *  van `ReportQueryService` met `team_id`, `project_id`, `cost_center_id`)
 *  hangt aan een latere taak (12.2 of later). De UI-bedrading blijft
 *  geldig: zodra de service de extra filters accepteert hoeft alleen het
 *  filter-array in {@see buildFilters()} te worden uitgebreid.
 *
 * Bewust niet:
 *  - Geen route-registratie in `routes/web.php` — wordt opgenomen in
 *    een latere taak (sectie 13 of een interim-taak voor /rapportages-
 *    routes), zelfde patroon als bij {@see WeekOverviewTable}
 *    en {@see StatusDashboard}.
 *  - Geen jaarexport-tab — die hoort bij taak 12.2 als aparte component
 *    `Reports\YearExport`.
 *  - Geen client-side excel-rendering — alle render gebeurt server-side
 *    zodat de output identiek is aan de HTTP-controller-export.
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt op `<x-ui.button>`, `<x-ui.card>`, `<x-ui.text-input>`.
 *  - Bewuste deviation naar native `<select>` voor de vier filter-
 *    selects (medewerker/team/project/kostenplaats) — `<x-ui.text-input>`
 *    levert geen `type=select`-mode. Zelfde rationale als in
 *    {@see WeekOverviewTable} en
 *    {@see EntryFormModal}.
 */
#[Layout('layouts.app')]
#[Title('Rapportages — LaVita Urenregistratie')]
final class Filters extends Component
{
    /**
     * Optionele filter op één medewerker. `null` betekent "alle
     * medewerkers binnen de huidige scope". Validatie: zie `#[Validate]`.
     */
    #[Validate(
        rule: 'nullable|integer',
        message: [
            'employeeId.integer' => 'Medewerker-id is ongeldig.',
        ],
        attribute: ['employeeId' => 'medewerker'],
        translate: false,
    )]
    public ?int $employeeId = null;

    /**
     * Optionele filter op één team. UI-only voor nu — zie deviation-note
     * in de class-docblock. Validatie houdt 'm wel als integer/null vast
     * zodat een latere backend-uitbreiding deze waarde direct kan
     * meelezen.
     */
    #[Validate(
        rule: 'nullable|integer',
        message: [
            'teamId.integer' => 'Team-id is ongeldig.',
        ],
        attribute: ['teamId' => 'team'],
        translate: false,
    )]
    public ?int $teamId = null;

    /**
     * Optionele filter op één project. UI-only voor nu — zie deviation-
     * note.
     */
    #[Validate(
        rule: 'nullable|integer',
        message: [
            'projectId.integer' => 'Project-id is ongeldig.',
        ],
        attribute: ['projectId' => 'project'],
        translate: false,
    )]
    public ?int $projectId = null;

    /**
     * Optionele filter op één kostenplaats. UI-only voor nu — zie
     * deviation-note.
     */
    #[Validate(
        rule: 'nullable|integer',
        message: [
            'costCenterId.integer' => 'Kostenplaats-id is ongeldig.',
        ],
        attribute: ['costCenterId' => 'kostenplaats'],
        translate: false,
    )]
    public ?int $costCenterId = null;

    /**
     * Begin van de periode in `Y-m-d`. Wordt in {@see mount()} ingesteld
     * op de eerste dag van de huidige maand in Europe/Amsterdam.
     */
    #[Validate(
        rule: 'nullable|date_format:Y-m-d',
        message: [
            'dateFrom.date_format' => 'Begindatum moet in het formaat JJJJ-MM-DD staan.',
        ],
        attribute: ['dateFrom' => 'begindatum'],
        translate: false,
    )]
    public string $dateFrom = '';

    /**
     * Eind van de periode in `Y-m-d`. Wordt in {@see mount()} ingesteld
     * op de laatste dag van de huidige maand in Europe/Amsterdam. Mag
     * niet vóór `dateFrom` liggen.
     */
    #[Validate(
        rule: 'nullable|date_format:Y-m-d|after_or_equal:dateFrom',
        message: [
            'dateTo.date_format' => 'Einddatum moet in het formaat JJJJ-MM-DD staan.',
            'dateTo.after_or_equal' => 'Einddatum mag niet vóór de begindatum liggen.',
        ],
        attribute: ['dateTo' => 'einddatum'],
        translate: false,
    )]
    public string $dateTo = '';

    /**
     * Optionele NL-bevestigingsmelding boven het formulier (bv. "Geen
     * werkregels gevonden voor de huidige filters."). `null` betekent
     * "geen bevestiging tonen".
     */
    public ?string $confirmation = null;

    /**
     * Aantal regels van de laatst-uitgevoerde preview-aanroep. `null`
     * betekent "nog geen preview gedraaid"; de view rendert dan geen
     * "X regels gevonden"-hint.
     */
    public ?int $rowCount = null;

    /**
     * Naam van de organisatie van de ingelogde gebruiker. Wordt in de
     * header van de view getoond; cachen we als property zodat we 'm
     * niet bij elke render opnieuw via een relation moeten resolven.
     */
    public string $organizationName = '';

    /**
     * Mount-fase.
     *
     *  1. Resolve current user via de `Auth`-facade. Geen user → 403.
     *  2. Verbied rol `employee` — die gebruikt `/uren/mijn-week`.
     *     Owner / manager / boekhouder zijn welkom (req 6.7 + 3.2 —
     *     boekhouder mag rapportages bekijken en exporteren).
     *  3. Cache `$organizationName` voor de header.
     *  4. Stel `$dateFrom` in op de eerste van de huidige maand en
     *     `$dateTo` op de laatste, beide in Europe/Amsterdam, zodat
     *     de UI direct met een zinvolle default-periode opent.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            // Defensief: routes draaien in `web`-middleware-stack maar de
            // auth-guard wordt pas in een latere taak vol-geactiveerd.
            // Tests gebruiken `$this->actingAs($user)` zodat dit pad alleen
            // wordt geraakt door anonieme requests in productie.
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role === 'employee') {
            // Employees zien hun eigen week in /uren/mijn-week en hebben
            // geen rapportage-/export-rechten op anderen (req 6.7).
            abort(403, 'Geen toegang tot rapportages.');
        }

        $this->organizationName = (string) ($user->organization?->name ?? '');

        $today = Carbon::now('Europe/Amsterdam');
        $this->dateFrom = $today->copy()->startOfMonth()->toDateString();
        $this->dateTo = $today->copy()->endOfMonth()->toDateString();
    }

    /**
     * "Toon aantal regels"-knop — bereken het aantal regels dat de
     * huidige filters zouden opleveren zonder een download te triggeren.
     * Praktisch nut: managers willen vóór een PDF-export zien of de
     * filterset überhaupt iets oplevert, zodat ze niet onnodig een
     * lege download starten.
     *
     * Tijdens preview gebruiken we exact dezelfde filter-payload als de
     * download-paden zodat de getoonde count één-op-één overeenkomt met
     * het aantal regels in de uiteindelijke export.
     */
    public function previewCount(ReportQueryService $reportQueryService): void
    {
        $this->confirmation = null;
        $this->validate();

        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        $entries = $reportQueryService->getEntries((int) $actor->id, $this->buildFilters());
        $this->rowCount = $entries->count();
    }

    /**
     * Genereer een PDF-export van werkregels voor de huidige filters en
     * stream die als download terug.
     *
     * Render-logica is bewust een 1-op-1-mirror van
     * {@see ReportsModuleController::getInternalReportsWorkEntriesPdf}
     * zodat zowel de UI-download als de API-download exact dezelfde PDF
     * produceren — handig voor cross-channel-vergelijking en voor latere
     * snapshot-tests.
     *
     * Returns een {@see StreamedResponse} (geen plain `Response`) zodat
     * Livewire 3 hem als file-download capteert (`SupportFileDownloads`
     * accepteert alléén `StreamedResponse` of `BinaryFileResponse`).
     */
    public function downloadPdf(ReportQueryService $reportQueryService): StreamedResponse
    {
        $this->confirmation = null;
        $this->validate();

        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        $filters = $this->buildFilters();
        $entries = $reportQueryService->getEntries((int) $actor->id, $filters);
        $rows = $reportQueryService->toReportRows($entries);

        // Cache de count zodat de view de hint kan tonen wanneer de
        // gebruiker terugkeert na de download.
        $this->rowCount = count($rows);

        $fromLabel = $filters['from'] ?? 'begin';
        $toLabel = $filters['to'] ?? 'heden';

        $pdfBinary = Pdf::loadView('reports.work-entries', [
            'rows' => $rows,
            'from' => $fromLabel,
            'to' => $toLabel,
            'generated_at' => now()->setTimezone('Europe/Amsterdam')->format('d-m-Y H:i'),
        ])->output();

        $filename = 'werkregels-'.$fromLabel.'-'.$toLabel.'.pdf';

        // streamDownload wikkelt de callback in een StreamedResponse
        // — dat is exact wat Livewire 3 herkent als file-download.
        return response()->streamDownload(
            static function () use ($pdfBinary): void {
                echo $pdfBinary;
            },
            $filename,
            [
                'Content-Type' => 'application/pdf',
            ],
        );
    }

    /**
     * Genereer een Excel-export (XLSX) van werkregels voor de huidige
     * filters en stream die als download terug.
     *
     * Render-logica is een 1-op-1-mirror van
     * {@see ReportsModuleController::getInternalReportsWorkEntriesExcel}.
     * Zelfde kolomkoppen, zelfde data-volgorde, zelfde auto-size.
     */
    public function downloadExcel(ReportQueryService $reportQueryService): StreamedResponse
    {
        $this->confirmation = null;
        $this->validate();

        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        $filters = $this->buildFilters();
        $entries = $reportQueryService->getEntries((int) $actor->id, $filters);
        $rows = $reportQueryService->toReportRows($entries);

        $this->rowCount = count($rows);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Werkregels');

        // Headers identiek aan de HTTP-controller (zie
        // ReportsModuleController::getInternalReportsWorkEntriesExcel).
        $headers = ['Medewerker', 'Datum', 'Start', 'Einde', 'Pauze (min)', 'Netto uren', 'Type', 'Team'];
        $sheet->fromArray([$headers], null, 'A1');

        $dataRows = array_map(
            static fn (array $r): array => array_values($r),
            $rows,
        );
        if (! empty($dataRows)) {
            $sheet->fromArray($dataRows, null, 'A2');
        }

        // Kolombreedte automatisch — identiek aan de HTTP-controller.
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        $fromLabel = $filters['from'] ?? 'begin';
        $toLabel = $filters['to'] ?? 'heden';
        $filename = 'werkregels-'.$fromLabel.'-'.$toLabel.'.xlsx';

        // We bouwen de XLSX-content in-memory via een output-buffer in
        // de stream-callback. PhpSpreadsheet's Xlsx-writer schrijft naar
        // `php://output`; door de callback uit te voeren binnen de
        // StreamedResponse mag die direct streamen — Livewire's
        // SupportFileDownloads vangt de output op via een eigen
        // ob_start()/ob_get_clean()-laag.
        return response()->streamDownload(
            static function () use ($writer): void {
                $writer->save('php://output');
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
        );
    }

    /**
     * Beschikbare medewerkers voor de huidige scope. Wordt door de view
     * gebruikt om de medewerker-`<select>` op te bouwen.
     *
     * Filter-regels zijn identiek aan
     * {@see WeekOverviewTable::getEmployees()}:
     *
     *  - `organization_id` = die van de actieve gebruiker.
     *  - `role` ∈ {employee, manager, owner} — boekhouder verschijnt niet
     *    als kandidaat-rij omdat die rol geen werkregels heeft.
     *  - `is_active` = true.
     *  - manager → vast op eigen `team_id`.
     *  - owner / boekhouder → respect `$teamId` indien gezet (zachte
     *    filter binnen de UI; backend-side wordt het pas afgedwongen
     *    zodra `ReportQueryService` `team_id` accepteert).
     *  - sorteer op `full_name` ASC, dan `name` ASC voor stabiele volgorde.
     *
     * @return Collection<int, User>
     */
    public function getEmployeesInScope(): Collection
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
        } elseif ($this->teamId !== null) {
            $query->where('team_id', $this->teamId);
        }

        return $query
            ->orderByRaw('COALESCE(full_name, name) ASC')
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Beschikbare teams voor de huidige scope. Identiek aan
     * {@see WeekOverviewTable::getAvailableTeams()}:
     *
     *  - Manager: 1-element collectie met het eigen team (of leeg
     *    wanneer manager geen team heeft).
     *  - Owner/boekhouder: alle teams binnen eigen organisatie,
     *    alfabetisch.
     *
     * @return Collection<int, Team>
     */
    public function getTeamsInScope(): Collection
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
     * Beschikbare projecten voor de actor zijn organisatie. Volgt het
     * pattern uit {@see EntryFormModal::getProjects()}:
     * we vragen alleen actieve projecten op (geen gearchiveerde) zodat
     * de dropdown niet onnodig wordt vervuild met projecten waar geen
     * uren meer op geregistreerd kunnen worden.
     *
     * @return array<int, string> `[id => name]`-map voor de view.
     */
    public function getProjectsInScope(ProjectsService $projectsService): array
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            return [];
        }

        $rows = $projectsService->list((int) $actor->id, ['is_active' => true]);

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = (string) ($row['name'] ?? '');
        }

        return $map;
    }

    /**
     * Beschikbare kostenplaatsen voor de actor zijn organisatie.
     *
     * @return array<int, string> `[id => name]`-map voor de view.
     */
    public function getCostCentersInScope(CostCentersService $costCentersService): array
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            return [];
        }

        $rows = $costCentersService->list((int) $actor->id, ['is_active' => true]);

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = (string) ($row['name'] ?? '');
        }

        return $map;
    }

    public function render(): View
    {
        return view('livewire.reports.filters');
    }

    /**
     * Bouw het filter-array dat aan {@see ReportQueryService::getEntries()}
     * wordt doorgegeven.
     *
     * Op dit moment ondersteunt de service drie filters: `from`, `to` en
     * `employee_id`. De UI-side filters voor `team_id`, `project_id` en
     * `cost_center_id` worden bewust niet meegegeven (zie deviation-note
     * in de class-docblock). Lege strings worden omgezet naar `null` en
     * vervolgens uit het filter-array gefilterd, zodat de service-laag
     * het verschil ziet tussen "filter niet meegegeven" en "filter is
     * leeg".
     *
     * @return array<string, int|string>
     */
    private function buildFilters(): array
    {
        $filters = [
            'from' => $this->dateFrom !== '' ? $this->dateFrom : null,
            'to' => $this->dateTo !== '' ? $this->dateTo : null,
            'employee_id' => $this->employeeId,
        ];

        // Filter null-/lege waarden eruit zodat
        // ReportQueryService::getEntries de bijbehorende WHERE-clauses
        // overslaat (zie service-implementatie: `if (!empty(...))`).
        return array_filter(
            $filters,
            static fn ($value): bool => $value !== null && $value !== '' && $value !== 0,
        );
    }
}
