<?php

use App\Http\Controllers\Internal\AgentRunController;
use App\Http\Controllers\Internal\ConversationHandoffController;
use App\Http\Controllers\Internal\ConversationTimelineController;
use App\Http\Controllers\Internal\HealthController;
use App\Http\Controllers\Internal\InboundMessageController;
use App\Http\Controllers\Internal\InboxConversationController;
use App\Http\Controllers\Internal\InboxMessageController;
use App\Http\Controllers\Internal\InboxSummaryController;
use App\Http\Controllers\Internal\InternalConversationMessageController;
use App\Http\Controllers\Internal\OrchestraTenantEventController;
use App\Http\Controllers\Internal\OutboundMessageController;
use App\Http\Controllers\Internal\ProvisionWhatsappChannelController;
use App\Http\Controllers\Internal\TenantDatabaseProvisionController;
use App\Http\Controllers\Internal\TenantSyncController;
use App\Http\Controllers\Internal\WhatsAppChannelStatusController;
use App\Http\Controllers\Internal\WhatsAppInstanceController;
use App\Http\Controllers\Internal\WhatsAppMessageController;
use App\Http\Controllers\Internal\ZapiChannelConnectionController;
use App\Http\Controllers\Providers\EvolutionWebhookController;
use App\Http\Controllers\Providers\ZapiChannelConnectedWebhookController;
use App\Http\Controllers\Providers\ZapiChannelDisconnectedWebhookController;
use App\Http\Controllers\Providers\ZapiChannelMessagesWebhookController;
use App\Http\Controllers\Providers\ZapiChannelMessageStatusWebhookController;
use App\Http\Controllers\Providers\ZapiMessageStatusController;
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

    Route::post('/tenant/communication/channels/provision-whatsapp', ProvisionWhatsappChannelController::class)
        ->middleware('service.token');

    Route::get('/internal/inbox/conversations', [InboxConversationController::class, 'index'])
        ->middleware('service.token');

    Route::get('/internal/inbox/summary', InboxSummaryController::class)
        ->middleware('service.token');

    Route::get('/internal/inbox/conversations/{conversation_id}/messages', [InboxMessageController::class, 'index'])
        ->middleware('service.token');

    Route::get('/internal/inbox/conversations/{conversation_id}/messages/status', [InboxMessageController::class, 'status'])
        ->middleware('service.token');

    Route::get('/internal/inbox/conversations/{conversation_id}/timeline', ConversationTimelineController::class)
        ->middleware('service.token');

    Route::post('/internal/inbox/conversations/{conversation_id}/messages', [InternalConversationMessageController::class, 'send'])
        ->middleware('service.token');

    Route::post('/internal/inbox/conversations/{conversation_id}/request-handoff', [ConversationHandoffController::class, 'requestHandoff'])
        ->middleware('service.token');

    Route::post('/internal/inbox/conversations/{conversation_id}/assign', [ConversationHandoffController::class, 'assign'])
        ->middleware('service.token');

    Route::post('/internal/inbox/conversations/{conversation_id}/close', [ConversationHandoffController::class, 'close'])
        ->middleware('service.token');

    Route::post('/internal/inbox/conversations/{conversation_id}/reopen', [ConversationHandoffController::class, 'reopen'])
        ->middleware('service.token');

    Route::post('/internal/inbox/conversations/{conversation_id}/return-to-ai', [ConversationHandoffController::class, 'returnToAi'])
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

    Route::post('/internal/channels/z-api/{channel_id}/qr-code', [ZapiChannelConnectionController::class, 'qrCode'])
        ->middleware('service.token');

    Route::get('/internal/channels/z-api/{channel_id}/status', [ZapiChannelConnectionController::class, 'status'])
        ->middleware('service.token');

    Route::post('/internal/channels/z-api/{channel_id}/disconnect', [ZapiChannelConnectionController::class, 'disconnect'])
        ->middleware('service.token');

    Route::post('/internal/channels/z-api/{channel_id}/webhooks', [ZapiChannelConnectionController::class, 'configureWebhooks'])
        ->middleware('service.token');

    Route::get('/internal/communication/channels/whatsapp/status', WhatsAppChannelStatusController::class)
        ->middleware('service.token');

    Route::post('/internal/communication/channels/whatsapp/activate', [WhatsAppInstanceController::class, 'activate'])
        ->middleware('service.token');

    Route::post('/internal/communication/channels/whatsapp/qrcode/refresh', [WhatsAppInstanceController::class, 'refreshQrCode'])
        ->middleware('service.token');

    Route::post('/internal/communication/messages/whatsapp/text', [WhatsAppMessageController::class, 'text'])
        ->middleware('service.token');

    Route::post('/internal/communication/messages/whatsapp/image', [WhatsAppMessageController::class, 'image'])
        ->middleware('service.token');

    Route::post('/internal/communication/messages/whatsapp/document', [WhatsAppMessageController::class, 'document'])
        ->middleware('service.token');

    Route::post('/internal/communication/messages/whatsapp/audio', [WhatsAppMessageController::class, 'audio'])
        ->middleware('service.token');
});

Route::post('/providers/evolution/messages', [EvolutionWebhookController::class, 'messages'])
    ->middleware('throttle:provider-webhooks');

Route::post('/providers/evolution/message-status', [EvolutionWebhookController::class, 'status'])
    ->middleware('throttle:provider-webhooks');

Route::post('/webhooks/evolution', EvolutionWebhookController::class)
    ->middleware('throttle:provider-webhooks');

Route::post('/providers/zapi/webhook', ZapiWebhookController::class)
    ->middleware(['throttle:provider-webhooks', 'provider.webhook.signature']);

Route::post('/providers/zapi/message-status', ZapiMessageStatusController::class)
    ->middleware(['throttle:provider-webhooks', 'provider.webhook.signature']);

Route::post('/webhooks/z-api/{channel_id}/messages', ZapiChannelMessagesWebhookController::class)
    ->middleware(['throttle:provider-webhooks', 'provider.webhook.signature']);

Route::post('/webhooks/z-api/{channel_id}/message-status', ZapiChannelMessageStatusWebhookController::class)
    ->middleware(['throttle:provider-webhooks', 'provider.webhook.signature']);

Route::post('/webhooks/z-api/{channel_id}/connected', ZapiChannelConnectedWebhookController::class)
    ->middleware(['throttle:provider-webhooks', 'provider.webhook.signature']);

Route::post('/webhooks/z-api/{channel_id}/disconnected', ZapiChannelDisconnectedWebhookController::class)
    ->middleware(['throttle:provider-webhooks', 'provider.webhook.signature']);
