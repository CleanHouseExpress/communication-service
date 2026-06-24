<?php

namespace App\DTO\Webhooks;

class DeliveryStatusData
{
    public function __construct(
        public readonly ?string $provider,
        public readonly ?string $messageId,
        public readonly string $status,
        public readonly array $payload = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            provider: $data['provider'] ?? null,
            messageId: $data['message_id'] ?? null,
            status: $data['status'] ?? 'unknown',
            payload: $data['payload'] ?? $data,
        );
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'message_id' => $this->messageId,
            'status' => $this->status,
            'payload' => $this->payload,
        ];
    }
}
