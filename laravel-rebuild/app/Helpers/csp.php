<?php

declare(strict_types=1);

if (! function_exists('csp_nonce')) {
    /**
     * Haal de CSP-nonce op voor het huidige request.
     * Wordt gebruikt in Blade-templates: <script nonce="{{ csp_nonce() }}">
     */
    function csp_nonce(): string
    {
        $nonce = request()->attributes->get('csp_nonce');

        if ($nonce === null) {
            $nonce = base64_encode(random_bytes(16));
            request()->attributes->set('csp_nonce', $nonce);
        }

        return $nonce;
    }
}
