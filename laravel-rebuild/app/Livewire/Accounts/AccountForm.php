<?php

declare(strict_types=1);

namespace App\Livewire\Accounts;

use App\Livewire\Hours\EntryFormModal;
use App\Models\Team;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Services\AuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Livewire-component — `Accounts\AccountForm` (taak 12.3 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.8  → scherm "Accountbeheer" met create/edit
 *      formulier. Owner/manager kunnen accounts aanmaken; rollen
 *      toewijzen, activeren/deactiveren.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens.
 *  - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Accountbeheer" → componenten `Accounts\List`,
 *      `Accounts\Form` op `/accounts`.
 *  - tasks.md 12.3.
 *
 * Verantwoordelijkheid:
 *  - Modal-formulier voor create én edit van een account binnen de
 *    eigen organisatie.
 *  - Wordt geactiveerd door event `open-account-form` (gedispatcht door
 *    {@see AccountsList}). Sluit op event `account-saved` zodat de
 *    parent-lijst kan refreshen.
 *  - Create-pad delegeert aan {@see AccountProvisioningService::create()}
 *    zodat de welkomstmail-flow én alle role/team-validaties uit één
 *    plek komen (req 5.x).
 *  - Edit-pad gebruikt directe Eloquent — zie deviation-note hieronder.
 *
 * Spec-deviation — direct Eloquent voor edit i.p.v. service:
 *  Het taakprompt schrijft expliciet voor dat de edit-pad via directe
 *  `User::update()` werkt met scope-checks ín de Livewire-component.
 *  Reden: {@see AccountProvisioningService} biedt enkel `create`. Het
 *  uitbreiden van die service met `update` valt buiten scope van taak
 *  12.3. We herhalen daarom dezelfde scope-grenzen die de service in
 *  `create` afdwingt:
 *   - account moet binnen dezelfde organisatie liggen;
 *   - manager mag alleen `employee`-accounts in eigen team bewerken;
 *   - owner kan niet via dit formulier worden gedemoteerd (rol-wijziging
 *     `owner → *` blokkeert) — de spec verbiedt dit expliciet.
 *
 * Bewust niet:
 *  - Geen wachtwoord-reset-flow vanuit dit formulier — accounts maken
 *    een wachtwoord aan via de welkomstmail-link (req 5.5). Een edit
 *    overschrijft het wachtwoord nooit.
 *  - Geen route-registratie — leeft binnen `AccountsList` en wordt via
 *    `<livewire:accounts.account-form />` ingebed, naar het patroon van
 *    {@see EntryFormModal}.
 *  - Geen activate/deactivate-toggle — die zit in {@see AccountsList}.
 *  - Geen soft-delete — UI-stub op {@see AccountsList} (taak 17.x).
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt op `<x-ui.button>`, `<x-ui.card>`, `<x-ui.text-input>`.
 *  - Bewuste deviation naar native `<select>` voor rol/team. Zelfde
 *    rationale als in de andere modal-componenten.
 */
#[Layout('layouts.app')]
#[Title('Account-formulier — LaVita Urenregistratie')]
final class AccountForm extends Component
{
    /**
     * Modal-zichtbaarheid. Wanneer `false` rendert de view een lege
     * wrapper zodat het component op pagina aanwezig is en kan luisteren
     * naar het open-event, zonder backdrop of formulier in de DOM.
     */
    public bool $isOpen = false;

    /**
     * `null` = create-modus, anders is dit de id van de te bewerken
     * gebruiker. Wordt door {@see openModal()} gevuld vanuit het
     * `open-account-form`-event.
     */
    public ?int $userId = null;

    /**
     * Korte naam (`users.name`) — verplicht bij create én edit.
     */
    #[Validate(
        rule: 'required|string|max:100',
        message: [
            'name.required' => 'Naam is verplicht.',
            'name.string' => 'Naam moet een tekstwaarde zijn.',
            'name.max' => 'Naam mag maximaal 100 tekens lang zijn.',
        ],
        attribute: ['name' => 'naam'],
        translate: false,
    )]
    public string $name = '';

    /**
     * Volledige naam (`users.full_name`) — optioneel, max 100 tekens.
     */
    #[Validate(
        rule: 'nullable|string|max:100',
        message: [
            'fullName.string' => 'Volledige naam moet een tekstwaarde zijn.',
            'fullName.max' => 'Volledige naam mag maximaal 100 tekens lang zijn.',
        ],
        attribute: ['fullName' => 'volledige naam'],
        translate: false,
    )]
    public string $fullName = '';

    /**
     * E-mailadres (`users.email`) — verplicht, max 254 tekens conform
     * RFC 5321. Backend-side wordt het naar lowercase genormaliseerd.
     */
    #[Validate(
        rule: 'required|email:rfc|max:254',
        message: [
            'email.required' => 'E-mailadres is verplicht.',
            'email.email' => 'E-mailadres heeft geen geldig formaat.',
            'email.max' => 'E-mailadres mag maximaal 254 tekens lang zijn.',
        ],
        attribute: ['email' => 'e-mailadres'],
        translate: false,
    )]
    public string $email = '';

    /**
     * Rol (`users.role`). Geldige waarden: owner|manager|employee|
     * boekhouder. Op create-pad wordt `owner` afgewezen door de
     * service-laag (creatie van eigenaars valt buiten dit formulier).
     * Op edit-pad blokkeren we `owner → *` (geen demote via dit form).
     */
    #[Validate(
        rule: 'required|in:owner,manager,employee,boekhouder',
        message: [
            'role.required' => 'Rol is verplicht.',
            'role.in' => 'Rol moet eigenaar, manager, medewerker of boekhouder zijn.',
        ],
        attribute: ['role' => 'rol'],
        translate: false,
    )]
    public string $role = 'employee';

    /**
     * Team-id (`users.team_id`) — optioneel. Boekhouders mogen géén
     * team hebben (req 3.8). Backend-side wordt op org-grens
     * gevalideerd.
     */
    #[Validate(
        rule: 'nullable|integer',
        message: [
            'teamId.integer' => 'Team-id is ongeldig.',
        ],
        attribute: ['teamId' => 'team'],
        translate: false,
    )]
    public ?int $teamId = null;

    /**
     * Active-vlag (`users.is_active`). Default `true` zodat een
     * nieuw aangemaakt account direct kan inloggen.
     */
    #[Validate(
        rule: 'boolean',
        message: [
            'isActive.boolean' => 'Status moet actief of inactief zijn.',
        ],
        attribute: ['isActive' => 'status'],
        translate: false,
    )]
    public bool $isActive = true;

    /**
     * E-mail herinneringen opt-in (`users.email_reminders_opt_in`).
     * Default `true` — AVG opt-out per gebruiker (req 9.1, 9.6).
     */
    #[Validate(
        rule: 'boolean',
        message: [
            'emailRemindersOptIn.boolean' => 'E-mail herinneringen moet aan of uit zijn.',
        ],
        attribute: ['emailRemindersOptIn' => 'e-mail herinneringen'],
        translate: false,
    )]
    public bool $emailRemindersOptIn = true;

    /**
     * Indiensttreding (`users.employment_start`). Optioneel,
     * `Y-m-d`-formaat.
     */
    #[Validate(
        rule: 'nullable|date_format:Y-m-d',
        message: [
            'employmentStart.date_format' => 'Indiensttreding moet in het formaat JJJJ-MM-DD staan.',
        ],
        attribute: ['employmentStart' => 'indiensttreding'],
        translate: false,
    )]
    public ?string $employmentStart = null;

    /**
     * Uitdiensttreding (`users.employment_end`). Optioneel,
     * `Y-m-d`-formaat. Mag niet vóór `employmentStart` liggen.
     */
    #[Validate(
        rule: 'nullable|date_format:Y-m-d|after_or_equal:employmentStart',
        message: [
            'employmentEnd.date_format' => 'Uitdiensttreding moet in het formaat JJJJ-MM-DD staan.',
            'employmentEnd.after_or_equal' => 'Uitdiensttreding mag niet vóór de indiensttreding liggen.',
        ],
        attribute: ['employmentEnd' => 'uitdiensttreding'],
        translate: false,
    )]
    public ?string $employmentEnd = null;

    /**
     * Optionele NL-bevestigingsmelding boven het formulier (bv.
     * "Account opgeslagen."). De `closeModal()` reset 'm naar `null`.
     */
    public ?string $confirmation = null;

    /**
     * Default-waarden voor alle invoer-velden, zodat we ze in
     * {@see openModal()} en {@see closeModal()} consistent kunnen
     * herstellen. Per kolom is dit identiek aan de class-property-default.
     *
     * @var array<string, mixed>
     */
    private const FIELD_DEFAULTS = [
        'name' => '',
        'fullName' => '',
        'email' => '',
        'role' => 'employee',
        'teamId' => null,
        'isActive' => true,
        'emailRemindersOptIn' => true,
        'employmentStart' => null,
        'employmentEnd' => null,
    ];

    /**
     * Listener voor het `open-account-form`-event vanuit
     * {@see AccountsList}. Reset eerst alle velden naar default,
     * laad daarna eventueel een bestaande user (edit-modus). De
     * scope-check (org + manager-team-pin) gebeurt expliciet om
     * een gerichte 403 te geven bij out-of-scope access.
     */
    #[On('open-account-form')]
    public function openModal(?int $userId = null): void
    {
        $this->resetFieldsToDefaults();
        $this->resetErrorBag();
        $this->confirmation = null;
        $this->userId = $userId;

        if ($userId !== null) {
            /** @var User|null $actor */
            $actor = Auth::user();
            if ($actor === null) {
                abort(403, 'Geen toegang.');
            }

            $target = User::query()
                ->where('id', $userId)
                ->where('organization_id', (int) $actor->organization_id)
                ->first();

            if ($target === null) {
                $this->addError('userId', 'Account niet gevonden.');
                $this->isOpen = false;

                return;
            }

            // Manager scope: alleen employees van eigen team. Het is OK
            // dat een manager z'n eigen account opent (om eigen velden
            // te zien) — maar updaten van rol is niet toegestaan en
            // wordt in {@see submit()} afgevangen.
            if ((string) $actor->role === 'manager'
                && (int) $target->id !== (int) $actor->id) {
                if ((string) $target->role !== 'employee'
                    || $actor->team_id === null
                    || (int) $target->team_id !== (int) $actor->team_id) {
                    $this->addError('userId', 'Manager kan alleen accounts binnen eigen team bewerken.');
                    $this->isOpen = false;

                    return;
                }
            }

            $this->name = (string) $target->name;
            $this->fullName = (string) ($target->full_name ?? '');
            $this->email = (string) $target->email;
            $this->role = (string) $target->role;
            $this->teamId = $target->team_id !== null ? (int) $target->team_id : null;
            $this->isActive = (bool) $target->is_active;
            $this->emailRemindersOptIn = (bool) ($target->email_reminders_opt_in ?? true);
            $this->employmentStart = $target->employment_start !== null
                ? Carbon::parse((string) $target->employment_start)->toDateString()
                : null;
            $this->employmentEnd = $target->employment_end !== null
                ? Carbon::parse((string) $target->employment_end)->toDateString()
                : null;
        }

        $this->isOpen = true;
    }

    /**
     * Sluit de modal en reset alle invoer-state.
     */
    public function closeModal(): void
    {
        $this->isOpen = false;
        $this->userId = null;
        $this->confirmation = null;
        $this->resetFieldsToDefaults();
        $this->resetErrorBag();
    }

    /**
     * Submit-handler — splitst op create vs edit en mapt service-fouten
     * terug naar Livewire-velden.
     */
    public function submit(AccountProvisioningService $service): void
    {
        $this->validate();

        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        if ($this->userId === null) {
            $this->submitCreate($service, $actor);

            return;
        }

        $this->submitEdit($actor);
    }

    /**
     * Create-pad — delegeer aan
     * {@see AccountProvisioningService::create()} zodat alle org-/team-
     * scope-validaties én de welkomstmail-flow van één plek komen.
     */
    private function submitCreate(AccountProvisioningService $service, User $actor): void
    {
        try {
            $service->create([
                'name' => trim($this->name),
                'full_name' => $this->fullName !== '' ? trim($this->fullName) : null,
                'email' => strtolower(trim($this->email)),
                'role' => $this->role,
                'team_id' => $this->teamId,
                'is_active' => $this->isActive,
                'email_reminders_opt_in' => $this->emailRemindersOptIn,
                'employment_start' => $this->employmentStart,
                'employment_end' => $this->employmentEnd,
            ], (int) $actor->id);
        } catch (ValidationException $e) {
            $this->mapServiceErrors($e);

            return;
        }

        $this->confirmation = 'Account aangemaakt.';
        $this->dispatch('account-saved');
        $this->closeModal();
    }

    /**
     * Edit-pad — directe Eloquent-update met scope-checks.
     */
    private function submitEdit(User $actor): void
    {
        $target = User::query()
            ->where('id', (int) $this->userId)
            ->where('organization_id', (int) $actor->organization_id)
            ->first();

        if ($target === null) {
            $this->addError('userId', 'Account niet gevonden.');

            return;
        }

        // Manager-scope-check (zelfde regels als openModal — defensief
        // herhaald omdat de userId zou kunnen zijn aangepast tussen
        // open en submit door client-side manipulatie).
        if ((string) $actor->role === 'manager'
            && (int) $target->id !== (int) $actor->id) {
            if ((string) $target->role !== 'employee'
                || $actor->team_id === null
                || (int) $target->team_id !== (int) $actor->team_id) {
                $this->addError('userId', 'Manager kan alleen accounts binnen eigen team bewerken.');

                return;
            }
        }

        // Geen rol-demote van owner: spec eist dat de owner-rol niet
        // via dit formulier kan worden gewijzigd. Bewust generiek:
        // ook owner→owner wordt simpelweg niet gewijzigd, en
        // owner→manager/employee/boekhouder krijgt een NL-foutmelding.
        if ((string) $target->role === 'owner' && $this->role !== 'owner') {
            $this->addError('role', 'De rol "Eigenaar" kan via dit formulier niet worden gewijzigd.');

            return;
        }

        // Manager mag rollen niet wijzigen (mag alleen employees
        // bewerken, en die houden hun rol). Defensief: silently
        // overschrijven we client-side rolwijziging tenzij de actor
        // owner is.
        if ((string) $actor->role === 'manager' && $this->role !== (string) $target->role) {
            $this->addError('role', 'Manager kan rollen niet wijzigen.');

            return;
        }

        // Team-id moet binnen dezelfde organisatie liggen, of null zijn
        // (bv. boekhouder zonder team). We verifiëren dat hier expliciet
        // i.p.v. te vertrouwen op een nullable-FK-constraint.
        if ($this->teamId !== null) {
            $teamOk = Team::query()
                ->where('id', (int) $this->teamId)
                ->where('organization_id', (int) $actor->organization_id)
                ->exists();
            if (! $teamOk) {
                $this->addError('teamId', 'Team hoort niet bij deze organisatie.');

                return;
            }
        }

        // E-mail-uniciteit binnen de tabel (case-insensitive). Skip
        // eigen rij. We vergelijken op lowercase zodat een hoofdletter-
        // wijziging niet als duplicaat wordt gezien.
        $newEmail = strtolower(trim($this->email));
        $emailTaken = User::query()
            ->whereRaw('LOWER(email) = ?', [$newEmail])
            ->where('id', '!=', (int) $target->id)
            ->exists();
        if ($emailTaken) {
            $this->addError('email', 'Dit e-mailadres is al in gebruik.');

            return;
        }

        $beforeData = [
            'name' => $target->name,
            'email' => $target->email,
            'role' => $target->role,
            'team_id' => $target->team_id,
            'is_active' => $target->is_active,
            'employment_start' => $target->employment_start?->toDateString(),
            'employment_end' => $target->employment_end?->toDateString(),
        ];

        $target->update([
            'name' => trim($this->name),
            'full_name' => $this->fullName !== '' ? trim($this->fullName) : null,
            'email' => $newEmail,
            'role' => $this->role,
            'team_id' => $this->teamId,
            'is_active' => $this->isActive,
            'email_reminders_opt_in' => $this->emailRemindersOptIn,
            'employment_start' => $this->employmentStart,
            'employment_end' => $this->employmentEnd,
        ]);

        // Audit-event voor account-mutatie (AVG compliance)
        app(AuditService::class)->record([
            'organization_id' => (int) $actor->organization_id,
            'actor_id' => (int) $actor->id,
            'action' => 'ACCOUNT_UPDATED',
            'target_type' => 'user',
            'target_id' => (string) $target->id,
            'before_data' => $beforeData,
            'after_data' => [
                'name' => trim($this->name),
                'email' => $newEmail,
                'role' => $this->role,
                'team_id' => $this->teamId,
                'is_active' => $this->isActive,
                'employment_start' => $this->employmentStart,
                'employment_end' => $this->employmentEnd,
            ],
        ]);

        $this->confirmation = 'Account opgeslagen.';
        $this->dispatch('account-saved');
        $this->closeModal();
    }

    /**
     * Beschikbare rol-opties voor de `<select>`. Boekhouder is geldig
     * (req 3.8 — boekhouder zonder team_id). Owner is alleen relevant
     * in edit-modus voor een bestaande owner; we tonen de optie
     * altijd zodat een owner-rij niet onder water naar een lege rol
     * springt.
     *
     * @return array<string, string>
     */
    public function getRoleOptions(): array
    {
        return [
            'owner' => 'Eigenaar',
            'manager' => 'Manager',
            'employee' => 'Medewerker',
            'boekhouder' => 'Boekhouder',
        ];
    }

    /**
     * Beschikbare teams voor de actor zijn organisatie. Alfabetisch
     * geordend zodat de `<select>`-options voorspelbaar zijn.
     *
     * @return array<int, string> `[id => name]`-map.
     */
    public function getTeams(): array
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            return [];
        }

        $rows = Team::where('organization_id', (int) $actor->organization_id)
            ->orderBy('name', 'ASC')
            ->get(['id', 'name']);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->id] = (string) $row->name;
        }

        return $map;
    }

    public function render(): View
    {
        return view('livewire.accounts.account-form');
    }

    /**
     * Reset alle invoer-velden naar default-waarden. Wordt aangeroepen
     * door {@see openModal()} (vóór het laden van een te bewerken user)
     * en {@see closeModal()}.
     */
    private function resetFieldsToDefaults(): void
    {
        foreach (self::FIELD_DEFAULTS as $property => $value) {
            $this->{$property} = $value;
        }
    }

    /**
     * Map service-side ValidationException-fouten naar Livewire-velden.
     * De service gebruikt zowel snake_case (`team_id`,
     * `employment_start`) als generieke keys (`creator`, `role`) als
     * foutkeys, dus we mappen beide naar de juiste UI-veldnaam.
     */
    private function mapServiceErrors(ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            $first = is_array($messages) ? ($messages[0] ?? '') : (string) $messages;

            $target = match ($field) {
                'name' => 'name',
                'full_name' => 'fullName',
                'email' => 'email',
                'role' => 'role',
                'team_id' => 'teamId',
                'is_active' => 'isActive',
                'email_reminders_opt_in' => 'emailRemindersOptIn',
                'employment_start' => 'employmentStart',
                'employment_end' => 'employmentEnd',
                default => 'name',
            };

            $this->addError($target, (string) $first);
        }
    }
}
