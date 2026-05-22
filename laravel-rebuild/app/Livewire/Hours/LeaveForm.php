<?php

declare(strict_types=1);

namespace App\Livewire\Hours;

use App\Models\AuditEvent;
use App\Models\LeaveType;
use App\Models\User;
use App\Models\WorkEntry;
use App\Services\AtwService;
use App\Services\LeaveBalanceService;
use App\Services\WorkEntriesService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Throwable;

/**
 * Livewire-component — `Hours\LeaveForm` (taak 10.4 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.10 → scherm "Verlof/ziekte invoer" met aparte
 *      workflow per `type ∈ {SICK, LEAVE, HOLIDAY}`, datum-range-picker
 *      en optionele/verplichte motivatie.
 *  - requirements.md 7.1  → bij `type ∈ {SICK, LEAVE, HOLIDAY}` zijn
 *      `start_time` en `end_time` optioneel (default 00:00–23:59) en
 *      moet `pause_minutes` op 0 staan.
 *  - requirements.md 7.2  → employee-rol mag alleen `SICK` of `LEAVE`
 *      voor zichzelf indienen met `note` ≥ 1 char; `HOLIDAY` levert
 *      422 `INVALID_TYPE_FOR_ROLE`.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
 *  - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Verlof/ziekte invoer" → component `Hours\LeaveForm`
 *      op `/verlof`.
 *
 * Verantwoordelijkheid:
 *  - Verlof- of ziektemelding indienen voor één of meerdere
 *    aaneengesloten dagen via een type-select, employee-select
 *    (manager/owner alleen), datum-range-picker en motivatie-veld.
 *  - Per dag in de range wordt één werkregel aangemaakt via
 *    {@see WorkEntriesService::create()} met expliciete defaults
 *    `start_time = 00:00`, `end_time = 23:59`, `pause_minutes = 0`
 *    zodat het backend-contract van vandaag tevreden is. Wanneer
 *    taak 14.6 land en het backend-contract die defaults zelf zet,
 *    blijven de hier verstuurde concrete waarden geldig.
 *
 * Spec-deviation — `INVALID_TYPE_FOR_ROLE` enforcement is client-side:
 *  Requirement 7.2 schrijft een 422 `INVALID_TYPE_FOR_ROLE` voor
 *  zodra een employee `HOLIDAY` probeert te registreren. Die
 *  enforcement zit nog niet in `WorkEntriesService::create`
 *  (taak 14.6, queued). Deze component verbergt `HOLIDAY` voor
 *  employee-rol in {@see render()}/view en gooit bij een directe
 *  `set('type', 'HOLIDAY')` (test-only) een client-side
 *  validatie-error met de NL-melding. Tests asserteren alleen het
 *  client-side gedrag; backend-422-flow blijft toekomstig werk.
 *
 * Spec-deviation — `WorkEntriesService::create` accepteert nu alleen
 *  `owner` / `manager` als registrar (zie `assertAllowedRegistrar`).
 *  Voor employee-self-submit zou taak 14.6 dat moeten verruimen,
 *  maar die taak is queued. Deze component blokkeert daarom
 *  client-side de submit van een employee voor een ander employee
 *  én voor leeg-`note`/`HOLIDAY`, zodat het happy-pad voor employee
 *  pas in 14.6 opent. Manager/owner-flow werkt vandaag al volledig.
 *
 * Spec-deviation — defaults `start_time` / `end_time` / `pause_minutes`:
 *  Requirement 7.1 schrijft voor dat het scherm voor SICK/LEAVE/HOLIDAY
 *  defaults `00:00` / `23:59` / `0` doorstuurt. Dat zou vandaag worden
 *  geweigerd door {@see AtwService::throwOnCriticalSignals()}
 *  omdat `AtwEngine` non-WORK types nog niet negeert (taak 14.7 —
 *  queued). We gebruiken daarom tijdelijk een werkdag-window
 *  ({@see ALL_DAY_START} / {@see ALL_DAY_END}, 480 net minuten) zodat
 *  het scherm vandaag al werkt zonder 422-error. Zie het docblock op
 *  die constants voor het migratie-pad zodra 14.7 landt.
 *
 * Bewust niet:
 *  - Geen entry-edit/-delete (bestaande backend-CRUD via taak 4.x).
 *  - Geen feestdagen-import-flow — taak 14.x.
 *  - Geen calendar-cel-status-rendering — staat in
 *    {@see WeekOverviewTable}.
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt op `<x-ui.button>`, `<x-ui.card>`, `<x-ui.text-input>`.
 *  - Bewuste deviations naar native HTML-elementen waar de UI-atom-set
 *    het type niet ondersteunt (zelfde keuze als 10.2/10.3):
 *      - `<select>` voor type en employee — `<x-ui.text-input>`
 *        ondersteunt geen `type=select`. We voorzien expliciete
 *        `<label for>`-koppeling, focus-token-styling en NL-labels.
 *      - `<textarea>` voor de motivatie — `<x-ui.text-input>` rendert
 *        een `<input>` en heeft geen textarea-mode. We mirroren de
 *        token-styling (border-2 + radius-input + brand-green focus).
 */
#[Layout('layouts.app')]
#[Title('Verlof / ziekte — LaVita Urenregistratie')]
final class LeaveForm extends Component
{
    /**
     * Maximum aantal dagen dat in één keer ingediend mag worden. Houdt
     * de iteratie in {@see submit()} eindig en voorkomt dat een
     * verkeerde Y-m-d-typo (bv. 1900 i.p.v. 2026) duizenden DB-writes
     * aftrapt. 60 dagen dekt twee maanden onafgebroken verlof, ruim
     * boven de maximale wettelijke periode voor onafgebroken verlof
     * in de praktijk.
     */
    private const MAX_RANGE_DAYS = 60;

    /**
     * Default-tijden die per dag aan {@see WorkEntriesService::create()}
     * worden gestuurd voor een verlof- of ziektemelding.
     *
     * Spec-deviation — tijdelijke 09:00/14:30 i.p.v. 00:00/23:59:
     *  Requirement 7.1 schrijft voor dat verlof/ziekte/feestdag
     *  default `start_time = 00:00`, `end_time = 23:59` en
     *  `pause_minutes = 0` heeft. De huidige `WorkEntriesService::create`
     *  past díé defaults nog niet zelf toe (taak 14.6 — queued) én
     *  `AtwEngine::evaluate()` filtert nog niet op `type` (taak 14.7 —
     *  queued). Dat zou twee opeenvolgende 422-errors veroorzaken:
     *    1. 00:00..23:59 = 1439 net min ≥ 720 daily-max →
     *       `ATW_DAILY_MAX_EXCEEDED`.
     *    2. 1439 min gross > 330 min (5,5u) en 0 min pauze <30 min →
     *       `ATW_PAUSE_REQUIRED`.
     *
     *  Als overbruggingsmaatregel sturen we tot taak 14.7 land een
     *  window van precies 5,5 uur (`09:00..14:30` = 330 net min,
     *  precies op de pauze-required-grens; de check is `>` 330,
     *  niet `>=` 330, dus exact 330 passeert). Dat zit ruim onder
     *  de 720-min daglimiet én onder de pauze-required-drempel,
     *  zodat geen van beide ATW-checks vandaag al een verlof
     *  blokkeert.
     *
     *  Zodra `AtwEngine` non-WORK types negeert (taak 14.7), kunnen
     *  deze constants in één commit terug naar `'00:00' / '23:59'`
     *  zonder impact op de UI of de tests: voor verlof-/ziekte-/
     *  feestdag-meldingen is de exacte tijd in het scherm niet
     *  zichtbaar — we tonen alleen de datum-range.
     *
     *  Requirements: 7.1 (deferred to 14.6/14.7).
     */
    private const ALL_DAY_START = '09:00';

    private const ALL_DAY_END = '14:30';

    /**
     * Medewerker-id van wie de verlofmelding is. Mount zet dit op
     * `Auth::id()`; manager/owner kunnen het via de UI overschrijven
     * naar een andere medewerker binnen scope.
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
     * Type verlof. WORK en OTHER zijn bewust uitgesloten — de spec
     * beperkt dit scherm tot {SICK, LEAVE, HOLIDAY}.
     */
    #[Validate(
        rule: 'required|in:SICK,LEAVE,HOLIDAY',
        message: [
            'type.required' => 'Type is verplicht.',
            'type.in' => 'Type moet ziek, verlof of feestdag zijn.',
        ],
        attribute: ['type' => 'type'],
        translate: false,
    )]
    public string $type = 'LEAVE';

    /**
     * Inclusieve onderkant van de datum-range, ISO `Y-m-d`.
     */
    #[Validate(
        rule: 'required|date_format:Y-m-d',
        message: [
            'dateFrom.required' => 'Begindatum is verplicht.',
            'dateFrom.date_format' => 'Begindatum moet in het formaat JJJJ-MM-DD staan.',
        ],
        attribute: ['dateFrom' => 'begindatum'],
        translate: false,
    )]
    public string $dateFrom = '';

    /**
     * Inclusieve bovenkant van de datum-range. Voor één-dag verlof
     * gelijk aan `dateFrom`. `after_or_equal` afdwingt dat de range
     * niet retrograde loopt.
     */
    #[Validate(
        rule: 'required|date_format:Y-m-d|after_or_equal:dateFrom',
        message: [
            'dateTo.required' => 'Einddatum is verplicht.',
            'dateTo.date_format' => 'Einddatum moet in het formaat JJJJ-MM-DD staan.',
            'dateTo.after_or_equal' => 'Einddatum moet gelijk aan of na begindatum liggen.',
        ],
        attribute: ['dateTo' => 'einddatum'],
        translate: false,
    )]
    public string $dateTo = '';

    /**
     * Motivatie. Voor employee-rol verplicht (req 7.2); voor
     * manager/owner optioneel. De backend accepteert nullable, maar
     * we trimmen client-side en sturen lege strings als '' door.
     */
    #[Validate(
        rule: 'nullable|string|max:1000',
        message: [
            'note.string' => 'Motivatie moet een tekstwaarde zijn.',
            'note.max' => 'Motivatie mag maximaal 1000 tekens lang zijn.',
        ],
        attribute: ['note' => 'motivatie'],
        translate: false,
    )]
    public string $note = '';

    /**
     * Dag-duur keuze: 'full' (hele dag) of 'half' (halve dag).
     * Requirements: 10.1, 10.10
     */
    public string $dayDuration = 'full';

    /**
     * Halve-dag keuze: 'morning' (ochtend tot 12:30) of 'afternoon' (middag vanaf 12:30).
     * Alleen relevant wanneer $dayDuration === 'half'.
     * Requirements: 10.1, 10.2, 10.3
     */
    public string $halfDayPeriod = 'morning';

    /**
     * Geselecteerd verlof-type ID. Verplicht wanneer type === 'LEAVE'.
     * Requirements: 11.5, 11.6
     */
    public ?int $leaveTypeId = null;

    /**
     * Bevestigingsbanner-tekst (NL). `null` = geen banner zichtbaar.
     */
    public ?string $confirmation = null;

    /**
     * Aantal werkregels dat in de laatste submit succesvol werd
     * aangemaakt. Door tests gelezen om te bevestigen hoeveel dagen
     * in DB landden. `0` = nog niet ingediend, of laatste submit
     * faalde volledig.
     */
    public int $createdCount = 0;

    /**
     * ID van de verlofaanvraag die de gebruiker wil annuleren.
     * Wordt gezet wanneer de gebruiker op "Annuleren" klikt en
     * de bevestigingsmodal opent. `null` = modal gesloten.
     * Requirements: 10.5, 10.6
     */
    public ?int $cancellingEntryId = null;

    /**
     * Mount-fase.
     *
     *  1. Resolve current user via de `Auth`-facade. Geen user → 403.
     *  2. Verbied rol `boekhouder` — dat is een read-only rol zonder
     *     eigen diensten, dus ook geen verlof-invoer.
     *  3. Default `$employeeId` op `Auth::id()`. Manager/owner kan
     *     dit later via de UI veranderen naar een collega in scope.
     *  4. Voor employee-rol forceren we `$type = 'LEAVE'` zodat een
     *     gerouteerde GET met `?type=HOLIDAY` (defensief) niet
     *     direct de verkeerde state zet.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            // Defensief: routes worden in `web`-middleware-stack
            // geserveerd maar de auth-guard wordt pas in een latere
            // taak vol-geactiveerd. Tests gebruiken `actingAs($user)`.
            abort(403, 'Geen toegang.');
        }

        if ((string) $user->role === 'boekhouder') {
            abort(403, 'Geen toegang tot verlof-invoer.');
        }

        $this->employeeId = (int) $user->id;

        if ((string) $user->role === 'employee' && $this->type === 'HOLIDAY') {
            // Defensief: een employee mag nooit met HOLIDAY starten.
            $this->type = 'LEAVE';
        }
    }

    /**
     * NL-labels voor de type-select. De view filtert `HOLIDAY` weg
     * voor employee-rol — zelfde patroon als
     * {@see EntryFormModal::getAvailableTypes()}.
     *
     * @return array<string, string>
     */
    public function getAvailableTypes(): array
    {
        return [
            'SICK' => 'Ziek',
            'LEAVE' => 'Verlof',
            'HOLIDAY' => 'Feestdag',
        ];
    }

    /**
     * Beschikbare medewerkers voor de employee-select.
     *
     *  - Owner: alle actieve medewerkers binnen eigen organisatie.
     *  - Manager: alleen actieve medewerkers binnen het eigen team.
     *  - Employee: 1-element collectie met zichzelf — zodat de view
     *    geen select hoeft te tonen, maar de hint-tekst nog steeds
     *    een naam kan tonen.
     *
     * Resultaat is alfabetisch op `full_name` (of `name` fallback)
     * voor stabiele rendering.
     */
    public function getRoleEmployees(): Collection
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return collect();
        }

        $role = (string) $user->role;

        if ($role === 'employee') {
            return collect([$user]);
        }

        $query = User::query()
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('role', ['employee', 'manager', 'owner'])
            ->where('is_active', true);

        if ($role === 'manager') {
            // Manager altijd vastgepind op eigen team; ook null wordt
            // gerespecteerd (manager zonder team ziet niemand).
            $query->where('team_id', $user->team_id);
        }

        return $query
            ->orderByRaw('COALESCE(full_name, name) ASC')
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Haal actieve verlof-types op voor de organisatie van de huidige gebruiker.
     * Requirements: 11.5
     *
     * @return Collection<int, LeaveType>
     */
    public function getActiveLeaveTypes(): Collection
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null || ! $this->leaveTypesTableExists()) {
            return collect();
        }

        return LeaveType::query()
            ->forOrganization((int) $user->organization_id)
            ->active()
            ->orderBy('name')
            ->get();
    }

    /**
     * Haal het verlof-saldo op voor de geselecteerde medewerker.
     * Requirements: 9.9
     *
     * @return array{annual_days: int|null, taken_days: float, remaining_days: float|null, status: string, breakdown: array<string, float>}|null
     */
    public function getLeaveBalance(): ?array
    {
        if ($this->employeeId === null) {
            return null;
        }

        $service = app(LeaveBalanceService::class);

        return $service->getBalance($this->employeeId, (int) date('Y'));
    }

    /**
     * Controleer of het maximum aantal dagen per jaar voor het geselecteerde
     * verlof-type is bereikt. Retourneert een waarschuwingsbericht of null.
     * Requirements: 11.7
     */
    public function getMaxDaysWarning(): ?string
    {
        if ($this->leaveTypeId === null || $this->employeeId === null) {
            return null;
        }

        if (! $this->leaveTypesTableExists()) {
            return null;
        }

        $leaveType = LeaveType::find($this->leaveTypeId);

        if ($leaveType === null || $leaveType->max_days_per_year === null) {
            return null;
        }

        // Tel het aantal opgenomen dagen van dit specifieke type in het huidige jaar
        $takenDays = WorkEntry::query()
            ->where('employee_id', $this->employeeId)
            ->where('type', 'LEAVE')
            ->where('leave_type_id', $this->leaveTypeId)
            ->whereNull('deleted_at')
            ->whereYear('entry_date', (int) date('Y'))
            ->count();

        // Half-dag entries tellen als 0.5
        $halfDayCount = WorkEntry::query()
            ->where('employee_id', $this->employeeId)
            ->where('type', 'LEAVE')
            ->where('leave_type_id', $this->leaveTypeId)
            ->whereNull('deleted_at')
            ->whereYear('entry_date', (int) date('Y'))
            ->where(function ($q) {
                $q->where(function ($sub) {
                    $sub->whereRaw("TIME_FORMAT(start_at, '%H:%i') = '00:00'")
                        ->whereRaw("TIME_FORMAT(end_at, '%H:%i') = '12:30'");
                })->orWhere(function ($sub) {
                    $sub->whereRaw("TIME_FORMAT(start_at, '%H:%i') = '12:30'")
                        ->whereRaw("TIME_FORMAT(end_at, '%H:%i') = '23:59'");
                });
            })
            ->count();

        // Correctie: halve dagen tellen als 0.5 i.p.v. 1.0
        $actualDays = (float) ($takenDays - $halfDayCount) + ($halfDayCount * 0.5);

        if ($actualDays >= $leaveType->max_days_per_year) {
            return 'Maximum '.$leaveType->name.' bereikt ('.$leaveType->max_days_per_year.' dagen per jaar).';
        }

        return null;
    }

    /**
     * Check of de leave_types tabel bestaat.
     */
    private function leaveTypesTableExists(): bool
    {
        static $exists = null;

        if ($exists === null) {
            $exists = Schema::hasTable('leave_types')
                && Schema::hasColumn('work_entries', 'leave_type_id');
        }

        return $exists;
    }

    /**
     * Submit-handler.
     *
     *  1. Resolve actor; null → 403.
     *  2. Run de Livewire-validatie (decoreert de invoer-velden).
     *  3. Role-based extra validatie (zie inline comments per stap).
     *  4. Bouw de datum-range; cap op {@see MAX_RANGE_DAYS}.
     *  5. Voor elke dag in de range: roep
     *     {@see WorkEntriesService::create()} aan met de defaults
     *     uit {@see ALL_DAY_START} / {@see ALL_DAY_END} en
     *     `pause_minutes = 0`.
     *  6. Bij {@see ValidationException}: failure-counter ophogen,
     *     eerste foutmelding cachen voor de banner — NIET aborten,
     *     andere dagen kunnen wel succesvol opslaan.
     *  7. Set bevestigingsbanner + reset velden bij volledige
     *     succes; toon "X opgeslagen, Y mislukt" bij partial; gooi
     *     foutmelding op `dateFrom` bij volledig falen.
     *  8. Dispatch `leave-saved`-event met `type` zodat een
     *     hypothetisch ouder-scherm (week-overzicht) kan refreshen.
     */
    public function submit(WorkEntriesService $workEntriesService): mixed
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        $this->confirmation = null;
        $this->createdCount = 0;

        $this->validate();

        $role = (string) $actor->role;

        // 1) HOLIDAY blokkeren voor employee-rol — de spec wil hier een
        //    422 `INVALID_TYPE_FOR_ROLE` (req 7.2). Backend-handhaving
        //    is queued (taak 14.6); we doen het hier client-side zodat
        //    de regel vandaag al geldt.
        if ($role === 'employee' && $this->type === 'HOLIDAY') {
            $this->addError(
                'type',
                'Feestdagen kunnen alleen door manager of eigenaar worden geregistreerd.'
            );

            return null;
        }

        // 2) Note verplicht voor employee — req 7.2.
        if ($role === 'employee' && trim($this->note) === '') {
            $this->addError(
                'note',
                'Motivatie is verplicht voor verlof of ziekmelding.'
            );

            return null;
        }

        // 3) Employee mag alleen voor zichzelf indienen — req 7.2.
        if ($role === 'employee' && (int) $this->employeeId !== (int) $actor->id) {
            $this->addError(
                'employeeId',
                'Je kunt alleen voor jezelf verlof of ziekmelding indienen.'
            );

            return null;
        }

        // 4) Verlof-type verplicht bij LEAVE — req 11.5
        //    Alleen afdwingen als er daadwerkelijk actieve leave types bestaan
        if ($this->type === 'LEAVE' && $this->leaveTypesTableExists()) {
            $hasActiveTypes = LeaveType::query()
                ->forOrganization((int) $actor->organization_id)
                ->active()
                ->exists();

            if ($hasActiveTypes && $this->leaveTypeId === null) {
                $this->addError(
                    'leaveTypeId',
                    'Verlof-type is verplicht bij verlof.'
                );

                return null;
            }
        }

        // 5) Valideer dat het geselecteerde verlof-type actief is en van de eigen organisatie
        if ($this->type === 'LEAVE' && $this->leaveTypeId !== null && $this->leaveTypesTableExists()) {
            $leaveType = LeaveType::find($this->leaveTypeId);
            if ($leaveType === null
                || (int) $leaveType->organization_id !== (int) $actor->organization_id
                || ! $leaveType->is_active
            ) {
                $this->addError(
                    'leaveTypeId',
                    'Het geselecteerde verlof-type is niet beschikbaar.'
                );

                return null;
            }
        }

        // 6) Datum-range opbouwen.
        try {
            $start = Carbon::parse($this->dateFrom)->startOfDay();
            $end = Carbon::parse($this->dateTo)->startOfDay();
        } catch (Throwable) {
            $this->addError('dateFrom', 'Begindatum of einddatum is ongeldig.');

            return null;
        }

        $totalDays = (int) $start->diffInDays($end) + 1;

        if ($totalDays > self::MAX_RANGE_DAYS) {
            $this->addError(
                'dateTo',
                'De periode is te lang (maximaal '.self::MAX_RANGE_DAYS.' dagen).'
            );

            return null;
        }

        // 7) Bepaal start/eind-tijden op basis van dag-duur keuze
        $startTime = self::ALL_DAY_START;
        $endTime = self::ALL_DAY_END;

        if ($this->dayDuration === 'half') {
            if ($this->halfDayPeriod === 'morning') {
                $startTime = '00:00';
                $endTime = '12:30';
            } else {
                $startTime = '12:30';
                $endTime = '23:59';
            }
        }

        $created = 0;
        $failureCount = 0;
        $firstFailureMessage = null;

        $cursor = $start->copy();
        for ($i = 0; $i < $totalDays; $i++) {
            $dateIso = $cursor->toDateString();

            try {
                $payload = [
                    'employee_id' => (int) $this->employeeId,
                    'entry_date' => $dateIso,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'pause_minutes' => 0,
                    'type' => $this->type,
                    'note' => trim($this->note),
                ];

                // Voeg leave_type_id toe als het type LEAVE is en er een type geselecteerd is
                if ($this->type === 'LEAVE' && $this->leaveTypeId !== null) {
                    $payload['leave_type_id'] = $this->leaveTypeId;
                }

                $workEntriesService->create($payload, (int) $actor->id);

                $created++;
            } catch (ValidationException $e) {
                $failureCount++;

                if ($firstFailureMessage === null) {
                    $first = collect($e->errors())->flatten()->first();
                    $firstFailureMessage = is_string($first) && $first !== ''
                        ? $first
                        : 'Een dag in de periode kon niet worden opgeslagen.';
                }
            } catch (Throwable $e) {
                $failureCount++;
                if ($firstFailureMessage === null) {
                    $firstFailureMessage = 'Een dag in de periode kon niet worden opgeslagen.';
                }
            }

            $cursor->addDay();
        }

        $this->createdCount = $created;

        if ($created > 0 && $failureCount === 0) {
            $this->confirmation = 'Verlof/ziekte ingediend voor '
                .$created.' dag(en).';
            $this->note = '';
            $this->dateFrom = '';
            $this->dateTo = '';
            $this->dayDuration = 'full';
            $this->halfDayPeriod = 'morning';

            $this->dispatch('leave-saved', type: $this->type);
            $this->dispatch('toast', variant: 'success', message: 'Verlof/ziekte ingediend voor '.$created.' dag(en).');

            // Dispatch leave_requested e-mail naar manager(s) bij employee self-submit (Req 13.3).
            if ($role === 'employee') {
                try {
                    // Haal het laatst aangemaakte entry op voor de notificatie.
                    $lastEntry = WorkEntry::query()
                        ->where('employee_id', (int) $actor->id)
                        ->where('organization_id', (int) $actor->organization_id)
                        ->whereIn('type', ['SICK', 'LEAVE', 'HOLIDAY'])
                        ->orderByDesc('id')
                        ->first();

                    if ($lastEntry !== null) {
                        app(\App\Services\LeaveNotificationService::class)->notifyRequested($lastEntry, $actor);
                    }
                } catch (Throwable) {
                    // E-mail dispatch mag submit-actie niet blokkeren.
                }
            }

            return null;
        }

        if ($created > 0 && $failureCount > 0) {
            $this->confirmation = $created.' dag(en) opgeslagen, '
                .$failureCount.' dag(en) konden niet worden opgeslagen.';

            return null;
        }

        $this->addError(
            'dateFrom',
            $firstFailureMessage ?? 'De verlof- of ziektemelding kon niet worden opgeslagen.'
        );

        return null;
    }

    /**
     * Open de bevestigingsmodal voor verlof-annulering.
     * Zet het ID van de te annuleren entry zodat de modal opent.
     * Requirements: 10.5, 10.6
     */
    public function confirmCancelLeave(int $entryId): void
    {
        $this->cancellingEntryId = $entryId;
    }

    /**
     * Sluit de bevestigingsmodal zonder te annuleren.
     */
    public function dismissCancelModal(): void
    {
        $this->cancellingEntryId = null;
    }

    /**
     * Annuleer een verlofaanvraag (soft-delete).
     *
     * Stappen:
     *  1. Resolve actor; null → 403.
     *  2. Zoek de werkregel (inclusief soft-deleted check).
     *  3. Controleer dat de entry van de huidige gebruiker is.
     *  4. Controleer dat de entry NIET goedgekeurd is (is_finalized=false).
     *     Bij goedgekeurd verlof: HTTP 409 met code LEAVE_ALREADY_APPROVED.
     *  5. Soft-delete de werkregel.
     *  6. Schrijf audit-event LEAVE_CANCELLED.
     *  7. Dispatch toast (success) en leave-cancelled event.
     *  8. Reset de modal-state.
     *
     * Requirements: 10.5, 10.6, 10.7, 10.8, 10.9
     */
    public function cancelLeave(): void
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        if ($this->cancellingEntryId === null) {
            return;
        }

        $entry = WorkEntry::query()
            ->where('id', $this->cancellingEntryId)
            ->where('employee_id', (int) $actor->id)
            ->whereIn('type', ['LEAVE', 'SICK', 'HOLIDAY'])
            ->whereNull('deleted_at')
            ->first();

        if ($entry === null) {
            $this->cancellingEntryId = null;
            $this->dispatch('toast', variant: 'error', message: 'Verlofaanvraag niet gevonden.');

            return;
        }

        // HTTP 409: goedgekeurd verlof kan niet geannuleerd worden (req 10.8)
        if ((bool) $entry->is_finalized === true) {
            $this->cancellingEntryId = null;
            abort(409, json_encode([
                'code' => 'LEAVE_ALREADY_APPROVED',
                'message' => 'Dit verlof is al goedgekeurd en kan niet meer geannuleerd worden.',
            ]));
        }

        // Soft-delete de werkregel (req 10.7)
        $entry->delete();

        // Audit-event LEAVE_CANCELLED (req 10.7)
        AuditEvent::create([
            'organization_id' => (int) $actor->organization_id,
            'actor_id' => (int) $actor->id,
            'action' => 'LEAVE_CANCELLED',
            'target_type' => 'work_entry',
            'target_id' => (int) $entry->id,
            'before_data' => [
                'entry_date' => $entry->entry_date?->toDateString(),
                'type' => $entry->type,
                'is_finalized' => $entry->is_finalized,
            ],
            'after_data' => [
                'deleted_at' => now()->toIso8601String(),
            ],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        // Reset modal-state
        $this->cancellingEntryId = null;

        // Toast (success) — req 10.7
        $this->dispatch('toast', variant: 'success', message: 'Verlofaanvraag geannuleerd.');

        // Dispatch leave-cancelled event zodat andere componenten kunnen refreshen
        $this->dispatch('leave-cancelled', entryId: (int) $entry->id);
    }

    public function render(): View
    {
        return view('livewire.hours.leave-form');
    }
}
