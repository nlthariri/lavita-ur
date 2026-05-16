<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed variant van de standaard ThrottleRequests middleware.
 *
 * Bij uitval van de cache-backend (Redis, database, etc.) wordt de
 * request GEWEIGERD (429) in plaats van doorgelaten. Dit voorkomt
 * dat een cache-outage de beveiligingsgrens opheft.
 *
 * Pas uitsluitend toe op beveiligingsgevoelige routes zoals auth/mfa.
 */
class FailClosedThrottle extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = ''): Response
    {
        try {
            // Laravel's ThrottleRequests controleert `func_num_args() === 3` om named
            // rate limiters te onderscheiden van numerieke limiten. We moeten exact
            // hetzelfde aantal argumenten doorgeven aan de parent om die check te laten werken.
            if (is_string($maxAttempts) && func_num_args() === 3) {
                return parent::handle($request, $next, $maxAttempts);
            }

            return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
        } catch (\RuntimeException $e) {
            return $this->blockOnCacheFailure($request, $e);
        } catch (\Predis\Connection\ConnectionException $e) {
            return $this->blockOnCacheFailure($request, $e);
        } catch (\RedisException $e) {
            return $this->blockOnCacheFailure($request, $e);
        }
    }

    /**
     * Verwerkt cache-backend storingen: altijd 429 teruggeven (fail-closed).
     * Geen gevoelige details worden gelekt in de response.
     */
    private function blockOnCacheFailure(Request $request, \Throwable $e): Response
    {
        report($e); // logt de oorzaak voor ops-team

        return response()->json(
            ['error' => 'Te veel verzoeken. Probeer het later opnieuw.'],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => 60]
        );
    }
}
