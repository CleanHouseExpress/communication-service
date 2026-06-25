<?php

namespace App\Actions\Messages;

use App\DTO\Messages\OutboundMessageData;
use App\Actions\Conversations\RecordConversationEventAction;
use App\Enums\ConversationEventType;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\ProviderType;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboundMessage;
use App\Services\Providers\ZapiClient;
use App\Actions\Tenancy\ResolveTenantRuntimeConnectionAction;
use App\Support\Tenancy\CurrentTenantConnection;
use App\Support\Tenancy\TenantResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class ProcessOutboundMessageAction
{
    public function __construct(
        private readonly ZapiClient $zapiClient,
        private readonly TenantResolver $tenantResolver,
        private readonly ResolveTenantRuntimeConnectionAction $resolveTenantRuntimeConnection,
        private readonly CurrentTenantConnection $currentTenantConnection,
        private readonly RecordConversationEventAction $recordConversationEvent,
    ) {}

    public function handle(OutboundMessageData $messageData): array
    {
        $this->tenantResolver->enforceIfEnabled($messageData->tenantId);
        $hadTenantContext = $this->currentTenantConnection->connectionName() !== null;
        $this->resolveTenantRuntimeConnection->handle($messageData->tenantId);

        try {
            return $this->transaction(function () use ($messageData): array {
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

            $communicationMessage = CommunicationMessage::create([
                'tenant_id' => $messageData->tenantId,
                'conversation_id' => $messageData->conversationId,
                'contact_id' => $messageData->contactId,
                'channel_id' => $messageData->channelId,
                'provider' => ProviderType::Zapi->value,
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
                'provider' => ProviderType::Zapi->value,
                'external_contact_id' => $messageData->externalContactId,
                'idempotency_key' => $messageData->idempotencyKey,
                'message_type' => $messageData->messageType->value,
                'text' => $messageData->text,
                'payload' => $messageData->payload,
                'status' => MessageStatus::Pending->value,
            ]);

            $outboundMessage->forceFill(['status' => MessageStatus::Sending->value])->save();
            $communicationMessage->forceFill(['status' => MessageStatus::Sending->value])->save();

            $result = match ($messageData->messageType->value) {
                'text' => $this->zapiClient->sendText($messageData->externalContactId, (string) $messageData->text),
                default => throw new InvalidArgumentException('Only text outbound messages are supported in this phase.'),
            };

            if ($result->success) {
                $outboundMessage->forceFill([
                    'status' => MessageStatus::Sent->value,
                    'provider_message_id' => $result->providerMessageId,
                    'provider_response' => $result->response,
                    'failed_reason' => null,
                    'sent_at' => now(),
                ])->save();

                $communicationMessage->forceFill([
                    'external_message_id' => $result->providerMessageId,
                    'status' => MessageStatus::Sent->value,
                ])->save();

                Log::info('Outbound message sent.', [
                    'tenant_id' => $outboundMessage->tenant_id,
                    'provider' => $outboundMessage->provider,
                    'message_id' => $communicationMessage->id,
                    'conversation_id' => $outboundMessage->conversation_id,
                    'status' => MessageStatus::Sent->value,
                ]);

                $this->recordConversationEvent->handle(
                    eventType: ConversationEventType::MessageSent,
                    tenantId: $communicationMessage->tenant_id,
                    conversationId: (string) $communicationMessage->conversation_id,
                    actorType: (string) ($messageData->payload['source'] ?? 'system'),
                    messageId: (string) $communicationMessage->id,
                    description: 'Outbound message sent.',
                    metadata: [
                        'provider' => $outboundMessage->provider,
                        'message_type' => $outboundMessage->message_type,
                        'source' => $messageData->payload['source'] ?? null,
                    ],
                    occurredAt: $outboundMessage->sent_at,
                );
            } else {
                $outboundMessage->forceFill([
                    'status' => MessageStatus::Failed->value,
                    'provider_response' => $result->response,
                    'failed_reason' => $result->error,
                ])->save();

                $communicationMessage->forceFill([
                    'status' => MessageStatus::Failed->value,
                ])->save();

                Log::warning('Outbound message failed.', [
                    'tenant_id' => $outboundMessage->tenant_id,
                    'provider' => $outboundMessage->provider,
                    'message_id' => $communicationMessage->id,
                    'conversation_id' => $outboundMessage->conversation_id,
                    'status' => MessageStatus::Failed->value,
                    'error' => $result->error,
                ]);
            }

            return [
                'outbound_message' => $outboundMessage->refresh(),
                'communication_message' => $communicationMessage->refresh(),
                'duplicate' => false,
            ];
            });
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
