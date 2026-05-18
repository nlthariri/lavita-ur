<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Models\Objection;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Dashboard\EmployeeHome`
 *
 * Dashboard voor medewerkers (employee-rol). Toont:
 * - Uren deze week (totaal netto-minuten)
 * - Openstaande bezwaren (eigen)
 * - Snelkoppelingen naar mijn-week, verlof, bezwaren
 */
#[Layout('layouts.app')]
#[Title('Dashboard — LaVita Urenregistratie')]
final class EmployeeHome extends Component
{
    public string $userFullName = '';

    public string $organizationName = '';

    public int $totalMinutesThisWeek = 0;

    public int $daysWorkedThisWeek = 0;

    public int $openObjectionsCount = 0;

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        $this->userFullName = (string) ($user->full_name ?: $user->name ?: '');
        $this->organizationName = (string) ($user->organization?->name ?? '');

        $monday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->toDateString();
        $sunday = Carbon::now('Europe/Amsterdam')->startOfWeek(Carbon::MONDAY)->addDays(6)->toDateString();

        // Uren deze week
        $entries = WorkEntry::query()
            ->where('employee_id', (int) $user->id)
            ->whereBetween('entry_date', [$monday, $sunday])
            ->whereNull('deleted_at')
            ->get(['net_minutes', 'entry_date']);

        $this->totalMinutesThisWeek = (int) $entries->sum('net_minutes');
        $this->daysWorkedThisWeek = $entries->pluck('entry_date')->unique()->count();

        // Eigen openstaande bezwaren
        $this->openObjectionsCount = (int) Objection::query()
            ->where('submitted_by_id', (int) $user->id)
            ->where('status', 'OPEN')
            ->count();
    }

    /**
     * Formatteer minuten als uren en minuten.
     */
    public function getFormattedHours(): string
    {
        $hours = intdiv($this->totalMinutesThisWeek, 60);
        $minutes = $this->totalMinutesThisWeek % 60;

        if ($hours > 0 && $minutes > 0) {
            return "{$hours}u {$minutes}min";
        }
        if ($hours > 0) {
            return "{$hours}u";
        }

        return "{$minutes}min";
    }

    public function render(): View
    {
        return view('livewire.dashboard.employee-home');
    }
}
