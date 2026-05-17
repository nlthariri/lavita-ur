<?php

namespace Tests\Feature;

use App\Models\EmailOutbox;
use App\Models\EmailOutboxEvent;
use App\Models\MonthlyReportRun;
use App\Models\Organization;
use App\Models\User;
use App\Services\EmailOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class EmailFlowsModuleContractTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $owner;
    private User $boekhouder;
    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['name' => 'Email BV']);
        $this->owner = User::create([
            'name' => 'Admin', 'email' => 'admin@email.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'owner', 'is_active' => true,
        ]);
        $this->boekhouder = User::create([
            'name' => 'Boekhouder', 'email' => 'boekhouder@email.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'boekhouder', 'is_active' => true,
        ]);
    }

    // ─── Dispatch endpoint ──────────────────────────────────────────────────

    public function test_dispatch_requires_recipient_and_subject(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient', 'subject', 'body_text', 'body_html']);
    }

    public function test_dispatch_queues_email_and_returns_202(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', [
            'recipient' => 'user@example.nl',
            'subject' => 'Test onderwerp',
            'body_text' => 'Hallo',
            'body_html' => '<p>Hallo</p>',
            'organization_id' => $this->org->id,
        ])
        ->assertStatus(202)
        ->assertJsonFragment(['status' => 'queued', 'idempotent' => false])
        ->assertJsonStructure(['id', 'status', 'idempotent', 'correlation_id']);

        $this->assertDatabaseCount('email_outbox', 1);
        $this->assertDatabaseHas('email_outbox', [
            'recipient' => 'user@example.nl',
            'status' => 'queued',
            'initiator_actor_id' => $this->owner->id,
            'initiator_org_id_snapshot' => $this->org->id,
            'initiator_role_snapshot' => 'owner',
        ]);

        $item = EmailOutbox::firstOrFail();
        $this->assertNotNull($item->correlation_id);
        $this->assertNotNull($item->subject_sha256);
        $this->assertNotNull($item->body_text_sha256);
        $this->assertNotNull($item->body_html_sha256);
        $this->assertDatabaseHas('email_outbox_events', [
            'outbox_id' => $item->id,
            'event_type' => 'queued',
            'actor_id' => $this->owner->id,
        ]);
    }

    public function test_dispatch_is_idempotent_with_same_key(): void
    {
        $payload = [
            'recipient' => 'dup@example.nl',
            'subject' => 'Dubbel',
            'body_text' => 'x',
            'body_html' => '<p>x</p>',
            'idempotency_key' => 'uniq-key-abc123',
        ];

        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', $payload)
            ->assertStatus(202)
            ->assertJsonFragment(['idempotent' => false]);

        // Tweede aanroep met zelfde sleutel
        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', $payload)
            ->assertStatus(202)
            ->assertJsonFragment(['idempotent' => true]);

        // Nog steeds slechts 1 rij
        $this->assertDatabaseCount('email_outbox', 1);
    }

    public function test_employee_cannot_dispatch_email_even_with_spoofed_org(): void
    {
        $employee = User::create([
            'name' => 'Employee', 'email' => 'dispatch-emp@email.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'employee', 'is_active' => true,
        ]);

        $this->postWithAuth($employee, '/api/internal/email/dispatch', [
            'recipient' => 'user@example.nl',
            'subject' => 'Onterecht',
            'body_text' => 'Hallo',
            'body_html' => '<p>Hallo</p>',
            'organization_id' => $this->org->id,
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Onvoldoende rechten voor e-mail dispatch.');
    }

    public function test_dispatch_cannot_target_other_organization(): void
    {
        $otherOrg = Organization::create(['name' => 'Other Org']);

        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', [
            'recipient' => 'user@example.nl',
            'subject' => 'Cross org',
            'body_text' => 'Hallo',
            'body_html' => '<p>Hallo</p>',
            'organization_id' => $otherOrg->id,
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'U mag geen e-mail dispatchen voor een andere organisatie.');
    }

    public function test_dispatch_rejects_recipient_user_from_other_organization(): void
    {
        $otherOrg = Organization::create(['name' => 'OtherOrg']);
        $otherUser = User::create([
            'name' => 'Other user', 'email' => 'other-user@email.nl', 'password' => bcrypt('x'),
            'organization_id' => $otherOrg->id, 'role' => 'employee', 'is_active' => true,
        ]);

        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', [
            'recipient' => 'user@example.nl',
            'subject' => 'Cross tenant user id',
            'body_text' => 'Hallo',
            'body_html' => '<p>Hallo</p>',
            'organization_id' => $this->org->id,
            'user_id' => $otherUser->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('errors.user_id.0', 'Ontvanger hoort niet bij deze organisatie.');
    }

    // ─── Monthly report job endpoint ────────────────────────────────────────

    public function test_monthly_report_requires_organization_id_and_period(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/jobs/monthly-report', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['organization_id', 'period_month']);
    }

    public function test_monthly_report_invalid_period_format_rejected(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/jobs/monthly-report', [
            'organization_id' => $this->org->id,
            'period_month' => 'mei-2026',  // ongeldig formaat
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['period_month']);
    }

    public function test_monthly_report_queues_emails_for_owner_and_manager(): void
    {
        $manager = User::create([
            'name' => 'Mgr', 'email' => 'mgr@email.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'manager', 'is_active' => true,
        ]);

        $response = $this->postWithAuth($this->owner, '/api/internal/jobs/monthly-report', [
            'organization_id' => $this->org->id,
            'period_month' => '2026-04',
        ])
        ->assertStatus(202)
        ->assertJsonFragment(['period' => '2026-04', 'queued_for' => 2])
        ->assertJsonStructure(['run_id', 'period', 'organization_id', 'initiated_by_user_id', 'correlation_id', 'queued_for']);

        // Owner en manager elk een e-mail in de outbox
        $this->assertDatabaseCount('email_outbox', 2);
        $this->assertDatabaseHas('email_outbox', [
            'user_id' => $this->owner->id,
            'type' => 'monthly_report',
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('email_outbox', [
            'user_id' => $manager->id,
            'type' => 'monthly_report',
        ]);

        $runId = (int) $response->json('run_id');
        $this->assertDatabaseHas('monthly_report_runs', [
            'id' => $runId,
            'organization_id' => $this->org->id,
            'requested_by_actor_id' => $this->owner->id,
            'period_month' => '2026-04',
        ]);

        $this->assertSame(2, EmailOutbox::where('monthly_report_run_id', $runId)->count());
        $this->assertSame(2, EmailOutboxEvent::whereIn('outbox_id', EmailOutbox::where('monthly_report_run_id', $runId)->pluck('id'))->where('event_type', 'queued')->count());
    }

    public function test_employee_cannot_start_monthly_report_even_with_spoofed_requester_id(): void
    {
        $employee = User::create([
            'name' => 'Employee', 'email' => 'employee@email.nl', 'password' => bcrypt('x'),
            'organization_id' => $this->org->id, 'role' => 'employee', 'is_active' => true,
        ]);

        $this->postWithAuth($employee, '/api/internal/jobs/monthly-report', [
            'organization_id' => $this->org->id,
            'period_month' => '2026-04',
            'requester_id' => $this->owner->id,
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Onvoldoende rechten voor maandrapportage.');
    }

    public function test_boekhouder_cannot_dispatch_email(): void
    {
        $this->postWithAuth($this->boekhouder, '/api/internal/email/dispatch', [
            'recipient' => 'user@example.nl',
            'subject' => 'Niet toegestaan',
            'body_text' => 'Hallo',
            'body_html' => '<p>Hallo</p>',
            'organization_id' => $this->org->id,
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Onvoldoende rechten voor e-mail dispatch.');
    }

    public function test_boekhouder_cannot_start_monthly_report(): void
    {
        $this->postWithAuth($this->boekhouder, '/api/internal/jobs/monthly-report', [
            'organization_id' => $this->org->id,
            'period_month' => '2026-04',
        ])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Onvoldoende rechten voor maandrapportage.');
    }

    // ─── Service: exponential backoff ────────────────────────────────────────

    public function test_process_batch_marks_failed_item_after_max_retries(): void
    {
        EmailOutbox::create([
            'idempotency_key' => 'fail-test-1',
            'recipient' => 'bad@example.nl',
            'subject' => 'Test',
            'body_text' => 'x',
            'body_html' => '<p>x</p>',
            'status' => 'queued',
            'retry_count' => 4, // één onder max (5)
            'next_attempt_at' => now()->subSecond(),
        ]);

        // processBatch probeert te verzenden — SMTP mislukt in testsuite
        // Mock door config('mail.mailers.smtp.host') leeg; test focust op statusovergang
        config(['mail.mailers.smtp.host' => 'invalid-host-does-not-exist.local']);

        /** @var EmailOutboxService $service */
        $service = app(EmailOutboxService::class);
        $result = $service->processBatch();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(0, $result['sent']);

        $this->assertDatabaseHas('email_outbox', [
            'idempotency_key' => 'fail-test-1',
            'status' => 'failed',
            'retry_count' => 5,
        ]);

        $outbox = EmailOutbox::where('idempotency_key', 'fail-test-1')->firstOrFail();
        $events = EmailOutboxEvent::where('outbox_id', $outbox->id)->pluck('event_type')->all();
        $this->assertContains('send_attempt', $events);
        $this->assertContains('failed', $events);
    }

    public function test_monthly_report_run_tracks_initiator_evidence(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/jobs/monthly-report', [
            'organization_id' => $this->org->id,
            'period_month' => '2026-05',
        ])->assertStatus(202);

        $run = MonthlyReportRun::firstOrFail();
        $this->assertSame($this->owner->id, $run->requested_by_actor_id);
        $this->assertSame($this->org->id, $run->organization_id);
        $this->assertNotNull($run->correlation_id);
    }

    public function test_email_outbox_events_are_append_only(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', [
            'recipient' => 'append-only@example.nl',
            'subject' => 'Append only',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'organization_id' => $this->org->id,
        ])->assertStatus(202);

        $event = EmailOutboxEvent::firstOrFail();

        $this->expectException(\LogicException::class);
        $event->update(['event_type' => 'tampered']);
    }

    public function test_event_hash_chain_can_be_verified(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', [
            'recipient' => 'hash-chain@example.nl',
            'subject' => 'Hash chain',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'organization_id' => $this->org->id,
            'idempotency_key' => 'hash-chain-key',
        ])->assertStatus(202);

        config(['mail.mailers.smtp.host' => 'invalid-host-does-not-exist.local']);
        app(EmailOutboxService::class)->processBatch();

        $outbox = EmailOutbox::where('idempotency_key', 'hash-chain-key')->firstOrFail();
        $this->assertTrue(app(EmailOutboxService::class)->verifyEventChainForOutbox((int) $outbox->id));
    }

    public function test_event_hash_chain_verification_fails_after_tampered_insert(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', [
            'recipient' => 'hash-chain-corrupt@example.nl',
            'subject' => 'Hash chain corrupt',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'organization_id' => $this->org->id,
            'idempotency_key' => 'hash-chain-corrupt-key',
        ])->assertStatus(202);

        config(['mail.mailers.smtp.host' => 'invalid-host-does-not-exist.local']);
        app(EmailOutboxService::class)->processBatch();

        $outbox = EmailOutbox::where('idempotency_key', 'hash-chain-corrupt-key')->firstOrFail();

        DB::table('email_outbox_events')->insert([
            'outbox_id' => (int) $outbox->id,
            'event_type' => 'tampered_insert',
            'actor_id' => null,
            'request_id' => null,
            'source_ip' => null,
            'user_agent' => null,
            'correlation_id' => (string) $outbox->correlation_id,
            'payload' => json_encode(['tampered' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'previous_event_hash' => '0000corruptedprevioushash',
            'event_hash' => hash('sha256', 'tampered-event'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertFalse(app(EmailOutboxService::class)->verifyEventChainForOutbox((int) $outbox->id));
    }

    public function test_db_level_append_only_blocks_update_and_delete_on_events(): void
    {
        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', [
            'recipient' => 'append-only-db@example.nl',
            'subject' => 'Append only db',
            'body_text' => 'tekst',
            'body_html' => '<p>tekst</p>',
            'organization_id' => $this->org->id,
        ])->assertStatus(202);

        $event = EmailOutboxEvent::firstOrFail();

        try {
            DB::table('email_outbox_events')
                ->where('id', $event->id)
                ->update(['event_type' => 'tampered_db_update']);
            $this->fail('DB-level append-only trigger had update moeten blokkeren.');
        } catch (QueryException) {
            // Verwacht: DB trigger blokkeert update.
        }

        try {
            DB::table('email_outbox_events')
                ->where('id', $event->id)
                ->delete();
            $this->fail('DB-level append-only trigger had delete moeten blokkeren.');
        } catch (QueryException) {
            // Verwacht: DB trigger blokkeert delete.
        }

        $this->assertDatabaseHas('email_outbox_events', [
            'id' => $event->id,
            'event_type' => 'queued',
        ]);
    }

    public function test_owner_can_upsert_and_fetch_email_template(): void
    {
        $ownerToken = $this->createBearerToken($this->owner);

        $this->putJson('/api/internal/email/templates/account_created', [
            'subject_template' => 'Welkom {{name}}',
            'body_text_template' => 'Hallo {{name}}',
            'body_html_template' => '<p>Hallo {{name}}</p>',
            'is_active' => true,
        ], ['Authorization' => 'Bearer '.$ownerToken])
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('template.type', 'account_created')
            ->assertJsonPath('template.subject_template', 'Welkom {{name}}');

        $this->getWithAuth($this->owner, '/api/internal/email/templates/account_created')
            ->assertStatus(200)
            ->assertJsonPath('type', 'account_created')
            ->assertJsonPath('subject_template', 'Welkom {{name}}');

        $this->assertDatabaseHas('email_templates', [
            'organization_id' => $this->org->id,
            'type' => 'account_created',
            'subject_template' => 'Welkom {{name}}',
            'is_active' => 1,
        ]);
    }

    public function test_dispatch_uses_active_template_override_for_type(): void
    {
        $ownerToken = $this->createBearerToken($this->owner);

        $this->putJson('/api/internal/email/templates/account_created', [
            'subject_template' => 'Welkom {{name}}',
            'body_text_template' => 'Beste {{name}}, uw account voor {{email}} is gereed.',
            'body_html_template' => '<p>Beste {{name}}, account voor <strong>{{email}}</strong> is gereed.</p>',
            'is_active' => true,
        ], ['Authorization' => 'Bearer '.$ownerToken])->assertStatus(200);

        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', [
            'recipient' => 'render@example.nl',
            'subject' => 'fallback-subject',
            'body_text' => 'fallback-text',
            'body_html' => '<p>fallback-html</p>',
            'type' => 'account_created',
            'organization_id' => $this->org->id,
            'template_vars' => [
                'name' => 'Jan',
                'email' => 'render@example.nl',
            ],
        ])->assertStatus(202);

        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'type' => 'account_created',
            'recipient' => 'render@example.nl',
            'subject' => 'Welkom Jan',
        ]);
    }

    public function test_boekhouder_cannot_manage_templates(): void
    {
        $boekhouderToken = $this->createBearerToken($this->boekhouder);

        $this->putJson('/api/internal/email/templates/monthly_report', [
            'subject_template' => 'Niet toegestaan',
            'body_text_template' => 'x',
            'body_html_template' => '<p>x</p>',
        ], ['Authorization' => 'Bearer '.$boekhouderToken])
            ->assertStatus(403)
            ->assertJsonPath('message', 'Onvoldoende rechten voor e-mailtemplates.');

        $this->getWithAuth($this->boekhouder, '/api/internal/email/templates/monthly_report')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Onvoldoende rechten voor e-mailtemplates.');
    }

    public function test_owner_cannot_upsert_template_with_unsupported_type(): void
    {
        $ownerToken = $this->createBearerToken($this->owner);

        $this->putJson('/api/internal/email/templates/unsupported_type', [
            'subject_template' => 'Niet toegestaan',
            'body_text_template' => 'x',
            'body_html_template' => '<p>x</p>',
        ], ['Authorization' => 'Bearer '.$ownerToken])
            ->assertStatus(422)
            ->assertJsonPath('errors.type.0', 'Ongeldig e-mailtemplate type.');
    }

    public function test_owner_can_upsert_and_fetch_welcome_email_template(): void
    {
        $ownerToken = $this->createBearerToken($this->owner);

        $this->putJson('/api/internal/email/templates/welcome_email', [
            'subject_template' => 'Welkom {{ full_name }}',
            'body_text_template' => 'Beste {{ full_name }}, uw account is gereed. Login: {{ login_url }}',
            'body_html_template' => '<p>Beste {{ full_name }}, uw account is gereed. <a href="{{ login_url }}">Inloggen</a></p>',
            'is_active' => true,
        ], ['Authorization' => 'Bearer '.$ownerToken])
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('template.type', 'welcome_email')
            ->assertJsonPath('template.subject_template', 'Welkom {{ full_name }}');

        $this->getWithAuth($this->owner, '/api/internal/email/templates/welcome_email')
            ->assertStatus(200)
            ->assertJsonPath('type', 'welcome_email')
            ->assertJsonPath('subject_template', 'Welkom {{ full_name }}');

        $this->assertDatabaseHas('email_templates', [
            'organization_id' => $this->org->id,
            'type' => 'welcome_email',
            'subject_template' => 'Welkom {{ full_name }}',
            'is_active' => 1,
        ]);
    }

    public function test_dispatch_escapes_html_in_template_vars_for_html_body(): void
    {
        $ownerToken = $this->createBearerToken($this->owner);

        $this->putJson('/api/internal/email/templates/account_created', [
            'subject_template' => 'Welkom {{name}}',
            'body_text_template' => 'Beste {{name}}',
            'body_html_template' => '<p>Beste {{name}}</p>',
            'is_active' => true,
        ], ['Authorization' => 'Bearer '.$ownerToken])->assertStatus(200);

        $this->postWithAuth($this->owner, '/api/internal/email/dispatch', [
            'recipient' => 'escape@example.nl',
            'subject' => 'fallback-subject',
            'body_text' => 'fallback-text',
            'body_html' => '<p>fallback-html</p>',
            'type' => 'account_created',
            'organization_id' => $this->org->id,
            'template_vars' => [
                'name' => '<script>alert(1)</script>',
            ],
        ])->assertStatus(202);

        $this->assertDatabaseHas('email_outbox', [
            'organization_id' => $this->org->id,
            'type' => 'account_created',
            'recipient' => 'escape@example.nl',
            'body_html' => '<p>Beste &lt;script&gt;alert(1)&lt;/script&gt;</p>',
        ]);
    }
}
