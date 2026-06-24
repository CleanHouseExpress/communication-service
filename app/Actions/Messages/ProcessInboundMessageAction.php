<?php

namespace App\Actions\Messages;

use App\DTO\Messages\InboundMessageData;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use Illuminate\Support\Facades\DB;

class ProcessInboundMessageAction
{
    public function handle(InboundMessageData $messageData): array
    {
        return DB::transaction(function () use ($messageData): array {
            $channel = $this->resolveChannel($messageData);
            $contact = $this->resolveContact($messageData);
            $conversation = $this->resolveConversation($messageData, $channel, $contact);

            $message = $this->findExistingMessage($messageData);
            $created = false;

            if ($message === null) {
                $message = CommunicationMessage::create([
                    'tenant_id' => $messageData->tenantId,
                    'conversation_id' => $conversation->id,
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'provider' => $messageData->provider->value,
                    'external_message_id' => $messageData->externalMessageId,
                    'direction' => MessageDirection::Inbound->value,
                    'message_type' => $messageData->messageType->value,
                    'text' => $messageData->text,
                    'payload' => $messageData->rawPayload,
                    'status' => MessageStatus::Received->value,
                    'occurred_at' => $messageData->occurredAt,
                ]);
                $created = true;
            }

            $conversation->forceFill([
                'last_message_at' => $messageData->occurredAt ?? now(),
            ])->save();

            return [
                'channel' => $channel,
                'contact' => $contact,
                'conversation' => $conversation->refresh(),
                'message' => $message,
                'message_created' => $created,
            ];
        });
    }

    private function resolveChannel(InboundMessageData $messageData): CommunicationChannel
    {
        if ($messageData->channelId !== null) {
            $channel = CommunicationChannel::query()->find($messageData->channelId);

            if ($channel !== null) {
                return $channel;
            }
        }

        return CommunicationChannel::query()->firstOrCreate(
            [
                'tenant_id' => $messageData->tenantId,
                'provider' => $messageData->provider->value,
                'external_id' => $messageData->channelId,
            ],
            [
                'name' => strtoupper($messageData->provider->value),
                'status' => 'active',
                'settings' => [],
            ]
        );
    }

    private function resolveContact(InboundMessageData $messageData): CommunicationContact
    {
        return CommunicationContact::query()->updateOrCreate(
            [
                'tenant_id' => $messageData->tenantId,
                'provider' => $messageData->provider->value,
                'external_id' => $messageData->externalContactId,
            ],
            [
                'name' => $messageData->contactName,
                'phone' => $messageData->contactPhone,
                'metadata' => [
                    'last_external_event_id' => $messageData->externalEventId,
                ],
            ]
        );
    }

    private function resolveConversation(
        InboundMessageData $messageData,
        CommunicationChannel $channel,
        CommunicationContact $contact
    ): CommunicationConversation {
        $conversation = CommunicationConversation::query()
            ->where('tenant_id', $messageData->tenantId)
            ->where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->where('status', ConversationStatus::Open->value)
            ->latest('created_at')
            ->first();

        if ($conversation !== null) {
            return $conversation;
        }

        return CommunicationConversation::create([
            'tenant_id' => $messageData->tenantId,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'status' => ConversationStatus::Open->value,
            'last_message_at' => $messageData->occurredAt ?? now(),
            'metadata' => [],
        ]);
    }

    private function findExistingMessage(InboundMessageData $messageData): ?CommunicationMessage
    {
        if ($messageData->externalMessageId === null || $messageData->externalMessageId === '') {
            return null;
        }

        return CommunicationMessage::query()
            ->where('provider', $messageData->provider->value)
            ->where('external_message_id', $messageData->externalMessageId)
            ->first();
    }
}
