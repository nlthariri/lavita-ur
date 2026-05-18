<?php

namespace App\Http\Controllers\Transitie\WorkEntriesModule;

use App\Http\Controllers\Controller;
use App\Services\CopyWeekService;
use App\Services\WorkEntriesService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class WorkEntriesModuleController extends Controller
{
    public function __construct(
        private readonly WorkEntriesService $workEntriesService,
        private readonly CopyWeekService $copyWeekService,
    ) {}

    public function postInternalWorkEntries(Request $request): JsonResponse
    {
        // Read-only afdwinging voor de rol `boekhouder` (Requirement 3.4) wordt
        // globaal afgehandeld door de `bookkeeper.readonly`-middleware (alias in
        // `bootstrap/app.php`, gekoppeld aan de internal-auth route-groep in
        // `routes/api.php`). Een aparte inline-check is daarom niet meer nodig.

        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'min:1'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'end_time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'pause_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'type' => ['sometimes', 'string', 'in:WORK,SICK,LEAVE,HOLIDAY,OTHER'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cost_center_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
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

    /**
     * Haalt een enkele werkregel op binnen team-/owner-scope.
     *
     * Requirements: 1.1, 1.2, 1.3
     */
    public function getInternalWorkEntryById(Request $request, int $id): JsonResponse
    {
        $entry = $this->workEntriesService->find($id, (int) $request->user()->id);

        return response()->json($entry);
    }

    /**
     * Werkt een bestaande werkregel bij. Validatie identiek aan POST,
     * maar elk veld optioneel via `sometimes`. `employee_id` is per
     * Requirement 1.4 niet muteerbaar en wordt hier dus niet
     * geaccepteerd.
     *
     * Requirements: 1.4, 1.5, 1.6
     */
    public function patchInternalWorkEntryById(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'entry_date' => ['sometimes', 'date_format:Y-m-d'],
            'start_time' => ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'end_time' => ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'pause_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],
            'type' => ['sometimes', 'string', 'in:WORK,SICK,LEAVE,HOLIDAY,OTHER'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'project_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'cost_center_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        $entry = $this->workEntriesService->update(
            $id,
            $validated,
            (int) $request->user()->id,
        );

        return response()->json($entry);
    }

    /**
     * Soft-delete een werkregel binnen team-/owner-scope. Retourneert
     * HTTP 204 No Content bij succes.
     *
     * Requirements: 1.7
     */
    public function deleteInternalWorkEntryById(Request $request, int $id): Response
    {
        $this->workEntriesService->delete($id, (int) $request->user()->id);

        return response()->noContent();
    }

    /**
     * Kopieer een werkweek naar een doelweek. Alleen owner/manager.
     * Valideert dat employee_id, source_week_start en target_week_start
     * aanwezig zijn en dat beide datums maandagen zijn.
     *
     * Requirements: 8.1, 8.6
     */
    public function postCopyWeek(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'source_week_start' => ['required', 'date_format:Y-m-d'],
            'target_week_start' => ['required', 'date_format:Y-m-d'],
        ]);

        // Maandagcheck (aanvullend op service-level check; vroegtijdige 422)
        $source = Carbon::parse($validated['source_week_start']);
        $target = Carbon::parse($validated['target_week_start']);

        if ($source->dayOfWeek !== Carbon::MONDAY) {
            throw ValidationException::withMessages([
                'source_week_start' => 'source_week_start moet een maandag zijn.',
            ]);
        }

        if ($target->dayOfWeek !== Carbon::MONDAY) {
            throw ValidationException::withMessages([
                'target_week_start' => 'target_week_start moet een maandag zijn.',
            ]);
        }

        $result = $this->copyWeekService->copyWeek(
            (int) $validated['employee_id'],
            $validated['source_week_start'],
            $validated['target_week_start'],
            (int) $request->user()->id,
        );

        return response()->json($result);
    }
}
