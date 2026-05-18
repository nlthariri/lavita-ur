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

        // Manager ziet alleen eigen team — ALTIJD scopen, ook als team_id null is
        if ($requester->role === 'manager') {
            if ($requester->team_id) {
                $query->where('team_id', $requester->team_id);
            } else {
                // Manager zonder team ziet NIETS (niet alles)
                $query->where('team_id', -1);
            }
        }

        // Employee ziet alleen eigen regels
        if ($requester->role === 'employee') {
            $query->where('employee_id', $requester->id);
        }

        if (! empty($filters['from'])) {
            $query->whereDate('entry_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('entry_date', '<=', $filters['to']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }

        return $query->limit(5000)->get();
    }

    public function toReportRows(Collection $entries): array
    {
        return $entries->map(fn (WorkEntry $e) => [
            'medewerker' => $e->employee?->full_name ?? $e->employee?->name ?? '—',
            'datum' => $e->entry_date instanceof Carbon ? $e->entry_date->format('d-m-Y') : '—',
            'start' => $e->start_at instanceof Carbon ? $e->start_at->setTimezone('Europe/Amsterdam')->format('H:i') : '—',
            'einde' => $e->end_at instanceof Carbon ? $e->end_at->setTimezone('Europe/Amsterdam')->format('H:i') : '—',
            'pauze_minuten' => $e->pause_minutes ?? 0,
            'netto_uren' => number_format(($e->net_minutes ?? 0) / 60, 2, ',', '.'),
            'type' => $e->type ?? '—',
            'team' => $e->team?->name ?? '—',
        ])->all();
    }

    /**
     * Bouw een fiscale jaarexport-aggregatie (taak 12.2 spec
     * lavita-urenregistratie — Requirement 6.7 jaaroverzicht-tab,
     * Requirement 14.5 endpoint, NFR-9 7-jaars retentie).
     *
     * Aggregeert per `(employee × maand)` de minuten per type
     * (`WORK`, `SICK`, `LEAVE`, `HOLIDAY`, `OTHER`) plus jaartotalen.
     * Het resultaat-formaat is gestructureerd zodat een Blade-template
     * één tabel per medewerker kan renderen met 13 kolommen
     * (Jan..Dec + Jaartotaal) en één rij per type plus een totaalrij.
     *
     * Scope-regels (parity met {@see getEntries()}):
     *  - `organization_id` = die van de aanvragende gebruiker.
     *  - manager → uitsluitend eigen `team_id`.
     *  - employee-rol → 403, jaarexport is geen self-service tool
     *    (vertegenwoordigd in de Livewire-component én hier defensief
     *    zodat een directe service-call ook geweigerd wordt).
     *
     * @return array{
     *     year: int,
     *     employees: array<int, array{
     *         employee_id: int,
     *         employee_name: string,
     *         employee_email: string,
     *         team_name: string,
     *         months: array<int, array{WORK:int, SICK:int, LEAVE:int, HOLIDAY:int, OTHER:int, total:int}>,
     *         year_total: array{WORK:int, SICK:int, LEAVE:int, HOLIDAY:int, OTHER:int, total:int}
     *     }>,
     *     generated_at: string
     * }
     */
    public function yearExport(int $requesterId, int $year, ?int $employeeId = null): array
    {
        $requester = User::findOrFail($requesterId);

        // Employees krijgen geen toegang tot de jaarexport — die is voor
        // owner/manager/boekhouder bedoeld (Requirement 6.7 + parity met
        // het screen-level guard in de Livewire-component).
        if ((string) $requester->role === 'employee') {
            abort(403, 'Geen toegang tot jaaroverzicht.');
        }

        // Periode-grenzen op kalenderjaar in Europe/Amsterdam zodat een
        // werkregel op 31 dec 23:30 lokale tijd niet per ongeluk in het
        // volgende boekjaar belandt. We filteren op de DATE-kolom
        // `entry_date` (zelfde semantiek als `getEntries()`).
        $start = Carbon::create($year, 1, 1, 0, 0, 0, 'Europe/Amsterdam')->toDateString();
        $end = Carbon::create($year, 12, 31, 23, 59, 59, 'Europe/Amsterdam')->toDateString();

        $query = WorkEntry::with(['employee:id,name,full_name,email', 'team:id,name'])
            ->where('organization_id', $requester->organization_id)
            ->whereBetween('entry_date', [$start, $end]);

        // Manager-scope identiek aan de rest van de rapportagepaden.
        if ((string) $requester->role === 'manager') {
            if ($requester->team_id) {
                $query->where('team_id', $requester->team_id);
            } else {
                // Manager zonder team ziet NIETS
                $query->where('team_id', -1);
            }
        }

        if ($employeeId !== null) {
            $query->where('employee_id', $employeeId);
        }

        $entries = $query->orderBy('employee_id')->orderBy('entry_date')->get();

        // Aggregeer per (employee, month). Iteratief omdat we per
        // medewerker een 12-maands skelet willen vullen — ook maanden
        // zonder entries verschijnen als nul-rij in de PDF, anders zou
        // de tabel-layout per medewerker scheef worden.
        $perEmployeeMonth = [];

        foreach ($entries as $entry) {
            $eid = (int) $entry->employee_id;

            // entry_date is via cast een Carbon-instance, maar we zijn
            // defensief omdat de cast in de toekomst kan veranderen.
            $month = (int) ($entry->entry_date instanceof Carbon
                ? $entry->entry_date->month
                : Carbon::parse((string) $entry->entry_date)->month);

            if (! isset($perEmployeeMonth[$eid])) {
                $perEmployeeMonth[$eid] = [
                    'employee_id' => $eid,
                    'employee_name' => $entry->employee?->full_name ?? $entry->employee?->name ?? '—',
                    'employee_email' => (string) ($entry->employee?->email ?? ''),
                    'team_name' => (string) ($entry->team?->name ?? '—'),
                    'months' => array_fill(1, 12, [
                        'WORK' => 0,
                        'SICK' => 0,
                        'LEAVE' => 0,
                        'HOLIDAY' => 0,
                        'OTHER' => 0,
                        'total' => 0,
                    ]),
                    'year_total' => [
                        'WORK' => 0,
                        'SICK' => 0,
                        'LEAVE' => 0,
                        'HOLIDAY' => 0,
                        'OTHER' => 0,
                        'total' => 0,
                    ],
                ];
            }

            // Onbekende of nul-types vallen in de OTHER-bucket zodat de
            // som van de 5 type-rijen altijd het totaal oplevert.
            $type = (string) ($entry->type ?? 'WORK');
            if (! in_array($type, ['WORK', 'SICK', 'LEAVE', 'HOLIDAY', 'OTHER'], true)) {
                $type = 'OTHER';
            }

            $minutes = (int) $entry->net_minutes;

            $perEmployeeMonth[$eid]['months'][$month][$type] += $minutes;
            $perEmployeeMonth[$eid]['months'][$month]['total'] += $minutes;
            $perEmployeeMonth[$eid]['year_total'][$type] += $minutes;
            $perEmployeeMonth[$eid]['year_total']['total'] += $minutes;
        }

        return [
            'year' => $year,
            'employees' => array_values($perEmployeeMonth),
            'generated_at' => now()->setTimezone('Europe/Amsterdam')->toIso8601String(),
        ];
    }
}
