<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Profile\ProfilePage`
 *
 * Profielpagina waarop de ingelogde gebruiker zijn eigen gegevens kan
 * bekijken en beperkt bewerken (naam, telefoon, wachtwoord wijzigen,
 * e-mail herinneringen opt-in).
 */
#[Layout('layouts.app')]
#[Title('Mijn profiel — LaVita Urenregistratie')]
final class ProfilePage extends Component
{
    public string $fullName = '';

    public string $phone = '';

    public bool $emailRemindersOptIn = true;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public ?string $confirmation = null;

    public ?string $passwordConfirmation = null;

    // Read-only display fields
    public string $email = '';

    public string $role = '';

    public string $teamName = '';

    public string $organizationName = '';

    public ?string $employmentStart = null;

    public ?string $employmentEnd = null;

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        $this->fullName = (string) ($user->full_name ?? $user->name ?? '');
        $this->phone = (string) ($user->phone ?? '');
        $this->emailRemindersOptIn = (bool) ($user->email_reminders_opt_in ?? true);
        $this->email = (string) ($user->email ?? '');
        $this->role = (string) ($user->role ?? '');
        $this->teamName = (string) ($user->team?->name ?? '—');
        $this->organizationName = (string) ($user->organization?->name ?? '');
        $this->employmentStart = $user->employment_start?->format('d-m-Y');
        $this->employmentEnd = $user->employment_end?->format('d-m-Y');
    }

    /**
     * Sla profielwijzigingen op (naam, telefoon, e-mail opt-in).
     */
    public function saveProfile(): void
    {
        $this->confirmation = null;
        $this->resetErrorBag();

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        // Haal een verse instantie op uit de database zodat we niet
        // per ongeluk kolommen overschrijven met null (partial model).
        $user = User::find($user->id);
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        $fullNameTrimmed = trim($this->fullName);
        if ($fullNameTrimmed === '') {
            $this->addError('fullName', 'Naam is verplicht.');

            return;
        }
        if (mb_strlen($fullNameTrimmed) > 255) {
            $this->addError('fullName', 'Naam mag maximaal 255 tekens bevatten.');

            return;
        }

        $phoneTrimmed = trim($this->phone);
        if ($phoneTrimmed !== '' && mb_strlen($phoneTrimmed) > 20) {
            $this->addError('phone', 'Telefoonnummer mag maximaal 20 tekens bevatten.');

            return;
        }

        $user->full_name = $fullNameTrimmed;
        $user->phone = $phoneTrimmed !== '' ? $phoneTrimmed : null;
        $user->email_reminders_opt_in = $this->emailRemindersOptIn;
        $user->save();

        $this->confirmation = 'Profiel opgeslagen.';
    }

    /**
     * Wijzig wachtwoord.
     */
    public function changePassword(): void
    {
        $this->passwordConfirmation = null;
        $this->resetErrorBag();

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        if (trim($this->currentPassword) === '') {
            $this->addError('currentPassword', 'Huidig wachtwoord is verplicht.');

            return;
        }

        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', 'Huidig wachtwoord is onjuist.');

            return;
        }

        if (mb_strlen($this->newPassword) < 12) {
            $this->addError('newPassword', 'Nieuw wachtwoord moet minimaal 12 tekens bevatten.');

            return;
        }

        if ($this->newPassword !== $this->newPasswordConfirmation) {
            $this->addError('newPasswordConfirmation', 'Wachtwoorden komen niet overeen.');

            return;
        }

        $user->password = Hash::make($this->newPassword);
        $user->save();

        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPasswordConfirmation = '';
        $this->passwordConfirmation = 'Wachtwoord gewijzigd.';
    }

    /**
     * NL-label voor de rol.
     */
    public function getRoleLabel(): string
    {
        return match ($this->role) {
            'owner' => 'Eigenaar',
            'manager' => 'Manager',
            'employee' => 'Medewerker',
            'boekhouder' => 'Boekhouder',
            default => $this->role,
        };
    }

    public function render(): View
    {
        return view('livewire.profile.profile-page');
    }
}
