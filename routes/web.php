<?php

use App\Http\Controllers\BaleConnectionController;
use App\Http\Controllers\BaleWebhookController;
use App\Http\Controllers\RubikaConnectionController;
use App\Http\Controllers\RubikaWebhookController;
use App\Http\Controllers\EitaaConnectionController;
use App\Http\Controllers\GmailOAuthController;
use App\Http\Controllers\SmtpAccountController;
use App\Http\Controllers\EmailAccountsController;
use App\Http\Controllers\EmailApiController;
use App\Http\Controllers\MailGatewayController;
use App\Http\Controllers\EmailProjectKeysController;
use App\Http\Middleware\AuthenticateEmailProject;
use App\Http\Middleware\VerifyMailGateway;
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


Route::middleware(['auth', 'throttle:20,1'])->prefix('api/v1/email/accounts')->group(function (): void {
    Route::get('/', [EmailAccountsController::class, 'index']);
    Route::get('/gmail/authorize', [GmailOAuthController::class, 'begin']);
    Route::post('/smtp', [SmtpAccountController::class, 'store']);
    Route::post('/smtp/{id}/verify', [SmtpAccountController::class, 'verify']);
    Route::post('/{id}/projects', [EmailAccountsController::class, 'allowProject']);
    Route::delete('/{id}/projects', [EmailAccountsController::class, 'disallowProject']);
    Route::delete('/{id}', [EmailAccountsController::class, 'disconnect']);
});

Route::get('/api/v1/email/accounts/gmail/callback', [GmailOAuthController::class, 'callback'])
    ->middleware(['auth', 'throttle:20,1'])->name('gmail.callback');

Route::middleware([AuthenticateEmailProject::class, 'throttle:60,1'])->prefix('api/v1/emails')->group(function (): void {
    Route::post('/', [EmailApiController::class, 'store']);
    Route::get('/{id}', [EmailApiController::class, 'show']);
});

Route::post('/internal/mail-gateway', MailGatewayController::class)
    ->middleware([VerifyMailGateway::class, 'throttle:120,1']);


Route::middleware(['auth', 'throttle:20,1'])->prefix('api/v1/email/projects/{project}/keys')->group(function (): void {
    Route::get('/', [EmailProjectKeysController::class, 'index']);
    Route::post('/', [EmailProjectKeysController::class, 'store']);
    Route::delete('/{key}', [EmailProjectKeysController::class, 'destroy']);
});
