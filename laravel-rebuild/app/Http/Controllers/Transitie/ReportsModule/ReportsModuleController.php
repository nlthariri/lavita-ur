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
    public function __construct(private readonly ReportQueryService $reportQueryService)
    {
    }

    public function getInternalReportsWorkEntriesPdf(Request $request): Response
    {
        $validated = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'employee_id' => ['sometimes', 'integer'],
        ]);

        $filters = array_filter([
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
        ]);

        $entries = $this->reportQueryService->getEntries((int) $request->user()->id, $filters);
        $rows = $this->reportQueryService->toReportRows($entries);

        $fromLabel = $validated['from'] ?? 'begin';
        $toLabel = $validated['to'] ?? 'heden';

        $pdf = Pdf::loadView('reports.work-entries', [
            'rows' => $rows,
            'from' => $fromLabel,
            'to' => $toLabel,
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
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'employee_id' => ['sometimes', 'integer'],
        ]);

        $filters = array_filter([
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
            'employee_id' => $validated['employee_id'] ?? null,
        ]);

        $entries = $this->reportQueryService->getEntries((int) $request->user()->id, $filters);
        $rows = $this->reportQueryService->toReportRows($entries);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Werkregels');

        $headers = ['Medewerker', 'Datum', 'Start', 'Einde', 'Pauze (min)', 'Netto uren', 'Type', 'Team'];
        $sheet->fromArray([$headers], null, 'A1');

        $dataRows = array_map(
            fn (array $r) => array_values($r),
            $rows,
        );
        if (!empty($dataRows)) {
            $sheet->fromArray($dataRows, null, 'A2');
        }

        // Kolombreedte automatisch
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $xlsxContent = ob_get_clean();

        $fromLabel = $validated['from'] ?? 'begin';
        $toLabel = $validated['to'] ?? 'heden';
        $filename = 'werkregels-'.$fromLabel.'-'.$toLabel.'.xlsx';

        return response($xlsxContent, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}

