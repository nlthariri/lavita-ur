<?php

declare(strict_types=1);

namespace App\Livewire\Objections;

use App\Livewire\Hours\EntryFormModal;
use App\Livewire\Hours\MyWeek;
use App\Models\User;
use App\Services\ObjectionsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Livewire-component — `Objections\NewObjectionForm` (taak 10.3 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.4  → bezwaarknop per regel die deze modal opent.
 *  - requirements.md 4.x  → bezwaar alleen op finalized regels, motivatie
 *      ≥10 / ≤2000 tekens, één open bezwaar per regel.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
 *  - requirements.md 6.14 / NFR-10 → NL-labels en NL-foutmeldingen.
 *  - design.md § Bezwaarprocedure → submit roept
 *      {@see ObjectionsService::submit()} aan met
 *      `['work_entry_id', 'motivation']` + submitter id.
 *  - tasks.md 10.3.
 *
 * Verantwoordelijkheid:
 *  - Modal-component dat door {@see MyWeek} wordt
 *    ingebed via `<livewire:objections.new-objection-form />`. Luistert
 *    op het Livewire-event `open-new-objection` met payload
 *    `{ workEntryId: int }` en opent zichzelf met een leeg formulier.
 *  - Submit roept `ObjectionsService::submit` aan en mapt
 *    {@see ValidationException}-fouten naar de `motivation`-velderror
 *    zodat de gebruiker de NL-melding inline ziet.
 *  - Op succes: dispatched `objection-submitted` event (zodat de parent-
 *    week-tabel kan refreshen) en sluit het modal — de bevestiging
 *    `confirmation` wordt vóór sluiten kort gerendered en daarna door
 *    {@see closeModal()} weer geleegd.
 *
 * `#[Layout]` + `#[Title]` blijven gezet voor full-page-rendering tijdens
 * tests die het component direct via een route benaderen (bv. integratie-
 * tests). In productie wordt het component alleen ingebed in `MyWeek`.
 *
 * Bewust niet:
 *  - Geen objection-review-flow — dat is taak 11.2 (`Objections\ReviewForm`).
 *  - Geen mail-dispatch — die zit in `ObjectionsService::submit`.
 *  - Geen lijst-view van eigen bezwaren — buiten scope van 10.3.
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt op `<x-ui.button>` en `<x-ui.card>`.
 *  - Bewuste deviation: native `<textarea>` voor de motivatie. Het
 *    bestaande `<x-ui.text-input>`-atom rendert een `<input>` en heeft
 *    geen textarea-mode. We mirroren de input-token-styling
 *    (border-2 + radius-input + brand-green focus) — zelfde deviation als
 *    in taak 10.2 ({@see EntryFormModal}).
 */
#[Layout('layouts.app')]
#[Title('Bezwaar indienen — LaVita Urenregistratie')]
final class NewObjectionForm extends Component
{
    /**
     * Modal-zichtbaarheid. Wanneer `false` rendert de view een lege
     * wrapper zodat het component op pagina aanwezig is en kan luisteren
     * naar het open-event, zonder backdrop of formulier in de DOM.
     */
    public bool $isOpen = false;

    /**
     * Werkregel-id waarop het bezwaar wordt ingediend. Wordt door
     * {@see openModal()} gevuld via het `open-new-objection`-event vanuit
     * `Hours\MyWeek`.
     */
    public ?int $workEntryId = null;

    /**
     * Motivatie van het bezwaar.
     *
     * Validatie:
     *  - `required` — verplicht.
     *  - `string`   — tekstwaarde.
     *  - `min:10`   — minimaal 10 tekens (req 4.x / 6.6-spiegel).
     *  - `max:2000` — maximaal 2000 tekens (matched
     *    `ObjectionsService::submit` die op 2000 tekens trimt).
     *
     * NL-foutmeldingen via het `#[Validate]`-attribuut zodat NFR-10 op
     * elke validatie-foutmelding gerespecteerd wordt.
     */
    #[Validate(
        rule: 'required|string|min:10|max:2000',
        message: [
            'motivation.required' => 'Motivatie is verplicht.',
            'motivation.string' => 'Motivatie moet een tekstwaarde zijn.',
            'motivation.min' => 'Motivatie moet minimaal 10 tekens bevatten.',
            'motivation.max' => 'Motivatie mag maximaal 2000 tekens bevatten.',
        ],
        attribute: ['motivation' => 'motivatie'],
        translate: false,
    )]
    public string $motivation = '';

    /**
     * Bevestigingstekst die kort wordt getoond na een succesvolle submit.
     * `null` betekent "geen bevestiging tonen".
     */
    public ?string $confirmation = null;

    /**
     * Listener voor het `open-new-objection`-event dat door
     * {@see MyWeek} wordt gedispatcht via een
     * `wire:click="$dispatch('open-new-objection', { workEntryId: …})"`-
     * binding op de bezwaarknop.
     *
     * Resetten van de invoer-state vóór het zetten van `isOpen` zodat een
     * tweede openslag van de modal geen residu uit een vorige sessie toont.
     */
    #[On('open-new-objection')]
    public function openModal(int $workEntryId): void
    {
        $this->workEntryId = $workEntryId;
        $this->motivation = '';
        $this->confirmation = null;
        $this->resetErrorBag();
        $this->isOpen = true;
    }

    /**
     * Sluit de modal en reset de invoer-state. Wordt aangeroepen door:
     *  - de "Annuleren"-knop in de view,
     *  - de backdrop-click,
     *  - na een succesvolle submit (zodat de modal weer leeg in beeld komt).
     */
    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->workEntryId = null;
        $this->motivation = '';
        $this->confirmation = null;
        $this->resetErrorBag();
    }

    /**
     * Submit het bezwaar.
     */
    public function submit(ObjectionsService $service): mixed
    {
        $this->validate();

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        try {
            $service->submit(
                [
                    'work_entry_id' => $this->workEntryId,
                    'motivation' => $this->motivation,
                ],
                (int) $user->id,
            );
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            $this->addError(
                'motivation',
                is_string($first) && $first !== ''
                    ? $first
                    : 'Bezwaar kon niet worden ingediend.'
            );

            return null;
        }

        // Succesvol — dispatch event zodat parent-componenten kunnen
        // herladen. Gebruik een browser-event (toast/flash) zodat de
        // bevestiging zichtbaar blijft nadat de modal sluit.
        $this->dispatch('objection-submitted', workEntryId: $this->workEntryId);

        // Sluit modal en reset state
        $this->isOpen = false;
        $this->workEntryId = null;
        $this->motivation = '';
        $this->resetErrorBag();

        // Flash-bericht via session zodat de parent-pagina het kan tonen
        session()->flash('success', 'Bezwaar ingediend. Manager ontvangt een melding.');

        return null;
    }

    public function render(): View
    {
        return view('livewire.objections.new-objection-form');
    }
}
