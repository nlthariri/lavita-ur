<?php

namespace App\Http\Controllers\Transitie\ObjectionsModule;

use App\Http\Controllers\Controller;
use App\Services\ObjectionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ObjectionsModuleController extends Controller
{
    public function __construct(private readonly ObjectionsService $objectionsService)
    {
    }

    public function postInternalObjections(Request $request): JsonResponse
    {
        if ((string) $request->user()->role === 'boekhouder') {
            return response()->json([
                'message' => 'Boekhouder heeft alleen read-only rapportage toegang.',
            ], 403);
        }

        $validated = $request->validate([
            'work_entry_id' => ['required', 'integer'],
            'motivation' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $objection = $this->objectionsService->submit($validated, (int) $request->user()->id);

        return response()->json($objection, 201);
    }

    public function postInternalObjectionsIdReview(Request $request, int $id): JsonResponse
    {
        if ((string) $request->user()->role === 'boekhouder') {
            return response()->json([
                'message' => 'Boekhouder heeft alleen read-only rapportage toegang.',
            ], 403);
        }

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVED,REJECTED'],
            'manager_response' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'corrected_start_time' => ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'corrected_end_time' => ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'corrected_pause_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
        ]);

        $result = $this->objectionsService->review($id, $validated, (int) $request->user()->id);

        return response()->json($result);
    }

    public function getInternalObjections(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:OPEN,APPROVED,REJECTED'],
        ]);

        $objections = $this->objectionsService->list(
            (int) $request->user()->id,
            array_filter(['status' => $validated['status'] ?? null]),
        );

        return response()->json(['data' => $objections, 'count' => count($objections)]);
    }
}

