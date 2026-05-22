<?php

declare(strict_types=1);

namespace App\Livewire\Hours;

use App\Models\User;
use App\Models\WorkEntry;
use App\Services\AtwService;
use App\Services\CostCentersService;
use App\Services\HolidaysService;
use App\Services\ProjectsService;
use App\Services\WorkEntriesService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Livewire-component — `Hours\EntryFormModal` (taak 10.2 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.3  → scherm "Invoermodal" met live netto-minuten-
 *      berekening, ATW-pre-validatie vóór opslaan, project- én
 *      kostenplaats-selector. Live-warnings/critical-melding op basis
 *      van ATW-validatie.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
 *      `design.md`.
 *  - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Invoermodal (live netto + ATW)" → component
 *      `Hours\EntryFormModal` als modal binnen `Hours\WeekOverviewTable`.
 *
 * Verantwoordelijkheid:
 *  - Werkregel-invoer-formulier (datum, type, start/eind/pauze, project,
 *    kostenplaats, notitie) met live netto-minuten-berekening en live
 *    ATW-pre-validatie. Wordt geactiveerd door event
 *    `open-entry-form-modal` (gedispatcht door taak 10.1 wanneer een
 *    cel in het weekoverzicht wordt aangeklikt) en sluit op event
 *    `entry-saved` zodat de week-tabel kan refreshen.
 *  - Submit roept {@see WorkEntriesService::create()} aan. Kritieke
 *    ATW-signalen (`has_critical = true`) blokkeren de save vóór de
 *    service-call; hetzelfde beleid als de backend afdwingt (zie
 *    `WorkEntriesService::create` + `AtwService::throwOnCriticalSignals`).
 *
 * Spec-deviation — service-call vs HTTP roundtrip naar
 * `POST /api/internal/work-entries/validate-atw`:
 *  De spec-tekst van taak 10.2 noemt expliciet de HTTP-endpoint
 *  `/api/internal/work-entries/validate-atw`. In een Livewire-3
 *  serverside-render-context zou een HTTP-roundtrip naar onszelf
 *  betekenen: extra TLS-hop, herhaalde bearer-token-auth, en een
 *  request-cycle die we al binnen handen hebben. Daarom roepen we de
 *  onderliggende service direct aan via
 *  {@see AtwService::validateProposedShift()} — dezelfde codepath die
 *  de HTTP-controller intern dispatcht. De response-shape is identiek
 *  (`{employee_id, entry_date, net_minutes, signals, has_critical}`),
 *  dus de view en de event-bus zien geen verschil. Indien er later
 *  toch een literal HTTP-roundtrip nodig is (bv. cross-origin Livewire-
 *  setup), kan een aparte adapter worden toegevoegd zonder de view of
 *  het event-contract aan te passen.
 *
 * Bewust niet:
 *  - Geen project-/kostenplaats-CRUD (die zit in `Accounts`-/`Settings`-
 *    schermen, taken 12.x).
 *  - Geen week-overzicht-rendering (taak 10.1).
 *  - Geen verlof-/ziekte-flow met date-range-picker — die hoort bij
 *    `Hours\LeaveForm` (taak 10.4) en heeft een eigen workflow.
 *  - Geen route-registratie — de modal leeft binnen het week-overzicht-
 *    scherm en wordt via een Livewire-event geopend.
 *  - Geen offline-/optimistic-update-state. Submit is synchroon; bij
 *    fouten blijft de modal open zodat de gebruiker kan corrigeren.
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt op `<x-ui.button>`, `<x-ui.card>`, `<x-ui.text-input>`.
 *  - Bewuste deviations naar native HTML-elementen waar de UI-atom-set
 *    het type niet ondersteunt:
 *      - `<select>` voor type/project/kostenplaats — `<x-ui.text-input>`
 *        ondersteunt geen `type=select`. We voorzien expliciete
 *        `<label for>`-koppeling, focus-token-styling en NL-labels.
 *      - `<textarea>` voor de notitie — `<x-ui.text-input>` rendert een
 *        `<input>` en heeft geen textarea-mode. We mirroren de
 *        token-styling (border-2 + radius-input + brand-green focus).
 */
#[Layout('layouts.app')]
#[Title('Uurregel — LaVita Urenregistratie')]
final class EntryFormModal extends Component
{
    /**
     * Modal-zichtbaarheid. Wanneer `false` rendert de view een lege
     * wrapper zodat het component op pagina aanwezig is en kan luisteren
     * naar het open-event, zonder backdrop of formulier in de DOM.
     */
    public bool $isOpen = false;

    /**
     * ID van de bestaande werkregel bij bewerken. `null` = aanmaakmodus.
     * Wordt gevuld door {@see openModal()} wanneer het event een `entryId`
     * bevat (klik op een gevulde cel in het weekoverzicht).
     */
    public ?int $entryId = null;

    /**
     * Medewerker-id van wie de werkregel is. Wordt gevuld door
     * {@see openModal()} via het `open-entry-form-modal`-event vanuit
     * `Hours\WeekOverviewTable`. Validatie: zie `#[Validate]`-attribuut.
     */
    #[Validate(
        rule: 'required|integer',
        message: [
            'employeeId.required' => 'Medewerker is verplicht.',
            'employeeId.integer' => 'Medewerker-id is ongeldig.',
        ],
        attribute: ['employeeId' => 'medewerker'],
        translate: false,
    )]
    public ?int $employeeId = null;

    /**
     * Datum van de werkregel in `Y-m-d`. Default leeg zodat de view de
     * date-input zonder default-waarde rendert tot het open-event hem
     * vult.
     */
    #[Validate(
        rule: 'required|date_format:Y-m-d',
        message: [
            'entryDate.required' => 'Datum is verplicht.',
            'entryDate.date_format' => 'Datum moet in het formaat JJJJ-MM-DD staan.',
        ],
        attribute: ['entryDate' => 'datum'],
        translate: false,
    )]
    public string $entryDate = '';

    /**
     * Begintijd in `H:i` (24-uurs notatie, Europe/Amsterdam).
     */
    #[Validate(
        rule: 'required|date_format:H:i',
        message: [
            'startTime.required' => 'Begintijd is verplicht.',
            'startTime.date_format' => 'Begintijd moet in het formaat UU:MM staan.',
        ],
        attribute: ['startTime' => 'begintijd'],
        translate: false,
    )]
    public string $startTime = '08:00';

    /**
     * Eindtijd in `H:i`.
     */
    #[Validate(
        rule: 'required|date_format:H:i',
        message: [
            'endTime.required' => 'Eindtijd is verplicht.',
            'endTime.date_format' => 'Eindtijd moet in het formaat UU:MM staan.',
        ],
        attribute: ['endTime' => 'eindtijd'],
        translate: false,
    )]
    public string $endTime = '17:00';

    /**
     * Pauze in minuten. Wettelijk relevant: bij bruto >5,5u (=330 min)
     * is ≥30 min pauze verplicht (Req 4.1). Hier alleen het bereik
     * 0..480 valideren; ATW-checks gebeuren via de service.
     */
    #[Validate(
        rule: 'required|integer|min:0|max:480',
        message: [
            'pauseMinutes.required' => 'Pauze is verplicht.',
            'pauseMinutes.integer' => 'Pauze moet een geheel aantal minuten zijn.',
            'pauseMinutes.min' => 'Pauze kan niet negatief zijn.',
            'pauseMinutes.max' => 'Pauze mag maximaal 480 minuten zijn.',
        ],
        attribute: ['pauseMinutes' => 'pauze'],
        translate: false,
    )]
    public int $pauseMinutes = 30;

    /**
     * Type werkregel. WORK = standaard werkdienst; SICK/LEAVE/HOLIDAY/OTHER
     * worden ondersteund door de backend (Req 7). HOLIDAY is alleen
     * beschikbaar voor manager/owner — view-side filter via
     * {@see getAvailableTypes()} + Auth::user()->role in de Blade.
     */
    #[Validate(
        rule: 'required|in:WORK,SICK,LEAVE,HOLIDAY,OTHER',
        message: [
            'type.required' => 'Type is verplicht.',
            'type.in' => 'Type moet WORK, SICK, LEAVE, HOLIDAY of OTHER zijn.',
        ],
        attribute: ['type' => 'type'],
        translate: false,
    )]
    public string $type = 'WORK';

    /**
     * Optionele FK naar `projects.id`. Wordt door de backend gevalideerd
     * op `PROJECT_ORG_MISMATCH` en `PROJECT_INACTIVE` (Req 2.9, 2.10).
     */
    #[Validate(
        rule: 'nullable|integer',
        message: [
            'projectId.integer' => 'Project-id is ongeldig.',
        ],
        attribute: ['projectId' => 'project'],
        translate: false,
    )]
    public ?int $projectId = null;

    /**
     * Optionele FK naar `cost_centers.id`. Backend-validatie op
     * `COST_CENTER_ORG_MISMATCH` en `COST_CENTER_INACTIVE`.
     */
    #[Validate(
        rule: 'nullable|integer',
        message: [
            'costCenterId.integer' => 'Kostenplaats-id is ongeldig.',
        ],
        attribute: ['costCenterId' => 'kostenplaats'],
        translate: false,
    )]
    public ?int $costCenterId = null;

    /**
     * Vrije notitie (max 1000 tekens; backend-zijde wordt op 500
     * getrimd via `WorkEntriesService::create`, maar we accepteren tot
     * 1000 zodat de gebruiker niet onverwacht halverwege wordt afgekapt
     * — backend-truncatie blijft idempotent).
     */
    #[Validate(
        rule: 'nullable|string|max:1000',
        message: [
            'note.string' => 'Notitie moet een tekstwaarde zijn.',
            'note.max' => 'Notitie mag maximaal 1000 tekens lang zijn.',
        ],
        attribute: ['note' => 'notitie'],
        translate: false,
    )]
    public string $note = '';

    /**
     * Laatste resultaat van {@see AtwService::validateProposedShift()}.
     *
     * Shape exact zoals de service hem retourneert (consistent met
     * `POST /api/internal/work-entries/validate-atw`):
     * `{employee_id, entry_date, net_minutes, signals, has_critical}`.
     *
     * `null` betekent "nog geen validatie uitgevoerd" — de view rendert
     * dan geen ATW-alert-block. Wordt door {@see validateAtwLive()}
     * gevuld na elke `wire:model.live`-update op de relevante velden.
     *
     * @var array{
     *     employee_id: int,
     *     entry_date: string,
     *     net_minutes: int,
     *     signals: array<int, array<string, mixed>>,
     *     has_critical: bool
     * }|null
     */
    public ?array $atwResult = null;

    /**
     * Submit-flag — true tussen `submit()`-start en -eind, zodat de
     * view een "bezig met opslaan…"-hint kan tonen en de submit-knop
     * tijdens de roundtrip kan disablen.
     */
    public bool $isSubmitting = false;

    /**
     * Slimme defaults: placeholder-tekst op basis van de vorige werkdag.
     * Formaat: "Vorige dag: HH:MM - HH:MM" of null als er geen vorige
     * werkdag-entry is gevonden.
     *
     * Requirements: 6.2
     */
    public ?string $previousDayPlaceholder = null;

    /**
     * Default-waarden voor alle invoer-velden, zodat we ze in
     * {@see openModal()} en {@see closeModal()} consistent kunnen
     * herstellen. Per kolom is dit identiek aan de class-property-default.
     *
     * @var array<string, mixed>
     */
    private const FIELD_DEFAULTS = [
        'entryId' => null,
        'startTime' => '08:00',
        'endTime' => '17:00',
        'pauseMinutes' => 30,
        'type' => 'WORK',
        'projectId' => null,
        'costCenterId' => null,
        'note' => '',
        'atwResult' => null,
        'previousDayPlaceholder' => null,
    ];

    /**
     * Listener voor het `open-entry-form-modal`-event. Wordt gedispatcht
     * door taak 10.1 (week-overzicht) of door tests.
     *
     * Resetten van de invoer-state vóór het zetten van `isOpen` zodat
     * een tweede openslag van de modal geen residu uit een vorige sessie
     * toont.
     */
    #[On('open-entry-form-modal')]
    public function openModal(int $employeeId, string $entryDate, ?int $entryId = null): void
    {
        $this->resetFieldsToDefaults();
        $this->resetErrorBag();

        $this->employeeId = $employeeId;
        $this->entryDate = $entryDate;
        $this->entryId = $entryId;
        $this->isOpen = true;

        // Edit-modus: laad bestaande entry-data in de formuliervelden.
        if ($entryId !== null) {
            $this->loadEntryData($entryId);
        } else {
            // Slimme defaults: zoek de vorige werkdag-entry voor deze medewerker.
            $this->previousDayPlaceholder = $this->loadPreviousDayPlaceholder($employeeId, $entryDate);
        }
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
        $this->employeeId = null;
        $this->entryId = null;
        $this->entryDate = '';
        $this->resetFieldsToDefaults();
        $this->resetErrorBag();
    }

    /**
     * Geeft aan of de modal in bewerkingsmodus staat (bestaande entry).
     */
    public function getIsEditModeProperty(): bool
    {
        return $this->entryId !== null;
    }

    /**
     * Bereken de netto werktijd in minuten op basis van de huidige
     * `start`/`end`/`pauze`-input.
     *
     *   netto = max(0, (eindtijd - begintijd) - pauze)
     *
     * Negatieve bruto (eindtijd ≤ begintijd, bv. typo) levert 0; dit
     * volgt het non-negativiteits-invariant van de backend (zie
     * `WorkEntriesService` — `net_minutes = max(0, gross - pause)`).
     *
     * Wordt door de view aangeroepen als `$this->getNetMinutes()` zodat
     * de live netto-minuten-cel mee-update bij elke `wire:model.live`-
     * propagatie van start/end/pauze.
     *
     * Requirements: 6.3, 1.4 (consistentie met backend-formule)
     */
    public function getNetMinutes(): int
    {
        $start = self::timeToMinutes($this->startTime);
        $end = self::timeToMinutes($this->endTime);
        $gross = $end - $start;

        return max(0, $gross - max(0, $this->pauseMinutes));
    }

    /**
     * Roep ATW-validatie aan en plaats het resultaat in `$atwResult`.
     *
     * Wordt aangeroepen vanuit {@see updated()} bij elke wijziging van
     * een ATW-relevante input (datum, start, end, pauze, type,
     * employeeId). Bij ontbrekende kernvelden zetten we `$atwResult`
     * op `null` zodat de view de waarschuwing-/foutblokken niet rendert
     * voor incomplete invoer.
     *
     * Foutpaden:
     *  - {@see HttpException} (403 vanuit `assertCanAccessEmployee`):
     *    map naar veld-error op `employeeId`.
     *  - {@see Throwable} (model-not-found, format-fout, etc.): toon
     *    een NL-melding op het virtuele `atw`-veld zodat de gebruiker
     *    weet dat de ATW-pre-validatie niet kon worden uitgevoerd.
     */
    public function validateAtwLive(AtwService $atwService): void
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            // Defensief: zonder sessie geen ATW-validatie. De backend
            // zou dit ook weigeren; we voorkomen alleen dat de service
            // op een null-id wordt aangeroepen.
            abort(403, 'Geen toegang.');
        }

        // Onvolledige invoer — niet valideren, alert-block niet renderen.
        if ($this->employeeId === null
            || $this->entryDate === ''
            || $this->startTime === ''
            || $this->endTime === ''
        ) {
            $this->atwResult = null;

            return;
        }

        try {
            $this->atwResult = $atwService->validateProposedShift([
                'employee_id' => $this->employeeId,
                'entry_date' => $this->entryDate,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'pause_minutes' => max(0, $this->pauseMinutes),
            ], (int) $actor->id);
        } catch (ValidationException $e) {
            // Eg. "Medewerker behoort niet tot uw organisatie." — zet op
            // `employeeId`-veld zodat de view de melding inline laat
            // zien onder de medewerker-context.
            $first = collect($e->errors())->flatten()->first()
                ?? 'Kon ATW-validatie niet uitvoeren.';
            $this->atwResult = null;
            $this->addError('employeeId', (string) $first);
        } catch (HttpException $e) {
            $this->atwResult = null;
            $this->addError('employeeId', $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Geen toegang tot deze medewerker.');
        } catch (Throwable $e) {
            $this->atwResult = null;
            $this->addError('atw', 'Kon ATW-validatie niet uitvoeren. Probeer opnieuw.');
        }
    }

    /**
     * Livewire-3 lifecycle: roep ATW-validatie opnieuw aan zodra een
     * relevante property wijzigt. Livewire roept `updated()` aan na
     * elke property-write die door `wire:model.live`/`.blur` getriggerd
     * wordt.
     *
     * We gebruiken `app()` om de service uit de container te halen; de
     * lifecycle-hook krijgt geen automatische DI-injectie (dat doet
     * Livewire alleen op `mount`, `render` en specifieke action-methodes).
     */
    public function updated(string $name): void
    {
        $atwRelevantFields = [
            'entryDate',
            'startTime',
            'endTime',
            'pauseMinutes',
            'type',
            'employeeId',
        ];

        if (! in_array($name, $atwRelevantFields, true)) {
            return;
        }

        if (! $this->isOpen) {
            // Modal is dicht; geen behoefte aan ATW-roundtrip op
            // verborgen velden.
            return;
        }

        $this->validateAtwLive(app(AtwService::class));
    }

    /**
     * Submit-handler.
     *
     *  1. `isSubmitting = true` zodat de view de "bezig met opslaan…"-
     *     state kan tonen en dubbele submits worden geblokkeerd.
     *  2. Run de Livewire-validatie (decoreert de invoer-velden).
     *  3. Run ATW-pre-validatie nogmaals zodat we met een verse
     *     `$atwResult` werken (de gebruiker kan na de laatste live-
     *     update de submit-knop hebben ingedrukt zonder verdere edit).
     *  4. Bij `has_critical` blokkeren we de save met een `atw`-error
     *     en NL-melding. We doen NIET de service-create-call: dat zou
     *     de backend toch met 422 afwijzen, en we willen geen onnodige
     *     transactie-attempt.
     *  5. Anders: roep `WorkEntriesService::create()` aan. Catches
     *     ValidationException (project/cost-center mismatch, late ATW-
     *     check) en mapt de eerste foutmelding terug naar het juiste
     *     Livewire-veld.
     *  6. On success: dispatch `entry-saved` event (de week-tabel kan
     *     daarop refreshen) en sluit de modal.
     */
    public function submit(WorkEntriesService $workEntriesService, AtwService $atwService): mixed
    {
        $this->isSubmitting = true;

        try {
            $this->validate();

            // Verse ATW-validatie zodat we niet op een stale resultaat
            // beslissen of we mogen opslaan.
            $this->validateAtwLive($atwService);

            if (($this->atwResult['has_critical'] ?? false) === true) {
                $this->addError(
                    'atw',
                    'Eerst de kritieke ATW-meldingen oplossen voordat je kunt opslaan.'
                );

                return null;
            }

            /** @var User|null $actor */
            $actor = Auth::user();
            if ($actor === null) {
                abort(403, 'Geen toegang.');
            }

            try {
                if ($this->entryId !== null) {
                    // Edit-modus: update bestaande entry.
                    $workEntriesService->update($this->entryId, $this->buildCreatePayload(), (int) $actor->id);
                } else {
                    // Aanmaakmodus: maak nieuwe entry.
                    $workEntriesService->create($this->buildCreatePayload(), (int) $actor->id);
                }
            } catch (ValidationException $e) {
                $this->mapServiceErrors($e);

                return null;
            }

            // Succes — informeer de week-tabel zodat hij kan herladen
            // en sluit de modal. We sturen `employeeId` en `entryDate`
            // mee zodat een geoptimaliseerde refresh mogelijk is
            // (taak 10.1 mag daar later op luisteren).
            $this->dispatch(
                'entry-saved',
                employeeId: $this->employeeId,
                entryDate: $this->entryDate,
            );

            // Toast (success) — Req 6.7
            $toastMessage = $this->entryId !== null
                ? 'Werkregel bijgewerkt'
                : 'Werkregel opgeslagen';
            $this->dispatch('toast', variant: 'success', message: $toastMessage);

            $this->closeModal();

            return null;
        } finally {
            $this->isSubmitting = false;
        }
    }

    /**
     * Verwijder de bestaande werkregel. Alleen beschikbaar in edit-modus.
     * Roept `WorkEntriesService::delete()` aan en dispatcht toast +
     * entry-saved event zodat de week-tabel kan refreshen.
     */
    public function delete(WorkEntriesService $workEntriesService): void
    {
        if ($this->entryId === null) {
            return;
        }

        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        try {
            $workEntriesService->delete($this->entryId, (int) $actor->id);
        } catch (ValidationException $e) {
            $this->mapServiceErrors($e);

            return;
        } catch (Throwable $e) {
            $this->addError('atw', 'Kon de werkregel niet verwijderen. Probeer opnieuw.');

            return;
        }

        $this->dispatch(
            'entry-saved',
            employeeId: $this->employeeId,
            entryDate: $this->entryDate,
        );

        $this->dispatch('toast', variant: 'success', message: 'Werkregel verwijderd');

        $this->closeModal();
    }

    /**
     * Beschikbare projecten voor de actor zijn organisatie. View-side
     * formaat: `[id => name]` zodat de `<select>` simpel te renderen is.
     */
    public function getProjects(ProjectsService $projectsService): array
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            return [];
        }

        $rows = $projectsService->list((int) $actor->id, ['is_active' => true]);

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = (string) ($row['name'] ?? '');
        }

        return $map;
    }

    /**
     * Beschikbare kostenplaatsen voor de actor zijn organisatie.
     */
    public function getCostCenters(CostCentersService $costCentersService): array
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            return [];
        }

        $rows = $costCentersService->list((int) $actor->id, ['is_active' => true]);

        $map = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $map[$id] = (string) ($row['name'] ?? '');
        }

        return $map;
    }

    /**
     * NL-labels voor de type-select. HOLIDAY is alleen toegestaan voor
     * owner/manager; de view filtert op `Auth::user()->role` om de optie
     * te verbergen voor employees (Req 7.2).
     *
     * @return array<string, string>
     */
    public function getAvailableTypes(): array
    {
        return [
            'WORK' => 'Werk',
            'SICK' => 'Ziek',
            'LEAVE' => 'Verlof',
            'HOLIDAY' => 'Feestdag',
            'OTHER' => 'Overig',
        ];
    }

    public function render(): View
    {
        return view('livewire.hours.entry-form-modal');
    }

    /**
     * Bouw het input-array voor `WorkEntriesService::create`. Optionele
     * velden alleen meegeven als ze gezet zijn; de service mapt 0 / null
     * / "" naar "geen koppeling" via `resolveOptionalId`, dus we mogen
     * altijd de raw-waarde meegeven.
     *
     * @return array<string, mixed>
     */
    private function buildCreatePayload(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'entry_date' => $this->entryDate,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'pause_minutes' => max(0, $this->pauseMinutes),
            'type' => $this->type,
            'project_id' => $this->projectId,
            'cost_center_id' => $this->costCenterId,
            'note' => $this->note,
        ];
    }

    /**
     * Map service-side ValidationException-fouten naar Livewire-velden.
     * De service gebruikt zowel snake_case (`employee_id`, `pause_minutes`)
     * als kale codes (`PROJECT_ORG_MISMATCH`, `COST_CENTER_INACTIVE`)
     * als foutkeys, dus we mappen beide naar de juiste UI-veldnaam.
     */
    private function mapServiceErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            $first = is_array($messages) ? ($messages[0] ?? '') : (string) $messages;

            $target = match ($field) {
                'employee_id' => 'employeeId',
                'entry_date' => 'entryDate',
                'start_time' => 'startTime',
                'end_time' => 'endTime',
                'pause_minutes' => 'pauseMinutes',
                'project_id' => 'projectId',
                'cost_center_id' => 'costCenterId',
                'note' => 'note',
                'type' => 'type',
                default => 'atw',
            };

            $this->addError($target, (string) $first);
        }
    }

    /**
     * Laad de bestaande entry-data in de formuliervelden voor edit-modus.
     * Haalt de werkregel op uit de database en vult de properties.
     */
    private function loadEntryData(int $entryId): void
    {
        $entry = WorkEntry::query()
            ->where('id', $entryId)
            ->whereNull('deleted_at')
            ->first();

        if ($entry === null) {
            // Entry niet gevonden of verwijderd — val terug naar aanmaakmodus.
            $this->entryId = null;

            return;
        }

        $this->employeeId = (int) $entry->employee_id;
        $this->entryDate = $entry->entry_date instanceof Carbon
            ? $entry->entry_date->toDateString()
            : (string) $entry->entry_date;
        $this->type = strtoupper((string) $entry->type);
        $this->pauseMinutes = (int) $entry->pause_minutes;
        $this->note = (string) ($entry->note ?? '');
        $this->projectId = $entry->project_id !== null ? (int) $entry->project_id : null;
        $this->costCenterId = $entry->cost_center_id !== null ? (int) $entry->cost_center_id : null;

        // Tijden converteren van UTC naar Europe/Amsterdam voor weergave.
        if ($entry->start_at instanceof Carbon) {
            $this->startTime = $entry->start_at->copy()->setTimezone('Europe/Amsterdam')->format('H:i');
        } else {
            $this->startTime = Carbon::parse($entry->start_at)->setTimezone('Europe/Amsterdam')->format('H:i');
        }

        if ($entry->end_at instanceof Carbon) {
            $this->endTime = $entry->end_at->copy()->setTimezone('Europe/Amsterdam')->format('H:i');
        } else {
            $this->endTime = Carbon::parse($entry->end_at)->setTimezone('Europe/Amsterdam')->format('H:i');
        }
    }

    /**
     * Zoek de vorige werkdag (ma-vr, exclusief feestdagen) en retourneer
     * de placeholder-tekst als de medewerker op die dag een WORK-entry had.
     *
     * Formaat: "Vorige dag: HH:MM - HH:MM"
     * Retourneert null als er geen vorige werkdag-entry is.
     *
     * Requirements: 6.2
     */
    private function loadPreviousDayPlaceholder(int $employeeId, string $entryDate): ?string
    {
        try {
            $date = Carbon::parse($entryDate);
        } catch (Throwable) {
            return null;
        }

        // Zoek de vorige werkdag (max 7 dagen terug om feestdagen/weekenden te overbruggen).
        $holidaysService = app(HolidaysService::class);
        $holidays = collect($holidaysService->computeNlHolidaysForYear($date->year))
            ->pluck('date')
            ->toArray();

        $previousWorkday = null;
        $candidate = $date->copy()->subDay();
        $maxAttempts = 7;

        while ($maxAttempts > 0) {
            // Werkdag = ma-vr en geen feestdag
            if ($candidate->isWeekday() && ! in_array($candidate->format('Y-m-d'), $holidays, true)) {
                $previousWorkday = $candidate->format('Y-m-d');
                break;
            }
            $candidate->subDay();
            $maxAttempts--;
        }

        if ($previousWorkday === null) {
            return null;
        }

        // Zoek de meest recente WORK-entry op die dag voor deze medewerker.
        $entry = WorkEntry::query()
            ->where('employee_id', $employeeId)
            ->where('type', 'WORK')
            ->whereDate('entry_date', $previousWorkday)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->first(['start_at', 'end_at']);

        if ($entry === null) {
            return null;
        }

        $start = $entry->start_at instanceof Carbon
            ? $entry->start_at->format('H:i')
            : Carbon::parse($entry->start_at)->format('H:i');
        $end = $entry->end_at instanceof Carbon
            ? $entry->end_at->format('H:i')
            : Carbon::parse($entry->end_at)->format('H:i');

        return "Vorige dag: {$start} - {$end}";
    }

    /**
     * Reset alle invoer-velden (behalve `employeeId` en `entryDate`,
     * die door de open-event worden bepaald) naar de default-waarden.
     * Wordt aangeroepen door {@see openModal()} en {@see closeModal()}.
     */
    private function resetFieldsToDefaults(): void
    {
        foreach (self::FIELD_DEFAULTS as $property => $value) {
            $this->{$property} = $value;
        }
    }

    /**
     * Converteer `H:i` naar het totaal aantal minuten sinds middernacht.
     * Returnt 0 bij parse-fout (lege string, ongeldig formaat) zodat de
     * netto-berekening niet kan crashen op halve invoer.
     */
    private static function timeToMinutes(string $hi): int
    {
        if ($hi === '') {
            return 0;
        }

        $parts = explode(':', $hi, 2);
        if (count($parts) !== 2) {
            return 0;
        }

        $h = (int) $parts[0];
        $m = (int) $parts[1];

        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return 0;
        }

        return $h * 60 + $m;
    }
}
