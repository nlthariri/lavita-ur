<?php

namespace App\Http\Controllers\Transitie\ProjectsModule;

use App\Http\Controllers\Controller;
use App\Services\ProjectsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HTTP-controller voor de project-module van LaVita Urenregistratie.
 *
 * Mapt de routes `GET/POST /api/internal/projects` en
 * `GET/PATCH/DELETE /api/internal/projects/{id}` op de bewerkingen van
 * `App\Services\ProjectsService`. De controller blijft bewust dun:
 *
 * - Inputvalidatie via `$request->validate(...)` voor velden `code` (uniek per
 *   organisatie, afgedwongen in de service), `name`, `description?` en
 *   `hourly_rate?` (Requirement 2.4).
 * - Autorisatie (rol `owner` voor mutaties; `boekhouder` als read-only via
 *   middleware, `manager`/`employee` met 403 `FORBIDDEN_ROLE`) wordt door de
 *   service afgehandeld zodat het gedrag identiek blijft tussen HTTP en
 *   eventuele directe service-aanroepen (Requirement 2.7).
 * - Organisatiescope wordt door de service afgedwongen op basis van de actor
 *   (`$request->user()`). Een project uit een andere organisatie levert 404 op
 *   om bestaan over organisatiegrenzen niet te lekken.
 *
 * De DELETE-handler roept `ProjectsService::archive` aan en archiveert
 * idempotent via `archived_at` (soft-delete, Requirement 2.5).
 *
 * Requirements: 2.4, 2.5, 2.7
 */
class ProjectsModuleController extends Controller
{
    public function __construct(
        private readonly ProjectsService $projectsService,
    ) {
    }

    /**
     * GET /api/internal/projects
     *
     * Lijst van projecten binnen de organisatie van de actor. Optionele
     * querystring-filters: `with_archived` (bool), `is_active` (bool),
     * `search` (string).
     */
    public function getInternalProjects(Request $request): JsonResponse
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

        $projects = $this->projectsService->list((int) $request->user()->id, $filters);

        return response()->json([
            'data' => $projects,
            'count' => count($projects),
        ]);
    }

    /**
     * GET /api/internal/projects/{id}
     *
     * Geeft één project terug binnen de organisatie van de actor. Een project
     * uit een andere organisatie of een onbekend id levert 404 op
     * (`abort(404)` in de service) om bestaan niet te lekken.
     */
    public function getInternalProjectById(Request $request, int $id): JsonResponse
    {
        $project = $this->projectsService->find($id, (int) $request->user()->id);

        return response()->json($project);
    }

    /**
     * POST /api/internal/projects
     *
     * Maak een nieuw project aan binnen de organisatie van de actor. De
     * service dwingt rol `owner` af en valideert dat `code` uniek is binnen de
     * organisatie (Requirement 2.4).
     */
    public function postInternalProjects(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $project = $this->projectsService->create($validated, (int) $request->user()->id);

        return response()->json($project, 201);
    }

    /**
     * PATCH /api/internal/projects/{id}
     *
     * Werk een bestaand project bij. Alle velden zijn optioneel; ontbrekende
     * velden blijven ongewijzigd. Bij een hernoeming naar een bestaande `code`
     * wordt 422 met een veldfout op `code` geretourneerd.
     */
    public function patchInternalProjectById(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:40'],
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $project = $this->projectsService->update($id, $validated, (int) $request->user()->id);

        return response()->json($project);
    }

    /**
     * DELETE /api/internal/projects/{id}
     *
     * Archiveer (soft-delete) een project via `archived_at` zoals voorgeschreven
     * in Requirement 2.5. De bewerking is idempotent: een reeds gearchiveerd
     * project blijft gearchiveerd zonder fout. Retourneert het bijgewerkte
     * project met `archived_at` gevuld zodat clients direct de nieuwe status
     * zien.
     */
    public function deleteInternalProjectById(Request $request, int $id): JsonResponse
    {
        $project = $this->projectsService->archive($id, (int) $request->user()->id);

        return response()->json($project);
    }
}
