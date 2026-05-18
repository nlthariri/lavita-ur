{{--
  Livewire-view — `Settings\EmailTemplates` (taak 12.4 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.12 → scherm "E-mailcycli beheer" waarop owner alle
       11 templates kan bekijken en bewerken.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels, NL-foutmeldingen.

  Compositie:
   - Header met paginatitel en organisatie-naam.
   - Lijst van 11 template-types met status-indicatie.
   - Inline-editor (monospace, Geist Mono) bij selectie van een type.
   - Opslaan-knop en terug-knop.

  Design-token-discipline:
   - `<x-ui.card>` voor panelen, `<x-ui.button>` voor actieknoppen.
   - Editor-textarea's gebruiken `font-mono` (Geist Mono).
   - Focus-state via `border 2px #00d4a4` (brand-green).
--}}
<div data-livewire-component="settings.email-templates">
    @php
        $existingTemplates = $this->getExistingTemplates();
        $typeLabels = \App\Livewire\Settings\EmailTemplates::TYPE_LABELS;
        $templateTypes = \App\Livewire\Settings\EmailTemplates::TEMPLATE_TYPES;
    @endphp

    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-2 tablet:flex-row tablet:items-center tablet:justify-between">
                <h1 class="text-heading-2 font-semibold text-ink">
                    E-mailtemplates
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
                data-testid="email-templates-confirmation"
                class="mb-4 rounded-input border border-success-fg/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg"
            >{{ $confirmation }}</p>
        @endif

        {{-- Foutmelding --}}
        @if ($saveError !== null && $saveError !== '')
            <p
                role="alert"
                aria-live="polite"
                data-testid="email-templates-error"
                class="mb-4 rounded-input border border-danger/40 bg-danger-bg px-3 py-2 text-body-sm text-danger-fg"
            >{{ $saveError }}</p>
        @endif

        @if ($selectedType === null)
            {{-- ===== LIJST-MODUS ===== --}}
            <p class="mb-4 text-body-sm text-steel">
                Selecteer een template om het onderwerp en de inhoud te bewerken.
            </p>

            <div class="overflow-x-auto">
                <table
                    class="w-full text-left text-body-sm"
                    aria-label="Lijst van e-mailtemplates"
                >
                    <thead class="border-b border-hairline">
                        <tr>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Type</th>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Onderwerp</th>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Status</th>
                            <th scope="col" class="px-3 py-2 font-medium text-ink">Actie</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($templateTypes as $type)
                            @php
                                $template = $existingTemplates[$type] ?? null;
                                $label = $typeLabels[$type] ?? $type;
                                $subject = $template !== null ? (string) $template->subject_template : '—';
                                $isConfigured = $template !== null;
                            @endphp
                            <tr
                                class="border-b border-hairline"
                                data-testid="email-template-row-{{ $type }}"
                            >
                                <td class="px-3 py-2 font-medium text-ink">{{ $label }}</td>
                                <td class="px-3 py-2 font-mono text-body-sm text-ink">
                                    {{ \Illuminate\Support\Str::limit($subject, 60) }}
                                </td>
                                <td class="px-3 py-2">
                                    @if ($isConfigured)
                                        <x-ui.status-badge variant="success">Geconfigureerd</x-ui.status-badge>
                                    @else
                                        <x-ui.status-badge variant="concept">Standaard</x-ui.status-badge>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <x-ui.button
                                        variant="secondary"
                                        wire:click="selectType('{{ $type }}')"
                                        data-testid="email-template-edit-{{ $type }}"
                                        aria-label="Bewerk template {{ $label }}"
                                    >Bewerken</x-ui.button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            {{-- ===== EDITOR-MODUS ===== --}}
            @php
                $currentLabel = $typeLabels[$selectedType] ?? $selectedType;
            @endphp

            <div class="mb-4 flex items-center gap-4">
                <x-ui.button
                    variant="secondary"
                    wire:click="closeEditor"
                    data-testid="email-templates-back"
                    aria-label="Terug naar de lijst"
                >← Terug</x-ui.button>

                <h2 class="text-lg font-semibold text-ink">
                    {{ $currentLabel }}
                </h2>
            </div>

            <p class="mb-2 text-body-sm text-steel">
                Type: <code class="font-mono text-ink">{{ $selectedType }}</code>
            </p>

            <form wire:submit="saveTemplate" class="space-y-4">
                {{-- Onderwerp --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="email-template-subject"
                        class="text-body-sm font-medium text-ink"
                    >
                        Onderwerp
                    </label>
                    <input
                        id="email-template-subject"
                        type="text"
                        name="subjectTemplate"
                        wire:model="subjectTemplate"
                        maxlength="500"
                        required
                        aria-required="true"
                        aria-describedby="email-template-subject-error"
                        class="block h-10 w-full rounded-input border-2 border-hairline bg-canvas px-3 font-mono text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                        placeholder="Onderwerp van de e-mail"
                        data-testid="email-template-subject-input"
                    />
                    @error('subjectTemplate')
                        <p
                            id="email-template-subject-error"
                            role="alert"
                            class="text-body-sm text-danger-fg"
                        >{{ $message }}</p>
                    @enderror
                </div>

                {{-- Body tekst --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="email-template-body-text"
                        class="text-body-sm font-medium text-ink"
                    >
                        Tekst-body
                    </label>
                    <p id="email-template-body-text-help" class="text-body-sm text-steel">
                        Platte-tekst versie van de e-mail. Gebruik <code class="font-mono">@{{ placeholder }}</code> voor variabelen.
                    </p>
                    <textarea
                        id="email-template-body-text"
                        name="bodyTextTemplate"
                        wire:model="bodyTextTemplate"
                        rows="10"
                        required
                        aria-required="true"
                        aria-describedby="email-template-body-text-help email-template-body-text-error"
                        class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 font-mono text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                        placeholder="Beste @{{ full_name }},&#10;&#10;..."
                        data-testid="email-template-body-text-input"
                    ></textarea>
                    @error('bodyTextTemplate')
                        <p
                            id="email-template-body-text-error"
                            role="alert"
                            class="text-body-sm text-danger-fg"
                        >{{ $message }}</p>
                    @enderror
                </div>

                {{-- Body HTML --}}
                <div class="flex flex-col gap-1">
                    <label
                        for="email-template-body-html"
                        class="text-body-sm font-medium text-ink"
                    >
                        HTML-body
                    </label>
                    <p id="email-template-body-html-help" class="text-body-sm text-steel">
                        HTML-versie van de e-mail. Gebruik <code class="font-mono">@{{ placeholder }}</code> voor variabelen.
                    </p>
                    <textarea
                        id="email-template-body-html"
                        name="bodyHtmlTemplate"
                        wire:model="bodyHtmlTemplate"
                        rows="14"
                        required
                        aria-required="true"
                        aria-describedby="email-template-body-html-help email-template-body-html-error"
                        class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 font-mono text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20"
                        placeholder="<p>Beste @{{ full_name }},</p>&#10;..."
                        data-testid="email-template-body-html-input"
                    ></textarea>
                    @error('bodyHtmlTemplate')
                        <p
                            id="email-template-body-html-error"
                            role="alert"
                            class="text-body-sm text-danger-fg"
                        >{{ $message }}</p>
                    @enderror
                </div>

                {{-- Opslaan-knop --}}
                <div class="flex items-center gap-4">
                    <x-ui.button
                        variant="primary"
                        type="submit"
                        data-testid="email-template-save"
                    >Opslaan</x-ui.button>

                    <x-ui.button
                        variant="secondary"
                        type="button"
                        wire:click="closeEditor"
                    >Annuleren</x-ui.button>
                </div>
            </form>
        @endif
    </x-ui.card>
</div>
