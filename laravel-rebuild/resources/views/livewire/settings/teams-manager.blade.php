{{--
  Livewire-view — `Settings\TeamsManager`.

  Verantwoordelijkheid:
   - Lijst van alle teams met ledenaantal en manager.
   - Inline-editor voor aanmaken/bewerken van teams.
   - Verwijder-actie (alleen als team geen leden heeft).

  Design-token-discipline:
   - `<x-ui.card>` voor panelen, `<x-ui.button>` voor actieknoppen.
   - Focus-state via `border 2px #00d4a4` (brand-green).
   - Tailwind-classes: border-hairline, text-ink, text-steel, bg-canvas, rounded-input.
--}}
<div data-livewire-component="settings.teams-manager">
    @php
        $teams = $this->getTeams();
        $managers = $this->getAvailableManagers();
    @endphp

    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-2 tablet:flex-row tablet:items-center tablet:justify-between">
                <h1 class="text-heading-2 font-semibold text-ink">
                    Teams
                </h1>
                @if ($organizationName !== '')
                    <p class="text-body-sm text-steel">
                        Organisatie: {{ $organizationName }}
                    </p>
                @endif
            </div>
        </x-slot:header>

        {{-- Bevestigingsmelding --}}
        @if ($confirmation !== null && $confirmation !== '')
            <p
                role="status"
                aria-live="polite"
                data-testid="teams-confirmation"
                class="mb-4 rounded-input border border-success-fg/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg"
            >{{ $confirmation }}</p>
        @endif

        {{-- Foutmelding --}}
        @if ($error !== null && $error !== '')
            <p
                role="alert"
                aria-live="polite"
                data-testid="teams-error"
                class="mb-4 rounded-input border border-danger/40 bg-danger-bg px-3 py-2 text-body-sm text-danger-fg"
            >{{ $error }}</p>
        @endif

        @if ($mode === null)
            {{-- ===== LIJST-MODUS ===== --}}
            <div class="mb-4 flex items-center justify-between">
                <p class="text-body-sm text-steel">
                    {{ $teams->count() }} {{ $teams->count() === 1 ? 'team' : 'teams' }} in uw organisatie.
                </p>
                <x-ui.button
                    variant="primary"
                    wire:click="startCreate"
                    data-testid="teams-create-btn"
                    aria-label="Nieuw team aanmaken"
                >+ Nieuw team</x-ui.button>
            </div>

            @if ($teams->isEmpty())
                <p class="py-8 text-center text-body-sm text-steel">
                    Er zijn nog geen teams aangemaakt.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table
                        class="w-full text-left text-body-sm"
                        aria-label="Lijst van teams"
                    >
                        <thead class="border-b border-hairline">
                            <tr>
                                <th scope="col" class="px-3 py-2 font-medium text-ink">Naam</th>
                                <th scope="col" class="px-3 py-2 font-medium text-ink">Manager</th>
                                <th scope="col" class="px-3 py-2 font-medium text-ink">Leden</th>
                                <th scope="col" class="px-3 py-2 font-medium text-ink">Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($teams as $team)
                                <tr
                                    class="border-b border-hairline"
                                    data-testid="team-row-{{ $team->id }}"
                                >
                                    <td class="px-3 py-2 font-medium text-ink">{{ $team->name }}</td>
                                    <td class="px-3 py-2 text-ink">
                                        {{ $team->manager?->name ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-ink">
                                        {{ $team->members_count }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <x-ui.button
                                                variant="secondary"
                                                wire:click="startEdit({{ $team->id }})"
                                                data-testid="team-edit-{{ $team->id }}"
                                                aria-label="Bewerk team {{ $team->name }}"
                                            >Bewerken</x-ui.button>

                                            @if ($team->members_count === 0)
                                                <x-ui.button
                                                    variant="danger"
                                                    wire:click="deleteTeam({{ $team->id }})"
                                                    wire:confirm="Weet u zeker dat u dit team wilt verwijderen?"
                                                    data-testid="team-delete-{{ $team->id }}"
                                                    aria-label="Verwijder team {{ $team->name }}"
                                                >Verwijderen</x-ui.button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

        @elseif ($mode === 'create')
            {{-- ===== AANMAKEN-MODUS ===== --}}
            <div class="mb-4 flex items-center gap-4">
                <x-ui.button
                    variant="secondary"
                    wire:click="cancel"
                    data-testid="teams-back"
                    aria-label="Terug naar de lijst"
                >← Terug</x-ui.button>

                <h2 class="text-lg font-semibold text-ink">
                    Nieuw team aanmaken
                </h2>
            </div>

            <form wire:submit="createTeam" class="space-y-4">
                {{-- Teamnaam --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="team-name-input"
                        class="text-body-sm font-medium text-ink"
                    >
                        Teamnaam
                    </label>
                    <input
                        id="team-name-input"
                        type="text"
                        wire:model="teamName"
                        maxlength="255"
                        required
                        aria-required="true"
                        aria-describedby="team-name-error"
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                        placeholder="Naam van het team"
                        data-testid="team-name-input"
                    />
                    @error('teamName')
                        <p
                            id="team-name-error"
                            role="alert"
                            class="text-body-sm text-danger-fg"
                        >{{ $message }}</p>
                    @enderror
                </div>

                {{-- Manager --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="team-manager-select"
                        class="text-body-sm font-medium text-ink"
                    >
                        Manager <span class="text-steel">(optioneel)</span>
                    </label>
                    <select
                        id="team-manager-select"
                        wire:model="managerId"
                        aria-describedby="team-manager-error"
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                        data-testid="team-manager-select"
                    >
                        <option value="">— Geen manager —</option>
                        @foreach ($managers as $manager)
                            <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                        @endforeach
                    </select>
                    @error('managerId')
                        <p
                            id="team-manager-error"
                            role="alert"
                            class="text-body-sm text-danger-fg"
                        >{{ $message }}</p>
                    @enderror
                </div>

                {{-- Actieknoppen --}}
                <div class="flex items-center gap-4">
                    <x-ui.button
                        variant="primary"
                        type="submit"
                        data-testid="team-save"
                    >Aanmaken</x-ui.button>

                    <x-ui.button
                        variant="secondary"
                        type="button"
                        wire:click="cancel"
                    >Annuleren</x-ui.button>
                </div>
            </form>

        @elseif ($mode === 'edit')
            {{-- ===== BEWERK-MODUS ===== --}}
            <div class="mb-4 flex items-center gap-4">
                <x-ui.button
                    variant="secondary"
                    wire:click="cancel"
                    data-testid="teams-back"
                    aria-label="Terug naar de lijst"
                >← Terug</x-ui.button>

                <h2 class="text-lg font-semibold text-ink">
                    Team bewerken
                </h2>
            </div>

            <form wire:submit="updateTeam" class="space-y-4">
                {{-- Teamnaam --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="team-name-input"
                        class="text-body-sm font-medium text-ink"
                    >
                        Teamnaam
                    </label>
                    <input
                        id="team-name-input"
                        type="text"
                        wire:model="teamName"
                        maxlength="255"
                        required
                        aria-required="true"
                        aria-describedby="team-name-error"
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                        placeholder="Naam van het team"
                        data-testid="team-name-input"
                    />
                    @error('teamName')
                        <p
                            id="team-name-error"
                            role="alert"
                            class="text-body-sm text-danger-fg"
                        >{{ $message }}</p>
                    @enderror
                </div>

                {{-- Manager --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="team-manager-select"
                        class="text-body-sm font-medium text-ink"
                    >
                        Manager <span class="text-steel">(optioneel)</span>
                    </label>
                    <select
                        id="team-manager-select"
                        wire:model="managerId"
                        aria-describedby="team-manager-error"
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 text-body-sm text-ink focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                        data-testid="team-manager-select"
                    >
                        <option value="">— Geen manager —</option>
                        @foreach ($managers as $manager)
                            <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                        @endforeach
                    </select>
                    @error('managerId')
                        <p
                            id="team-manager-error"
                            role="alert"
                            class="text-body-sm text-danger-fg"
                        >{{ $message }}</p>
                    @enderror
                </div>

                {{-- Actieknoppen --}}
                <div class="flex items-center gap-4">
                    <x-ui.button
                        variant="primary"
                        type="submit"
                        data-testid="team-save"
                    >Opslaan</x-ui.button>

                    <x-ui.button
                        variant="secondary"
                        type="button"
                        wire:click="cancel"
                    >Annuleren</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>
</div>
