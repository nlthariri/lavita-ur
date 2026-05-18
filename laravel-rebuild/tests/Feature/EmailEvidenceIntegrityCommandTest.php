<?php

namespace Tests\Feature;

use App\Models\EmailOutbox;
use App\Models\Organization;
use App\Models\SystemJobRun;
use App\Models\User;
use App\Services\EmailOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailEvidenceIntegrityCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_integrity_command_records_completed_run_for_valid_chain(): void
    {
        $organization = Organization::create(['name' => 'Integrity Org']);

        app(EmailOutboxService::class)->dispatch([
            'organization_id' => $organization->id,
            'recipient' => 'valid@example.test',
            'subject' => 'Valid',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'idempotency_key' => 'integrity-valid-1',
        ]);

        $this->artisan('integrity:email-evidence', ['--org-id' => $organization->id])
            ->assertExitCode(0);

        $job = SystemJobRun::where('job_name', 'integrity.email_evidence')->firstOrFail();
        $this->assertSame('completed', $job->status);
        $this->assertSame(0, $job->rows_affected);
        $this->assertSame(1, $job->details['scanned']);
        $this->assertFalse($job->details['tamper_detected']);
    }

    public function test_integrity_command_fails_when_tampering_detected_with_fail_option(): void
    {
        $organization = Organization::create(['name' => 'Tamper Org']);

        app(EmailOutboxService::class)->dispatch([
            'organization_id' => $organization->id,
            'recipient' => 'tamper@example.test',
            'subject' => 'Tamper',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'idempotency_key' => 'integrity-tamper-1',
        ]);

        $outbox = EmailOutbox::where('idempotency_key', 'integrity-tamper-1')->firstOrFail();

        DB::table('email_outbox_events')->insert([
            'outbox_id' => (int) $outbox->id,
            'event_type' => 'tampered_insert',
            'actor_id' => null,
            'request_id' => null,
            'source_ip' => null,
            'user_agent' => null,
            'correlation_id' => (string) $outbox->correlation_id,
            'payload' => json_encode(['tampered' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'previous_event_hash' => 'corrupted-previous-hash',
            'event_hash' => hash('sha256', 'tampered-event'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('integrity:email-evidence', [
            '--org-id' => $organization->id,
            '--fail-on-corruption' => true,
        ])->assertExitCode(1);

        $job = SystemJobRun::where('job_name', 'integrity.email_evidence')->firstOrFail();
        $this->assertSame('failed', $job->status);
        $this->assertSame(1, $job->rows_affected);
        $this->assertTrue($job->details['tamper_detected']);
        $this->assertSame([(int) $outbox->id], $job->details['tampered_outbox_ids']);
    }

    public function test_integrity_command_scopes_to_single_outbox_when_provided(): void
    {
        $organization = Organization::create(['name' => 'Scope Org']);

        app(EmailOutboxService::class)->dispatch([
            'organization_id' => $organization->id,
            'recipient' => 'first@example.test',
            'subject' => 'First',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'idempotency_key' => 'integrity-scope-1',
        ]);

        app(EmailOutboxService::class)->dispatch([
            'organization_id' => $organization->id,
            'recipient' => 'second@example.test',
            'subject' => 'Second',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'idempotency_key' => 'integrity-scope-2',
        ]);

        $target = EmailOutbox::where('idempotency_key', 'integrity-scope-1')->firstOrFail();

        $this->artisan('integrity:email-evidence', ['--outbox-id' => $target->id])
            ->assertExitCode(0);

        $job = SystemJobRun::where('job_name', 'integrity.email_evidence')->firstOrFail();
        $this->assertSame(1, $job->details['scanned']);
    }

    public function test_integrity_command_blocks_when_retention_lock_is_active(): void
    {
        $organization = Organization::create(['name' => 'Lock Org']);
        User::create([
            'name' => 'Owner',
            'email' => 'owner@lock.test',
            'password' => bcrypt('x'),
            'organization_id' => $organization->id,
            'role' => 'owner',
            'is_active' => true,
        ]);

        $lock = Cache::lock('retention:run:any', 3600);
        $this->assertTrue($lock->get());

        $this->artisan('integrity:email-evidence', ['--org-id' => $organization->id])
            ->assertExitCode(1);

        $lock->release();
        $this->assertSame(0, SystemJobRun::where('job_name', 'integrity.email_evidence')->count());
    }

    public function test_integrity_command_sends_escalation_webhook_when_tamper_detected(): void
    {
        config(['services.integrity_audit.webhook_url' => 'https://alerts.example.test/integrity']);
        Http::fake([
            'https://alerts.example.test/*' => Http::response(['accepted' => true], 202),
        ]);

        $organization = Organization::create(['name' => 'Escalation Org']);

        app(EmailOutboxService::class)->dispatch([
            'organization_id' => $organization->id,
            'recipient' => 'escalation@example.test',
            'subject' => 'Escalation',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'idempotency_key' => 'integrity-escalation-1',
        ]);

        $outbox = EmailOutbox::where('idempotency_key', 'integrity-escalation-1')->firstOrFail();

        DB::table('email_outbox_events')->insert([
            'outbox_id' => (int) $outbox->id,
            'event_type' => 'tampered_insert',
            'actor_id' => null,
            'request_id' => null,
            'source_ip' => null,
            'user_agent' => null,
            'correlation_id' => (string) $outbox->correlation_id,
            'payload' => json_encode(['tampered' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'previous_event_hash' => 'corrupted-previous-hash',
            'event_hash' => hash('sha256', 'tampered-event'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('integrity:email-evidence', ['--org-id' => $organization->id])
            ->assertExitCode(0);

        Http::assertSent(function ($request) use ($outbox) {
            $data = $request->data();

            return $request->url() === 'https://alerts.example.test/integrity'
                && ($data['event'] ?? null) === 'integrity.email_evidence.tamper_detected'
                && ! empty($data['incident_id'])
                && in_array((int) $outbox->id, $data['tampered_outbox_ids'] ?? [], true);
        });

        $job = SystemJobRun::where('job_name', 'integrity.email_evidence')->firstOrFail();
        $this->assertSame('sent', $job->details['escalation_delivery']['status']);
        $this->assertNotEmpty($job->details['escalation']['incident_id']);
        $this->assertSame(
            $job->details['escalation']['incident_id'],
            $job->details['escalation_delivery']['incident_id']
        );
        $this->assertSame(1, $job->details['escalation_delivery']['attempts']);
        $this->assertFalse($job->details['escalation_delivery']['acknowledged']);
        $this->assertTrue($job->details['escalation_delivery']['open_incident']);
    }

    public function test_integrity_command_records_http_error_after_retries(): void
    {
        config(['services.integrity_audit.webhook_url' => 'https://alerts.example.test/integrity']);
        Http::fake([
            'https://alerts.example.test/*' => Http::response(['error' => 'fail'], 500),
        ]);

        $organization = Organization::create(['name' => 'Http Error Org']);

        app(EmailOutboxService::class)->dispatch([
            'organization_id' => $organization->id,
            'recipient' => 'http-error@example.test',
            'subject' => 'Http Error',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'idempotency_key' => 'integrity-http-error-1',
        ]);

        $outbox = EmailOutbox::where('idempotency_key', 'integrity-http-error-1')->firstOrFail();

        DB::table('email_outbox_events')->insert([
            'outbox_id' => (int) $outbox->id,
            'event_type' => 'tampered_insert',
            'actor_id' => null,
            'request_id' => null,
            'source_ip' => null,
            'user_agent' => null,
            'correlation_id' => (string) $outbox->correlation_id,
            'payload' => json_encode(['tampered' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'previous_event_hash' => 'corrupted-previous-hash',
            'event_hash' => hash('sha256', 'tampered-event-http'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('integrity:email-evidence', ['--org-id' => $organization->id])
            ->assertExitCode(0);

        Http::assertSentCount(3);

        $job = SystemJobRun::where('job_name', 'integrity.email_evidence')->firstOrFail();
        $this->assertSame('http_error', $job->details['escalation_delivery']['status']);
        $this->assertSame(3, $job->details['escalation_delivery']['attempts']);
    }

    public function test_escalation_report_command_fails_on_open_incidents(): void
    {
        config(['services.integrity_audit.webhook_url' => 'https://alerts.example.test/integrity']);
        Http::fake([
            'https://alerts.example.test/*' => Http::response(['error' => 'fail'], 500),
        ]);

        $organization = Organization::create(['name' => 'Report Org']);

        app(EmailOutboxService::class)->dispatch([
            'organization_id' => $organization->id,
            'recipient' => 'report@example.test',
            'subject' => 'Report',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'idempotency_key' => 'integrity-report-1',
        ]);

        $outbox = EmailOutbox::where('idempotency_key', 'integrity-report-1')->firstOrFail();
        DB::table('email_outbox_events')->insert([
            'outbox_id' => (int) $outbox->id,
            'event_type' => 'tampered_insert',
            'actor_id' => null,
            'request_id' => null,
            'source_ip' => null,
            'user_agent' => null,
            'correlation_id' => (string) $outbox->correlation_id,
            'payload' => json_encode(['tampered' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'previous_event_hash' => 'corrupted-previous-hash',
            'event_hash' => hash('sha256', 'tampered-event-report'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('integrity:email-evidence', ['--org-id' => $organization->id])
            ->assertExitCode(0);

        $this->artisan('integrity:email-evidence:escalations:report', ['--fail-on-open' => true])
            ->assertExitCode(1);
    }

    public function test_incident_can_be_acknowledged_and_resolved_via_commands(): void
    {
        config(['services.integrity_audit.webhook_url' => 'https://alerts.example.test/integrity']);
        Http::fake([
            'https://alerts.example.test/*' => Http::response(['accepted' => true], 202),
        ]);

        $organization = Organization::create(['name' => 'Lifecycle Org']);

        app(EmailOutboxService::class)->dispatch([
            'organization_id' => $organization->id,
            'recipient' => 'lifecycle@example.test',
            'subject' => 'Lifecycle',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'idempotency_key' => 'integrity-lifecycle-1',
        ]);

        $outbox = EmailOutbox::where('idempotency_key', 'integrity-lifecycle-1')->firstOrFail();
        DB::table('email_outbox_events')->insert([
            'outbox_id' => (int) $outbox->id,
            'event_type' => 'tampered_insert',
            'actor_id' => null,
            'request_id' => null,
            'source_ip' => null,
            'user_agent' => null,
            'correlation_id' => (string) $outbox->correlation_id,
            'payload' => json_encode(['tampered' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'previous_event_hash' => 'corrupted-previous-hash',
            'event_hash' => hash('sha256', 'tampered-event-lifecycle'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('integrity:email-evidence', ['--org-id' => $organization->id])
            ->assertExitCode(0);

        $job = SystemJobRun::where('job_name', 'integrity.email_evidence')->firstOrFail();
        $incidentId = (string) $job->details['escalation']['incident_id'];

        $this->artisan('integrity:email-evidence:incident:ack', [
            'incident-id' => $incidentId,
            '--note' => 'Ack by on-call',
        ])->assertExitCode(0);

        $job->refresh();
        $this->assertSame('acknowledged', $job->details['escalation']['state']);
        $this->assertTrue($job->details['escalation_delivery']['acknowledged']);

        $this->artisan('integrity:email-evidence:incident:resolve', [
            'incident-id' => $incidentId,
            '--note' => 'Resolved after investigation',
        ])->assertExitCode(0);

        $job->refresh();
        $this->assertSame('resolved', $job->details['escalation']['state']);
        $this->assertTrue($job->details['escalation_delivery']['resolved']);
        $this->assertFalse($job->details['escalation_delivery']['open_incident']);
    }
}
