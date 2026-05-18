<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web-layer authenticatie-guard voor Livewire-pagina's.
 *
 * Controleert of er een geldige sessie-token in de Laravel-sessie staat
 * (opgeslagen na succesvolle MFA-verificatie). Zonder geldige sessie
 * wordt de gebruiker naar /inloggen geredirect.
 *
 * Dit is de web-equivalent van InternalApiAuth (die bearer tokens
 * valideert voor API-routes).
 *
 * Requirements: 6.1, 6.9, NFR-7
 */
class EnsureSessionAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionToken = session('auth_session_token');

        if (! $sessionToken) {
            return redirect()->route('login')
                ->with('message', 'Je moet ingelogd zijn om deze pagina te bekijken.');
        }

        $tokenHash = hash('sha256', $sessionToken);

        $authSession = AuthSession::query()
            ->with('user:id,name,email,role,organization_id,team_id,is_active')
            ->where('session_token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $authSession || ! $authSession->user || ! $authSession->user->is_active) {
            session()->forget('auth_session_token');

            return redirect()->route('login')
                ->with('message', 'Je sessie is verlopen. Log opnieuw in.');
        }

        // Idle-timeout: 30 minuten inactiviteit → sessie verlopen
        $idleTimeoutMinutes = 30;
        if ($authSession->last_seen_at && $authSession->last_seen_at->diffInMinutes(now()) > $idleTimeoutMinutes) {
            $authSession->update(['revoked_at' => now()]);
            session()->forget('auth_session_token');

            return redirect()->route('login')
                ->with('message', 'Je sessie is verlopen door inactiviteit. Log opnieuw in.');
        }

        // Update last_seen_at (throttled: max 1x per minuut)
        if (! $authSession->last_seen_at || $authSession->last_seen_at->diffInSeconds(now()) >= 60) {
            $authSession->update(['last_seen_at' => now()]);
        }

        // Maak de user beschikbaar via auth()->user() en $request->user()
        $request->setUserResolver(fn () => $authSession->user);

        return $next($request);
    }
}
