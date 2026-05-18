<?php

namespace App\Http\Controllers\Transitie\AtwModule;

use App\Http\Controllers\Controller;
use App\Services\AtwService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AtwModuleController extends Controller
{
    public function __construct(private readonly AtwService $atwService) {}

    public function postInternalWorkEntriesValidateAtw(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'min:1'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'end_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'pause_minutes' => ['required', 'integer', 'min:0', 'max:240'],
        ]);

        $result = $this->atwService->validateProposedShift($validated, (int) $request->user()->id);

        // Verrijk elk signaal met de publieke ATW-foutcode (`code`) zodat de frontend
        // exact dezelfde code ziet als POST/PATCH zou retourneren bij een 422.
        // Voor non-blocking signaaltypes (`WEEKLY_WARNING`, `SIXTEEN_WEEK_AVERAGE`)
        // is `code` expliciet `null` om de non-blocking aard zichtbaar te maken.
        // Requirements: 4.8, 4.9
        $result['signals'] = array_map(
            fn (array $signal): array => $signal + ['code' => $this->atwService->signalApiCode((string) ($signal['type'] ?? ''))],
            $result['signals']
        );

        return response()->json($result);
    }

    public function getInternalAtwSignals(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
        ]);

        $signals = $this->atwService->getSignalsForUser((int) $validated['user_id'], (int) $request->user()->id);

        return response()->json(['data' => $signals, 'count' => count($signals)]);
    }
}
