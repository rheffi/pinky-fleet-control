<?php

use App\Http\Controllers\FleetAgentController;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\FleetAgent;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('agent/v1')->middleware(FleetAgent::class)->group(function () {
    Route::get('robots/{robot}/command', [FleetAgentController::class, 'command']);
    Route::post('robots/{robot}/telemetry', [FleetAgentController::class, 'telemetry']);
});