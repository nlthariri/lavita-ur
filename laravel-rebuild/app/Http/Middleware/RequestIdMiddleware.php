<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Genereert een unieke request-ID voor elk inkomend verzoek.
 *
 * Als de client een `X-Request-ID` header meestuurt, wordt deze
 * gevalideerd en hergebruikt (max 128 tekens, alleen alfanumeriek + dash).
 * Anders wordt een UUID v4 gegenereerd.
 *
 * De request-ID wordt:
 * - Opgeslagen in request attributes (voor AuditService)
 * - Toegevoegd aan de response headers (voor client-side correlatie)
 * - Beschikbaar via request()->attributes->get('request_id')
 *
 * Requirements: NFR-8 (observability), OWASP ASVS 7.1
 */
class RequestIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('x-request-id');

        // Valideer client-supplied request-ID (voorkom header injection)
        if ($requestId !== null) {
            $requestId = substr($requestId, 0, 128);
            if (! preg_match('/^[a-zA-Z0-9\-_]+$/', $requestId)) {
                $requestId = null;
            }
        }

        if ($requestId === null) {
            $requestId = (string) Str::uuid();
        }

        // Sla op in request attributes voor gebruik door AuditService
        $request->attributes->set('request_id', $requestId);
        $request->headers->set('x-request-id', $requestId);

        $response = $next($request);

        // Voeg toe aan response voor client-side correlatie
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
