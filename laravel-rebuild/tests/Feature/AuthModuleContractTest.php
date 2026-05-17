<?php

namespace Tests\Feature;

use App\Models\AuthSession;
use App\Models\Organization;
use App\Models\Team;
use App\Models\MfaSecret;
use App\Models\User;
use App\Services\AuthMfaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthModuleContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_requires_email_and_password(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_with_valid_credentials_creates_session_and_returns_token(): void
    {
        $user = User::factory()->create([
            'email' => 'owner@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'LangWachtwoord123',
        ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('module', 'AuthModule')
            ->assertJsonPath('scope', 'MUST-AUTH-MFA')
            ->assertJsonPath('user_id', $user->id)
            ->assertJsonPath('mfa_required', true);

        $token = (string) $response->json('session_token');
        $this->assertNotSame('', $token);

        $hash = hash('sha256', $token);
        $this->assertDatabaseHas('auth_sessions', [
            'user_id' => $user->id,
            'session_token_hash' => $hash,
        ]);
    }

    public function test_logout_revokes_existing_session(): void
    {
        $user = User::factory()->create([
            'email' => 'owner-logout@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'LangWachtwoord123',
        ])->assertStatus(200);

        $token = (string) $login->json('session_token');

        $this->postJson('/api/auth/logout', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(200)
            ->assertJsonPath('revoked', true);

        $revoked = AuthSession::query()
            ->where('session_token_hash', hash('sha256', $token))
            ->first();

        $this->assertNotNull($revoked);
        $this->assertNotNull($revoked->revoked_at);
    }

    public function test_mfa_setup_rejects_invalid_password_confirmation(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('LangWachtwoord123'),
        ]);

        $token = $this->createBearerToken($user);

        $this->postJson('/api/auth/mfa/setup', [
            'user_id' => $user->id,
            'password_confirmation' => 'foutfoutfout',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password_confirmation']);
    }

    public function test_mfa_setup_and_verify_flow_marks_secret_verified(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('LangWachtwoord123'),
        ]);

        $token = $this->createBearerToken($user);

        $setup = $this->postJson('/api/auth/mfa/setup', [
            'user_id' => $user->id,
            'password_confirmation' => 'LangWachtwoord123',
        ], ['Authorization' => 'Bearer '.$token]);

        $setup
            ->assertStatus(201)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('module', 'AuthModule')
            ->assertJsonPath('user_id', $user->id);

        $secret = (string) $setup->json('provisioning_secret');
        $this->assertSame(substr($secret, -4), (string) $setup->json('provisioning_secret_last4'));
        $code = app(AuthMfaService::class)->codeForTesting($secret);

        $this->postJson('/api/auth/mfa/verify', [
            'user_id' => $user->id,
            'code' => $code,
        ])
            ->assertStatus(200)
            ->assertJsonPath('verified', true);

        $record = MfaSecret::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($record);
        $this->assertNotNull($record->verified_at);

        $recordArray = $record->toArray();
        $this->assertArrayNotHasKey('secret_encrypted', $recordArray);
    }

    public function test_mfa_verify_requires_numeric_6_digit_code(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('LangWachtwoord123'),
        ]);

        $token = $this->createBearerToken($user);

        $this->postJson('/api/auth/mfa/setup', [
            'user_id' => $user->id,
            'password_confirmation' => 'LangWachtwoord123',
        ], ['Authorization' => 'Bearer '.$token])->assertStatus(201);

        $response = $this->postJson('/api/auth/mfa/verify', [
            'user_id' => $user->id,
            'code' => '12ab',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_employee_login_is_not_blocked_by_mfa_requirement(): void
    {
        $employee = User::factory()->create([
            'email' => 'employee@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $employee->email,
            'password' => 'LangWachtwoord123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('mfa_required', false);
    }

    public function test_internal_routes_require_verified_mfa_for_owner_and_manager(): void
    {
        $owner = User::factory()->create([
            'email' => 'owner-no-mfa@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $token = \Illuminate\Support\Str::random(64);
        AuthSession::query()->create([
            'user_id' => $owner->id,
            'session_token_hash' => hash('sha256', $token),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestSuite/1.0',
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->getJson('/api/internal/work-entries', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('error', 'MFA verificatie is verplicht voor deze rol.');
    }

    public function test_owner_can_create_account_and_queue_onboarding_email(): void
    {
        $org = Organization::create(['name' => 'LaVita Org']);
        $team = Team::create(['organization_id' => $org->id, 'name' => 'Team A']);

        $owner = User::factory()->create([
            'email' => 'owner-create@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'organization_id' => $org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $response = $this->postWithAuth($owner, '/api/auth/accounts', [
            'password_confirmation' => 'LangWachtwoord123',
            'name' => 'Nieuw Medewerker',
            'full_name' => 'Nieuw Medewerker Volledige Naam',
            'email' => 'new.employee@lavita.nl',
            'role' => 'employee',
            'team_id' => $team->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('module', 'AuthModule')
            ->assertJsonPath('account.email', 'new.employee@lavita.nl')
            ->assertJsonPath('account.role', 'employee')
            ->assertJsonPath('account.onboarding_email_queued', true);

        $createdUserId = (int) $response->json('account.id');

        $this->assertDatabaseHas('users', [
            'id' => $createdUserId,
            'email' => 'new.employee@lavita.nl',
            'organization_id' => $org->id,
            'team_id' => $team->id,
            'role' => 'employee',
        ]);

        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $org->id,
            'user_id' => $createdUserId,
            'recipient' => 'new.employee@lavita.nl',
            'type' => 'welcome_email',
            'status' => 'queued',
            'initiator_actor_id' => $owner->id,
        ]);
    }

    public function test_boekhouder_cannot_create_account(): void
    {
        $org = Organization::create(['name' => 'LaVita Org 2']);
        $boekhouder = User::factory()->create([
            'email' => 'boekhouder@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'organization_id' => $org->id,
            'role' => 'boekhouder',
            'is_active' => true,
        ]);

        $this->postWithAuth($boekhouder, '/api/auth/accounts', [
            'name' => 'Onterecht',
            'email' => 'blocked-account@lavita.nl',
            'role' => 'employee',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Onvoldoende rechten voor account-aanmaak.');
    }

    public function test_manager_cannot_create_non_employee_account(): void
    {
        $org = Organization::create(['name' => 'LaVita Org 3']);
        $team = Team::create(['organization_id' => $org->id, 'name' => 'Team M']);
        $manager = User::factory()->create([
            'email' => 'manager-create@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'organization_id' => $org->id,
            'team_id' => $team->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->postWithAuth($manager, '/api/auth/accounts', [
            'password_confirmation' => 'LangWachtwoord123',
            'name' => 'Niet toegestaan',
            'email' => 'manager-target@lavita.nl',
            'role' => 'manager',
            'team_id' => $team->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.role.0', 'Manager kan alleen medewerker-accounts aanmaken.');
    }

    public function test_inactive_owner_cannot_create_account(): void
    {
        $org = Organization::create(['name' => 'LaVita Org 4']);
        $owner = User::factory()->create([
            'email' => 'inactive-owner@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'organization_id' => $org->id,
            'role' => 'owner',
            'is_active' => false,
        ]);

        $token = \Illuminate\Support\Str::random(64);
        AuthSession::query()->create([
            'user_id' => $owner->id,
            'session_token_hash' => hash('sha256', $token),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestSuite/1.0',
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $this->postJson('/api/auth/accounts', [
            'name' => 'Niet Toestaan',
            'email' => 'blocked-by-inactive@lavita.nl',
            'role' => 'employee',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401);
    }

    public function test_owner_cannot_create_account_with_team_from_other_organization(): void
    {
        $orgA = Organization::create(['name' => 'Org A']);
        $orgB = Organization::create(['name' => 'Org B']);
        $foreignTeam = Team::create(['organization_id' => $orgB->id, 'name' => 'Foreign Team']);

        $owner = User::factory()->create([
            'email' => 'owner-org-a@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'organization_id' => $orgA->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->postWithAuth($owner, '/api/auth/accounts', [
            'password_confirmation' => 'LangWachtwoord123',
            'name' => 'Cross Team',
            'email' => 'cross-team@lavita.nl',
            'role' => 'employee',
            'team_id' => $foreignTeam->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.team_id.0', 'Team hoort niet bij de organisatie van de account-aanmaker.');
    }

    // ─── Regressietests: MFA anti-spoof ──────────────────────────────────────

    public function test_mfa_setup_blocked_when_user_id_differs_from_authenticated_user(): void
    {
        $victim = User::factory()->create([
            'email' => 'victim@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        $attacker = User::factory()->create([
            'email' => 'attacker@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        // Aanvaller stuurt het user_id van het slachtoffer mee — moet 422 opleveren
        $this->postWithAuth($attacker, '/api/auth/mfa/setup', [
            'user_id' => $victim->id,
            'password_confirmation' => 'LangWachtwoord123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.user_id.0', 'MFA-setup is alleen toegestaan voor uw eigen account.');
    }

    public function test_expired_session_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'expired-session@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        $token = \Illuminate\Support\Str::random(64);
        AuthSession::query()->create([
            'user_id' => $user->id,
            'session_token_hash' => hash('sha256', $token),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestSuite/1.0',
            'last_seen_at' => now()->subHour(),
            'expires_at' => now()->subMinute(), // verlopen
        ]);

        $this->getJson('/api/internal/work-entries', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401);
    }

    public function test_revoked_session_token_is_rejected(): void
    {
        $user = User::factory()->create([
            'email' => 'revoked-session@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'role' => 'employee',
            'is_active' => true,
        ]);

        $token = \Illuminate\Support\Str::random(64);
        AuthSession::query()->create([
            'user_id' => $user->id,
            'session_token_hash' => hash('sha256', $token),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestSuite/1.0',
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
            'revoked_at' => now()->subMinute(), // ingetrokken
        ]);

        $this->getJson('/api/internal/work-entries', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(401);
    }

    // ─── Recovery codes ─────────────────────────────────────────────────────

    public function test_mfa_setup_returns_eight_recovery_codes(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('LangWachtwoord123'),
        ]);

        $token = $this->createBearerToken($user);

        $setup = $this->postJson('/api/auth/mfa/setup', [
            'user_id' => $user->id,
            'password_confirmation' => 'LangWachtwoord123',
        ], ['Authorization' => 'Bearer '.$token]);

        $setup->assertStatus(201);

        $codes = $setup->json('recovery_codes');
        $this->assertIsArray($codes);
        $this->assertCount(8, $codes);
        foreach ($codes as $code) {
            $this->assertSame(10, strlen($code), 'Elke recovery code moet 10 tekens zijn.');
        }

        $this->assertDatabaseCount('mfa_recovery_codes', 8);
    }

    public function test_mfa_verify_with_recovery_code_marks_code_as_used(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('LangWachtwoord123'),
        ]);

        $token = $this->createBearerToken($user);

        $setup = $this->postJson('/api/auth/mfa/setup', [
            'user_id' => $user->id,
            'password_confirmation' => 'LangWachtwoord123',
        ], ['Authorization' => 'Bearer '.$token]);

        $setup->assertStatus(201);
        $recoveryCodes = $setup->json('recovery_codes');
        $usedCode = $recoveryCodes[0];

        // Gebruik de eerste recovery code om te verifiëren
        $this->postJson('/api/auth/mfa/verify', [
            'user_id' => $user->id,
            'code' => $usedCode,
        ])->assertStatus(200)->assertJsonPath('verified', true);

        // Zelfde code een tweede keer gebruiken moet mislukken
        $this->postJson('/api/auth/mfa/verify', [
            'user_id' => $user->id,
            'code' => $usedCode,
        ])->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    // ─── MFA rotatie-policy + re-auth account-aanmaak ────────────────────────

    public function test_mfa_rotation_required_after_180_days(): void
    {
        $user = User::factory()->create([
            'email' => 'rotation-test@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $token = $this->createBearerToken($user);

        // Zet rotated_at 181 dagen terug → verlopen secret
        MfaSecret::where('user_id', $user->id)->update([
            'rotated_at' => now()->subDays(181),
        ]);

        $this->getJson('/api/internal/work-entries', ['Authorization' => 'Bearer '.$token])
            ->assertStatus(403)
            ->assertJsonPath('code', 'MFA_ROTATION_REQUIRED');
    }

    public function test_account_creation_requires_correct_password_confirmation(): void
    {
        $org = Organization::create(['name' => 'Org ReAuth']);
        $team = Team::create(['organization_id' => $org->id, 'name' => 'Team X']);
        $owner = User::factory()->create([
            'email' => 'owner-reauth@lavita.nl',
            'password' => Hash::make('LangWachtwoord123'),
            'organization_id' => $org->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        // Verkeerd wachtwoord → 422
        $this->postWithAuth($owner, '/api/auth/accounts', [
            'password_confirmation' => 'VerkeerdeWachtwoord!',
            'name' => 'Blocked',
            'email' => 'blocked-reauth@lavita.nl',
            'role' => 'employee',
            'team_id' => $team->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['password_confirmation']);

        // Correct wachtwoord → 201
        $this->postWithAuth($owner, '/api/auth/accounts', [
            'password_confirmation' => 'LangWachtwoord123',
            'name' => 'Toegestaan',
            'email' => 'allowed-reauth@lavita.nl',
            'role' => 'employee',
            'team_id' => $team->id,
        ])->assertStatus(201);
    }
}
