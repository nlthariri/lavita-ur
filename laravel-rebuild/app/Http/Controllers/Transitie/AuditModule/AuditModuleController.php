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
    ) {
    }

    /**
     * GET /internal/audit/export
     * Exporteer audit-events voor de organisatie van de ingelogde gebruiker.
     * Alleen OWNER en MANAGER.
     */
    public function getAuditExport(Request $request): JsonResponse
    {
        $requesterId = (int) $request->user()->id;

        $filters = $request->only([
            'action',
            'target_type',
            'target_id',
            'actor_id',
            'start_date',
            'end_date',
        ]);

        $result = $this->auditService->export($requesterId, $filters);

        return response()->json($result, 200);
    }
}
