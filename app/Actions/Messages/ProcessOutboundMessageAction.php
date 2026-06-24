<?php

namespace App\Actions\Messages;

use App\DTO\Messages\OutboundMessageData;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\ProviderType;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboundMessage;
use App\Services\Providers\ZapiClient;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ProcessOutboundMessageAction
{
    public function __construct(
        private readonly ZapiClient $zapiClient,
    ) {}

    public function handle(OutboundMessageData $messageData): array
    {
        return DB::transaction(function () use ($messageData): array {
            $existing = CommunicationOutboundMessage::query()
                ->where('idempotency_key', $messageData->idempotencyKey)
                ->first();

            if ($existing !== null) {
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
            } else {
                $outboundMessage->forceFill([
                    'status' => MessageStatus::Failed->value,
                    'provider_response' => $result->response,
                    'failed_reason' => $result->error,
                ])->save();

                $communicationMessage->forceFill([
                    'status' => MessageStatus::Failed->value,
                ])->save();
            }

            return [
                'outbound_message' => $outboundMessage->refresh(),
                'communication_message' => $communicationMessage->refresh(),
                'duplicate' => false,
            ];
        });
    }
}
