<?php

use App\Http\Controllers\BaleConnectionController;
use App\Http\Controllers\BaleWebhookController;
use App\Http\Controllers\RubikaConnectionController;
use App\Http\Controllers\RubikaWebhookController;
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
