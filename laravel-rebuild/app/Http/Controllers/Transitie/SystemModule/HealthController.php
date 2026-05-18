<?php

namespace App\Http\Controllers\Transitie\SystemModule;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function getHealth(): JsonResponse
    {
        $checks = [
            'app' => 'ok',
            'database' => 'down',
            'cache' => 'down',
        ];

        try {
            DB::connection()->select('select 1');
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'down';
        }

        try {
            Cache::store()->put('health_check', true, 5);
            $checks['cache'] = Cache::store()->get('health_check') === true ? 'ok' : 'down';
        } catch (\Throwable) {
            $checks['cache'] = 'down';
        }

        $allOk = ! in_array('down', $checks, true);
        $status = $allOk ? 'ok' : 'degraded';

        return response()->json([
            'status' => $status,
            'service' => 'lavita-ur-laravel-rebuild',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $allOk ? 200 : 503);
    }

    public function getReady(): JsonResponse
    {
        try {
            DB::connection()->getPdo();

            return response()->json([
                'status' => 'ready',
                'service' => 'lavita-ur-laravel-rebuild',
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (\Throwable) {
            return response()->json([
                'status' => 'not_ready',
                'service' => 'lavita-ur-laravel-rebuild',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    }
}
