<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $databaseConnected = false;

        try {
            DB::select('SELECT 1');
            $databaseConnected = true;
        } catch (Throwable $exception) {
            Log::warning('Database health check failed.', ['exception_type' => $exception::class]);
        }

        return response()->json([
            'status' => $databaseConnected ? 'ok' : 'degraded',
            'service' => 'pinky-fleet-control',
            'mode' => 'environment-check',
            'database' => $databaseConnected ? 'connected' : 'unavailable',
            'checked_at' => now()->toIso8601String(),
        ], $databaseConnected ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}
