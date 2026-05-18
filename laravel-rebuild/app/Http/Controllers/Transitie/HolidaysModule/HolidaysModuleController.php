<?php

namespace App\Http\Controllers\Transitie\HolidaysModule;

use App\Http\Controllers\Controller;
use App\Services\HolidaysService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller voor het ophalen van feestdagen.
 *
 * Endpoint: GET /api/internal/holidays?year={YYYY}
 *
 * Requirements: 7.6
 */
class HolidaysModuleController extends Controller
{
    public function __construct(
        private readonly HolidaysService $holidaysService,
    ) {}

    /**
     * Retourneer de feestdagen voor het opgegeven jaar (of huidig jaar).
     *
     * Response: JSON-array [{ date, name, is_national }]
     */
    public function getInternalHolidays(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'integer', 'min:2000', 'max:2100'],
        ]);

        $year = (int) ($validated['year'] ?? now()->year);

        $holidays = $this->holidaysService->forYear($year);

        return response()->json($holidays);
    }
}
