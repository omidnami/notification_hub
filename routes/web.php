<?php

use App\Http\Controllers\BaleConnectionController;
use App\Http\Controllers\BaleWebhookController;
use App\Http\Controllers\RubikaConnectionController;
use App\Http\Controllers\RubikaWebhookController;
use App\Http\Controllers\EitaaConnectionController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'throttle:10,1'])->prefix('api/v1/channels/bale')->group(function (): void {
    Route::get('/connection', [BaleConnectionController::class, 'show']);
    Route::post('/connect', [BaleConnectionController::class, 'connect']);
    Route::delete('/connection', [BaleConnectionController::class, 'disconnect']);
});

Route::post('/hooks/bale/{secret}', BaleWebhookController::class)
    ->middleware('throttle:300,1')
    ->where('secret', '[A-Za-z0-9_-]{32,128}')
    ->name('bale.webhook');

Route::middleware(['auth', 'throttle:10,1'])->prefix('api/v1/channels/rubika')->group(function (): void {
    Route::get('/connection', [RubikaConnectionController::class, 'show']);
    Route::post('/connect', [RubikaConnectionController::class, 'connect']);
    Route::delete('/connection', [RubikaConnectionController::class, 'disconnect']);
});

Route::post('/hooks/rubika/{secret}', RubikaWebhookController::class)
    ->middleware('throttle:300,1')
    ->where('secret', '[A-Za-z0-9_-]{32,128}')
    ->name('rubika.webhook');

Route::middleware(['auth', 'throttle:10,1'])->prefix('api/v1/channels/eitaa')->group(function (): void {
    Route::get('/connection', [EitaaConnectionController::class, 'show']);
    Route::post('/connect', [EitaaConnectionController::class, 'connect']);
    Route::delete('/connection', [EitaaConnectionController::class, 'disconnect']);
});

// Authenticated by signed Eitaa initData and a short-lived one-time pairing code.
Route::post('/api/v1/channels/eitaa/complete', [EitaaConnectionController::class, 'complete'])
    ->middleware('throttle:10,1');
