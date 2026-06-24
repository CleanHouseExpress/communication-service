<?php

namespace App\DTO\Messages;

use App\Enums\MessageType;

class OutboundMessageData
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $channelId,
        public readonly string $conversationId,
        public readonly string $contactId,
        public readonly string $externalContactId,
        public readonly MessageType $messageType,
        public readonly ?string $text,
        public readonly string $idempotencyKey,
        public readonly array $payload = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: $data['tenant_id'],
            channelId: $data['channel_id'],
            conversationId: $data['conversation_id'],
            contactId: $data['contact_id'],
            externalContactId: $data['external_contact_id'],
            messageType: MessageType::from($data['message_type']),
            text: $data['text'] ?? null,
            idempotencyKey: $data['idempotency_key'],
            payload: $data['payload'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'channel_id' => $this->channelId,
            'conversation_id' => $this->conversationId,
            'contact_id' => $this->contactId,
            'external_contact_id' => $this->externalContactId,
            'message_type' => $this->messageType->value,
            'text' => $this->text,
            'idempotency_key' => $this->idempotencyKey,
            'payload' => $this->payload,
        ];
    }
}
