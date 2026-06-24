<?php

namespace App\DTO\Messages;

use App\Enums\MessageType;
use App\Enums\ProviderType;
use Carbon\CarbonImmutable;

class InboundMessageData
{
    public function __construct(
        public readonly ProviderType $provider,
        public readonly ?string $tenantId,
        public readonly ?string $channelId,
        public readonly ?string $externalEventId,
        public readonly ?string $externalMessageId,
        public readonly string $externalContactId,
        public readonly ?string $contactName,
        public readonly ?string $contactPhone,
        public readonly MessageType $messageType,
        public readonly ?string $text,
        public readonly ?CarbonImmutable $occurredAt,
        public readonly array $rawPayload,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            provider: ProviderType::from($data['provider']),
            tenantId: $data['tenant_id'] ?? null,
            channelId: $data['channel_id'] ?? null,
            externalEventId: $data['external_event_id'] ?? null,
            externalMessageId: $data['external_message_id'] ?? null,
            externalContactId: $data['external_contact_id'],
            contactName: $data['contact_name'] ?? null,
            contactPhone: $data['contact_phone'] ?? null,
            messageType: MessageType::from($data['message_type']),
            text: $data['text'] ?? null,
            occurredAt: isset($data['occurred_at']) ? CarbonImmutable::parse($data['occurred_at']) : null,
            rawPayload: $data['raw_payload'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'tenant_id' => $this->tenantId,
            'channel_id' => $this->channelId,
            'external_event_id' => $this->externalEventId,
            'external_message_id' => $this->externalMessageId,
            'external_contact_id' => $this->externalContactId,
            'contact_name' => $this->contactName,
            'contact_phone' => $this->contactPhone,
            'message_type' => $this->messageType->value,
            'text' => $this->text,
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'raw_payload' => $this->rawPayload,
        ];
    }
}
