<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Services\PasswordResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Contract-tests voor wachtwoord-reset flow en audit-log export.
 *
 * Password-reset is stateless (HMAC-signed token, geen DB-rij).
 * Audit-export vereist een Bearer token met role owner of manager.
 */
class PasswordResetAuditModuleContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name' => 'TestOrg BV',
            'kvk_number' => '12345678',
            'sector' => 'zorg',
        ]);

        $this->owner = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Maria Owner',
            'full_name' => 'Maria Owner',
            'email' => 'owner@test.nl',
            'password' => bcrypt('Password123!'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->employee = User::create([
            'organization_id' => $this->org->id,
            'name' => 'Jan Werknemer',
            'full_name' => 'Jan Werknemer',
            'email' => 'jan@test.nl',
            'password' => bcrypt('Werknemerpass1!'),
            'role' => 'employee',
            'is_active' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Password-reset: request
    // -------------------------------------------------------------------------

    public function test_password_reset_request_returns_200_for_known_email(): void
    {
        $response = $this->postJson('/api/auth/password-reset/request', [
            'email' => 'owner@test.nl',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['ok', 'message']);
    }

    public function test_password_reset_request_returns_200_for_unknown_email(): void
    {
        // Timing-safe: ook onbekend adres geeft 200
        $response = $this->postJson('/api/auth/password-reset/request', [
            'email' => 'niemanddies@test.nl',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    public function test_password_reset_request_validates_email_format(): void
    {
        $response = $this->postJson('/api/auth/password-reset/request', [
            'email' => 'geen-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    // -------------------------------------------------------------------------
    // Password-reset: confirm
    // -------------------------------------------------------------------------

    public function test_password_reset_confirm_succeeds_with_valid_token(): void
    {
        /** @var PasswordResetService $service */
        $service = app(PasswordResetService::class);
        $token = $service->createToken($this->owner->id);

        $response = $this->postJson('/api/auth/password-reset/confirm', [
            'token' => $token,
            'password' => 'NieuwWachtwoord99!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true);

        // Wachtwoord is daadwerkelijk gewijzigd
        $this->owner->refresh();
        $this->assertTrue(Hash::check('NieuwWachtwoord99!', $this->owner->password));
    }

    public function test_password_reset_confirm_fails_with_invalid_token(): void
    {
        $response = $this->postJson('/api/auth/password-reset/confirm', [
            'token' => 'ditisgeenvalidetoken',
            'password' => 'NieuwWachtwoord99!',
        ]);

        $response->assertStatus(422);
    }

    public function test_password_reset_confirm_rejects_same_password(): void
    {
        /** @var PasswordResetService $service */
        $service = app(PasswordResetService::class);
        $token = $service->createToken($this->owner->id);

        $response = $this->postJson('/api/auth/password-reset/confirm', [
            'token' => $token,
            'password' => 'Password123!', // Zelfde als huidig
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    public function test_password_reset_confirm_rejects_too_short_password(): void
    {
        /** @var PasswordResetService $service */
        $service = app(PasswordResetService::class);
        $token = $service->createToken($this->owner->id);

        $response = $this->postJson('/api/auth/password-reset/confirm', [
            'token' => $token,
            'password' => 'kort', // < 10 tekens
        ]);

        $response->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // Audit export
    // -------------------------------------------------------------------------

    public function test_audit_export_returns_events_for_owner(): void
    {
        // Seed een audit event
        AuditEvent::create([
            'organization_id' => $this->org->id,
            'actor_id' => $this->owner->id,
            'action' => 'work_entry.created',
            'target_type' => 'work_entry',
            'target_id' => '42',
        ]);

        $response = $this->getWithAuth(
            $this->owner,
            '/api/internal/audit/export',
        );

        $response->assertStatus(200)
            ->assertJsonStructure(['count', 'events'])
            ->assertJsonPath('count', 1);
    }

    public function test_audit_export_forbidden_for_employee(): void
    {
        $response = $this->getWithAuth(
            $this->employee,
            '/api/internal/audit/export',
        );

        $response->assertStatus(403);
    }

    public function test_audit_export_filters_by_action(): void
    {
        AuditEvent::create([
            'organization_id' => $this->org->id,
            'actor_id' => $this->owner->id,
            'action' => 'work_entry.created',
            'target_type' => 'work_entry',
            'target_id' => '1',
        ]);
        AuditEvent::create([
            'organization_id' => $this->org->id,
            'actor_id' => $this->owner->id,
            'action' => 'objection.approved',
            'target_type' => 'objection',
            'target_id' => '2',
        ]);

        $response = $this->getWithAuth(
            $this->owner,
            '/api/internal/audit/export?action=work_entry.created',
        );

        $response->assertStatus(200)
            ->assertJsonPath('count', 1);
    }

    public function test_audit_export_requires_authentication(): void
    {
        $response = $this->getJson('/api/internal/audit/export');
        $response->assertStatus(401);
    }
}
