<?php

namespace App\Http\Middleware;

use App\Models\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Valideert een Bearer-token uit de Authorization-header.
 * Het token wordt SHA-256 gehasht en opgezocht in auth_sessions.
 * Verlopen en ingetrokken sessies worden afgewezen.
 */
class InternalApiAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (!$bearerToken) {
            return response()->json(['error' => 'Authenticatie vereist.'], 401);
        }

        $tokenHash = hash('sha256', $bearerToken);

        $session = AuthSession::query()
            ->with([
                'user:id,name,email,role,organization_id,team_id,is_active',
                'user.mfaSecret:id,user_id,verified_at,rotated_at,disabled_at',
            ])
            ->where('session_token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$session || !$session->user || !$session->user->is_active) {
            return response()->json(['error' => 'Ongeldige of verlopen sessie.'], 401);
        }

        if ($this->requiresVerifiedMfa($session->user->role) && !$this->isMfaBootstrapRoute($request)) {
            $mfa = $session->user->mfaSecret;
            $isVerified = $mfa && $mfa->verified_at !== null && $mfa->disabled_at === null;

            if (!$isVerified) {
                return response()->json(['error' => 'MFA verificatie is verplicht voor deze rol.'], 403);
            }

            // Rotatie-policy: MFA-secret mag maximaal 180 dagen oud zijn
            $lastRotation = $mfa->rotated_at ?? $mfa->verified_at;
            if ($lastRotation === null || $lastRotation->lt(now()->subDays(180))) {
                return response()->json([
                    'error' => 'MFA-secret is verlopen (>180 dagen). Roteer via /api/auth/mfa/setup.',
                    'code' => 'MFA_ROTATION_REQUIRED',
                ], 403);
            }
        }

        // Sessiestempel bijwerken (throttled: max 1x per minuut)
        if (!$session->last_seen_at || $session->last_seen_at->diffInSeconds(now()) >= 60) {
            $session->update(['last_seen_at' => now()]);
        }

        // Gebruiker beschikbaar via $request->user() of auth()->user()
        $request->setUserResolver(fn () => $session->user);

        return $next($request);
    }

    private function requiresVerifiedMfa(?string $role): bool
    {
        return in_array((string) $role, ['owner', 'manager'], true);
    }

    private function isMfaBootstrapRoute(Request $request): bool
    {
        return $request->is('api/auth/mfa/setup') || $request->is('api/auth/logout');
    }
}
