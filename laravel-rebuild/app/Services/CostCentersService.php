<?php

namespace App\Services;

use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service voor de kostenplaats-module van LaVita Urenregistratie.
 *
 * Implementeert de CRUD-bewerkingen `create`, `update`, `archive`, `list` en
 * `find` op `App\Models\CostCenter`. De service is een tegenhanger van
 * `ProjectsService` met dezelfde organisatie-scope-checks en uniek-code-
 * validatie binnen de organisatie. Kostenplaatsen kennen geen `hourly_rate`;
 * dat veld bestaat alleen op projecten.
 *
 * Autorisatie volgens Requirement 2.6 en 2.7: enkel `owner` mag kostenplaatsen
 * aanmaken, bijwerken en archiveren; `manager` en `employee` krijgen een 403
 * met code `FORBIDDEN_ROLE`. De rol `boekhouder` wordt via de globale
 * `bookkeeper.readonly`-middleware afgehandeld (Requirement 3) en hoeft hier
 * niet apart te worden afgewezen — een GET-`list`/`find` met die rol is
 * toegestaan.
 *
 * Validaties:
 * - Code is uniek binnen de organisatie (Requirement 2.2, 2.6).
 * - `update` en `archive` weigeren met 404 wanneer de kostenplaats niet binnen
 *   de organisatie van de actor valt.
 *
 * Daarnaast biedt de service een hulpmethode `assertUsableForWorkEntry()` die
 * door `WorkEntriesService` (task 2.8) wordt aangeroepen om de foutcodes
 * `COST_CENTER_ORG_MISMATCH` en `COST_CENTER_INACTIVE` af te dwingen
 * (Requirement 2.9, 2.10).
 *
 * Requirements: 2.6, 2.7, 2.9, 2.10
 */
class CostCentersService
{
    /**
     * Rollen die `create`, `update` en `archive` mogen uitvoeren.
     * Volgens Requirement 2.6 en 2.7 is dit uitsluitend `owner`.
     */
    private const MUTATING_ROLES = ['owner'];

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    /**
     * Maak een nieuwe kostenplaats aan binnen de organisatie van de actor.
     *
     * Vereiste rol: `owner` (Requirement 2.6).
     *
     * @param  array{code: string, name: string, description?: ?string, is_active?: bool}  $input
     * @return array<string, mixed> Genormaliseerde representatie van de kostenplaats.
     *
     * @throws HttpException 403 met code `FORBIDDEN_ROLE` voor andere rollen.
     * @throws ValidationException 422 wanneer `code` reeds bestaat binnen de organisatie.
     */
    public function create(array $input, int $actorId): array
    {
        $actor = $this->loadActor($actorId);
        $this->assertCanMutate($actor);

        $code = $this->normalizeCode($input['code'] ?? '');
        $this->assertCodeUniqueInOrg((int) $actor->organization_id, $code);

        return DB::transaction(function () use ($actor, $code, $input): array {
            $costCenter = CostCenter::create([
                'organization_id' => (int) $actor->organization_id,
                'code' => $code,
                'name' => trim((string) ($input['name'] ?? '')),
                'description' => isset($input['description'])
                    ? substr(trim((string) $input['description']), 0, 500)
                    : null,
                'is_active' => (bool) ($input['is_active'] ?? true),
                'archived_at' => null,
            ]);

            $this->auditService->record([
                'organization_id' => (int) $actor->organization_id,
                'actor_id' => (int) $actor->id,
                'action' => 'COST_CENTER_CREATED',
                'target_type' => 'cost_center',
                'target_id' => (string) $costCenter->id,
                'after_data' => $this->toArray($costCenter),
            ]);

            return $this->toArray($costCenter->fresh());
        });
    }

    /**
     * Werk een bestaande kostenplaats bij. Alleen meegegeven velden worden
     * gewijzigd; ontbrekende velden blijven ongewijzigd.
     *
     * Vereiste rol: `owner` (Requirement 2.6, 2.7).
     *
     * @param  array{code?: string, name?: string, description?: ?string, is_active?: bool}  $input
     *
     * @throws HttpException 403 / 404
     * @throws ValidationException 422 op duplicate code
     */
    public function update(int $costCenterId, array $input, int $actorId): array
    {
        $actor = $this->loadActor($actorId);
        $this->assertCanMutate($actor);
        $costCenter = $this->findInOrgOrFail($costCenterId, $actor, includeArchived: true);

        // Snapshot vóór mutatie ten behoeve van het audit-event.
        $before = $this->toArray($costCenter);

        if (array_key_exists('code', $input)) {
            $newCode = $this->normalizeCode((string) $input['code']);
            if ($newCode !== $costCenter->code) {
                $this->assertCodeUniqueInOrg(
                    (int) $costCenter->organization_id,
                    $newCode,
                    excludeId: (int) $costCenter->id,
                );
                $costCenter->code = $newCode;
            }
        }

        if (array_key_exists('name', $input)) {
            $costCenter->name = trim((string) $input['name']);
        }

        if (array_key_exists('description', $input)) {
            $costCenter->description = $input['description'] === null
                ? null
                : substr(trim((string) $input['description']), 0, 500);
        }

        if (array_key_exists('is_active', $input)) {
            $costCenter->is_active = (bool) $input['is_active'];
        }

        return DB::transaction(function () use ($costCenter, $actor, $before): array {
            $costCenter->save();

            $this->auditService->record([
                'organization_id' => (int) $actor->organization_id,
                'actor_id' => (int) $actor->id,
                'action' => 'COST_CENTER_UPDATED',
                'target_type' => 'cost_center',
                'target_id' => (string) $costCenter->id,
                'before_data' => $before,
                'after_data' => $this->toArray($costCenter->fresh()),
            ]);

            return $this->toArray($costCenter->fresh());
        });
    }

    /**
     * Archiveer een kostenplaats (soft-delete via `archived_at`). Idempotent:
     * een reeds gearchiveerde kostenplaats blijft gearchiveerd zonder fout.
     *
     * Vereiste rol: `owner` (Requirement 2.6, 2.7).
     */
    public function archive(int $costCenterId, int $actorId): array
    {
        $actor = $this->loadActor($actorId);
        $this->assertCanMutate($actor);
        $costCenter = $this->findInOrgOrFail($costCenterId, $actor, includeArchived: true);

        return DB::transaction(function () use ($costCenter, $actor): array {
            $before = $this->toArray($costCenter);

            if (! $costCenter->isArchived()) {
                $costCenter->is_active = false;
                $costCenter->archive();
            }

            $this->auditService->record([
                'organization_id' => (int) $actor->organization_id,
                'actor_id' => (int) $actor->id,
                'action' => 'COST_CENTER_ARCHIVED',
                'target_type' => 'cost_center',
                'target_id' => (string) $costCenter->id,
                'before_data' => $before,
                'after_data' => $this->toArray($costCenter->fresh()),
            ]);

            return $this->toArray($costCenter->fresh());
        });
    }

    /**
     * Geef kostenplaatsen van de organisatie van de actor terug. Alle rollen
     * mogen lezen; de filter `with_archived = true` toont gearchiveerde
     * records eveneens (default `false`). Filter `is_active` filtert exact op
     * de boolean-waarde wanneer meegegeven.
     *
     * @param  array{with_archived?: bool, is_active?: bool, search?: ?string}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(int $actorId, array $filters = []): array
    {
        $actor = $this->loadActor($actorId);

        $query = CostCenter::query()
            ->where('organization_id', (int) $actor->organization_id)
            ->orderBy('code');

        if (! empty($filters['with_archived'])) {
            $query = CostCenter::withArchived()
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

        return $query->limit(500)->get()->map(fn (CostCenter $c) => $this->toArray($c))->all();
    }

    /**
     * Zoek één kostenplaats binnen de organisatie van de actor. Werpt een 404
     * wanneer de kostenplaats niet bestaat of in een andere organisatie zit.
     */
    public function find(int $costCenterId, int $actorId): array
    {
        $actor = $this->loadActor($actorId);
        $costCenter = $this->findInOrgOrFail($costCenterId, $actor, includeArchived: true);

        return $this->toArray($costCenter);
    }

    /**
     * Hulpmethode voor `WorkEntriesService` om de foutcodes
     * `COST_CENTER_ORG_MISMATCH` (422) en `COST_CENTER_INACTIVE` (422) af te
     * dwingen bij het koppelen van een kostenplaats aan een werkregel.
     *
     * Requirements: 2.9, 2.10
     */
    public function assertUsableForWorkEntry(int $costCenterId, int $organizationId): CostCenter
    {
        $costCenter = CostCenter::withArchived()->find($costCenterId);

        if (! $costCenter || (int) $costCenter->organization_id !== $organizationId) {
            throw ValidationException::withMessages([
                'cost_center_id' => ['COST_CENTER_ORG_MISMATCH'],
            ]);
        }

        if (! $costCenter->is_active || $costCenter->isArchived()) {
            throw ValidationException::withMessages([
                'cost_center_id' => ['COST_CENTER_INACTIVE'],
            ]);
        }

        return $costCenter;
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
     * Verzeker dat de actor kostenplaatsen mag muteren. Werpt 403
     * `FORBIDDEN_ROLE` voor `manager` en `employee`. `boekhouder` wordt
     * afgevangen door de `bookkeeper.readonly`-middleware (zie design.md
     * component-tabel).
     */
    private function assertCanMutate(User $actor): void
    {
        if (! in_array((string) $actor->role, self::MUTATING_ROLES, true)) {
            abort(403, 'FORBIDDEN_ROLE');
        }
    }

    /**
     * Vind een kostenplaats binnen de organisatie van de actor. Geeft 404
     * wanneer de kostenplaats niet bestaat of in een andere organisatie zit;
     * daarmee voorkomen we lekken van bestaan over organisatiegrenzen heen.
     */
    private function findInOrgOrFail(int $costCenterId, User $actor, bool $includeArchived = false): CostCenter
    {
        $query = $includeArchived ? CostCenter::withArchived() : CostCenter::query();
        $costCenter = $query->where('id', $costCenterId)->first();

        if (! $costCenter || (int) $costCenter->organization_id !== (int) $actor->organization_id) {
            abort(404, 'Kostenplaats niet gevonden.');
        }

        return $costCenter;
    }

    /**
     * Verzeker dat de gegeven `code` nog niet bestaat binnen de organisatie.
     * Houdt rekening met gearchiveerde records (deze blokkeren hergebruik
     * niet, omdat de uniek-index uit de migratie wel geldt op de tabel).
     *
     * Wanneer `excludeId` is meegegeven (update-pad), wordt de kostenplaats
     * zelf uitgesloten van de check zodat een update zonder code-wijziging
     * niet faalt.
     */
    private function assertCodeUniqueInOrg(int $organizationId, string $code, ?int $excludeId = null): void
    {
        if ($code === '') {
            throw ValidationException::withMessages([
                'code' => ['Kostenplaats-code mag niet leeg zijn.'],
            ]);
        }

        $exists = CostCenter::withArchived()
            ->where('organization_id', $organizationId)
            ->where('code', $code)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'code' => ['Kostenplaats-code bestaat al binnen deze organisatie.'],
            ]);
        }
    }

    /**
     * Normaliseer een kostenplaats-code: trim + uppercase + max 40 tekens
     * (matched de migratie-kolom).
     */
    private function normalizeCode(string $code): string
    {
        return substr(strtoupper(trim($code)), 0, 40);
    }

    /**
     * Geef een serialiseerbare representatie van een kostenplaats terug. Werkt
     * zowel met een `CostCenter`-instance als met een gehydreerde array
     * (gebruikt door audit-snapshots).
     *
     * @param  CostCenter|array<string, mixed>|null  $costCenter
     * @return array<string, mixed>|null
     */
    private function toArray(CostCenter|array|null $costCenter): ?array
    {
        if ($costCenter === null) {
            return null;
        }

        if (is_array($costCenter)) {
            return $costCenter;
        }

        return [
            'id' => (int) $costCenter->id,
            'organization_id' => (int) $costCenter->organization_id,
            'code' => (string) $costCenter->code,
            'name' => (string) $costCenter->name,
            'description' => $costCenter->description !== null ? (string) $costCenter->description : null,
            'is_active' => (bool) $costCenter->is_active,
            'archived_at' => $costCenter->archived_at?->toIso8601String(),
            'created_at' => $costCenter->created_at?->toIso8601String(),
            'updated_at' => $costCenter->updated_at?->toIso8601String(),
        ];
    }
}
