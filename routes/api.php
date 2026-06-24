<?php

use App\Http\Controllers\Internal\AgentRunController;
use App\Http\Controllers\Internal\ConversationHandoffController;
use App\Http\Controllers\Internal\HealthController;
use App\Http\Controllers\Internal\InboxConversationController;
use App\Http\Controllers\Internal\InboxMessageController;
use App\Http\Controllers\Internal\InboundMessageController;
use App\Http\Controllers\Internal\OrchestraTenantEventController;
use App\Http\Controllers\Internal\OutboundMessageController;
use App\Http\Controllers\Internal\TenantDatabaseProvisionController;
use App\Http\Controllers\Internal\TenantSyncController;
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

    Route::get('/internal/inbox/conversations', [InboxConversationController::class, 'index'])
        ->middleware('service.token');

    Route::get('/internal/inbox/conversations/{conversation_id}/messages', [InboxMessageController::class, 'index'])
        ->middleware('service.token');

    Route::post('/internal/inbox/conversations/{conversation_id}/request-handoff', [ConversationHandoffController::class, 'requestHandoff'])
        ->middleware('service.token');

    Route::post('/internal/inbox/conversations/{conversation_id}/assign', [ConversationHandoffController::class, 'assign'])
        ->middleware('service.token');

    Route::post('/internal/inbox/conversations/{conversation_id}/close', [ConversationHandoffController::class, 'close'])
        ->middleware('service.token');

    Route::post('/internal/inbox/conversations/{conversation_id}/reopen', [ConversationHandoffController::class, 'reopen'])
        ->middleware('service.token');

    Route::get('/internal/inbox/conversations/{conversation_id}', [InboxConversationController::class, 'show'])
        ->middleware('service.token');

    Route::post('/internal/agent/runs', AgentRunController::class)
        ->middleware('service.token');

    Route::post('/internal/tenants/sync', TenantSyncController::class)
        ->middleware('service.token');

    Route::post('/internal/tenants/{orchestra_tenant_id}/provision-database', TenantDatabaseProvisionController::class)
        ->middleware('service.token');

    Route::post('/internal/orchestra/events/tenants', OrchestraTenantEventController::class)
        ->middleware('service.token');
});

Route::post('/providers/zapi/webhook', ZapiWebhookController::class)
    ->middleware(['throttle:provider-webhooks', 'provider.webhook.signature']);
