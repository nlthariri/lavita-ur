<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /**
     * Leg een audit-event vast. Fire-and-forget; gooit geen exceptions.
     *
     * Wanneer `ip_address` en `user_agent` niet expliciet zijn meegegeven,
     * worden ze automatisch uit het huidige request gehaald (indien
     * beschikbaar). Dit zorgt ervoor dat audit-events altijd zo compleet
     * mogelijk zijn, ook wanneer de aanroepende code vergeet de meta mee
     * te geven.
     */
    public function record(array $input): void
    {
        try {
            // Auto-enrich met request-context wanneer niet expliciet meegegeven.
            // Gebruik $request->ip() dat rekening houdt met TrustProxies
            // configuratie en niet direct X-Forwarded-For vertrouwt.
            $request = request();
            if (! isset($input['ip_address']) && $request !== null) {
                $input['ip_address'] = $request->ip();
            }
            if (! isset($input['user_agent']) && $request !== null) {
                $input['user_agent'] = substr((string) $request->userAgent(), 0, 500);
            }
            if (! isset($input['request_id']) && $request !== null) {
                $input['request_id'] = $request->header('x-request-id');
            }

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
        } catch (\Throwable $e) {
            // Audit mag de primaire flow nooit blokkeren, maar log de fout
            // zodat operationele monitoring het kan oppikken.
            Log::warning('Audit event recording failed', [
                'error' => $e->getMessage(),
                'action' => $input['action'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Haal meta-gegevens op uit een HTTP-request voor audit.
     * Gebruikt $request->ip() dat TrustProxies respecteert.
     */
    public function extractMeta(Request $request): array
    {
        return [
            'request_id' => $request->header('x-request-id'),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ];
    }

    /**
     * Exporteer audit-events voor owner/manager (org-scoped, max 10.000).
     */
    public function export(int $requesterId, array $filters): array
    {
        $requester = User::findOrFail($requesterId);

        if (! in_array($requester->role, ['owner', 'manager'], true)) {
            abort(403, 'Onvoldoende rechten voor audit export.');
        }

        $query = AuditEvent::where('organization_id', $requester->organization_id)
            ->orderBy('created_at', 'desc');

        if (! empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (! empty($filters['target_type'])) {
            $query->where('target_type', $filters['target_type']);
        }
        if (! empty($filters['target_id'])) {
            $query->where('target_id', $filters['target_id']);
        }
        if (! empty($filters['actor_id'])) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }
        if (! empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }
        if (! empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }

        $events = $query->limit(10_000)->get();

        return [
            'count' => $events->count(),
            'events' => $events->toArray(),
        ];
    }
}
