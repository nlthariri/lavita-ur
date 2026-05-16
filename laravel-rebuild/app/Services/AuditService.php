<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;

class AuditService
{
    /**
     * Leg een audit-event vast. Fire-and-forget; gooit geen exceptions.
     */
    public function record(array $input): void
    {
        try {
            AuditEvent::create([
                'organization_id' => $input['organization_id'],
                'actor_id' => $input['actor_id'],
                'action' => $input['action'],
                'target_type' => $input['target_type'],
                'target_id' => (string) $input['target_id'],
                'before_data' => $input['before_data'] ?? null,
                'after_data' => $input['after_data'] ?? null,
                'request_id' => $input['request_id'] ?? null,
                'ip_address' => $input['ip_address'] ?? null,
                'user_agent' => $input['user_agent'] ?? null,
            ]);
        } catch (\Throwable) {
            // Audit mag de primaire flow nooit blokkeren
        }
    }

    /**
     * Haal meta-gegevens op uit een HTTP-request voor audit.
     */
    public function extractMeta(Request $request): array
    {
        return [
            'request_id' => $request->header('x-request-id'),
            'ip_address' => $request->header('x-forwarded-for')
                ? explode(',', $request->header('x-forwarded-for'))[0]
                : $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ];
    }

    /**
     * Exporteer audit-events voor owner/manager (org-scoped, max 10.000).
     */
    public function export(int $requesterId, array $filters): array
    {
        $requester = User::findOrFail($requesterId);

        if (!in_array($requester->role, ['owner', 'manager'], true)) {
            abort(403, 'Onvoldoende rechten voor audit export.');
        }

        $query = AuditEvent::where('organization_id', $requester->organization_id)
            ->orderBy('created_at', 'desc');

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (!empty($filters['target_type'])) {
            $query->where('target_type', $filters['target_type']);
        }
        if (!empty($filters['target_id'])) {
            $query->where('target_id', $filters['target_id']);
        }
        if (!empty($filters['actor_id'])) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }
        if (!empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        $events = $query->limit(10_000)->get();

        return [
            'count' => $events->count(),
            'events' => $events->toArray(),
        ];
    }
}
