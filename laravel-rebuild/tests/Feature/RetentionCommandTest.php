<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\AuthSession;
use App\Models\EmailOutbox;
use App\Models\MfaSecret;
use App\Models\Objection;
use App\Models\Organization;
use App\Models\SystemJobRun;
use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RetentionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_command_scrubs_sent_email_bodies_and_audit_ips(): void
    {
        $organization = Organization::create([
            'name' => 'Cleanup Org',
            'retention_years' => 7,
        ]);

        EmailOutbox::create([
            'idempotency_key' => 'sent-old',
            'organization_id' => $organization->id,
            'recipient' => 'persoon@example.test',
            'subject' => 'Onderwerp',
            'body_text' => 'Gevoelige inhoud',
            'body_html' => '<p>Gevoelige inhoud</p>',
            'attachments' => ['foo' => 'bar'],
            'status' => 'sent',
            'retry_count' => 0,
            'next_attempt_at' => now()->subDays(40),
            'sent_at' => now()->subDays(40),
            'error_message' => 'persoonlijke fout',
        ]);

        AuditEvent::create([
            'organization_id' => $organization->id,
            'actor_id' => 999,
            'action' => 'audit.exported',
            'target_type' => 'audit',
            'target_id' => '1',
            'ip_address' => '203.0.113.55',
            'created_at' => now()->subDays(100),
        ]);

        $this->artisan('retention:run')
            ->assertExitCode(0);

        $email = EmailOutbox::firstOrFail();
        $audit = AuditEvent::firstOrFail();

        $this->assertSame('', $email->body_text);
        $this->assertSame('', $email->body_html);
        $this->assertNull($email->attachments);
        $this->assertNull($email->error_message);
        $this->assertNotNull($email->scrubbed_at);
        $this->assertNull($audit->ip_address);
        $this->assertNotNull($audit->scrubbed_at);
    }

    public function test_retention_command_pseudonymizes_inactive_users_and_related_records(): void
    {
        $organization = Organization::create([
            'name' => 'Retention Org',
            'retention_years' => 2,
        ]);

        $user = User::create([
            'organization_id' => $organization->id,
            'name' => 'Oud Personeel',
            'full_name' => 'Oud Personeel',
            'email' => 'oud@example.test',
            'password' => bcrypt('OudWachtwoord123!'),
            'role' => 'employee',
            'is_active' => false,
            'employment_end' => now()->subYears(3)->toDateString(),
        ]);

        WorkEntry::create([
            'organization_id' => $organization->id,
            'employee_id' => $user->id,
            'registered_by_id' => $user->id,
            'entry_date' => now()->subYears(3)->toDateString(),
            'start_at' => now()->subYears(3)->startOfDay(),
            'end_at' => now()->subYears(3)->startOfDay()->addHours(8),
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'type' => 'WORK',
            'note' => 'medische notitie',
            'is_finalized' => true,
        ]);

        Objection::create([
            'organization_id' => $organization->id,
            'work_entry_id' => 1,
            'submitted_by_id' => $user->id,
            'motivation' => 'privacygevoelig',
            'manager_response' => 'ook gevoelig',
            'status' => 'submitted',
            'submitted_at' => now()->subYears(3),
        ]);

        AuthSession::create([
            'user_id' => $user->id,
            'session_token_hash' => hash('sha256', 'legacy-token'),
            'expires_at' => now()->addDay(),
        ]);

        MfaSecret::create([
            'user_id' => $user->id,
            'secret_encrypted' => 'encrypted-secret',
            'label' => 'oud@example.test',
        ]);

        DB::table('password_reset_tokens')->insert([
            'email' => 'oud@example.test',
            'token' => 'reset-token',
            'created_at' => now(),
        ]);

        $this->artisan('retention:run', ['--org-id' => $organization->id])
            ->assertExitCode(0);

        $user->refresh();
        $this->assertSame('Gepseudonimiseerd', $user->name);
        $this->assertSame('Gepseudonimiseerd', $user->full_name);
        $this->assertStringStartsWith('deleted+', $user->email);
        $this->assertStringEndsWith('@anonymized.local', $user->email);
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertNotNull(AuthSession::firstOrFail()->revoked_at);
        $this->assertNotNull(MfaSecret::firstOrFail()->disabled_at);
        $this->assertNull(WorkEntry::firstOrFail()->note);
        $this->assertSame('[gepseudonimiseerd conform retentiebeleid]', Objection::firstOrFail()->motivation);
        $this->assertSame('[gepseudonimiseerd conform retentiebeleid]', Objection::firstOrFail()->manager_response);
    }

    public function test_retention_command_dry_run_records_evidence_without_mutation(): void
    {
        Carbon::setTestNow(now());

        $organization = Organization::create([
            'name' => 'Dry Run Org',
            'retention_years' => 1,
        ]);

        EmailOutbox::create([
            'idempotency_key' => 'dry-run-email',
            'organization_id' => $organization->id,
            'recipient' => 'droog@example.test',
            'subject' => 'Dry run',
            'body_text' => 'Blijf staan',
            'body_html' => '<p>Blijf staan</p>',
            'status' => 'sent',
            'retry_count' => 0,
            'next_attempt_at' => now()->subDays(31),
            'sent_at' => now()->subDays(31),
        ]);

        $this->artisan('retention:run', ['--dry-run' => true])
            ->assertExitCode(0);

        $jobRun = SystemJobRun::firstOrFail();
        $email = EmailOutbox::firstOrFail();

        $this->assertSame('completed', $jobRun->status);
        $this->assertSame(0, $jobRun->rows_affected);
        $this->assertTrue($jobRun->details['dry_run']);
        $this->assertSame('Blijf staan', $email->body_text);
        $this->assertNull($email->scrubbed_at);

        Carbon::setTestNow();
    }

    public function test_retention_command_scopes_cleanup_to_requested_organization(): void
    {
        $target = Organization::create([
            'name' => 'Target Org',
            'retention_years' => 7,
        ]);
        $other = Organization::create([
            'name' => 'Other Org',
            'retention_years' => 7,
        ]);

        $targetEmail = EmailOutbox::create([
            'idempotency_key' => 'target-old',
            'organization_id' => $target->id,
            'recipient' => 'target@example.test',
            'subject' => 'Target',
            'body_text' => 'scrub mij',
            'body_html' => '<p>scrub mij</p>',
            'status' => 'sent',
            'retry_count' => 0,
            'next_attempt_at' => now()->subDays(35),
            'sent_at' => now()->subDays(35),
        ]);
        $otherEmail = EmailOutbox::create([
            'idempotency_key' => 'other-old',
            'organization_id' => $other->id,
            'recipient' => 'other@example.test',
            'subject' => 'Other',
            'body_text' => 'laat staan',
            'body_html' => '<p>laat staan</p>',
            'status' => 'sent',
            'retry_count' => 0,
            'next_attempt_at' => now()->subDays(35),
            'sent_at' => now()->subDays(35),
        ]);

        $targetAudit = AuditEvent::create([
            'organization_id' => $target->id,
            'actor_id' => 1,
            'action' => 'target.action',
            'target_type' => 'target',
            'target_id' => '1',
            'ip_address' => '198.51.100.10',
            'created_at' => now()->subDays(100),
        ]);
        $otherAudit = AuditEvent::create([
            'organization_id' => $other->id,
            'actor_id' => 2,
            'action' => 'other.action',
            'target_type' => 'other',
            'target_id' => '2',
            'ip_address' => '198.51.100.11',
            'created_at' => now()->subDays(100),
        ]);

        $this->artisan('retention:run', ['--org-id' => $target->id])
            ->assertExitCode(0);

        $this->assertSame('', $targetEmail->fresh()->body_text);
        $this->assertSame('laat staan', $otherEmail->fresh()->body_text);
        $this->assertNull($targetAudit->fresh()->ip_address);
        $this->assertSame('198.51.100.11', $otherAudit->fresh()->ip_address);
    }

    public function test_retention_command_preserves_null_manager_response(): void
    {
        $organization = Organization::create([
            'name' => 'Null Org',
            'retention_years' => 2,
        ]);
        $user = User::create([
            'organization_id' => $organization->id,
            'name' => 'Oud Personeel',
            'full_name' => 'Oud Personeel',
            'email' => 'null@example.test',
            'password' => bcrypt('OudWachtwoord123!'),
            'role' => 'employee',
            'is_active' => false,
            'employment_end' => now()->subYears(3)->toDateString(),
        ]);
        $entry = WorkEntry::create([
            'organization_id' => $organization->id,
            'employee_id' => $user->id,
            'registered_by_id' => $user->id,
            'entry_date' => now()->subYears(3)->toDateString(),
            'start_at' => now()->subYears(3)->startOfDay(),
            'end_at' => now()->subYears(3)->startOfDay()->addHours(8),
            'pause_minutes' => 30,
            'net_minutes' => 450,
            'type' => 'WORK',
            'is_finalized' => true,
        ]);
        Objection::create([
            'organization_id' => $organization->id,
            'work_entry_id' => $entry->id,
            'submitted_by_id' => $user->id,
            'motivation' => 'alleen motivatie',
            'manager_response' => null,
            'status' => 'submitted',
            'submitted_at' => now()->subYears(3),
        ]);

        $this->artisan('retention:run', ['--org-id' => $organization->id])
            ->assertExitCode(0);

        $objection = Objection::firstOrFail();
        $this->assertSame('[gepseudonimiseerd conform retentiebeleid]', $objection->motivation);
        $this->assertNull($objection->manager_response);
    }

    public function test_retention_command_fails_when_same_scope_is_locked(): void
    {
        $lock = Cache::lock('retention:run:any', 3600);
        $this->assertTrue($lock->get());

        $this->artisan('retention:run')
            ->assertExitCode(1);

        $lock->release();
    }

    public function test_retention_command_blocks_tenant_run_when_global_mutex_is_held(): void
    {
        $organization = Organization::create([
            'name' => 'Locked Org',
            'retention_years' => 7,
        ]);

        $lock = Cache::lock('retention:run:any', 3600);
        $this->assertTrue($lock->get());

        $this->artisan('retention:run', ['--org-id' => $organization->id])
            ->assertExitCode(1);

        $lock->release();
    }
}