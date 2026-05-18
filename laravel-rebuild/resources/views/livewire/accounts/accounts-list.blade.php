{{--
  Livewire-view — `Accounts\AccountsList` (taak 12.3 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.8  → scherm "Accountbeheer" met tabel + search,
       create/edit, activeren/deactiveren, soft-delete (alleen owner).
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels, NL-foutmeldingen.

  Compositie:
   - Header met organisatie-naam, search-veld en filters (rol/status).
   - Primary CTA "+ Nieuw account" boven de tabel.
   - `<livewire:accounts.account-form />` als ingebed modal-component
     dat luistert op het `open-account-form`-event.
   - Tabel met kolommen Naam, E-mail, Rol, Team, Status, Acties.
   - Empty-state-rij wanneer er geen accounts overeenkomen met de filters.

  Design-token-discipline:
   - `<x-ui.card>` voor het paneel, `<x-ui.button>` voor de actieknoppen,
     `<x-ui.text-input>` voor de search en `<x-ui.status-badge>` voor de
     statuskolom.
   - Native `<select>` voor de filters — `<x-ui.text-input>` ondersteunt
     geen `type=select`.
--}}
<div data-livewire-component="accounts.accounts-list">
    @php
        /** @var \Illuminate\Support\ViewErrorBag $errors */
        $authUser = \Illuminate\Support\Facades\Auth::user();
        $isOwner = $authUser !== null && (string) $authUser->role === 'owner';

        $users = $this->getUsers();
        $teamLookup = $this->getTeamLookup();
        $roleOptions = $this->getRoleOptions();
        $statusOptions = $this->getStatusOptions();

        $toggleError = $errors->first('toggle');
        $softDeleteError = $errors->first('softDelete');
    @endphp

    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-2 tablet:flex-row tablet:items-center tablet:justify-between">
                <h1 class="text-heading-2 font-semibold text-ink">
                    Accountbeheer
                </h1>
                @if ($organizationName !== '')
                    <p class="text-body-sm text-steel">
                        Organisatie: {{ $organizationName }}
                    </p>
                @endif
            </div>
        </x-slot:header>

        {{-- Bevestigingsmelding (bv. "Soft-delete wordt geactiveerd zodra…",
             "Account opgeslagen.") --}}
        @if ($confirmation !== null && $confirmation !== '')
            <p
                role="status"
                aria-live="polite"
                data-testid="accounts-confirmation"
                class="mb-4 rounded-input border border-success-fg/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg"
            >{{ $confirmation }}</p>
        @endif

        {{-- Globale toggle-/softDelete-foutmeldingen — niet aan een veld
             gekoppeld, maar wel zichtbaar voor screenreaders. --}}
        @if ($toggleError !== null)
            <p
                role="alert"
                aria-live="polite"
                data-testid="accounts-toggle-error"
                class="mb-4 rounded-input border border-danger/40 bg-danger-bg px-3 py-2 text-body-sm text-danger-fg"
            >{{ $toggleError }}</p>
        @endif

        @if ($softDeleteError !== null)
            <p
                role="alert"
                aria-live="polite"
                data-testid="accounts-soft-delete-error"
                class="mb-4 rounded-input border border-danger/40 bg-danger-bg px-3 py-2 text-body-sm text-danger-fg"
            >{{ $softDeleteError }}</p>
        @endif

        {{-- Filters: zoek + rol + status. Op desktop side-by-side, op
             mobiel gestapeld. --}}
        <div class="mb-4 grid grid-cols-1 gap-4 tablet:grid-cols-3">
            <x-ui.text-input
                name="search"
                type="search"
                label="Zoeken"
                placeholder="Naam of e-mailadres"
                wire:model.live.debounce.250ms="search"
                :value="$search"
                help="Zoekt in naam, volledige naam en e-mailadres."
            />

            <div class="flex flex-col gap-1">
                <label for="accounts-role-filter" class="text-body-sm font-medium text-ink">
                    Rol
                </label>
                <select
                    id="accounts-role-filter"
                    name="roleFilter"
                    wire:model.live="roleFilter"
                    class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                >
                    <option value="">Alle rollen</option>
                    @foreach ($roleOptions as $code => $label)
                        <option value="{{ $code }}" @selected($roleFilter === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="flex flex-col gap-1">
                <label for="accounts-status-filter" class="text-body-sm font-medium text-ink">
                    Status
                </label>
                <select
                    id="accounts-status-filter"
                    name="statusFilter"
                    wire:model.live="statusFilter"
                    class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                >
                    <option value="">Alle statussen</option>
                    @foreach ($statusOptions as $code => $label)
                        <option value="{{ $code }}" @selected($statusFilter === $code)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- Primary CTA. --}}
        <div class="mb-4 flex justify-end">
            <x-ui.button
                variant="primary"
                wire:click="openCreate"
                data-testid="accounts-open-create"
            >+ Nieuw account</x-ui.button>
        </div>

        {{-- Ingebed account-form-modaal — luistert op
             `open-account-form`-event en dispatcht `account-saved` na
             een succesvolle opslag. --}}
        <livewire:accounts.account-form />

        {{-- Tabel. Bewust een native `<table>` met `scope`-attributen voor
             screenreader-orientatie (WCAG 1.3.1). --}}
        <div class="overflow-x-auto">
            <table class="w-full text-left text-body-sm" aria-label="Lijst van accounts">
                <thead class="border-b border-hairline">
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium text-ink">Naam</th>
                        <th scope="col" class="px-3 py-2 font-medium text-ink">E-mail</th>
                        <th scope="col" class="px-3 py-2 font-medium text-ink">Rol</th>
                        <th scope="col" class="px-3 py-2 font-medium text-ink">Team</th>
                        <th scope="col" class="px-3 py-2 font-medium text-ink">Status</th>
                        <th scope="col" class="px-3 py-2 font-medium text-ink">Acties</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        @php
                            $teamName = $user->team_id !== null
                                ? ($teamLookup[(int) $user->team_id] ?? '—')
                                : '—';
                            $statusVariant = $user->is_active ? 'success' : 'concept';
                            $statusLabel = $user->is_active ? 'Actief' : 'Inactief';
                            $toggleLabel = $user->is_active ? 'Deactiveren' : 'Activeren';
                            $displayName = $user->full_name !== null && $user->full_name !== ''
                                ? $user->full_name
                                : $user->name;
                        @endphp
                        <tr class="border-b border-hairline" data-testid="accounts-row-{{ $user->id }}">
                            <td class="px-3 py-2 text-ink">{{ $displayName }}</td>
                            <td class="px-3 py-2 font-mono text-body-sm text-ink">{{ $user->email }}</td>
                            <td class="px-3 py-2 text-ink">{{ $this->labelForRole((string) $user->role) }}</td>
                            <td class="px-3 py-2 text-ink">{{ $teamName }}</td>
                            <td class="px-3 py-2">
                                <x-ui.status-badge
                                    :variant="$statusVariant"
                                    icon
                                    data-testid="accounts-status-{{ $user->id }}"
                                >{{ $statusLabel }}</x-ui.status-badge>
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap gap-2">
                                    <x-ui.button
                                        variant="secondary"
                                        wire:click="openEdit({{ (int) $user->id }})"
                                        data-testid="accounts-edit-{{ $user->id }}"
                                    >Bewerken</x-ui.button>

                                    <x-ui.button
                                        variant="ghost"
                                        wire:click="toggleActive({{ (int) $user->id }})"
                                        data-testid="accounts-toggle-{{ $user->id }}"
                                    >{{ $toggleLabel }}</x-ui.button>

                                    @if ($isOwner)
                                        <x-ui.button
                                            variant="danger"
                                            wire:click="softDeletePlaceholder({{ (int) $user->id }})"
                                            data-testid="accounts-soft-delete-{{ $user->id }}"
                                            aria-label="Soft-delete {{ $displayName }}"
                                        >Soft-delete</x-ui.button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr data-testid="accounts-empty-row">
                            <td
                                colspan="6"
                                class="px-3 py-6 text-center text-body-sm text-steel"
                            >
                                Geen accounts gevonden voor de huidige filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-ui.card>
</div>
