<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Voegt enterprise-grade security headers toe aan alle responses.
 *
 * Headers conform OWASP Secure Headers Project:
 * - X-Content-Type-Options: nosniff (voorkomt MIME-sniffing)
 * - X-Frame-Options: DENY (voorkomt clickjacking)
 * - X-XSS-Protection: 0 (deprecated, maar expliciet uit voor moderne browsers)
 * - Referrer-Policy: strict-origin-when-cross-origin
 * - Permissions-Policy: beperkt browser-features
 * - Content-Security-Policy: strikte CSP voor XSS-preventie
 *
 * Requirements: NFR-7 (security), OWASP ASVS 14.4
 */
class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        // Voorkom caching van API-responses die gevoelige data bevatten
        // (tokens, MFA-secrets, persoonsgegevens). Alle /api/ routes
        // krijgen no-store; web-routes mogen browser-caching gebruiken.
        if ($request->is('api/*') || $request->is('auth/*')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        // CSP: Alpine.js/Livewire vereist 'unsafe-eval' voor x-data/x-on
        // expressies en 'unsafe-inline' voor Livewire's geïnjecteerde scripts.
        // Google Fonts vereist fonts.googleapis.com en fonts.gstatic.com.
        if (app()->environment('production')) {
            $nonce = $this->getCspNonce();
            $response->headers->set(
                'Content-Security-Policy',
                "default-src 'self'; script-src 'self' 'unsafe-eval' 'unsafe-inline' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data:; font-src 'self' https://fonts.gstatic.com; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self';"
            );
        }

        return $response;
    }

    /**
     * Genereer of haal de CSP-nonce op voor het huidige request.
     * De nonce wordt opgeslagen in de request-attributes zodat Blade
     * templates er via `csp_nonce()` bij kunnen.
     */
    private function getCspNonce(): string
    {
        $request = request();
        $nonce = $request->attributes->get('csp_nonce');

        if ($nonce === null) {
            $nonce = base64_encode(random_bytes(16));
            $request->attributes->set('csp_nonce', $nonce);
        }

        return $nonce;
    }
}
