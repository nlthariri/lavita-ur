<?php

namespace App\Http\Controllers\Transitie\CostCentersModule;

use App\Http\Controllers\Controller;
use App\Services\CostCentersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP-controller voor de kostenplaats-module van LaVita Urenregistratie.
 *
 * Mapt de routes `GET/POST /api/internal/cost-centers` en
 * `GET/PATCH/DELETE /api/internal/cost-centers/{id}` op de bewerkingen van
 * `App\Services\CostCentersService`. De controller is bewust analoog aan
 * `ProjectsModuleController` opgezet en blijft dun:
 *
 * - Inputvalidatie via `$request->validate(...)` voor velden `code` (uniek per
 *   organisatie, afgedwongen in de service), `name`, `description?` en
 *   `is_active?` (Requirement 2.6). Kostenplaatsen kennen — anders dan
 *   projecten — geen `hourly_rate`, dus dat veld ontbreekt hier bewust.
 * - Autorisatie (rol `owner` voor mutaties; `boekhouder` als read-only via
 *   middleware, `manager`/`employee` met 403 `FORBIDDEN_ROLE`) wordt door de
 *   service afgehandeld zodat het gedrag identiek blijft tussen HTTP en
 *   eventuele directe service-aanroepen (Requirement 2.7).
 * - Organisatiescope wordt door de service afgedwongen op basis van de actor
 *   (`$request->user()`). Een kostenplaats uit een andere organisatie levert
 *   404 op om bestaan over organisatiegrenzen niet te lekken.
 *
 * De DELETE-handler roept `CostCentersService::archive` aan en archiveert
 * idempotent via `archived_at` (soft-delete, Requirement 2.6).
 *
 * Requirements: 2.6, 2.7
 */
class CostCentersModuleController extends Controller
{
    public function __construct(
        private readonly CostCentersService $costCentersService,
    ) {}

    /**
     * GET /api/internal/cost-centers
     *
     * Lijst van kostenplaatsen binnen de organisatie van de actor. Optionele
     * querystring-filters: `with_archived` (bool), `is_active` (bool),
     * `search` (string).
     */
    public function getInternalCostCenters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'with_archived' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $filters = array_filter([
            'with_archived' => $validated['with_archived'] ?? null,
            'is_active' => array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null,
            'search' => $validated['search'] ?? null,
        ], static fn ($value) => $value !== null);

        $costCenters = $this->costCentersService->list((int) $request->user()->id, $filters);

        return response()->json([
            'data' => $costCenters,
            'count' => count($costCenters),
        ]);
    }

    /**
     * GET /api/internal/cost-centers/{id}
     *
     * Geeft één kostenplaats terug binnen de organisatie van de actor. Een
     * kostenplaats uit een andere organisatie of een onbekend id levert 404 op
     * (`abort(404)` in de service) om bestaan niet te lekken.
     */
    public function getInternalCostCenterById(Request $request, int $id): JsonResponse
    {
        $costCenter = $this->costCentersService->find($id, (int) $request->user()->id);

        return response()->json($costCenter);
    }

    /**
     * POST /api/internal/cost-centers
     *
     * Maak een nieuwe kostenplaats aan binnen de organisatie van de actor. De
     * service dwingt rol `owner` af en valideert dat `code` uniek is binnen de
     * organisatie (Requirement 2.6).
     */
    public function postInternalCostCenters(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $costCenter = $this->costCentersService->create($validated, (int) $request->user()->id);

        return response()->json($costCenter, 201);
    }

    /**
     * PATCH /api/internal/cost-centers/{id}
     *
     * Werk een bestaande kostenplaats bij. Alle velden zijn optioneel;
     * ontbrekende velden blijven ongewijzigd. Bij een hernoeming naar een
     * bestaande `code` wordt 422 met een veldfout op `code` geretourneerd.
     */
    public function patchInternalCostCenterById(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $costCenter = $this->costCentersService->update($id, $validated, (int) $request->user()->id);

        return response()->json($costCenter);
    }

    /**
     * DELETE /api/internal/cost-centers/{id}
     *
     * Archiveer (soft-delete) een kostenplaats via `archived_at` zoals
     * voorgeschreven in Requirement 2.6. De bewerking is idempotent: een reeds
     * gearchiveerde kostenplaats blijft gearchiveerd zonder fout. Retourneert
     * de bijgewerkte kostenplaats met `archived_at` gevuld zodat clients
     * direct de nieuwe status zien.
     */
    public function deleteInternalCostCenterById(Request $request, int $id): JsonResponse
    {
        $costCenter = $this->costCentersService->archive($id, (int) $request->user()->id);

        return response()->json($costCenter);
    }
}
