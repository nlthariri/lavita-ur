<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfNotSecure
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isSecure() && app()->environment('production')) {
            // Gebruik getRequestUri() om de path+query te behouden en
            // combineer met een expliciete https-scheme. Dit voorkomt
            // problemen met `http://` in query-parameters.
            $secureUrl = 'https://'.$request->getHost().$request->getRequestUri();

            return redirect()->to($secureUrl, 308);
        }

        return $next($request);
    }
}
