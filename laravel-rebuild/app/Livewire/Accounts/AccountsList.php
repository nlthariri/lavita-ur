<?php

declare(strict_types=1);

namespace App\Livewire\Accounts;

use App\Livewire\Hours\WeekOverviewTable;
use App\Livewire\Reports\Filters;
use App\Models\AuthSession;
use App\Models\Team;
use App\Models\User;
use App\Services\AccountProvisioningService;
use App\Services\AuditService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Livewire-component — `Accounts\AccountsList` (taak 12.3 spec lavita-urenregistratie).
 *
 * Bron:
 *  - requirements.md 6.8  → scherm "Accountbeheer" waarop owner/manager
 *      accounts aanmaken, rollen toewijzen, activeren/deactiveren en bij
 *      owner ook softdelete kunnen.
 *  - requirements.md 6.13 → WCAG 2.1 AA, mobile-first, design tokens uit
 *      `design.md`.
 *  - requirements.md 6.14 → NL-labels en NL-foutmeldingen (NFR-10).
 *  - requirements.md 10.1 → owner mag soft-deleten / pseudonimiseren;
 *      wordt op deze plek alleen voorbereid via een UI-stub omdat de
 *      backend-implementatie (`users.deleted_at`-kolom +
 *      `RetentionService::pseudonymize`) pas in taak 17.x landt.
 *  - design.md § Components and Interfaces > Frontend componenten →
 *      Scherm "Accountbeheer" → componenten `Accounts\List`,
 *      `Accounts\Form` op `/accounts`.
 *  - tasks.md 12.3.
 *
 * Verantwoordelijkheid:
 *  - Lijst-/zoekoverzicht van actieve én inactieve accounts binnen de
 *    eigen organisatie. Per rij rendert de Blade naam, e-mail, rol,
 *    team en een statusbadge plus drie acties: bewerken, activeren/
 *    deactiveren-toggle en (alleen voor owner) een soft-delete-knop.
 *  - Bewerken en aanmaken openen het ingebedde
 *    {@see AccountForm}-modaal door het
 *    `open-account-form`-event te dispatchen. Het AccountForm-component
 *    luistert daarop en haalt zelf de user op (of opent leeg in
 *    create-modus).
 *  - Activeren/deactiveren is een directe Eloquent-toggle op
 *    `users.is_active` met scope-checks (org + manager-team-pin).
 *    Forbid: jezelf deactiveren is niet toegestaan — owner zou anders
 *    zichzelf kunnen uitsluiten.
 *  - Soft-delete is een UI-stub: er is nog geen `users.deleted_at`-kolom
 *    en geen `RetentionService::pseudonymize`-methode, dus we tonen
 *    een NL-bevestiging dat de functie geactiveerd wordt zodra de
 *    retentie-module live is (taak 17.x). Wanneer die taak landt
 *    hoeft alleen {@see softDeletePlaceholder()} te worden vervangen
 *    door een echte service-call; de view en het button-pad blijven
 *    hetzelfde.
 *
 * Spec-deviation — direct Eloquent voor edit/toggle i.p.v. service:
 *  Het taakprompt schrijft expliciet voor dat de Livewire-form's edit-
 *  pad via directe Eloquent-`User::update()` werkt, met scope-checks ín
 *  de Livewire-component, niet via {@see AccountProvisioningService}.
 *  Reden: de bestaande service biedt enkel een `create`-methode
 *  (welkomstmail-flow) en geen `update`/`activate`/`softDelete`. Het is
 *  buiten de scope van taak 12.3 om die service uit te breiden.
 *  Validatie-grenzen die de service in `create` afdwingt
 *  (organisatie-eigenaar-scope, manager-team-pin) worden hier expliciet
 *  herhaald om dezelfde autorisatie-garantie te bieden.
 *
 * Bewust niet:
 *  - Geen route-registratie in `routes/web.php` — wordt opgenomen in
 *    een latere taak (sectie 13 of een interim-taak voor /accounts-
 *    routes), zelfde patroon als bij {@see WeekOverviewTable}
 *    en {@see Filters}.
 *  - Geen pagineerlogica — voor een kleine MKB-organisatie is de
 *    user-set te overzien; bij groei kan `paginate()` worden ingebouwd
 *    zonder API-wijzigingen.
 *  - Geen real-time updates via Livewire's `wire:poll` — refresh gebeurt
 *    via `account-saved`-event vanaf de form.
 *  - Geen real soft-delete-flow (zie taak 17.x).
 *
 * Design-token-discipline (NFR-4):
 *  - UI bouwt op `<x-ui.button>`, `<x-ui.card>`, `<x-ui.text-input>`
 *    en `<x-ui.status-badge>`.
 *  - Bewuste deviation naar native `<select>` voor de twee filter-
 *    selects (rol/status) — `<x-ui.text-input>` levert geen `type=select`-
 *    mode. Zelfde rationale als in
 *    {@see WeekOverviewTable} en
 *    {@see Filters}.
 */
#[Layout('layouts.app')]
#[Title('Accountbeheer — LaVita Urenregistratie')]
final class AccountsList extends Component
{
    /**
     * Vrije zoektekst — case-insensitive match op `name`, `full_name`
     * en `email`. Wordt door de view gekoppeld aan
     * `wire:model.live.debounce.250ms` zodat typen direct filtert
     * zonder elke toetsaanslag een SQL-query te triggeren.
     */
    public string $search = '';

    /**
     * Optionele rol-filter. `null` = alle rollen; geldige waarden
     * volgen `users.role`-domein. Boekhouder verschijnt hier ook —
     * een owner moet het account immers kunnen zien en bewerken,
     * ook al verschijnt boekhouder niet als rij in
     * {@see WeekOverviewTable}.
     */
    public ?string $roleFilter = null;

    /**
     * Optionele status-filter. `null` = alle statussen; geldige waarden
     * zijn `active` of `inactive`. Mapping naar `users.is_active`:
     *   active   → is_active = true
     *   inactive → is_active = false
     */
    public ?string $statusFilter = null;

    /**
     * Naam van de organisatie van de ingelogde gebruiker. Wordt in de
     * header van de view getoond; cachen we als property zodat we 'm
     * niet bij elke render opnieuw via een relation moeten resolven.
     */
    public string $organizationName = '';

    /**
     * Optionele NL-bevestigingsmelding boven de lijst — bv. de
     * placeholder-tekst die {@see softDeletePlaceholder()} zet wanneer
     * een owner op de soft-delete-knop drukt zolang de retentie-module
     * nog niet live is (taak 17.x). `null` betekent "geen bevestiging
     * tonen".
     */
    public ?string $confirmation = null;

    /**
     * Mount-fase.
     *
     *  1. Resolve current user via de `Auth`-facade. Geen user → 403.
     *  2. Verbied rol `employee` (zij zien geen accountbeheer) en rol
     *     `boekhouder` (read-only — req 3, en de spec van 12.3 noemt
     *     expliciet owner/manager als doelgroep).
     *  3. Cache `$organizationName` voor de header.
     */
    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            // Defensief: routes draaien in `web`-middleware-stack maar de
            // auth-guard wordt pas in een latere taak vol-geactiveerd.
            // Tests gebruiken `$this->actingAs($user)` zodat dit pad alleen
            // wordt geraakt door anonieme requests in productie.
            abort(403, 'Geen toegang.');
        }

        $role = (string) $user->role;
        if ($role === 'employee') {
            abort(403, 'Geen toegang tot accountbeheer.');
        }
        if ($role === 'boekhouder') {
            // Boekhouder is read-only over alle non-GET (req 3.3..3.6).
            // Accountbeheer is een schrijf-scherm; we weigeren toegang.
            abort(403, 'Geen toegang tot accountbeheer.');
        }

        $this->organizationName = (string) ($user->organization?->name ?? '');
    }

    /**
     * Bouw de lijst van accounts binnen de huidige scope + filters.
     *
     * Filter-regels:
     *  - `organization_id` = die van de actieve gebruiker.
     *  - manager → vast op eigen `team_id` plus de manager zelf
     *    (zodat een manager niet door een eigen toggle z'n eigen
     *    account kwijtraakt op de lijst; ze ziet zichzelf).
     *  - search: case-insensitive match op `name`, `full_name`, `email`.
     *  - rol-filter: zo gezet, anders alle rollen.
     *  - status-filter: `active` → `is_active = true`,
     *    `inactive` → `is_active = false`.
     *  - sorteer op `full_name` ASC, dan `name` ASC voor stabiele volgorde.
     *
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            return collect();
        }

        $query = User::query()
            ->where('organization_id', (int) $actor->organization_id);

        // Manager mag alleen het eigen team zien (plus zichzelf
        // omdat een manager zonder team-rij in de lijst zou verdwijnen
        // als we strikt op `team_id` filteren). Owner ziet alle
        // accounts binnen de organisatie, inclusief boekhouders zonder
        // team_id.
        if ((string) $actor->role === 'manager') {
            $query->where(function ($q) use ($actor): void {
                $q->where('team_id', $actor->team_id)
                    ->orWhere('id', (int) $actor->id);
            });
        }

        // Search — leeg = geen filter. Since full_name and email are
        // encrypted (Laravel encrypted cast), we cannot use SQL LIKE on
        // those columns. For this small MKB app we fetch all users in
        // scope and filter in PHP on decrypted values.
        $search = trim($this->search);

        if ($this->roleFilter !== null && $this->roleFilter !== '') {
            $query->where('role', $this->roleFilter);
        }

        if ($this->statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        $results = $query
            ->with('mfaSecret')
            ->orderBy('name', 'ASC')
            ->get();

        if ($search !== '') {
            $needle = strtolower($search);
            $results = $results->filter(function (User $user) use ($needle): bool {
                return str_contains(strtolower((string) $user->name), $needle)
                    || str_contains(strtolower((string) $user->full_name), $needle)
                    || str_contains(strtolower((string) $user->email), $needle);
            })->values();
        }

        return $results;
    }

    /**
     * Beschikbare teams voor de actor zijn organisatie. Wordt door de
     * view gebruikt om in de tabel het team-naamveld te vullen op basis
     * van het `team_id`-FK-veld (zonder voor elke rij een aparte
     * relation-query af te trappen).
     *
     * @return array<int, string> `[id => name]`-map.
     */
    public function getTeamLookup(): array
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

    /**
     * Toggle `is_active` voor het opgegeven account binnen de scope
     * van de actor.
     *
     * Validatie:
     *  - Account moet bestaan binnen dezelfde organisatie.
     *  - Manager mag alleen accounts in eigen team togglen.
     *  - Manager mag alleen `employee`-accounts togglen (geen owners
     *    of mede-managers): bewust dezelfde regel als
     *    `AccountProvisioningService` voor create.
     *  - Niemand mag zichzelf deactiveren — anders kan een owner
     *    zichzelf uit het systeem werken.
     */
    public function toggleActive(int $userId): void
    {
        $this->confirmation = null;

        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        if ($userId === (int) $actor->id) {
            $this->addError('toggle', 'Je kunt jezelf niet deactiveren.');

            return;
        }

        /** @var User|null $target */
        $target = User::query()
            ->where('id', $userId)
            ->where('organization_id', (int) $actor->organization_id)
            ->first();

        if ($target === null) {
            $this->addError('toggle', 'Account niet gevonden.');

            return;
        }

        if ((string) $actor->role === 'manager') {
            // Manager mag alleen eigen team-employees togglen.
            if ((string) $target->role !== 'employee') {
                $this->addError('toggle', 'Manager kan alleen medewerker-accounts wijzigen.');

                return;
            }

            if ($actor->team_id === null || (int) $target->team_id !== (int) $actor->team_id) {
                $this->addError('toggle', 'Manager kan alleen accounts binnen eigen team wijzigen.');

                return;
            }
        }

        // Toggle is_active. We slaan ook op zodat de nieuwe waarde
        // direct in de database staat — de tabelrij zal in de volgende
        // render zelf een nieuwe statusbadge tonen.
        $previousActive = (bool) $target->is_active;
        $target->is_active = ! $previousActive;
        $target->save();

        // Audit-event voor activering/deactivering (AVG compliance)
        app(AuditService::class)->record([
            'organization_id' => (int) $actor->organization_id,
            'actor_id' => (int) $actor->id,
            'action' => $target->is_active ? 'ACCOUNT_ACTIVATED' : 'ACCOUNT_DEACTIVATED',
            'target_type' => 'user',
            'target_id' => (string) $target->id,
            'before_data' => ['is_active' => $previousActive],
            'after_data' => ['is_active' => (bool) $target->is_active],
        ]);

        // Bij deactivering: revoke alle actieve sessies zodat de
        // gebruiker direct wordt uitgelogd.
        if (! $target->is_active) {
            AuthSession::where('user_id', $target->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        $this->dispatch(
            'account-updated',
            userId: (int) $target->id,
            isActive: (bool) $target->is_active,
        );
    }

    /**
     * Soft-delete een gebruiker. Owner-only.
     *
     * Verwijdert het account via Laravel's SoftDeletes (zet deleted_at).
     * Revoked ook alle actieve sessies zodat de gebruiker direct wordt uitgelogd.
     */
    public function softDelete(int $userId): void
    {
        $this->resetErrorBag();
        $this->confirmation = null;

        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $actor->role !== 'owner') {
            $this->addError('softDelete', 'Alleen eigenaar kan accounts verwijderen.');

            return;
        }

        if ($userId === (int) $actor->id) {
            $this->addError('softDelete', 'Je kunt jezelf niet verwijderen.');

            return;
        }

        /** @var User|null $target */
        $target = User::query()
            ->where('id', $userId)
            ->where('organization_id', (int) $actor->organization_id)
            ->first();

        if ($target === null) {
            $this->addError('softDelete', 'Account niet gevonden.');

            return;
        }

        $displayName = (string) ($target->full_name ?? $target->name ?? '');

        // Revoke alle actieve sessies
        AuthSession::where('user_id', $target->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        // Soft-delete (zet deleted_at)
        $target->is_active = false;
        $target->save();
        $target->delete();

        // Audit
        app(AuditService::class)->record([
            'organization_id' => (int) $actor->organization_id,
            'actor_id' => (int) $actor->id,
            'action' => 'ACCOUNT_DELETED',
            'target_type' => 'user',
            'target_id' => (string) $userId,
        ]);

        $this->confirmation = "Account '{$displayName}' is verwijderd.";
    }

    /**
     * Soft-delete placeholder — UI-stub zolang de retentie-module (taak 17.x)
     * nog niet live is. Toont een NL-bevestiging dat de functie geactiveerd
     * wordt zodra de retentie-module landt. Doet bewust geen DB-mutatie.
     *
     * Alleen owner mag deze actie uitvoeren; manager krijgt een foutmelding.
     */
    public function softDeletePlaceholder(int $userId): void
    {
        $this->resetErrorBag();
        $this->confirmation = null;

        /** @var \App\Models\User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $actor->role !== 'owner') {
            $this->addError('softDelete', 'Alleen eigenaar kan accounts verwijderen.');

            return;
        }

        $this->confirmation = 'Soft-delete wordt geactiveerd zodra de retentie-module live is (taak 17.x). Het account is nog niet gewijzigd.';
    }

    /**
     * Toggle MFA voor het opgegeven account. Owner-only.
     *
     * - Als de user een actieve MFA heeft (verified_at != null, disabled_at == null):
     *   → zet disabled_at = now() (MFA uitschakelen)
     * - Als de user een uitgeschakelde MFA heeft (disabled_at != null):
     *   → zet disabled_at = null (MFA weer inschakelen)
     * - Als de user geen MFA-secret heeft:
     *   → niets te doen, MFA is nog nooit ingesteld
     */
    public function toggleMfa(int $userId): void
    {
        $this->confirmation = null;
        $this->resetErrorBag();

        /** @var User|null $actor */
        $actor = Auth::user();
        if ($actor === null) {
            abort(403, 'Geen toegang.');
        }

        if ((string) $actor->role !== 'owner') {
            $this->addError('toggle', 'Alleen de eigenaar kan MFA beheren.');

            return;
        }

        /** @var User|null $target */
        $target = User::query()
            ->where('id', $userId)
            ->where('organization_id', (int) $actor->organization_id)
            ->first();

        if ($target === null) {
            $this->addError('toggle', 'Account niet gevonden.');

            return;
        }

        $mfaSecret = $target->mfaSecret;

        if ($mfaSecret === null) {
            $this->addError('toggle', 'MFA is nog niet ingesteld voor deze gebruiker. De gebruiker moet eerst zelf MFA instellen via /mfa-setup.');

            return;
        }

        if ($mfaSecret->disabled_at === null) {
            // MFA uitschakelen
            $mfaSecret->update(['disabled_at' => now()]);
            $this->confirmation = 'MFA uitgeschakeld voor ' . ($target->full_name ?? $target->name) . '.';
        } else {
            // MFA weer inschakelen
            $mfaSecret->update(['disabled_at' => null]);
            $this->confirmation = 'MFA ingeschakeld voor ' . ($target->full_name ?? $target->name) . '.';
        }

        // Audit
        app(AuditService::class)->record([
            'organization_id' => (int) $actor->organization_id,
            'actor_id' => (int) $actor->id,
            'action' => $mfaSecret->disabled_at === null ? 'MFA_ENABLED' : 'MFA_DISABLED',
            'target_type' => 'user',
            'target_id' => (string) $target->id,
        ]);
    }

    /**
     * Haal MFA-status op voor een user.
     * Returns: 'active', 'disabled', 'not_configured'
     */
    public function getMfaStatus(User $user): string
    {
        $mfa = $user->mfaSecret;

        if ($mfa === null) {
            return 'not_configured';
        }

        if ($mfa->disabled_at !== null) {
            return 'disabled';
        }

        if ($mfa->verified_at !== null) {
            return 'active';
        }

        return 'not_configured';
    }

    /**
     * Open het AccountForm-modaal in create-modus. De form-component
     * luistert op `open-account-form` zonder userId zodat een verse
     * formulier-state wordt geopend.
     */
    public function openCreate(): void
    {
        $this->dispatch('open-account-form', userId: null);
    }

    /**
     * Open het AccountForm-modaal in edit-modus voor het opgegeven
     * account. De form-component verifieert zelf of de actor het
     * account mag bewerken (manager → eigen team).
     */
    public function openEdit(int $userId): void
    {
        $this->dispatch('open-account-form', userId: $userId);
    }

    /**
     * Listener voor het `account-saved`-event dat de form dispatcht na
     * een succesvolle create/update. We hoeven hier niets actief te
     * doen: de volgende render haalt de getoonde lijst opnieuw op via
     * {@see getUsers()}, dus de wijziging verschijnt vanzelf in de
     * tabel. We gebruiken de listener wel om een NL-bevestiging te
     * tonen ("Account opgeslagen.") zodat de gebruiker een duidelijke
     * UX-acknowledgment krijgt.
     */
    #[On('account-saved')]
    public function onAccountSaved(): void
    {
        $this->confirmation = 'Account opgeslagen.';
    }

    /**
     * NL-labels voor de rol-filter. Geen owner-optie zelf-bewerken-flow
     * hier — alle vier de rollen mogen vrij gefilterd worden, ook al
     * mag een manager via de tabel niet alle rollen togglen.
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
     * NL-labels voor de status-filter.
     *
     * @return array<string, string>
     */
    public function getStatusOptions(): array
    {
        return [
            'active' => 'Actief',
            'inactive' => 'Inactief',
        ];
    }

    /**
     * NL-label voor de rol-kolom in de tabel.
     */
    public function labelForRole(string $role): string
    {
        return match ($role) {
            'owner' => 'Eigenaar',
            'manager' => 'Manager',
            'employee' => 'Medewerker',
            'boekhouder' => 'Boekhouder',
            default => $role,
        };
    }

    public function render(): View
    {
        return view('livewire.accounts.accounts-list');
    }
}
