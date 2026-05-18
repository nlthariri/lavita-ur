<?php

use App\Http\Middleware\BookkeeperReadonly;
use App\Http\Middleware\EnsureSessionAuthenticated;
use App\Http\Middleware\FailClosedThrottle;
use App\Http\Middleware\HstsMiddleware;
use App\Http\Middleware\InternalApiAuth;
use App\Http\Middleware\RedirectIfNotSecure;
use App\Http\Middleware\RequestIdMiddleware;
use App\Http\Middleware\SecurityHeadersMiddleware;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Vertrouw proxies op basis van de TRUSTED_PROXY_IPS env-variabele.
        // In productie: stel in op de specifieke Cloud86 proxy-IP-ranges.
        // Voorbeeld: TRUSTED_PROXY_IPS=10.0.0.0/8,172.16.0.0/12
        // Fallback '*' voor lokale ontwikkeling.
        $trustedProxies = env('TRUSTED_PROXY_IPS', '*');
        $middleware->trustProxies(
            at: $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        $middleware->prepend([
            RedirectIfNotSecure::class,
            HstsMiddleware::class,
            SecurityHeadersMiddleware::class,
            RequestIdMiddleware::class,
        ]);

        $middleware->alias([
            'internal.auth' => InternalApiAuth::class,
            'throttle.secure' => FailClosedThrottle::class,
            'bookkeeper.readonly' => BookkeeperReadonly::class,
            'auth.session' => EnsureSessionAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Voorkom dat interne model-namen lekken in 404-responses.
        // Laravel's default handler toont "No query results for model [App\Models\WorkEntry]"
        // wat informatie lekt over de interne architectuur.
        $exceptions->render(function (ModelNotFoundException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => 'Resource niet gevonden.',
                ], 404);
            }

            return null; // Laat web-requests door naar de default handler
        });

        // Voorkom dat validatie-exceptions dubbel worden gewrapped
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'error' => 'Endpoint niet gevonden.',
                ], 404);
            }

            return null;
        });
    })->create();
