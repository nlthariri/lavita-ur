<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ReportQueryService
{
    /**
     * Haal werkregels op met organisatie- en rolscoping.
     *
     * @return Collection<int, WorkEntry>
     */
    public function getEntries(int $requesterId, array $filters): Collection
    {
        $requester = User::findOrFail($requesterId);

        $query = WorkEntry::with(['employee:id,name,full_name,email', 'team:id,name'])
            ->where('organization_id', $requester->organization_id)
            ->orderBy('entry_date', 'desc')
            ->orderBy('start_at', 'desc');

        // Manager ziet alleen eigen team
        if ($requester->role === 'manager' && $requester->team_id) {
            $query->where('team_id', $requester->team_id);
        }

        // Employee ziet alleen eigen regels
        if ($requester->role === 'employee') {
            $query->where('employee_id', $requester->id);
        }

        if (!empty($filters['from'])) {
            $query->whereDate('entry_date', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('entry_date', '<=', $filters['to']);
        }

        if (!empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        return $query->limit(5000)->get();
    }

    public function toReportRows(Collection $entries): array
    {
        return $entries->map(fn (WorkEntry $e) => [
            'medewerker' => $e->employee?->full_name ?? $e->employee?->name ?? '—',
            'datum' => $e->entry_date->format('d-m-Y'),
            'start' => $e->start_at->setTimezone('Europe/Amsterdam')->format('H:i'),
            'einde' => $e->end_at->setTimezone('Europe/Amsterdam')->format('H:i'),
            'pauze_minuten' => $e->pause_minutes,
            'netto_uren' => number_format($e->net_minutes / 60, 2, ',', '.'),
            'type' => $e->type,
            'team' => $e->team?->name ?? '—',
        ])->all();
    }
}
