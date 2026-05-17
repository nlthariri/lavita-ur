<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service voor de project-module van LaVita Urenregistratie.
 *
 * Implementeert de CRUD-bewerkingen `create`, `update`, `archive`, `list` en
 * `find` op `App\Models\Project`. Alle bewerkingen zijn organisatie-gescoped:
 * een gebruiker werkt enkel met projecten van de eigen organisatie.
 *
 * Autorisatie volgens Requirement 2.4 en 2.7: enkel `owner` mag projecten
 * aanmaken, bijwerken en archiveren; `manager` en `employee` krijgen een
 * 403 met code `FORBIDDEN_ROLE`. De rol `boekhouder` wordt via de globale
 * `bookkeeper.readonly`-middleware afgehandeld (Requirement 3) en hoeft
 * hier niet apart te worden afgewezen — een GET-`list`/`find` met die rol
 * is toegestaan.
 *
 * Validaties:
 * - Code is uniek binnen de organisatie (Requirement 2.1, 2.4).
 * - `update` en `archive` weigeren met code `PROJECT_NOT_FOUND` (404)
 *   wanneer het project niet binnen de organisatie van de actor valt.
 *
 * Daarnaast biedt de service een hulpmethode `assertUsableForWorkEntry()`
 * die door `WorkEntriesService` (task 2.8) wordt aangeroepen om de
 * foutcodes `PROJECT_ORG_MISMATCH` en `PROJECT_INACTIVE` af te dwingen
 * (Requirement 2.9, 2.10).
 *
 * Requirements: 2.4, 2.5, 2.7, 2.9, 2.10
 */
class ProjectsService
{
    /**
     * Rollen die `create`, `update` en `archive` mogen uitvoeren.
     * Volgens Requirement 2.4 en 2.7 is dit uitsluitend `owner`.
     */
    private const MUTATING_ROLES = ['owner'];

    public function __construct(
        private readonly AuditService $auditService,
    ) {
    }

    /**
     * Maak een nieuw project aan binnen de organisatie van de actor.
     *
     * Vereiste rol: `owner` (Requirement 2.4).
     *
     * @param  array{code: string, name: string, description?: ?string, hourly_rate?: ?float, is_active?: bool}  $input
     * @return array<string, mixed>  Genormaliseerde representatie van het project.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException  403 met code `FORBIDDEN_ROLE` voor andere rollen.
     * @throws ValidationException  422 wanneer `code` reeds bestaat binnen de organisatie.
     */
    public function create(array $input, int $actorId): array
    {
        $actor = $this->loadActor($actorId);
        $this->assertCanMutate($actor);

        $code = $this->normalizeCode($input['code'] ?? '');
        $this->assertCodeUniqueInOrg((int) $actor->organization_id, $code);

        return DB::transaction(function () use ($actor, $code, $input): array {
            $project = Project::create([
                'organization_id' => (int) $actor->organization_id,
                'code' => $code,
                'name' => trim((string) ($input['name'] ?? '')),
                'description' => isset($input['description'])
                    ? substr(trim((string) $input['description']), 0, 500)
                    : null,
                'hourly_rate' => $this->normalizeHourlyRate($input['hourly_rate'] ?? null),
                'is_active' => (bool) ($input['is_active'] ?? true),
                'archived_at' => null,
            ]);

            $this->auditService->record([
                'organization_id' => (int) $actor->organization_id,
                'actor_id' => (int) $actor->id,
                'action' => 'PROJECT_CREATED',
                'target_type' => 'project',
                'target_id' => (string) $project->id,
                'after_data' => $this->toArray($project),
            ]);

            return $this->toArray($project->fresh());
        });
    }

    /**
     * Werk een bestaand project bij. Alleen meegegeven velden worden
     * gewijzigd; ontbrekende velden blijven ongewijzigd.
     *
     * Vereiste rol: `owner` (Requirement 2.5, 2.7).
     *
     * @param  array{code?: string, name?: string, description?: ?string, hourly_rate?: ?float, is_active?: bool}  $input
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException  403 / 404
     * @throws ValidationException  422 op duplicate code
     */
    public function update(int $projectId, array $input, int $actorId): array
    {
        $actor = $this->loadActor($actorId);
        $this->assertCanMutate($actor);
        $project = $this->findInOrgOrFail($projectId, $actor, includeArchived: true);

        // Snapshot vóór mutatie ten behoeve van het audit-event.
        $before = $this->toArray($project);

        if (array_key_exists('code', $input)) {
            $newCode = $this->normalizeCode((string) $input['code']);
            if ($newCode !== $project->code) {
                $this->assertCodeUniqueInOrg(
                    (int) $project->organization_id,
                    $newCode,
                    excludeId: (int) $project->id,
                );
                $project->code = $newCode;
            }
        }

        if (array_key_exists('name', $input)) {
            $project->name = trim((string) $input['name']);
        }

        if (array_key_exists('description', $input)) {
            $project->description = $input['description'] === null
                ? null
                : substr(trim((string) $input['description']), 0, 500);
        }

        if (array_key_exists('hourly_rate', $input)) {
            $project->hourly_rate = $this->normalizeHourlyRate($input['hourly_rate']);
        }

        if (array_key_exists('is_active', $input)) {
            $project->is_active = (bool) $input['is_active'];
        }

        return DB::transaction(function () use ($project, $actor, $before): array {
            $project->save();

            $this->auditService->record([
                'organization_id' => (int) $actor->organization_id,
                'actor_id' => (int) $actor->id,
                'action' => 'PROJECT_UPDATED',
                'target_type' => 'project',
                'target_id' => (string) $project->id,
                'before_data' => $before,
                'after_data' => $this->toArray($project->fresh()),
            ]);

            return $this->toArray($project->fresh());
        });
    }

    /**
     * Archiveer een project (soft-delete via `archived_at`). Idempotent: een
     * reeds gearchiveerd project blijft gearchiveerd zonder fout.
     *
     * Vereiste rol: `owner` (Requirement 2.5, 2.7).
     */
    public function archive(int $projectId, int $actorId): array
    {
        $actor = $this->loadActor($actorId);
        $this->assertCanMutate($actor);
        $project = $this->findInOrgOrFail($projectId, $actor, includeArchived: true);

        return DB::transaction(function () use ($project, $actor): array {
            $before = $this->toArray($project);

            if (! $project->isArchived()) {
                $project->is_active = false;
                $project->archive();
            }

            $this->auditService->record([
                'organization_id' => (int) $actor->organization_id,
                'actor_id' => (int) $actor->id,
                'action' => 'PROJECT_ARCHIVED',
                'target_type' => 'project',
                'target_id' => (string) $project->id,
                'before_data' => $before,
                'after_data' => $this->toArray($project->fresh()),
            ]);

            return $this->toArray($project->fresh());
        });
    }

    /**
     * Geef projecten van de organisatie van de actor terug. Alle rollen mogen
     * lezen; de filter `with_archived = true` toont gearchiveerde projecten
     * eveneens (default `false`). Filter `is_active` filtert exact op de
     * boolean-waarde wanneer meegegeven.
     *
     * @param  array{with_archived?: bool, is_active?: bool, search?: ?string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(int $actorId, array $filters = []): array
    {
        $actor = $this->loadActor($actorId);

        $query = Project::query()
            ->where('organization_id', (int) $actor->organization_id)
            ->orderBy('code');

        if (! empty($filters['with_archived'])) {
            $query = Project::withArchived()
                ->where('organization_id', (int) $actor->organization_id)
                ->orderBy('code');
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['search'])) {
            $needle = '%'.strtolower(trim((string) $filters['search'])).'%';
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(code) LIKE ?', [$needle])
                  ->orWhereRaw('LOWER(name) LIKE ?', [$needle]);
            });
        }

        return $query->limit(500)->get()->map(fn (Project $p) => $this->toArray($p))->all();
    }

    /**
     * Zoek één project binnen de organisatie van de actor. Werpt een 404 wanneer
     * het project niet bestaat of in een andere organisatie zit.
     */
    public function find(int $projectId, int $actorId): array
    {
        $actor = $this->loadActor($actorId);
        $project = $this->findInOrgOrFail($projectId, $actor, includeArchived: true);

        return $this->toArray($project);
    }

    /**
     * Hulpmethode voor `WorkEntriesService` om de foutcodes
     * `PROJECT_ORG_MISMATCH` (422) en `PROJECT_INACTIVE` (422) af te dwingen
     * bij het koppelen van een project aan een werkregel.
     *
     * Requirements: 2.9, 2.10
     */
    public function assertUsableForWorkEntry(int $projectId, int $organizationId): Project
    {
        $project = Project::withArchived()->find($projectId);

        if (! $project || (int) $project->organization_id !== $organizationId) {
            throw ValidationException::withMessages([
                'project_id' => ['PROJECT_ORG_MISMATCH'],
            ]);
        }

        if (! $project->is_active || $project->isArchived()) {
            throw ValidationException::withMessages([
                'project_id' => ['PROJECT_INACTIVE'],
            ]);
        }

        return $project;
    }

    /**
     * Laad de actor en valideer dat deze een geldige organisatie heeft.
     */
    private function loadActor(int $actorId): User
    {
        $actor = User::findOrFail($actorId);

        if (! $actor->organization_id) {
            abort(403, 'Gebruiker is niet gekoppeld aan een organisatie.');
        }

        return $actor;
    }

    /**
     * Verzeker dat de actor projecten mag muteren. Werpt 403 `FORBIDDEN_ROLE`
     * voor `manager` en `employee`. `boekhouder` wordt afgevangen door de
     * `bookkeeper.readonly`-middleware (zie design.md component-tabel).
     */
    private function assertCanMutate(User $actor): void
    {
        if (! in_array((string) $actor->role, self::MUTATING_ROLES, true)) {
            abort(403, 'FORBIDDEN_ROLE');
        }
    }

    /**
     * Vind een project binnen de organisatie van de actor. Geeft 404 wanneer
     * het project niet bestaat of in een andere organisatie zit; daarmee
     * voorkomen we lekken van bestaan over organisatiegrenzen heen.
     */
    private function findInOrgOrFail(int $projectId, User $actor, bool $includeArchived = false): Project
    {
        $query = $includeArchived ? Project::withArchived() : Project::query();
        $project = $query->where('id', $projectId)->first();

        if (! $project || (int) $project->organization_id !== (int) $actor->organization_id) {
            abort(404, 'Project niet gevonden.');
        }

        return $project;
    }

    /**
     * Verzeker dat de gegeven `code` nog niet bestaat binnen de organisatie.
     * Houdt rekening met gearchiveerde records (deze blokkeren hergebruik
     * niet, omdat de uniek-index uit de migratie wel geldt op de tabel).
     *
     * Wanneer `excludeId` is meegegeven (update-pad), wordt het project zelf
     * uitgesloten van de check zodat een update zonder code-wijziging niet
     * faalt.
     */
    private function assertCodeUniqueInOrg(int $organizationId, string $code, ?int $excludeId = null): void
    {
        if ($code === '') {
            throw ValidationException::withMessages([
                'code' => ['Project-code mag niet leeg zijn.'],
            ]);
        }

        $exists = Project::withArchived()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Project-code bestaat al binnen deze organisatie.'],
            ]);
        }
    }

    /**
     * Normaliseer een project-code: trim + uppercase + max 40 tekens
     * (matched de migratie-kolom).
     */
    private function normalizeCode(string $code): string
    {
        return substr(strtoupper(trim($code)), 0, 40);
    }

    /**
     * Cast een uurtarief-input naar een DECIMAL(8,2)-compatibele waarde,
     * of `null` indien niet opgegeven of leeg.
     */
    private function normalizeHourlyRate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Geef een serialiseerbare representatie van een project terug. Werkt
     * zowel met een `Project`-instance als met een gehydreerde array (gebruikt
     * door audit-snapshots).
     *
     * @param  Project|array<string, mixed>|null  $project
     * @return array<string, mixed>|null
     */
    private function toArray(Project|array|null $project): ?array
    {
        if ($project === null) {
            return null;
        }

        if (is_array($project)) {
            return $project;
        }

        return [
            'id' => (int) $project->id,
            'organization_id' => (int) $project->organization_id,
            'code' => (string) $project->code,
            'name' => (string) $project->name,
            'description' => $project->description !== null ? (string) $project->description : null,
            'hourly_rate' => $project->hourly_rate !== null ? (string) $project->hourly_rate : null,
            'is_active' => (bool) $project->is_active,
            'archived_at' => $project->archived_at?->toIso8601String(),
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }
}
