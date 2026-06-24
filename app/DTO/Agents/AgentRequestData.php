<?php

namespace App\DTO\Agents;

class AgentRequestData
{
    public function __construct(
        public readonly ?string $tenantId,
        public readonly ?string $conversationId,
        public readonly ?string $messageId,
        public readonly ?string $contactId,
        public readonly ?string $channelId,
        public readonly ?string $provider,
        public readonly ?string $text,
        public readonly ?string $messageType,
        public readonly ?string $contactName,
        public readonly ?string $contactPhone,
        public readonly array $history = [],
        public readonly array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'message_id' => $this->messageId,
            'contact_id' => $this->contactId,
            'channel_id' => $this->channelId,
            'provider' => $this->provider,
            'text' => $this->text,
            'message_type' => $this->messageType,
            'contact_name' => $this->contactName,
            'contact_phone' => $this->contactPhone,
            'history' => $this->history,
            'metadata' => $this->metadata,
        ];
    }
}
