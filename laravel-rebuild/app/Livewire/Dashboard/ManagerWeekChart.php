<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Services\DashboardAggregationService;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * Livewire-component — `Dashboard\ManagerWeekChart`
 *
 * Lazy-loaded staafgrafiek (ApexCharts) met uren per dag (ma-zo),
 * gegroepeerd per team (owner) of totaal (manager).
 *
 * Requirements: 1.3, 1.10
 *
 * De `#[Lazy]` attribute zorgt ervoor dat dit component pas rendert
 * nadat de initiële pagina-paint is voltooid (FCP < 2s).
 * Tijdens het laden wordt de placeholder() methode getoond.
 */
#[Lazy]
final class ManagerWeekChart extends Component
{
    /**
     * Chart-data: array van team/totaal => [dag => minuten].
     *
     * @var array<string, array<string, int>>
     */
    public array $chartData = [];

    public function mount(DashboardAggregationService $aggregationService): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            $this->chartData = ['Totaal' => array_fill_keys(['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'], 0)];
            return;
        }

        $kpiData = $aggregationService->getKpiData($user);
        $this->chartData = $kpiData['chart_data'] ?? ['Totaal' => array_fill_keys(['ma', 'di', 'wo', 'do', 'vr', 'za', 'zo'], 0)];
    }

    /**
     * Placeholder getoond tijdens lazy-loading (skeleton).
     *
     * Gebruikt plain HTML i.p.v. Blade-componenten om recursie-problemen
     * te voorkomen bij het renderen van de placeholder in Livewire's
     * lazy-loading mechanisme.
     */
    public function placeholder(): string
    {
        return <<<'HTML'
        <section aria-label="Uren per dag grafiek" wire:id="placeholder">
            <div class="animate-pulse" role="status" aria-label="Inhoud wordt geladen" aria-hidden="true">
                <div class="h-[200px] w-full rounded bg-surface"></div>
            </div>
        </section>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.dashboard.manager-week-chart');
    }
}
