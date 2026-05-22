<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\AuditEvent;
use App\Models\LeaveType;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Settings\LeaveTypesManager`
 *
 * Beheerpagina voor verlof-types per organisatie. Alleen toegankelijk voor de owner-rol.
 *
 * Functionaliteit:
 *  - Overzicht van alle verlof-types (actief + inactief)
 *  - Aanmaken van nieuwe verlof-types
 *  - Bewerken van bestaande verlof-types (code niet wijzigbaar na aanmaak)
 *  - Deactiveren (soft) van verlof-types
 *  - Audit-events: LEAVE_TYPE_CREATED, LEAVE_TYPE_UPDATED, LEAVE_TYPE_DEACTIVATED
 *  - Dispatch `leave-type-updated` event bij wijzigingen
 */
#[Layout('layouts.app')]
#[Title('Verlof-types — LaVita Urenregistratie')]
final class LeaveTypesManager extends Component
{
    // ─── Formulier-state ─────────────────────────────────────────────────

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public ?string $description = null;

    public ?string $maxDaysPerYear = null;

    public bool $countsTowardsBalance = true;

    // ─── Feedback ────────────────────────────────────────────────────────

    public ?string $confirmation = null;

    public ?string $error = null;

    // ─── Lifecycle ───────────────────────────────────────────────────────

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role !== 'owner') {
            abort(403, 'Geen toegang tot verlof-types beheer.');
        }
    }

    // ─── Computed property: lijst van verlof-types ───────────────────────

    /**
     * @return array<int, array{id: int, code: string, name: string, description: string|null, max_days_per_year: int|null, counts_towards_balance: bool, is_active: bool}>
     */
    public function getLeaveTypesProperty(): array
    {
        /** @var User $user */
        $user = Auth::user();

        return LeaveType::forOrganization((int) $user->organization_id)
            ->orderBy('is_active', 'desc')
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $lt) => [
                'id' => (int) $lt->id,
                'code' => (string) $lt->code,
                'name' => (string) $lt->name,
                'description' => $lt->description,
                'max_days_per_year' => $lt->max_days_per_year,
                'counts_towards_balance' => (bool) $lt->counts_towards_balance,
                'is_active' => (bool) $lt->is_active,
            ])
            ->all();
    }

    // ─── Formulier openen/sluiten ────────────────────────────────────────

    public function openForm(): void
    {
        $this->resetForm();
        $this->showForm = true;
        $this->resetFeedback();
        $this->resetErrorBag();
    }

    public function editLeaveType(int $id): void
    {
        $this->resetFeedback();
        $this->resetErrorBag();

        /** @var User $user */
        $user = Auth::user();

        $leaveType = LeaveType::forOrganization((int) $user->organization_id)->find($id);

        if ($leaveType === null) {
            $this->error = 'Verlof-type niet gevonden.';

            return;
        }

        $this->editingId = (int) $leaveType->id;
        $this->code = (string) $leaveType->code;
        $this->name = (string) $leaveType->name;
        $this->description = $leaveType->description;
        $this->maxDaysPerYear = $leaveType->max_days_per_year !== null
            ? (string) $leaveType->max_days_per_year
            : null;
        $this->countsTowardsBalance = (bool) $leaveType->counts_towards_balance;
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
        $this->resetErrorBag();
    }

    // ─── Opslaan (create/update) ─────────────────────────────────────────

    public function save(): void
    {
        $this->resetFeedback();
        $this->resetErrorBag();
        $this->authorizeOwner();

        /** @var User $user */
        $user = Auth::user();
        $organizationId = (int) $user->organization_id;

        // Validatie
        if (! $this->validateForm($organizationId)) {
            return;
        }

        $maxDays = $this->maxDaysPerYear !== null && $this->maxDaysPerYear !== ''
            ? (int) $this->maxDaysPerYear
            : null;

        if ($this->editingId !== null) {
            $this->updateLeaveType($organizationId, $user, $maxDays);
        } else {
            $this->createLeaveType($organizationId, $user, $maxDays);
        }
    }

    // ─── Deactiveren ─────────────────────────────────────────────────────

    public function deactivate(int $id): void
    {
        $this->resetFeedback();
        $this->resetErrorBag();
        $this->authorizeOwner();

        /** @var User $user */
        $user = Auth::user();

        $leaveType = LeaveType::forOrganization((int) $user->organization_id)->find($id);

        if ($leaveType === null) {
            $this->error = 'Verlof-type niet gevonden.';

            return;
        }

        if (! $leaveType->is_active) {
            $this->error = 'Dit verlof-type is al gedeactiveerd.';

            return;
        }

        $beforeData = [
            'is_active' => true,
        ];

        $leaveType->update(['is_active' => false]);

        // Audit-event
        AuditEvent::create([
            'organization_id' => (int) $user->organization_id,
            'actor_id' => (int) $user->id,
            'action' => 'LEAVE_TYPE_DEACTIVATED',
            'target_type' => 'leave_type',
            'target_id' => (int) $leaveType->id,
            'before_data' => $beforeData,
            'after_data' => ['is_active' => false],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        // Dispatch event
        $this->dispatch('leave-type-updated');

        $this->confirmation = 'Verlof-type "' . $leaveType->name . '" gedeactiveerd.';

        // Sluit formulier als we het bewerkte type deactiveren
        if ($this->editingId === $id) {
            $this->resetForm();
            $this->showForm = false;
        }
    }

    // ─── Render ──────────────────────────────────────────────────────────

    public function render(): View
    {
        return view('livewire.settings.leave-types-manager');
    }

    // ─── Private helpers ─────────────────────────────────────────────────

    private function createLeaveType(int $organizationId, User $user, ?int $maxDays): void
    {
        $leaveType = LeaveType::create([
            'organization_id' => $organizationId,
            'code' => trim($this->code),
            'name' => trim($this->name),
            'description' => $this->description !== null && trim($this->description) !== ''
                ? trim($this->description)
                : null,
            'max_days_per_year' => $maxDays,
            'counts_towards_balance' => $this->countsTowardsBalance,
            'is_active' => true,
        ]);

        // Audit-event
        AuditEvent::create([
            'organization_id' => $organizationId,
            'actor_id' => (int) $user->id,
            'action' => 'LEAVE_TYPE_CREATED',
            'target_type' => 'leave_type',
            'target_id' => (int) $leaveType->id,
            'before_data' => null,
            'after_data' => [
                'code' => $leaveType->code,
                'name' => $leaveType->name,
                'description' => $leaveType->description,
                'max_days_per_year' => $leaveType->max_days_per_year,
                'counts_towards_balance' => $leaveType->counts_towards_balance,
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        // Dispatch event
        $this->dispatch('leave-type-updated');

        $this->confirmation = 'Verlof-type "' . $leaveType->name . '" aangemaakt.';
        $this->resetForm();
        $this->showForm = false;
    }

    private function updateLeaveType(int $organizationId, User $user, ?int $maxDays): void
    {
        $leaveType = LeaveType::forOrganization($organizationId)->find($this->editingId);

        if ($leaveType === null) {
            $this->error = 'Verlof-type niet gevonden.';

            return;
        }

        $beforeData = [
            'name' => $leaveType->name,
            'description' => $leaveType->description,
            'max_days_per_year' => $leaveType->max_days_per_year,
            'counts_towards_balance' => $leaveType->counts_towards_balance,
        ];

        $leaveType->update([
            'name' => trim($this->name),
            'description' => $this->description !== null && trim($this->description) !== ''
                ? trim($this->description)
                : null,
            'max_days_per_year' => $maxDays,
            'counts_towards_balance' => $this->countsTowardsBalance,
        ]);

        $afterData = [
            'name' => $leaveType->name,
            'description' => $leaveType->description,
            'max_days_per_year' => $leaveType->max_days_per_year,
            'counts_towards_balance' => $leaveType->counts_towards_balance,
        ];

        // Audit-event
        AuditEvent::create([
            'organization_id' => $organizationId,
            'actor_id' => (int) $user->id,
            'action' => 'LEAVE_TYPE_UPDATED',
            'target_type' => 'leave_type',
            'target_id' => (int) $leaveType->id,
            'before_data' => $beforeData,
            'after_data' => $afterData,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        // Dispatch event
        $this->dispatch('leave-type-updated');

        $this->confirmation = 'Verlof-type "' . $leaveType->name . '" bijgewerkt.';
        $this->resetForm();
        $this->showForm = false;
    }

    /**
     * Valideer het formulier. Retourneert true als alles geldig is.
     */
    private function validateForm(int $organizationId): bool
    {
        $codeTrimmed = trim($this->code);
        $nameTrimmed = trim($this->name);

        // Code validatie
        if ($codeTrimmed === '') {
            $this->addError('code', 'Code is verplicht.');

            return false;
        }

        if (mb_strlen($codeTrimmed) > 40) {
            $this->addError('code', 'Code mag maximaal 40 tekens bevatten.');

            return false;
        }

        // Unieke code per organisatie (exclude current bij edit)
        $codeQuery = LeaveType::forOrganization($organizationId)
            ->where('code', $codeTrimmed);

        if ($this->editingId !== null) {
            $codeQuery->where('id', '!=', $this->editingId);
        }

        if ($codeQuery->exists()) {
            $this->addError('code', 'Deze code is al in gebruik binnen de organisatie.');

            return false;
        }

        // Naam validatie
        if ($nameTrimmed === '') {
            $this->addError('name', 'Naam is verplicht.');

            return false;
        }

        if (mb_strlen($nameTrimmed) > 120) {
            $this->addError('name', 'Naam mag maximaal 120 tekens bevatten.');

            return false;
        }

        // Beschrijving validatie
        if ($this->description !== null && mb_strlen(trim($this->description)) > 500) {
            $this->addError('description', 'Beschrijving mag maximaal 500 tekens bevatten.');

            return false;
        }

        // Max dagen per jaar validatie
        if ($this->maxDaysPerYear !== null && $this->maxDaysPerYear !== '') {
            $maxDaysInt = (int) $this->maxDaysPerYear;
            if (! is_numeric($this->maxDaysPerYear) || $maxDaysInt < 1 || $maxDaysInt > 365) {
                $this->addError('maxDaysPerYear', 'Maximum dagen moet een getal zijn tussen 1 en 365.');

                return false;
            }
        }

        return true;
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->code = '';
        $this->name = '';
        $this->description = null;
        $this->maxDaysPerYear = null;
        $this->countsTowardsBalance = true;
    }

    private function resetFeedback(): void
    {
        $this->confirmation = null;
        $this->error = null;
    }

    private function authorizeOwner(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null || (string) $user->role !== 'owner') {
            abort(403, 'Geen toegang.');
        }
    }
}
