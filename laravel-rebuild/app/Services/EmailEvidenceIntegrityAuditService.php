<?php

namespace App\Services;

use App\Models\EmailOutbox;
use App\Models\SystemJobRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EmailEvidenceIntegrityAuditService
{
    private const BATCH_SIZE = 200;

    public function run(?int $organizationId = null, ?int $outboxId = null, bool $failOnCorruption = false): array
    {
        $startedAt = CarbonImmutable::now();

        $jobRun = SystemJobRun::create([
            'organization_id' => $organizationId,
            'job_name' => 'integrity.email_evidence',
            'status' => 'started',
            'started_at' => $startedAt,
            'details' => [
                'organization_id' => $organizationId,
                'outbox_id' => $outboxId,
                'fail_on_corruption' => $failOnCorruption,
                'scanned' => 0,
                'tampered_outbox_ids' => [],
                'tamper_detected' => false,
            ],
        ]);

        try {
            $query = EmailOutbox::query()
                ->when($organizationId !== null, fn ($builder) => $builder->where('organization_id', $organizationId))
                ->when($outboxId !== null, fn ($builder) => $builder->where('id', $outboxId))
                ->orderBy('id');

            $scanned = 0;
            $tamperedOutboxIds = [];
            /** @var EmailOutboxService $emailOutboxService */
            $emailOutboxService = app(EmailOutboxService::class);

            $query->chunkById(self::BATCH_SIZE, function ($items) use (&$scanned, &$tamperedOutboxIds, $emailOutboxService): void {
                foreach ($items as $item) {
                    $scanned++;
                    $isValid = $emailOutboxService->verifyEventChainForOutbox((int) $item->id);
                    if (! $isValid) {
                        $tamperedOutboxIds[] = (int) $item->id;
                    }
                }
            });

            $tamperDetected = count($tamperedOutboxIds) > 0;
            $finishedAt = CarbonImmutable::now();
            $escalationDelivery = null;
            $incidentId = null;
            if ($tamperDetected) {
                $incidentId = Str::uuid()->toString();
                $escalationDelivery = $this->dispatchEscalation($jobRun->id, $organizationId, $tamperedOutboxIds, $scanned, $incidentId);
            }

            $details = [
                'organization_id' => $organizationId,
                'outbox_id' => $outboxId,
                'fail_on_corruption' => $failOnCorruption,
                'scanned' => $scanned,
                'tampered_outbox_ids' => $tamperedOutboxIds,
                'tamper_detected' => $tamperDetected,
                'escalation' => $tamperDetected
                    ? [
                        'severity' => 'critical',
                        'action' => 'security_escalation_required',
                        'reason' => 'email evidence hash-chain mismatch',
                        'incident_id' => $incidentId,
                    ]
                    : null,
                'escalation_delivery' => $escalationDelivery,
            ];

            if ($tamperDetected && $failOnCorruption) {
                $jobRun->update([
                    'status' => 'failed',
                    'finished_at' => $finishedAt,
                    'duration_ms' => $finishedAt->diffInMilliseconds($startedAt),
                    'rows_affected' => count($tamperedOutboxIds),
                    'details' => $details,
                    'error_message' => 'Tampering detected in email evidence chain.',
                ]);

                return [
                    'job_run_id' => $jobRun->id,
                    'summary' => $details,
                    'failed' => true,
                ];
            }

            $jobRun->update([
                'status' => 'completed',
                'finished_at' => $finishedAt,
                'duration_ms' => $finishedAt->diffInMilliseconds($startedAt),
                'rows_affected' => count($tamperedOutboxIds),
                'details' => $details,
            ]);

            return [
                'job_run_id' => $jobRun->id,
                'summary' => $details,
                'failed' => false,
            ];
        } catch (\Throwable $exception) {
            $finishedAt = CarbonImmutable::now();
            $jobRun->update([
                'status' => 'failed',
                'finished_at' => $finishedAt,
                'duration_ms' => $finishedAt->diffInMilliseconds($startedAt),
                'error_message' => substr($exception->getMessage(), 0, 2000),
            ]);

            throw $exception;
        }
    }

    private function dispatchEscalation(int $jobRunId, ?int $organizationId, array $tamperedOutboxIds, int $scanned, string $incidentId): array
    {
        $webhookUrl = (string) config('services.integrity_audit.webhook_url', '');
        if ($webhookUrl === '') {
            return [
                'status' => 'not_configured',
                'destination' => null,
                'incident_id' => $incidentId,
                'attempts' => 0,
                'acknowledged' => false,
                'open_incident' => true,
            ];
        }

        $payload = [
            'event' => 'integrity.email_evidence.tamper_detected',
            'incident_id' => $incidentId,
            'job_run_id' => $jobRunId,
            'organization_id' => $organizationId,
            'scanned' => $scanned,
            'tampered_outbox_ids' => $tamperedOutboxIds,
            'detected_at' => now()->toIso8601String(),
        ];

        $maxAttempts = 3;
        $lastHttpStatus = null;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = Http::timeout(5)->acceptJson()->asJson()->post($webhookUrl, $payload);

                if ($response->successful()) {
                    return [
                        'status' => 'sent',
                        'destination' => $webhookUrl,
                        'http_status' => $response->status(),
                        'incident_id' => $incidentId,
                        'attempts' => $attempt,
                        'acknowledged' => false,
                        'open_incident' => true,
                    ];
                }

                $lastHttpStatus = $response->status();
            } catch (\Throwable $exception) {
                $lastError = substr($exception->getMessage(), 0, 500);
            }
        }

        if ($lastHttpStatus !== null) {
            return [
                'status' => 'http_error',
                'destination' => $webhookUrl,
                'http_status' => $lastHttpStatus,
                'incident_id' => $incidentId,
                'attempts' => $maxAttempts,
                'acknowledged' => false,
                'open_incident' => true,
            ];
        }

        return [
            'status' => 'send_failed',
            'destination' => $webhookUrl,
            'incident_id' => $incidentId,
            'error' => $lastError,
            'attempts' => $maxAttempts,
            'acknowledged' => false,
            'open_incident' => true,
        ];
    }

    public function summarizeOpenEscalations(?int $organizationId = null): array
    {
        $runs = SystemJobRun::query()
            ->where('job_name', 'integrity.email_evidence')
            ->when($organizationId !== null, fn ($builder) => $builder->where('organization_id', $organizationId))
            ->whereNotNull('details')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $open = [];

        foreach ($runs as $run) {
            $details = is_array($run->details) ? $run->details : [];
            if (empty($details['tamper_detected'])) {
                continue;
            }

            $delivery = isset($details['escalation_delivery']) && is_array($details['escalation_delivery'])
                ? $details['escalation_delivery']
                : [];

            $status = (string) ($delivery['status'] ?? 'unknown');
            $acknowledged = (bool) ($delivery['acknowledged'] ?? false);
            $isOpen = in_array($status, ['not_configured', 'http_error', 'send_failed', 'unknown'], true)
                || ($status === 'sent' && ! $acknowledged);

            if ($isOpen) {
                $open[] = [
                    'job_run_id' => (int) $run->id,
                    'organization_id' => $run->organization_id !== null ? (int) $run->organization_id : null,
                    'incident_id' => $details['escalation']['incident_id'] ?? $delivery['incident_id'] ?? null,
                    'status' => $status,
                    'attempts' => (int) ($delivery['attempts'] ?? 0),
                ];
            }
        }

        return [
            'open_count' => count($open),
            'open_escalations' => $open,
        ];
    }

    public function acknowledgeIncident(string $incidentId, string $note = ''): bool
    {
        $run = $this->findJobRunByIncidentId($incidentId);
        if ($run === null) {
            return false;
        }

        $details = is_array($run->details) ? $run->details : [];
        $delivery = isset($details['escalation_delivery']) && is_array($details['escalation_delivery'])
            ? $details['escalation_delivery']
            : [];
        $escalation = isset($details['escalation']) && is_array($details['escalation'])
            ? $details['escalation']
            : [];

        $delivery['acknowledged'] = true;
        $delivery['acknowledged_at'] = now()->toIso8601String();
        $delivery['ack_note'] = $note !== '' ? $note : null;
        $delivery['open_incident'] = true;
        $delivery['incident_id'] = $incidentId;

        $escalation['incident_id'] = $incidentId;
        $escalation['state'] = 'acknowledged';

        $details['escalation_delivery'] = $delivery;
        $details['escalation'] = $escalation;

        $run->update(['details' => $details]);

        return true;
    }

    public function resolveIncident(string $incidentId, string $note = ''): bool
    {
        $run = $this->findJobRunByIncidentId($incidentId);
        if ($run === null) {
            return false;
        }

        $details = is_array($run->details) ? $run->details : [];
        $delivery = isset($details['escalation_delivery']) && is_array($details['escalation_delivery'])
            ? $details['escalation_delivery']
            : [];
        $escalation = isset($details['escalation']) && is_array($details['escalation'])
            ? $details['escalation']
            : [];

        $delivery['acknowledged'] = true;
        $delivery['acknowledged_at'] = $delivery['acknowledged_at'] ?? now()->toIso8601String();
        $delivery['resolved'] = true;
        $delivery['resolved_at'] = now()->toIso8601String();
        $delivery['resolve_note'] = $note !== '' ? $note : null;
        $delivery['open_incident'] = false;
        $delivery['incident_id'] = $incidentId;

        $escalation['incident_id'] = $incidentId;
        $escalation['state'] = 'resolved';

        $details['escalation_delivery'] = $delivery;
        $details['escalation'] = $escalation;

        $run->update(['details' => $details]);

        return true;
    }

    private function findJobRunByIncidentId(string $incidentId): ?SystemJobRun
    {
        $runs = SystemJobRun::query()
            ->where('job_name', 'integrity.email_evidence')
            ->whereNotNull('details')
            ->orderByDesc('id')
            ->limit(1000)
            ->get();

        foreach ($runs as $run) {
            $details = is_array($run->details) ? $run->details : [];
            $candidate = $details['escalation']['incident_id'] ?? $details['escalation_delivery']['incident_id'] ?? null;
            if ($candidate === $incidentId) {
                return $run;
            }
        }

        return null;
    }
}
