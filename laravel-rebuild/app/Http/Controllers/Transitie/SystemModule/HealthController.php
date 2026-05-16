<?php

namespace App\Http\Controllers\Transitie\SystemModule;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function getHealth(): JsonResponse
    {
        $checks = [
            'app' => 'ok',
            'database' => 'down',
        ];

        try {
            DB::connection()->select('select 1');
            $checks['database'] = 'ok';
        } catch (\Throwable) {
            $checks['database'] = 'down';
        }

        $status = $checks['database'] === 'ok' ? 'ok' : 'degraded';

        return response()->json([
            'status' => $status,
            'service' => 'lavita-ur-laravel-rebuild',
            'checks' => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $status === 'ok' ? 200 : 503);
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
