<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Settings\TeamsManager`.
 *
 * Verantwoordelijkheid:
 *  - Lijst van alle teams binnen de organisatie met ledenaantal.
 *  - Aanmaken van een nieuw team (naam + optionele manager).
 *  - Inline bewerken van teamnaam en manager.
 *  - Verwijderen van een team (alleen als er geen leden aan gekoppeld zijn).
 *
 * Autorisatie:
 *  - Alleen owner mag teams beheren.
 *
 * Scope:
 *  - Alle queries zijn organisatie-scoped (organization_id van de ingelogde user).
 */
#[Layout('layouts.app')]
#[Title('Teams — LaVita Urenregistratie')]
final class TeamsManager extends Component
{
    /**
     * Modus: null = lijst, 'create' = nieuw team, int = bewerk team-id.
     */
    public ?string $mode = null;

    /**
     * Het team-id dat momenteel bewerkt wordt.
     */
    public ?int $editingTeamId = null;

    /**
     * Formuliervelden.
     */
    public string $teamName = '';

    public ?int $managerId = null;

    /**
     * NL-bevestigingsmelding.
     */
    public ?string $confirmation = null;

    /**
     * NL-foutmelding.
     */
    public ?string $error = null;

    /**
     * Naam van de organisatie — voor de header.
     */
    public string $organizationName = '';

    /**
     * Mount-fase: autorisatie-check.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role !== 'owner') {
            abort(403, 'Alleen de eigenaar kan teams beheren.');
        }

        $this->organizationName = (string) ($user->organization?->name ?? '');
    }

    /**
     * Open het formulier voor een nieuw team.
     */
    public function startCreate(): void
    {
        $this->resetForm();
        $this->mode = 'create';
    }

    /**
     * Open de editor voor een bestaand team.
     */
    public function startEdit(int $teamId): void
    {
        $this->resetForm();

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        $team = Team::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('id', $teamId)
            ->first();

        if ($team === null) {
            $this->error = 'Team niet gevonden.';

            return;
        }

        $this->mode = 'edit';
        $this->editingTeamId = $teamId;
        $this->teamName = (string) $team->name;
        $this->managerId = $team->manager_id;
    }

    /**
     * Sluit de editor — terug naar de lijst.
     */
    public function cancel(): void
    {
        $this->resetForm();
    }

    /**
     * Sla een nieuw team op.
     */
    public function createTeam(): void
    {
        $this->confirmation = null;
        $this->error = null;
        $this->resetErrorBag();

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role !== 'owner') {
            $this->error = 'Alleen de eigenaar kan teams aanmaken.';

            return;
        }

        $nameTrimmed = trim($this->teamName);

        if ($nameTrimmed === '') {
            $this->addError('teamName', 'De teamnaam is verplicht.');

            return;
        }

        if (mb_strlen($nameTrimmed) > 255) {
            $this->addError('teamName', 'De teamnaam mag maximaal 255 tekens bevatten.');

            return;
        }

        // Check of de naam al bestaat binnen de organisatie
        $exists = Team::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('name', $nameTrimmed)
            ->exists();

        if ($exists) {
            $this->addError('teamName', 'Er bestaat al een team met deze naam.');

            return;
        }

        // Valideer manager_id indien opgegeven
        if ($this->managerId !== null) {
            $managerExists = User::query()
                ->where('id', $this->managerId)
                ->where('organization_id', (int) $user->organization_id)
                ->where('role', 'manager')
                ->where('is_active', true)
                ->exists();

            if (! $managerExists) {
                $this->addError('managerId', 'De geselecteerde manager is ongeldig.');

                return;
            }
        }

        try {
            Team::create([
                'organization_id' => (int) $user->organization_id,
                'name' => $nameTrimmed,
                'manager_id' => $this->managerId,
            ]);

            $this->confirmation = 'Team aangemaakt.';
            $this->resetForm();
        } catch (\Throwable $e) {
            $this->error = 'Er is een fout opgetreden bij het aanmaken.';
        }
    }

    /**
     * Werk een bestaand team bij.
     */
    public function updateTeam(): void
    {
        $this->confirmation = null;
        $this->error = null;
        $this->resetErrorBag();

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role !== 'owner') {
            $this->error = 'Alleen de eigenaar kan teams bewerken.';

            return;
        }

        if ($this->editingTeamId === null) {
            $this->error = 'Geen team geselecteerd.';

            return;
        }

        $nameTrimmed = trim($this->teamName);

        if ($nameTrimmed === '') {
            $this->addError('teamName', 'De teamnaam is verplicht.');

            return;
        }

        if (mb_strlen($nameTrimmed) > 255) {
            $this->addError('teamName', 'De teamnaam mag maximaal 255 tekens bevatten.');

            return;
        }

        $team = Team::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('id', $this->editingTeamId)
            ->first();

        if ($team === null) {
            $this->error = 'Team niet gevonden.';

            return;
        }

        // Check of de naam al bestaat bij een ander team
        $exists = Team::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('name', $nameTrimmed)
            ->where('id', '!=', $this->editingTeamId)
            ->exists();

        if ($exists) {
            $this->addError('teamName', 'Er bestaat al een team met deze naam.');

            return;
        }

        // Valideer manager_id indien opgegeven
        if ($this->managerId !== null) {
            $managerExists = User::query()
                ->where('id', $this->managerId)
                ->where('organization_id', (int) $user->organization_id)
                ->where('role', 'manager')
                ->where('is_active', true)
                ->exists();

            if (! $managerExists) {
                $this->addError('managerId', 'De geselecteerde manager is ongeldig.');

                return;
            }
        }

        try {
            $team->update([
                'name' => $nameTrimmed,
                'manager_id' => $this->managerId,
            ]);

            $this->confirmation = 'Team bijgewerkt.';
            $this->resetForm();
        } catch (\Throwable $e) {
            $this->error = 'Er is een fout opgetreden bij het opslaan.';
        }
    }

    /**
     * Verwijder een team (alleen als er geen leden aan gekoppeld zijn).
     */
    public function deleteTeam(int $teamId): void
    {
        $this->confirmation = null;
        $this->error = null;
        $this->resetErrorBag();

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role !== 'owner') {
            $this->error = 'Alleen de eigenaar kan teams verwijderen.';

            return;
        }

        $team = Team::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('id', $teamId)
            ->first();

        if ($team === null) {
            $this->error = 'Team niet gevonden.';

            return;
        }

        $memberCount = User::query()
            ->where('team_id', $teamId)
            ->where('organization_id', (int) $user->organization_id)
            ->count();

        if ($memberCount > 0) {
            $this->error = 'Dit team kan niet verwijderd worden omdat er nog leden aan gekoppeld zijn.';

            return;
        }

        try {
            $team->delete();
            $this->confirmation = 'Team verwijderd.';
            $this->resetForm();
        } catch (\Throwable $e) {
            $this->error = 'Er is een fout opgetreden bij het verwijderen.';
        }
    }

    /**
     * Haal alle teams op voor de huidige organisatie, inclusief ledenaantal.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Team>
     */
    public function getTeams(): \Illuminate\Database\Eloquent\Collection
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return Team::query()
            ->where('organization_id', (int) $user->organization_id)
            ->withCount('members')
            ->with('manager')
            ->orderBy('name')
            ->get();
    }

    /**
     * Haal beschikbare managers op (users met rol 'manager' in dezelfde organisatie).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    public function getAvailableManagers(): \Illuminate\Database\Eloquent\Collection
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return User::query()
            ->where('organization_id', (int) $user->organization_id)
            ->where('role', 'manager')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * Reset formuliervelden en modus.
     */
    private function resetForm(): void
    {
        $this->mode = null;
        $this->editingTeamId = null;
        $this->teamName = '';
        $this->managerId = null;
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.settings.teams-manager');
    }
}
