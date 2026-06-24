<?php

use App\Http\Controllers\Internal\InboundMessageController;
use App\Http\Controllers\Providers\ZapiWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:internal-api')->group(function (): void {
    Route::get('/health', fn () => [
        'status' => 'ok',
        'service' => config('communication.service_name'),
    ]);

    Route::get('/version', fn () => [
        'service' => config('communication.service_name'),
        'version' => '0.1.0',
    ]);

    Route::get('/internal/health', fn () => [
        'status' => 'ok',
        'authenticated' => true,
    ])->middleware('service.token');

    Route::post('/internal/inbound/messages', InboundMessageController::class)
        ->middleware('service.token');
});

Route::post('/providers/zapi/webhook', ZapiWebhookController::class)
    ->middleware(['throttle:provider-webhooks', 'provider.webhook.signature']);
