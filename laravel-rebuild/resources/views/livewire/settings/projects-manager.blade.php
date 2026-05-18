{{--
  Livewire-view — `Settings\ProjectsManager`.

  Beheerscherm voor Projecten en Kostenplaatsen met twee tabbladen.
  Elke sectie biedt: zoekfilter, lijst, inline aanmaken/bewerken, archiveren.

  Design-token-discipline:
   - `<x-ui.card>` voor panelen, `<x-ui.button>` voor actieknoppen.
   - `<x-ui.text-input>` voor formuliervelden.
   - `<x-ui.status-badge>` voor actief/inactief/gearchiveerd.
   - Focus-state via `border 2px #00d4a4` (brand-green).
--}}
<div data-livewire-component="settings.projects-manager">

    {{-- Bevestigingsmelding --}}
    @if ($confirmation !== null && $confirmation !== '')
        <div
            role="status"
            aria-live="polite"
            data-testid="projects-manager-confirmation"
            class="mb-4 rounded-input border border-success-fg/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg"
        >{{ $confirmation }}</div>
    @endif

    {{-- Tab-navigatie --}}
    <div class="mb-4 flex gap-2 border-b border-hairline" role="tablist" aria-label="Secties">
        <button
            role="tab"
            type="button"
            wire:click="switchTab('projects')"
            aria-selected="{{ $activeTab === 'projects' ? 'true' : 'false' }}"
            data-testid="tab-projects"
            @class([
                'px-4 py-2 text-body-sm font-medium transition-colors -mb-px',
                'border-b-2 border-brand-green text-ink' => $activeTab === 'projects',
                'text-steel hover:text-ink' => $activeTab !== 'projects',
            ])
        >
            Projecten
        </button>
        <button
            role="tab"
            type="button"
            wire:click="switchTab('cost_centers')"
            aria-selected="{{ $activeTab === 'cost_centers' ? 'true' : 'false' }}"
            data-testid="tab-cost-centers"
            @class([
                'px-4 py-2 text-body-sm font-medium transition-colors -mb-px',
                'border-b-2 border-brand-green text-ink' => $activeTab === 'cost_centers',
                'text-steel hover:text-ink' => $activeTab !== 'cost_centers',
            ])
        >
            Kostenplaatsen
        </button>
    </div>

    {{-- ===== TAB: PROJECTEN ===== --}}
    @if ($activeTab === 'projects')
        <x-ui.card>
            <x-slot:header>
                <div class="flex flex-col gap-2 tablet:flex-row tablet:items-center tablet:justify-between">
                    <h2 class="text-heading-2 font-semibold text-ink">Projecten</h2>
                    @if ($isOwner)
                        <x-ui.button
                            variant="primary"
                            wire:click="openProjectForm"
                            data-testid="project-create-btn"
                        >+ Nieuw project</x-ui.button>
                    @endif
                </div>
            </x-slot:header>

            {{-- Zoekfilter --}}
            <div class="mb-4">
                <x-ui.text-input
                    label="Zoeken"
                    name="projectSearch"
                    type="search"
                    placeholder="Zoek op code of naam..."
                    wire:model.live.debounce.300ms="projectSearch"
                    data-testid="project-search"
                />
            </div>

            {{-- Inline formulier --}}
            @if ($showProjectForm)
                <div class="mb-4 rounded-input border border-hairline bg-canvas p-4" data-testid="project-form">
                    <h3 class="mb-3 text-body-sm font-semibold text-ink">
                        {{ $editingProjectId !== null ? 'Project bewerken' : 'Nieuw project' }}
                    </h3>

                    <form wire:submit="saveProject" class="space-y-3">
                        <div class="grid grid-cols-1 gap-3 tablet:grid-cols-2">
                            <x-ui.text-input
                                label="Code"
                                name="projectCode"
                                placeholder="Bijv. PROJ-001"
                                :required="true"
                                maxlength="40"
                                wire:model="projectCode"
                                :error="$errors->first('projectCode')"
                                data-testid="project-code-input"
                            />
                            <x-ui.text-input
                                label="Naam"
                                name="projectName"
                                placeholder="Projectnaam"
                                :required="true"
                                maxlength="120"
                                wire:model="projectName"
                                :error="$errors->first('projectName')"
                                data-testid="project-name-input"
                            />
                        </div>

                        <div class="grid grid-cols-1 gap-3 tablet:grid-cols-2">
                            <x-ui.text-input
                                label="Uurtarief (€)"
                                name="projectHourlyRate"
                                type="number"
                                placeholder="0.00"
                                step="0.01"
                                min="0"
                                wire:model="projectHourlyRate"
                                :error="$errors->first('projectHourlyRate')"
                                data-testid="project-hourly-rate-input"
                            />
                            <div class="flex flex-col gap-1">
                                <label for="projectDescription" class="text-body-sm font-medium text-ink">
                                    Omschrijving
                                </label>
                                <textarea
                                    id="projectDescription"
                                    name="projectDescription"
                                    wire:model="projectDescription"
                                    maxlength="500"
                                    rows="2"
                                    placeholder="Optionele omschrijving..."
                                    class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                                    data-testid="project-description-input"
                                ></textarea>
                                @error('projectDescription')
                                    <p class="text-body-sm text-danger-fg" role="alert">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                id="projectIsActive"
                                wire:model="projectIsActive"
                                class="h-4 w-4 rounded border-hairline text-brand-green focus:ring-brand-green/20"
                                data-testid="project-is-active-input"
                            />
                            <label for="projectIsActive" class="text-body-sm text-ink">Actief</label>
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <x-ui.button
                                variant="primary"
                                type="submit"
                                data-testid="project-save-btn"
                            >{{ $editingProjectId !== null ? 'Bijwerken' : 'Aanmaken' }}</x-ui.button>

                            <x-ui.button
                                variant="secondary"
                                type="button"
                                wire:click="cancelProjectForm"
                                data-testid="project-cancel-btn"
                            >Annuleren</x-ui.button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Projectenlijst --}}
            <div class="overflow-x-auto">
                <table class="w-full text-left text-body-sm" aria-label="Lijst van projecten">
                    <thead class="border-b border-hairline">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Code</th>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Naam</th>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Uurtarief</th>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Status</th>
                            @if ($isOwner)
                                <th scope="col" class="px-3 py-2 font-medium text-ink">Acties</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->projects as $project)
                            <tr
                                class="border-b border-hairline"
                                data-testid="project-row-{{ $project['id'] }}"
                            >
                                <td class="px-3 py-2 font-mono font-medium text-ink">{{ $project['code'] }}</td>
                                <td class="px-3 py-2 text-ink">{{ $project['name'] }}</td>
                                <td class="px-3 py-2 text-ink">
                                    {{ $project['hourly_rate'] !== null ? '€ ' . $project['hourly_rate'] : '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($project['archived_at'] !== null)
                                        <x-ui.status-badge variant="concept">Gearchiveerd</x-ui.status-badge>
                                    @elseif ($project['is_active'])
                                        <x-ui.status-badge variant="success">Actief</x-ui.status-badge>
                                    @else
                                        <x-ui.status-badge variant="concept">Inactief</x-ui.status-badge>
                                    @endif
                                </td>
                                @if ($isOwner)
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <x-ui.button
                                                variant="secondary"
                                                wire:click="editProject({{ $project['id'] }})"
                                                data-testid="project-edit-{{ $project['id'] }}"
                                                aria-label="Bewerk project {{ $project['code'] }}"
                                            >Bewerken</x-ui.button>

                                            @if ($project['archived_at'] === null)
                                                <x-ui.button
                                                    variant="secondary"
                                                    wire:click="archiveProject({{ $project['id'] }})"
                                                    wire:confirm="Weet je zeker dat je dit project wilt archiveren?"
                                                    data-testid="project-archive-{{ $project['id'] }}"
                                                    aria-label="Archiveer project {{ $project['code'] }}"
                                                >Archiveren</x-ui.button>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isOwner ? 5 : 4 }}" class="px-3 py-4 text-center text-steel">
                                    Geen projecten gevonden.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif

    {{-- ===== TAB: KOSTENPLAATSEN ===== --}}
    @if ($activeTab === 'cost_centers')
        <x-ui.card>
            <x-slot:header>
                <div class="flex flex-col gap-2 tablet:flex-row tablet:items-center tablet:justify-between">
                    <h2 class="text-heading-2 font-semibold text-ink">Kostenplaatsen</h2>
                    @if ($isOwner)
                        <x-ui.button
                            variant="primary"
                            wire:click="openCostCenterForm"
                            data-testid="cost-center-create-btn"
                        >+ Nieuwe kostenplaats</x-ui.button>
                    @endif
                </div>
            </x-slot:header>

            {{-- Zoekfilter --}}
            <div class="mb-4">
                <x-ui.text-input
                    label="Zoeken"
                    name="costCenterSearch"
                    type="search"
                    placeholder="Zoek op code of naam..."
                    wire:model.live.debounce.300ms="costCenterSearch"
                    data-testid="cost-center-search"
                />
            </div>

            {{-- Inline formulier --}}
            @if ($showCostCenterForm)
                <div class="mb-4 rounded-input border border-hairline bg-canvas p-4" data-testid="cost-center-form">
                    <h3 class="mb-3 text-body-sm font-semibold text-ink">
                        {{ $editingCostCenterId !== null ? 'Kostenplaats bewerken' : 'Nieuwe kostenplaats' }}
                    </h3>

                    <form wire:submit="saveCostCenter" class="space-y-3">
                        <div class="grid grid-cols-1 gap-3 tablet:grid-cols-2">
                            <x-ui.text-input
                                label="Code"
                                name="costCenterCode"
                                placeholder="Bijv. KP-001"
                                :required="true"
                                maxlength="40"
                                wire:model="costCenterCode"
                                :error="$errors->first('costCenterCode')"
                                data-testid="cost-center-code-input"
                            />
                            <x-ui.text-input
                                label="Naam"
                                name="costCenterName"
                                placeholder="Naam kostenplaats"
                                :required="true"
                                maxlength="120"
                                wire:model="costCenterName"
                                :error="$errors->first('costCenterName')"
                                data-testid="cost-center-name-input"
                            />
                        </div>

                        <div class="flex flex-col gap-1">
                            <label for="costCenterDescription" class="text-body-sm font-medium text-ink">
                                Omschrijving
                            </label>
                            <textarea
                                id="costCenterDescription"
                                name="costCenterDescription"
                                wire:model="costCenterDescription"
                                maxlength="500"
                                rows="2"
                                placeholder="Optionele omschrijving..."
                                class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                                data-testid="cost-center-description-input"
                            ></textarea>
                            @error('costCenterDescription')
                                <p class="text-body-sm text-danger-fg" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-center gap-2">
                            <input
                                type="checkbox"
                                id="costCenterIsActive"
                                wire:model="costCenterIsActive"
                                class="h-4 w-4 rounded border-hairline text-brand-green focus:ring-brand-green/20"
                                data-testid="cost-center-is-active-input"
                            />
                            <label for="costCenterIsActive" class="text-body-sm text-ink">Actief</label>
                        </div>

                        <div class="flex items-center gap-3 pt-2">
                            <x-ui.button
                                variant="primary"
                                type="submit"
                                data-testid="cost-center-save-btn"
                            >{{ $editingCostCenterId !== null ? 'Bijwerken' : 'Aanmaken' }}</x-ui.button>

                            <x-ui.button
                                variant="secondary"
                                type="button"
                                wire:click="cancelCostCenterForm"
                                data-testid="cost-center-cancel-btn"
                            >Annuleren</x-ui.button>
                        </div>
                    </form>
                </div>
            @endif

            {{-- Kostenplaatsenlijst --}}
            <div class="overflow-x-auto">
                <table class="w-full text-left text-body-sm" aria-label="Lijst van kostenplaatsen">
                    <thead class="border-b border-hairline">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Code</th>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Naam</th>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Status</th>
                            @if ($isOwner)
                                <th scope="col" class="px-3 py-2 font-medium text-ink">Acties</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($this->costCenters as $costCenter)
                            <tr
                                class="border-b border-hairline"
                                data-testid="cost-center-row-{{ $costCenter['id'] }}"
                            >
                                <td class="px-3 py-2 font-mono font-medium text-ink">{{ $costCenter['code'] }}</td>
                                <td class="px-3 py-2 text-ink">{{ $costCenter['name'] }}</td>
                                <td class="px-3 py-2">
                                    @if ($costCenter['archived_at'] !== null)
                                        <x-ui.status-badge variant="concept">Gearchiveerd</x-ui.status-badge>
                                    @elseif ($costCenter['is_active'])
                                        <x-ui.status-badge variant="success">Actief</x-ui.status-badge>
                                    @else
                                        <x-ui.status-badge variant="concept">Inactief</x-ui.status-badge>
                                    @endif
                                </td>
                                @if ($isOwner)
                                    <td class="px-3 py-2">
                                        <div class="flex items-center gap-2">
                                            <x-ui.button
                                                variant="secondary"
                                                wire:click="editCostCenter({{ $costCenter['id'] }})"
                                                data-testid="cost-center-edit-{{ $costCenter['id'] }}"
                                                aria-label="Bewerk kostenplaats {{ $costCenter['code'] }}"
                                            >Bewerken</x-ui.button>

                                            @if ($costCenter['archived_at'] === null)
                                                <x-ui.button
                                                    variant="secondary"
                                                    wire:click="archiveCostCenter({{ $costCenter['id'] }})"
                                                    wire:confirm="Weet je zeker dat je deze kostenplaats wilt archiveren?"
                                                    data-testid="cost-center-archive-{{ $costCenter['id'] }}"
                                                    aria-label="Archiveer kostenplaats {{ $costCenter['code'] }}"
                                                >Archiveren</x-ui.button>
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $isOwner ? 4 : 3 }}" class="px-3 py-4 text-center text-steel">
                                    Geen kostenplaatsen gevonden.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    @endif
</div>
