{{--
  Livewire-view — `Profile\ProfilePage`

  Profielpagina: bekijk en bewerk eigen gegevens, wijzig wachtwoord.
--}}
@php
    $roleLabel = $this->getRoleLabel();
@endphp

<div class="flex flex-col gap-6" data-livewire-component="profile.profile-page">
    {{-- Profiel-informatie (read-only) --}}
    <x-ui.card>
        <x-slot:header>
            <h1 class="text-heading-2 font-semibold text-ink">Mijn profiel</h1>
        </x-slot:header>

        <dl class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
            <div class="flex flex-col gap-1">
                <dt class="text-body-sm text-steel">E-mailadres</dt>
                <dd class="text-body-md text-ink">{{ $email }}</dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt class="text-body-sm text-steel">Rol</dt>
                <dd class="text-body-md text-ink">{{ $roleLabel }}</dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt class="text-body-sm text-steel">Organisatie</dt>
                <dd class="text-body-md text-ink">{{ $organizationName ?: '—' }}</dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt class="text-body-sm text-steel">Team</dt>
                <dd class="text-body-md text-ink">{{ $teamName }}</dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt class="text-body-sm text-steel">In dienst sinds</dt>
                <dd class="text-body-md text-ink">{{ $employmentStart ?? '—' }}</dd>
            </div>
            <div class="flex flex-col gap-1">
                <dt class="text-body-sm text-steel">Uit dienst</dt>
                <dd class="text-body-md text-ink">{{ $employmentEnd ?? '—' }}</dd>
            </div>
        </dl>
    </x-ui.card>

    {{-- Bewerkbare gegevens --}}
    <x-ui.card>
        <x-slot:header>
            <h2 class="text-heading-3 font-semibold text-ink">Gegevens bewerken</h2>
        </x-slot:header>

        @if ($confirmation)
            <div role="status" aria-live="polite" class="mb-4 rounded-input border border-success/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg">
                {{ $confirmation }}
            </div>
        @endif

        <form wire:submit.prevent="saveProfile" novalidate class="flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <label for="profile-full-name" class="text-body-sm font-medium text-ink">
                    Volledige naam <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input
                    id="profile-full-name"
                    type="text"
                    wire:model="fullName"
                    maxlength="255"
                    class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    @if ($errors->has('fullName')) aria-invalid="true" aria-describedby="profile-full-name-error" @endif
                />
                @error('fullName')
                    <p id="profile-full-name-error" class="text-body-sm text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="profile-phone" class="text-body-sm font-medium text-ink">
                    Telefoonnummer
                </label>
                <input
                    id="profile-phone"
                    type="tel"
                    wire:model="phone"
                    maxlength="20"
                    placeholder="+31 6 12345678"
                    class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    @if ($errors->has('phone')) aria-invalid="true" aria-describedby="profile-phone-error" @endif
                />
                @error('phone')
                    <p id="profile-phone-error" class="text-body-sm text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center gap-2">
                <input
                    id="profile-email-opt-in"
                    type="checkbox"
                    wire:model="emailRemindersOptIn"
                    class="h-4 w-4 rounded border-hairline text-brand-green focus:ring-brand-green"
                />
                <label for="profile-email-opt-in" class="text-body-sm text-ink">
                    E-mailherinneringen ontvangen
                </label>
            </div>

            <div class="flex justify-end">
                <x-ui.button type="submit" variant="primary">
                    Opslaan
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    {{-- Wachtwoord wijzigen --}}
    <x-ui.card>
        <x-slot:header>
            <h2 class="text-heading-3 font-semibold text-ink">Wachtwoord wijzigen</h2>
        </x-slot:header>

        @if ($passwordConfirmation)
            <div role="status" aria-live="polite" class="mb-4 rounded-input border border-success/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg">
                {{ $passwordConfirmation }}
            </div>
        @endif

        <form wire:submit.prevent="changePassword" novalidate class="flex flex-col gap-4">
            <div class="flex flex-col gap-1">
                <label for="profile-current-password" class="text-body-sm font-medium text-ink">
                    Huidig wachtwoord <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input
                    id="profile-current-password"
                    type="password"
                    wire:model="currentPassword"
                    autocomplete="current-password"
                    class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    @if ($errors->has('currentPassword')) aria-invalid="true" aria-describedby="profile-current-password-error" @endif
                />
                @error('currentPassword')
                    <p id="profile-current-password-error" class="text-body-sm text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label for="profile-new-password" class="text-body-sm font-medium text-ink">
                    Nieuw wachtwoord <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input
                    id="profile-new-password"
                    type="password"
                    wire:model="newPassword"
                    autocomplete="new-password"
                    class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    @if ($errors->has('newPassword')) aria-invalid="true" aria-describedby="profile-new-password-error" @endif
                />
                @error('newPassword')
                    <p id="profile-new-password-error" class="text-body-sm text-danger" role="alert">{{ $message }}</p>
                @enderror
                <p class="text-body-sm text-steel">Minimaal 12 tekens.</p>
            </div>

            <div class="flex flex-col gap-1">
                <label for="profile-new-password-confirm" class="text-body-sm font-medium text-ink">
                    Bevestig nieuw wachtwoord <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <input
                    id="profile-new-password-confirm"
                    type="password"
                    wire:model="newPasswordConfirmation"
                    autocomplete="new-password"
                    class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                    @if ($errors->has('newPasswordConfirmation')) aria-invalid="true" aria-describedby="profile-new-password-confirm-error" @endif
                />
                @error('newPasswordConfirmation')
                    <p id="profile-new-password-confirm-error" class="text-body-sm text-danger" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex justify-end">
                <x-ui.button type="submit" variant="secondary">
                    Wachtwoord wijzigen
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
