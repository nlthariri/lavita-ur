<?php

declare(strict_types=1);

namespace App\Livewire\Objections;

use App\Models\Objection;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Objections\ObjectionsList`
 *
 * Lijst-overzicht van alle bezwaren binnen de organisatie (owner/manager)
 * of eigen bezwaren (employee). Biedt zoek- en statusfilter, en een
 * doorklik naar het review-formulier per bezwaar.
 */
#[Layout('layouts.app')]
#[Title('Bezwaren — LaVita Urenregistratie')]
final class ObjectionsList extends Component
{
    public string $search = '';

    public ?string $statusFilter = null;

    public string $organizationName = '';

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role === 'boekhouder') {
            abort(403, 'Geen toegang tot bezwaren.');
        }

        $this->organizationName = (string) ($user->organization?->name ?? '');
    }

    /**
     * Haal bezwaren op binnen de scope van de actor.
     *
     * - Owner: alle bezwaren in de organisatie.
     * - Manager: bezwaren op werkregels van het eigen team.
     * - Employee: alleen eigen bezwaren (ingediend door henzelf).
     *
     * @return Collection<int, Objection>
     */
    public function getObjections(): Collection
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            return collect();
        }

        $query = Objection::query()
            ->with(['workEntry.employee'])
            ->where('organization_id', (int) $actor->organization_id)
            ->orderByDesc('created_at');

        // Rol-scope
        if ((string) $actor->role === 'employee') {
            // Employee ziet alleen bezwaren die zij zelf hebben ingediend
            $query->where('submitted_by_id', (int) $actor->id);
        } elseif ((string) $actor->role === 'manager') {
            // Manager ziet bezwaren op werkregels van het eigen team
            $teamId = $actor->team_id;
            if ($teamId === null) {
                return collect();
            }
            $entryIds = WorkEntry::query()
                ->where('organization_id', (int) $actor->organization_id)
                ->where('team_id', (int) $teamId)
                ->pluck('id')
                ->all();
            if (empty($entryIds)) {
                return collect();
            }
            $query->whereIn('work_entry_id', $entryIds);
        }
        // Owner ziet alles in de organisatie

        // Status-filter
        if ($this->statusFilter !== null && $this->statusFilter !== '') {
            $query->where('status', strtoupper($this->statusFilter));
        }

        $results = $query->get();

        // Zoekfilter (in PHP vanwege encrypted velden)
        $search = trim($this->search);
        if ($search !== '') {
            $needle = strtolower($search);
            $results = $results->filter(function (Objection $objection) use ($needle): bool {
                $employee = $objection->workEntry?->employee;
                $employeeName = strtolower((string) ($employee?->full_name ?? $employee?->name ?? ''));
                $motivation = strtolower((string) ($objection->motivation ?? ''));

                return str_contains($employeeName, $needle)
                    || str_contains($motivation, $needle)
                    || str_contains((string) $objection->id, $needle);
            })->values();
        }

        return $results;
    }

    /**
     * NL-labels voor status.
     */
    public function labelForStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'OPEN' => 'Open',
            'APPROVED' => 'Geaccepteerd',
            'REJECTED' => 'Afgewezen',
            default => $status,
        };
    }

    /**
     * Badge-variant voor status.
     */
    public function variantForStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'OPEN' => 'concept',
            'APPROVED' => 'success',
            'REJECTED' => 'warning',
            default => 'concept',
        };
    }

    /**
     * Status-opties voor het filter.
     *
     * @return array<string, string>
     */
    public function getStatusOptions(): array
    {
        return [
            'OPEN' => 'Open',
            'APPROVED' => 'Geaccepteerd',
            'REJECTED' => 'Afgewezen',
        ];
    }

    public function render(): View
    {
        return view('livewire.objections.objections-list');
    }
}
