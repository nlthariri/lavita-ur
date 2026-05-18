<?php

namespace App\Http\Controllers\Transitie\ReportsModule;

use App\Http\Controllers\Controller;
use App\Services\ReportQueryService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportsModuleController extends Controller
{
    public function __construct(private readonly ReportQueryService $reportQueryService) {}

    public function getInternalReportsWorkEntriesPdf(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $filters = array_filter([
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
        ]);

        $entries = $this->reportQueryService->getEntries((int) $request->user()->id, $filters);
        $rows = $this->reportQueryService->toReportRows($entries);

        $fromLabel = preg_replace('/[^a-zA-Z0-9\-]/', '', $validated['from'] ?? 'begin');
        $toLabel = preg_replace('/[^a-zA-Z0-9\-]/', '', $validated['to'] ?? 'heden');

        $pdf = Pdf::loadView('reports.work-entries', [
            'rows' => $rows,
            'from' => $validated['from'] ?? 'begin',
            'to' => $validated['to'] ?? 'heden',
            'generated_at' => now()->setTimezone('Europe/Amsterdam')->format('d-m-Y H:i'),
        ]);

        $filename = 'werkregels-'.$fromLabel.'-'.$toLabel.'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function getInternalReportsWorkEntriesExcel(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $filters = array_filter([
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
        ]);

        $entries = $this->reportQueryService->getEntries((int) $request->user()->id, $filters);
        $rows = $this->reportQueryService->toReportRows($entries);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Werkregels');

        $headers = ['Medewerker', 'Datum', 'Start', 'Einde', 'Pauze (min)', 'Netto uren', 'Type', 'Team'];
        $sheet->fromArray([$headers], null, 'A1');

        $dataRows = array_map(
            fn (array $r) => array_values($r),
            $rows,
        );
        if (! empty($dataRows)) {
            $sheet->fromArray($dataRows, null, 'A2');
        }

        // Kolombreedte automatisch
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        // Gebruik een temp-bestand i.p.v. php://output + ob_start() om
        // memory-exhaustion te voorkomen bij grote spreadsheets. De
        // ZipStream-library die PhpSpreadsheet intern gebruikt, buffert
        // anders het volledige ZIP-archief in het geheugen.
        $tempFile = tempnam(sys_get_temp_dir(), 'lavita_xlsx_');
        $writer->save($tempFile);

        $fromLabel = preg_replace('/[^a-zA-Z0-9\-]/', '', $validated['from'] ?? 'begin');
        $toLabel = preg_replace('/[^a-zA-Z0-9\-]/', '', $validated['to'] ?? 'heden');
        $filename = 'werkregels-'.$fromLabel.'-'.$toLabel.'.xlsx';

        // Lees het bestand en verwijder het daarna
        $xlsxContent = file_get_contents($tempFile);
        @unlink($tempFile);

        // Spreadsheet-objecten expliciet vrijgeven
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $writer);

        return response($xlsxContent, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Fiscale jaarexport (taak 12.2 spec lavita-urenregistratie —
     * Requirement 6.7 jaaroverzicht-tab, Requirement 14.5 endpoint,
     * NFR-9 7-jaars retentie). Levert een PDF met per medewerker een
     * 13-koloms tabel: Jan..Dec + Jaartotaal, één rij per type
     * (WORK/SICK/LEAVE/HOLIDAY/OTHER) plus een totaalrij.
     *
     * Scope-regels worden afgedwongen door
     * {@see ReportQueryService::yearExport()}: de service
     * scoppt op organisatie en — voor managers — op het eigen team, en
     * werpt 403 voor de employee-rol.
     */
    public function getYearExport(Request $request): Response
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:1900', 'max:2099'],
            'employee_id' => ['sometimes', 'integer'],
        ]);

        $year = (int) $validated['year'];
        $employeeId = isset($validated['employee_id']) ? (int) $validated['employee_id'] : null;

        $data = $this->reportQueryService->yearExport(
            (int) $request->user()->id,
            $year,
            $employeeId,
        );

        $pdf = Pdf::loadView('reports.year-export', $data);

        $filename = 'jaaroverzicht-'.$year.'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
