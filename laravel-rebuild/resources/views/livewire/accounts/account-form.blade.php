{{--
  Livewire-view — `Accounts\AccountForm` (taak 12.3 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.8  → create/edit-formulier voor accountbeheer.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels, NL-foutmeldingen.

  Compositie:
   - Wanneer `! $isOpen` rendert deze view een lege wrapper. Het component
     blijft op de pagina staan zodat het kan luisteren op het
     `open-account-form`-event.
   - Wanneer `$isOpen`:
       1. Backdrop (`role="presentation"`) die op klik `closeModal` triggert.
       2. Modal-paneel (`role="dialog" aria-modal="true"
          aria-labelledby="account-modal-heading"`) met het invoer-formulier
          binnen `<x-ui.card>`.
       3. Confirmation-blok (`role="status"`) toont NL-bevestiging na
          opslaan.

  Design-token-discipline:
   - `<x-ui.card>`, `<x-ui.button>`, `<x-ui.text-input>` voor primitives.
   - Native `<select>` voor rol en team — `<x-ui.text-input>` ondersteunt
     geen `type=select`.
--}}
<div data-livewire-component="accounts.account-form">
    @if ($isOpen)
        @php
            /** @var \Illuminate\Support\ViewErrorBag $errors */
            $isCreate = $userId === null;
            $heading = $isCreate ? 'Nieuw account aanmaken' : 'Account bewerken';

            $teams = $this->getTeams();
            $roleOptions = $this->getRoleOptions();
            $userIdError = $errors->first('userId');
        @endphp

        {{-- Backdrop. --}}
        <div
            role="presentation"
            wire:click="closeModal"
            class="fixed inset-0 z-40 bg-ink/60"
            data-testid="account-form-backdrop"
        ></div>

        {{-- Modal-paneel. --}}
        <div
            role="dialog"
            aria-modal="true"
            aria-labelledby="account-modal-heading"
            class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4"
        >
            <div class="w-full max-w-xl">
                <x-ui.card>
                    <x-slot:header>
                        <h2
                            id="account-modal-heading"
                            class="text-heading-2 font-semibold text-ink"
                        >{{ $heading }}</h2>
                    </x-slot:header>

                    {{-- userId-fout (out-of-scope user / niet gevonden). --}}
                    @if ($userIdError !== null)
                        <p
                            role="alert"
                            aria-live="polite"
                            data-testid="account-form-userid-error"
                            class="mb-4 rounded-input border border-danger/40 bg-danger-bg px-3 py-2 text-body-sm text-danger-fg"
                        >{{ $userIdError }}</p>
                    @endif

                    {{-- Confirmation-blok ("Account opgeslagen.", "Account aangemaakt."). --}}
                    @if ($confirmation !== null && $confirmation !== '')
                        <p
                            role="status"
                            aria-live="polite"
                            data-testid="account-form-confirmation"
                            class="mb-4 rounded-input border border-success-fg/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg"
                        >{{ $confirmation }}</p>
                    @endif

                    <form
                        wire:submit.prevent="submit"
                        method="POST"
                        action="#"
                        novalidate
                        aria-labelledby="account-modal-heading"
                        class="flex flex-col gap-4"
                    >
                        {{-- Naam --}}
                        <x-ui.text-input
                            name="name"
                            type="text"
                            label="Naam"
                            required
                            autocomplete="name"
                            wire:model.blur="name"
                            :value="$name"
                            :error="$errors->first('name')"
                            help="Korte naam, maximaal 100 tekens."
                        />

                        {{-- Volledige naam --}}
                        <x-ui.text-input
                            name="fullName"
                            type="text"
                            label="Volledige naam"
                            autocomplete="name"
                            wire:model.blur="fullName"
                            :value="$fullName"
                            :error="$errors->first('fullName')"
                            help="Optioneel. Verschijnt in welkomstmail en rapportages."
                        />

                        {{-- E-mailadres --}}
                        <x-ui.text-input
                            name="email"
                            type="email"
                            label="E-mailadres"
                            required
                            autocomplete="email"
                            wire:model.blur="email"
                            :value="$email"
                            :error="$errors->first('email')"
                        />

                        {{-- Rol-select. --}}
                        @php
                            $roleError = $errors->first('role');
                        @endphp
                        <div class="flex flex-col gap-1">
                            <label for="account-role" class="text-body-sm font-medium text-ink">
                                Rol
                                <span class="text-danger" aria-hidden="true">*</span>
                                <span class="sr-only">(verplicht)</span>
                            </label>
                            <select
                                id="account-role"
                                name="role"
                                wire:model.live="role"
                                required
                                @if ($roleError) aria-invalid="true" aria-describedby="account-role-error" @endif
                                class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                            >
                                @foreach ($roleOptions as $code => $label)
                                    <option value="{{ $code }}" @selected($role === $code)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @if ($roleError)
                                <p
                                    id="account-role-error"
                                    class="text-body-sm text-danger"
                                    role="alert"
                                    aria-live="polite"
                                >{{ $roleError }}</p>
                            @endif
                        </div>

                        {{-- Team-select. --}}
                        @php
                            $teamError = $errors->first('teamId');
                        @endphp
                        <div class="flex flex-col gap-1">
                            <label for="account-team" class="text-body-sm font-medium text-ink">
                                Team
                            </label>
                            <select
                                id="account-team"
                                name="teamId"
                                wire:model.live="teamId"
                                @if ($teamError) aria-invalid="true" aria-describedby="account-team-error" @endif
                                class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                            >
                                <option value="">Geen team</option>
                                @foreach ($teams as $id => $teamName)
                                    <option value="{{ $id }}" @selected($teamId === (int) $id)>{{ $teamName }}</option>
                                @endforeach
                            </select>
                            @if ($teamError)
                                <p
                                    id="account-team-error"
                                    class="text-body-sm text-danger"
                                    role="alert"
                                    aria-live="polite"
                                >{{ $teamError }}</p>
                            @endif
                        </div>

                        {{-- Datum-velden, naast elkaar op desktop. --}}
                        <div class="grid grid-cols-1 gap-4 tablet:grid-cols-2">
                            <x-ui.text-input
                                name="employmentStart"
                                type="date"
                                label="Indiensttreding"
                                wire:model.blur="employmentStart"
                                :value="$employmentStart"
                                :error="$errors->first('employmentStart')"
                            />
                            <x-ui.text-input
                                name="employmentEnd"
                                type="date"
                                label="Uitdiensttreding"
                                wire:model.blur="employmentEnd"
                                :value="$employmentEnd"
                                :error="$errors->first('employmentEnd')"
                                help="Optioneel."
                            />
                        </div>

                        {{-- Active-checkbox. --}}
                        @php
                            $activeError = $errors->first('isActive');
                        @endphp
                        <div class="flex flex-col gap-1">
                            <label for="account-is-active" class="inline-flex items-center gap-2 text-body-sm font-medium text-ink">
                                <input
                                    id="account-is-active"
                                    name="isActive"
                                    type="checkbox"
                                    wire:model.live="isActive"
                                    @if ($activeError) aria-invalid="true" aria-describedby="account-is-active-error" @endif
                                    class="h-4 w-4 rounded border-2 border-hairline text-brand-green focus:border-brand-green focus:ring-2 focus:ring-brand-green/20"
                                />
                                Account actief
                            </label>
                            @if ($activeError)
                                <p
                                    id="account-is-active-error"
                                    class="text-body-sm text-danger"
                                    role="alert"
                                    aria-live="polite"
                                >{{ $activeError }}</p>
                            @endif
                            <p class="text-body-sm text-steel">
                                Inactieve accounts kunnen niet inloggen.
                            </p>
                        </div>

                        {{-- E-mail herinneringen opt-in toggle (Req 9.1, 9.6). --}}
                        @php
                            $emailRemindersError = $errors->first('emailRemindersOptIn');
                        @endphp
                        <div class="flex flex-col gap-1">
                            <label for="account-email-reminders" class="inline-flex items-center gap-2 text-body-sm font-medium text-ink">
                                <input
                                    id="account-email-reminders"
                                    name="emailRemindersOptIn"
                                    type="checkbox"
                                    wire:model.live="emailRemindersOptIn"
                                    @if ($emailRemindersError) aria-invalid="true" aria-describedby="account-email-reminders-error" @endif
                                    class="h-4 w-4 rounded border-2 border-hairline text-brand-green focus:border-brand-green focus:ring-2 focus:ring-brand-green/20"
                                />
                                E-mail herinneringen ontvangen
                            </label>
                            @if ($emailRemindersError)
                                <p
                                    id="account-email-reminders-error"
                                    class="text-body-sm text-danger"
                                    role="alert"
                                    aria-live="polite"
                                >{{ $emailRemindersError }}</p>
                            @endif
                            <p class="text-body-sm text-steel">
                                Wanneer uitgeschakeld ontvangt deze gebruiker geen herinneringsmails voor openstaande invoer.
                            </p>
                        </div>

                        {{-- Verlofrecht configuratie (Req 9.1, 9.2). --}}
                        @php
                            $annualLeaveDaysError = $errors->first('annualLeaveDays');
                        @endphp
                        <div class="flex flex-col gap-3">
                            <div class="flex flex-col gap-1">
                                <label for="account-annual-leave-days" class="text-body-sm font-medium text-ink">
                                    Jaarlijks verlofrecht (dagen)
                                </label>
                                <input
                                    id="account-annual-leave-days"
                                    name="annualLeaveDays"
                                    type="number"
                                    min="0"
                                    max="365"
                                    step="1"
                                    wire:model.blur="annualLeaveDays"
                                    placeholder="Niet geconfigureerd"
                                    @if ($annualLeaveDaysError) aria-invalid="true" aria-describedby="account-annual-leave-days-error" @endif
                                    class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                                />
                                @if ($annualLeaveDaysError)
                                    <p
                                        id="account-annual-leave-days-error"
                                        class="text-body-sm text-danger"
                                        role="alert"
                                        aria-live="polite"
                                    >{{ $annualLeaveDaysError }}</p>
                                @endif
                                <p class="text-body-sm text-steel">
                                    Laat leeg om verlof-saldo tracking uit te schakelen. Waarde 0-365.
                                </p>
                            </div>

                            {{-- Verlof-saldo overzicht (Req 9.5, 9.6, 9.7, 9.8, 9.10) --}}
                            @if (! $isCreate && $leaveBalance !== null && $leaveBalance['status'] !== 'unconfigured')
                                @php
                                    $balanceAnnual = $leaveBalance['annual_days'] ?? 0;
                                    $balanceTaken = $leaveBalance['taken_days'] ?? 0;
                                    $balanceRemaining = $leaveBalance['remaining_days'] ?? 0;
                                    $balanceStatus = $leaveBalance['status'] ?? 'ok';
                                    $balanceBreakdown = $leaveBalance['breakdown'] ?? [];

                                    $progressVariant = match ($balanceStatus) {
                                        'danger' => 'danger',
                                        'warning' => 'warning',
                                        default => 'success',
                                    };

                                    $statusBadgeText = match ($balanceStatus) {
                                        'danger' => 'Saldo op',
                                        'warning' => 'Bijna op',
                                        default => null,
                                    };
                                @endphp
                                <div
                                    class="rounded-card border border-hairline bg-surface p-4"
                                    data-testid="leave-balance-overview"
                                    aria-label="Verlof-saldo overzicht"
                                >
                                    <h3 class="mb-3 text-body-sm font-semibold text-ink">Verlof-saldo {{ now()->year }}</h3>

                                    {{-- Saldo-details --}}
                                    <div class="mb-3 grid grid-cols-3 gap-2 text-center">
                                        <div class="flex flex-col">
                                            <span class="text-heading-3 font-bold text-ink">{{ $balanceAnnual }}</span>
                                            <span class="text-body-sm text-steel">Recht</span>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-heading-3 font-bold text-ink">{{ number_format($balanceTaken, 1) }}</span>
                                            <span class="text-body-sm text-steel">Opgenomen</span>
                                        </div>
                                        <div class="flex flex-col">
                                            <span class="text-heading-3 font-bold {{ $balanceStatus === 'danger' ? 'text-danger' : ($balanceStatus === 'warning' ? 'text-warning' : 'text-brand-green') }}">{{ number_format($balanceRemaining, 1) }}</span>
                                            <span class="text-body-sm text-steel">Resterend</span>
                                        </div>
                                    </div>

                                    {{-- Progress bar --}}
                                    <x-ui.progress
                                        :value="$balanceTaken"
                                        :max="$balanceAnnual > 0 ? $balanceAnnual : 1"
                                        :variant="$progressVariant"
                                        label="Opgenomen verlofdagen"
                                        :showPercentage="true"
                                    />

                                    {{-- Status-badge bij waarschuwing --}}
                                    @if ($statusBadgeText !== null)
                                        <div class="mt-2">
                                            <span class="inline-flex items-center rounded-button px-2 py-0.5 text-body-sm font-medium {{ $balanceStatus === 'danger' ? 'bg-danger/10 text-danger' : 'bg-warning/10 text-warning' }}">
                                                {{ $statusBadgeText }}
                                            </span>
                                        </div>
                                    @endif

                                    {{-- Breakdown per verlof-type --}}
                                    @if (count($balanceBreakdown) > 0)
                                        <div class="mt-3 border-t border-hairline pt-2">
                                            <p class="mb-1 text-body-sm font-medium text-steel">Uitsplitsing:</p>
                                            <ul class="space-y-0.5 text-body-sm text-ink">
                                                @foreach ($balanceBreakdown as $typeName => $days)
                                                    <li class="flex justify-between">
                                                        <span>{{ $typeName }}</span>
                                                        <span class="font-mono text-steel">{{ number_format($days, 1) }} {{ $days == 1 ? 'dag' : 'dagen' }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>

                        {{-- Acties --}}
                        <div class="mt-2 flex flex-col-reverse gap-3 tablet:flex-row tablet:justify-end">
                            <x-ui.button
                                type="button"
                                variant="secondary"
                                wire:click="closeModal"
                                data-testid="account-form-cancel"
                            >Annuleren</x-ui.button>

                            <x-ui.button
                                type="submit"
                                variant="primary"
                                wire:loading.attr="disabled"
                                wire:target="submit"
                                data-testid="account-form-save"
                            >
                                <span wire:loading.remove wire:target="submit">Opslaan</span>
                                <span wire:loading wire:target="submit" aria-live="polite">Bezig met opslaan…</span>
                            </x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            </div>
        </div>
    @endif
</div>
