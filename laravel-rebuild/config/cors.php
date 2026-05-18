<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Enterprise-configuratie: alleen het eigen domein mag API-requests doen.
    | In productie wordt APP_URL gebruikt als allowed origin.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [env('APP_URL', 'https://lavita.nl')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'X-Request-ID',
        'X-Requested-With',
    ],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 3600, // 1 uur preflight cache

    'supports_credentials' => true,

];
