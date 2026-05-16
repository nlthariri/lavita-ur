<?php

namespace App\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountProvisioningService
{
    private const ALLOWED_CREATOR_ROLES = ['owner', 'manager'];
    private const ALLOWED_TARGET_ROLES = ['manager', 'employee', 'boekhouder'];

    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
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

        return DB::transaction(function () use ($input, $creator, $teamId, $targetRole): array {
            $user = User::create([
                'name' => trim((string) ($input['name'] ?? '')),
                'full_name' => isset($input['full_name']) ? trim((string) $input['full_name']) : null,
                'email' => strtolower(trim((string) $input['email'])),
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
            $name = $user->full_name ?: $user->name;

            $this->emailOutboxService->dispatch([
                'idempotency_key' => 'account-created-'.$user->id,
                'organization_id' => (int) $creator->organization_id,
                'user_id' => (int) $user->id,
                'recipient' => (string) $user->email,
                'subject' => 'Nieuw account aangemaakt',
                'body_text' => "Beste {$name},\n\nEr is een account voor u aangemaakt in LaVita Urenregistratie.\n"
                    ."Rol: {$targetRole}\n"
                    ."Inloggen met e-mailadres: {$user->email}\n"
                    ."Stel uw wachtwoord in via: {$resetLink}\n\n"
                    .'Deze link is 24 uur geldig.',
                'body_html' => '<p>Beste '.$name.',</p>'
                    .'<p>Er is een account voor u aangemaakt in <strong>LaVita Urenregistratie</strong>.</p>'
                    .'<p>Rol: <strong>'.$targetRole.'</strong><br>'
                    .'Inloggen met e-mailadres: <strong>'.$user->email.'</strong></p>'
                    .'<p><a href="'.$resetLink.'">Stel uw wachtwoord in</a></p>'
                    .'<p>Deze link is 24 uur geldig.</p>',
                'type' => 'account_created',
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
    }
}
