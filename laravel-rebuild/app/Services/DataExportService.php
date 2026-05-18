<?php

namespace App\Services;

use App\Models\AtwViolation;
use App\Models\AuditEvent;
use App\Models\EmailOutbox;
use App\Models\Objection;
use App\Models\User;
use App\Models\WorkEntry;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DataExportService
{
    /**
     * Exporteer alle gerelateerde data voor een gebruiker (AVG inzage).
     *
     * @throws HttpException 403 FORBIDDEN_DATA_EXPORT als requester geen self of owner is
     */
    public function exportFor(int $userId, int $requesterId): array
    {
        $requester = User::findOrFail($requesterId);
        $targetUser = User::findOrFail($userId);

        // Autorisatie: alleen de gebruiker zelf of een owner binnen dezelfde organisatie
        $isSelf = $requesterId === $userId;
        $isOwner = $requester->role === 'owner'
            && (int) $requester->organization_id === (int) $targetUser->organization_id;

        if (! $isSelf && ! $isOwner) {
            abort(403, json_encode([
                'error' => 'U heeft geen toegang tot de data-export van deze gebruiker.',
                'code' => 'FORBIDDEN_DATA_EXPORT',
            ]));
        }

        $workEntries = WorkEntry::where('employee_id', $userId)
            ->orderBy('entry_date', 'desc')
            ->get()
            ->toArray();

        $workEntryIds = WorkEntry::where('employee_id', $userId)->pluck('id');

        $objections = Objection::whereIn('work_entry_id', $workEntryIds)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        $atwViolations = AtwViolation::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        $emailOutbox = EmailOutbox::where('user_id', $userId)
            ->where('status', 'sent')
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        $auditEvents = AuditEvent::where(function ($query) use ($userId) {
            $query->where('actor_id', $userId)
                ->orWhere('target_id', (string) $userId);
        })
            ->where('organization_id', $targetUser->organization_id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();

        return [
            'user' => $targetUser->toArray(),
            'work_entries' => $workEntries,
            'objections' => $objections,
            'atw_violations' => $atwViolations,
            'email_outbox' => $emailOutbox,
            'audit_events' => $auditEvents,
        ];
    }
}
