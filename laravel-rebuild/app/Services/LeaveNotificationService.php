<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\WorkEntry;
use Illuminate\Support\Str;

/**
 * LeaveNotificationService
 *
 * Verantwoordelijk voor het dispatchen van verlof-gerelateerde e-mails:
 *  - leave_approved: naar medewerker bij goedkeuring
 *  - leave_rejected: naar medewerker bij afwijzing
 *  - leave_requested: naar manager(s) van het team bij nieuwe aanvraag
 *
 * Respecteert opt-out logica:
 *  - Essentiële mails (goedkeuring/afwijzing) worden ALTIJD verstuurd.
 *  - Herinneringen worden alleen verstuurd als email_reminders_opt_in = true.
 *
 * Requirements: 13.1, 13.2, 13.3, 13.6, 13.7
 */
class LeaveNotificationService
{
    public function __construct(
        private readonly EmailOutboxService $emailOutboxService,
        private readonly EmailTemplateService $emailTemplateService,
    ) {}

    /**
     * Dispatch een 'leave_approved' e-mail naar de medewerker.
     * Dit is een essentiële mail — wordt altijd verstuurd ongeacht opt-in.
     */
    public function notifyApproved(WorkEntry $entry, User $approver): void
    {
        $employee = $entry->employee;
        if ($employee === null) {
            return;
        }

        $organizationId = (int) $entry->organization_id;
        $leaveType = $this->resolveLeaveTypeName($entry);
        $leaveDate = $entry->entry_date;

        $vars = [
            'full_name' => (string) ($employee->full_name ?? $employee->name),
            'leave_date' => (string) $leaveDate,
            'leave_type' => $leaveType,
            'approved_by' => (string) ($approver->full_name ?? $approver->name),
        ];

        $rendered = $this->emailTemplateService->render('leave_approved', $vars, $organizationId);

        $this->emailOutboxService->dispatch([
            'idempotency_key' => 'leave-approved-' . $entry->id . '-' . Str::uuid()->toString(),
            'organization_id' => $organizationId,
            'user_id' => (int) $employee->id,
            'recipient' => (string) $employee->email,
            'subject' => $rendered['subject'],
            'body_text' => $rendered['body_text'],
            'body_html' => $rendered['body_html'],
            'type' => 'leave_approved',
        ], [
            'actor_id' => (int) $approver->id,
            'organization_id' => $organizationId,
        ]);
    }

    /**
     * Dispatch een 'leave_rejected' e-mail naar de medewerker.
     * Dit is een essentiële mail — wordt altijd verstuurd ongeacht opt-in.
     */
    public function notifyRejected(WorkEntry $entry, User $rejector, string $reason = ''): void
    {
        $employee = $entry->employee;
        if ($employee === null) {
            return;
        }

        $organizationId = (int) $entry->organization_id;
        $leaveType = $this->resolveLeaveTypeName($entry);
        $leaveDate = $entry->entry_date;

        $vars = [
            'full_name' => (string) ($employee->full_name ?? $employee->name),
            'leave_date' => (string) $leaveDate,
            'leave_type' => $leaveType,
            'rejected_by' => (string) ($rejector->full_name ?? $rejector->name),
            'reason' => $reason !== '' ? $reason : 'Geen reden opgegeven',
        ];

        $rendered = $this->emailTemplateService->render('leave_rejected', $vars, $organizationId);

        $this->emailOutboxService->dispatch([
            'idempotency_key' => 'leave-rejected-' . $entry->id . '-' . Str::uuid()->toString(),
            'organization_id' => $organizationId,
            'user_id' => (int) $employee->id,
            'recipient' => (string) $employee->email,
            'subject' => $rendered['subject'],
            'body_text' => $rendered['body_text'],
            'body_html' => $rendered['body_html'],
            'type' => 'leave_rejected',
        ], [
            'actor_id' => (int) $rejector->id,
            'organization_id' => $organizationId,
        ]);
    }

    /**
     * Dispatch een 'leave_requested' e-mail naar de manager(s) van het team.
     * Dit is een essentiële mail — wordt altijd verstuurd ongeacht opt-in.
     */
    public function notifyRequested(WorkEntry $entry, User $requester): void
    {
        $organizationId = (int) $entry->organization_id;
        $teamId = (int) ($entry->team_id ?? $requester->team_id);

        if ($teamId <= 0) {
            return;
        }

        $leaveType = $this->resolveLeaveTypeName($entry);
        $leaveDate = $entry->entry_date;
        $employeeName = (string) ($requester->full_name ?? $requester->name);
        $note = trim((string) $entry->note);

        // Vind alle managers en owners van het team/organisatie.
        $managers = User::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where(function ($query) use ($teamId) {
                $query->where(function ($q) use ($teamId) {
                    $q->where('role', 'manager')
                      ->where('team_id', $teamId);
                })->orWhere('role', 'owner');
            })
            ->get();

        foreach ($managers as $manager) {
            $vars = [
                'employee_name' => $employeeName,
                'leave_date' => (string) $leaveDate,
                'leave_type' => $leaveType,
                'note' => $note !== '' ? $note : '—',
            ];

            $rendered = $this->emailTemplateService->render('leave_requested', $vars, $organizationId);

            $this->emailOutboxService->dispatch([
                'idempotency_key' => 'leave-requested-' . $entry->id . '-' . $manager->id,
                'organization_id' => $organizationId,
                'user_id' => (int) $manager->id,
                'recipient' => (string) $manager->email,
                'subject' => $rendered['subject'],
                'body_text' => $rendered['body_text'],
                'body_html' => $rendered['body_html'],
                'type' => 'leave_requested',
            ], [
                'actor_id' => (int) $requester->id,
                'organization_id' => $organizationId,
            ]);
        }
    }

    /**
     * Resolve de naam van het verlof-type voor weergave in e-mails.
     */
    private function resolveLeaveTypeName(WorkEntry $entry): string
    {
        $type = strtoupper((string) $entry->type);

        if ($type === 'LEAVE' && $entry->leave_type_id !== null) {
            $leaveType = $entry->leaveType;
            if ($leaveType !== null) {
                return (string) $leaveType->name;
            }
        }

        return match ($type) {
            'LEAVE' => 'Verlof',
            'SICK' => 'Ziekmelding',
            'HOLIDAY' => 'Feestdag',
            default => $type,
        };
    }
}
