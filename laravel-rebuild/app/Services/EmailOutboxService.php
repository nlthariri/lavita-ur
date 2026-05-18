<?php

namespace App\Services;

use App\Models\EmailOutbox;
use App\Models\EmailOutboxEvent;
use App\Models\MonthlyReportRun;
use App\Models\User;
use App\Models\WorkEntry;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class EmailOutboxService
{
    private const BATCH_SIZE = 50;

    private const MAX_RETRY_DELAY_SECONDS = 300; // 5 minuten

    private const MAX_RETRY_COUNT = 5;

    public function __construct(
        private readonly EmailTemplateService $emailTemplateService,
    ) {}

    /**
     * Voeg een e-mail toe aan de outbox (idempotent via sleutel).
     */
    public function dispatch(array $input, array $actorContext = []): array
    {
        $input = $this->emailTemplateService->applyTemplate($input);
        $this->assertDispatchInput($input, $actorContext);

        $idempotencyKey = $input['idempotency_key']
            ?? Str::uuid()->toString();

        $existing = EmailOutbox::where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            $this->recordEvent(
                outboxId: (int) $existing->id,
                eventType: 'idempotent_hit',
                actorContext: $actorContext,
                payload: ['idempotency_key' => $idempotencyKey],
            );

            return [
                'id' => $existing->id,
                'status' => $existing->status,
                'idempotent' => true,
                'correlation_id' => $existing->correlation_id,
            ];
        }

        $organizationId = (int) ($input['organization_id'] ?? 0);

        try {
            $item = DB::transaction(function () use ($input, $actorContext, $idempotencyKey, $organizationId): EmailOutbox {
                $item = EmailOutbox::create([
                    'idempotency_key' => $idempotencyKey,
                    'organization_id' => $organizationId,
                    'user_id' => $input['user_id'] ?? null,
                    'initiator_actor_id' => $actorContext['actor_id'] ?? null,
                    'initiator_role_snapshot' => $actorContext['role'] ?? null,
                    'initiator_org_id_snapshot' => $actorContext['organization_id'] ?? null,
                    'monthly_report_run_id' => $input['monthly_report_run_id'] ?? null,
                    'request_id' => $actorContext['request_id'] ?? null,
                    'source_ip' => $actorContext['source_ip'] ?? null,
                    'user_agent' => isset($actorContext['user_agent']) ? substr((string) $actorContext['user_agent'], 0, 500) : null,
                    'correlation_id' => $actorContext['correlation_id'] ?? null,
                    'recipient' => $input['recipient'],
                    'subject' => $input['subject'],
                    'subject_sha256' => hash('sha256', (string) $input['subject']),
                    'body_text' => $input['body_text'],
                    'body_text_sha256' => hash('sha256', (string) $input['body_text']),
                    'body_html' => $input['body_html'],
                    'body_html_sha256' => hash('sha256', (string) $input['body_html']),
                    'type' => $input['type'] ?? 'custom',
                    'attachments' => $input['attachments'] ?? null,
                    'status' => 'queued',
                    'retry_count' => 0,
                    'next_attempt_at' => now(),
                ]);

                $this->recordEvent(
                    outboxId: (int) $item->id,
                    eventType: 'queued',
                    actorContext: $actorContext,
                    payload: [
                        'organization_id' => $organizationId,
                        'recipient' => $item->recipient,
                        'type' => $item->type,
                        'monthly_report_run_id' => $item->monthly_report_run_id,
                    ],
                );

                return $item;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                $existing = EmailOutbox::where('idempotency_key', $idempotencyKey)->first();
                if ($existing) {
                    $this->recordEvent(
                        outboxId: (int) $existing->id,
                        eventType: 'idempotent_hit',
                        actorContext: $actorContext,
                        payload: ['idempotency_key' => $idempotencyKey, 'source' => 'unique_conflict_recovery'],
                    );

                    return [
                        'id' => $existing->id,
                        'status' => $existing->status,
                        'idempotent' => true,
                        'correlation_id' => $existing->correlation_id,
                    ];
                }
            }

            throw $e;
        }

        return [
            'id' => $item->id,
            'status' => 'queued',
            'idempotent' => false,
            'correlation_id' => $item->correlation_id,
        ];
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }

    /**
     * Verwerk een batch openstaande outbox-items.
     * Exponential backoff: delay = min(2^retryCount, 300) seconden.
     *
     * Per-item processing: locks worden alleen gehouden tijdens korte
     * DB-updates, NIET tijdens SMTP-sends. Dit voorkomt deadlocks en
     * timeouts bij trage mailservers.
     */
    public function processBatch(): array
    {
        // Stap 1: Selecteer kandidaten ZONDER lock (snapshot van IDs).
        $candidateIds = EmailOutbox::whereIn('status', ['queued', 'retrying'])
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(self::BATCH_SIZE)
            ->pluck('id');

        $sent = 0;
        $failed = 0;

        foreach ($candidateIds as $itemId) {
            // Stap 2a: Korte transactie — claim het item met lockForUpdate
            // en verifieer dat het nog steeds eligible is.
            $item = DB::transaction(function () use ($itemId) {
                $item = EmailOutbox::where('id', $itemId)
                    ->lockForUpdate()
                    ->first();

                if ($item === null) {
                    return null;
                }

                // Verifieer eligibility (een andere worker kan het al hebben opgepakt)
                if (! in_array($item->status, ['queued', 'retrying'], true)) {
                    return null;
                }

                if ($item->next_attempt_at !== null && $item->next_attempt_at->gt(now())) {
                    return null;
                }

                // Record send_attempt event binnen de transactie
                $this->recordEvent(
                    outboxId: (int) $item->id,
                    eventType: 'send_attempt',
                    actorContext: [
                        'correlation_id' => $item->correlation_id,
                    ],
                    payload: [
                        'retry_count' => $item->retry_count,
                        'next_attempt_at' => $item->next_attempt_at?->toIso8601String(),
                    ],
                );

                return $item;
            });

            if ($item === null) {
                continue;
            }

            // Stap 2d: SMTP send BUITEN de transactie — geen DB-locks gehouden.
            $smtpSuccess = false;
            $smtpError = null;

            try {
                $this->sendSmtp($item);
                $smtpSuccess = true;
            } catch (\Throwable $e) {
                $smtpError = $e;
            }

            // Stap 2e: Korte transactie — update status op basis van resultaat.
            DB::transaction(function () use ($item, $smtpSuccess, $smtpError, &$sent, &$failed) {
                if ($smtpSuccess) {
                    $item->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                        'error_message' => null,
                    ]);

                    $this->recordEvent(
                        outboxId: (int) $item->id,
                        eventType: 'sent',
                        actorContext: [
                            'correlation_id' => $item->correlation_id,
                        ],
                    );

                    $sent++;
                } else {
                    $retryCount = $item->retry_count + 1;
                    $delaySec = min(2 ** $retryCount, self::MAX_RETRY_DELAY_SECONDS);
                    $isFinal = $retryCount >= self::MAX_RETRY_COUNT;

                    $item->update([
                        'status' => $isFinal ? 'failed' : 'retrying',
                        'retry_count' => $retryCount,
                        'next_attempt_at' => now()->addSeconds($delaySec),
                        'error_message' => substr($smtpError->getMessage(), 0, 1000),
                    ]);

                    $this->recordEvent(
                        outboxId: (int) $item->id,
                        eventType: $isFinal ? 'failed' : 'retry_scheduled',
                        actorContext: [
                            'correlation_id' => $item->correlation_id,
                        ],
                        payload: [
                            'retry_count' => $retryCount,
                            'delay_seconds' => $delaySec,
                            'error' => substr($smtpError->getMessage(), 0, 500),
                        ],
                    );

                    $failed++;

                    Log::warning('EmailOutbox: verzenden mislukt', [
                        'id' => $item->id,
                        'recipient' => $item->recipient,
                        'retry_count' => $retryCount,
                        'error' => $smtpError->getMessage(),
                    ]);
                }
            });
        }

        return ['sent' => $sent, 'failed' => $failed, 'processed' => $sent + $failed];
    }

    /**
     * Maandelijkse rapporten in de wachtrij zetten voor alle actieve
     * managers en owners van een organisatie.
     */
    public function queueMonthlyReports(int $organizationId, string $periodMonth, int $requesterId, array $actorContext = []): array
    {
        $this->assertRequester($organizationId, $requesterId, $actorContext);

        // Valideer periodMonth formaat (YYYY-MM)
        $start = Carbon::createFromFormat('Y-m', $periodMonth)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $run = MonthlyReportRun::create([
            'organization_id' => $organizationId,
            'period_month' => $periodMonth,
            'requested_by_actor_id' => $requesterId,
            'request_id' => $actorContext['request_id'] ?? null,
            'source_ip' => $actorContext['source_ip'] ?? null,
            'user_agent' => isset($actorContext['user_agent']) ? substr((string) $actorContext['user_agent'], 0, 500) : null,
            'correlation_id' => $actorContext['correlation_id'] ?? null,
            'dedupe_key' => 'monthly-report:'.$organizationId.':'.$periodMonth,
        ]);

        $recipients = User::where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('role', ['owner', 'manager'])
            ->get();

        $queued = 0;
        foreach ($recipients as $user) {
            $count = WorkEntry::where('organization_id', $organizationId)
                ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
                ->when($user->role === 'manager' && $user->team_id, function ($q) use ($user) {
                    $q->where('team_id', $user->team_id);
                })
                ->count();

            $labelNl = $start->locale('nl')->translatedFormat('F Y');
            $key = 'monthly-report-'.$organizationId.'-'.$periodMonth.'-'.$user->id;
            $safeName = e($user->name);
            $safeLabelNl = e($labelNl);

            $this->dispatch([
                'idempotency_key' => $key,
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'monthly_report_run_id' => $run->id,
                'recipient' => $user->email,
                'subject' => 'Maandrapport werkregels '.$labelNl,
                'body_text' => 'Beste '.$user->name.','.PHP_EOL.PHP_EOL
                    .'In de maand '.$labelNl.' zijn er '.$count.' werkregels geregistreerd.',
                'body_html' => '<p>Beste '.$safeName.',</p>'
                    .'<p>In de maand <strong>'.$safeLabelNl.'</strong> zijn er <strong>'.$count.'</strong> werkregels geregistreerd.</p>',
                'type' => 'monthly_report',
            ], $actorContext);
            $queued++;
        }

        return [
            'run_id' => $run->id,
            'period' => $periodMonth,
            'organization_id' => $organizationId,
            'initiated_by_user_id' => $requesterId,
            'correlation_id' => $run->correlation_id,
            'queued_for' => $queued,
        ];
    }

    private function assertDispatchInput(array $input, array $actorContext): void
    {
        $organizationId = (int) ($input['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            throw ValidationException::withMessages([
                'organization_id' => 'organization_id is verplicht voor e-mail dispatch.',
            ]);
        }

        if (! empty($actorContext['actor_id'])) {
            $actor = User::findOrFail((int) $actorContext['actor_id']);
            if ((int) $actor->organization_id !== $organizationId) {
                throw ValidationException::withMessages([
                    'organization_id' => 'Actor hoort niet bij deze organisatie.',
                ]);
            }
        }

        if (! empty($input['user_id'])) {
            $recipientUser = User::findOrFail((int) $input['user_id']);
            if ((int) $recipientUser->organization_id !== $organizationId) {
                throw ValidationException::withMessages([
                    'user_id' => 'Ontvanger hoort niet bij deze organisatie.',
                ]);
            }
        }
    }

    private function assertRequester(int $organizationId, int $requesterId, array $actorContext): void
    {
        $requester = User::findOrFail($requesterId);

        if (! in_array((string) $requester->role, ['owner', 'manager'], true)) {
            throw ValidationException::withMessages([
                'requester' => 'Alleen owner of manager kan maandrapportage starten.',
            ]);
        }

        if ((int) $requester->organization_id !== $organizationId) {
            throw ValidationException::withMessages([
                'organization_id' => 'Requester hoort niet bij deze organisatie.',
            ]);
        }

        if (! empty($actorContext['actor_id']) && (int) $actorContext['actor_id'] !== $requesterId) {
            throw ValidationException::withMessages([
                'requester' => 'Requester en actor-context komen niet overeen.',
            ]);
        }
    }

    private function recordEvent(int $outboxId, string $eventType, array $actorContext = [], ?array $payload = null): void
    {
        $previous = EmailOutboxEvent::where('outbox_id', $outboxId)
            ->latest('id')
            ->first();

        $previousHash = $previous?->event_hash;
        $occurredAt = now();
        $hashBase = [
            'outbox_id' => $outboxId,
            'event_type' => $eventType,
            'actor_id' => $actorContext['actor_id'] ?? null,
            'request_id' => $actorContext['request_id'] ?? null,
            'source_ip' => $actorContext['source_ip'] ?? null,
            'user_agent' => isset($actorContext['user_agent']) ? substr((string) $actorContext['user_agent'], 0, 500) : null,
            'correlation_id' => $actorContext['correlation_id'] ?? null,
            'payload' => $payload,
            'previous_event_hash' => $previousHash,
            'occurred_at' => $occurredAt->toIso8601String(),
        ];

        EmailOutboxEvent::create([
            'outbox_id' => $outboxId,
            'event_type' => $eventType,
            'actor_id' => $actorContext['actor_id'] ?? null,
            'request_id' => $actorContext['request_id'] ?? null,
            'source_ip' => $actorContext['source_ip'] ?? null,
            'user_agent' => isset($actorContext['user_agent']) ? substr((string) $actorContext['user_agent'], 0, 500) : null,
            'correlation_id' => $actorContext['correlation_id'] ?? null,
            'payload' => $payload,
            'previous_event_hash' => $previousHash,
            'event_hash' => $this->computeEventHash($hashBase),
            'occurred_at' => $occurredAt,
        ]);
    }

    public function verifyEventChainForOutbox(int $outboxId): bool
    {
        $events = EmailOutboxEvent::where('outbox_id', $outboxId)
            ->orderBy('id')
            ->get();

        $previousHash = null;

        foreach ($events as $event) {
            if ($event->previous_event_hash !== $previousHash) {
                return false;
            }

            $hashBase = [
                'outbox_id' => (int) $event->outbox_id,
                'event_type' => (string) $event->event_type,
                'actor_id' => $event->actor_id !== null ? (int) $event->actor_id : null,
                'request_id' => $event->request_id,
                'source_ip' => $event->source_ip,
                'user_agent' => $event->user_agent,
                'correlation_id' => $event->correlation_id,
                'payload' => $event->payload,
                'previous_event_hash' => $event->previous_event_hash,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ];

            if ($event->event_hash !== $this->computeEventHash($hashBase)) {
                return false;
            }

            $previousHash = $event->event_hash;
        }

        return true;
    }

    private function computeEventHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function sendSmtp(EmailOutbox $item): void
    {
        $config = config('mail');
        $smtpConfig = $config['mailers']['smtp'] ?? [];

        $host = $smtpConfig['host'] ?? 'localhost';
        $port = (int) ($smtpConfig['port'] ?? 587);
        $encryption = $smtpConfig['encryption'] ?? $smtpConfig['scheme'] ?? null;

        // Bepaal TLS-modus op basis van de encryption/scheme-instelling:
        // - 'tls' of 'ssl': forceer TLS (implicit TLS op poort 465, STARTTLS op 587)
        // - null/false: geen TLS (alleen voor lokale dev-omgevingen)
        $tls = in_array($encryption, ['tls', 'ssl'], true);

        $transport = new EsmtpTransport(
            host: $host,
            port: $port,
            tls: $tls,
        );

        // Stel een expliciete timeout in om te voorkomen dat een hangende
        // SMTP-server de queue worker blokkeert (default is PHP's
        // default_socket_timeout wat te lang kan zijn).
        $transport->getStream()->setTimeout(30);

        $username = $smtpConfig['username'] ?? '';
        $password = $smtpConfig['password'] ?? '';

        if ($username !== '' && $username !== 'null') {
            $transport->setUsername($username);
        }
        if ($password !== '' && $password !== 'null') {
            $transport->setPassword($password);
        }

        $mailer = new Mailer($transport);

        $fromAddress = $config['from']['address'] ?? 'no-reply@lavita.nl';
        $fromName = $config['from']['name'] ?? 'LaVita Urenregistratie';

        $email = (new Email)
            ->from(new Address($fromAddress, $fromName))
            ->to($item->recipient)
            ->subject($item->subject)
            ->text($item->body_text ?? '')
            ->html($item->body_html ?? '');

        $mailer->send($email);

        // Sluit de SMTP-verbinding expliciet om connection leaks te voorkomen
        $transport->stop();
    }
}
