<?php

declare(strict_types=1);

namespace App\Livewire\Hours;

use App\Models\WorkEntry;
use App\Models\User;
use App\Services\LeaveNotificationService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Livewire-component — `Hours\LeaveOverview`
 *
 * Overzichtspagina voor verlof-/ziektemeldingen met status-indicatie
 * en goedkeur-/afwijsacties voor managers en owners.
 *
 * Autorisatie:
 *  - Employee: ziet alleen eigen verlofmeldingen.
 *  - Manager: ziet verlofmeldingen van het eigen team.
 *  - Owner: ziet alle verlofmeldingen binnen de organisatie.
 *
 * Status-logica:
 *  - In afwachting: is_finalized = false AND deleted_at IS NULL
 *  - Goedgekeurd:   is_finalized = true AND deleted_at IS NULL
 *  - Afgewezen:     deleted_at IS NOT NULL (soft-deleted)
 */
#[Layout('layouts.app')]
#[Title('Verlofoverzicht — LaVita Urenregistratie')]
final class LeaveOverview extends Component
{
    use WithPagination;

    #[Url]
    public string $filterStatus = '';

    #[Url]
    public string $filterType = '';

    #[Url]
    public string $filterDateFrom = '';

    #[Url]
    public string $filterDateTo = '';

    /**
     * Bevestigingsbanner na een actie (goedkeuren/afwijzen).
     */
    public ?string $confirmation = null;

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        // Alleen owner, manager en employee hebben toegang.
        if (! in_array((string) $user->role, ['owner', 'manager', 'employee'], true)) {
            abort(403, 'Geen toegang tot verlofoverzicht.');
        }
    }

    /**
     * Verlofmelding goedkeuren: zet is_finalized op true.
     */
    public function approve(int $entryId): void
    {
        $this->confirmation = null;

        /** @var User $user */
        $user = Auth::user();

        if (! in_array((string) $user->role, ['owner', 'manager'], true)) {
            return;
        }

        $entry = WorkEntry::withTrashed()
            ->where('id', $entryId)
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('type', ['SICK', 'LEAVE', 'HOLIDAY'])
            ->first();

        if ($entry === null) {
            return;
        }

        // Manager mag alleen eigen team goedkeuren.
        if ((string) $user->role === 'manager' && (int) $entry->team_id !== (int) $user->team_id) {
            return;
        }

        // Als het entry soft-deleted was (eerder afgewezen), herstellen.
        if ($entry->trashed()) {
            $entry->restore();
        }

        $entry->update(['is_finalized' => true]);

        // Dispatch leave_approved e-mail naar de medewerker (Req 13.1).
        try {
            app(LeaveNotificationService::class)->notifyApproved($entry, $user);
        } catch (\Throwable) {
            // E-mail dispatch mag approve-actie niet blokkeren.
        }

        $this->confirmation = 'Verlofmelding goedgekeurd.';
    }

    /**
     * Verlofmelding afwijzen: soft-delete het entry en voeg een notitie toe.
     */
    public function reject(int $entryId): void
    {
        $this->confirmation = null;

        /** @var User $user */
        $user = Auth::user();

        if (! in_array((string) $user->role, ['owner', 'manager'], true)) {
            return;
        }

        $entry = WorkEntry::query()
            ->where('id', $entryId)
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('type', ['SICK', 'LEAVE', 'HOLIDAY'])
            ->first();

        if ($entry === null) {
            return;
        }

        // Manager mag alleen eigen team afwijzen.
        if ((string) $user->role === 'manager' && (int) $entry->team_id !== (int) $user->team_id) {
            return;
        }

        // Notitie bijwerken met afwijzing.
        $existingNote = trim((string) $entry->note);
        $rejectionNote = '[Afgewezen door ' . ($user->full_name ?? $user->name) . ' op ' . now()->format('d-m-Y') . ']';
        $newNote = $existingNote !== ''
            ? $existingNote . ' — ' . $rejectionNote
            : $rejectionNote;

        $entry->update(['note' => $newNote]);

        // Dispatch leave_rejected e-mail naar de medewerker VÓÓR soft-delete (Req 13.2).
        try {
            $reason = $rejectionNote;
            app(LeaveNotificationService::class)->notifyRejected($entry, $user, $reason);
        } catch (\Throwable) {
            // E-mail dispatch mag reject-actie niet blokkeren.
        }

        $entry->delete(); // Soft-delete

        $this->confirmation = 'Verlofmelding afgewezen.';
    }

    /**
     * Filters resetten.
     */
    public function resetFilters(): void
    {
        $this->filterStatus = '';
        $this->filterType = '';
        $this->filterDateFrom = '';
        $this->filterDateTo = '';
        $this->resetPage();
    }

    /**
     * Bij filter-wijziging terug naar pagina 1.
     */
    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatedFilterType(): void
    {
        $this->resetPage();
    }

    public function updatedFilterDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedFilterDateTo(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $role = (string) $user->role;

        $query = WorkEntry::withTrashed()
            ->whereIn('type', ['SICK', 'LEAVE', 'HOLIDAY'])
            ->where('organization_id', (int) $user->organization_id);

        // Scope op basis van rol.
        if ($role === 'employee') {
            $query->where('employee_id', (int) $user->id);
        } elseif ($role === 'manager') {
            $query->where('team_id', (int) $user->team_id);
        }
        // Owner ziet alles binnen de organisatie (geen extra filter).

        // Status-filter.
        if ($this->filterStatus === 'pending') {
            $query->whereNull('deleted_at')->where('is_finalized', false);
        } elseif ($this->filterStatus === 'approved') {
            $query->whereNull('deleted_at')->where('is_finalized', true);
        } elseif ($this->filterStatus === 'rejected') {
            $query->whereNotNull('deleted_at');
        }

        // Type-filter.
        if ($this->filterType !== '' && in_array($this->filterType, ['SICK', 'LEAVE', 'HOLIDAY'], true)) {
            $query->where('type', $this->filterType);
        }

        // Datum-filter.
        if ($this->filterDateFrom !== '') {
            $query->where('entry_date', '>=', $this->filterDateFrom);
        }
        if ($this->filterDateTo !== '') {
            $query->where('entry_date', '<=', $this->filterDateTo);
        }

        $entries = $query
            ->with('employee')
            ->orderByDesc('entry_date')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.hours.leave-overview', [
            'entries' => $entries,
            'userRole' => $role,
        ]);
    }
}
