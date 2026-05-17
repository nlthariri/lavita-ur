<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dwingt de read-only beperking af voor de rol `boekhouder` (Requirement 3.3).
 *
 * Een geauthenticeerde gebruiker met rol `boekhouder` mag uitsluitend
 * `GET`-requests uitvoeren binnen de `/api/internal/*`-endpoints. Elke
 * andere HTTP-methode (POST, PUT, PATCH, DELETE, ...) wordt geweigerd met
 * HTTP 403 en foutcode `READ_ONLY_ROLE`. Voor andere rollen — of wanneer
 * er (nog) geen geauthenticeerde gebruiker is — laat de middleware het
 * verzoek ongewijzigd door zodat de volgende laag (autorisatie of
 * validatie) zijn werk kan doen.
 *
 * Deze middleware moet ná `InternalApiAuth` draaien zodat
 * `$request->user()` is gepopuleerd. De alias `bookkeeper.readonly` en de
 * route-groep-registratie worden in een aparte taak (3.2/3.3) toegevoegd.
 *
 * Validates: Requirements 3.3, 3.4, 3.5, 3.6
 */
class BookkeeperReadonly
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && (string) $user->role === 'boekhouder' && ! $this->isReadMethod($request)) {
            return response()->json([
                'error' => 'Boekhouder heeft alleen read-only toegang.',
                'code' => 'READ_ONLY_ROLE',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Bepaalt of de HTTP-methode als read-only mag worden beschouwd.
     *
     * Strikt volgens Requirement 3.3 ("elke HTTP-methode anders dan GET")
     * is uitsluitend `GET` toegestaan voor `boekhouder`.
     */
    private function isReadMethod(Request $request): bool
    {
        return strtoupper($request->getMethod()) === 'GET';
    }
}
