<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountProvisioningService
{
    private const ALLOWED_CREATOR_ROLES = ['owner', 'manager'];
    private const ALLOWED_TARGET_ROLES = ['manager', 'employee', 'boekhouder'];

    /**
     * Geldigheidsduur (in uren) van de wachtwoord-set-link in de
     * welkomstmail. Komt overeen met `PasswordResetService::TTL_HOURS`
     * (Requirement 5.1).
     */
    private const RESET_LINK_VALID_HOURS = 24;

    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
        private readonly EmailTemplateService $emailTemplateService,
        private readonly PasswordResetService $passwordResetService,
    ) {
    }

    public function create(array $input, int $creatorId): array
    {
        $creator = User::findOrFail($creatorId);

        if (!$creator->is_active) {
            throw ValidationException::withMessages([
                'creator' => 'Inactieve gebruiker mag geen accounts aanmaken.',
            ]);
        }

        if (!$creator->organization_id) {
            throw ValidationException::withMessages([
                'creator' => 'Alleen gebruikers met een geldige organisatie mogen accounts aanmaken.',
            ]);
        }

        if (!in_array((string) $creator->role, self::ALLOWED_CREATOR_ROLES, true)) {
            throw ValidationException::withMessages([
                'creator' => 'Alleen eigenaar of manager kan accounts aanmaken.',
            ]);
        }

        $targetRole = (string) ($input['role'] ?? 'employee');
        if (!in_array($targetRole, self::ALLOWED_TARGET_ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => 'Ongeldige rol voor account-aanmaak.',
            ]);
        }

        $teamId = isset($input['team_id']) ? (int) $input['team_id'] : null;

        if ($creator->role === 'manager') {
            if ($targetRole !== 'employee') {
                throw ValidationException::withMessages([
                    'role' => 'Manager kan alleen medewerker-accounts aanmaken.',
                ]);
            }

            if (!$creator->team_id) {
                throw ValidationException::withMessages([
                    'creator' => 'Manager moet aan een team gekoppeld zijn.',
                ]);
            }

            $managerTeamExists = Team::query()
                ->where('id', (int) $creator->team_id)
                ->where('organization_id', (int) $creator->organization_id)
                ->exists();

            if (!$managerTeamExists) {
                throw ValidationException::withMessages([
                    'creator' => 'Manager-team is ongeldig voor deze organisatie.',
                ]);
            }

            if ($teamId !== null && $teamId !== (int) $creator->team_id) {
                throw ValidationException::withMessages([
                    'team_id' => 'Manager kan alleen accounts binnen eigen team aanmaken.',
                ]);
            }

            $teamId = (int) $creator->team_id;
        } elseif ($teamId !== null) {
            $team = Team::query()
                ->where('id', $teamId)
                ->where('organization_id', (int) $creator->organization_id)
                ->first();

            if (!$team) {
                throw ValidationException::withMessages([
                    'team_id' => 'Team hoort niet bij de organisatie van de account-aanmaker.',
                ]);
            }
        }

        try {
            return DB::transaction(function () use ($input, $creator, $teamId, $targetRole): array {
                $user = User::create([
                    'name' => trim((string) ($input['name'] ?? '')),
                    'full_name' => isset($input['full_name']) ? trim((string) $input['full_name']) : null,
                    'email' => strtolower(trim((string) $input['email'])),
                    // Random initieel wachtwoord — wordt nooit naar de
                    // gebruiker gestuurd, alleen overschreven via de
                    // wachtwoord-reset-link uit de welkomstmail
                    // (Requirement 5.5).
                    'password' => Str::random(40),
                    'organization_id' => (int) $creator->organization_id,
                    'team_id' => $teamId,
                    'role' => $targetRole,
                    'is_active' => (bool) ($input['is_active'] ?? true),
                    'employment_start' => $input['employment_start'] ?? null,
                    'employment_end' => $input['employment_end'] ?? null,
                ]);

                $token = $this->passwordResetService->createToken((int) $user->id);
                $appUrl = rtrim((string) config('app.url', 'https://lavita.nl'), '/');
                $resetLink = $appUrl.'/wachtwoord-reset?token='.urlencode($token);
                $loginUrl = $appUrl.'/inloggen';

                $organizationName = (string) (Organization::query()
                    ->where('id', (int) $creator->organization_id)
                    ->value('name') ?? '');

                $teamName = '';
                if ($teamId !== null) {
                    $teamName = (string) (Team::query()->where('id', $teamId)->value('name') ?? '');
                }

                $vars = [
                    'full_name' => (string) ($user->full_name ?: $user->name),
                    'email' => (string) $user->email,
                    'role' => $targetRole,
                    'organization_name' => $organizationName,
                    // Requirement 5.6: leeg `team_name` mag geen 422 of
                    // onverwerkte placeholder opleveren.
                    'team_name' => $teamName,
                    'login_url' => $loginUrl,
                    'reset_link' => $resetLink,
                    'valid_hours' => (string) self::RESET_LINK_VALID_HOURS,
                ];

                // Render via de centrale template-renderer
                // (Requirement 5.1, 5.3) i.p.v. een inline body.
                $rendered = $this->emailTemplateService->render(
                    'welcome_email',
                    $vars,
                    (int) $creator->organization_id,
                );

                $this->emailOutboxService->dispatch([
                    'idempotency_key' => 'welcome-email-'.$user->id,
                    'organization_id' => (int) $creator->organization_id,
                    'user_id' => (int) $user->id,
                    'recipient' => (string) $user->email,
                    'subject' => $rendered['subject'],
                    'body_text' => $rendered['body_text'],
                    'body_html' => $rendered['body_html'],
                    'type' => 'welcome_email',
                ], [
                    'actor_id' => (int) $creator->id,
                    'organization_id' => (int) $creator->organization_id,
                ]);

                return [
                    'id' => (int) $user->id,
                    'email' => (string) $user->email,
                    'role' => (string) $user->role,
                    'organization_id' => (int) $user->organization_id,
                    'team_id' => $user->team_id !== null ? (int) $user->team_id : null,
                    'is_active' => (bool) $user->is_active,
                    'onboarding_email_queued' => true,
                ];
            });
        } catch (ValidationException $e) {
            // Validatie-fouten (bv. recipient hoort niet bij organisatie)
            // mogen als 422 doorlopen — niet als 500 verpakken.
            throw $e;
        } catch (\Throwable $e) {
            // Requirement 5.4: bij outbox-/render-fout de hele transactie
            // terugdraaien (DB::transaction heeft dit reeds gedaan
            // doordat de exception escaleerde) en HTTP 500 met code
            // `WELCOME_EMAIL_FAILED` retourneren via de controller.
            throw new \RuntimeException('WELCOME_EMAIL_FAILED', 0, $e);
        }
    }
}
