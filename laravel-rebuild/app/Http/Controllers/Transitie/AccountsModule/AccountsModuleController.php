<?php

namespace App\Http\Controllers\Transitie\AccountsModule;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DataExportService;
use App\Services\RetentionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountsModuleController extends Controller
{
    public function __construct(
        private readonly RetentionService $retentionService,
        private readonly DataExportService $dataExportService,
    ) {}

    /**
     * DELETE /api/internal/accounts/{id}
     *
     * Pseudonimiseert het account (AVG recht op verwijdering).
     * Alleen owner binnen dezelfde organisatie mag dit.
     */
    public function deleteInternalAccount(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        if ((string) $actor->role !== 'owner') {
            return response()->json([
                'error' => 'Alleen de eigenaar mag accounts verwijderen.',
                'code' => 'FORBIDDEN_ROLE',
            ], 403);
        }

        // Organisatie-scope: voorkom cross-org account-verwijdering.
        // Een owner mag alleen accounts binnen de eigen organisatie
        // pseudonimiseren. We retourneren 404 i.p.v. 403 om het
        // bestaan van het account niet over org-grenzen te lekken.
        $targetUser = User::find($id);
        if (! $targetUser || (int) $targetUser->organization_id !== (int) $actor->organization_id) {
            return response()->json([
                'error' => 'Resource niet gevonden.',
            ], 404);
        }

        // Voorkom dat een owner zichzelf verwijdert
        if ((int) $actor->id === $id) {
            return response()->json([
                'error' => 'U kunt uw eigen account niet verwijderen.',
                'code' => 'SELF_DELETE_FORBIDDEN',
            ], 422);
        }

        $this->retentionService->pseudonymize($id, (int) $actor->id);

        return response()->json(null, 204);
    }

    /**
     * GET /api/internal/accounts/{id}/data-export
     *
     * Retourneert alle gerelateerde data van een gebruiker (AVG inzage).
     */
    public function getInternalAccountDataExport(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();

        // AVG inzage: gebruiker zelf of owner van dezelfde organisatie
        if ((int) $actor->id !== $id && (string) $actor->role !== 'owner') {
            return response()->json([
                'error' => 'Onvoldoende rechten voor data-export.',
                'code' => 'FORBIDDEN_DATA_EXPORT',
            ], 403);
        }

        $data = $this->dataExportService->exportFor($id, (int) $actor->id);

        return response()->json($data, 200);
    }
}
