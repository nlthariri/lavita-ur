<?php

namespace App\Http\Controllers\Transitie\WorkEntriesModule;

use App\Http\Controllers\Controller;
use App\Services\WorkEntriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WorkEntriesModuleController extends Controller
{
    public function __construct(private readonly WorkEntriesService $workEntriesService)
    {
    }

    public function postInternalWorkEntries(Request $request): JsonResponse
    {
        if ((string) $request->user()->role === 'boekhouder') {
            return response()->json([
                'message' => 'Boekhouder heeft alleen read-only rapportage toegang.',
            ], 403);
        }

        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'end_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'pause_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'type' => ['sometimes', 'string', 'in:WORK,SICK,HOLIDAY,OTHER'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $entry = $this->workEntriesService->create($validated, (int) $request->user()->id);

        return response()->json($entry, 201);
    }

    public function getInternalWorkEntries(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['sometimes', 'integer'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $filters = array_filter([
            'employee_id' => $validated['employee_id'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ]);

        $entries = $this->workEntriesService->list((int) $request->user()->id, $filters);

        return response()->json(['data' => $entries, 'count' => count($entries)]);
    }
}

