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

        if (! $bearerToken) {
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

        if (! $session || ! $session->user || ! $session->user->is_active) {
            return response()->json(['error' => 'Ongeldige of verlopen sessie.'], 401);
        }

        // Idle-timeout: als de sessie langer dan 30 minuten niet is
        // gebruikt, beschouw deze als verlopen. Dit voorkomt dat een
        // gestolen token uren later nog bruikbaar is.
        $idleTimeoutMinutes = 30;
        if ($session->last_seen_at && $session->last_seen_at->diffInMinutes(now()) > $idleTimeoutMinutes) {
            $session->update(['revoked_at' => now()]);

            return response()->json([
                'error' => 'Sessie verlopen door inactiviteit. Log opnieuw in.',
                'code' => 'SESSION_IDLE_TIMEOUT',
            ], 401);
        }

        // Session-hijacking detectie: vergelijk het huidige IP met het
        // oorspronkelijke login-IP. Bij /8 mismatch (class A) blokkeren
        // we hard — dit duidt op een compleet ander netwerk. Bij /16
        // mismatch loggen we alleen (mobiele gebruikers wisselen vaak
        // binnen hetzelfde netwerk).
        $currentIp = $request->ip();
        $sessionIp = $session->ip_address;
        if ($currentIp && $sessionIp) {
            if (! $this->isSameClassA($currentIp, $sessionIp)) {
                // Hard block: compleet ander netwerk → waarschijnlijk hijack
                report(new \RuntimeException(sprintf(
                    'Session hijack blocked: session=%s, current=%s, user_id=%d',
                    $sessionIp,
                    $currentIp,
                    $session->user_id,
                )));

                // Revoke de verdachte sessie
                $session->update(['revoked_at' => now()]);

                return response()->json([
                    'error' => 'Sessie ongeldig vanwege netwerkwijziging. Log opnieuw in.',
                    'code' => 'SESSION_IP_MISMATCH',
                ], 401);
            }

            if (! $this->isSameNetwork($currentIp, $sessionIp)) {
                // Soft warning: /16 mismatch — log maar blokkeer niet
                report(new \RuntimeException(sprintf(
                    'Session IP drift: session=%s, current=%s, user_id=%d',
                    $sessionIp,
                    $currentIp,
                    $session->user_id,
                )));
            }
        }

        if ($this->requiresVerifiedMfa($session->user->role) && ! $this->isMfaBootstrapRoute($request)) {
            $mfa = $session->user->mfaSecret;
            $isVerified = $mfa && $mfa->verified_at !== null && $mfa->disabled_at === null;

            if (! $isVerified) {
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
        if (! $session->last_seen_at || $session->last_seen_at->diffInSeconds(now()) >= 60) {
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

    /**
     * Vergelijk of twee IP-adressen in hetzelfde /8-subnet zitten (class A).
     * Een mismatch hier duidt op een compleet ander netwerk en is een
     * sterke indicator voor session hijacking.
     */
    private function isSameClassA(string $ip1, string $ip2): bool
    {
        // IPv6 of ongeldige IPs: skip de check (geen false positive)
        if (! filter_var($ip1, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || ! filter_var($ip2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        $parts1 = explode('.', $ip1);
        $parts2 = explode('.', $ip2);

        return $parts1[0] === $parts2[0];
    }

    /**
     * Vergelijk of twee IP-adressen in hetzelfde /16-subnet zitten.
     * Dit is een balans tussen security (session-hijacking detectie)
     * en usability (mobiele gebruikers wisselen vaak van IP binnen
     * hetzelfde netwerk).
     */
    private function isSameNetwork(string $ip1, string $ip2): bool
    {
        // IPv6 of ongeldige IPs: skip de check
        if (! filter_var($ip1, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            || ! filter_var($ip2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        $parts1 = explode('.', $ip1);
        $parts2 = explode('.', $ip2);

        // Vergelijk eerste twee octetten (/16 subnet)
        return $parts1[0] === $parts2[0] && $parts1[1] === $parts2[1];
    }
}
