<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Settings\SettingsOverview`
 *
 * Overzichtspagina voor instellingen met links naar de verschillende
 * instellingen-secties (e-mailtemplates, organisatie, etc.).
 */
#[Layout('layouts.app')]
#[Title('Instellingen — LaVita Urenregistratie')]
final class SettingsOverview extends Component
{
    public string $organizationName = '';

    public string $userRole = '';

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role === 'employee') {
            abort(403, 'Geen toegang tot instellingen.');
        }

        $this->organizationName = (string) ($user->organization?->name ?? '');
        $this->userRole = (string) $user->role;
    }

    /**
     * Beschikbare instellingen-secties op basis van rol.
     *
     * @return array<int, array{label: string, url: string, description: string, roles: array<string>}>
     */
    public function getSections(): array
    {
        $sections = [
            [
                'label' => 'E-mailtemplates',
                'url' => '/instellingen/email',
                'description' => 'Beheer de 11 e-mailtemplates voor notificaties en herinneringen.',
                'roles' => ['owner', 'manager'],
            ],
            [
                'label' => 'Organisatie',
                'url' => '/instellingen/organisatie',
                'description' => 'Organisatienaam, retentieperiode en algemene instellingen.',
                'roles' => ['owner'],
            ],
            [
                'label' => 'Teams',
                'url' => '/instellingen/teams',
                'description' => 'Beheer teams en teamindelingen.',
                'roles' => ['owner'],
            ],
            [
                'label' => 'Projecten',
                'url' => '/instellingen/projecten',
                'description' => 'Beheer projecten en kostenplaatsen voor urenregistratie.',
                'roles' => ['owner', 'manager'],
            ],
            [
                'label' => 'Feestdagen',
                'url' => '/instellingen/feestdagen',
                'description' => 'Beheer erkende feestdagen per jaar.',
                'roles' => ['owner'],
            ],
        ];

        return array_filter($sections, fn (array $section) => in_array($this->userRole, $section['roles'], true));
    }

    public function render(): View
    {
        return view('livewire.settings.settings-overview');
    }
}
