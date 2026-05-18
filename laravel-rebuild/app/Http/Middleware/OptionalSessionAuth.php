<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\AuthSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optionele sessie-authenticatie voor Livewire's update-endpoint.
 *
 * Verschil met EnsureSessionAuthenticated:
 *  - Geen redirect bij ontbrekende sessie — laat het request door.
 *  - Als er WEL een geldige sessie is, wordt Auth::user() gezet.
 *
 * Dit is nodig omdat Livewire's /livewire/update endpoint NIET door de
 * route-specifieke middleware gaat. Zonder deze middleware is Auth::user()
 * null bij Livewire-acties, wat 403-errors veroorzaakt in componenten
 * die Auth::user() checken.
 *
 * Auth-pagina's (login, MFA) hebben geen sessie en worden gewoon
 * doorgelaten — hun componenten verwachten geen Auth::user().
 */
class OptionalSessionAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $sessionToken = session('auth_session_token');

        if (! $sessionToken) {
            // Geen sessie-token → laat door (auth-pagina's of verlopen sessie).
            return $next($request);
        }

        $tokenHash = hash('sha256', $sessionToken);

        $authSession = AuthSession::query()
            ->with('user')
            ->where('session_token_hash', $tokenHash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $authSession || ! $authSession->user || ! $authSession->user->is_active) {
            // Ongeldige/verlopen sessie → laat door zonder user.
            // De component of de pagina-middleware handelt de redirect af.
            return $next($request);
        }

        // Idle-timeout: 30 minuten inactiviteit
        $idleTimeoutMinutes = 30;
        if ($authSession->last_seen_at && $authSession->last_seen_at->diffInMinutes(now()) > $idleTimeoutMinutes) {
            // Verlopen door inactiviteit → laat door zonder user.
            return $next($request);
        }

        // Update last_seen_at (throttled: max 1x per minuut)
        if (! $authSession->last_seen_at || $authSession->last_seen_at->diffInSeconds(now()) >= 60) {
            $authSession->update(['last_seen_at' => now()]);
        }

        // Maak de user beschikbaar via auth()->user() en $request->user()
        $request->setUserResolver(fn () => $authSession->user);
        Auth::setUser($authSession->user);

        return $next($request);
    }
}
