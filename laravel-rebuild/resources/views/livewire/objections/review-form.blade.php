{{--
  Livewire-view — `Objections\ReviewForm` (taak 11.2 spec lavita-urenregistratie).

  Bron:
   - requirements.md 6.6  → scherm "Bezwaar beoordelen": owner/manager
       accepteert/wijst af met motivatie min 10, max 1000 tekens; submit
       gedeactiveerd zolang motivatie korter is dan 10 tekens.
   - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
   - requirements.md 6.14 → NL-labels en NL-bevestigingen.

  Compositie:
   - Buitenste container `<x-ui.card>` met `<x-slot:header>` voor titel
     "Bezwaar beoordelen" + subtitel met bezwaar-id, medewerker en datum.
   - Statusbadge bovenaan via `<x-ui.status-badge>` met variant op basis
     van OPEN / APPROVED / REJECTED.
   - Read-only "Oorspronkelijke werkregel"-blok als `<dl>` met de
     vier termen Begintijd / Eindtijd / Pauze / Netto.
   - Submitter-motivatie als `<blockquote>`.
   - Wanneer status ≠ OPEN: extra `role="status"`-block dat aankondigt
     dat het bezwaar al beoordeeld is + alle form-velden gedisabled.
   - Form met `<form wire:submit.prevent="reject">` (default action,
     maar de twee actie-knoppen sturen via `wire:click` naar respectievelijk
     `reject` of `accept` zodat de gebruiker uit twee uitkomsten kiest).
   - Manager-response (=motivatie) textarea met live char-counter.
   - Correctie-velden voor bij accept: gecorrigeerde begintijd, eindtijd,
     pauze minuten.
   - Acties-rij: "Afwijzen" (secondary) + "Accepteren" (primary).
   - Bevestigingsbanner na succesvolle accept/reject.

  Toegankelijkheid (WCAG 2.1 AA):
   - `<form>` heeft `aria-labelledby` naar de heading.
   - `<label for>`-koppeling op alle invoervelden.
   - Inline foutmeldingen met `role="alert" aria-live="polite"`.
   - Char-counter heeft `aria-live="polite"` zodat screenreaders updates
     volgen.
   - Statusbadge heeft `data-status` zodat tests een stabiele selector
     hebben zonder op kleur te steunen.

  Design-token-discipline (NFR-4):
   - `<x-ui.card>` voor het paneel, `<x-ui.button>` voor de actie-knoppen,
     `<x-ui.text-input>` voor de tijd- en pauze-correctie-velden,
     `<x-ui.status-badge>` voor de statusbadge.
   - Bewuste deviation: native `<textarea>` voor de manager_response —
     `<x-ui.text-input>` levert geen textarea-mode (zelfde deviation als
     bij taak 10.2 en 10.3). We mirroren de input-token-styling.
--}}
@php
    /** @var \Illuminate\Support\ViewErrorBag $errors */
    $managerResponseError = $errors->first('managerResponse');
    $correctedStartError = $errors->first('correctedStartTime');
    $correctedEndError = $errors->first('correctedEndTime');
    $correctedPauseError = $errors->first('correctedPauseMinutes');

    $managerResponseLength = mb_strlen($managerResponse);
    $isAlreadyReviewed = $status !== 'OPEN' && $status !== null;
    $rejectDisabled = $isAlreadyReviewed || mb_strlen(trim($managerResponse)) < 10;
    $acceptDisabled = $isAlreadyReviewed;
    $statusLabel = $this->getStatusLabel();
    $statusVariant = $this->getStatusBadgeVariant();

    // Helper voor de netto-display: hu mmin.
    $netHours = $originalNetMinutes !== null ? intdiv((int) $originalNetMinutes, 60) : null;
    $netRemainder = $originalNetMinutes !== null ? ((int) $originalNetMinutes) % 60 : null;
@endphp

<div class="flex flex-col gap-4" data-livewire-component="objections.review-form">
    <x-ui.card>
        <x-slot:header>
            <div class="flex flex-col gap-2 tablet:flex-row tablet:items-center tablet:justify-between">
                <div class="flex flex-col gap-1">
                    <h1 class="text-heading-2 font-semibold text-ink">
                        Bezwaar beoordelen
                    </h1>
                    <p class="text-body-sm text-steel">
                        Bezwaar #{{ $objectionId }}
                        @if (! empty($employeeName))
                            — {{ $employeeName }}
                        @endif
                        @if (! empty($entryDate))
                            ({{ $entryDate }})
                        @endif
                    </p>
                </div>
                <div class="flex items-start tablet:items-center">
                    <x-ui.status-badge
                        :variant="$statusVariant"
                        data-status="{{ $status }}"
                    >
                        Status: {{ $statusLabel }}
                    </x-ui.status-badge>
                </div>
            </div>
        </x-slot:header>

        {{-- Read-only blok: oorspronkelijke werkregel-snapshot. --}}
        <section
            aria-labelledby="review-original-heading"
            class="flex flex-col gap-3"
        >
            <h2 id="review-original-heading" class="text-button-md font-medium text-ink">
                Oorspronkelijke werkregel
            </h2>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 tablet:grid-cols-4">
                <div class="flex flex-col">
                    <dt class="text-body-sm text-steel">Begintijd</dt>
                    <dd class="font-mono text-body-md text-ink" data-testid="review-original-start">
                        {{ $originalStartTime ?? '—' }}
                    </dd>
                </div>
                <div class="flex flex-col">
                    <dt class="text-body-sm text-steel">Eindtijd</dt>
                    <dd class="font-mono text-body-md text-ink" data-testid="review-original-end">
                        {{ $originalEndTime ?? '—' }}
                    </dd>
                </div>
                <div class="flex flex-col">
                    <dt class="text-body-sm text-steel">Pauze</dt>
                    <dd class="font-mono text-body-md text-ink" data-testid="review-original-pause">
                        @if ($originalPauseMinutes !== null)
                            {{ $originalPauseMinutes }} min
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div class="flex flex-col">
                    <dt class="text-body-sm text-steel">Netto</dt>
                    <dd class="font-mono text-body-md text-ink" data-testid="review-original-net">
                        @if ($originalNetMinutes !== null)
                            @if ($netHours > 0 && $netRemainder > 0)
                                {{ $netHours }}u {{ $netRemainder }}min
                            @elseif ($netHours > 0)
                                {{ $netHours }}u
                            @else
                                {{ $netRemainder }}min
                            @endif
                        @else
                            —
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        {{-- Submitter-motivatie als blockquote. --}}
        <section class="mt-6 flex flex-col gap-2" aria-labelledby="review-submitter-heading">
            <h2 id="review-submitter-heading" class="text-button-md font-medium text-ink">
                Motivatie van medewerker
            </h2>
            <blockquote
                class="rounded-input border-l-4 border-hairline bg-surface px-4 py-3 text-body-md text-ink"
                data-testid="review-submitter-motivation"
            >
                @if (! empty($submitterMotivation))
                    {{ $submitterMotivation }}
                @else
                    <span class="italic text-steel">Geen motivatie opgegeven.</span>
                @endif
            </blockquote>
        </section>

        {{-- Reeds-beoordeeld-block. --}}
        @if ($isAlreadyReviewed)
            <div
                role="status"
                aria-live="polite"
                class="mt-6 rounded-input border border-warning/40 bg-warning-bg px-3 py-2 text-body-sm text-warning-fg"
                data-testid="review-already-decided"
            >
                Dit bezwaar is al beoordeeld (status: {{ $statusLabel }}).
            </div>
        @endif

        {{-- Bevestigingsbanner na succesvolle accept/reject. --}}
        @if ($confirmation !== null && $confirmation !== '')
            <div
                role="status"
                aria-live="polite"
                class="mt-6 rounded-input border border-success/40 bg-success-bg px-3 py-2 text-body-sm text-success-fg"
                data-testid="review-confirmation"
            >
                {{ $confirmation }}
            </div>
        @endif

        {{-- Form: textarea + correctie-velden + actieknoppen. --}}
        <form
            wire:submit.prevent="reject"
            method="POST"
            action="#"
            novalidate
            aria-labelledby="review-form-heading"
            class="mt-6 flex flex-col gap-4"
        >
            <h2 id="review-form-heading" class="sr-only">Beoordeling invoeren</h2>

            {{-- Manager-response textarea (=motivatie). --}}
            <div class="flex flex-col gap-1">
                <label
                    for="objection-manager-response"
                    class="text-body-sm font-medium text-ink"
                >
                    Beoordeling motivatie
                    <span class="text-danger" aria-hidden="true">*</span>
                    <span class="sr-only">(verplicht bij afwijzen)</span>
                </label>
                <textarea
                    id="objection-manager-response"
                    name="managerResponse"
                    rows="6"
                    maxlength="1000"
                    @disabled($isAlreadyReviewed)
                    wire:model.live.debounce.150ms="managerResponse"
                    @if ($managerResponseError) aria-invalid="true" aria-describedby="objection-manager-response-error objection-manager-response-help objection-manager-response-counter" @else aria-describedby="objection-manager-response-help objection-manager-response-counter" @endif
                    class="block w-full rounded-input border-2 border-hairline bg-canvas px-3 py-2 text-body-sm text-ink placeholder:text-steel focus:border-brand-green focus:outline-none focus:ring-2 focus:ring-brand-green/20 disabled:bg-surface disabled:text-steel disabled:cursor-not-allowed"
                >{{ $managerResponse }}</textarea>

                @if ($managerResponseError)
                    <p
                        id="objection-manager-response-error"
                        class="text-body-sm text-danger"
                        role="alert"
                        aria-live="polite"
                    >{{ $managerResponseError }}</p>
                @endif

                <p
                    id="objection-manager-response-help"
                    class="text-body-sm text-steel"
                >
                    Bij afwijzing is een motivatie verplicht (minimaal 10 tekens). Bij accepteren is de motivatie optioneel — geef wel correcties op tijden hieronder.
                </p>

                <p
                    id="objection-manager-response-counter"
                    class="text-body-sm text-steel"
                    aria-live="polite"
                    data-testid="manager-response-counter"
                >
                    {{ $managerResponseLength }} / 1000 tekens
                </p>
            </div>

            {{-- Correctie-velden voor bij accept. --}}
            <fieldset class="flex flex-col gap-3" @disabled($isAlreadyReviewed)>
                <legend class="text-button-md font-medium text-ink">
                    Correcties (bij accepteren)
                </legend>

                <div class="grid grid-cols-1 gap-4 tablet:grid-cols-3">
                    <x-ui.text-input
                        name="correctedStartTime"
                        type="time"
                        label="Gecorrigeerde begintijd"
                        wire:model.live="correctedStartTime"
                        :value="$correctedStartTime"
                        :error="$correctedStartError"
                        :disabled="$isAlreadyReviewed"
                    />
                    <x-ui.text-input
                        name="correctedEndTime"
                        type="time"
                        label="Gecorrigeerde eindtijd"
                        wire:model.live="correctedEndTime"
                        :value="$correctedEndTime"
                        :error="$correctedEndError"
                        :disabled="$isAlreadyReviewed"
                    />
                    <x-ui.text-input
                        name="correctedPauseMinutes"
                        type="number"
                        label="Gecorrigeerde pauze (min)"
                        min="0"
                        max="480"
                        step="5"
                        wire:model.live="correctedPauseMinutes"
                        :value="$correctedPauseMinutes"
                        :error="$correctedPauseError"
                        :disabled="$isAlreadyReviewed"
                    />
                </div>
            </fieldset>

            {{-- Acties: Afwijzen (secondary) + Accepteren (primary). --}}
            <div class="mt-2 flex flex-col-reverse gap-3 tablet:flex-row tablet:justify-end">
                <x-ui.button
                    type="button"
                    variant="secondary"
                    wire:click="reject"
                    :disabled="$rejectDisabled"
                    data-testid="review-reject-button"
                >
                    Afwijzen
                </x-ui.button>

                <x-ui.button
                    type="button"
                    variant="primary"
                    wire:click="accept"
                    :disabled="$acceptDisabled"
                    data-testid="review-accept-button"
                >
                    Accepteren
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>
</div>
