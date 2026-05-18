<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Http\Controllers\Transitie\ReportsModule\ReportsModuleController;
use App\Models\User;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Livewire-component — `Reports\YearExport` (taak 12.2 spec
 * lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.7  → scherm "Rapportages & export" met aparte
 *      "Jaaroverzicht"-tab voor fiscale export.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
 *  - requirements.md 6.14 → NL-labels en NL-foutmeldingen.
 *  - requirements.md 14.5 → endpoint `GET /reports/year-export`.
 *  - requirements.md NFR-9 → fiscale documenten 7-jaars retentie — de
 *      jaarexport moet over historische data werken.
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      `Reports\YearExport` als tab op `/rapportages`.
 *  - tasks.md 12.2.
 *
 * Verantwoordelijkheid:
 *  - Filterscherm voor de fiscale jaarexport: jaartal-input + optionele
 *    medewerker-selector. Twee acties:
 *      (a) "Toon aantal medewerkers" — preview-count zonder de PDF te
 *          genereren, identiek aan de preview in
 *          {@see Filters::previewCount()}.
 *      (b) "Download PDF" — render via barryvdh/laravel-dompdf en stream
 *          terug als file-download.
 *
 * Spec-deviation — service-call vs HTTP GET:
 *  De taak-spec mandeert toegang tot het nieuwe HTTP-endpoint
 *  `GET /api/internal/reports/year-export?year=&employee_id=`. Het
 *  endpoint is wél geregistreerd (zie
 *  {@see ReportsModuleController::getYearExport()}),
 *  maar voor de Livewire-download zelf roepen we
 *  {@see ReportQueryService::yearExport()} direct aan in-process —
 *  zelfde rationale als
 *  {@see Filters::downloadPdf()}: een Livewire-
 *  component op de web-stack heeft de bearer-token van de internal-API
 *  niet in scope, en een TLS-roundtrip naar zichzelf is een onnodige
 *  hop. De render-logica in beide paden is identiek (zelfde service-
 *  call, zelfde Blade-view, zelfde headers).
 *
 * Bewust niet:
 *  - Geen route-registratie in `routes/web.php` — de tab wordt later
 *    vanuit de combined `/rapportages`-pagina geëmbed via
 *    `<livewire:reports.year-export />`. Zelfde patroon als bij
 *    {@see Filters} (zie taak 12.1).
 *  - Geen client-side PDF-rendering — alle render gebeurt server-side
 *    zodat de output identiek is aan de HTTP-controller-export.
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt op `<x-ui.button>`, `<x-ui.card>`, `<x-ui.text-input>`.
 *  - Bewuste deviation naar native `<select>` voor de medewerker-
 *    dropdown — `<x-ui.text-input>` levert geen `type=select`-mode.
 *    Zelfde rationale als in {@see Filters}.
 */
#[Layout('layouts.app')]
#[Title('Jaaroverzicht — LaVita Urenregistratie')]
final class YearExport extends Component
{
    /**
     * Het rapportjaar. Wordt in {@see mount()} ingesteld op het huidige
     * kalenderjaar in Europe/Amsterdam, zodat de UI direct met een
     * zinvolle default opent. Validatie houdt 'm in [1900, 2099] zodat
     * de PDF-output binnen de fiscale 7-jaars-bewaartermijn-rekruut
     * (NFR-9) en daar nog ruim ervoor blijft werken.
     */
    #[Validate(
        rule: 'required|integer|min:1900|max:2099',
        message: [
            'year.required' => 'Jaar is verplicht.',
            'year.integer' => 'Jaar moet een getal zijn.',
            'year.min' => 'Jaar moet 1900 of later zijn.',
            'year.max' => 'Jaar moet 2099 of eerder zijn.',
        ],
        attribute: ['year' => 'jaar'],
        translate: false,
    )]
    public int $year = 0;

    /**
     * Optionele filter op één medewerker. `null` betekent "alle
     * medewerkers binnen de huidige scope".
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
     * Aantal medewerkers met data voor het opgegeven jaar — de telling
     * uit de laatst-gedraaide preview. `null` betekent "nog geen preview
     * gedraaid"; de view rendert dan geen "X medewerkers gevonden"-hint.
     */
    public ?int $rowCount = null;

    /**
     * Naam van de organisatie van de ingelogde gebruiker. Wordt in de
     * header van de view getoond; cachen we als property zodat we 'm
     * niet bij elke render opnieuw via een relation moeten resolven.
     */
    public string $organizationName = '';

    /**
     * Optionele NL-bevestigingsmelding boven het formulier. `null`
     * betekent "geen bevestiging tonen".
     */
    public ?string $confirmation = null;

    /**
     * Mount-fase.
     *
     *  1. Resolve current user via de `Auth`-facade. Geen user → 403.
     *  2. Verbied rol `employee` — die gebruikt `/uren/mijn-week`.
     *     Owner / manager / boekhouder zijn welkom (req 6.7 + 3.2 —
     *     boekhouder mag rapportages bekijken en exporteren). De
     *     onderliggende {@see ReportQueryService::yearExport()}
     *     verifieert deze guard ook nog eens defensief.
     *  3. Cache `$organizationName` voor de header.
     *  4. Stel `$year` in op het huidige kalenderjaar in Europe/Amsterdam.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            // Defensief: routes draaien in `web`-middleware-stack maar
            // de auth-guard wordt pas in een latere taak vol-geactiveerd.
            // Tests gebruiken `$this->actingAs($user)` zodat dit pad
            // alleen wordt geraakt door anonieme requests in productie.
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role === 'employee') {
            // Employees zien hun eigen week in /uren/mijn-week en hebben
            // geen rapportage-/export-rechten op anderen (req 6.7).
            abort(403, 'Geen toegang tot jaaroverzicht.');
        }

        $this->organizationName = (string) ($user->organization?->name ?? '');

        $this->year = (int) Carbon::now('Europe/Amsterdam')->year;
    }

    /**
     * "Toon aantal medewerkers"-knop — bereken het aantal medewerkers
     * dat het opgegeven jaar uren registreerde, zonder de PDF te
     * genereren. Praktisch nut: managers willen vóór een PDF-export
     * zien of het opgegeven jaar überhaupt iets oplevert, zodat ze niet
     * onnodig een lege jaarexport starten.
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

        $data = $reportQueryService->yearExport(
            (int) $actor->id,
            $this->year,
            $this->employeeId,
        );

        $this->rowCount = count($data['employees'] ?? []);
    }

    /**
     * Genereer een PDF-export van het jaaroverzicht voor het huidige
     * jaar/employee-filter en stream die als download terug.
     *
     * Render-logica is bewust een 1-op-1-mirror van
     * {@see ReportsModuleController::getYearExport()}
     * zodat zowel de UI-download als de API-download exact dezelfde PDF
     * produceren — handig voor cross-channel-vergelijking en voor
     * latere snapshot-tests.
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

        $data = $reportQueryService->yearExport(
            (int) $actor->id,
            $this->year,
            $this->employeeId,
        );

        // Cache de count zodat de view de hint kan tonen wanneer de
        // gebruiker terugkeert na de download.
        $this->rowCount = count($data['employees'] ?? []);

        $pdfBinary = Pdf::loadView('reports.year-export', $data)->output();

        $filename = 'jaaroverzicht-'.$this->year.'.pdf';

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
     * Beschikbare medewerkers voor de huidige scope. Identiek aan
     * {@see Filters::getEmployeesInScope()} —
     * organisatie-scope met manager-team-pin en alfabetische sortering.
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
        }

        return $query
            ->orderByRaw('COALESCE(full_name, name) ASC')
            ->orderBy('name', 'ASC')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.reports.year-export');
    }
}
