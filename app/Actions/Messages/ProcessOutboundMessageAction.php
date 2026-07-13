<?php

namespace App\Actions\Messages;

use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\DTO\Messages\OutboundMessageData;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\ProviderType;
use App\Jobs\SendOutboundMessageJob;
use App\Models\CommunicationChannel;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboundMessage;
use App\Support\Tenancy\CurrentTenantConnection;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessOutboundMessageAction
{
    public function __construct(
        private readonly TenantResolver $tenantResolver,
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
        private readonly SendPendingOutboundMessageAction $sendPendingOutboundMessage,
    ) {}

    public function handle(OutboundMessageData $messageData): array
    {
        $this->tenantResolver->enforceIfEnabled($messageData->tenantId);
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($messageData->tenantId);

        try {
            $result = $this->transaction(function () use ($messageData): array {
                $existing = CommunicationOutboundMessage::query()
                    ->where('idempotency_key', $messageData->idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    Log::info('Outbound message skipped as duplicate.', [
                        'tenant_id' => $existing->tenant_id,
                        'provider' => $existing->provider,
                        'message_id' => $existing->communication_message_id,
                        'conversation_id' => $existing->conversation_id,
                        'status' => 'duplicate',
                    ]);

                    return [
                        'outbound_message' => $existing,
                        'communication_message' => $existing->communicationMessage,
                        'duplicate' => true,
                    ];
                }

                $channel = CommunicationChannel::query()->find($messageData->channelId);
                $provider = $channel?->provider ?: ProviderType::Zapi->value;

                $communicationMessage = CommunicationMessage::create([
                    'tenant_id' => $messageData->tenantId,
                    'conversation_id' => $messageData->conversationId,
                    'contact_id' => $messageData->contactId,
                    'channel_id' => $messageData->channelId,
                    'provider' => $provider,
                    'external_message_id' => null,
                    'direction' => MessageDirection::Outbound->value,
                    'message_type' => $messageData->messageType->value,
                    'text' => $messageData->text,
                    'payload' => $messageData->payload,
                    'status' => MessageStatus::Pending->value,
                    'occurred_at' => now(),
                ]);

                $outboundMessage = CommunicationOutboundMessage::create([
                    'tenant_id' => $messageData->tenantId,
                    'channel_id' => $messageData->channelId,
                    'conversation_id' => $messageData->conversationId,
                    'contact_id' => $messageData->contactId,
                    'communication_message_id' => $communicationMessage->id,
                    'provider' => $provider,
                    'external_contact_id' => $messageData->externalContactId,
                    'idempotency_key' => $messageData->idempotencyKey,
                    'message_type' => $messageData->messageType->value,
                    'text' => $messageData->text,
                    'payload' => $messageData->payload,
                    'status' => MessageStatus::Pending->value,
                ]);

                return [
                    'outbound_message' => $outboundMessage->refresh(),
                    'communication_message' => $communicationMessage->refresh(),
                    'duplicate' => false,
                ];
            });

            if (! $result['duplicate']) {
                if ((bool) config('communication.queues.outbound.enabled', false)) {
                    SendOutboundMessageJob::dispatch(
                        (string) $result['outbound_message']->id,
                        $messageData->tenantId,
                    )->onQueue((string) config('communication.queues.outbound.name', 'communication-outbound'));
                } else {
                    $outboundMessage = $this->sendPendingOutboundMessage->handle(
                        (string) $result['outbound_message']->id,
                        $messageData->tenantId,
                    );

                    if ($outboundMessage !== null) {
                        $result['outbound_message'] = $outboundMessage;
                        $result['communication_message'] = $outboundMessage->communicationMessage;
                    }
                }
            }

            return $result;
        } finally {
            if (! $hadTenantContext) {
                $this->currentTenantConnection->clear();
            }
        }
    }

    private function transaction(callable $callback): mixed
    {
        $connectionName = $this->currentTenantConnection->connectionName();

        return $connectionName !== null
            ? DB::connection($connectionName)->transaction($callback)
            : DB::transaction($callback);
    }
}
