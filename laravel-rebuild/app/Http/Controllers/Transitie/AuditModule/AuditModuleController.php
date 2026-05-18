<?php

namespace App\Http\Controllers\Transitie\AuditModule;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditModuleController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * GET /internal/audit/export
     * Exporteer audit-events voor de organisatie van de ingelogde gebruiker.
     * Alleen OWNER en MANAGER.
     */
    public function getAuditExport(Request $request): JsonResponse
    {
        $actor = $request->user();

        // Expliciete rolcheck — defense-in-depth naast service-level check
        if (! in_array((string) $actor->role, ['owner', 'manager'], true)) {
            return response()->json([
                'error' => 'Onvoldoende rechten voor audit export.',
                'code' => 'FORBIDDEN_ROLE',
            ], 403);
        }

        $validated = $request->validate([
            'action' => ['sometimes', 'nullable', 'string', 'max:100'],
            'target_type' => ['sometimes', 'nullable', 'string', 'max:100'],
            'target_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'actor_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);

        $filters = array_filter($validated, fn ($v) => $v !== null);

        $result = $this->auditService->export((int) $actor->id, $filters);

        return response()->json($result, 200);
    }
}
