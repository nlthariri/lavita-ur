<?php

declare(strict_types=1);

namespace App\Livewire\Objections;

use App\Livewire\Atw\StatusDashboard;
use App\Livewire\Hours\EntryFormModal;
use App\Livewire\Hours\WeekOverviewTable;
use App\Models\Objection;
use App\Models\User;
use App\Services\ObjectionsService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Livewire-component — `Objections\ReviewForm` (taak 11.2 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.6  → scherm "Bezwaarbeoordeling": owner/manager
 *      accepteert/wijst af met motivatie min 10, max 1000 tekens; submit
 *      gedeactiveerd zolang motivatie korter is dan 10 tekens.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
 *      `design.md`.
 *  - requirements.md 6.14 / NFR-10 → NL-labels en NL-foutmeldingen.
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      "Bezwaar beoordelen | /bezwaren/{id} | Objections\ReviewForm".
 *  - design.md § Bezwaarprocedure → review roept
 *      {@see ObjectionsService::review()} aan met decision APPROVED of
 *      REJECTED en optionele correctie-velden.
 *  - tasks.md 11.2.
 *
 * Verantwoordelijkheid:
 *  - Full-page-formulier (geen modal — de URL is `/bezwaren/{id}`) waarmee
 *    een owner of manager een open bezwaar beoordeelt. Bij accept wordt
 *    de werkregel gecorrigeerd via `ObjectionsService::review()` met
 *    `decision = APPROVED` en de drie corrected_*-velden. Bij reject
 *    wordt de werkregel niet aangepast, maar de motivatie is verplicht
 *    (min 10 tekens — req 6.6).
 *  - De originele werkregel-snapshot (datum, begin/eind, pauze, netto)
 *    en de motivatie van de medewerker worden read-only getoond zodat
 *    de beoordelaar context heeft.
 *  - Statusbadge OPEN/APPROVED/REJECTED bovenaan; reeds beoordeelde
 *    bezwaren tonen een NL `role="status"`-block en disablen de form-
 *    velden zodat dubbelreview niet mogelijk is (de service zou ook
 *    422 retourneren, maar UI-disable bespaart een rondrit).
 *
 * Spec-deviation — directe service-call vs HTTP-roundtrip:
 *  De spec van taak 11.2 noemt geen expliciet HTTP-endpoint en de
 *  Livewire-server zit binnen dezelfde app als de Laravel-route. We
 *  roepen {@see ObjectionsService::review()} direct aan via DI op de
 *  actie-methode — zelfde patroon als
 *  {@see EntryFormModal::submit()} en
 *  {@see NewObjectionForm::submit()}. De
 *  service-foutmeldingen blijven NL en worden via {@see mapServiceErrors()}
 *  op de juiste UI-velden geplaatst.
 *
 * Spec-deviation — "motivation"-textarea heet in DB/service `manager_response`:
 *  De acceptance-tekst van req 6.6 noemt "motivatie" als alias voor
 *  het manager-response-veld dat hij invult bij beoordelen. De service
 *  + DB-kolom heten `manager_response` (zie `objections.manager_response`).
 *  De UI volgt de NL-tekst van requirements.md ("Beoordeling motivatie")
 *  maar de Livewire-property heet `managerResponse` zodat de mapping
 *  naar de service triviaal blijft.
 *
 * Bewust niet:
 *  - Geen route-registratie in `routes/web.php` — wordt in een latere
 *    taak opgenomen samen met de andere Livewire-routes (zelfde keuze
 *    als bij {@see StatusDashboard} en
 *    {@see WeekOverviewTable}).
 *  - Geen mail-dispatch — die zit volledig in
 *    {@see ObjectionsService::review()} (notify submitter).
 *  - Geen lijst-view van te-beoordelen bezwaren — buiten scope van 11.2
 *    (hoort bij taak 11.3 — `Dashboard\ManagerHome`).
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt op `<x-ui.button>`, `<x-ui.card>`, `<x-ui.text-input>`,
 *    `<x-ui.status-badge>`.
 *  - Bewuste deviation: native `<textarea>` voor de manager_response —
 *    het bestaande `<x-ui.text-input>`-atom rendert een `<input>` en
 *    heeft geen textarea-mode (zelfde deviation als bij taak 10.2 en
 *    10.3). We mirroren de input-token-styling (border-2 + radius-input
 *    + brand-green focus).
 */
#[Layout('layouts.app')]
#[Title('Bezwaar beoordelen — LaVita Urenregistratie')]
final class ReviewForm extends Component
{
    /**
     * Identifier van het bezwaar dat beoordeeld wordt. Wordt in
     * {@see mount()} gevuld uit (in volgorde) de mount-arg, de query-
     * parameter `?objection_id=…`, of de route-parameter `{id}`.
     *
     * 0 / null → 404 in mount; we eisen een int >0 zodat tests die het
     * component zonder argument starten direct een 404 krijgen.
     */
    #[Validate(
        rule: 'required|integer',
        message: [
            'objectionId.required' => 'Bezwaar-id is verplicht.',
            'objectionId.integer' => 'Bezwaar-id is ongeldig.',
        ],
        attribute: ['objectionId' => 'bezwaar'],
        translate: false,
    )]
    public ?int $objectionId = null;

    /**
     * Manager-response (alias "motivatie" in req 6.6, `manager_response`
     * in DB/service). Voor REJECTED is min 10 verplicht (handhaafd in
     * {@see reject()} en op de submit-knop in de view); voor APPROVED is
     * deze optioneel. Max 1000 tekens (req 6.6).
     *
     * We gebruiken `nullable|string|max:1000` als basis-validatie zodat
     * accept met een lege motivatie niet faalt op het generieke
     * `validate()`-pad; de min:10-regel voor reject wordt expliciet
     * gecheckt in {@see reject()}.
     */
    #[Validate(
        rule: 'nullable|string|max:1000',
        message: [
            'managerResponse.string' => 'Motivatie moet een tekstwaarde zijn.',
            'managerResponse.max' => 'Motivatie mag maximaal 1000 tekens bevatten.',
        ],
        attribute: ['managerResponse' => 'motivatie'],
        translate: false,
    )]
    public string $managerResponse = '';

    /**
     * Gecorrigeerde begintijd `H:i` (Europe/Amsterdam-tijd op de entry-
     * datum). Default leeg; wordt in {@see mount()} naar de oorspronkelijke
     * begintijd van de werkregel gezet zodat "accepteren as-is" geen
     * extra invoer vereist.
     */
    #[Validate(
        rule: 'nullable|date_format:H:i',
        message: [
            'correctedStartTime.date_format' => 'Gecorrigeerde begintijd moet in het formaat UU:MM staan.',
        ],
        attribute: ['correctedStartTime' => 'gecorrigeerde begintijd'],
        translate: false,
    )]
    public string $correctedStartTime = '';

    /**
     * Gecorrigeerde eindtijd `H:i`.
     */
    #[Validate(
        rule: 'nullable|date_format:H:i',
        message: [
            'correctedEndTime.date_format' => 'Gecorrigeerde eindtijd moet in het formaat UU:MM staan.',
        ],
        attribute: ['correctedEndTime' => 'gecorrigeerde eindtijd'],
        translate: false,
    )]
    public string $correctedEndTime = '';

    /**
     * Gecorrigeerde pauze in minuten. 0..480, gelijke spreiding als
     * {@see EntryFormModal}.
     */
    #[Validate(
        rule: 'nullable|integer|min:0|max:480',
        message: [
            'correctedPauseMinutes.integer' => 'Gecorrigeerde pauze moet een geheel aantal minuten zijn.',
            'correctedPauseMinutes.min' => 'Gecorrigeerde pauze kan niet negatief zijn.',
            'correctedPauseMinutes.max' => 'Gecorrigeerde pauze mag maximaal 480 minuten zijn.',
        ],
        attribute: ['correctedPauseMinutes' => 'gecorrigeerde pauze'],
        translate: false,
    )]
    public int $correctedPauseMinutes = 0;

    /**
     * Read-only display: de datum van de werkregel waarop het bezwaar
     * is ingediend (`Y-m-d`). Wordt in {@see mount()} gezet.
     */
    public ?string $entryDate = null;

    /**
     * Display-naam van de medewerker (`users.full_name` met fallback
     * naar `users.name`). Read-only.
     */
    public ?string $employeeName = null;

    /**
     * Originele begintijd in `H:i` (Europe/Amsterdam). Read-only.
     */
    public ?string $originalStartTime = null;

    /**
     * Originele eindtijd in `H:i`. Read-only.
     */
    public ?string $originalEndTime = null;

    /**
     * Originele pauze (minuten). Read-only.
     */
    public ?int $originalPauseMinutes = null;

    /**
     * Originele netto-minuten. Read-only.
     */
    public ?int $originalNetMinutes = null;

    /**
     * Motivatie van de medewerker bij het indienen van het bezwaar.
     * Read-only. Komt uit `objections.motivation`.
     */
    public ?string $submitterMotivation = null;

    /**
     * Status van het bezwaar (`OPEN` / `APPROVED` / `REJECTED`). Wordt
     * in {@see mount()} uit de DB gelezen en bij succesvolle accept/reject
     * in-memory bijgewerkt zodat de view direct kan herkennen dat de
     * form gedeactiveerd moet zijn.
     */
    public ?string $status = null;

    /**
     * NL-bevestigingsbanner na succesvolle accept/reject. `null` =
     * geen banner. Wordt door de view in een `role="status"`-block
     * gerenderd.
     */
    public ?string $confirmation = null;

    /**
     * Mount-fase.
     *
     *  1. Resolve current user via de `Auth`-facade. Geen user → 403.
     *  2. Verbied rollen `employee` en `boekhouder`; alleen owner/manager
     *     mag bezwaren beoordelen (zie service `ALLOWED_REVIEW_ROLES`).
     *  3. Resolve `$objectionId` via mount-arg → query-string → route-
     *     parameter → 0. Bij 0 → 404 zodat tests die het component zonder
     *     argument starten een duidelijk antwoord krijgen.
     *  4. Laad het bezwaar inclusief de werkregel + medewerker. Bezwaar
     *     niet gevonden → 404 (via `findOrFail`).
     *  5. Org-scope-check: het bezwaar moet bij de org van de actor horen
     *     (anders 403). De service zou dit ook checken bij submit, maar
     *     UI-side weren we het direct.
     *  6. Vul de read-only display-velden + de corrected_*-defaults
     *     (originele waarden zodat "accept as-is" geen extra invoer
     *     vereist).
     */
    public function mount(?int $objectionId = null): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            // Defensief: routes worden in `web`-middleware-stack geserveerd
            // maar de auth-guard wordt pas in een latere taak vol-geactiveerd.
            // Tests gebruiken `$this->actingAs($user)` zodat dit pad alleen
            // wordt geraakt door anonieme requests in productie.
            abort(403, 'Geen toegang.');
        }

        if (in_array((string) $user->role, ['employee', 'boekhouder'], true)) {
            abort(403, 'Alleen eigenaar of manager mag bezwaren beoordelen.');
        }

        // Resolve volgorde: methode-arg → query → route-param → 0.
        $resolvedId = $objectionId
            ?? request()->query('objection_id')
            ?? request()->input('objection_id')
            ?? request()->route('id')
            ?? 0;

        $resolvedId = (int) $resolvedId;

        if ($resolvedId <= 0) {
            abort(404, 'Bezwaar niet gevonden.');
        }

        $this->objectionId = $resolvedId;

        /** @var Objection $objection */
        $objection = Objection::with(['workEntry.employee'])->findOrFail($resolvedId);

        if ((int) $objection->organization_id !== (int) $user->organization_id) {
            abort(403, 'Bezwaar behoort niet tot uw organisatie.');
        }

        $this->status = (string) $objection->status;
        $this->submitterMotivation = (string) ($objection->motivation ?? '');

        $workEntry = $objection->workEntry;
        if ($workEntry !== null) {
            // Datum als `Y-m-d`. `entry_date` is een `date`-cast (Carbon).
            $this->entryDate = $workEntry->entry_date instanceof Carbon
                ? $workEntry->entry_date->toDateString()
                : (string) $workEntry->entry_date;

            // Originele tijden in Europe/Amsterdam zodat de beoordelaar
            // ze in lokale tijd ziet (`start_at` / `end_at` zijn UTC in
            // de DB).
            if ($workEntry->start_at instanceof Carbon) {
                $this->originalStartTime = $workEntry->start_at->copy()
                    ->setTimezone('Europe/Amsterdam')
                    ->format('H:i');
            }
            if ($workEntry->end_at instanceof Carbon) {
                $this->originalEndTime = $workEntry->end_at->copy()
                    ->setTimezone('Europe/Amsterdam')
                    ->format('H:i');
            }

            $this->originalPauseMinutes = (int) ($workEntry->pause_minutes ?? 0);
            $this->originalNetMinutes = (int) ($workEntry->net_minutes ?? 0);

            // Default de corrected_*-velden naar de originele waarden
            // zodat accepteren "as-is" geen extra invoer vereist; de
            // beoordelaar kan ze daarna naar wens aanpassen.
            $this->correctedStartTime = (string) ($this->originalStartTime ?? '');
            $this->correctedEndTime = (string) ($this->originalEndTime ?? '');
            $this->correctedPauseMinutes = (int) ($this->originalPauseMinutes ?? 0);

            // Display-naam van de medewerker.
            $employee = $workEntry->employee;
            if ($employee instanceof User) {
                $this->employeeName = (string) ($employee->full_name ?? $employee->name ?? '');
            }
        }
    }

    /**
     * Beoordeel het bezwaar als geaccepteerd (decision = APPROVED).
     *
     * Stappen:
     *  1. `validate()` over de basis-velden (objectionId aanwezig +
     *     corrected_*-velden in juist formaat).
     *  2. Roep {@see ObjectionsService::review()} met decision APPROVED
     *     en de drie corrected_*-velden + manager_response (mag leeg).
     *  3. {@see ValidationException} → map errors via {@see mapServiceErrors()}.
     *  4. Op succes: zet status, confirmation en dispatch
     *     `objection-reviewed` event zodat de parent-pagina (bv.
     *     `Dashboard\ManagerHome`, taak 11.3) kan refreshen.
     */
    public function accept(ObjectionsService $service): mixed
    {
        $this->validate();

        if ((int) ($this->objectionId ?? 0) <= 0) {
            abort(404, 'Bezwaar niet gevonden.');
        }

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        try {
            $service->review(
                (int) $this->objectionId,
                [
                    'decision' => 'APPROVED',
                    'manager_response' => $this->managerResponse,
                    'corrected_start_time' => $this->correctedStartTime,
                    'corrected_end_time' => $this->correctedEndTime,
                    'corrected_pause_minutes' => (int) $this->correctedPauseMinutes,
                ],
                (int) $user->id,
            );
        } catch (ValidationException $e) {
            $this->mapServiceErrors($e);

            return null;
        }

        $this->status = 'APPROVED';
        $this->confirmation = 'Bezwaar geaccepteerd. Werkregel is bijgewerkt.';

        $this->dispatch('objection-reviewed', objectionId: $this->objectionId, decision: 'APPROVED');

        return null;
    }

    /**
     * Beoordeel het bezwaar als afgewezen (decision = REJECTED).
     *
     * Stappen:
     *  1. `validate()` over de basis-velden.
     *  2. Min:10-regel voor REJECTED: spec req 6.6 schrijft min 10 tekens
     *     voor de motivatie voor — bij afwijzing is een motivatie verplicht
     *     (de service eist ≥1 niet-blanco teken; UI eist ≥10). Bij <10
     *     tekens NL-foutmelding op `managerResponse` en geen service-call.
     *  3. Roep {@see ObjectionsService::review()} met decision REJECTED.
     *  4. {@see ValidationException} → {@see mapServiceErrors()}.
     *  5. Op succes: zet status, confirmation en dispatch
     *     `objection-reviewed`.
     */
    public function reject(ObjectionsService $service): mixed
    {
        $this->validate();

        if ((int) ($this->objectionId ?? 0) <= 0) {
            abort(404, 'Bezwaar niet gevonden.');
        }

        if (mb_strlen(trim($this->managerResponse)) < 10) {
            $this->addError('managerResponse', 'Motivatie moet minimaal 10 tekens bevatten.');

            return null;
        }

        /** @var User|null $user */
        $user = Auth::user();
        if ($user === null) {
            abort(403, 'Geen toegang.');
        }

        try {
            $service->review(
                (int) $this->objectionId,
                [
                    'decision' => 'REJECTED',
                    'manager_response' => $this->managerResponse,
                ],
                (int) $user->id,
            );
        } catch (ValidationException $e) {
            $this->mapServiceErrors($e);

            return null;
        }

        $this->status = 'REJECTED';
        $this->confirmation = 'Bezwaar afgewezen. Werkregel blijft ongewijzigd.';

        $this->dispatch('objection-reviewed', objectionId: $this->objectionId, decision: 'REJECTED');

        return null;
    }

    /**
     * NL-label voor de huidige status. Wordt door de view gebruikt voor
     * de statusbadge en het "al beoordeeld"-block.
     */
    public function getStatusLabel(): string
    {
        return match ((string) $this->status) {
            'OPEN' => 'Open',
            'APPROVED' => 'Geaccepteerd',
            'REJECTED' => 'Afgewezen',
            default => '—',
        };
    }

    /**
     * Map status-string naar `<x-ui.status-badge>`-variant. OPEN gebruikt
     * `concept` (grijs) zodat hij visueel onderscheidt van de definitieve
     * uitkomsten APPROVED (succes-groen) en REJECTED (waarschuwingsgeel).
     */
    public function getStatusBadgeVariant(): string
    {
        return match ((string) $this->status) {
            'OPEN' => 'concept',
            'APPROVED' => 'success',
            'REJECTED' => 'warning',
            default => 'concept',
        };
    }

    public function render(): View
    {
        return view('livewire.objections.review-form');
    }

    /**
     * Map service-side ValidationException-fouten naar Livewire-velden.
     * De service gebruikt zowel snake_case (`manager_response`,
     * `corrected_start_time`) als generieke keys (`reviewer`,
     * `decision`, `objection`, `status`) als foutkeys; alle generieke
     * keys mappen we op het zichtbare `managerResponse`-veld zodat de
     * gebruiker direct ziet waarom de submit faalde.
     */
    private function mapServiceErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            $first = is_array($messages) ? ($messages[0] ?? '') : (string) $messages;

            $target = match ($field) {
                'manager_response' => 'managerResponse',
                'corrected_start_time' => 'correctedStartTime',
                'corrected_end_time' => 'correctedEndTime',
                'corrected_pause_minutes' => 'correctedPauseMinutes',
                default => 'managerResponse',
            };

            $this->addError($target, (string) $first);
        }
    }
}
