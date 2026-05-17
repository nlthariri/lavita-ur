<?php

namespace Tests\Unit;

use App\Services\AtwEngine;
use App\Services\AtwService;
use App\Services\AuditService;
use App\Services\EmailOutboxService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Mockery;
use Tests\TestCase;

/**
 * Unit-tests voor {@see AtwService::throwOnCriticalSignals}.
 *
 * Gevalideerde requirements: 4.2, 4.3, 4.4, 4.5, 4.6, 4.9.
 *
 * De helper zelf raakt geen DB en geen mail — daarom mocken we
 * {@see EmailOutboxService} en gebruiken we de echte {@see AtwEngine}.
 */
class AtwServiceThrowOnCriticalSignalsTest extends TestCase
{
    private AtwService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AtwService(
            new AtwEngine(),
            Mockery::mock(EmailOutboxService::class),
            Mockery::mock(AuditService::class),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_no_signals_does_not_throw(): void
    {
        $this->service->throwOnCriticalSignals([]);

        $this->assertTrue(true);
    }

    public function test_warning_signals_are_non_blocking(): void
    {
        $signals = [
            [
                'type' => 'WEEKLY_WARNING',
                'severity' => 'warning',
                'message' => 'Naderende ATW-weeklimiet (48 uur of meer in huidige week).',
                'threshold_minutes' => 2880,
                'current_minutes' => 3000,
            ],
        ];

        $this->service->throwOnCriticalSignals($signals);

        $this->assertTrue(true);
    }

    public function test_sixteen_week_average_is_non_blocking(): void
    {
        // Requirement 4.6: het 16-weeks gemiddelde is wel `severity:critical`
        // maar mag de DB-write niet blokkeren — het is alleen een signaal.
        $signals = [
            [
                'type' => 'SIXTEEN_WEEK_AVERAGE',
                'severity' => 'critical',
                'message' => 'Gemiddelde over 16 weken overschrijdt 48 uur per week.',
                'threshold_minutes' => 2880,
                'current_minutes' => 2900,
            ],
        ];

        $this->service->throwOnCriticalSignals($signals);

        $this->assertTrue(true);
    }

    public function test_pause_required_throws_with_atw_pause_required_code(): void
    {
        $signals = [
            [
                'type' => 'PAUSE_REQUIRED',
                'severity' => 'critical',
                'message' => 'Bij meer dan 5,5 uur werken is minimaal 30 minuten pauze verplicht.',
                'threshold_minutes' => 30,
                'current_minutes' => 10,
            ],
        ];

        $payload = $this->captureThrownPayload($signals);

        $this->assertSame('ATW_PAUSE_REQUIRED', $payload['code']);
        $this->assertSame(
            'Bij meer dan 5,5 uur werken is minimaal 30 minuten pauze verplicht.',
            $payload['error']
        );
        $this->assertArrayHasKey('ATW_PAUSE_REQUIRED', $payload['errors']);
        $this->assertSame(10, $payload['meta']['current_minutes']);
        $this->assertSame(30, $payload['meta']['threshold_minutes']);
        $this->assertSame('PAUSE_REQUIRED', $payload['meta']['signal_type']);
    }

    public function test_daily_limit_throws_with_atw_daily_max_exceeded_code(): void
    {
        $signals = [[
            'type' => 'DAILY_LIMIT',
            'severity' => 'critical',
            'message' => 'Daglimiet bereikt of overschreden (12 uur).',
            'threshold_minutes' => 720,
            'current_minutes' => 720,
        ]];

        $payload = $this->captureThrownPayload($signals);

        $this->assertSame('ATW_DAILY_MAX_EXCEEDED', $payload['code']);
        $this->assertArrayHasKey('ATW_DAILY_MAX_EXCEEDED', $payload['errors']);
    }

    public function test_weekly_limit_throws_with_atw_weekly_max_exceeded_code(): void
    {
        $signals = [[
            'type' => 'WEEKLY_LIMIT',
            'severity' => 'critical',
            'message' => 'ATW-weeklimiet overschreden (60 uur).',
            'threshold_minutes' => 3600,
            'current_minutes' => 3700,
        ]];

        $payload = $this->captureThrownPayload($signals);

        $this->assertSame('ATW_WEEKLY_MAX_EXCEEDED', $payload['code']);
        $this->assertArrayHasKey('ATW_WEEKLY_MAX_EXCEEDED', $payload['errors']);
    }

    public function test_rest_period_throws_with_atw_rest_period_violated_code(): void
    {
        $signals = [[
            'type' => 'REST_PERIOD',
            'severity' => 'critical',
            'message' => 'Rusttijd tussen diensten is minder dan 11 uur.',
            'threshold_minutes' => 660,
            'current_minutes' => 480,
        ]];

        $payload = $this->captureThrownPayload($signals);

        $this->assertSame('ATW_REST_PERIOD_VIOLATED', $payload['code']);
        $this->assertArrayHasKey('ATW_REST_PERIOD_VIOLATED', $payload['errors']);
    }

    public function test_multiple_critical_signals_are_keyed_per_signal_type(): void
    {
        $signals = [
            [
                'type' => 'PAUSE_REQUIRED',
                'severity' => 'critical',
                'message' => 'Pauze-bericht.',
                'threshold_minutes' => 30,
                'current_minutes' => 5,
            ],
            [
                'type' => 'DAILY_LIMIT',
                'severity' => 'critical',
                'message' => 'Dag-bericht.',
                'threshold_minutes' => 720,
                'current_minutes' => 800,
            ],
            [
                'type' => 'WEEKLY_LIMIT',
                'severity' => 'critical',
                'message' => 'Week-bericht.',
                'threshold_minutes' => 3600,
                'current_minutes' => 3700,
            ],
        ];

        $payload = $this->captureThrownPayload($signals);

        // Het primaire signaal is het eerste in de invoer-volgorde.
        $this->assertSame('ATW_PAUSE_REQUIRED', $payload['code']);
        $this->assertSame('Pauze-bericht.', $payload['error']);

        $this->assertArrayHasKey('ATW_PAUSE_REQUIRED', $payload['errors']);
        $this->assertArrayHasKey('ATW_DAILY_MAX_EXCEEDED', $payload['errors']);
        $this->assertArrayHasKey('ATW_WEEKLY_MAX_EXCEEDED', $payload['errors']);

        $this->assertSame(['Pauze-bericht.'], $payload['errors']['ATW_PAUSE_REQUIRED']);
        $this->assertSame(['Dag-bericht.'], $payload['errors']['ATW_DAILY_MAX_EXCEEDED']);
        $this->assertSame(['Week-bericht.'], $payload['errors']['ATW_WEEKLY_MAX_EXCEEDED']);
    }

    public function test_warnings_are_filtered_when_combined_with_critical_signals(): void
    {
        $signals = [
            [
                'type' => 'WEEKLY_WARNING',
                'severity' => 'warning',
                'message' => 'Naderende ATW-weeklimiet.',
                'threshold_minutes' => 2880,
                'current_minutes' => 3000,
            ],
            [
                'type' => 'WEEKLY_LIMIT',
                'severity' => 'critical',
                'message' => 'ATW-weeklimiet overschreden (60 uur).',
                'threshold_minutes' => 3600,
                'current_minutes' => 3700,
            ],
            [
                'type' => 'SIXTEEN_WEEK_AVERAGE',
                'severity' => 'critical',
                'message' => '16-weken gemiddelde overschreden.',
                'threshold_minutes' => 2880,
                'current_minutes' => 3000,
            ],
        ];

        $payload = $this->captureThrownPayload($signals);

        $this->assertSame(['ATW_WEEKLY_MAX_EXCEEDED'], array_keys($payload['errors']));
        $this->assertSame('ATW_WEEKLY_MAX_EXCEEDED', $payload['code']);
    }

    public function test_status_is_422_and_response_is_json(): void
    {
        $signals = [[
            'type' => 'DAILY_LIMIT',
            'severity' => 'critical',
            'message' => 'Daglimiet bereikt of overschreden (12 uur).',
            'threshold_minutes' => 720,
            'current_minutes' => 720,
        ]];

        try {
            $this->service->throwOnCriticalSignals($signals);
            $this->fail('Verwachtte HttpResponseException.');
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
            $this->assertSame(422, $response->getStatusCode());
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
        }
    }

    public function test_no_audit_recorded_without_context(): void
    {
        // Wanneer de helper als pure validator wordt gebruikt (zonder
        // `$context`), MOET er geen `ATW_VIOLATION_BLOCKED`-audit-event
        // worden geschreven. We tellen aanroepen op een spy zodat we
        // expliciet kunnen asserten dat `record(...)` nooit is gebeurd.
        $recorded = [];
        $auditService = $this->makeRecordingAuditService($recorded);

        $service = new AtwService(
            new AtwEngine(),
            Mockery::mock(EmailOutboxService::class),
            $auditService,
        );

        try {
            $service->throwOnCriticalSignals([[
                'type' => 'DAILY_LIMIT',
                'severity' => 'critical',
                'message' => 'Daglimiet bereikt of overschreden (12 uur).',
                'threshold_minutes' => 720,
                'current_minutes' => 720,
            ]]);
            $this->fail('Verwachtte HttpResponseException.');
        } catch (HttpResponseException) {
            // verwacht
        }

        $this->assertSame([], $recorded, 'Audit-event mag niet geschreven worden zonder context.');
    }

    public function test_audit_event_recorded_per_critical_signal_with_context(): void
    {
        // Requirement 4.7: per kritiek signaal MOET er één
        // `ATW_VIOLATION_BLOCKED`-audit-event worden geschreven met
        // `signal_type`, `current_minutes`, `threshold_minutes` en
        // `employee_id` in `before_data`.
        $recorded = [];
        $auditService = $this->makeRecordingAuditService($recorded);

        $service = new AtwService(
            new AtwEngine(),
            Mockery::mock(EmailOutboxService::class),
            $auditService,
        );

        try {
            $service->throwOnCriticalSignals([
                [
                    'type' => 'PAUSE_REQUIRED',
                    'severity' => 'critical',
                    'message' => 'Pauze-bericht.',
                    'threshold_minutes' => 30,
                    'current_minutes' => 5,
                ],
                [
                    'type' => 'WEEKLY_LIMIT',
                    'severity' => 'critical',
                    'message' => 'Week-bericht.',
                    'threshold_minutes' => 3600,
                    'current_minutes' => 3700,
                ],
            ], [
                'organization_id' => 7,
                'actor_id' => 11,
                'employee_id' => 99,
                'target_id' => 42,
            ]);
            $this->fail('Verwachtte HttpResponseException.');
        } catch (HttpResponseException) {
            // verwacht
        }

        $this->assertCount(2, $recorded);

        $this->assertSame('ATW_VIOLATION_BLOCKED', $recorded[0]['action']);
        $this->assertSame('work_entry', $recorded[0]['target_type']);
        $this->assertSame('42', $recorded[0]['target_id']);
        $this->assertSame(7, $recorded[0]['organization_id']);
        $this->assertSame(11, $recorded[0]['actor_id']);
        $this->assertSame('PAUSE_REQUIRED', $recorded[0]['before_data']['signal_type']);
        $this->assertSame(5, $recorded[0]['before_data']['current_minutes']);
        $this->assertSame(30, $recorded[0]['before_data']['threshold_minutes']);
        $this->assertSame(99, $recorded[0]['before_data']['employee_id']);
        $this->assertNull($recorded[0]['after_data']);

        $this->assertSame('WEEKLY_LIMIT', $recorded[1]['before_data']['signal_type']);
        $this->assertSame(3700, $recorded[1]['before_data']['current_minutes']);
        $this->assertSame(3600, $recorded[1]['before_data']['threshold_minutes']);
        $this->assertSame(99, $recorded[1]['before_data']['employee_id']);
    }

    public function test_audit_target_id_is_empty_string_when_target_id_null(): void
    {
        // Bij create-pad is `target_id` `null` (geen werkregel-id beschikbaar);
        // de audit-laag MOET dat als lege string opslaan omdat
        // `audit_events.target_id` non-NULL is.
        $recorded = [];
        $auditService = $this->makeRecordingAuditService($recorded);

        $service = new AtwService(
            new AtwEngine(),
            Mockery::mock(EmailOutboxService::class),
            $auditService,
        );

        try {
            $service->throwOnCriticalSignals([[
                'type' => 'REST_PERIOD',
                'severity' => 'critical',
                'message' => 'Rust te kort.',
                'threshold_minutes' => 660,
                'current_minutes' => 480,
            ]], [
                'organization_id' => 1,
                'actor_id' => 2,
                'employee_id' => 3,
                'target_id' => null,
            ]);
            $this->fail('Verwachtte HttpResponseException.');
        } catch (HttpResponseException) {
            // verwacht
        }

        $this->assertCount(1, $recorded);
        $this->assertSame('', $recorded[0]['target_id']);
    }

    public function test_warnings_do_not_produce_audit_events(): void
    {
        // Non-blocking signalen (`WEEKLY_WARNING`, `SIXTEEN_WEEK_AVERAGE`)
        // mogen geen audit-event produceren — ze worden afzonderlijk via
        // `dispatchSignalsForCreatedEntry` geregistreerd in
        // `atw_violations`, niet als geblokkeerde poging.
        $recorded = [];
        $auditService = $this->makeRecordingAuditService($recorded);

        $service = new AtwService(
            new AtwEngine(),
            Mockery::mock(EmailOutboxService::class),
            $auditService,
        );

        $service->throwOnCriticalSignals([
            [
                'type' => 'WEEKLY_WARNING',
                'severity' => 'warning',
                'message' => 'Naderende ATW-weeklimiet.',
                'threshold_minutes' => 2880,
                'current_minutes' => 3000,
            ],
            [
                'type' => 'SIXTEEN_WEEK_AVERAGE',
                'severity' => 'critical',
                'message' => '16-weken gemiddelde.',
                'threshold_minutes' => 2880,
                'current_minutes' => 3000,
            ],
        ], [
            'organization_id' => 1,
            'actor_id' => 2,
            'employee_id' => 3,
            'target_id' => null,
        ]);

        $this->assertSame([], $recorded);
    }

    /**
     * Bouw een AuditService-stub die elke `record(...)`-aanroep in de
     * meegegeven `$recorded`-array bewaart, zodat tests expliciet kunnen
     * asserten op de payload (en op het uitblijven van aanroepen).
     */
    private function makeRecordingAuditService(array &$recorded): AuditService
    {
        return new class($recorded) extends AuditService {
            /** @var array<int, array<string, mixed>> */
            private array $sink;

            public function __construct(array &$sink)
            {
                $this->sink = &$sink;
            }

            public function record(array $input): void
            {
                $this->sink[] = $input;
            }
        };
    }

    /**
     * Roep `throwOnCriticalSignals` aan en geef de gedecodeerde JSON-body
     * van de response terug. Faalt expliciet wanneer er geen exception
     * gegooid wordt, zodat de aanroepende test geen vals-positief krijgt.
     *
     * @return array<string, mixed>
     */
    private function captureThrownPayload(array $signals): array
    {
        try {
            $this->service->throwOnCriticalSignals($signals);
        } catch (HttpResponseException $e) {
            return json_decode($e->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        }

        $this->fail('Verwachtte HttpResponseException, maar er is geen exception gegooid.');
    }
}
