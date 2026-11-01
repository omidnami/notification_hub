<?php

use App\Http\Controllers\BaleConnectionController;
use App\Http\Controllers\BaleWebhookController;
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
