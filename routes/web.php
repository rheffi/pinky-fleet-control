<?php

use App\Http\Controllers\FleetController;
use App\Http\Middleware\FleetMutation;
use Illuminate\Support\Facades\Route;

Route::view('/', 'environment');
Route::view('/environment', 'environment');

Route::prefix('api/v1')->middleware(FleetMutation::class)->group(function () {
    $controller = FleetController::class;
    Route::get('bootstrap', [$controller, 'bootstrap']);
    Route::get('snapshot', [$controller, 'snapshot']);
    Route::get('runs', [$controller, 'index']);
    Route::post('runs', [$controller, 'store']);
    Route::get('runs/{id}', [$controller, 'show'])->whereUuid('id');
    Route::post('runs/{id}/stop', [$controller, 'stop'])->whereUuid('id');
    Route::get('runs/{id}/events', [$controller, 'events'])->whereUuid('id');
});
