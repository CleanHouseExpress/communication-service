<?php

use App\Http\Controllers\Internal\AgentRunController;
use App\Http\Controllers\Internal\HealthController;
use App\Http\Controllers\Internal\InboundMessageController;
use App\Http\Controllers\Internal\OutboundMessageController;
use App\Http\Controllers\Providers\ZapiWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'status' => 'ok',
    'service' => config('communication.service_name'),
])->middleware('throttle:internal-health');

Route::get('/version', fn () => [
    'service' => config('communication.service_name'),
    'version' => '0.1.0',
])->middleware('throttle:internal-health');

Route::get('/internal/health', HealthController::class)
    ->middleware(['service.token', 'throttle:internal-health']);

Route::middleware('throttle:internal-api')->group(function (): void {
    Route::post('/internal/inbound/messages', InboundMessageController::class)
        ->middleware('service.token');

    Route::post('/internal/outbound/messages', OutboundMessageController::class)
        ->middleware('service.token');

    Route::post('/internal/agent/runs', AgentRunController::class)
        ->middleware('service.token');
});

Route::post('/providers/zapi/webhook', ZapiWebhookController::class)
    ->middleware(['throttle:provider-webhooks', 'provider.webhook.signature']);
