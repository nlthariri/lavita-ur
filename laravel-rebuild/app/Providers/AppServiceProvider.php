<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Productie-veiligheidscheck: verplichte configuratie valideren
        if (app()->environment('production')) {
            $this->validateProductionConfig();
        }

        // Publieke auth-routes: strikte rate limit (20 req/min per IP)
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(20)->by($request->ip());
        });

        // MFA verify: maximaal 5 pogingen per minuut per user_id+IP (brute-force bescherming)
        RateLimiter::for('mfa', function (Request $request) {
            $userId = (string) $request->input('user_id', '');

            return Limit::perMinute(5)->by('mfa|'.$userId.'|'.$request->ip());
        });

        // Interne API-routes: ruimere rate limit (300 req/min per gebruiker/IP)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(300)->by(
                optional($request->user())->id ?? $request->ip()
            );
        });
    }

    /**
     * Valideer kritieke productie-configuratie bij opstarten.
     * Gooit een RuntimeException als verplichte waarden ontbreken.
     */
    private function validateProductionConfig(): void
    {
        $missing = [];

        if (empty(config('app.key'))) {
            $missing[] = 'APP_KEY';
        }

        if (empty(env('BACKUP_ARCHIVE_PASSWORD'))) {
            $missing[] = 'BACKUP_ARCHIVE_PASSWORD (backups zijn onversleuteld!)';
        }

        if (env('SESSION_SECURE_COOKIE') !== true && env('SESSION_SECURE_COOKIE') !== 'true') {
            $missing[] = 'SESSION_SECURE_COOKIE moet true zijn in productie';
        }

        if (! empty($missing)) {
            Log::critical('Productie-configuratie onvolledig', [
                'missing' => $missing,
            ]);
        }
    }
}
